<?php
/**
 * TCloud — OnlyOffice Document Server Integration
 * 
 * Handles: editor config generation, JWT signing/verification,
 * save callbacks (status 2 & 6), file versioning, force save.
 * 
 * Requirements:
 *   - OnlyOffice Document Server (Docker or standalone)
 *   - System settings: onlyoffice_url, onlyoffice_secret
 *   - APP_URL must be accessible from the ONLYOFFICE server
 */

class OnlyOfficeIntegration {
    private string $serverUrl;
    private string $secret;
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->serverUrl = rtrim($this->getSetting('onlyoffice_url', ''), '/');
        $this->secret = $this->getSetting('onlyoffice_secret', '');
    }

    /**
     * Is OnlyOffice configured?
     */
    public function isAvailable(): bool {
        return !empty($this->serverUrl);
    }

    /**
     * Get the Document Server JS API URL
     */
    public function getApiUrl(): string {
        return $this->serverUrl . '/web-apps/apps/api/documents/api.js';
    }

    /**
     * Is a file type supported by OnlyOffice?
     */
    public function isSupported(string $ext): bool {
        return $this->getDocumentType($ext) !== null;
    }

    /**
     * Generate full editor config JSON for a file
     */
    public function getEditorConfig(array $file, array $user, string $mode = 'edit'): array {
        $ext = strtolower($file['extension'] ?? '');
        $docType = $this->getDocumentType($ext);

        if (!$docType) {
            return ['success' => false, 'message' => 'Formato não suportado: .' . $ext];
        }

        // Unique document key: changes when file changes (forces re-download)
        $key = substr(md5($file['id'] . '_' . $file['version'] . '_' . ($file['hash_sha256'] ?? '')), 0, 20);

        // URLs that ONLYOFFICE server will call (must be accessible from Docker/server)
        $fileUrl = APP_URL . '/api/download.php?type=preview&id=' . $file['id'] . '&oo=1';
        $callbackUrl = APP_URL . '/api/onlyoffice_callback.php?id=' . $file['id'];

        $canEdit = $mode === 'edit';

        $config = [
            'document' => [
                'fileType' => $ext,
                'key' => $key,
                'title' => $file['original_name'],
                'url' => $fileUrl,
                'permissions' => [
                    'chat' => false,
                    'comment' => true,
                    'download' => true,
                    'edit' => $canEdit,
                    'print' => true,
                    'review' => $canEdit,
                    'fillForms' => $canEdit,
                ],
            ],
            'documentType' => $docType,
            'editorConfig' => [
                'callbackUrl' => $callbackUrl,
                'lang' => 'pt',
                'mode' => $mode,
                'user' => [
                    'id' => (string)$user['id'],
                    'name' => $user['full_name'] ?? $user['username'],
                ],
                'customization' => [
                    'autosave' => true,
                    'chat' => false,
                    'compactHeader' => false,
                    'compactToolbar' => false,
                    'feedback' => false,
                    'forcesave' => true,
                    'help' => false,
                    'hideRightMenu' => false,
                    'hideRulers' => false,
                    'submitForm' => false,
                    'about' => false,
                    'logo' => [
                        'image' => APP_URL . '/public/favicon.svg',
                    ],
                ],
            ],
        ];

        // Sign with JWT if secret is set
        if (!empty($this->secret)) {
            $config['token'] = $this->jwtEncode($config);
        }

        return [
            'success' => true,
            'available' => true,
            'config' => $config,
            'serverUrl' => $this->serverUrl,
            'apiUrl' => $this->getApiUrl(),
        ];
    }

    /**
     * Handle callback from OnlyOffice Document Server
     * 
     * Status codes:
     *   0 - no changes
     *   1 - editing
     *   2 - ready to save (all editors closed)
     *   3 - save error
     *   4 - close without changes
     *   6 - force save while editing
     *   7 - force save error
     */
    public function handleCallback(int $fileId): array {
        $body = file_get_contents('php://input');
        $input = json_decode($body, true);

        if (!$input) {
            error_log("OnlyOffice callback: empty body for file $fileId");
            return ['error' => 0];
        }

        // Verify JWT if secret is set
        if (!empty($this->secret) && isset($input['token'])) {
            $decoded = $this->jwtDecode($input['token']);
            if (!$decoded) {
                error_log("OnlyOffice callback: JWT verification failed for file $fileId");
                return ['error' => 1]; // 1 = error
            }
            $input = $decoded;
        }

        $status = (int)($input['status'] ?? 0);

        error_log("OnlyOffice callback: file=$fileId status=$status");

        // Status 2 = document ready to save (editors closed)
        // Status 6 = force save (document still being edited)
        if (in_array($status, [2, 6])) {
            $downloadUrl = $input['url'] ?? '';
            if (empty($downloadUrl)) {
                error_log("OnlyOffice callback: no download URL for file $fileId");
                return ['error' => 0];
            }

            return $this->saveFromCallback($fileId, $downloadUrl, $status);
        }

        // All other statuses: acknowledge
        return ['error' => 0];
    }

    /**
     * Download and save the edited document from OnlyOffice
     */
    private function saveFromCallback(int $fileId, string $downloadUrl, int $status): array {
        // Download the document from OnlyOffice
        $context = stream_context_create([
            'http' => ['timeout' => 30],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $content = @file_get_contents($downloadUrl, false, $context);
        if ($content === false) {
            error_log("OnlyOffice callback: failed to download from $downloadUrl");
            return ['error' => 1];
        }

        // Get current file info
        $stmt = $this->db->prepare("SELECT * FROM files WHERE id = ? AND is_deleted = 0");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();

        if (!$file) {
            error_log("OnlyOffice callback: file $fileId not found");
            return ['error' => 1];
        }

        $path = STORAGE_PATH . '/' . $file['storage_path'];
        $oldSize = (int)$file['size'];

        // Save previous version
        try {
            $this->db->prepare("
                INSERT INTO file_versions (file_id, user_id, version_number, size, hash_sha256, storage_path, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $fileId,
                $file['user_id'],
                $file['version'],
                $oldSize,
                $file['hash_sha256'],
                $file['storage_path'],
            ]);
        } catch (\Exception $e) {
            error_log("OnlyOffice callback: version save error: " . $e->getMessage());
        }

        // Write new content
        if (file_put_contents($path, $content) === false) {
            error_log("OnlyOffice callback: failed to write to $path");
            return ['error' => 1];
        }

        $newSize = strlen($content);
        $newHash = hash('sha256', $content);
        $newVersion = (int)$file['version'] + 1;

        // Update file record
        $this->db->prepare("
            UPDATE files 
            SET size = ?, hash_sha256 = ?, version = ?, updated_at = NOW() 
            WHERE id = ?
        ")->execute([$newSize, $newHash, $newVersion, $fileId]);

        // Update user storage
        $delta = $newSize - $oldSize;
        if ($delta !== 0) {
            $this->db->prepare("
                UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) + ?) WHERE id = ?
            ")->execute([$delta, $file['user_id']]);
        }

        // Audit log
        AuditLog::log('edit_office', 'file', $fileId, $file['original_name'], [
            'editor' => 'onlyoffice',
            'status' => $status,
            'version' => $newVersion,
            'old_size' => $oldSize,
            'new_size' => $newSize,
        ]);

        error_log("OnlyOffice callback: saved file $fileId v$newVersion ({$newSize} bytes)");
        return ['error' => 0];
    }

    /**
     * Get document type for OnlyOffice (word/cell/slide)
     */
    private function getDocumentType(string $ext): ?string {
        $map = [
            'doc' => 'word', 'docx' => 'word', 'docm' => 'word',
            'dot' => 'word', 'dotx' => 'word',
            'odt' => 'word', 'ott' => 'word', 'rtf' => 'word', 'txt' => 'word',
            'html' => 'word', 'htm' => 'word', 'mht' => 'word',
            'pdf' => 'word', 'djvu' => 'word', 'fb2' => 'word', 'epub' => 'word',
            'xps' => 'word',

            'xls' => 'cell', 'xlsx' => 'cell', 'xlsm' => 'cell',
            'xlt' => 'cell', 'xltx' => 'cell',
            'ods' => 'cell', 'ots' => 'cell', 'csv' => 'cell',

            'ppt' => 'cell', 'pptx' => 'slide', 'pptm' => 'slide',
            'pot' => 'slide', 'potx' => 'slide',
            'odp' => 'slide', 'otp' => 'slide',
        ];
        return $map[strtolower($ext)] ?? null;
    }

    // ═══════════════════════════════════════════════════════
    // JWT
    // ═══════════════════════════════════════════════════════

    private function jwtEncode(array $payload): string {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $this->base64UrlEncode(json_encode($payload));
        $sig = $this->base64UrlEncode(hash_hmac('sha256', "$header.$body", $this->secret, true));
        return "$header.$body.$sig";
    }

    private function jwtDecode(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $sig = $this->base64UrlEncode(hash_hmac('sha256', "$parts[0].$parts[1]", $this->secret, true));
        if (!hash_equals($sig, $parts[2])) return null;

        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    }

    private function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ═══════════════════════════════════════════════════════
    // UTILS
    // ═══════════════════════════════════════════════════════

    private function getSetting(string $key, $default = null): ?string {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }
}

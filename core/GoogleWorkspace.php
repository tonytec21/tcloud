<?php
/**
 * TCloud — Google Workspace Integration
 * 
 * Flow: Upload to Drive → Edit in Google Docs → Export back → Delete from Drive
 * 
 * Tables used: google_tokens, google_temp_files, system_settings
 * 
 * Requirements:
 *   1. Google Cloud Console project with Drive API enabled
 *   2. OAuth2 credentials (Client ID + Client Secret)
 *   3. Authorized redirect URI: {APP_URL}/api/google_auth.php
 *   4. Admin settings: google_client_id, google_client_secret, google_enabled=1
 */

class GoogleWorkspace {
    private PDO $db;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    const DRIVE_API  = 'https://www.googleapis.com/drive/v3';
    const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
    const SCOPES     = 'https://www.googleapis.com/auth/drive.file';

    // Extension → Google MIME type (import/convert)
    const IMPORT = [
        'docx'=>'application/vnd.google-apps.document',
        'doc' =>'application/vnd.google-apps.document',
        'odt' =>'application/vnd.google-apps.document',
        'rtf' =>'application/vnd.google-apps.document',
        'txt' =>'application/vnd.google-apps.document',
        'xlsx'=>'application/vnd.google-apps.spreadsheet',
        'xls' =>'application/vnd.google-apps.spreadsheet',
        'ods' =>'application/vnd.google-apps.spreadsheet',
        'csv' =>'application/vnd.google-apps.spreadsheet',
        'pptx'=>'application/vnd.google-apps.presentation',
        'ppt' =>'application/vnd.google-apps.presentation',
        'odp' =>'application/vnd.google-apps.presentation',
    ];

    // Google MIME → export MIME + extension
    const EXPORT = [
        'application/vnd.google-apps.document'    => ['mime'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','ext'=>'docx'],
        'application/vnd.google-apps.spreadsheet'  => ['mime'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','ext'=>'xlsx'],
        'application/vnd.google-apps.presentation' => ['mime'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','ext'=>'pptx'],
    ];

    // Editor URLs
    const EDITORS = [
        'document'     => 'https://docs.google.com/document/d/{ID}/edit',
        'spreadsheet'  => 'https://docs.google.com/spreadsheets/d/{ID}/edit',
        'presentation' => 'https://docs.google.com/presentation/d/{ID}/edit',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
        $this->ensureSchema();
        $this->clientId     = $this->setting('google_client_id', '');
        $this->clientSecret = $this->setting('google_client_secret', '');
        $this->redirectUri  = APP_URL . '/api/google_auth.php';
    }

    private static bool $schemaChecked = false;

    /**
     * Auto-create settings + tables if missing (safe for existing DBs)
     */
    private function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            // Settings
            $needed = [
                ['google_client_id',     '', 'string', 'integrations', 'Google OAuth Client ID'],
                ['google_client_secret', '', 'string', 'integrations', 'Google OAuth Client Secret'],
                ['google_enabled',       '0','bool',   'integrations', 'Habilitar edição via Google Workspace'],
            ];
            foreach ($needed as $s) {
                $stmt = $this->db->prepare("SELECT 1 FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$s[0]]);
                if (!$stmt->fetch()) {
                    $this->db->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES (?,?,?,?,?)")
                        ->execute($s);
                }
            }

            // google_tokens table
            $this->db->exec("CREATE TABLE IF NOT EXISTS `google_tokens` (
                `user_id` INT UNSIGNED NOT NULL,
                `access_token` TEXT NOT NULL,
                `refresh_token` TEXT DEFAULT NULL,
                `token_type` VARCHAR(50) DEFAULT 'Bearer',
                `expires_at` DATETIME NOT NULL,
                `scope` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // google_temp_files table
            $this->db->exec("CREATE TABLE IF NOT EXISTS `google_temp_files` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `file_id` INT UNSIGNED NOT NULL,
                `google_file_id` VARCHAR(255) NOT NULL,
                `google_mime_type` VARCHAR(100) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Exception $e) {
            // Silently continue — non-critical
            error_log('GoogleWorkspace ensureSchema: ' . $e->getMessage());
        }
    }

    public function isAvailable(): bool {
        return $this->setting('google_enabled', '0') === '1'
            && !empty($this->clientId)
            && !empty($this->clientSecret);
    }

    public function isSupported(string $ext): bool {
        return isset(self::IMPORT[strtolower($ext)]);
    }

    public function isUserAuthorized(int $userId): bool {
        return $this->validToken($userId) !== null;
    }

    // ═══════════════════════════════════════════════════════
    // OAUTH2
    // ═══════════════════════════════════════════════════════

    public function getAuthUrl(int $userId, ?int $fileId = null): string {
        $state = base64_encode(json_encode([
            'uid'  => $userId,
            'fid'  => $fileId,
            'csrf' => bin2hex(random_bytes(16)),
        ]));
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    public function handleAuthCallback(string $code, string $state): array {
        $s = json_decode(base64_decode($state), true);
        if (!$s || !isset($s['uid'])) {
            return ['success' => false, 'message' => 'Estado inválido.'];
        }

        $r = $this->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$r || isset($r['error'])) {
            return ['success' => false, 'message' => 'Erro OAuth: ' . ($r['error_description'] ?? $r['error'] ?? '?')];
        }

        $this->saveToken($s['uid'], $r);
        return ['success' => true, 'user_id' => $s['uid'], 'file_id' => $s['fid'] ?? null];
    }

    // ═══════════════════════════════════════════════════════
    // EDIT FLOW
    // ═══════════════════════════════════════════════════════

    /**
     * Upload file to Google Drive for editing
     */
    public function uploadForEditing(int $userId, int $fileId): array {
        $token = $this->validToken($userId);
        if (!$token) {
            return ['success'=>false, 'needs_auth'=>true, 'auth_url'=>$this->getAuthUrl($userId, $fileId)];
        }

        $fm = new FileManager($userId);
        $file = $fm->getFile($fileId);
        if (!$file) return ['success'=>false, 'message'=>'Arquivo não encontrado.'];

        $ext = strtolower($file['extension'] ?? '');
        if (!isset(self::IMPORT[$ext])) return ['success'=>false, 'message'=>'Formato não suportado: .'.$ext];

        $path = STORAGE_PATH . '/' . $file['storage_path'];
        if (!file_exists($path)) return ['success'=>false, 'message'=>'Arquivo não encontrado no disco.'];

        // Multipart upload with conversion
        $googleMime = self::IMPORT[$ext];
        $boundary = 'cv_' . uniqid();
        $meta = json_encode(['name' => $file['original_name'], 'mimeType' => $googleMime]);
        $content = file_get_contents($path);
        $fileMime = $file['mime_type'] ?: 'application/octet-stream';

        $body  = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n";
        $body .= "--{$boundary}\r\nContent-Type: {$fileMime}\r\n\r\n{$content}\r\n--{$boundary}--";

        $r = $this->req('POST',
            self::UPLOAD_API . '/files?uploadType=multipart&fields=id,mimeType,webViewLink',
            $body,
            ["Authorization: Bearer {$token}", "Content-Type: multipart/related; boundary={$boundary}"]
        );

        if (!$r || isset($r['error'])) {
            return ['success'=>false, 'message'=> $r['error']['message'] ?? 'Erro ao enviar para Google Drive'];
        }

        $gid = $r['id'];
        $gMime = $r['mimeType'];

        // Make editable by anyone with link (needed for iframe embed)
        $this->req('POST',
            self::DRIVE_API . "/files/{$gid}/permissions",
            json_encode(['role'=>'writer','type'=>'anyone']),
            ["Authorization: Bearer {$token}", 'Content-Type: application/json']
        );

        // Editor URL
        $docType = str_replace('application/vnd.google-apps.', '', $gMime);
        $editorUrl = str_replace('{ID}', $gid, self::EDITORS[$docType] ?? $r['webViewLink'] ?? '');

        // Track temp file
        $this->trackTemp($userId, $fileId, $gid, $gMime);

        AuditLog::log('google_upload', 'file', $fileId, $file['original_name'], ['google_id'=>$gid]);

        return [
            'success'        => true,
            'google_file_id' => $gid,
            'editor_url'     => $editorUrl,
            'google_mime'    => $gMime,
            'file_name'      => $file['original_name'],
        ];
    }

    /**
     * Export from Google Drive back to TCloud
     */
    public function saveBack(int $userId, int $fileId): array {
        $token = $this->validToken($userId);
        if (!$token) return ['success'=>false, 'message'=>'Token expirado. Reconecte sua conta Google.'];

        $t = $this->getTemp($userId, $fileId);
        if (!$t) return ['success'=>false, 'message'=>'Sessão de edição não encontrada.'];

        $exp = self::EXPORT[$t['google_mime_type']] ?? null;
        if (!$exp) return ['success'=>false, 'message'=>'Formato de exportação não suportado.'];

        // Export
        $content = $this->rawReq('GET',
            self::DRIVE_API . "/files/{$t['google_file_id']}/export?mimeType=" . urlencode($exp['mime']),
            null, ["Authorization: Bearer {$token}"]
        );

        if ($content === false || empty($content)) {
            return ['success'=>false, 'message'=>'Erro ao exportar do Google Drive.'];
        }

        // Save to TCloud
        $fm = new FileManager($userId);
        $file = $fm->getFile($fileId);
        if (!$file) return ['success'=>false, 'message'=>'Arquivo local não encontrado.'];

        $path = STORAGE_PATH . '/' . $file['storage_path'];
        $oldSize = (int)$file['size'];

        // Version history
        try {
            $this->db->prepare("INSERT INTO file_versions (file_id,user_id,version_number,size,hash_sha256,storage_path,created_at) VALUES(?,?,?,?,?,?,NOW())")
                ->execute([$fileId, $userId, $file['version'], $oldSize, $file['hash_sha256'], $file['storage_path']]);
        } catch (\Exception $e) { error_log("Version: ".$e->getMessage()); }

        file_put_contents($path, $content);
        $newSize = strlen($content);
        $newHash = hash('sha256', $content);
        $newVer  = (int)$file['version'] + 1;

        $this->db->prepare("UPDATE files SET size=?, hash_sha256=?, version=?, updated_at=NOW() WHERE id=?")
            ->execute([$newSize, $newHash, $newVer, $fileId]);

        $delta = $newSize - $oldSize;
        if ($delta) $this->db->prepare("UPDATE users SET storage_used=GREATEST(0,CAST(storage_used AS SIGNED)+?) WHERE id=?")->execute([$delta,$userId]);

        AuditLog::log('google_save_back', 'file', $fileId, $file['original_name'], ['version'=>$newVer,'size'=>$newSize]);

        return ['success'=>true, 'message'=>'Documento salvo!', 'version'=>$newVer, 'size'=>$newSize];
    }

    /**
     * Delete temp file from Google Drive
     */
    public function cleanup(int $userId, int $fileId): array {
        $token = $this->validToken($userId);
        $t = $this->getTemp($userId, $fileId);
        
        if ($t && $token) {
            // Delete file from Google Drive
            $result = $this->req('DELETE', self::DRIVE_API."/files/{$t['google_file_id']}", null, ["Authorization: Bearer {$token}"]);
            // Log if delete failed
            if ($result === null) {
                error_log("Google Drive cleanup failed for file {$t['google_file_id']} (user {$userId})");
            }
        } elseif ($t && !$token) {
            error_log("Google Drive cleanup skipped - no valid token for user {$userId}, google_file_id={$t['google_file_id']}");
        }
        
        $this->removeTemp($userId, $fileId);
        return ['success' => true];
    }

    /**
     * Cleanup stale temp files (older than 24h) — called periodically
     */
    public function cleanupStale(): void {
        try {
            $stmt = $this->db->query("SELECT gtf.*, gt.access_token, gt.refresh_token, gt.expires_at 
                FROM google_temp_files gtf 
                LEFT JOIN google_tokens gt ON gtf.user_id = gt.user_id 
                WHERE gtf.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            while ($row = $stmt->fetch()) {
                $token = $this->validToken($row['user_id']);
                if ($token) {
                    @$this->req('DELETE', self::DRIVE_API."/files/{$row['google_file_id']}", null, ["Authorization: Bearer {$token}"]);
                }
                $this->removeTemp($row['user_id'], $row['file_id']);
            }
        } catch (\Exception $e) {
            error_log("cleanupStale error: " . $e->getMessage());
        }
    }

    public function disconnect(int $userId): array {
        $this->cleanupAll($userId);
        try { $this->db->prepare("DELETE FROM google_tokens WHERE user_id=?")->execute([$userId]); } catch(\Exception $e){}
        return ['success'=>true, 'message'=>'Conta Google desconectada.'];
    }

    // ═══════════════════════════════════════════════════════
    // TOKEN STORAGE (google_tokens table)
    // ═══════════════════════════════════════════════════════

    private function validToken(int $userId): ?string {
        try {
            $stmt = $this->db->prepare("SELECT * FROM google_tokens WHERE user_id=?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        } catch (\Exception $e) { return null; }

        if (!$row) return null;

        // Expired? Refresh
        if (strtotime($row['expires_at']) < time() + 60) {
            return $this->refresh($userId, $row['refresh_token']);
        }
        return $row['access_token'];
    }

    private function refresh(int $userId, ?string $refreshToken): ?string {
        if (empty($refreshToken)) { $this->deleteTokens($userId); return null; }

        $r = $this->post(self::TOKEN_URL, [
            'client_id'=>$this->clientId, 'client_secret'=>$this->clientSecret,
            'refresh_token'=>$refreshToken, 'grant_type'=>'refresh_token',
        ]);

        if (!$r || isset($r['error'])) {
            error_log('Google refresh failed: '.json_encode($r));
            $this->deleteTokens($userId);
            return null;
        }
        $r['refresh_token'] = $r['refresh_token'] ?? $refreshToken;
        $this->saveToken($userId, $r);
        return $r['access_token'];
    }

    private function saveToken(int $userId, array $t): void {
        $expires = date('Y-m-d H:i:s', time() + ($t['expires_in'] ?? 3600));
        $scope = $t['scope'] ?? self::SCOPES;
        try {
            $this->db->prepare("REPLACE INTO google_tokens (user_id,access_token,refresh_token,token_type,expires_at,scope) VALUES(?,?,?,?,?,?)")
                ->execute([$userId, $t['access_token'], $t['refresh_token']??'', $t['token_type']??'Bearer', $expires, $scope]);
        } catch (\Exception $e) { error_log("Save token: ".$e->getMessage()); }
    }

    private function deleteTokens(int $userId): void {
        try { $this->db->prepare("DELETE FROM google_tokens WHERE user_id=?")->execute([$userId]); } catch(\Exception $e){}
    }

    // ═══════════════════════════════════════════════════════
    // TEMP FILE TRACKING (google_temp_files table)
    // ═══════════════════════════════════════════════════════

    private function trackTemp(int $userId, int $fileId, string $gid, string $gMime): void {
        try {
            $this->db->prepare("REPLACE INTO google_temp_files (user_id,file_id,google_file_id,google_mime_type) VALUES(?,?,?,?)")
                ->execute([$userId, $fileId, $gid, $gMime]);
        } catch (\Exception $e) { error_log("Track temp: ".$e->getMessage()); }
    }

    private function getTemp(int $userId, int $fileId): ?array {
        try {
            $stmt = $this->db->prepare("SELECT * FROM google_temp_files WHERE user_id=? AND file_id=?");
            $stmt->execute([$userId, $fileId]);
            return $stmt->fetch() ?: null;
        } catch (\Exception $e) { return null; }
    }

    private function removeTemp(int $userId, int $fileId): void {
        try { $this->db->prepare("DELETE FROM google_temp_files WHERE user_id=? AND file_id=?")->execute([$userId,$fileId]); } catch(\Exception $e){}
    }

    private function cleanupAll(int $userId): void {
        try {
            $token = $this->validToken($userId);
            $stmt = $this->db->prepare("SELECT google_file_id FROM google_temp_files WHERE user_id=?");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch()) {
                if ($token) @$this->req('DELETE', self::DRIVE_API."/files/{$row['google_file_id']}", null, ["Authorization: Bearer {$token}"]);
            }
            $this->db->prepare("DELETE FROM google_temp_files WHERE user_id=?")->execute([$userId]);
        } catch (\Exception $e) {}
    }

    // ═══════════════════════════════════════════════════════
    // HTTP
    // ═══════════════════════════════════════════════════════

    private function post(string $url, array $data): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>http_build_query($data), CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>30]);
        $r = curl_exec($ch); curl_close($ch);
        return $r ? json_decode($r, true) : null;
    }

    private function req(string $method, string $url, ?string $body, array $headers): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>60, CURLOPT_HTTPHEADER=>$headers]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 204) return [];
        return $r ? json_decode($r, true) : null;
    }

    private function rawReq(string $method, string $url, ?string $body, array $headers) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>1, CURLOPT_SSL_VERIFYPEER=>0, CURLOPT_TIMEOUT=>120, CURLOPT_HTTPHEADER=>$headers, CURLOPT_FOLLOWLOCATION=>1]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($code >= 200 && $code < 300) ? $r : false;
    }

    private function setting(string $key, $default = null): ?string {
        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
            $stmt->execute([$key]); $row = $stmt->fetch();
            return $row ? $row['setting_value'] : $default;
        } catch (\Exception $e) { return $default; }
    }
}

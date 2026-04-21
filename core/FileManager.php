<?php
/**
 * TCloud - Gerenciador de Arquivos (Core)
 * Lida com todas as operações de arquivos e pastas
 */

class FileManager {
    private PDO $db;
    private int $userId;

    public function __construct(int $userId) {
        $this->db = Database::getInstance();
        $this->userId = $userId;
    }

    // ========================================================
    // PASTAS
    // ========================================================

    /**
     * Lista conteúdo de uma pasta
     */
    public function listFolder(?int $folderId = null, array $options = []): array {
        $sort = $options['sort'] ?? 'name';
        $order = strtoupper($options['order'] ?? 'ASC');
        $order = in_array($order, ['ASC','DESC']) ? $order : 'ASC';
        $type = $options['type'] ?? null;
        $search = $options['search'] ?? null;

        $validSorts = ['name'=>'original_name', 'size'=>'size', 'date'=>'updated_at', 'type'=>'extension'];
        $sortCol = $validSorts[$sort] ?? 'original_name';
        $folderSort = $sort === 'name' ? 'name' : ($sort === 'date' ? 'updated_at' : 'name');

        // Subpastas
        $folderWhere = ['f.user_id = ?', 'f.is_trashed = 0'];
        $folderParams = [$this->userId];

        if ($folderId === null) {
            $folderWhere[] = 'f.parent_id IS NULL';
        } else {
            $folderWhere[] = 'f.parent_id = ?';
            $folderParams[] = $folderId;
        }
        if ($search) {
            $folderWhere[] = 'f.name LIKE ?';
            $folderParams[] = "%{$search}%";
        }

        $w = implode(' AND ', $folderWhere);
        $stmt = $this->db->prepare("
            SELECT f.*, 'folder' as item_type, 
                   (SELECT COUNT(*) FROM files WHERE folder_id = f.id AND is_trashed = 0) as file_count,
                   (SELECT COUNT(*) FROM folders WHERE parent_id = f.id AND is_trashed = 0) as subfolder_count,
                   IF(fav.id IS NOT NULL, 1, 0) as is_favorited
            FROM folders f
            LEFT JOIN favorites fav ON fav.folder_id = f.id AND fav.user_id = ?
            WHERE {$w}
            ORDER BY {$folderSort} {$order}
        ");
        $stmt->execute(array_merge([$this->userId], $folderParams));
        $folders = $stmt->fetchAll();

        // Arquivos
        $fileWhere = ['fi.user_id = ?', 'fi.is_trashed = 0'];
        $fileParams = [$this->userId];

        if ($folderId === null) {
            $fileWhere[] = 'fi.folder_id IS NULL';
        } else {
            $fileWhere[] = 'fi.folder_id = ?';
            $fileParams[] = $folderId;
        }
        if ($search) {
            $fileWhere[] = 'fi.original_name LIKE ?';
            $fileParams[] = "%{$search}%";
        }
        if ($type) {
            $typeMap = [
                'image' => ['jpg','jpeg','png','gif','webp','svg','bmp','ico'],
                'document' => ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp'],
                'text' => ['txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml'],
                'video' => ['mp4','avi','mkv','mov','wmv','flv','webm'],
                'audio' => ['mp3','wav','ogg','flac','aac','wma','m4a'],
                'archive' => ['zip','rar','7z','tar','gz','bz2']
            ];
            if (isset($typeMap[$type])) {
                $placeholders = str_repeat('?,', count($typeMap[$type]) - 1) . '?';
                $fileWhere[] = "fi.extension IN ({$placeholders})";
                $fileParams = array_merge($fileParams, $typeMap[$type]);
            }
        }

        $w = implode(' AND ', $fileWhere);
        $stmt = $this->db->prepare("
            SELECT fi.*, 'file' as item_type,
                   IF(fav.id IS NOT NULL, 1, 0) as is_favorited
            FROM files fi
            LEFT JOIN favorites fav ON fav.file_id = fi.id AND fav.user_id = ?
            WHERE {$w}
            ORDER BY {$sortCol} {$order}
        ");
        $stmt->execute(array_merge([$this->userId], $fileParams));
        $files = $stmt->fetchAll();

        return ['folders' => $folders, 'files' => $files];
    }

    /**
     * Obtém breadcrumbs de uma pasta
     */
    public function getBreadcrumbs(?int $folderId): array {
        $crumbs = [];
        $currentId = $folderId;
        while ($currentId !== null) {
            $stmt = $this->db->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$currentId, $this->userId]);
            $folder = $stmt->fetch();
            if (!$folder) break;
            array_unshift($crumbs, $folder);
            $currentId = $folder['parent_id'];
        }
        return $crumbs;
    }

    /**
     * Cria uma nova pasta
     */
    public function createFolder(string $name, ?int $parentId = null): array {
        $name = $this->sanitizeName($name);
        if (empty($name)) {
            return ['success' => false, 'message' => 'Nome da pasta é obrigatório.'];
        }

        // Verificar duplicata
        $check = $this->db->prepare("
            SELECT id FROM folders 
            WHERE name = ? AND user_id = ? AND " . ($parentId ? "parent_id = ?" : "parent_id IS NULL") . " AND is_trashed = 0
        ");
        $params = [$name, $this->userId];
        if ($parentId) $params[] = $parentId;
        $check->execute($params);
        if ($check->fetch()) {
            return ['success' => false, 'message' => 'Já existe uma pasta com esse nome.'];
        }

        // Calcular caminho
        $path = '/' . $name;
        $depth = 0;
        if ($parentId) {
            $parent = $this->db->prepare("SELECT path, depth FROM folders WHERE id = ? AND user_id = ?");
            $parent->execute([$parentId, $this->userId]);
            $p = $parent->fetch();
            if (!$p) return ['success' => false, 'message' => 'Pasta pai não encontrada.'];
            $path = $p['path'] . '/' . $name;
            $depth = $p['depth'] + 1;
        }

        $slug = $this->generateSlug($name);
        $stmt = $this->db->prepare("
            INSERT INTO folders (name, slug, parent_id, user_id, path, depth) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $slug, $parentId, $this->userId, $path, $depth]);
        $folderId = (int)$this->db->lastInsertId();

        // Criar diretório físico
        $physicalPath = STORAGE_PATH . '/' . $this->userId . $path;
        if (!is_dir($physicalPath)) {
            mkdir($physicalPath, 0755, true);
        }

        AuditLog::log('folder_create', 'folder', $folderId, $name);
        return ['success' => true, 'id' => $folderId, 'message' => 'Pasta criada com sucesso.'];
    }

    // ========================================================
    // UPLOAD DE ARQUIVOS
    // ========================================================

    /**
     * Processa upload de arquivo
     */
    public function uploadFile(array $file, ?int $folderId = null, string $conflict = 'rename'): array {
        // Validar arquivo
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o limite do servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário.',
                UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente.',
                UPLOAD_ERR_CANT_WRITE => 'Erro ao gravar no disco.',
            ];
            return ['success' => false, 'message' => $errors[$file['error']] ?? 'Erro no upload.'];
        }

        // Validar tamanho
        if ($file['size'] > MAX_UPLOAD_SIZE) {
            return ['success' => false, 'message' => 'Arquivo excede o tamanho máximo permitido.'];
        }

        // Verificar quota do usuário
        $user = $this->getUser();
        if (($user['storage_used'] + $file['size']) > $user['storage_quota']) {
            return ['success' => false, 'message' => 'Espaço de armazenamento insuficiente.'];
        }

        // Validar extensão
        $originalName = basename($file['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!$this->isAllowedExtension($extension)) {
            return ['success' => false, 'message' => "Extensão .{$extension} não é permitida."];
        }

        // Verificar MIME type
        $mimeType = mime_content_type($file['tmp_name']) ?: $file['type'];

        // Tratar conflito de nomes
        if ($conflict !== 'replace') {
            $originalName = $this->resolveNameConflict($originalName, $folderId);
        } else {
            // Excluir arquivo existente com mesmo nome
            $this->deleteByName($originalName, $folderId);
        }

        // Gerar nome único para armazenamento
        $hash = hash_file('sha256', $file['tmp_name']);
        $storedName = uniqid('cv_', true) . '.' . $extension;

        // Determinar caminho de armazenamento
        $subDir = date('Y/m');
        $storagePath = $this->userId . '/' . $subDir;
        $fullDir = STORAGE_PATH . '/' . $storagePath;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        // Mover arquivo (suporta upload HTTP e arquivos internos)
        $destination = $fullDir . '/' . $storedName;
        if (is_uploaded_file($file['tmp_name'])) {
            $moved = move_uploaded_file($file['tmp_name'], $destination);
        } else {
            $moved = rename($file['tmp_name'], $destination) || copy($file['tmp_name'], $destination);
        }
        if (!$moved) {
            return ['success' => false, 'message' => 'Erro ao salvar arquivo no disco.'];
        }

        // Impedir execução de scripts
        @chmod($destination, 0644);

        // Inserir no banco
        $stmt = $this->db->prepare("
            INSERT INTO files (original_name, stored_name, extension, mime_type, size, hash_sha256, folder_id, user_id, storage_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $originalName, $storedName, $extension, $mimeType,
            $file['size'], $hash, $folderId, $this->userId, $storagePath . '/' . $storedName
        ]);
        $fileId = (int)$this->db->lastInsertId();

        // Atualizar espaço usado
        $this->updateStorageUsed($file['size']);

        // Gerar thumbnail para imagens
        if (in_array($extension, ['jpg','jpeg','png','gif','webp'])) {
            $this->generateThumbnail($destination, $fileId, $extension);
        }

        AuditLog::log('upload', 'file', $fileId, $originalName, ['size' => $file['size'], 'type' => $mimeType]);

        return [
            'success' => true,
            'id' => $fileId,
            'name' => $originalName,
            'size' => $file['size'],
            'message' => 'Arquivo enviado com sucesso.'
        ];
    }

    // ========================================================
    // OPERAÇÕES COM ARQUIVOS
    // ========================================================

    /**
     * Obtém informações de um arquivo
     */
    public function getFile(int $fileId): ?array {
        $stmt = $this->db->prepare("
            SELECT f.*, u.username as owner_name,
                   IF(fav.id IS NOT NULL, 1, 0) as is_favorited
            FROM files f
            JOIN users u ON f.user_id = u.id
            LEFT JOIN favorites fav ON fav.file_id = f.id AND fav.user_id = ?
            WHERE f.id = ? AND f.user_id = ?
        ");
        $stmt->execute([$this->userId, $fileId, $this->userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtém arquivo por ID sem filtrar por usuário (para callbacks do OnlyOffice)
     */
    public function getFileById(int $fileId): ?array {
        $stmt = $this->db->prepare("
            SELECT f.*, u.username as owner_name
            FROM files f
            JOIN users u ON f.user_id = u.id
            WHERE f.id = ? AND f.is_deleted = 0
        ");
        $stmt->execute([$fileId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtém informações de uma pasta
     */
    public function getFolder(int $folderId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $this->userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Renomear arquivo ou pasta
     */
    public function rename(string $type, int $id, string $newName): array {
        $newName = $this->sanitizeName($newName);
        if (empty($newName)) {
            return ['success' => false, 'message' => 'Nome não pode ser vazio.'];
        }

        if ($type === 'folder') {
            $stmt = $this->db->prepare("SELECT * FROM folders WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $this->userId]);
            $item = $stmt->fetch();
            if (!$item) return ['success' => false, 'message' => 'Pasta não encontrada.'];

            $this->db->prepare("UPDATE folders SET name = ?, slug = ?, updated_at = NOW() WHERE id = ?")->execute([
                $newName, $this->generateSlug($newName), $id
            ]);
            AuditLog::log('rename', 'folder', $id, $newName, ['old_name' => $item['name']]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $this->userId]);
            $item = $stmt->fetch();
            if (!$item) return ['success' => false, 'message' => 'Arquivo não encontrado.'];

            // Manter extensão original
            $ext = pathinfo($newName, PATHINFO_EXTENSION);
            if (empty($ext) && $item['extension']) {
                $newName .= '.' . $item['extension'];
            }

            $this->db->prepare("UPDATE files SET original_name = ?, updated_at = NOW() WHERE id = ?")->execute([$newName, $id]);
            AuditLog::log('rename', 'file', $id, $newName, ['old_name' => $item['original_name']]);
        }

        return ['success' => true, 'message' => 'Renomeado com sucesso.'];
    }

    /**
     * Mover arquivo ou pasta para outra pasta
     */
    public function move(string $type, int $id, ?int $targetFolderId): array {
        if ($type === 'folder') {
            if ($id === $targetFolderId) {
                return ['success' => false, 'message' => 'Não é possível mover pasta para dentro dela mesma.'];
            }
            $this->db->prepare("UPDATE folders SET parent_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                ->execute([$targetFolderId, $id, $this->userId]);
            AuditLog::log('move', 'folder', $id, null, ['target' => $targetFolderId]);
        } else {
            $this->db->prepare("UPDATE files SET folder_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ?")
                ->execute([$targetFolderId, $id, $this->userId]);
            AuditLog::log('move', 'file', $id, null, ['target' => $targetFolderId]);
        }
        return ['success' => true, 'message' => 'Movido com sucesso.'];
    }

    /**
     * Copiar arquivo
     */
    public function copyFile(int $fileId, ?int $targetFolderId = null): array {
        $file = $this->getFile($fileId);
        if (!$file) return ['success' => false, 'message' => 'Arquivo não encontrado.'];

        // Verificar quota
        $user = $this->getUser();
        if (($user['storage_used'] + $file['size']) > $user['storage_quota']) {
            return ['success' => false, 'message' => 'Espaço insuficiente para copiar.'];
        }

        // Copiar arquivo físico
        $srcPath = STORAGE_PATH . '/' . $file['storage_path'];
        $newStoredName = uniqid('cv_', true) . '.' . $file['extension'];
        $subDir = date('Y/m');
        $newStoragePath = $this->userId . '/' . $subDir . '/' . $newStoredName;
        $destDir = STORAGE_PATH . '/' . $this->userId . '/' . $subDir;
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        if (!copy($srcPath, $destDir . '/' . $newStoredName)) {
            return ['success' => false, 'message' => 'Erro ao copiar arquivo.'];
        }

        // Nome com sufixo "- Cópia"
        $newName = $this->resolveNameConflict($file['original_name'], $targetFolderId ?? $file['folder_id']);

        $stmt = $this->db->prepare("
            INSERT INTO files (original_name, stored_name, extension, mime_type, size, hash_sha256, folder_id, user_id, storage_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $newName, $newStoredName, $file['extension'], $file['mime_type'],
            $file['size'], $file['hash_sha256'], $targetFolderId ?? $file['folder_id'], $this->userId, $newStoragePath
        ]);

        $this->updateStorageUsed($file['size']);
        AuditLog::log('copy', 'file', $fileId, $file['original_name']);

        return ['success' => true, 'message' => 'Arquivo copiado com sucesso.', 'id' => (int)$this->db->lastInsertId()];
    }

    /**
     * Mover para lixeira
     */
    public function trash(string $type, int $id): array {
        $table = $type === 'folder' ? 'folders' : 'files';
        $stmt = $this->db->prepare("UPDATE {$table} SET is_trashed = 1, trashed_at = NOW() WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $this->userId]);

        if ($type === 'folder') {
            // Mover conteúdo da pasta para lixeira recursivamente
            $this->trashFolderContents($id);
        }

        AuditLog::log('trash', $type, $id);
        return ['success' => true, 'message' => 'Movido para lixeira.'];
    }

    /**
     * Restaurar da lixeira
     */
    public function restore(string $type, int $id): array {
        $table = $type === 'folder' ? 'folders' : 'files';
        $this->db->prepare("UPDATE {$table} SET is_trashed = 0, trashed_at = NULL WHERE id = ? AND user_id = ?")
            ->execute([$id, $this->userId]);

        AuditLog::log('restore', $type, $id);
        return ['success' => true, 'message' => 'Restaurado com sucesso.'];
    }

    /**
     * Excluir permanentemente
     */
    public function deletePermanently(string $type, int $id): array {
        if ($type === 'file') {
            $file = $this->getFile($id);
            if (!$file) return ['success' => false, 'message' => 'Arquivo não encontrado.'];
            
            // Remover arquivo físico
            $path = STORAGE_PATH . '/' . $file['storage_path'];
            if (file_exists($path)) unlink($path);
            
            // Remover thumbnail
            if ($file['thumbnail_path'] && file_exists(THUMB_PATH . '/' . $file['thumbnail_path'])) {
                unlink(THUMB_PATH . '/' . $file['thumbnail_path']);
            }

            $this->db->prepare("DELETE FROM files WHERE id = ? AND user_id = ?")->execute([$id, $this->userId]);
            $this->updateStorageUsed(-$file['size']);
            AuditLog::log('delete_permanent', 'file', $id, $file['original_name']);
        } else {
            $this->deleteFolderRecursive($id);
            AuditLog::log('delete_permanent', 'folder', $id);
        }

        return ['success' => true, 'message' => 'Excluído permanentemente.'];
    }

    /**
     * Listar lixeira
     */
    public function listTrash(): array {
        $folders = $this->db->prepare("
            SELECT *, 'folder' as item_type FROM folders WHERE user_id = ? AND is_trashed = 1 ORDER BY trashed_at DESC
        ");
        $folders->execute([$this->userId]);

        $files = $this->db->prepare("
            SELECT *, 'file' as item_type FROM files WHERE user_id = ? AND is_trashed = 1 ORDER BY trashed_at DESC
        ");
        $files->execute([$this->userId]);

        return ['folders' => $folders->fetchAll(), 'files' => $files->fetchAll()];
    }

    /**
     * Esvaziar lixeira
     */
    public function emptyTrash(): array {
        $files = $this->db->prepare("SELECT * FROM files WHERE user_id = ? AND is_trashed = 1");
        $files->execute([$this->userId]);
        $totalFreed = 0;
        foreach ($files->fetchAll() as $file) {
            $path = STORAGE_PATH . '/' . $file['storage_path'];
            if (file_exists($path)) unlink($path);
            $totalFreed += $file['size'];
        }
        $this->db->prepare("DELETE FROM files WHERE user_id = ? AND is_trashed = 1")->execute([$this->userId]);
        $this->db->prepare("DELETE FROM folders WHERE user_id = ? AND is_trashed = 1")->execute([$this->userId]);
        $this->updateStorageUsed(-$totalFreed);

        AuditLog::log('empty_trash', null, null, null, ['freed' => $totalFreed]);
        return ['success' => true, 'message' => 'Lixeira esvaziada.', 'freed' => $totalFreed];
    }

    // ========================================================
    // FAVORITOS E RECENTES
    // ========================================================

    public function toggleFavorite(string $type, int $id): array {
        $col = $type === 'folder' ? 'folder_id' : 'file_id';
        $check = $this->db->prepare("SELECT id FROM favorites WHERE user_id = ? AND {$col} = ?");
        $check->execute([$this->userId, $id]);

        if ($check->fetch()) {
            $this->db->prepare("DELETE FROM favorites WHERE user_id = ? AND {$col} = ?")->execute([$this->userId, $id]);
            return ['success' => true, 'favorited' => false];
        } else {
            $this->db->prepare("INSERT INTO favorites (user_id, {$col}) VALUES (?, ?)")->execute([$this->userId, $id]);
            return ['success' => true, 'favorited' => true];
        }
    }

    public function listFavorites(): array {
        $folders = $this->db->prepare("
            SELECT f.*, 'folder' as item_type, 1 as is_favorited
            FROM folders f JOIN favorites fav ON fav.folder_id = f.id
            WHERE fav.user_id = ? AND f.is_trashed = 0 ORDER BY f.name
        ");
        $folders->execute([$this->userId]);

        $files = $this->db->prepare("
            SELECT fi.*, 'file' as item_type, 1 as is_favorited
            FROM files fi JOIN favorites fav ON fav.file_id = fi.id
            WHERE fav.user_id = ? AND fi.is_trashed = 0 ORDER BY fi.original_name
        ");
        $files->execute([$this->userId]);

        return ['folders' => $folders->fetchAll(), 'files' => $files->fetchAll()];
    }

    public function listRecent(int $limit = 30): array {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare("
            SELECT fi.*, 'file' as item_type, rf.accessed_at,
                   IF(fav.id IS NOT NULL, 1, 0) as is_favorited
            FROM recent_files rf
            JOIN files fi ON rf.file_id = fi.id
            LEFT JOIN favorites fav ON fav.file_id = fi.id AND fav.user_id = ?
            WHERE rf.user_id = ? AND fi.is_trashed = 0
            ORDER BY rf.accessed_at DESC LIMIT {$limit}
        ");
        $stmt->execute([$this->userId, $this->userId]);
        return $stmt->fetchAll();
    }

    public function addRecent(int $fileId): void {
        try {
            // Remover entrada anterior se existir
            $this->db->prepare("DELETE FROM recent_files WHERE user_id = ? AND file_id = ?")
                ->execute([$this->userId, $fileId]);
            $this->db->prepare("INSERT INTO recent_files (user_id, file_id) VALUES (?, ?)")
                ->execute([$this->userId, $fileId]);
            // Manter apenas os últimos 50
            $count = $this->db->prepare("SELECT COUNT(*) FROM recent_files WHERE user_id = ?");
            $count->execute([$this->userId]);
            if ((int)$count->fetchColumn() > 50) {
                $this->db->prepare("
                    DELETE FROM recent_files WHERE user_id = ? AND id NOT IN (
                        SELECT id FROM (SELECT id FROM recent_files WHERE user_id = ? ORDER BY accessed_at DESC LIMIT 50) t
                    )
                ")->execute([$this->userId, $this->userId]);
            }
        } catch (\Exception $e) {
            error_log("addRecent error: " . $e->getMessage());
        }
    }

    // ========================================================
    // COMPARTILHAMENTO
    // ========================================================

    public function createShare(string $type, int $id, array $options = []): array {
        $col = $type === 'folder' ? 'folder_id' : 'file_id';
        $otherCol = $type === 'folder' ? 'file_id' : 'folder_id';
        $token = bin2hex(random_bytes(32));
        $permission = $options['permission'] ?? 'view';
        $expiresAt = !empty($options['expires_hours']) ? date('Y-m-d H:i:s', time() + $options['expires_hours'] * 3600) : null;
        $passwordHash = !empty($options['password']) ? password_hash($options['password'], PASSWORD_BCRYPT) : null;
        $sharedWith = $options['shared_with'] ?? null;
        $maxDownloads = $options['max_downloads'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO shares ({$col}, {$otherCol}, shared_by, shared_with, token, permission, password_hash, expires_at, max_downloads)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $this->userId, $sharedWith, $token, $permission, $passwordHash, $expiresAt, $maxDownloads]);

        // Marcar como compartilhado
        $table = $type === 'folder' ? 'folders' : 'files';
        $this->db->prepare("UPDATE {$table} SET is_shared = 1 WHERE id = ?")->execute([$id]);

        AuditLog::log('share_create', $type, $id, null, ['permission' => $permission]);

        return [
            'success' => true,
            'token' => $token,
            'link' => APP_URL . '/share.php?token=' . $token,
            'message' => 'Link de compartilhamento gerado.'
        ];
    }

    public function listSharedByMe(): array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   fi.original_name as file_name, fi.extension as file_ext,
                   fo.name as folder_name,
                   u.username as shared_with_name
            FROM shares s
            LEFT JOIN files fi ON s.file_id = fi.id
            LEFT JOIN folders fo ON s.folder_id = fo.id
            LEFT JOIN users u ON s.shared_with = u.id
            WHERE s.shared_by = ? AND s.is_active = 1
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll();
    }

    public function listSharedWithMe(): array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   fi.original_name as file_name, fi.extension as file_ext, fi.size as file_size,
                   fo.name as folder_name,
                   u.username as shared_by_name, u.full_name as shared_by_fullname
            FROM shares s
            LEFT JOIN files fi ON s.file_id = fi.id
            LEFT JOIN folders fo ON s.folder_id = fo.id
            JOIN users u ON s.shared_by = u.id
            WHERE s.shared_with = ? AND s.is_active = 1
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$this->userId]);
        return $stmt->fetchAll();
    }

    // ========================================================
    // EDIÇÃO DE CONTEÚDO
    // ========================================================

    /**
     * Lê o conteúdo de um arquivo de texto
     */
    public function readFileContent(int $fileId): ?string {
        $file = $this->getFile($fileId);
        if (!$file) return null;
        $path = STORAGE_PATH . '/' . $file['storage_path'];
        if (!file_exists($path)) return null;
        return file_get_contents($path);
    }

    /**
     * Salva conteúdo em um arquivo de texto
     */
    public function saveFileContent(int $fileId, string $content): array {
        $file = $this->getFile($fileId);
        if (!$file) return ['success' => false, 'message' => 'Arquivo não encontrado.'];

        $editableExts = ['txt','html','css','js','json','xml','csv','md','log','ini','yaml','yml','svg','htaccess','env'];
        if (!in_array($file['extension'], $editableExts)) {
            return ['success' => false, 'message' => 'Este tipo de arquivo não pode ser editado.'];
        }

        $path = STORAGE_PATH . '/' . $file['storage_path'];

        // Salvar versão anterior
        $this->saveVersion($file);

        // Escrever novo conteúdo
        $oldSize = $file['size'];
        $newSize = strlen($content);
        file_put_contents($path, $content);

        $newHash = hash('sha256', $content);
        $this->db->prepare("UPDATE files SET size = ?, hash_sha256 = ?, version = version + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$newSize, $newHash, $fileId]);

        $this->updateStorageUsed($newSize - $oldSize);
        AuditLog::log('edit', 'file', $fileId, $file['original_name']);

        return ['success' => true, 'message' => 'Arquivo salvo com sucesso.', 'size' => $newSize];
    }

    /**
     * Cria um novo arquivo com conteúdo
     */
    public function createFile(string $name, string $content, ?int $folderId = null): array {
        $name = $this->sanitizeName($name);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!$this->isAllowedExtension($extension)) {
            return ['success' => false, 'message' => "Extensão .{$extension} não permitida."];
        }

        $name = $this->resolveNameConflict($name, $folderId);
        $storedName = uniqid('cv_', true) . '.' . $extension;
        $subDir = date('Y/m');
        $storagePath = $this->userId . '/' . $subDir;
        $fullDir = STORAGE_PATH . '/' . $storagePath;
        if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);

        $destination = $fullDir . '/' . $storedName;
        file_put_contents($destination, $content);
        chmod($destination, 0644);

        $size = strlen($content);
        $hash = hash('sha256', $content);
        $mimeType = mime_content_type($destination) ?: 'text/plain';

        $stmt = $this->db->prepare("
            INSERT INTO files (original_name, stored_name, extension, mime_type, size, hash_sha256, folder_id, user_id, storage_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $storedName, $extension, $mimeType, $size, $hash, $folderId, $this->userId, $storagePath . '/' . $storedName]);
        $fileId = (int)$this->db->lastInsertId();
        $this->updateStorageUsed($size);

        AuditLog::log('create_file', 'file', $fileId, $name);
        return ['success' => true, 'id' => $fileId, 'name' => $name, 'message' => 'Arquivo criado com sucesso.'];
    }

    // ========================================================
    // DOWNLOAD / ZIP
    // ========================================================

    /**
     * Retorna o caminho físico de um arquivo para download
     */
    public function getFilePath(int $fileId): ?string {
        $file = $this->getFile($fileId);
        if (!$file) return null;
        $this->addRecent($fileId);
        $this->db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = ?")->execute([$fileId]);
        AuditLog::log('download', 'file', $fileId, $file['original_name']);
        return STORAGE_PATH . '/' . $file['storage_path'];
    }

    /**
     * Cria ZIP de múltiplos arquivos
     */
    public function createZip(array $fileIds, array $folderIds = []): ?string {
        $zipPath = TEMP_PATH . '/' . uniqid('download_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return null;

        foreach ($fileIds as $fid) {
            $file = $this->getFile($fid);
            if ($file) {
                $path = STORAGE_PATH . '/' . $file['storage_path'];
                if (file_exists($path)) {
                    $zip->addFile($path, $file['original_name']);
                }
            }
        }
        foreach ($folderIds as $fid) {
            $this->addFolderToZip($zip, $fid, '');
        }

        $zip->close();
        return file_exists($zipPath) ? $zipPath : null;
    }

    /**
     * Extrai ZIP para pasta
     */
    public function extractZip(int $fileId, ?int $targetFolderId = null): array {
        $file = $this->getFile($fileId);
        if (!$file || $file['extension'] !== 'zip') {
            return ['success' => false, 'message' => 'Arquivo ZIP não encontrado.'];
        }

        $zipPath = STORAGE_PATH . '/' . $file['storage_path'];
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['success' => false, 'message' => 'Erro ao abrir arquivo ZIP.'];
        }

        $extracted = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (substr($entry, -1) === '/') continue; // pular diretórios

            $content = $zip->getFromIndex($i);
            $name = basename($entry);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            
            if (!$this->isAllowedExtension($ext)) continue;
            
            $result = $this->createFile($name, $content, $targetFolderId);
            if ($result['success']) $extracted++;
        }

        $zip->close();
        return ['success' => true, 'message' => "{$extracted} arquivo(s) extraído(s).", 'count' => $extracted];
    }

    // ========================================================
    // PESQUISA
    // ========================================================

    public function search(string $query, array $filters = []): array {
        $like = "%{$query}%";

        $files = $this->db->prepare("
            SELECT fi.*, 'file' as item_type,
                   IF(fav.id IS NOT NULL, 1, 0) as is_favorited
            FROM files fi
            LEFT JOIN favorites fav ON fav.file_id = fi.id AND fav.user_id = ?
            WHERE fi.user_id = ? AND fi.is_trashed = 0 
            AND fi.original_name LIKE ?
            ORDER BY fi.original_name LIMIT 100
        ");
        $files->execute([$this->userId, $this->userId, $like]);

        $folders = $this->db->prepare("
            SELECT f.*, 'folder' as item_type, 0 as is_favorited
            FROM folders f WHERE f.user_id = ? AND f.is_trashed = 0 AND f.name LIKE ?
            ORDER BY f.name LIMIT 50
        ");
        $folders->execute([$this->userId, $like]);

        return ['files' => $files->fetchAll(), 'folders' => $folders->fetchAll()];
    }

    // ========================================================
    // ESTATÍSTICAS
    // ========================================================

    public function getStats(): array {
        $user = $this->getUser();
        $fileCount = $this->db->prepare("SELECT COUNT(*) FROM files WHERE user_id = ? AND is_trashed = 0");
        $fileCount->execute([$this->userId]);
        
        $folderCount = $this->db->prepare("SELECT COUNT(*) FROM folders WHERE user_id = ? AND is_trashed = 0");
        $folderCount->execute([$this->userId]);

        $trashCount = $this->db->prepare("
            SELECT (SELECT COUNT(*) FROM files WHERE user_id = ? AND is_trashed = 1) +
                   (SELECT COUNT(*) FROM folders WHERE user_id = ? AND is_trashed = 1) as total
        ");
        $trashCount->execute([$this->userId, $this->userId]);
        $trashTotal = (int)$trashCount->fetchColumn();

        $typeStats = $this->db->prepare("
            SELECT extension, COUNT(*) as count, SUM(size) as total_size 
            FROM files WHERE user_id = ? AND is_trashed = 0 
            GROUP BY extension ORDER BY total_size DESC LIMIT 10
        ");
        $typeStats->execute([$this->userId]);

        return [
            'storage_used' => (int)$user['storage_used'],
            'storage_quota' => (int)$user['storage_quota'],
            'storage_percent' => $user['storage_quota'] > 0 ? round($user['storage_used'] / $user['storage_quota'] * 100, 1) : 0,
            'file_count' => (int)$fileCount->fetchColumn(),
            'folder_count' => (int)$folderCount->fetchColumn(),
            'trash_count' => $trashTotal,
            'type_stats' => $typeStats->fetchAll()
        ];
    }

    // ========================================================
    // MÉTODOS AUXILIARES (PRIVADOS)
    // ========================================================

    private function getUser(): array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$this->userId]);
        return $stmt->fetch();
    }

    private function updateStorageUsed(int $delta): void {
        $this->db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) + ?) WHERE id = ?")
            ->execute([$delta, $this->userId]);
    }

    private function sanitizeName(string $name): string {
        $name = trim($name);
        $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
        $name = preg_replace('/\.{2,}/', '.', $name);
        return mb_substr($name, 0, 255);
    }

    private function generateSlug(string $name): string {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    private function isAllowedExtension(string $ext): bool {
        // Only block server-side executable scripts (could be run by Apache/Nginx)
        $blocked = ['php','phtml','php3','php4','php5','phps','phar'];
        if (in_array($ext, $blocked)) return false;
        return true;
    }

    public function resolveNameConflict(string $name, ?int $folderId): string {
        $baseName = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $newName = $name;
        $counter = 1;

        while (true) {
            $check = $this->db->prepare("
                SELECT id FROM files WHERE original_name = ? AND user_id = ? AND is_trashed = 0 AND "
                . ($folderId ? "folder_id = ?" : "folder_id IS NULL")
            );
            $params = [$newName, $this->userId];
            if ($folderId) $params[] = $folderId;
            $check->execute($params);
            if (!$check->fetch()) break;
            $newName = $baseName . " ({$counter})" . ($ext ? ".{$ext}" : '');
            $counter++;
        }
        return $newName;
    }

    private function deleteByName(string $name, ?int $folderId): void {
        $stmt = $this->db->prepare("
            SELECT * FROM files WHERE original_name = ? AND user_id = ? AND "
            . ($folderId ? "folder_id = ?" : "folder_id IS NULL")
        );
        $params = [$name, $this->userId];
        if ($folderId) $params[] = $folderId;
        $stmt->execute($params);
        $existing = $stmt->fetch();
        if ($existing) {
            $this->deletePermanently('file', $existing['id']);
        }
    }

    private function trashFolderContents(int $folderId): void {
        $this->db->prepare("UPDATE files SET is_trashed = 1, trashed_at = NOW() WHERE folder_id = ? AND user_id = ?")
            ->execute([$folderId, $this->userId]);
        $sub = $this->db->prepare("SELECT id FROM folders WHERE parent_id = ? AND user_id = ?");
        $sub->execute([$folderId, $this->userId]);
        foreach ($sub->fetchAll() as $sf) {
            $this->db->prepare("UPDATE folders SET is_trashed = 1, trashed_at = NOW() WHERE id = ?")->execute([$sf['id']]);
            $this->trashFolderContents($sf['id']);
        }
    }

    private function deleteFolderRecursive(int $folderId): void {
        $files = $this->db->prepare("SELECT * FROM files WHERE folder_id = ? AND user_id = ?");
        $files->execute([$folderId, $this->userId]);
        foreach ($files->fetchAll() as $f) {
            $this->deletePermanently('file', $f['id']);
        }
        $sub = $this->db->prepare("SELECT id FROM folders WHERE parent_id = ? AND user_id = ?");
        $sub->execute([$folderId, $this->userId]);
        foreach ($sub->fetchAll() as $sf) {
            $this->deleteFolderRecursive($sf['id']);
        }
        $this->db->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?")->execute([$folderId, $this->userId]);
    }

    private function saveVersion(array $file): void {
        $stmt = $this->db->prepare("
            INSERT INTO file_versions (file_id, version_number, stored_name, storage_path, size, hash_sha256, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        // Copiar arquivo atual como versão
        $versionName = 'v' . $file['version'] . '_' . $file['stored_name'];
        $src = STORAGE_PATH . '/' . $file['storage_path'];
        $vDir = STORAGE_PATH . '/' . $this->userId . '/versions';
        if (!is_dir($vDir)) mkdir($vDir, 0755, true);
        @copy($src, $vDir . '/' . $versionName);

        $stmt->execute([
            $file['id'], $file['version'], $versionName,
            $this->userId . '/versions/' . $versionName,
            $file['size'], $file['hash_sha256'], $this->userId
        ]);
    }

    private function generateThumbnail(string $srcPath, int $fileId, string $ext): void {
        try {
            $maxDim = 200;
            list($w, $h) = getimagesize($srcPath);
            if (!$w || !$h) return;

            $ratio = min($maxDim / $w, $maxDim / $h);
            $nw = (int)($w * $ratio);
            $nh = (int)($h * $ratio);

            $thumb = imagecreatetruecolor($nw, $nh);
            switch ($ext) {
                case 'jpg': case 'jpeg': $src = imagecreatefromjpeg($srcPath); break;
                case 'png': 
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                    $src = imagecreatefrompng($srcPath); break;
                case 'gif': $src = imagecreatefromgif($srcPath); break;
                case 'webp': $src = imagecreatefromwebp($srcPath); break;
                default: return;
            }

            imagecopyresampled($thumb, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $thumbName = 'thumb_' . $fileId . '.jpg';
            imagejpeg($thumb, THUMB_PATH . '/' . $thumbName, 85);
            imagedestroy($thumb);
            imagedestroy($src);

            $this->db->prepare("UPDATE files SET thumbnail_path = ? WHERE id = ?")->execute([$thumbName, $fileId]);
        } catch (\Exception $e) {
            // Falha silenciosa na thumbnail
        }
    }

    private function addFolderToZip(ZipArchive $zip, int $folderId, string $basePath): void {
        $folder = $this->getFolder($folderId);
        if (!$folder) return;
        $path = $basePath . $folder['name'] . '/';
        $zip->addEmptyDir($path);

        $files = $this->db->prepare("SELECT * FROM files WHERE folder_id = ? AND user_id = ? AND is_trashed = 0");
        $files->execute([$folderId, $this->userId]);
        foreach ($files->fetchAll() as $f) {
            $fp = STORAGE_PATH . '/' . $f['storage_path'];
            if (file_exists($fp)) $zip->addFile($fp, $path . $f['original_name']);
        }

        $subs = $this->db->prepare("SELECT id FROM folders WHERE parent_id = ? AND user_id = ? AND is_trashed = 0");
        $subs->execute([$folderId, $this->userId]);
        foreach ($subs->fetchAll() as $sf) {
            $this->addFolderToZip($zip, $sf['id'], $path);
        }
    }
}

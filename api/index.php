<?php
/**
 * TCloud - API Principal
 * Endpoint único para todas as operações AJAX
 */

// PRIMEIRO: desativar exibição de erros ANTES de qualquer coisa
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('html_errors', '0');
@ini_set('memory_limit', '256M');
error_reporting(0); // Suprimir TUDO no output

// Output buffer para capturar qualquer vazamento
ob_start();

// Handler que captura TODOS os erros e impede output
set_error_handler(function($severity, $message, $file, $line) {
    error_log("TCloud API Error: [{$severity}] {$message} in {$file}:{$line}");
    return true; // Engolir o erro
});

// Handler de shutdown para erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Limpar QUALQUER output que tenha saído
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Erro interno do servidor: ' . $error['message']
        ]);
    }
});

require_once __DIR__ . '/../bootstrap.php';

// Limpar qualquer output do bootstrap
while (ob_get_level() > 0) ob_end_clean();
ob_start(); // Novo buffer limpo

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Verificar autenticação (exceto login)
$action = input('action', '');
$publicActions = ['login', 'share_access'];

if (!in_array($action, $publicActions) && !Auth::check()) {
    jsonResponse(['success' => false, 'message' => 'Não autenticado.', 'redirect' => 'login.php'], 401);
}

// Validar CSRF em operações de escrita
$csrfExempt = ['login', 'share_access', 'unlock_file', 'gw_discard', 'google_cleanup', 'heartbeat', 'poll_updates'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExempt)) {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('csrf_token', '');
    if (!Auth::validateCsrf($token)) {
        jsonResponse(['success' => false, 'message' => 'Token CSRF inválido.'], 403);
    }
}

$user = Auth::user();
$fm = $user ? new FileManager($user['id']) : null;

try {
    switch ($action) {
        // ==================== AUTH ====================
        case 'login':
            $username = input('username', '');
            $password = input('password', '');
            if (empty($username) || empty($password)) {
                jsonResponse(['success' => false, 'message' => 'Preencha todos os campos.']);
            }
            $result = Auth::attempt($username, $password);
            if ($result['success']) {
                $result['csrf_token'] = Auth::csrfToken();
            }
            jsonResponse($result);
            break;

        case 'logout':
            Auth::logout();
            jsonResponse(['success' => true, 'redirect' => 'login.php']);
            break;

        case 'get_user':
            jsonResponse([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role_name'],
                    'role_slug' => $user['role_slug'],
                    'avatar' => $user['avatar'],
                    'theme' => $user['theme'],
                    'view_mode' => $user['view_mode'],
                ],
                'permissions' => Auth::permissions(),
                'csrf_token' => Auth::csrfToken()
            ]);
            break;

        // ==================== NAVEGAÇÃO ====================
        case 'list':
            $folderId = input('folder_id') ? (int)input('folder_id') : null;
            $result = $fm->listFolder($folderId, [
                'sort'   => input('sort', 'name'),
                'order'  => input('order', 'asc'),
                'type'   => input('type'),
                'search' => input('search')
            ]);
            $result['breadcrumbs'] = $fm->getBreadcrumbs($folderId);
            $result['stats'] = $fm->getStats();
            $result['current_folder'] = $folderId;
            jsonResponse(['success' => true] + $result);
            break;

        case 'search':
            $query = input('query', '');
            if (strlen($query) < 2) jsonResponse(['success' => false, 'message' => 'Busca muito curta.']);
            jsonResponse(['success' => true] + $fm->search($query));
            break;

        // ==================== CRIAR ====================
        case 'create_folder':
            if (!Auth::can('folders.create')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->createFolder(input('name', ''), input('parent_id') ? (int)input('parent_id') : null));
            break;

        case 'create_folder_path':
            if (!Auth::can('folders.create')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $folderPath = input('path', '');
            $baseFolderId = input('folder_id') ? (int)input('folder_id') : null;
            if (empty($folderPath)) jsonResponse(['success' => false, 'message' => 'Caminho vazio.']);
            $parts = array_filter(array_map('trim', explode('/', $folderPath)));
            $parentId = $baseFolderId;
            $db = Database::getInstance();
            foreach ($parts as $folderName) {
                if ($folderName === '') continue;
                $stmt = $db->prepare("SELECT id FROM folders WHERE name = ? AND user_id = ? AND parent_id " . ($parentId ? "= ?" : "IS NULL") . " AND is_trashed = 0");
                $params = [$folderName, $user['id']];
                if ($parentId) $params[] = $parentId;
                $stmt->execute($params);
                $existing = $stmt->fetch();
                if ($existing) {
                    $parentId = (int)$existing['id'];
                } else {
                    $result = $fm->createFolder($folderName, $parentId);
                    if ($result['success']) $parentId = (int)$result['id'];
                }
            }
            jsonResponse(['success' => true, 'folder_id' => $parentId]);
            break;

        case 'create_file':
            if (!Auth::can('files.create_doc')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->createFile(
                input('name', ''),
                input('content', ''),
                input('folder_id') ? (int)input('folder_id') : null
            ));
            break;

        case 'create_office':
            if (!Auth::can('files.create_doc')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $officeName = input('name', '');
            $officeType = strtolower(pathinfo($officeName, PATHINFO_EXTENSION));
            $officeTitle = pathinfo($officeName, PATHINFO_FILENAME);
            $tempFile = TEMP_PATH . '/' . uniqid('office_') . '.' . $officeType;

            $created = false;
            switch ($officeType) {
                case 'docx':
                    $created = OfficeGenerator::createDocx($tempFile, input('content', ''), $officeTitle);
                    break;
                case 'xlsx':
                    $created = OfficeGenerator::createXlsx($tempFile, $officeTitle);
                    break;
                case 'pptx':
                    $created = OfficeGenerator::createPptx($tempFile, $officeTitle);
                    break;
                default:
                    jsonResponse(['success' => false, 'message' => 'Tipo não suportado.']);
            }

            if (!$created) {
                jsonResponse(['success' => false, 'message' => 'Erro ao gerar documento.']);
            }

            // Registrar como arquivo enviado via upload interno
            $fileData = [
                'name'     => $officeName,
                'type'     => mime_content_type($tempFile),
                'tmp_name' => $tempFile,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tempFile),
            ];
            $folderId = input('folder_id') ? (int)input('folder_id') : null;
            $result = $fm->uploadFile($fileData, $folderId, 'rename');
            @unlink($tempFile); // limpar temporário
            jsonResponse($result);
            break;

        // ==================== UPLOAD ====================
        case 'upload':
            if (!Auth::can('files.upload')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            if (empty($_FILES['file'])) jsonResponse(['success' => false, 'message' => 'Nenhum arquivo recebido.']);
            $folderId = input('folder_id') ? (int)input('folder_id') : null;
            $conflict = input('conflict', 'rename');

            // If relative_path is provided, create folder structure first
            $relativePath = input('relative_path', '');
            if ($relativePath) {
                $parts = explode('/', $relativePath);
                array_pop($parts); // remove filename
                $parentId = $folderId;
                foreach ($parts as $folderName) {
                    $folderName = trim($folderName);
                    if ($folderName === '') continue;
                    $db = Database::getInstance();
                    $stmt = $db->prepare("SELECT id FROM folders WHERE name = ? AND user_id = ? AND parent_id " . ($parentId ? "= ?" : "IS NULL") . " AND is_trashed = 0");
                    $params = [$folderName, $user['id']];
                    if ($parentId) $params[] = $parentId;
                    $stmt->execute($params);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $parentId = (int)$existing['id'];
                    } else {
                        $result = $fm->createFolder($folderName, $parentId);
                        if ($result['success']) $parentId = (int)$result['id'];
                    }
                }
                $folderId = $parentId;
            }

            $results = [];
            if (is_array($_FILES['file']['name'])) {
                for ($i = 0; $i < count($_FILES['file']['name']); $i++) {
                    $f = [
                        'name'     => $_FILES['file']['name'][$i],
                        'type'     => $_FILES['file']['type'][$i],
                        'tmp_name' => $_FILES['file']['tmp_name'][$i],
                        'error'    => $_FILES['file']['error'][$i],
                        'size'     => $_FILES['file']['size'][$i],
                    ];
                    $results[] = $fm->uploadFile($f, $folderId, $conflict);
                }
            } else {
                $results[] = $fm->uploadFile($_FILES['file'], $folderId, $conflict);
            }
            jsonResponse(['success' => true, 'results' => $results]);
            break;

        // ==================== CHUNKED UPLOAD (large files) ====================
        case 'upload_chunk':
            if (!Auth::can('files.upload')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            if (empty($_FILES['chunk'])) jsonResponse(['success' => false, 'message' => 'Chunk não recebido.']);
            $uploadId    = input('upload_id', '');
            $chunkIndex  = (int)input('chunk_index', 0);
            $totalChunks = (int)input('total_chunks', 1);
            if (!$uploadId || !preg_match('/^[a-zA-Z0-9_-]+$/', $uploadId)) {
                jsonResponse(['success' => false, 'message' => 'upload_id inválido.']);
            }
            $chunkDir = TEMP_PATH . '/chunks_' . $user['id'] . '_' . $uploadId;
            if (!is_dir($chunkDir)) mkdir($chunkDir, 0755, true);
            $chunkPath = $chunkDir . '/chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
            move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath);
            // Count how many chunks received
            $received = count(glob($chunkDir . '/chunk_*'));
            jsonResponse(['success' => true, 'received' => $received, 'total' => $totalChunks]);
            break;

        case 'upload_merge':
            if (!Auth::can('files.upload')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $uploadId     = input('upload_id', '');
            $fileName     = input('file_name', 'arquivo');
            $totalChunks  = (int)input('total_chunks', 1);
            $folderId     = input('folder_id') ? (int)input('folder_id') : null;
            $conflict     = input('conflict', 'rename');
            $relativePath = input('relative_path', '');

            if (!$uploadId || !preg_match('/^[a-zA-Z0-9_-]+$/', $uploadId)) {
                jsonResponse(['success' => false, 'message' => 'upload_id inválido.']);
            }

            $chunkDir = TEMP_PATH . '/chunks_' . $user['id'] . '_' . $uploadId;
            $received = count(glob($chunkDir . '/chunk_*'));
            if ($received < $totalChunks) {
                jsonResponse(['success' => false, 'message' => "Chunks incompletos: {$received}/{$totalChunks}"]);
            }

            // Create folder structure if relative_path provided
            if ($relativePath) {
                $parts = explode('/', $relativePath);
                array_pop($parts);
                $parentId = $folderId;
                foreach ($parts as $folderName) {
                    $folderName = trim($folderName);
                    if ($folderName === '') continue;
                    $db = Database::getInstance();
                    $stmt = $db->prepare("SELECT id FROM folders WHERE name = ? AND user_id = ? AND parent_id " . ($parentId ? "= ?" : "IS NULL") . " AND is_trashed = 0");
                    $params = [$folderName, $user['id']];
                    if ($parentId) $params[] = $parentId;
                    $stmt->execute($params);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $parentId = (int)$existing['id'];
                    } else {
                        $result = $fm->createFolder($folderName, $parentId);
                        if ($result['success']) $parentId = (int)$result['id'];
                    }
                }
                $folderId = $parentId;
            }

            // Validate extension
            $originalName = basename($fileName);
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            // Handle name conflict
            $originalName = $fm->resolveNameConflict($originalName, $folderId);

            // Prepare storage path
            $storedName = uniqid('cv_', true) . '.' . $extension;
            $subDir = $user['id'] . '/' . date('Y/m');
            $fullDir = STORAGE_PATH . '/' . $subDir;
            if (!is_dir($fullDir)) mkdir($fullDir, 0755, true);
            $destination = $fullDir . '/' . $storedName;

            // Stream chunks directly to final destination (no intermediate merge)
            $out = fopen($destination, 'wb');
            if (!$out) {
                jsonResponse(['success' => false, 'message' => 'Erro ao criar arquivo no disco.']);
            }
            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $chunkDir . '/chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
                if (!file_exists($chunkPath)) {
                    fclose($out); @unlink($destination);
                    jsonResponse(['success' => false, 'message' => "Chunk {$i} ausente."]);
                }
                $in = fopen($chunkPath, 'rb');
                while (!feof($in)) {
                    $data = fread($in, 8 * 1024 * 1024);
                    fwrite($out, $data);
                    $totalSize += strlen($data);
                }
                fclose($in);
            }
            fclose($out);
            @chmod($destination, 0644);

            // Verify file was written correctly
            $actualSize = filesize($destination);
            if ($actualSize < 1) {
                @unlink($destination);
                jsonResponse(['success' => false, 'message' => 'Erro: arquivo vazio após merge.']);
            }

            // Quick hash (first 1MB only for large files, full for small)
            if ($actualSize > 500 * 1024 * 1024) {
                $h = fopen($destination, 'rb');
                $hash = hash('sha256', fread($h, 1024 * 1024) . '_size_' . $actualSize);
                fclose($h);
            } else {
                $hash = hash_file('sha256', $destination);
            }

            $mimeType = @mime_content_type($destination) ?: 'application/octet-stream';

            // Check quota
            $userInfo = Auth::user();
            if (($userInfo['storage_used'] + $actualSize) > $userInfo['storage_quota']) {
                @unlink($destination);
                jsonResponse(['success' => false, 'message' => 'Espaço de armazenamento insuficiente.']);
            }

            // Insert into database
            $db = Database::getInstance();
            $db->prepare("INSERT INTO files (original_name, stored_name, extension, mime_type, size, hash_sha256, folder_id, user_id, storage_path) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$originalName, $storedName, $extension, $mimeType, $actualSize, $hash, $folderId, $user['id'], $subDir . '/' . $storedName]);
            $fileId = (int)$db->lastInsertId();

            $db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?")
                ->execute([$actualSize, $user['id']]);

            AuditLog::log('upload', 'file', $fileId, $originalName, ['size' => $actualSize, 'chunked' => true]);

            // Cleanup chunks
            array_map('unlink', glob($chunkDir . '/chunk_*'));
            @rmdir($chunkDir);

            jsonResponse([
                'success' => true,
                'message' => 'Arquivo enviado com sucesso.',
                'id' => $fileId,
                'name' => $originalName,
                'size' => $actualSize
            ]);
            break;

        // ==================== OPERAÇÕES ====================
        case 'rename':
            if (!Auth::can('files.rename')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->rename(input('type', 'file'), (int)input('id'), input('name', '')));
            break;

        case 'move':
            if (!Auth::can('files.move')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $items = json_decode(input('items', '[]'), true);
            $target = input('target_folder_id') !== null && input('target_folder_id') !== '' 
                ? (int)input('target_folder_id') : null;
            $results = [];
            foreach ($items as $item) {
                $results[] = $fm->move($item['type'], (int)$item['id'], $target);
            }
            jsonResponse(['success' => true, 'results' => $results, 'message' => 'Itens movidos.']);
            break;

        case 'copy':
            if (!Auth::can('files.copy')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $items = json_decode(input('items', '[]'), true);
            $target = input('target_folder_id') ? (int)input('target_folder_id') : null;
            $results = [];
            foreach ($items as $item) {
                if ($item['type'] === 'file') {
                    $results[] = $fm->copyFile((int)$item['id'], $target);
                }
            }
            jsonResponse(['success' => true, 'results' => $results, 'message' => 'Itens copiados.']);
            break;

        case 'trash':
            if (!Auth::can('files.delete')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $items = json_decode(input('items', '[]'), true);
            foreach ($items as $item) {
                $fm->trash($item['type'], (int)$item['id']);
            }
            jsonResponse(['success' => true, 'message' => 'Movido para lixeira.']);
            break;

        case 'restore':
            if (!Auth::can('files.restore')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $items = json_decode(input('items', '[]'), true);
            foreach ($items as $item) {
                $fm->restore($item['type'], (int)$item['id']);
            }
            jsonResponse(['success' => true, 'message' => 'Restaurado com sucesso.']);
            break;

        case 'delete_permanent':
            if (!Auth::can('files.delete')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $items = json_decode(input('items', '[]'), true);
            foreach ($items as $item) {
                $fm->deletePermanently($item['type'], (int)$item['id']);
            }
            jsonResponse(['success' => true, 'message' => 'Excluído permanentemente.']);
            break;

        case 'empty_trash':
            if (!Auth::can('trash.access')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->emptyTrash());
            break;

        case 'list_trash':
            if (!Auth::can('trash.access')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse(['success' => true] + $fm->listTrash());
            break;

        // ==================== FAVORITOS / RECENTES ====================
        case 'toggle_favorite':
            jsonResponse($fm->toggleFavorite(input('type', 'file'), (int)input('id')));
            break;

        case 'list_favorites':
            jsonResponse(['success' => true] + $fm->listFavorites());
            break;

        case 'list_recent':
            jsonResponse(['success' => true, 'files' => $fm->listRecent()]);
            break;

        // ==================== VISUALIZAÇÃO / EDIÇÃO ====================
        case 'get_file':
            $file = $fm->getFile((int)input('id'));
            if (!$file) jsonResponse(['success' => false, 'message' => 'Arquivo não encontrado.'], 404);
            $fm->addRecent($file['id']);
            jsonResponse(['success' => true, 'file' => $file]);
            break;

        case 'read_content':
            if (!Auth::can('files.view')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $content = $fm->readFileContent((int)input('id'));
            if ($content === null) jsonResponse(['success' => false, 'message' => 'Erro ao ler arquivo.']);
            $file = $fm->getFile((int)input('id'));
            jsonResponse([
                'success' => true,
                'content' => $content,
                'file' => $file,
                'mode' => getEditorMode($file['extension'] ?? 'txt')
            ]);
            break;

        case 'save_content':
            if (!Auth::can('files.edit')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->saveFileContent((int)input('id'), input('content', '')));
            break;

        // ==================== COMPARTILHAMENTO ====================
        case 'create_share':
            if (!Auth::can('files.share')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            jsonResponse($fm->createShare(
                input('type', 'file'),
                (int)input('id'),
                [
                    'permission' => input('permission', 'view'),
                    'expires_hours' => input('expires_hours') ? (int)input('expires_hours') : null,
                    'password' => input('password'),
                    'shared_with' => input('shared_with') ? (int)input('shared_with') : null,
                    'max_downloads' => input('max_downloads') ? (int)input('max_downloads') : null,
                ]
            ));
            break;

        case 'list_shared':
            jsonResponse([
                'success' => true,
                'shared_by_me' => $fm->listSharedByMe(),
                'shared_with_me' => $fm->listSharedWithMe()
            ]);
            break;

        // ==================== DOWNLOAD ====================
        case 'download_zip':
            $fileIds = json_decode(input('file_ids', '[]'), true);
            $folderIds = json_decode(input('folder_ids', '[]'), true);
            $zipPath = $fm->createZip($fileIds, $folderIds);
            if (!$zipPath) jsonResponse(['success' => false, 'message' => 'Erro ao criar ZIP.']);
            jsonResponse(['success' => true, 'zip_token' => basename($zipPath)]);
            break;

        case 'list_folder_files':
            if (!Auth::can('files.view')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $folderId = (int)input('folder_id');
            $db = Database::getInstance();
            $allFiles = [];
            $getFolderFiles = function($fid, $basePath) use (&$getFolderFiles, $db, $user, &$allFiles) {
                // Get files in this folder
                $stmt = $db->prepare("SELECT id, original_name, size FROM files WHERE folder_id = ? AND user_id = ? AND is_trashed = 0");
                $stmt->execute([$fid, $user['id']]);
                while ($f = $stmt->fetch()) {
                    $allFiles[] = ['id' => (int)$f['id'], 'name' => $f['original_name'], 'path' => $basePath . $f['original_name'], 'size' => (int)$f['size']];
                }
                // Recurse into subfolders
                $stmt2 = $db->prepare("SELECT id, name FROM folders WHERE parent_id = ? AND user_id = ? AND is_trashed = 0");
                $stmt2->execute([$fid, $user['id']]);
                while ($sub = $stmt2->fetch()) {
                    $getFolderFiles((int)$sub['id'], $basePath . $sub['name'] . '/');
                }
            };
            // Get folder name
            $folderStmt = $db->prepare("SELECT name FROM folders WHERE id = ? AND user_id = ?");
            $folderStmt->execute([$folderId, $user['id']]);
            $folderRow = $folderStmt->fetch();
            $folderName = $folderRow ? $folderRow['name'] : 'pasta';
            $getFolderFiles($folderId, $folderName . '/');
            $totalSize = array_sum(array_column($allFiles, 'size'));
            jsonResponse(['success' => true, 'files' => $allFiles, 'folder_name' => $folderName, 'total_size' => $totalSize, 'total_files' => count($allFiles)]);
            break;

        case 'extract_zip':
            jsonResponse($fm->extractZip((int)input('id'), input('folder_id') ? (int)input('folder_id') : null));
            break;

        // ==================== DOCUMENT EDITOR ====================
        case 'doc_open':
            if (!Auth::can('files.view')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $docEditor = new DocumentEditor($user['id']);
            jsonResponse($docEditor->openDocument((int)input('id')));
            break;

        case 'doc_save':
            if (!Auth::can('files.edit')) jsonResponse(['success' => false, 'message' => 'Sem permissão para editar.'], 403);
            try {
                $docEditor = new DocumentEditor($user['id']);
                jsonResponse($docEditor->saveDocument(
                    (int)input('id'),
                    input('content', ''),
                    input('content_type', 'html')
                ));
            } catch (\Throwable $e) {
                jsonResponse(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
            }
            break;

        case 'doc_save_as':
            if (!Auth::can('files.create_doc')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            try {
                $docEditor = new DocumentEditor($user['id']);
                jsonResponse($docEditor->saveAs(
                    (int)input('id'),
                    input('name', ''),
                    input('content', ''),
                    input('content_type', 'html'),
                    input('folder_id') ? (int)input('folder_id') : null
                ));
            } catch (\Throwable $e) {
                jsonResponse(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
            }
            break;

        case 'doc_versions':
            $docEditor = new DocumentEditor($user['id']);
            jsonResponse(['success' => true, 'versions' => $docEditor->getVersions((int)input('id'))]);
            break;

        case 'doc_restore_version':
            if (!Auth::can('files.edit')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $docEditor = new DocumentEditor($user['id']);
            jsonResponse($docEditor->restoreVersion((int)input('id'), (int)input('version_id')));
            break;

        // ==================== REAL-TIME & FILE LOCKS ====================
        case 'poll_updates':
            $db = Database::getInstance();
            $folderId = input('folder_id') ? (int)input('folder_id') : null;
            // Create file_locks table if not exists
            $db->exec("CREATE TABLE IF NOT EXISTS `file_locks` (
                `file_id` INT UNSIGNED NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `user_name` VARCHAR(100) DEFAULT '',
                `locked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `heartbeat_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Clean expired locks (no heartbeat for 60s)
            $db->exec("DELETE FROM file_locks WHERE heartbeat_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)");
            // Get folder state hash (quick checksum of file/folder changes)
            $stmt = $db->prepare("SELECT MAX(updated_at) as last_change, COUNT(*) as total FROM files WHERE user_id = ? AND folder_id " . ($folderId ? "= ?" : "IS NULL") . " AND is_trashed = 0");
            $params = [$user['id']];
            if ($folderId) $params[] = $folderId;
            $stmt->execute($params);
            $fileState = $stmt->fetch();
            $stmt2 = $db->prepare("SELECT MAX(updated_at) as last_change, COUNT(*) as total FROM folders WHERE user_id = ? AND parent_id " . ($folderId ? "= ?" : "IS NULL") . " AND is_trashed = 0");
            $stmt2->execute($params);
            $folderState = $stmt2->fetch();
            $hash = md5(($fileState['last_change']??'') . ($fileState['total']??0) . ($folderState['last_change']??'') . ($folderState['total']??0));
            // Get all active locks
            $locks = $db->query("SELECT file_id, user_id, user_name FROM file_locks")->fetchAll(PDO::FETCH_ASSOC);
            // Periodically cleanup stale Google Drive temp files (1 in 100 polls ≈ every ~8 min)
            if (rand(1, 100) === 1) {
                try { $gw = new GoogleWorkspace(); $gw->cleanupStale(); } catch (\Exception $e) {}
            }
            jsonResponse(['success' => true, 'hash' => $hash, 'locks' => $locks, 'my_id' => $user['id']]);
            break;

        case 'lock_file':
            $db = Database::getInstance();
            $fileId = (int)input('id');
            $db->exec("CREATE TABLE IF NOT EXISTS `file_locks` (
                `file_id` INT UNSIGNED NOT NULL PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `user_name` VARCHAR(100) DEFAULT '',
                `locked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `heartbeat_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->exec("DELETE FROM file_locks WHERE heartbeat_at < DATE_SUB(NOW(), INTERVAL 60 SECOND)");
            // Check if already locked by someone else
            $stmt = $db->prepare("SELECT user_id, user_name FROM file_locks WHERE file_id = ?");
            $stmt->execute([$fileId]);
            $existing = $stmt->fetch();
            if ($existing && (int)$existing['user_id'] !== (int)$user['id']) {
                jsonResponse(['success' => false, 'locked' => true, 'locked_by' => $existing['user_name']]);
            }
            $db->prepare("REPLACE INTO file_locks (file_id, user_id, user_name, locked_at, heartbeat_at) VALUES (?, ?, ?, NOW(), NOW())")
                ->execute([$fileId, $user['id'], $user['full_name']]);
            jsonResponse(['success' => true]);
            break;

        case 'unlock_file':
            $db = Database::getInstance();
            $db->prepare("DELETE FROM file_locks WHERE file_id = ? AND user_id = ?")
                ->execute([(int)input('id'), $user['id']]);
            jsonResponse(['success' => true]);
            break;

        case 'heartbeat':
            $db = Database::getInstance();
            $db->prepare("UPDATE file_locks SET heartbeat_at = NOW() WHERE user_id = ?")
                ->execute([$user['id']]);
            jsonResponse(['success' => true]);
            break;

        case 'office_editor':
            if (!Auth::can('files.view')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $file = $fm->getFile((int)input('id'));
            if (!$file) jsonResponse(['success' => false, 'message' => 'Arquivo não encontrado.'], 404);
            $oo = new OnlyOfficeIntegration();
            if ($oo->isAvailable()) {
                $mode = Auth::can('files.edit') ? (input('mode', 'edit')) : 'view';
                $config = $oo->getEditorConfig($file, $user, $mode);
                jsonResponse($config);
            } else {
                jsonResponse(['success' => true, 'available' => false, 'message' => 'OnlyOffice não configurado.']);
            }
            break;

        // ==================== GOOGLE WORKSPACE ====================

        case 'google_status':
        case 'gw_check':
            $gw = new GoogleWorkspace();
            $enabled = $gw->isAvailable();
            $hasToken = $enabled ? $gw->isUserAuthorized($user['id']) : false;
            $resp = ['success' => true, 'enabled' => $enabled, 'has_token' => $hasToken];
            if ($enabled && !$hasToken) {
                $resp['auth_url'] = $gw->getAuthUrl($user['id'], input('id') ? (int)input('id') : null);
            }
            jsonResponse($resp);
            break;

        case 'google_auth_url':
            $gw = new GoogleWorkspace();
            if (!$gw->isAvailable()) jsonResponse(['success' => false, 'message' => 'Google Workspace não configurado.']);
            jsonResponse(['success' => true, 'auth_url' => $gw->getAuthUrl($user['id'], input('file_id') ? (int)input('file_id') : null)]);
            break;

        case 'google_upload':
        case 'gw_open':
            if (!Auth::can('files.edit')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $gw = new GoogleWorkspace();
            if (!$gw->isAvailable()) jsonResponse(['success' => false, 'message' => 'Google Workspace não configurado.']);
            jsonResponse($gw->uploadForEditing($user['id'], (int)input('id')));
            break;

        case 'google_save_back':
        case 'gw_save':
            if (!Auth::can('files.edit')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $gw = new GoogleWorkspace();
            jsonResponse($gw->saveBack($user['id'], (int)input('id')));
            break;

        case 'google_cleanup':
        case 'gw_discard':
            $gw = new GoogleWorkspace();
            jsonResponse($gw->cleanup($user['id'], (int)input('id')));
            break;

        case 'google_disconnect':
            $gw = new GoogleWorkspace();
            jsonResponse($gw->disconnect($user['id']));
            break;

        // ==================== ADMIN ====================
        case 'admin_list_users':
            if (!Auth::can('users.manage')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $db = Database::getInstance();
            $stmt = $db->query("
                SELECT u.*, r.name as role_name, r.slug as role_slug,
                       (SELECT COUNT(*) FROM files WHERE user_id = u.id) as file_count
                FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.created_at DESC
            ");
            jsonResponse(['success' => true, 'users' => $stmt->fetchAll()]);
            break;

        case 'admin_save_user':
            if (!Auth::can('users.manage')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $db = Database::getInstance();
            $id = input('id') ? (int)input('id') : null;
            $data = [
                'username' => input('username'),
                'email' => input('email'),
                'full_name' => input('full_name'),
                'role_id' => (int)input('role_id'),
                'status' => input('status', 'active'),
                'storage_quota' => (int)input('storage_quota', 1073741824),
            ];

            if ($id) {
                $sets = [];
                $params = [];
                foreach ($data as $k => $v) {
                    $sets[] = "{$k} = ?";
                    $params[] = $v;
                }
                if (input('password')) {
                    $sets[] = 'password_hash = ?';
                    $params[] = password_hash(input('password'), PASSWORD_BCRYPT, ['cost' => 12]);
                }
                $params[] = $id;
                $db->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                AuditLog::log('user_update', 'user', $id, $data['username']);
            } else {
                if (!input('password')) jsonResponse(['success' => false, 'message' => 'Senha obrigatória.']);
                $data['password_hash'] = password_hash(input('password'), PASSWORD_BCRYPT, ['cost' => 12]);
                $cols = array_keys($data);
                $placeholders = str_repeat('?,', count($cols) - 1) . '?';
                $db->prepare("INSERT INTO users (" . implode(',', $cols) . ") VALUES ({$placeholders})")
                   ->execute(array_values($data));
                $id = (int)$db->lastInsertId();
                AuditLog::log('user_create', 'user', $id, $data['username']);
            }
            jsonResponse(['success' => true, 'id' => $id, 'message' => 'Usuário salvo.']);
            break;

        case 'admin_delete_user':
            if (!Auth::can('users.manage')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $id = (int)input('id');
            if ($id === $user['id']) jsonResponse(['success' => false, 'message' => 'Não é possível excluir a si mesmo.']);
            $db = Database::getInstance();
            $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$id]);
            AuditLog::log('user_deactivate', 'user', $id);
            jsonResponse(['success' => true, 'message' => 'Usuário desativado.']);
            break;

        case 'admin_logs':
            if (!Auth::can('logs.view')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $result = AuditLog::search([
                'user_id' => input('user_id'),
                'action' => input('filter_action'),
                'date_from' => input('date_from'),
                'date_to' => input('date_to'),
                'search' => input('search'),
            ], (int)input('limit', 50), (int)input('offset', 0));
            jsonResponse(['success' => true] + $result);
            break;

        case 'admin_dashboard':
            if (!Auth::can('users.manage')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $db = Database::getInstance();
            $totalUsers = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
            $totalFiles = $db->query("SELECT COUNT(*) FROM files WHERE is_trashed = 0")->fetchColumn();
            $totalStorage = $db->query("SELECT SUM(storage_used) FROM users")->fetchColumn();
            $recentLogs = $db->query("
                SELECT al.*, u.username FROM audit_logs al 
                LEFT JOIN users u ON al.user_id = u.id 
                ORDER BY al.created_at DESC LIMIT 20
            ")->fetchAll();
            $topUsers = $db->query("
                SELECT username, full_name, storage_used, storage_quota,
                       (SELECT COUNT(*) FROM files WHERE user_id = u.id) as files
                FROM users u WHERE status = 'active' ORDER BY storage_used DESC LIMIT 10
            ")->fetchAll();
            jsonResponse([
                'success' => true,
                'total_users' => (int)$totalUsers,
                'total_files' => (int)$totalFiles,
                'total_storage' => (int)($totalStorage ?? 0),
                'recent_logs' => $recentLogs,
                'top_users' => $topUsers
            ]);
            break;

        case 'admin_roles':
            $db = Database::getInstance();
            jsonResponse(['success' => true, 'roles' => $db->query("SELECT * FROM roles ORDER BY id")->fetchAll()]);
            break;

        case 'admin_settings':
            if (!Auth::can('settings.manage')) jsonResponse(['success' => false, 'message' => 'Sem permissão.'], 403);
            $db = Database::getInstance();
            // Ensure Google Workspace settings/tables exist
            try { new GoogleWorkspace(); } catch (\Exception $e) {}
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('settings')) {
                $settings = json_decode(input('settings'), true);
                if (is_array($settings)) {
                    foreach ($settings as $key => $value) {
                        $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")
                           ->execute([$value, $key]);
                    }
                    AuditLog::log('settings_update', null, null, null, ['keys' => array_keys($settings)]);
                    jsonResponse(['success' => true, 'message' => 'Configurações salvas.']);
                }
            }
            $stmt = $db->query("SELECT * FROM system_settings ORDER BY category, setting_key");
            jsonResponse(['success' => true, 'settings' => $stmt->fetchAll()]);
            break;

        case 'save_preferences':
            $db = Database::getInstance();
            $theme = in_array(input('theme'), ['dark','light','auto']) ? input('theme') : 'dark';
            $viewMode = in_array(input('view_mode'), ['list','grid']) ? input('view_mode') : 'list';
            $db->prepare("UPDATE users SET theme = ?, view_mode = ? WHERE id = ?")
               ->execute([$theme, $viewMode, $user['id']]);
            jsonResponse(['success' => true]);
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Ação desconhecida.'], 400);
    }
} catch (\Exception $e) {
    error_log("API Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => APP_DEBUG ? $e->getMessage() : 'Erro interno.'], 500);
}

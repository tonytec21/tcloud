<?php
/**
 * TCloud — Background ZIP preparation
 * Runs as a background process to create large ZIP files without blocking the UI.
 * Usage: php prepare_download.php <job_id>
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !isset($_SERVER['TCLOUD_BG'])) {
    http_response_code(403);
    exit('Background only.');
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../bootstrap.php';

$jobId = $argv[1] ?? ($_GET['job_id'] ?? '');
if (!$jobId || !preg_match('/^[a-zA-Z0-9_-]+$/', $jobId)) {
    exit('Invalid job_id');
}

$statusFile = TEMP_PATH . '/dlprep_' . $jobId . '.json';
$zipPath = TEMP_PATH . '/dlzip_' . $jobId . '.zip';

// Read job data
if (!file_exists($statusFile)) exit('No status file');
$job = json_decode(file_get_contents($statusFile), true);
if (!$job || empty($job['user_id'])) exit('Invalid job');

$userId = (int)$job['user_id'];
$folderIds = $job['folder_ids'] ?? [];
$fileIds = $job['file_ids'] ?? [];
$folderName = $job['folder_name'] ?? 'download';

// Update status
function updateStatus(string $file, string $status, int $processed = 0, int $total = 0, string $current = '', int $size = 0) {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $data['status'] = $status;
    $data['processed'] = $processed;
    $data['total'] = $total;
    $data['current'] = $current;
    $data['size'] = $size;
    $data['updated_at'] = time();
    file_put_contents($file, json_encode($data));
}

try {
    $fm = new FileManager($userId);
    
    // Collect all files
    updateStatus($statusFile, 'scanning', 0, 0, 'Listando arquivos...');
    
    $allFiles = [];
    
    // Add individual files
    foreach ($fileIds as $fid) {
        $file = $fm->getFile((int)$fid);
        if ($file) {
            $path = STORAGE_PATH . '/' . $file['storage_path'];
            if (file_exists($path)) {
                $allFiles[] = ['disk_path' => $path, 'zip_name' => $file['original_name']];
            }
        }
    }
    
    // Add folder files recursively
    $db = Database::getInstance();
    $addFolder = function($folderId, $basePath) use (&$addFolder, $db, $userId, &$allFiles) {
        // Files
        $stmt = $db->prepare("SELECT original_name, storage_path FROM files WHERE folder_id = ? AND user_id = ? AND is_trashed = 0");
        $stmt->execute([$folderId, $userId]);
        while ($f = $stmt->fetch()) {
            $diskPath = STORAGE_PATH . '/' . $f['storage_path'];
            if (file_exists($diskPath)) {
                $allFiles[] = ['disk_path' => $diskPath, 'zip_name' => $basePath . $f['original_name']];
            }
        }
        // Subfolders
        $stmt2 = $db->prepare("SELECT id, name FROM folders WHERE parent_id = ? AND user_id = ? AND is_trashed = 0");
        $stmt2->execute([$folderId, $userId]);
        while ($sub = $stmt2->fetch()) {
            $addFolder((int)$sub['id'], $basePath . $sub['name'] . '/');
        }
    };
    
    foreach ($folderIds as $fid) {
        $stmt = $db->prepare("SELECT name FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$fid, $userId]);
        $folder = $stmt->fetch();
        $name = $folder ? $folder['name'] : 'pasta_' . $fid;
        $addFolder((int)$fid, $name . '/');
    }
    
    $totalFiles = count($allFiles);
    if ($totalFiles === 0) {
        updateStatus($statusFile, 'error', 0, 0, 'Nenhum arquivo encontrado.');
        exit;
    }
    
    updateStatus($statusFile, 'preparing', 0, $totalFiles, 'Iniciando compressão...');
    
    // Create ZIP
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        updateStatus($statusFile, 'error', 0, $totalFiles, 'Erro ao criar ZIP.');
        exit;
    }
    
    foreach ($allFiles as $i => $file) {
        $zip->addFile($file['disk_path'], $file['zip_name']);
        
        // Update status every 50 files or on last file
        if ($i % 50 === 0 || $i === $totalFiles - 1) {
            $currentName = basename($file['zip_name']);
            updateStatus($statusFile, 'preparing', $i + 1, $totalFiles, $currentName);
        }
    }
    
    $zip->close();
    
    $zipSize = file_exists($zipPath) ? filesize($zipPath) : 0;
    
    if ($zipSize < 1) {
        updateStatus($statusFile, 'error', $totalFiles, $totalFiles, 'ZIP vazio.');
        exit;
    }
    
    // Mark as ready
    updateStatus($statusFile, 'ready', $totalFiles, $totalFiles, '', $zipSize);
    
} catch (\Throwable $e) {
    updateStatus($statusFile, 'error', 0, 0, 'Erro: ' . $e->getMessage());
    error_log("prepare_download error: " . $e->getMessage());
}

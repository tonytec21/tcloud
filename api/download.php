<?php
/**
 * TCloud - Endpoint de Download
 */
require_once __DIR__ . '/../bootstrap.php';

// ONLYOFFICE Document Server calls this URL directly (no session)
$isOORequest = isset($_GET['oo']) && $_GET['oo'] === '1';

if (!$isOORequest && !Auth::check()) {
    http_response_code(401);
    exit('Não autenticado.');
}

$user = $isOORequest ? null : Auth::user();
$fm = new FileManager($isOORequest ? 0 : $user['id']);

$type = input('type', 'file');
$id = (int)input('id', 0);
$zipToken = input('zip_token');

// Download de ZIP temporário (prepared download)
if ($zipToken) {
    // Support both old format (download_xxx.zip) and new format (dlzip_xxx.zip via job_id)
    $zipPath = TEMP_PATH . '/' . basename($zipToken);
    // Also check if it's a job_id
    if (!file_exists($zipPath)) {
        $zipPath = TEMP_PATH . '/dlzip_' . basename($zipToken) . '.zip';
    }
    if (!file_exists($zipPath)) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }
    
    $zipName = input('name', 'download') . '.zip';
    $fileSize = filesize($zipPath);
    
    while (ob_get_level()) ob_end_clean();
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $zipName) . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-store');
    
    // Stream in 8MB chunks
    $handle = fopen($zipPath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8 * 1024 * 1024);
            flush();
        }
        fclose($handle);
    }
    
    // Cleanup: delete ZIP and status file after download
    @unlink($zipPath);
    // Try to find and clean status file
    $jobId = str_replace(['dlzip_', '.zip'], '', basename($zipToken));
    @unlink(TEMP_PATH . '/dlprep_' . $jobId . '.json');
    
    exit;
}

// Download de arquivo individual
if ($type === 'file' && $id > 0) {
    $file = $fm->getFile($id);
    if (!$file) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }

    $path = $fm->getFilePath($id);
    if (!$path || !file_exists($path)) {
        http_response_code(404);
        exit('Arquivo não encontrado no disco.');
    }

    $fileSize = filesize($path);

    // Flush all output buffers for clean streaming
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['original_name']) . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    // Stream file in chunks (safe for multi-GB files)
    $handle = fopen($path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8 * 1024 * 1024); // 8MB chunks
            flush();
        }
        fclose($handle);
    }
    exit;
}

// Preview inline (imagens, PDF, etc)
if ($type === 'preview' && $id > 0) {
    $file = $isOORequest ? $fm->getFileById($id) : $fm->getFile($id);
    if (!$file) {
        http_response_code(404);
        exit;
    }
    $path = STORAGE_PATH . '/' . $file['storage_path'];
    if (!file_exists($path)) {
        http_response_code(404);
        exit;
    }
    if (!$isOORequest) $fm->addRecent($id);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-cache');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', $file['original_name']) . '"');
    $h = fopen($path, 'rb');
    if ($h) { while (!feof($h)) { echo fread($h, 8*1024*1024); flush(); } fclose($h); }
    exit;
}

// Thumbnail
if ($type === 'thumb' && $id > 0) {
    $file = $fm->getFile($id);
    if (!$file || !$file['thumbnail_path']) {
        http_response_code(404);
        exit;
    }
    $path = THUMB_PATH . '/' . $file['thumbnail_path'];
    if (!file_exists($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

http_response_code(400);
exit('Requisição inválida.');

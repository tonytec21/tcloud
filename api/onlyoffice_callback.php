<?php
/**
 * TCloud — OnlyOffice Document Server Callback
 * Called by ONLYOFFICE when documents are saved.
 * Must return {"error": 0} for success.
 */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(0);
ob_start();

require_once __DIR__ . '/../bootstrap.php';

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

try {
    $fileId = (int)($_GET['id'] ?? 0);
    if ($fileId <= 0) {
        echo json_encode(['error' => 1]);
        exit;
    }
    $oo = new OnlyOfficeIntegration();
    echo json_encode($oo->handleCallback($fileId));
} catch (\Throwable $e) {
    error_log("OnlyOffice callback error: " . $e->getMessage());
    echo json_encode(['error' => 0]);
}

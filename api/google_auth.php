<?php
/**
 * TCloud — Google OAuth2 Callback
 * 
 * Redirect URI: {APP_URL}/api/google_auth.php
 * 
 * IMPORTANT: This file does NOT require an active session.
 * The user_id comes from the OAuth state parameter.
 * Token is saved directly to the database.
 */

// Load only what we need — NOT full bootstrap (which may check auth)
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/AuditLog.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/FileManager.php';
require_once __DIR__ . '/../core/GoogleWorkspace.php';

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// Handle error from Google
if ($error) {
    $msg = urlencode('Autorização cancelada: ' . $error);
    header('Location: ' . APP_URL . '/index.php?google_error=' . $msg);
    exit;
}

if (empty($code) || empty($state)) {
    header('Location: ' . APP_URL . '/index.php?google_error=' . urlencode('Parâmetros inválidos.'));
    exit;
}

// Process the callback — saves token to DB using user_id from state
$gw = new GoogleWorkspace();
$result = $gw->handleAuthCallback($code, $state);

if (!$result['success']) {
    $msg = urlencode($result['message'] ?? 'Erro na autenticação.');
    header('Location: ' . APP_URL . '/index.php?google_error=' . $msg);
    exit;
}

$fileId = $result['file_id'] ?? null;

// Check if user still has an active session
session_start();
$hasSession = !empty($_SESSION['user_id']) && $_SESSION['user_id'] == $result['user_id'];

if ($hasSession) {
    // Session is active — redirect to index with auto-open
    $url = APP_URL . '/index.php?google_auth=success';
    if ($fileId) $url .= '&google_open_file=' . (int)$fileId;
    header('Location: ' . $url);
} else {
    // Session expired — redirect to login page
    // Token is already saved in DB, so after login the user can open files with Google
    $url = APP_URL . '/login.php?msg=' . urlencode('Conta Google conectada! Faça login para continuar.');
    if ($fileId) $url .= '&google_open_file=' . (int)$fileId;
    header('Location: ' . $url);
}
exit;

<?php
/**
 * TCloud - Bootstrap
 * Carrega configurações e classes do sistema
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/AuditLog.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/FileManager.php';
require_once __DIR__ . '/core/OnlyOfficeIntegration.php';
require_once __DIR__ . '/core/GoogleWorkspace.php';
require_once __DIR__ . '/core/OfficeGenerator.php';
require_once __DIR__ . '/core/DocumentEditor.php';

// Garantir que diretórios existam
foreach ([STORAGE_PATH, TRASH_PATH, TEMP_PATH, THUMB_PATH, LOG_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Iniciar sessão
Auth::init();

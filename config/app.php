<?php
/**
 * TCloud - Configurações Principais
 * Edite conforme seu ambiente
 */

// Modo de depuração (desativar em produção)
define('APP_DEBUG', true);

// Informações do sistema
define('APP_NAME', 'TCloud');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/tcloud');

// Banco de dados
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'tcloud');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Caminhos
define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage/files');
define('TRASH_PATH', BASE_PATH . '/storage/trash');
define('TEMP_PATH', BASE_PATH . '/storage/temp');
define('THUMB_PATH', BASE_PATH . '/public/thumbs');
define('LOG_PATH', BASE_PATH . '/logs');

// Upload
define('MAX_UPLOAD_SIZE', 20 * 1024 * 1024 * 1024); // 20GB
define('CHUNK_SIZE', 5 * 1024 * 1024); // 5MB para chunked upload

// Segurança
define('CSRF_TOKEN_NAME', 'cv_csrf_token');
define('SESSION_NAME', 'tcloud_session');
define('SESSION_LIFETIME', 7200); // 2 horas
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutos

// Hash para nomes de arquivos armazenados
define('STORAGE_HASH_ALGO', 'sha256');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Error reporting
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

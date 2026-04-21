<?php
/**
 * TCloud - Script de Instalação
 * 
 * Execute este arquivo UMA VEZ para criar o banco de dados e configurações iniciais.
 * Depois, EXCLUA este arquivo por segurança.
 * 
 * Uso: php install.php  (via terminal)
 *   ou acesse pelo navegador
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$isWeb = php_sapi_name() !== 'cli';
$output = function($msg) use ($isWeb) {
    echo $isWeb ? "<p>{$msg}</p>" : $msg . "\n";
};

if ($isWeb) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>TCloud - Instalação</title>
    <style>body{font-family:sans-serif;max-width:700px;margin:50px auto;background:#0d0f12;color:#e8eaed;padding:20px}
    h1{color:#6d8cff}p{margin:8px 0;line-height:1.6}.ok{color:#34d399}.err{color:#f87171}.warn{color:#fbbf24}
    code{background:#1a1d24;padding:2px 8px;border-radius:4px}pre{background:#1a1d24;padding:16px;border-radius:8px;overflow-x:auto}</style>
    </head><body><h1>🚀 TCloud — Instalação</h1>';
}

$output("=== TCloud - Instalação ===");

// 1. Verificar requisitos
$output("Verificando requisitos...");

$phpVersion = phpversion();
$output("PHP: {$phpVersion} " . (version_compare($phpVersion, '8.0', '>=') ? '✅' : '❌ Necessário PHP 8+'));

$extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'fileinfo', 'gd', 'zip'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    $output("  Extension {$ext}: " . ($loaded ? '✅' : '❌'));
}

// 2. Carregar configurações
require_once __DIR__ . '/config/app.php';

// 3. Conectar ao banco
$output("\nConectando ao banco de dados...");
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=" . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $output("✅ Conexão bem-sucedida!");
} catch (PDOException $e) {
    $output("❌ Erro: " . $e->getMessage());
    $output("\n⚠️  Edite config/app.php com suas credenciais do MySQL.");
    if ($isWeb) echo '</body></html>';
    exit(1);
}

// 4. Executar SQL
$output("\nCriando banco de dados e tabelas...");
$sql = file_get_contents(__DIR__ . '/migrations/001_schema.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

$errors = 0;
foreach ($statements as $stmt) {
    if (empty($stmt) || $stmt === '') continue;
    try {
        $pdo->exec($stmt);
    } catch (PDOException $e) {
        // Ignorar erros de "already exists"
        if (strpos($e->getMessage(), 'already exists') === false && 
            strpos($e->getMessage(), '1065') === false) {
            $output("⚠️  " . substr($e->getMessage(), 0, 100));
            $errors++;
        }
    }
}

if ($errors === 0) {
    $output("✅ Banco de dados criado com sucesso!");
} else {
    $output("⚠️  Banco criado com {$errors} aviso(s) (podem ser ignorados se tabelas já existiam).");
}

// 5. Criar diretórios
$output("\nCriando diretórios...");
$dirs = [
    STORAGE_PATH, TRASH_PATH, TEMP_PATH, THUMB_PATH, LOG_PATH,
    STORAGE_PATH . '/1'  // pasta do admin
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        $output("  📁 {$dir}");
    }
}
$output("✅ Diretórios criados!");

// 6. Verificar hash de senha do admin
$output("\nVerificando usuário admin...");
$pdo->exec("USE " . DB_NAME);
$check = $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetch();
if ($check) {
    $output("✅ Usuário admin já existe.");
} else {
    $hash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, storage_quota, status) VALUES (?, ?, ?, ?, 1, 10737418240, 'active')")
        ->execute(['admin', 'admin@tcloud.local', $hash, 'Administrador Master']);
    $output("✅ Usuário admin criado!");
}

$output("\n========================================");
$output("✅ INSTALAÇÃO CONCLUÍDA COM SUCESSO!");
$output("========================================");
$output("\n📋 Credenciais padrão:");
$output("   Usuário: admin");
$output("   Senha: Admin@123");
$output("\n⚠️  IMPORTANTE: Exclua este arquivo (install.php) após a instalação!");
$output("📝 Edite config/app.php para configurar o sistema.");

if ($isWeb) {
    echo '<br><br><a href="login.php" style="color:#6d8cff;font-size:18px">→ Acessar TCloud</a>';
    echo '</body></html>';
}

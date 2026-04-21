<?php
/**
 * TCloud - Acesso via Link Compartilhado
 */
require_once __DIR__ . '/bootstrap.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die('Link inválido.');
}

$db = Database::getInstance();
$stmt = $db->prepare("
    SELECT s.*, f.original_name, f.extension, f.mime_type, f.size, f.storage_path,
           fo.name as folder_name, u.full_name as owner_name
    FROM shares s
    LEFT JOIN files f ON s.file_id = f.id
    LEFT JOIN folders fo ON s.folder_id = fo.id
    JOIN users u ON s.shared_by = u.id
    WHERE s.token = ? AND s.is_active = 1
");
$stmt->execute([$token]);
$share = $stmt->fetch();

if (!$share) {
    die('Link não encontrado ou expirado.');
}

// Verificar expiração
if ($share['expires_at'] && strtotime($share['expires_at']) < time()) {
    die('Este link expirou.');
}

// Verificar limite de downloads
if ($share['max_downloads'] && $share['download_count'] >= $share['max_downloads']) {
    die('Limite de downloads atingido.');
}

// Verificar senha
if ($share['password_hash']) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (!password_verify($_POST['password'], $share['password_hash'])) {
            $error = 'Senha incorreta.';
        } else {
            $_SESSION['share_auth_' . $token] = true;
        }
    }
    if (empty($_SESSION['share_auth_' . $token])) {
        ?><!DOCTYPE html>
        <html data-theme="dark"><head><meta charset="UTF-8"><link rel="icon" type="image/svg+xml" href="public/favicon.svg">
    <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Acesso Protegido — TCloud</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="public/css/app.css">
        </head><body>
        <div class="login-page"><div class="login-card">
            <div class="login-logo"><div class="logo-icon"><i class="bi bi-lock-fill"></i></div><h1>Acesso Protegido</h1><p>Este arquivo requer uma senha</p></div>
            <form method="POST">
                <?php if (!empty($error)): ?><div style="color:var(--danger);margin-bottom:12px;font-size:13px"><?= e($error) ?></div><?php endif; ?>
                <div class="form-group"><label>Senha</label><input type="password" name="password" class="form-control" autofocus required></div>
                <button type="submit" class="btn btn-primary btn-lg btn-block">Acessar</button>
            </form>
        </div></div></body></html><?php
        exit;
    }
}

// Download
if (isset($_GET['download']) && $share['file_id']) {
    $path = STORAGE_PATH . '/' . $share['storage_path'];
    if (file_exists($path)) {
        $db->prepare("UPDATE shares SET download_count = download_count + 1 WHERE id = ?")->execute([$share['id']]);
        header('Content-Type: ' . ($share['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $share['original_name']) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}

// Exibir página de compartilhamento
$name = $share['original_name'] ?: $share['folder_name'] ?: 'Item compartilhado';
$isFile = !empty($share['file_id']);
$ext = strtolower($share['extension'] ?? '');
$isImage = in_array($ext, ['jpg','jpeg','png','gif','webp','svg']);
$isPdf = $ext === 'pdf';
$isVideo = in_array($ext, ['mp4','webm']);
?><!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="public/favicon.svg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($name) ?> — TCloud</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/app.css">
</head>
<body>
<div class="login-page" style="flex-direction:column;gap:24px">
    <div class="login-card" style="max-width:600px">
        <div style="text-align:center;margin-bottom:24px">
            <div class="logo-icon" style="display:inline-flex;width:48px;height:48px;background:linear-gradient(135deg,var(--accent),#a78bfa);border-radius:var(--radius);align-items:center;justify-content:center;color:#fff;font-size:20px;margin-bottom:12px">
                <i class="bi bi-cloud-fill"></i>
            </div>
            <h2 style="font-size:18px;margin-bottom:4px"><?= e($name) ?></h2>
            <p style="color:var(--text-muted);font-size:13px">Compartilhado por <?= e($share['owner_name']) ?></p>
        </div>

        <?php if ($isFile): ?>
        <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--radius);margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;font-size:13px">
                <span style="color:var(--text-muted)">Tamanho</span>
                <span><?= formatSize($share['size'] ?? 0) ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:8px">
                <span style="color:var(--text-muted)">Tipo</span>
                <span><?= strtoupper($ext) ?></span>
            </div>
        </div>

        <?php if ($isImage): ?>
            <div style="text-align:center;margin-bottom:16px">
                <img src="api/download.php?type=preview&id=<?= $share['file_id'] ?>" style="max-width:100%;border-radius:var(--radius)" alt="">
            </div>
        <?php elseif ($isPdf): ?>
            <div style="margin-bottom:16px">
                <iframe src="api/download.php?type=preview&id=<?= $share['file_id'] ?>" style="width:100%;height:500px;border:none;border-radius:var(--radius)"></iframe>
            </div>
        <?php endif; ?>

        <?php if (in_array($share['permission'], ['download','edit'])): ?>
            <a href="?token=<?= e($token) ?>&download=1" class="btn btn-primary btn-lg btn-block">
                <i class="bi bi-download"></i> Baixar Arquivo
            </a>
        <?php endif; ?>
        <?php else: ?>
            <p style="text-align:center;color:var(--text-secondary)">Esta é uma pasta compartilhada.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

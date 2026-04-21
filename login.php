<?php
require_once __DIR__ . '/bootstrap.php';
if (Auth::check()) {
    header('Location: index.php');
    exit;
}
// Capture params to pass forward after login
$googleOpenFile = isset($_GET['google_open_file']) ? (int)$_GET['google_open_file'] : '';
$msg = $_GET['msg'] ?? '';
?><!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — TCloud</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="public/css/app.css">
</head>
<body>
<div class="login-page">
    <div class="login-card slide-up">
        <div class="login-logo">
            <div class="logo-icon"><i class="bi bi-cloud-fill"></i></div>
            <h1>TCloud</h1>
            <p>Gerenciador de Arquivos Corporativo</p>
        </div>
        <?php if ($msg): ?>
        <div style="color:var(--success); font-size:13px; margin-bottom:12px; padding:8px 12px; background:var(--success-bg, rgba(52,211,153,0.1)); border-radius:var(--radius-sm); text-align:center">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        <form id="loginForm">
            <div class="form-group">
                <label for="username">Usuário ou E-mail</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Seu usuário ou e-mail" autofocus required>
            </div>
            <div class="form-group">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Sua senha" required>
            </div>
            <div id="loginError" style="display:none; color:var(--danger); font-size:13px; margin-bottom:12px; padding:8px 12px; background:var(--danger-bg); border-radius:var(--radius-sm);"></div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" id="loginBtn">
                <i class="bi bi-box-arrow-in-right"></i> Entrar
            </button>
        </form>
        <div style="text-align:center; margin-top:20px; font-size:12px; color:var(--text-muted);">
            Usuário padrão: <strong>admin</strong> / Senha: <strong>Admin@123</strong>
        </div>
    </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const err = document.getElementById('loginError');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Entrando...';
    err.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('action', 'login');
        fd.append('username', document.getElementById('username').value);
        fd.append('password', document.getElementById('password').value);

        const res = await fetch('api/index.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            // Pass google_open_file forward if present
            var redirect = 'index.php';
            var googleFile = <?= json_encode($googleOpenFile) ?>;
            if (googleFile) {
                redirect += '?google_auth=success&google_open_file=' + googleFile;
            }
            window.location.href = redirect;
        } else {
            err.textContent = data.message;
            err.style.display = 'block';
        }
    } catch (ex) {
        err.textContent = 'Erro de conexão.';
        err.style.display = 'block';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Entrar';
});
</script>
<style>
.spin { animation: spin 1s linear infinite; }
@keyframes spin { from{transform:rotate(0)} to{transform:rotate(360deg)} }
</style>
</body>
</html>

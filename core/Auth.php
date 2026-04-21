<?php
/**
 * TCloud - Sistema de Autenticação
 */

class Auth {
    private static ?array $currentUser = null;

    /**
     * Inicializa a sessão de forma segura
     */
    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Extend session lifetime for long operations (uploads)
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly'  => true,
                'samesite'  => 'Lax'
            ]);
            session_start();
        }
        // Regenerar ID periodicamente (keep old session alive to avoid breaking concurrent requests)
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
        } elseif (time() - $_SESSION['_created'] > 3600) {
            session_regenerate_id(false); // false = keep old session valid
            $_SESSION['_created'] = time();
        }
        // Touch session activity
        $_SESSION['_last_activity'] = time();
    }

    /**
     * Tenta autenticar o usuário
     */
    public static function attempt(string $username, string $password): array {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT u.*, r.slug as role_slug, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE (u.username = :login1 OR u.email = :login2) AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([':login1' => $username, ':login2' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Credenciais inválidas.'];
        }

        // Verificar bloqueio por tentativas
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['success' => false, 'message' => "Conta bloqueada. Tente novamente em {$remaining} minuto(s)."];
        }

        if (!password_verify($password, $user['password_hash'])) {
            // Incrementar tentativas
            $attempts = $user['login_attempts'] + 1;
            $lockUntil = $attempts >= MAX_LOGIN_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + LOCKOUT_DURATION)
                : null;
            $db->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?")
               ->execute([$attempts, $lockUntil, $user['id']]);
            
            $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
            if ($remaining > 0) {
                return ['success' => false, 'message' => "Senha incorreta. {$remaining} tentativa(s) restante(s)."];
            }
            return ['success' => false, 'message' => 'Conta bloqueada por excesso de tentativas.'];
        }

        // Login bem-sucedido
        $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
           ->execute([$user['id']]);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role_slug'];
        session_regenerate_id(true);

        // Log de auditoria
        AuditLog::log('login', 'user', $user['id'], $user['username']);

        return ['success' => true, 'user' => $user];
    }

    /**
     * Retorna o usuário autenticado atual
     */
    public static function user(): ?array {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT u.*, r.slug as role_slug, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND u.status = 'active'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        self::$currentUser = $stmt->fetch() ?: null;
        return self::$currentUser;
    }

    /**
     * Verifica se está autenticado
     */
    public static function check(): bool {
        return self::user() !== null;
    }

    /**
     * Verifica se o usuário tem uma permissão específica
     */
    public static function can(string $permissionSlug): bool {
        $user = self::user();
        if (!$user) return false;
        // Master tem todas as permissões
        if ($user['role_slug'] === 'master') return true;

        $db = Database::getInstance();
        // Checar permissão individual primeiro
        $stmt = $db->prepare("
            SELECT up.granted FROM user_permissions up
            JOIN permissions p ON up.permission_id = p.id
            WHERE up.user_id = ? AND p.slug = ?
        ");
        $stmt->execute([$user['id'], $permissionSlug]);
        $individual = $stmt->fetch();
        if ($individual !== false) {
            return (bool)$individual['granted'];
        }
        // Checar permissão do papel
        $stmt = $db->prepare("
            SELECT 1 FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.slug = ?
        ");
        $stmt->execute([$user['role_id'], $permissionSlug]);
        return $stmt->fetch() !== false;
    }

    /**
     * Encerra a sessão
     */
    public static function logout(): void {
        $user = self::user();
        if ($user) {
            AuditLog::log('logout', 'user', $user['id'], $user['username']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$currentUser = null;
    }

    /**
     * Gera e armazena token CSRF
     */
    public static function csrfToken(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    /**
     * Valida token CSRF
     */
    public static function validateCsrf(string $token): bool {
        return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }

    /**
     * Obtém todas as permissões do usuário atual
     */
    public static function permissions(): array {
        $user = self::user();
        if (!$user) return [];
        if ($user['role_slug'] === 'master') {
            $db = Database::getInstance();
            return $db->query("SELECT slug FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT DISTINCT p.slug FROM permissions p
            LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = ?
            LEFT JOIN user_permissions up ON p.id = up.permission_id AND up.user_id = ?
            WHERE rp.role_id IS NOT NULL OR (up.user_id IS NOT NULL AND up.granted = 1)
        ");
        $stmt->execute([$user['role_id'], $user['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

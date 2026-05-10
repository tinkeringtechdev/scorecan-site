<?php
/**
 * Tiny session-based admin auth.
 * Usage on every protected page:
 *     require __DIR__ . '/../bootstrap.php';
 *     Auth::require();
 */

class Auth {

    /** Attempt to log in. Returns true on success, false otherwise. */
    public static function attempt(string $username, string $password): bool {
        $row = Db::one('SELECT id, username, password_hash, display_name, is_active FROM admins WHERE username = ?', [$username]);
        if (!$row || !$row['is_active']) {
            // Constant-time-ish dummy verify to discourage user-enumeration timing attacks.
            password_verify($password, '$2y$12$/nullnullnullnullnullnullnullnullnullnullnullnullnu');
            return false;
        }
        if (!password_verify($password, $row['password_hash'])) {
            return false;
        }
        // Optional rehash if cost changed.
        if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            Db::exec('UPDATE admins SET password_hash = ? WHERE id = ?', [$newHash, $row['id']]);
        }

        Db::exec('UPDATE admins SET last_login = NOW() WHERE id = ?', [$row['id']]);

        // Regenerate session ID to prevent fixation.
        session_regenerate_id(true);
        $_SESSION['admin'] = [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'] ?? $row['username'],
            'logged_in_at' => time(),
        ];
        return true;
    }

    public static function isLoggedIn(): bool {
        return !empty($_SESSION['admin']['id']);
    }

    public static function user(): ?array {
        return $_SESSION['admin'] ?? null;
    }

    public static function id(): ?int {
        return $_SESSION['admin']['id'] ?? null;
    }

    /** Redirect to login if not authenticated. Call at the top of every admin page. */
    public static function require(): void {
        // IP allow-list check (optional).
        $allow = $GLOBALS['SCORECAN_CONFIG']['admin_ip_allowlist'] ?? null;
        if (is_array($allow) && !empty($allow)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!in_array($ip, $allow, true)) {
                http_response_code(403);
                die('Admin access not permitted from this IP.');
            }
        }
        if (!self::isLoggedIn()) {
            $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . View::url('admin/login.php') . '?back=' . $back);
            exit;
        }
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** Generate a CSRF token for forms. */
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    /** Verify CSRF on POST. die()s on mismatch. */
    public static function checkCsrf(): void {
        $tok = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $tok)) {
            http_response_code(400);
            die('CSRF token mismatch. Refresh the page and try again.');
        }
    }

    /** Convenience: write an audit_log row. */
    public static function audit(string $action, ?string $targetType = null, ?int $targetId = null, array $payload = []): void {
        Db::exec(
            'INSERT INTO audit_log (admin_id, action, target_type, target_id, payload_json) VALUES (?, ?, ?, ?, ?)',
            [self::id(), $action, $targetType, $targetId, json_encode($payload, JSON_UNESCAPED_SLASHES)]
        );
    }
}

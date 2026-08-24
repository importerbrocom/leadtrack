<?php

namespace App\Admin;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Helpers;

/**
 * Cookie-session authentication for the admin web panel.
 *
 * The mobile API uses bearer tokens; the browser panel uses a PHP session and
 * then hands the user row to Core\Auth so all the existing scope/permission
 * helpers (scopeClause, assertCanAccessLead, ...) work unchanged.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = ($_SERVER['HTTPS'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $https,
            'samesite' => 'Lax',
        ]);

        session_name('rlm_admin');
        session_start();
    }

    /** @return array{0:bool,1:?string} [ok, errorMessage] */
    public static function login(string $login, string $password): array
    {
        $digits = Helpers::normalizePhone($login);

        $user = Database::first(
            'SELECT * FROM users
              WHERE email = ? OR phone = ? OR (? IS NOT NULL AND RIGHT(phone, 10) = ?)
              LIMIT 1',
            [$login, $login, $digits, $digits]
        );

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return [false, 'Incorrect phone/email or password'];
        }

        if ((int) $user['is_active'] !== 1) {
            return [false, 'This account has been deactivated'];
        }

        if ($user['role'] === Auth::TELECALLER) {
            return [false, 'Telecallers use the mobile app, not the web panel'];
        }

        session_regenerate_id(true);

        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['last_seen']  = time();
        $_SESSION['user_agent'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

        Database::update('users', ['last_login_at' => Helpers::now()], 'id = ?', [(int) $user['id']]);
        Helpers::log((int) $user['id'], 'admin_panel_login', 'user', (int) $user['id']);

        return [true, null];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], true);
        }

        session_destroy();
    }

    /**
     * Load the logged-in user and seed Core\Auth. Returns null when not
     * logged in or the session has gone stale.
     */
    public static function user(): ?array
    {
        self::start();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        // Idle timeout
        $ttl = ((int) Config::get('auth.session_ttl_minutes', 240)) * 60;
        if (isset($_SESSION['last_seen']) && (time() - (int) $_SESSION['last_seen']) > $ttl) {
            self::logout();

            return null;
        }

        // Bind the session to the browser that created it.
        if (($_SESSION['user_agent'] ?? '') !== substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)) {
            self::logout();

            return null;
        }

        $_SESSION['last_seen'] = time();

        $user = Database::first('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $_SESSION['user_id']]);

        if ($user === null) {
            self::logout();

            return null;
        }

        Auth::setUser($user);

        return $user;
    }

    /** Bounce to the login page unless authenticated. */
    public static function requireLogin(): array
    {
        $user = self::user();

        if ($user === null) {
            header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
            exit;
        }

        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();

        if ($user['role'] !== Auth::ADMIN) {
            http_response_code(403);
            echo '<p style="font-family:sans-serif;padding:2rem">Head-office access only. <a href="index.php">Go back</a></p>';
            exit;
        }

        return $user;
    }

    // ------------------------------------------------------------ CSRF
    public static function csrfToken(): string
    {
        self::start();

        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        self::start();

        $sent = $_POST['_csrf'] ?? '';

        if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
            http_response_code(419);
            exit('Your session expired. Please go back, reload the page and try again.');
        }
    }

    // ------------------------------------------------------------ flash messages
    public static function flash(string $message, string $type = 'success'): void
    {
        self::start();
        $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
    }

    public static function takeFlashes(): array
    {
        self::start();
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $flashes;
    }
}

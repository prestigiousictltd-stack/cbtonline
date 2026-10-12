<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        // Choose SameSite based on which portal the current URL belongs to.
        // This is set before session_start, so we infer from the request path.
        $path = $_SERVER['SCRIPT_NAME'] ?? '';
        $samesite = (str_contains($path, '/admin/') || str_contains($path, '/superadmin/'))
            ? 'Strict'
            : 'Lax';

        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $samesite,
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
        ini_set('session.cookie_httponly', '1');
        if ($secure) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();

        // Idle timeout
        $now = time();
        if (isset($_SESSION['_last_activity']) && ($now - (int) $_SESSION['_last_activity']) > SESSION_LIFETIME) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;

        // Optional IP binding — only enforced for admin guards where the
        // legitimate use case is narrower. Client users on mobile networks
        // frequently change IP mid-session, so we don't bind them.
        if (str_contains($path, '/admin/')) {
            $ip = self::clientIp();
            if (!isset($_SESSION['_ip'])) {
                $_SESSION['_ip'] = $ip;
            } elseif (!hash_equals($_SESSION['_ip'], $ip)) {
                self::destroy();
                session_start();
                $_SESSION['_ip'] = $ip;
            }
        }

        self::$started = true;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    private static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
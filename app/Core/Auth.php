<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\AdminRepository;
use App\Repositories\SuperAdminRepository;
use App\Repositories\UserRepository;

final class Auth
{
    // ---------- CLIENT ----------

    public static function clientCheck(): bool { return !empty($_SESSION['client_id']); }
    public static function clientId(): ?int { return self::clientCheck() ? (int) $_SESSION['client_id'] : null; }

    public static function clientUser(): ?array
    {
        if (!self::clientCheck()) return null;
        return (new UserRepository())->findById(self::clientId());
    }

    public static function requireClient(): array
    {
        $u = self::clientUser();
        if (!$u || $u['status'] !== STATUS_ACTIVE) {
            self::logoutClient();
            flash('warning', 'Please sign in to continue.');
            redirect('/login');
        }

        // Forced password change (except on the change-password and logout pages)
        if (!empty($u['must_change_password'])) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!str_contains($script, 'change-password') && !str_contains($script, 'logout')) {
                flash('warning', 'Please change your password before continuing.');
                redirect('/client/change-password');
            }
        }
        return $u;
    }

    public static function loginClient(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Csrf::rotate();
        unset($_SESSION['admin_id'], $_SESSION['superadmin_id']);
        $_SESSION['client_id'] = (int) $user['id'];
        (new UserRepository())->touchLogin((int) $user['id'], client_ip());
        if ($remember) self::issueRememberToken('client', (int) $user['id']);
    }

    public static function logoutClient(): void
    {
        self::clearRememberCookie('client');
        unset($_SESSION['client_id']);
    }

    // ---------- ADMIN ----------

    public static function adminCheck(): bool { return !empty($_SESSION['admin_id']); }
    public static function adminId(): ?int { return self::adminCheck() ? (int) $_SESSION['admin_id'] : null; }

    public static function adminUser(): ?array
    {
        if (!self::adminCheck()) return null;
        return (new AdminRepository())->findById(self::adminId());
    }

    public static function requireAdmin(): array
    {
        $a = self::adminUser();
        if (!$a || $a['status'] !== STATUS_ACTIVE) {
            self::logoutAdmin();
            flash('warning', 'Please sign in to continue.');
            redirect('/admin/login');
        }

        // Forced password change (except on the change-password and logout pages)
        if (!empty($a['must_change_password'])) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!str_contains($script, 'change-password') && !str_contains($script, 'logout')) {
                flash('warning', 'Please change your password before continuing.');
                redirect('/admin/change-password');
            }
        }
        return $a;
    }

    public static function loginAdmin(array $admin, bool $remember = false): void
    {
        Session::regenerate();
        Csrf::rotate();
        unset($_SESSION['client_id'], $_SESSION['superadmin_id']);
        $_SESSION['admin_id'] = (int) $admin['id'];
        (new AdminRepository())->touchLogin((int) $admin['id'], client_ip());
        if ($remember) self::issueRememberToken('admin', (int) $admin['id']);
    }

    public static function logoutAdmin(): void
    {
        self::clearRememberCookie('admin');
        unset($_SESSION['admin_id']);
    }

    // ---------- SUPER ADMIN ----------

    public static function superAdminCheck(): bool { return !empty($_SESSION['superadmin_id']); }
    public static function superAdminId(): ?int { return self::superAdminCheck() ? (int) $_SESSION['superadmin_id'] : null; }

    public static function superAdminUser(): ?array
    {
        if (!self::superAdminCheck()) return null;
        return (new SuperAdminRepository())->findById(self::superAdminId());
    }

    public static function requireSuperAdmin(): array
    {
        $s = self::superAdminUser();
        if (!$s || $s['status'] !== STATUS_ACTIVE) {
            self::logoutSuperAdmin();
            flash('warning', 'Please sign in to continue.');
            redirect('/superadmin/login');
        }
        return $s;
    }

    public static function loginSuperAdmin(array $superAdmin, bool $remember = false): void
    {
        Session::regenerate();
        Csrf::rotate();
        unset($_SESSION['client_id'], $_SESSION['admin_id']);
        $_SESSION['superadmin_id'] = (int) $superAdmin['id'];
        (new SuperAdminRepository())->touchLogin((int) $superAdmin['id'], client_ip());
        if ($remember) self::issueRememberToken('superadmin', (int) $superAdmin['id']);
    }

    public static function logoutSuperAdmin(): void
    {
        self::clearRememberCookie('superadmin');
        unset($_SESSION['superadmin_id']);
    }

    // ---------- REMEMBER ME ----------

    public static function attemptRememberLogin(): void
    {
        if (self::clientCheck() || self::adminCheck() || self::superAdminCheck()) return;
        if (empty($_COOKIE['servicehub_remember'])) return;

        $raw = (string) $_COOKIE['servicehub_remember'];
        if (!str_contains($raw, ':') || !str_contains($raw, '.')) return;

        [$guard, $rest] = explode(':', $raw, 2);
        [$id, $token]   = explode('.', $rest, 2);
        $id = (int) $id;
        if ($id <= 0 || $token === '') return;

        $repo = match ($guard) {
            'client'     => new UserRepository(),
            'admin'      => new AdminRepository(),
            'superadmin' => new SuperAdminRepository(),
            default      => null,
        };
        if (!$repo) return;

        $row = $repo->findById($id);
        if (!$row || $row['status'] !== STATUS_ACTIVE || empty($row['remember_token_hash'])) return;
        if (!hash_equals($row['remember_token_hash'], hash('sha256', $token))) return;

        match ($guard) {
            'client'     => self::loginClient($row, true),
            'admin'      => self::loginAdmin($row, true),
            'superadmin' => self::loginSuperAdmin($row, true),
        };
    }

    private static function issueRememberToken(string $guard, int $userId): void
    {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $repo = match ($guard) {
            'client'     => new UserRepository(),
            'admin'      => new AdminRepository(),
            'superadmin' => new SuperAdminRepository(),
        };
        $repo->setRememberToken($userId, $hash);
        $cookie = $guard . ':' . $userId . '.' . $raw;
        setcookie('servicehub_remember', $cookie, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(string $guard): void
    {
        if (!empty($_COOKIE['servicehub_remember'])) {
            setcookie('servicehub_remember', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE['servicehub_remember']);
        }
    }
}
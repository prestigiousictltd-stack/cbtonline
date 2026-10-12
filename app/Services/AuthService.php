<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Repositories\AdminRepository;
use App\Repositories\SuperAdminRepository;
use App\Repositories\UserRepository;

final class AuthService
{
    public function __construct(
        private readonly UserRepository       $users       = new UserRepository(),
        private readonly AdminRepository      $admins      = new AdminRepository(),
        private readonly SuperAdminRepository $superAdmins = new SuperAdminRepository(),
        private readonly AuditService         $audit       = new AuditService(),
    ) {}

    /**
     * @return array{ok:bool,user?:array,error?:string,remember?:bool}
     */
    public function attemptClient(string $email, string $password, bool $remember): array
    {
        $email = strtolower(trim($email));
        $ip    = client_ip();

        if (RateLimiter::tooMany('client', $email, $ip)) {
            return ['ok' => false, 'error' =>
                'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.'];
        }

        $user  = $this->users->findByEmail($email);
        $valid = $user && password_verify($password, $user['password_hash']);

        RateLimiter::record('client', $email, $ip, $valid);

        if (!$valid) {
            $this->audit->log('LOGIN_FAILED', 'user', $user['id'] ?? null, 'Invalid credentials', userType: 'guest');
            return ['ok' => false, 'error' => 'Invalid email or password.'];
        }
        if ($user['status'] !== STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'Your account is ' . $user['status'] . '. Please contact support.'];
        }

        RateLimiter::clear('client', $email);
        $this->audit->log('LOGIN_SUCCESS', 'user', (int) $user['id'], 'Client logged in',
            userType: 'client', userId: (int) $user['id']);

        return ['ok' => true, 'user' => $user, 'remember' => $remember];
    }

    /**
     * @return array{ok:bool,user?:array,error?:string,remember?:bool}
     */
    public function attemptAdmin(string $identifier, string $password, bool $remember): array
    {
        $identifier = trim($identifier);
        $key        = strtolower($identifier);
        $ip         = client_ip();

        if (RateLimiter::tooMany('admin', $key, $ip)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again later.'];
        }

        $admin = $this->admins->findByEmailOrUsername($identifier);
        $valid = $admin && password_verify($password, $admin['password_hash']);

        RateLimiter::record('admin', $key, $ip, $valid);

        if (!$valid) {
            return ['ok' => false, 'error' => 'Invalid credentials.'];
        }
        if ($admin['status'] !== STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'This admin account is ' . $admin['status'] . '.'];
        }

        RateLimiter::clear('admin', $key);
        $this->audit->log('LOGIN_SUCCESS', 'admin', (int) $admin['id'], 'Admin logged in',
            userType: 'admin', userId: (int) $admin['id']);

        return ['ok' => true, 'user' => $admin, 'remember' => $remember];
    }

    /**
     * @return array{ok:bool,user?:array,error?:string,remember?:bool}
     */
    public function attemptSuperAdmin(string $identifier, string $password, bool $remember): array
    {
        $identifier = trim($identifier);
        $key        = strtolower($identifier);
        $ip         = client_ip();

        if (RateLimiter::tooMany('super_admin', $key, $ip)) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again later.'];
        }

        $sa    = $this->superAdmins->findByEmailOrUsername($identifier);
        $valid = $sa && password_verify($password, $sa['password_hash']);

        RateLimiter::record('super_admin', $key, $ip, $valid);

        if (!$valid) {
            return ['ok' => false, 'error' => 'Invalid credentials.'];
        }
        if ($sa['status'] !== STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'This account is ' . $sa['status'] . '.'];
        }

        RateLimiter::clear('super_admin', $key);
        $this->audit->log('LOGIN_SUCCESS', 'super_admin', (int) $sa['id'], 'Super admin logged in',
            userType: 'super_admin', userId: (int) $sa['id']);

        return ['ok' => true, 'user' => $sa, 'remember' => $remember];
    }

    public function logout(string $guard): void
    {
        $map = [
            'client'     => ['type' => 'client',      'id' => Auth::clientId(),      'action' => 'LOGOUT'],
            'admin'      => ['type' => 'admin',       'id' => Auth::adminId(),       'action' => 'LOGOUT'],
            'superadmin' => ['type' => 'super_admin', 'id' => Auth::superAdminId(),  'action' => 'LOGOUT'],
        ];
        $row = $map[$guard] ?? null;
        if ($row && $row['id']) {
            $this->audit->log($row['action'], null, null, 'User logged out', userType: $row['type'], userId: $row['id']);
        }

        match ($guard) {
            'client'     => Auth::logoutClient(),
            'admin'      => Auth::logoutAdmin(),
            'superadmin' => Auth::logoutSuperAdmin(),
        };
    }
}
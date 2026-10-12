<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SuperAdminRepository
{
    public function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM super_admins WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function findByEmailOrUsername(string $identifier): ?array
    {
        return Database::selectOne(
            'SELECT * FROM super_admins WHERE email = :e OR username = :u LIMIT 1',
            [':e' => strtolower(trim($identifier)), ':u' => $identifier]
        );
    }

    public function touchLogin(int $id, string $ip): void
    {
        Database::execute(
            'UPDATE super_admins SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip WHERE id = :id',
            [':ip' => $ip, ':id' => $id]
        );
    }

    public function updatePassword(int $id, string $hash): void
    {
        Database::execute(
            'UPDATE super_admins SET password_hash = :h, must_change_password = 0 WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }

    public function setRememberToken(int $id, ?string $hash): void
    {
        Database::execute(
            'UPDATE super_admins SET remember_token_hash = :h WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }
}
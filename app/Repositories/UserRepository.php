<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class UserRepository
{
    public function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM users WHERE email = :e LIMIT 1',
            [':e' => strtolower(trim($email))]
        );
    }

    public function emailExists(string $email): bool
    {
        return (bool) Database::selectOne(
            'SELECT 1 FROM users WHERE email = :e LIMIT 1',
            [':e' => strtolower(trim($email))]
        );
    }

    public function create(array $data): int
    {
        Database::execute(
            'INSERT INTO users (full_name, email, phone, password_hash, address, state, status)
             VALUES (:fn, :e, :p, :h, :a, :s, :st)',
            [
                ':fn' => $data['full_name'],
                ':e'  => strtolower(trim($data['email'])),
                ':p'  => $data['phone'],
                ':h'  => $data['password_hash'],
                ':a'  => $data['address'] ?? null,
                ':s'  => $data['state']   ?? null,
                ':st' => STATUS_ACTIVE,
            ]
        );
        return Database::lastInsertId();
    }

    public function touchLogin(int $id, string $ip): void
    {
        Database::execute(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip WHERE id = :id',
            [':ip' => $ip, ':id' => $id]
        );
    }

    public function updatePassword(int $id, string $hash): void
    {
        Database::execute(
            'UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }

    public function setRememberToken(int $id, ?string $hash): void
    {
        Database::execute(
            'UPDATE users SET remember_token_hash = :h WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }

    public function updateProfile(int $id, array $data): void
    {
        Database::execute(
            'UPDATE users SET full_name = :fn, phone = :p, address = :a, state = :s WHERE id = :id',
            [
                ':fn' => $data['full_name'],
                ':p'  => $data['phone'],
                ':a'  => $data['address'] ?? null,
                ':s'  => $data['state']   ?? null,
                ':id' => $id,
            ]
        );
    }
}
<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class WalletRepository
{
    public function findByUserId(int $userId): ?array
    {
        return Database::selectOne('SELECT * FROM wallets WHERE user_id = :u LIMIT 1', [':u' => $userId]);
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM wallets WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function createForUser(int $userId, string $currency = 'NGN'): int
    {
        Database::execute(
            'INSERT INTO wallets (user_id, balance, currency, status) VALUES (:u, 0.00, :c, "active")',
            [':u' => $userId, ':c' => $currency]
        );
        return Database::lastInsertId();
    }

    /** Row-level lock inside an active transaction. */
    public function lockForUpdate(int $walletId): ?array
    {
        return Database::selectOne('SELECT * FROM wallets WHERE id = :id FOR UPDATE', [':id' => $walletId]);
    }

    /** Direct balance write; call only from WalletService inside a transaction. */
    public function setBalance(int $walletId, string $newBalance): void
    {
        Database::execute(
            'UPDATE wallets SET balance = :b WHERE id = :id',
            [':b' => $newBalance, ':id' => $walletId]
        );
    }

    public function countAll(): int
    {
        return (int) Database::selectOne('SELECT COUNT(*) AS c FROM wallets')['c'];
    }

    public function totalBalance(): string
    {
        $row = Database::selectOne('SELECT COALESCE(SUM(balance), 0.00) AS s FROM wallets');
        return (string) $row['s'];
    }
}
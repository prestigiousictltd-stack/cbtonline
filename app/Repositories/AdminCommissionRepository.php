<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use RuntimeException;

final class AdminCommissionRepository
{
    /** Row-lock the admin record for balance changes. Caller must be in a transaction. */
    public function lockForUpdate(int $adminId): ?array
    {
        return Database::selectOne(
            'SELECT id, commission_balance, commission_reserved, status
             FROM admins WHERE id = :id FOR UPDATE',
            [':id' => $adminId]
        );
    }

    public function setBalance(int $adminId, string $newBalance): void
    {
        Database::execute(
            'UPDATE admins SET commission_balance = :b WHERE id = :id',
            [':b' => $newBalance, ':id' => $adminId]
        );
    }

    public function setReserved(int $adminId, string $newReserved): void
    {
        Database::execute(
            'UPDATE admins SET commission_reserved = :r WHERE id = :id',
            [':r' => $newReserved, ':id' => $adminId]
        );
    }

    public function creditBalance(int $adminId, string $amount): string
    {
        $row = $this->lockForUpdate($adminId);
        if (!$row) throw new RuntimeException('Admin not found for commission credit.');

        $before = money_normalize((string) $row['commission_balance']);
        $after  = money_add($before, money_normalize($amount));
        $this->setBalance($adminId, $after);
        return $after;
    }

    /**
     * Reserve funds for a pending withdrawal. Returns the new reserved total.
     * Caller must be inside a transaction that already locked the admin row.
     */
    public function reserveFunds(int $adminId, string $amount): string
    {
        $row = $this->lockForUpdate($adminId);
        if (!$row) throw new RuntimeException('Admin not found.');

        $before = money_normalize((string) $row['commission_reserved']);
        $after  = money_add($before, money_normalize($amount));
        $this->setReserved($adminId, $after);
        return $after;
    }

    /**
     * Release previously-reserved funds (on reject or cancel).
     * Refuses to go negative — signals a serious accounting bug if it would.
     */
    public function releaseReserved(int $adminId, string $amount): string
    {
        $row = $this->lockForUpdate($adminId);
        if (!$row) throw new RuntimeException('Admin not found.');

        $before = money_normalize((string) $row['commission_reserved']);
        if (money_cmp($before, $amount) < 0) {
            throw new RuntimeException('Release would make reserved balance negative.');
        }
        $after = money_sub($before, money_normalize($amount));
        $this->setReserved($adminId, $after);
        return $after;
    }

    /**
     * Finalize a paid withdrawal: deduct from both reserved and total balance.
     * Caller must be inside a transaction that already locked the admin row.
     */
    public function settlePaid(int $adminId, string $amount): array
    {
        $row = $this->lockForUpdate($adminId);
        if (!$row) throw new RuntimeException('Admin not found.');

        $beforeBalance  = money_normalize((string) $row['commission_balance']);
        $beforeReserved = money_normalize((string) $row['commission_reserved']);
        $amount         = money_normalize($amount);

        if (money_cmp($beforeReserved, $amount) < 0) {
            throw new RuntimeException('Reserved balance is less than the withdrawal amount.');
        }
        if (money_cmp($beforeBalance, $amount) < 0) {
            throw new RuntimeException('Balance is less than the withdrawal amount.');
        }

        $afterBalance  = money_sub($beforeBalance, $amount);
        $afterReserved = money_sub($beforeReserved, $amount);

        $this->setBalance($adminId, $afterBalance);
        $this->setReserved($adminId, $afterReserved);

        return [
            'balance_before'  => $beforeBalance,
            'balance_after'   => $afterBalance,
            'reserved_before' => $beforeReserved,
            'reserved_after'  => $afterReserved,
        ];
    }

    public function currentBalance(int $adminId): string
    {
        return (string) (Database::selectOne(
            'SELECT commission_balance FROM admins WHERE id = :id',
            [':id' => $adminId]
        )['commission_balance'] ?? '0.00');
    }

    public function currentReserved(int $adminId): string
    {
        return (string) (Database::selectOne(
            'SELECT commission_reserved FROM admins WHERE id = :id',
            [':id' => $adminId]
        )['commission_reserved'] ?? '0.00');
    }
}
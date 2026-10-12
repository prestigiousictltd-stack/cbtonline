<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class WithdrawalRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO withdrawals
                (withdrawal_ref, admin_id, amount, bank_name, account_name, account_number,
                 status, admin_note)
             VALUES
                (:ref, :aid, :amount, :bank, :acctname, :acctno,
                 :status, :note)',
            [
                ':ref'      => $d['withdrawal_ref'],
                ':aid'      => $d['admin_id'],
                ':amount'   => $d['amount'],
                ':bank'     => $d['bank_name'],
                ':acctname' => $d['account_name'],
                ':acctno'   => $d['account_number'],
                ':status'   => $d['status'] ?? 'pending',
                ':note'     => $d['admin_note'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT w.*, a.full_name AS admin_name, a.email AS admin_email
             FROM withdrawals w
             INNER JOIN admins a ON a.id = w.admin_id
             WHERE w.id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function findByRef(string $ref): ?array
    {
        return Database::selectOne(
            'SELECT * FROM withdrawals WHERE withdrawal_ref = :r LIMIT 1',
            [':r' => $ref]
        );
    }

    /** Row-lock the withdrawal. Caller must be inside a transaction. */
    public function lockForUpdate(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM withdrawals WHERE id = :id FOR UPDATE',
            [':id' => $id]
        );
    }

    public function paginateForAdmin(int $adminId, int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = 'admin_id = :a';
        $params = [':a' => $adminId];

        if ($status !== null && $status !== '') {
            $where .= ' AND status = :s';
            $params[':s'] = $status;
        }

        $rows = Database::selectAll(
            "SELECT * FROM withdrawals
             WHERE $where
             ORDER BY created_at DESC, id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM withdrawals WHERE $where",
            $params
        )['c'];

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    public function markRejected(int $id, int $reviewerId, string $note): void
    {
        Database::execute(
            'UPDATE withdrawals
                SET status = "rejected",
                    reviewed_by_type = "super_admin",
                    reviewed_by_id = :r,
                    reviewed_at = UTC_TIMESTAMP(),
                    super_admin_note = :n
              WHERE id = :id',
            [':r' => $reviewerId, ':n' => $note, ':id' => $id]
        );
    }

    public function markApproved(int $id, int $reviewerId, ?string $note): void
    {
        Database::execute(
            'UPDATE withdrawals
                SET status = "approved",
                    reviewed_by_type = "super_admin",
                    reviewed_by_id = :r,
                    reviewed_at = UTC_TIMESTAMP(),
                    super_admin_note = :n
              WHERE id = :id',
            [':r' => $reviewerId, ':n' => $note, ':id' => $id]
        );
    }

    public function markPaid(int $id, int $reviewerId, string $paymentReference, ?string $note): void
    {
        Database::execute(
            'UPDATE withdrawals
                SET status = "paid",
                    reviewed_by_type = "super_admin",
                    reviewed_by_id = :r,
                    reviewed_at = COALESCE(reviewed_at, UTC_TIMESTAMP()),
                    paid_at = UTC_TIMESTAMP(),
                    payment_reference = :ref,
                    super_admin_note = COALESCE(:n, super_admin_note)
              WHERE id = :id',
            [':r' => $reviewerId, ':ref' => $paymentReference, ':n' => $note, ':id' => $id]
        );
    }

    public function markCancelled(int $id, ?string $reason = null): void
    {
        Database::execute(
            'UPDATE withdrawals
                SET status = "rejected",
                    admin_note = COALESCE(:r, admin_note),
                    reviewed_at = UTC_TIMESTAMP()
              WHERE id = :id AND status = "pending"',
            [':r' => $reason, ':id' => $id]
        );
    }

    public function hasPendingForAdmin(int $adminId): bool
    {
        return (bool) Database::selectOne(
            'SELECT 1 FROM withdrawals
             WHERE admin_id = :a AND status = "pending" LIMIT 1',
            [':a' => $adminId]
        );
    }

    public function sumReservedForAdmin(int $adminId): string
    {
        return (string) Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM withdrawals
             WHERE admin_id = :a AND status IN ("pending","approved")',
            [':a' => $adminId]
        )['s'];
    }
}
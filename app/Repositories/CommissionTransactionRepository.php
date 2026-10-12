<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CommissionTransactionRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO commission_transactions
                (transaction_ref, admin_id, submission_id, withdrawal_id, type, direction,
                 amount, balance_before, balance_after, status, description,
                 created_by_type, created_by_id)
             VALUES
                (:ref, :aid, :sid, :wid, :type, :dir,
                 :amount, :before, :after, :status, :desc,
                 :cbtype, :cbid)',
            [
                ':ref'    => $d['transaction_ref'],
                ':aid'    => $d['admin_id'],
                ':sid'    => $d['submission_id'] ?? null,
                ':wid'    => $d['withdrawal_id'] ?? null,
                ':type'   => $d['type'],
                ':dir'    => $d['direction'],
                ':amount' => $d['amount'],
                ':before' => $d['balance_before'],
                ':after'  => $d['balance_after'],
                ':status' => $d['status'] ?? 'successful',
                ':desc'   => $d['description'] ?? null,
                ':cbtype' => $d['created_by_type'] ?? 'system',
                ':cbid'   => $d['created_by_id'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM commission_transactions WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function findByRef(string $ref): ?array
    {
        return Database::selectOne(
            'SELECT * FROM commission_transactions WHERE transaction_ref = :r LIMIT 1',
            [':r' => $ref]
        );
    }

    /** Rows tied to a specific withdrawal (request, reversal, payment). */
    public function listForWithdrawal(int $withdrawalId): array
    {
        return Database::selectAll(
            'SELECT * FROM commission_transactions
             WHERE withdrawal_id = :w ORDER BY id ASC',
            [':w' => $withdrawalId]
        );
    }

    public function paginateForAdmin(int $adminId, int $page = 1, int $perPage = 20, ?string $type = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = 'admin_id = :a';
        $params = [':a' => $adminId];

        if ($type !== null && $type !== '') {
            $where .= ' AND type = :t';
            $params[':t'] = $type;
        }

        $rows = Database::selectAll(
            "SELECT * FROM commission_transactions
             WHERE $where
             ORDER BY created_at DESC, id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM commission_transactions WHERE $where",
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

    public function sumEarnedForAdmin(int $adminId): string
    {
        return (string) Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE admin_id = :a AND type = "earned" AND status = "successful"',
            [':a' => $adminId]
        )['s'];
    }

    public function sumWithdrawnForAdmin(int $adminId): string
    {
        return (string) Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE admin_id = :a AND type = "withdrawal" AND status = "successful"',
            [':a' => $adminId]
        )['s'];
    }

    public function updateStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE commission_transactions SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        );
    }

    /**
     * Update the balance_before / balance_after snapshot of a row when a
     * pending withdrawal_request becomes successful at payment time.
     */
    public function updateBalanceSnapshot(int $id, string $before, string $after): void
    {
        Database::execute(
            'UPDATE commission_transactions
                SET balance_before = :b, balance_after = :a
              WHERE id = :id',
            [':b' => $before, ':a' => $after, ':id' => $id]
        );
    }
}
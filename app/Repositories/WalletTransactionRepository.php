<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class WalletTransactionRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO wallet_transactions
                (transaction_ref, wallet_id, user_id, type, category, amount,
                 balance_before, balance_after, status, reference_type, reference_id,
                 description, created_by_type, created_by_id)
             VALUES
                (:ref, :wid, :uid, :type, :cat, :amt,
                 :before, :after, :status, :reftype, :refid,
                 :desc, :cbtype, :cbid)',
            [
                ':ref'    => $d['transaction_ref'],
                ':wid'    => $d['wallet_id'],
                ':uid'    => $d['user_id'],
                ':type'   => $d['type'],
                ':cat'    => $d['category'],
                ':amt'    => $d['amount'],
                ':before' => $d['balance_before'],
                ':after'  => $d['balance_after'],
                ':status' => $d['status'] ?? 'successful',
                ':reftype'=> $d['reference_type'] ?? null,
                ':refid'  => $d['reference_id'] ?? null,
                ':desc'   => $d['description'] ?? null,
                ':cbtype' => $d['created_by_type'] ?? 'system',
                ':cbid'   => $d['created_by_id'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function paginateForUser(int $userId, int $page = 1, int $perPage = 20, ?string $category = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = 'user_id = :uid';
        $params = [':uid' => $userId];

        if ($category !== null && $category !== '') {
            $where .= ' AND category = :cat';
            $params[':cat'] = $category;
        }

        $rows = Database::selectAll(
            "SELECT * FROM wallet_transactions
             WHERE $where
             ORDER BY created_at DESC, id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM wallet_transactions WHERE $where",
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

    public function findByRef(string $ref): ?array
    {
        return Database::selectOne(
            'SELECT * FROM wallet_transactions WHERE transaction_ref = :r LIMIT 1',
            [':r' => $ref]
        );
    }

    public function sumByCategory(int $userId, string $category, string $type): string
    {
        $row = Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM wallet_transactions
             WHERE user_id = :u AND category = :c AND type = :t AND status = "successful"',
            [':u' => $userId, ':c' => $category, ':t' => $type]
        );
        return (string) $row['s'];
    }
}
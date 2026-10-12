<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ClientRepository
{
    /**
     * @param array{status?:string,q?:string} $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'u.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(u.full_name LIKE :q OR u.email LIKE :q OR u.phone LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT u.id, u.full_name, u.email, u.phone, u.state, u.status,
                    u.created_at, u.last_login_at,
                    COALESCE(w.balance, 0.00) AS wallet_balance,
                    (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS submissions_count
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE $whereSql
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM users u WHERE $whereSql",
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

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT u.*, COALESCE(w.balance, 0.00) AS wallet_balance
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE u.id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function setStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE users SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        );
    }

    public function countActive(): int
    {
        return (int) Database::selectOne('SELECT COUNT(*) AS c FROM users WHERE status="active"')['c'];
    }
}
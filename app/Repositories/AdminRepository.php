<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AdminRepository
{
    // ---------- Reads ----------

    public function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM admins WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return Database::selectOne(
            'SELECT * FROM admins WHERE email = :e LIMIT 1',
            [':e' => strtolower(trim($email))]
        );
    }

    public function findByUsername(string $username): ?array
    {
        return Database::selectOne(
            'SELECT * FROM admins WHERE username = :u LIMIT 1',
            [':u' => strtolower(trim($username))]
        );
    }

    public function findByEmailOrUsername(string $identifier): ?array
    {
        return Database::selectOne(
            'SELECT * FROM admins WHERE email = :e OR username = :u LIMIT 1',
            [':e' => strtolower(trim($identifier)), ':u' => $identifier]
        );
    }

    public function all(): array
    {
        return Database::selectAll('SELECT * FROM admins ORDER BY full_name ASC');
    }

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
            $where[] = 'status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(full_name LIKE :q OR email LIKE :q OR username LIKE :q OR phone LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM submissions s
                     WHERE s.assigned_admin_id = a.id AND s.status = 'completed') AS jobs_completed,
                    (SELECT COUNT(*) FROM submissions s
                     WHERE s.assigned_admin_id = a.id AND s.status = 'processing') AS jobs_processing,
                    (SELECT COUNT(*) FROM withdrawals w
                     WHERE w.admin_id = a.id AND w.status IN ('pending','approved')) AS pending_withdrawals
             FROM admins a
             WHERE $whereSql
             ORDER BY a.full_name ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM admins WHERE $whereSql",
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

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            return (bool) Database::selectOne(
                'SELECT 1 FROM admins WHERE email = :e AND id <> :id LIMIT 1',
                [':e' => strtolower(trim($email)), ':id' => $exceptId]
            );
        }
        return (bool) Database::selectOne(
            'SELECT 1 FROM admins WHERE email = :e LIMIT 1',
            [':e' => strtolower(trim($email))]
        );
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            return (bool) Database::selectOne(
                'SELECT 1 FROM admins WHERE username = :u AND id <> :id LIMIT 1',
                [':u' => strtolower(trim($username)), ':id' => $exceptId]
            );
        }
        return (bool) Database::selectOne(
            'SELECT 1 FROM admins WHERE username = :u LIMIT 1',
            [':u' => strtolower(trim($username))]
        );
    }

    // ---------- Writes ----------

    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO admins
                (full_name, email, username, phone, password_hash, status,
                 max_concurrent_tasks, must_change_password, created_by)
             VALUES
                (:fn, :e, :u, :p, :h, :st, :max, :mcp, :cb)',
            [
                ':fn'  => $d['full_name'],
                ':e'   => strtolower(trim($d['email'])),
                ':u'   => strtolower(trim($d['username'])),
                ':p'   => $d['phone'],
                ':h'   => $d['password_hash'],
                ':st'  => $d['status'] ?? 'active',
                ':max' => $d['max_concurrent_tasks'] ?? 0,
                ':mcp' => !empty($d['must_change_password']) ? 1 : 0,
                ':cb'  => $d['created_by'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $fields = [];
        $params = [':id' => $id];

        $map = [
            'full_name'            => ':fn',
            'phone'                => ':p',
            'max_concurrent_tasks' => ':max',
        ];
        foreach ($map as $col => $bind) {
            if (array_key_exists($col, $d)) {
                $fields[]      = "$col = $bind";
                $params[$bind] = $d[$col];
            }
        }
        if (empty($fields)) return;

        Database::execute(
            'UPDATE admins SET ' . implode(', ', $fields) . ' WHERE id = :id',
            $params
        );
    }

    public function setStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE admins SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        );
    }

    public function touchLogin(int $id, string $ip): void
    {
        Database::execute(
            'UPDATE admins SET last_login_at = UTC_TIMESTAMP(), last_login_ip = :ip WHERE id = :id',
            [':ip' => $ip, ':id' => $id]
        );
    }

    public function updatePassword(int $id, string $hash): void
    {
        Database::execute(
            'UPDATE admins SET password_hash = :h, must_change_password = 0 WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }

    public function setMustChangePassword(int $id, bool $flag): void
    {
        Database::execute(
            'UPDATE admins SET must_change_password = :f WHERE id = :id',
            [':f' => $flag ? 1 : 0, ':id' => $id]
        );
    }

    public function setRememberToken(int $id, ?string $hash): void
    {
        Database::execute(
            'UPDATE admins SET remember_token_hash = :h WHERE id = :id',
            [':h' => $hash, ':id' => $id]
        );
    }

    public function updateProfile(int $id, array $data): void
    {
        Database::execute(
            'UPDATE admins SET full_name = :fn, phone = :p WHERE id = :id',
            [':fn' => $data['full_name'], ':p' => $data['phone'], ':id' => $id]
        );
    }

    // ---------- Stats for admin-view page ----------

    public function taskStatsForAdmin(int $adminId): array
    {
        $row = Database::selectOne(
            'SELECT
                SUM(status = "pending")         AS pending,
                SUM(status = "processing")      AS processing,
                SUM(status = "completed")       AS completed,
                SUM(status = "error_detected")  AS error_detected,
                SUM(status = "cancelled")       AS cancelled,
                COALESCE(SUM(CASE WHEN status = "completed" THEN admin_commission_amount ELSE 0 END), 0.00) AS total_commission
             FROM submissions
             WHERE assigned_admin_id = :a',
            [':a' => $adminId]
        );
        return [
            'pending'          => (int) ($row['pending']         ?? 0),
            'processing'       => (int) ($row['processing']      ?? 0),
            'completed'        => (int) ($row['completed']       ?? 0),
            'error_detected'   => (int) ($row['error_detected']  ?? 0),
            'cancelled'        => (int) ($row['cancelled']       ?? 0),
            'total_commission' => (string) ($row['total_commission'] ?? '0.00'),
        ];
    }

    public function hasPendingWithdrawals(int $adminId): bool
    {
        return (bool) Database::selectOne(
            'SELECT 1 FROM withdrawals
             WHERE admin_id = :a AND status IN ("pending","approved") LIMIT 1',
            [':a' => $adminId]
        );
    }

    public function hasAnyWithdrawals(int $adminId): bool
    {
        return (bool) Database::selectOne(
            'SELECT 1 FROM withdrawals WHERE admin_id = :a LIMIT 1',
            [':a' => $adminId]
        );
    }

    public function hasAnySubmissions(int $adminId): bool
    {
        return (bool) Database::selectOne(
            'SELECT 1 FROM submissions WHERE assigned_admin_id = :a LIMIT 1',
            [':a' => $adminId]
        );
    }
}
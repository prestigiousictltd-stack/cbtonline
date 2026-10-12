<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SubmissionRepository
{
    // ---------- Creation ----------

    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO submissions
                (tracking_id, user_id, service_id, status,
                 service_name_snapshot, price_charged, admin_commission_amount,
                 processing_minutes_snapshot, parent_submission_id)
             VALUES
                (:tid, :uid, :sid, :status,
                 :sname, :price, :commission,
                 :minutes, :parent)',
            [
                ':tid'        => $d['tracking_id'],
                ':uid'        => $d['user_id'],
                ':sid'        => $d['service_id'],
                ':status'     => $d['status'] ?? 'pending',
                ':sname'      => $d['service_name_snapshot'],
                ':price'      => $d['price_charged'],
                ':commission' => $d['admin_commission_amount'] ?? '0.00',
                ':minutes'    => $d['processing_minutes_snapshot'],
                ':parent'     => $d['parent_submission_id'] ?? null,
            ]
        );
        return Database::lastInsertId();
    }

    // ---------- Reads ----------

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT s.*, sv.name AS current_service_name, sv.slug AS service_slug,
                    a.full_name AS assigned_admin_name,
                    u.full_name AS client_name, u.email AS client_email,
                    u.phone AS client_phone
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             INNER JOIN users u     ON u.id  = s.user_id
             LEFT  JOIN admins a    ON a.id  = s.assigned_admin_id
             WHERE s.id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function findByTrackingId(string $trackingId): ?array
    {
        return Database::selectOne(
            'SELECT s.*, sv.name AS current_service_name, sv.slug AS service_slug,
                    u.full_name AS client_name, u.email AS client_email, u.phone AS client_phone
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             INNER JOIN users u     ON u.id  = s.user_id
             WHERE s.tracking_id = :t LIMIT 1',
            [':t' => $trackingId]
        );
    }

    // ---------- Client views ----------

    public function countForUser(int $userId): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE user_id = :u',
            [':u' => $userId]
        )['c'];
    }

    public function countForUserByStatus(int $userId, string $status): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE user_id = :u AND status = :s',
            [':u' => $userId, ':s' => $status]
        )['c'];
    }

    public function recentForUser(int $userId, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return Database::selectAll(
            "SELECT s.*, sv.name AS service_name
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             WHERE s.user_id = :u
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT $limit",
            [':u' => $userId]
        );
    }

    /**
     * Paginated + filterable list for a single client.
     *
     * @param array{status?:string,service_id?:int,q?:string} $filters
     */
    public function paginateForUser(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['s.user_id = :uid'];
        $params = [':uid' => $userId];

        if (!empty($filters['status'])) {
            $where[] = 's.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['service_id'])) {
            $where[] = 's.service_id = :sid';
            $params[':sid'] = (int) $filters['service_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(s.tracking_id LIKE :q OR sv.name LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

      $rows = Database::selectAll(
    "SELECT s.*,
            sv.name AS service_name,
            COALESCE(
                (SELECT sfv.value_text
                 FROM submission_field_values sfv
                 WHERE sfv.submission_id = s.id
                   AND sfv.field_key_snapshot = 'full_name'
                 LIMIT 1),
                u.full_name
            ) AS applicant_name
     FROM submissions s
     INNER JOIN services sv ON sv.id = s.service_id
     INNER JOIN users    u  ON u.id  = s.user_id
     WHERE $whereSql
     ORDER BY s.created_at DESC, s.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             WHERE $whereSql",
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
}
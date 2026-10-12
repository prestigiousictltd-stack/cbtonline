<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AdminQueueService
{
    /**
     * Task queue for one service, paginated.
     *
     * @param array{status?:string,q?:string} $filters
     */
    public function queueForService(int $serviceId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['s.service_id = :sid'];
        $params = [':sid' => $serviceId];

        if (!empty($filters['status'])) {
            $where[] = 's.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(s.tracking_id LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT s.id, s.tracking_id, s.status, s.created_at, s.updated_at,
                    s.claimed_at, s.deadline_at, s.assigned_admin_id,
                    u.full_name AS client_name, u.email AS client_email,
                    a.full_name AS assigned_admin_name,
                    CASE
                        WHEN s.status = 'processing' AND s.deadline_at IS NOT NULL
                        THEN GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), s.deadline_at))
                        ELSE NULL
                    END AS seconds_remaining
             FROM submissions s
             INNER JOIN users  u ON u.id = s.user_id
             LEFT  JOIN admins a ON a.id = s.assigned_admin_id
             WHERE $whereSql
             ORDER BY
                CASE s.status
                    WHEN 'pending'      THEN 1
                    WHEN 'processing'   THEN 2
                    WHEN 'error_detected' THEN 3
                    WHEN 'completed'    THEN 4
                    ELSE 5
                END ASC,
                s.created_at ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c
             FROM submissions s
             INNER JOIN users u ON u.id = s.user_id
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

    public function countsForService(int $serviceId): array
    {
        $rows = Database::selectAll(
            'SELECT status, COUNT(*) AS c
             FROM submissions
             WHERE service_id = :sid
             GROUP BY status',
            [':sid' => $serviceId]
        );
        $out = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'error_detected' => 0, 'cancelled' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    /** Paginated completed jobs for one admin. */
    public function completedJobsForAdmin(int $adminId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::selectAll(
            "SELECT s.id, s.tracking_id, s.status, s.price_charged,
                    s.admin_commission_amount, s.completed_at,
                    u.full_name AS client_name, u.email AS client_email,
                    sv.name AS service_name
             FROM submissions s
             INNER JOIN users u    ON u.id = s.user_id
             INNER JOIN services sv ON sv.id = s.service_id
             WHERE s.assigned_admin_id = :a AND s.status = 'completed'
             ORDER BY s.completed_at DESC, s.id DESC
             LIMIT $perPage OFFSET $offset",
            [':a' => $adminId]
        );

        $total = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE assigned_admin_id = :a AND status = "completed"',
            [':a' => $adminId]
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
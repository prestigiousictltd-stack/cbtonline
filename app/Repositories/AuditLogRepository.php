<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AuditLogRepository
{
    /**
     * @param array{
     *   q?:string, user_type?:string, action?:string,
     *   entity_type?:string, entity_id?:int,
     *   from?:string, to?:string
     * } $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['1'];
        $params = [];

        if (!empty($filters['user_type'])) {
            $where[] = 'user_type = :ut';
            $params[':ut'] = (string) $filters['user_type'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'action = :action';
            $params[':action'] = (string) $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'entity_type = :et';
            $params[':et'] = (string) $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $where[] = 'entity_id = :eid';
            $params[':eid'] = (int) $filters['entity_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(description LIKE :q OR action LIKE :q OR ip_address LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params[':to'] = (string) $filters['to'];
        }

        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT * FROM audit_logs
             WHERE $whereSql
             ORDER BY id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM audit_logs WHERE $whereSql",
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
            'SELECT * FROM audit_logs WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    /** Distinct actions for the filter dropdown. */
    public function distinctActions(int $limit = 200): array
    {
        return Database::selectAll(
            "SELECT action, COUNT(*) AS c
             FROM audit_logs
             GROUP BY action
             ORDER BY c DESC
             LIMIT " . (int) $limit
        );
    }

    public function distinctEntityTypes(): array
    {
        return Database::selectAll(
            'SELECT DISTINCT entity_type FROM audit_logs
             WHERE entity_type IS NOT NULL ORDER BY entity_type ASC'
        );
    }

    public function countInRange(string $from, string $to): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM audit_logs
             WHERE created_at BETWEEN :f AND :t',
            [':f' => $from, ':t' => $to]
        )['c'];
    }
}
<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ServiceRepository
{
    // ---------- Reads ----------

    public function findById(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM services WHERE id = :id LIMIT 1', [':id' => $id]);
    }

    public function findBySlug(string $slug): ?array
    {
        return Database::selectOne('SELECT * FROM services WHERE slug = :s LIMIT 1', [':s' => $slug]);
    }

    public function allActive(): array
    {
        return Database::selectAll(
            'SELECT * FROM services WHERE status = "active" ORDER BY sort_order ASC, name ASC'
        );
    }

    public function allActiveForSelect(): array
    {
        return Database::selectAll(
            'SELECT id, name, slug, price, processing_minutes, duration_label
             FROM services WHERE status = "active" ORDER BY sort_order ASC, name ASC'
        );
    }

    public function all(): array
    {
        return Database::selectAll('SELECT * FROM services ORDER BY sort_order ASC, name ASC');
    }

    /**
     * @param array{q?:string,status?:string} $filters
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30): array
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
            $where[] = '(name LIKE :q OR slug LIKE :q OR description LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT * FROM services
             WHERE $whereSql
             ORDER BY sort_order ASC, name ASC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c FROM services WHERE $whereSql",
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

    public function countActive(): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM services WHERE status = "active"'
        )['c'];
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = Database::selectOne(
                'SELECT 1 FROM services WHERE slug = :s AND id <> :id LIMIT 1',
                [':s' => $slug, ':id' => $exceptId]
            );
        } else {
            $row = Database::selectOne(
                'SELECT 1 FROM services WHERE slug = :s LIMIT 1',
                [':s' => $slug]
            );
        }
        return (bool) $row;
    }

    // ---------- Writes ----------

    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO services
                (name, slug, description, short_description, price, admin_commission,
                 processing_minutes, duration_label, icon, sort_order, status)
             VALUES
                (:name, :slug, :desc, :short, :price, :commission,
                 :minutes, :duration, :icon, :sort, :status)',
            [
                ':name'       => $d['name'],
                ':slug'       => $d['slug'],
                ':desc'       => $d['description']       ?? null,
                ':short'      => $d['short_description'] ?? null,
                ':price'      => $d['price'],
                ':commission' => $d['admin_commission'],
                ':minutes'    => $d['processing_minutes'],
                ':duration'   => $d['duration_label']    ?? null,
                ':icon'       => $d['icon']              ?? null,
                ':sort'       => $d['sort_order']        ?? 0,
                ':status'     => $d['status']            ?? 'active',
            ]
        );
        return Database::lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $fields = [];
        $params = [':id' => $id];

        $map = [
            'name'               => ':name',
            'slug'               => ':slug',
            'description'        => ':desc',
            'short_description'  => ':short',
            'price'              => ':price',
            'admin_commission'   => ':commission',
            'processing_minutes' => ':minutes',
            'duration_label'     => ':duration',
            'icon'               => ':icon',
            'sort_order'         => ':sort',
            'status'             => ':status',
        ];
        foreach ($map as $col => $bind) {
            if (array_key_exists($col, $d)) {
                $fields[]      = "$col = $bind";
                $params[$bind] = $d[$col];
            }
        }
        if (empty($fields)) return;

        Database::execute(
            'UPDATE services SET ' . implode(', ', $fields) . ' WHERE id = :id',
            $params
        );
    }

    public function setStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE services SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        );
    }

    // ---------- Stats ----------

    public function countsPerService(): array
    {
        $rows = Database::selectAll(
            'SELECT service_id, status, COUNT(*) AS c FROM submissions GROUP BY service_id, status'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['service_id']][$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public function submissionCountForService(int $serviceId): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE service_id = :s',
            [':s' => $serviceId]
        )['c'];
    }
}
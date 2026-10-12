<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ServiceFieldRepository
{
    // ---------- Reads ----------

    public function listActiveForService(int $serviceId): array
    {
        return Database::selectAll(
            'SELECT * FROM service_fields
             WHERE service_id = :s AND status = "active"
             ORDER BY sort_order ASC, id ASC',
            [':s' => $serviceId]
        );
    }

    public function listAllForService(int $serviceId): array
    {
        return Database::selectAll(
            'SELECT * FROM service_fields
             WHERE service_id = :s
             ORDER BY sort_order ASC, id ASC',
            [':s' => $serviceId]
        );
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM service_fields WHERE id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function findByKey(int $serviceId, string $key): ?array
    {
        return Database::selectOne(
            'SELECT * FROM service_fields WHERE service_id = :s AND field_key = :k LIMIT 1',
            [':s' => $serviceId, ':k' => $key]
        );
    }

    public function countForService(int $serviceId, bool $activeOnly = false): int
    {
        $sql = $activeOnly
            ? 'SELECT COUNT(*) AS c FROM service_fields WHERE service_id = :s AND status = "active"'
            : 'SELECT COUNT(*) AS c FROM service_fields WHERE service_id = :s';
        return (int) Database::selectOne($sql, [':s' => $serviceId])['c'];
    }

    // ---------- Writes ----------

    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO service_fields
                (service_id, field_key, field_label, field_type, field_options,
                 placeholder, help_text, is_required, validation_regex, max_length,
                 sort_order, status)
             VALUES
                (:sid, :key, :label, :type, :options,
                 :placeholder, :help, :required, :regex, :maxlen,
                 :sort, :status)',
            [
                ':sid'         => $d['service_id'],
                ':key'         => $d['field_key'],
                ':label'       => $d['field_label'],
                ':type'        => $d['field_type'],
                ':options'     => isset($d['field_options']) ? json_encode($d['field_options']) : null,
                ':placeholder' => $d['placeholder'] ?? null,
                ':help'        => $d['help_text']   ?? null,
                ':required'    => !empty($d['is_required']) ? 1 : 0,
                ':regex'       => $d['validation_regex'] ?? null,
                ':maxlen'      => $d['max_length'] ?? null,
                ':sort'        => $d['sort_order'] ?? 0,
                ':status'      => $d['status']     ?? 'active',
            ]
        );
        return Database::lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $fields = [];
        $params = [':id' => $id];

        $map = [
            'field_key'         => ':key',
            'field_label'       => ':label',
            'field_type'        => ':type',
            'placeholder'       => ':placeholder',
            'help_text'         => ':help',
            'is_required'       => ':required',
            'validation_regex'  => ':regex',
            'max_length'        => ':maxlen',
            'sort_order'        => ':sort',
            'status'            => ':status',
        ];
        foreach ($map as $col => $bind) {
            if (array_key_exists($col, $d)) {
                $val = $col === 'is_required' ? (!empty($d[$col]) ? 1 : 0) : $d[$col];
                $fields[]      = "$col = $bind";
                $params[$bind] = $val;
            }
        }
        if (array_key_exists('field_options', $d)) {
            $fields[]            = 'field_options = :options';
            $params[':options']  = $d['field_options'] !== null ? json_encode($d['field_options']) : null;
        }
        if (empty($fields)) return;

        Database::execute(
            'UPDATE service_fields SET ' . implode(', ', $fields) . ' WHERE id = :id',
            $params
        );
    }

    public function delete(int $id): void
    {
        Database::execute('DELETE FROM service_fields WHERE id = :id', [':id' => $id]);
    }

    public function setStatus(int $id, string $status): void
    {
        Database::execute(
            'UPDATE service_fields SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        );
    }

    public function swapSortOrder(int $idA, int $idB): void
    {
        // Swap sort_order for two rows (used by move up / move down)
        $rowA = $this->findById($idA);
        $rowB = $this->findById($idB);
        if (!$rowA || !$rowB) return;

        Database::execute('UPDATE service_fields SET sort_order = :o WHERE id = :id', [
            ':o' => (int) $rowB['sort_order'], ':id' => (int) $idA,
        ]);
        Database::execute('UPDATE service_fields SET sort_order = :o WHERE id = :id', [
            ':o' => (int) $rowA['sort_order'], ':id' => (int) $idB,
        ]);
    }

    public function nextSortOrder(int $serviceId): int
    {
        return (int) (Database::selectOne(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 AS n FROM service_fields WHERE service_id = :s',
            [':s' => $serviceId]
        )['n'] ?? 10);
    }

    public function keyExists(int $serviceId, string $key, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            return (bool) Database::selectOne(
                'SELECT 1 FROM service_fields WHERE service_id = :s AND field_key = :k AND id <> :id LIMIT 1',
                [':s' => $serviceId, ':k' => $key, ':id' => $exceptId]
            );
        }
        return (bool) $this->findByKey($serviceId, $key);
    }

    /** How many submissions actually used this field? Used to warn before deletion. */
    public function usageCount(int $fieldId): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submission_field_values WHERE field_id = :f',
            [':f' => $fieldId]
        )['c'];
    }
}
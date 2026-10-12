<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PaymentRepository
{
    public function create(array $d): int
    {
        Database::execute(
            'INSERT INTO payments
                (payment_ref, user_id, wallet_id, gateway, method, amount, fee, net_amount,
                 status, gateway_reference, gateway_payload)
             VALUES
                (:ref, :uid, :wid, :gw, :method, :amt, :fee, :net,
                 :status, :gwref, :payload)',
            [
                ':ref'     => $d['payment_ref'],
                ':uid'     => $d['user_id'],
                ':wid'     => $d['wallet_id'],
                ':gw'      => $d['gateway'],
                ':method'  => $d['method'],
                ':amt'     => $d['amount'],
                ':fee'     => $d['fee'] ?? '0.00',
                ':net'     => $d['net_amount'],
                ':status'  => $d['status'] ?? 'pending',
                ':gwref'   => $d['gateway_reference'] ?? null,
                ':payload' => isset($d['gateway_payload']) ? json_encode($d['gateway_payload']) : null,
            ]
        );
        return Database::lastInsertId();
    }

    public function findById(int $id): ?array
    {
        return Database::selectOne(
            'SELECT p.*, u.full_name AS client_name, u.email AS client_email
             FROM payments p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.id = :id LIMIT 1',
            [':id' => $id]
        );
    }

    public function findByRef(string $ref): ?array
    {
        return Database::selectOne(
            'SELECT p.*, u.full_name AS client_name, u.email AS client_email
             FROM payments p
             INNER JOIN users u ON u.id = p.user_id
             WHERE p.payment_ref = :r LIMIT 1',
            [':r' => $ref]
        );
    }

    public function lockForUpdate(int $id): ?array
    {
        return Database::selectOne(
            'SELECT * FROM payments WHERE id = :id FOR UPDATE',
            [':id' => $id]
        );
    }

    public function paginateForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::selectAll(
            "SELECT * FROM payments WHERE user_id = :u ORDER BY created_at DESC, id DESC
             LIMIT $perPage OFFSET $offset",
            [':u' => $userId]
        );
        $total = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM payments WHERE user_id = :u',
            [':u' => $userId]
        )['c'];

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Super Admin listing with filters.
     *
     * @param array{status?:string,method?:string,q?:string,from?:string,to?:string} $filters
     */
    public function paginateForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = ['1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'p.method = :method';
            $params[':method'] = (string) $filters['method'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.payment_ref LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
            $params[':q'] = '%' . $filters['q'] . '%';
        }
        if (!empty($filters['from'])) {
            $where[] = 'p.created_at >= :from';
            $params[':from'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.created_at <= :to';
            $params[':to'] = (string) $filters['to'];
        }

        $whereSql = implode(' AND ', $where);

        $rows = Database::selectAll(
            "SELECT p.*, u.full_name AS client_name, u.email AS client_email
             FROM payments p
             INNER JOIN users u ON u.id = p.user_id
             WHERE $whereSql
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );
        $total = (int) Database::selectOne(
            "SELECT COUNT(*) AS c
             FROM payments p INNER JOIN users u ON u.id = p.user_id
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

    public function countByStatus(string $status): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM payments WHERE status = :s',
            [':s' => $status]
        )['c'];
    }

    public function markVerified(int $id, string $verifierType, int $verifierId): void
    {
        Database::execute(
            'UPDATE payments
                SET status = "successful",
                    verified_by_type = :t,
                    verified_by_id = :i,
                    verified_at = UTC_TIMESTAMP()
              WHERE id = :id AND status IN ("pending","awaiting_verification")',
            [':t' => $verifierType, ':i' => $verifierId, ':id' => $id]
        );
    }

    public function markRejected(int $id, string $verifierType, int $verifierId, string $reason): void
    {
        Database::execute(
            'UPDATE payments
                SET status = "rejected",
                    verified_by_type = :t,
                    verified_by_id = :i,
                    verified_at = UTC_TIMESTAMP(),
                    rejection_reason = :r
              WHERE id = :id AND status IN ("pending","awaiting_verification")',
            [':t' => $verifierType, ':i' => $verifierId, ':r' => $reason, ':id' => $id]
        );
    }
}
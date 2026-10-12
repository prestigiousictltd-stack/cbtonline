<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SubmissionLockRepository
{
    /**
     * Take an exclusive row lock. MUST be called inside a transaction.
     */
    public function lockForUpdate(int $submissionId): ?array
    {
        return Database::selectOne(
            'SELECT id, user_id, service_id, status, assigned_admin_id,
                    claimed_at, deadline_at, release_count,
                    admin_commission_amount, tracking_id
             FROM submissions
             WHERE id = :id
             FOR UPDATE',
            [':id' => $submissionId]
        );
    }

    /**
     * Conditional claim. Returns true if THIS call performed the claim.
     * MUST be called inside a transaction after lockForUpdate().
     */
    public function conditionalClaim(int $submissionId, int $adminId): bool
    {
        $affected = Database::execute(
            'UPDATE submissions
                SET status            = "processing",
                    assigned_admin_id = :admin,
                    claimed_at        = UTC_TIMESTAMP(),
                    deadline_at       = DATE_ADD(UTC_TIMESTAMP(),
                                                 INTERVAL processing_minutes_snapshot MINUTE)
              WHERE id = :id
                AND status = "pending"
                AND assigned_admin_id IS NULL',
            [':admin' => $adminId, ':id' => $submissionId]
        );

        return $affected === 1;
    }

    /**
     * Conditional release. Idempotent. Only releases rows whose deadline has passed.
     */
    public function conditionalRelease(int $submissionId, string $reason): bool
    {
        $affected = Database::execute(
            'UPDATE submissions
                SET status            = "pending",
                    assigned_admin_id = NULL,
                    deadline_at       = NULL,
                    release_count     = release_count + 1
              WHERE id = :id
                AND status = "processing"
                AND deadline_at IS NOT NULL
                AND deadline_at < UTC_TIMESTAMP()',
            [':id' => $submissionId]
        );

        if ($affected === 0 && $reason === 'expired') {
            return false;
        }
        return $affected === 1;
    }

    /**
     * Explicit release by the owning admin (before the deadline).
     */
    public function releaseByAdmin(int $submissionId, int $adminId): bool
    {
        $affected = Database::execute(
            'UPDATE submissions
                SET status            = "pending",
                    assigned_admin_id = NULL,
                    deadline_at       = NULL,
                    release_count     = release_count + 1
              WHERE id = :id
                AND status = "processing"
                AND assigned_admin_id = :admin',
            [':id' => $submissionId, ':admin' => $adminId]
        );
        return $affected === 1;
    }

    /**
     * Release all tasks held by an admin. Returns list of IDs released.
     */
    public function releaseAllForAdmin(int $adminId): array
    {
        $ids = Database::selectAll(
            'SELECT id FROM submissions
             WHERE assigned_admin_id = :admin AND status = "processing"',
            [':admin' => $adminId]
        );
        if (empty($ids)) return [];

        $idList = array_map(fn($r) => (int) $r['id'], $ids);
        $in     = implode(',', array_fill(0, count($idList), '?'));

        Database::execute(
            "UPDATE submissions
                SET status            = 'pending',
                    assigned_admin_id = NULL,
                    deadline_at       = NULL,
                    release_count     = release_count + 1
              WHERE id IN ($in) AND status = 'processing'",
            $idList
        );
        return $idList;
    }

    /**
     * Find submissions whose deadline has passed.
     */
    public function findExpired(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return Database::selectAll(
            "SELECT id, tracking_id, assigned_admin_id, deadline_at, release_count,
                    service_name_snapshot, user_id
             FROM submissions
             WHERE status = 'processing'
               AND deadline_at IS NOT NULL
               AND deadline_at < UTC_TIMESTAMP()
             ORDER BY deadline_at ASC
             LIMIT $limit"
        );
    }

    /**
     * Find processing tasks approaching their deadline.
     */
    public function findApproachingDeadline(int $withinMinutes = 60, int $limit = 500): array
    {
        $withinMinutes = max(5, min(1440, $withinMinutes));
        $limit         = max(1, min(1000, $limit));

        return Database::selectAll(
            "SELECT id, tracking_id, assigned_admin_id, deadline_at,
                    service_name_snapshot, user_id
             FROM submissions
             WHERE status = 'processing'
               AND deadline_at IS NOT NULL
               AND deadline_at >= UTC_TIMESTAMP()
               AND deadline_at <= DATE_ADD(UTC_TIMESTAMP(), INTERVAL $withinMinutes MINUTE)
             ORDER BY deadline_at ASC
             LIMIT $limit"
        );
    }

    public function findById(int $submissionId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM submissions WHERE id = :id LIMIT 1',
            [':id' => $submissionId]
        );
    }

    public function countActiveClaimsForAdmin(int $adminId): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions
             WHERE assigned_admin_id = :a AND status = "processing"',
            [':a' => $adminId]
        )['c'];
    }
}
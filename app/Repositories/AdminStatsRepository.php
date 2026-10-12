<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AdminStatsRepository
{
    public function summaryForAdmin(int $adminId): array
    {
        $completed = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE assigned_admin_id = :a AND status = "completed"',
            [':a' => $adminId]
        )['c'];

        $processing = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE assigned_admin_id = :a AND status = "processing"',
            [':a' => $adminId]
        )['c'];

        $errored = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE assigned_admin_id = :a AND status = "error_detected"',
            [':a' => $adminId]
        )['c'];

        $pendingPool = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions WHERE status = "pending"'
        )['c'];

        $commissionEarned = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE admin_id = :a AND type = "earned" AND status = "successful"',
            [':a' => $adminId]
        )['s'] ?? '0.00');

        $commissionWithdrawn = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE admin_id = :a AND type = "withdrawal" AND status = "successful"',
            [':a' => $adminId]
        )['s'] ?? '0.00');

        $pendingWithdrawals = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM withdrawals
             WHERE admin_id = :a AND status IN ("pending","approved")',
            [':a' => $adminId]
        )['s'] ?? '0.00');

        $admin = Database::selectOne(
            'SELECT commission_balance, commission_reserved FROM admins WHERE id = :a LIMIT 1',
            [':a' => $adminId]
        ) ?? ['commission_balance' => '0.00', 'commission_reserved' => '0.00'];

        return [
            'jobs_completed'         => $completed,
            'jobs_processing'        => $processing,
            'jobs_errored'           => $errored,
            'pending_in_pool'        => $pendingPool,
            'commission_balance'     => (string) $admin['commission_balance'],
            'commission_reserved'    => (string) $admin['commission_reserved'],
            'commission_earned'      => $commissionEarned,
            'commission_withdrawn'   => $commissionWithdrawn,
            'commission_pending_wd'  => $pendingWithdrawals,
        ];
    }

    public function queueSummary(): array
    {
        return [
            'pending'    => (int) Database::selectOne('SELECT COUNT(*) AS c FROM submissions WHERE status = "pending"')['c'],
            'processing' => (int) Database::selectOne('SELECT COUNT(*) AS c FROM submissions WHERE status = "processing"')['c'],
            'completed'  => (int) Database::selectOne('SELECT COUNT(*) AS c FROM submissions WHERE status = "completed"')['c'],
            'errored'    => (int) Database::selectOne('SELECT COUNT(*) AS c FROM submissions WHERE status = "error_detected"')['c'],
        ];
    }

    /** Per-service counters used by the sidebar and dashboard cards. */
    public function countsPerService(): array
    {
        $rows = Database::selectAll(
            'SELECT service_id, status, COUNT(*) AS c
             FROM submissions
             GROUP BY service_id, status'
        );
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['service_id'];
            $out[$sid][$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public function myCountsPerService(int $adminId): array
    {
        $rows = Database::selectAll(
            'SELECT service_id, status, COUNT(*) AS c
             FROM submissions
             WHERE assigned_admin_id = :a
             GROUP BY service_id, status',
            [':a' => $adminId]
        );
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['service_id'];
            $out[$sid][$r['status']] = (int) $r['c'];
        }
        return $out;
    }
}
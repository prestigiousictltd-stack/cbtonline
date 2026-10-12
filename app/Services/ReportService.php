<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ReportService
{
    /** Revenue grouped by service within a range. */
    public function revenueByService(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range('s.completed_at', $from, $to);

        return Database::selectAll(
            "SELECT sv.id, sv.name,
                    COUNT(*) AS jobs,
                    COALESCE(SUM(s.price_charged), 0.00)                            AS gross,
                    COALESCE(SUM(s.admin_commission_amount), 0.00)                  AS commission,
                    COALESCE(SUM(s.price_charged - s.admin_commission_amount), 0.00) AS revenue
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             WHERE s.status = 'completed'" . ($where ? " AND $where" : '') . "
             GROUP BY sv.id, sv.name
             ORDER BY revenue DESC",
            $params
        );
    }

    /** Revenue grouped by day within a range. */
    public function revenueByDay(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range('s.completed_at', $from, $to);

        return Database::selectAll(
            "SELECT DATE(s.completed_at) AS d,
                    COUNT(*) AS jobs,
                    COALESCE(SUM(s.price_charged), 0.00)                            AS gross,
                    COALESCE(SUM(s.price_charged - s.admin_commission_amount), 0.00) AS revenue
             FROM submissions s
             WHERE s.status = 'completed'" . ($where ? " AND $where" : '') . "
             GROUP BY DATE(s.completed_at)
             ORDER BY d ASC",
            $params
        );
    }

    public function clientReport(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range('u.created_at', $from, $to);

        return Database::selectAll(
            "SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at,
                    COALESCE(w.balance, 0.00) AS wallet_balance,
                    (SELECT COUNT(*) FROM submissions s WHERE s.user_id = u.id) AS submissions,
                    (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions wt
                        WHERE wt.user_id = u.id AND wt.category = 'funding'
                          AND wt.type = 'credit' AND wt.status = 'successful') AS total_funded,
                    (SELECT COALESCE(SUM(amount),0) FROM wallet_transactions wt
                        WHERE wt.user_id = u.id AND wt.category = 'service_charge'
                          AND wt.type = 'debit' AND wt.status = 'successful') AS total_spent
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             " . ($where ? "WHERE $where" : '') . "
             ORDER BY u.created_at DESC",
            $params
        );
    }

    public function adminReport(?string $from, ?string $to): array
    {
        // Range applies to completed_at via subquery; admins list is always all
        [$where, $params] = $this->range('s.completed_at', $from, $to);
        $innerWhere = $where ? "AND $where" : '';

        return Database::selectAll(
            "SELECT a.id, a.full_name, a.email, a.username, a.status,
                    a.commission_balance, a.commission_reserved,
                    (SELECT COUNT(*) FROM submissions s
                     WHERE s.assigned_admin_id = a.id AND s.status = 'completed' $innerWhere) AS jobs_completed,
                    (SELECT COUNT(*) FROM submissions s
                     WHERE s.assigned_admin_id = a.id AND s.status = 'processing') AS jobs_processing,
                    (SELECT COALESCE(SUM(amount),0) FROM commission_transactions ct
                     WHERE ct.admin_id = a.id AND ct.type = 'earned' AND ct.status = 'successful') AS commission_earned,
                    (SELECT COALESCE(SUM(amount),0) FROM withdrawals w
                     WHERE w.admin_id = a.id AND w.status = 'paid') AS commission_withdrawn
             FROM admins a
             ORDER BY jobs_completed DESC, a.full_name ASC",
            $params
        );
    }

    public function serviceUsageReport(?string $from, ?string $to): array
    {
        [$where, $params] = $this->range('s.created_at', $from, $to);

        return Database::selectAll(
            "SELECT sv.id, sv.name, sv.status AS service_status,
                    COUNT(s.id) AS submissions,
                    SUM(CASE WHEN s.status='pending'        THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN s.status='processing'     THEN 1 ELSE 0 END) AS processing,
                    SUM(CASE WHEN s.status='completed'      THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN s.status='error_detected' THEN 1 ELSE 0 END) AS errored,
                    SUM(CASE WHEN s.status='cancelled'      THEN 1 ELSE 0 END) AS cancelled,
                    COALESCE(SUM(s.price_charged), 0.00) AS gross
             FROM services sv
             LEFT JOIN submissions s ON s.service_id = sv.id
             " . ($where ? "WHERE $where" : '') . "
             GROUP BY sv.id, sv.name, sv.status
             ORDER BY submissions DESC",
            $params
        );
    }

    /**
     * @return array{0:string,1:array}
     */
    private function range(string $col, ?string $from, ?string $to): array
    {
        $conds  = [];
        $params = [];
        if ($from) { $conds[] = "$col >= :from"; $params[':from'] = $from; }
        if ($to)   { $conds[] = "$col <= :to";   $params[':to']   = $to;   }
        return [implode(' AND ', $conds), $params];
    }
}
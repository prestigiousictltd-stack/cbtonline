<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SuperAdminStatsRepository
{
    /**
     * @param array{from?:?string,to?:?string} $range  UTC datetime bounds (inclusive)
     */
    public function clientStats(array $range = []): array
    {
        [$where, $params] = $this->rangeClause('created_at', $range);

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active'   THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended
                FROM users
                " . ($where ? "WHERE $where" : '');
        $row = Database::selectOne($sql, $params) ?? [];

        $totalBalance = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(balance), 0.00) AS s FROM wallets'
        )['s'] ?? '0.00');

        return [
            'total'         => (int) ($row['total']    ?? 0),
            'active'        => (int) ($row['active']   ?? 0),
            'inactive'      => (int) ($row['inactive'] ?? 0),
            'suspended'     => (int) ($row['suspended']?? 0),
            'wallet_total'  => $totalBalance,
        ];
    }

    public function newClientsCount(array $range): int
    {
        [$where, $params] = $this->rangeClause('created_at', $range);
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM users' . ($where ? " WHERE $where" : ''),
            $params
        )['c'];
    }

    /**
     * @param array{from?:?string,to?:?string} $range
     */
    public function serviceStats(array $range = []): array
{
    $totalServices  = (int) Database::selectOne('SELECT COUNT(*) AS c FROM services')['c'];
    $activeServices = (int) Database::selectOne('SELECT COUNT(*) AS c FROM services WHERE status="active"')['c'];

    // Each status is filtered by the date that matters for it:
    //   completed  → completed_at
    //   cancelled  → cancelled_at
    //   everything else → created_at
    // That way a submission created in September and completed in October
    // shows up correctly under "This month".
    if (empty($range['from']) && empty($range['to'])) {
        $counts = Database::selectAll(
            'SELECT status, COUNT(*) AS c FROM submissions GROUP BY status'
        );
    } else {
        $counts = Database::selectAll(
            'SELECT status, COUNT(*) AS c
             FROM submissions
             WHERE CASE
                       WHEN status = "completed" THEN completed_at
                       WHEN status = "cancelled" THEN cancelled_at
                       ELSE created_at
                   END BETWEEN :from AND :to
             GROUP BY status',
            [':from' => $range['from'], ':to' => $range['to']]
        );
    }

    $byStatus = [
        'pending' => 0, 'processing' => 0, 'completed' => 0,
        'error_detected' => 0, 'cancelled' => 0,
    ];
    foreach ($counts as $r) {
        $byStatus[$r['status']] = (int) $r['c'];
    }
    $totalSubmissions = array_sum($byStatus);

    return [
        'total_services'   => $totalServices,
        'active_services'  => $activeServices,
        'total'            => $totalSubmissions,
        'pending'          => $byStatus['pending'],
        'processing'       => $byStatus['processing'],
        'completed'        => $byStatus['completed'],
        'error_detected'   => $byStatus['error_detected'],
        'cancelled'        => $byStatus['cancelled'],
    ];
}

    public function adminStats(array $range = []): array
    {
        $totalAdmins  = (int) Database::selectOne('SELECT COUNT(*) AS c FROM admins')['c'];
        $activeAdmins = (int) Database::selectOne('SELECT COUNT(*) AS c FROM admins WHERE status="active"')['c'];

        [$where, $params] = $this->rangeClause('completed_at', $range);

        $jobsCompleted = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM submissions
             WHERE status = "completed"' . ($where ? " AND $where" : ''),
            $params
        )['c'];

        [$whereC, $paramsC] = $this->rangeClause('created_at', $range);

        $commissionEarned = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE type = "earned" AND status = "successful"'
            . ($whereC ? " AND $whereC" : ''),
            $paramsC
        )['s'] ?? '0.00');

        $commissionWithdrawn = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE type = "withdrawal" AND status = "successful"'
            . ($whereC ? " AND $whereC" : ''),
            $paramsC
        )['s'] ?? '0.00');

        $pendingWithdrawals = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM withdrawals WHERE status = "pending"'
        )['c'];

        $pendingWithdrawalsAmount = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s FROM withdrawals WHERE status IN ("pending","approved")'
        )['s'] ?? '0.00');

        return [
            'total'                       => $totalAdmins,
            'active'                      => $activeAdmins,
            'jobs_completed'              => $jobsCompleted,
            'commission_earned'           => $commissionEarned,
            'commission_withdrawn'        => $commissionWithdrawn,
            'pending_withdrawals'         => $pendingWithdrawals,
            'pending_withdrawals_amount'  => $pendingWithdrawalsAmount,
        ];
    }

    public function financialStats(array $range = []): array
    {
        [$whereW, $paramsW] = $this->rangeClause('created_at', $range);

        $totalWalletFunding = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM wallet_transactions
             WHERE category = "funding" AND type = "credit" AND status = "successful"'
            . ($whereW ? " AND $whereW" : ''),
            $paramsW
        )['s'] ?? '0.00');

        $totalServiceCharges = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM wallet_transactions
             WHERE category = "service_charge" AND type = "debit" AND status = "successful"'
            . ($whereW ? " AND $whereW" : ''),
            $paramsW
        )['s'] ?? '0.00');

        $totalCommission = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM commission_transactions
             WHERE type = "earned" AND status = "successful"'
            . ($whereW ? " AND $whereW" : ''),
            $paramsW
        )['s'] ?? '0.00');

        $totalWithdrawals = (string) (Database::selectOne(
            'SELECT COALESCE(SUM(amount), 0.00) AS s
             FROM withdrawals WHERE status = "paid"'
            . ($whereW ? " AND $whereW" : ''),
            $paramsW
        )['s'] ?? '0.00');

        // Platform revenue = service charges − admin commission
        try {
            $revenue = money_sub($totalServiceCharges, $totalCommission);
        } catch (\Throwable) {
            $revenue = '0.00';
        }

        return [
            'revenue'              => $revenue,
            'total_wallet_funding' => $totalWalletFunding,
            'total_service_charges'=> $totalServiceCharges,
            'total_commission'     => $totalCommission,
            'total_withdrawals'    => $totalWithdrawals,
        ];
    }

    /**
     * Revenue per service in a date range — used on the dashboard's
     * "Revenue by service" table.
     */
    public function revenueByService(array $range = [], int $limit = 10): array
    {
        [$where, $params] = $this->rangeClause('s.completed_at', $range);
        $limit = max(1, min(50, $limit));

        return Database::selectAll(
            "SELECT sv.id, sv.name,
                    COUNT(*) AS jobs_completed,
                    COALESCE(SUM(s.price_charged), 0.00)            AS gross,
                    COALESCE(SUM(s.admin_commission_amount), 0.00)  AS commission,
                    COALESCE(SUM(s.price_charged - s.admin_commission_amount), 0.00) AS revenue
             FROM submissions s
             INNER JOIN services sv ON sv.id = s.service_id
             WHERE s.status = 'completed'" . ($where ? " AND $where" : '') . "
             GROUP BY sv.id, sv.name
             ORDER BY revenue DESC
             LIMIT $limit",
            $params
        );
    }

    public function countsPerService(): array
    {
        $rows = Database::selectAll(
            'SELECT service_id, status, COUNT(*) AS c
             FROM submissions GROUP BY service_id, status'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['service_id']][$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    // ----------------------------------------------------------------

    /**
     * @return array{0:string,1:array}
     */
    private function rangeClause(string $column, array $range): array
    {
        $conds  = [];
        $params = [];
        if (!empty($range['from'])) {
            $conds[] = "$column >= :from";
            $params[':from'] = (string) $range['from'];
        }
        if (!empty($range['to'])) {
            $conds[] = "$column <= :to";
            $params[':to'] = (string) $range['to'];
        }
        return [implode(' AND ', $conds), $params];
    }
}
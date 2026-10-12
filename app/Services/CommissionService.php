<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminCommissionRepository;
use App\Repositories\CommissionTransactionRepository;
use RuntimeException;

final class CommissionService
{
    public function __construct(
        private readonly AdminCommissionRepository        $admins = new AdminCommissionRepository(),
        private readonly CommissionTransactionRepository  $ledger = new CommissionTransactionRepository(),
        private readonly AuditService                     $audit  = new AuditService(),
    ) {}

    /**
     * Credit commission for a completed task.
     * MUST be called inside an outer transaction — does not open its own.
     *
     * @return string The admin's new commission balance
     */
    public function creditEarned(
        int    $adminId,
        int    $submissionId,
        string $amount,
        string $trackingId
    ): string {
        $amount = money_normalize($amount);
        if (money_cmp($amount, '0.00') <= 0) {
            throw new RuntimeException('Commission amount must be positive.');
        }

        $before = $this->admins->currentBalance($adminId);
        $after  = $this->admins->creditBalance($adminId, $amount);

        $this->ledger->create([
            'transaction_ref' => next_reference('CTX'),
            'admin_id'        => $adminId,
            'submission_id'   => $submissionId,
            'type'            => 'earned',
            'direction'       => 'credit',
            'amount'          => $amount,
            'balance_before'  => $before,
            'balance_after'   => $after,
            'status'          => 'successful',
            'description'     => 'Commission for ' . $trackingId,
            'created_by_type' => 'system',
        ]);

        $this->audit->log(
            'COMMISSION_EARNED', 'commission', null,
            'Commission ' . $amount . ' earned for ' . $trackingId,
            oldValues: ['balance' => $before],
            newValues: ['balance' => $after, 'amount' => $amount]
        );

        return $after;
    }
}
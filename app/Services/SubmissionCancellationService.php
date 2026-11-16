<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\SubmissionLockRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use Throwable;

final class SubmissionCancellationService
{
    public function __construct(
        private readonly SubmissionLockRepository          $locks   = new SubmissionLockRepository(),
        private readonly SubmissionStatusHistoryRepository $history = new SubmissionStatusHistoryRepository(),
        private readonly WalletService                     $wallet  = new WalletService(),
        private readonly NotificationService               $notify  = new NotificationService(),
        private readonly AuditService                      $audit   = new AuditService(),
    ) {}

    /**
     * Cancel a submission and refund the client's wallet with the exact
     * amount they were charged when they submitted it.
     *
     * Permitted statuses: pending, processing, error_detected.
     * Refused: completed (commission already paid — that's a dispute flow,
     *          not a cancellation), cancelled (already done), not_found.
     *
     * @return array{ok:bool,error?:string,refund_amount?:string,previous_status?:string}
     */
    public function cancel(int $submissionId, array $superAdmin, string $reason): array
    {
        $superAdminId = (int) ($superAdmin['id'] ?? 0);
        if ($superAdminId <= 0) {
            return ['ok' => false, 'error' => 'INVALID_ACTOR'];
        }

        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'REASON_REQUIRED'];
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        try {
            return Database::transaction(function () use ($submissionId, $superAdminId, $reason): array {
                $row = $this->locks->lockForCancellation($submissionId);
                if (!$row) {
                    return ['ok' => false, 'error' => 'NOT_FOUND'];
                }

                if (!in_array($row['status'], ['pending', 'processing', 'error_detected'], true)) {
                    return [
                        'ok'     => false,
                        'error'  => 'NOT_CANCELLABLE',
                        'status' => (string) $row['status'],
                    ];
                }

                $refundAmount   = money_normalize((string) $row['price_charged']);
                $previousStatus = (string) $row['status'];
                $previousAdminId = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;

                // 1. Refund the wallet — transaction-scoped, writes ledger row
                if (money_cmp($refundAmount, '0.00') > 0) {
                    $this->wallet->creditTx([
                        'user_id'         => (int) $row['user_id'],
                        'amount'          => $refundAmount,
                        'category'        => WALLET_CAT_REFUND,
                        'reference_type'  => 'submission',
                        'reference_id'    => $submissionId,
                        'description'     => 'Refund for cancelled submission ' . $row['tracking_id'],
                        'created_by_type' => 'super_admin',
                        'created_by_id'   => $superAdminId,
                    ]);
                }

                // 2. Flip status + clear any lock / error state
                $affected = Database::execute(
                    'UPDATE submissions
                        SET status            = "cancelled",
                            cancelled_at      = UTC_TIMESTAMP(),
                            assigned_admin_id = NULL,
                            claimed_at        = NULL,
                            deadline_at       = NULL,
                            error_remarks     = :reason
                      WHERE id = :id
                        AND status IN ("pending","processing","error_detected")',
                    [':reason' => $reason, ':id' => $submissionId]
                );

                if ($affected !== 1) {
                    // Something raced us — the row is no longer cancellable
                    throw new \RuntimeException('STATUS_RACE');
                }

                // 3. Timeline
                $this->history->record(
                    $submissionId,
                    $previousStatus,
                    'cancelled',
                    'super_admin',
                    $superAdminId,
                    'Cancelled and refunded: ' . $reason
                );

                // 4. Audit — with old/new values for the diff view
                $this->audit->log(
                    'SUBMISSION_CANCELLED',
                    'submission',
                    $submissionId,
                    'Super Admin cancelled submission ' . $row['tracking_id'] . ': ' . $reason,
                    oldValues: [
                        'status'            => $previousStatus,
                        'assigned_admin_id' => $previousAdminId,
                    ],
                    newValues: [
                        'status'        => 'cancelled',
                        'refund_amount' => $refundAmount,
                        'reason'        => $reason,
                    ],
                    userType: 'super_admin',
                    userId: $superAdminId
                );

                // 5. Notify the client
                $this->notify->notifyClient(
                    (int) $row['user_id'],
                    'Submission cancelled',
                    'Your submission ' . $row['tracking_id'] . ' has been cancelled. '
                        . money($refundAmount) . ' has been refunded to your wallet. '
                        . 'Reason: ' . $reason,
                    'submission',
                    '/client/submission-view?id=' . $submissionId
                );

                // 6. Notify the previous admin if the task was held
                if ($previousAdminId) {
                    $this->notify->notifyAdmin(
                        $previousAdminId,
                        'Task cancelled by Super Admin',
                        'Submission ' . $row['tracking_id'] . ' was cancelled and refunded. '
                            . 'You no longer need to work on it. Reason: ' . $reason,
                        'task',
                        '/admin/service?id=' . (int) $row['service_id']
                    );
                }

                return [
                    'ok'              => true,
                    'refund_amount'   => $refundAmount,
                    'previous_status' => $previousStatus,
                ];
            });
        } catch (Throwable $e) {
            Logger::error('Submission cancellation failed', [
                'error'         => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }
}
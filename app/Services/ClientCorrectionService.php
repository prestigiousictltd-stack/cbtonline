<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionLockRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Support\DynamicForm;
use Throwable;

final class ClientCorrectionService
{
    public function __construct(
        private readonly SubmissionLockRepository          $locks        = new SubmissionLockRepository(),
        private readonly SubmissionFieldValueRepository    $fieldValues  = new SubmissionFieldValueRepository(),
        private readonly SubmissionStatusHistoryRepository $history      = new SubmissionStatusHistoryRepository(),
        private readonly NotificationService               $notify       = new NotificationService(),
        private readonly AuditService                      $audit        = new AuditService(),
    ) {}

    /**
     * Client responds to an error-detected submission with corrected details.
     *
     * Rules enforced:
     *   - The submission must belong to the calling client.
     *   - The submission status must be exactly `error_detected`.
     *   - Dynamic form fields are validated with the same DynamicForm used at
     *     original submission time.
     *
     * On success:
     *   - Field values are upserted (updated in place; new fields inserted).
     *   - Status returns to `processing` if an admin still holds it,
     *     otherwise to `pending` (returns to the pool).
     *   - A fresh deadline is set when status → processing.
     *   - Admin (or all admins) and client are notified.
     *   - Timeline + audit entries are written.
     *
     * @param  array<string,mixed> $fieldInput  The raw $_POST['fields'] array.
     * @return array{ok:bool,error?:string,errors?:array<string,string>,new_status?:string}
     */
    public function respondToError(int $userId, int $submissionId, array $fieldInput): array
    {
        try {
            return Database::transaction(function () use ($userId, $submissionId, $fieldInput): array {
                // 1. Lock the submission row
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) {
                    return ['ok' => false, 'error' => 'NOT_FOUND'];
                }

                // 2. Ownership
                if ((int) $row['user_id'] !== $userId) {
                    return ['ok' => false, 'error' => 'NOT_YOURS'];
                }

                // 3. Only error_detected submissions can be corrected
                if ($row['status'] !== 'error_detected') {
                    return ['ok' => false, 'error' => 'NOT_IN_ERROR'];
                }

                // 4. Validate the dynamic form against the current service config
                $form = DynamicForm::forService((int) $row['service_id']);
                $validated = $form->validate($fieldInput);

                if (empty($validated['ok'])) {
                    return ['ok' => false, 'errors' => $validated['errors']];
                }

                // 5. Upsert each field value
                foreach ($validated['values'] as $v) {
                    $this->fieldValues->upsertForSubmission($submissionId, $v);
                }

                // 6. Decide the next status
                $previousAdminId = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;
                $nextStatus      = $previousAdminId ? 'processing' : 'pending';

                $newDeadline = null;
                if ($nextStatus === 'processing') {
                    $minutes     = max(1, (int) $row['processing_minutes_snapshot']);
                    $newDeadline = date('Y-m-d H:i:s', time() + ($minutes * 60));
                }

                // 7. Update the submission
                Database::execute(
                    'UPDATE submissions
                        SET status        = :st,
                            error_remarks = NULL,
                            deadline_at   = :dl
                      WHERE id = :id',
                    [
                        ':st' => $nextStatus,
                        ':dl' => $newDeadline,
                        ':id' => $submissionId,
                    ]
                );

                // 8. Timeline
                $this->history->record(
                    $submissionId,
                    'error_detected',
                    $nextStatus,
                    'client',
                    $userId,
                    'Client submitted corrected details'
                );

                // 9. Audit
                $this->audit->log(
                    'SUBMISSION_CLIENT_CORRECTION',
                    'submission',
                    $submissionId,
                    'Client responded to error on ' . $row['tracking_id'],
                    oldValues: ['status' => 'error_detected'],
                    newValues: ['status' => $nextStatus, 'deadline' => $newDeadline],
                    userType: 'client',
                    userId: $userId
                );

                // 10. Notify the admin holding the task — or all admins if it's back in the pool
                if ($previousAdminId) {
                    $this->notify->notifyAdmin(
                        $previousAdminId,
                        'Client corrected submission',
                        'Client has corrected ' . $row['tracking_id']
                            . '. Please review the updated details and continue processing.',
                        'task',
                        '/admin/process-task?id=' . $submissionId
                    );
                } else {
                    $this->notify->notifyAllAdmins(
                        'Task available (client corrected)',
                        'Submission ' . $row['tracking_id']
                            . ' has been corrected by the client and is ready for processing.',
                        'task',
                        '/admin/service?id=' . (int) $row['service_id']
                    );
                }

                // 11. Notify the client that their correction landed
                $this->notify->notifyClient(
                    $userId,
                    'Correction received',
                    'Your corrected details for ' . $row['tracking_id']
                        . ' have been sent to the processing team.',
                    'submission',
                    '/client/submission-view?id=' . $submissionId
                );

                return [
                    'ok'         => true,
                    'new_status' => $nextStatus,
                ];
            });
        } catch (Throwable $e) {
            Logger::error('Client correction failed', [
                'error'         => $e->getMessage(),
                'submission_id' => $submissionId,
                'user_id'       => $userId,
            ]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }
}
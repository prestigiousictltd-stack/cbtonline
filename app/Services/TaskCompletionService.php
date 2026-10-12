<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\DocumentRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\SubmissionLockRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use RuntimeException;
use Throwable;

final class TaskCompletionService
{
    public function __construct(
        private readonly SubmissionLockRepository          $locks     = new SubmissionLockRepository(),
        private readonly SubmissionStatusHistoryRepository $history   = new SubmissionStatusHistoryRepository(),
        private readonly FeedbackRepository                $feedback  = new FeedbackRepository(),
        private readonly DocumentRepository                $documents = new DocumentRepository(),
        private readonly DocumentService                   $docSvc    = new DocumentService(),
        private readonly CommissionService                 $commission = new CommissionService(),
        private readonly NotificationService               $notify    = new NotificationService(),
        private readonly AuditService                      $audit     = new AuditService(),
    ) {}

    // ============================================================
    // COMPLETE (unchanged — see previous step)
    // ============================================================

    public function complete(array $admin, int $submissionId, string $remarks, array $files): array
    {
        $adminId = (int) $admin['id'];
        $remarks = trim($remarks);

        if ($remarks === '') {
            return ['ok' => false, 'errors' => ['remarks' => 'Please enter written remarks before completing the task.']];
        }
        if (mb_strlen($remarks) > 5000) {
            return ['ok' => false, 'errors' => ['remarks' => 'Remarks must not exceed 5000 characters.']];
        }

        $storedMeta = [];
        $fileErrors = [];
        if (!empty($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => $files['name'][$i]     ?? '',
                    'type'     => $files['type'][$i]     ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i]     ?? 0,
                ];
                if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) continue;
                try {
                    $storedMeta[] = $this->docSvc->storeUpload($file, 'documents');
                } catch (Throwable $e) {
                    $fileErrors[] = sprintf('%s: %s', (string) $file['name'], $e->getMessage());
                }
            }
        }

        if ($fileErrors) {
            return ['ok' => false, 'errors' => ['documents' => implode(' | ', $fileErrors)]];
        }

        try {
            return Database::transaction(function () use ($adminId, $submissionId, $remarks, $storedMeta): array {
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if ((int) $row['assigned_admin_id'] !== $adminId || $row['status'] !== 'processing') {
                    return ['ok' => false, 'error' => 'NOT_YOUR_TASK'];
                }

                $deadlineTs = $row['deadline_at'] ? strtotime($row['deadline_at'] . ' UTC') : null;
                if ($deadlineTs !== null && $deadlineTs < time()) {
                    $this->locks->conditionalRelease($submissionId, 'expired');
                    $this->history->record($submissionId, 'processing', 'pending', 'system', null,
                        'Deadline expired during completion attempt');
                    return ['ok' => false, 'error' => 'DEADLINE_EXPIRED'];
                }

                $feedbackId = $this->feedback->create([
                    'submission_id'     => $submissionId,
                    'admin_id'          => $adminId,
                    'feedback_type'     => 'completion',
                    'remarks'           => $remarks,
                    'status_snapshot'   => 'completed',
                    'is_client_visible' => 1,
                ]);

                foreach ($storedMeta as $meta) {
                    $this->documents->create([
                        'submission_id'    => $submissionId,
                        'feedback_id'      => $feedbackId,
                        'uploaded_by_type' => 'admin',
                        'uploaded_by_id'   => $adminId,
                        'document_type'    => 'completion_result',
                        'original_name'    => $meta['original_name'],
                        'stored_name'      => $meta['stored_name'],
                        'file_path'        => $meta['file_path'],
                        'mime_type'        => $meta['mime_type'],
                        'file_size'        => $meta['file_size'],
                        'checksum_sha256'  => $meta['checksum_sha256'],
                    ]);
                }

                Database::execute(
                    'UPDATE submissions
                        SET status = "completed", completed_at = UTC_TIMESTAMP(), deadline_at = NULL
                      WHERE id = :id AND status = "processing" AND assigned_admin_id = :a',
                    [':id' => $submissionId, ':a' => $adminId]
                );

                $this->history->record($submissionId, 'processing', 'completed', 'admin', $adminId,
                    'Task completed by admin');

                $commission = money_normalize((string) $row['admin_commission_amount']);
                $newBalance = '0.00';
                if (money_cmp($commission, '0.00') > 0) {
                    $newBalance = $this->commission->creditEarned(
                        $adminId, $submissionId, $commission, (string) $row['tracking_id']
                    );
                }

                $this->audit->log(
                    'TASK_COMPLETED', 'submission', $submissionId,
                    'Task ' . $row['tracking_id'] . ' completed',
                    newValues: ['commission' => $commission, 'documents' => count($storedMeta)],
                    userType: 'admin', userId: $adminId
                );

                if (money_cmp($commission, '0.00') > 0) {
                    $this->notify->notifyAdmin(
                        $adminId, 'Commission earned',
                        'You earned ' . money($commission) . ' for ' . $row['tracking_id'] . '.',
                        'commission', '/admin/commissions'
                    );
                }

                $this->notify->notifyClient(
                    (int) $row['user_id'], 'Submission completed',
                    'Your request ' . $row['tracking_id'] . ' is complete. Results are available in your submissions.',
                    'submission', '/client/submission-view?id=' . $submissionId
                );

                return [
                    'ok'          => true,
                    'commission'  => $commission,
                    'tracking_id' => (string) $row['tracking_id'],
                    'new_balance' => $newBalance,
                ];
            });
        } catch (Throwable $e) {
            Logger::error('Task completion failed', ['error' => $e->getMessage(), 'submission_id' => $submissionId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ============================================================
    // REPORT ERROR (unchanged)
    // ============================================================

    public function reportError(array $admin, int $submissionId, string $remarks, string $disposition = 'release'): array
    {
        $adminId = (int) $admin['id'];
        $remarks = trim($remarks);

        if ($remarks === '') {
            return ['ok' => false, 'errors' => ['remarks' => 'Please describe the problem before reporting the error.']];
        }

        try {
            return Database::transaction(function () use ($adminId, $submissionId, $remarks, $disposition): array {
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) return ['ok' => false, 'error' => 'NOT_FOUND'];
                if ((int) $row['assigned_admin_id'] !== $adminId || $row['status'] !== 'processing') {
                    return ['ok' => false, 'error' => 'NOT_YOUR_TASK'];
                }

                $this->feedback->create([
                    'submission_id'     => $submissionId,
                    'admin_id'          => $adminId,
                    'feedback_type'     => 'error_report',
                    'remarks'           => $remarks,
                    'status_snapshot'   => 'error_detected',
                    'is_client_visible' => 1,
                ]);

                $newStatus = $disposition === 'hold' ? 'error_detected' : 'pending';

                if ($newStatus === 'error_detected') {
                    Database::execute(
                        'UPDATE submissions SET status = "error_detected", error_remarks = :r, deadline_at = NULL WHERE id = :id',
                        [':r' => mb_substr($remarks, 0, 500), ':id' => $submissionId]
                    );
                } else {
                    Database::execute(
                        'UPDATE submissions SET status = "pending", assigned_admin_id = NULL, deadline_at = NULL,
                            release_count = release_count + 1, error_remarks = :r WHERE id = :id',
                        [':r' => mb_substr($remarks, 0, 500), ':id' => $submissionId]
                    );
                }

                $this->history->record($submissionId, 'processing', $newStatus, 'admin', $adminId,
                    'Error reported: ' . mb_substr($remarks, 0, 200));

                $this->audit->log(
                    'TASK_ERROR_REPORTED', 'submission', $submissionId,
                    'Error reported by admin',
                    newValues: ['disposition' => $disposition, 'new_status' => $newStatus],
                    userType: 'admin', userId: $adminId
                );

                $this->notify->notifyClient(
                    (int) $row['user_id'], 'Issue with your submission',
                    'There was a problem with ' . $row['tracking_id'] . ': ' . $remarks,
                    'submission', '/client/submission-view?id=' . $submissionId
                );

                return ['ok' => true, 'new_status' => $newStatus];
            });
        } catch (Throwable $e) {
            Logger::error('Task report error failed', ['error' => $e->getMessage(), 'submission_id' => $submissionId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ============================================================
    // UPDATE COMPLETED — NEW
    // ============================================================

    /**
     * Correct information on a completed submission: edit the completion
     * remarks, remove result documents, add new ones.
     *
     * Only the admin who originally completed the task may edit it.
     * Super admins may also edit (their id is not in admins, so this
     * check is enforced by the caller in that case).
     *
     * @param array $admin        The admin row.
     * @param int   $submissionId
     * @param string $remarks
     * @param int[] $removeDocIds Documents to delete (by document id).
     * @param array $newFiles     Multi-file upload ($_FILES['documents']), optional.
     * @param bool  $notifyClient Whether to notify the client of the update.
     *
     * @return array{ok:bool,error?:string,errors?:array<string,string>,updated:int}
     */
    public function updateCompleted(
        array $admin,
        int $submissionId,
        string $remarks,
        array $removeDocIds = [],
        array $newFiles = [],
        bool $notifyClient = true
    ): array {
        $adminId = (int) $admin['id'];
        $remarks = trim($remarks);

        if ($remarks === '') {
            return ['ok' => false, 'errors' => ['remarks' => 'Remarks cannot be blank.']];
        }
        if (mb_strlen($remarks) > 5000) {
            return ['ok' => false, 'errors' => ['remarks' => 'Remarks must not exceed 5000 characters.']];
        }

        // Store any new files first (outside the transaction so we don't hold DB locks during disk I/O)
        $storedMeta = [];
        $fileErrors = [];
        if (!empty($newFiles['name']) && is_array($newFiles['name'])) {
            $count = count($newFiles['name']);
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => $newFiles['name'][$i]     ?? '',
                    'type'     => $newFiles['type'][$i]     ?? '',
                    'tmp_name' => $newFiles['tmp_name'][$i] ?? '',
                    'error'    => $newFiles['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $newFiles['size'][$i]     ?? 0,
                ];
                if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) continue;
                try {
                    $storedMeta[] = $this->docSvc->storeUpload($file, 'documents');
                } catch (Throwable $e) {
                    $fileErrors[] = sprintf('%s: %s', (string) $file['name'], $e->getMessage());
                }
            }
        }
        if ($fileErrors) {
            return ['ok' => false, 'errors' => ['documents' => implode(' | ', $fileErrors)]];
        }

        try {
            $result = Database::transaction(function () use (
                $adminId, $submissionId, $remarks, $removeDocIds, $storedMeta, $notifyClient
            ): array {
                // Lock the submission
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) return ['ok' => false, 'error' => 'NOT_FOUND'];

                if ($row['status'] !== 'completed') {
                    return ['ok' => false, 'error' => 'NOT_COMPLETED'];
                }
                if ((int) $row['assigned_admin_id'] !== $adminId) {
                    return ['ok' => false, 'error' => 'NOT_YOUR_TASK'];
                }

                // Load the current completion feedback
                $feedback = $this->feedback->findLatestForSubmission($submissionId, 'completion');
                if (!$feedback) {
                    return ['ok' => false, 'error' => 'NO_FEEDBACK'];
                }

                $oldRemarks = (string) $feedback['remarks'];
                $feedbackId = (int) $feedback['id'];

                // 1. Update remarks if they changed
                if ($oldRemarks !== $remarks) {
                    $this->feedback->updateRemarks($feedbackId, $remarks);
                }

                // 2. Delete requested documents (only ones that belong to this submission)
                $deletedCount = 0;
                if (!empty($removeDocIds)) {
                    $existing = $this->documents->listForSubmission($submissionId);
                    $existingById = [];
                    foreach ($existing as $d) { $existingById[(int) $d['id']] = $d; }

                    foreach ($removeDocIds as $rawId) {
                        $docId = (int) $rawId;
                        if ($docId <= 0 || !isset($existingById[$docId])) continue;
                        $this->documents->delete($docId);
                        $deletedCount++;

                        // Remove the file from disk
                        $path = $existingById[$docId]['file_path'] ?? '';
                        if ($path !== '') {
                            try {
                                $abs = $this->docSvc->absolutePath($path);
                                if (is_file($abs)) @unlink($abs);
                            } catch (Throwable) { /* ignore */ }
                        }
                    }
                }

                // 3. Add new documents
                $addedCount = 0;
                foreach ($storedMeta as $meta) {
                    $this->documents->create([
                        'submission_id'    => $submissionId,
                        'feedback_id'      => $feedbackId,
                        'uploaded_by_type' => 'admin',
                        'uploaded_by_id'   => $adminId,
                        'document_type'    => 'completion_result',
                        'original_name'    => $meta['original_name'],
                        'stored_name'      => $meta['stored_name'],
                        'file_path'        => $meta['file_path'],
                        'mime_type'        => $meta['mime_type'],
                        'file_size'        => $meta['file_size'],
                        'checksum_sha256'  => $meta['checksum_sha256'],
                    ]);
                    $addedCount++;
                }

                // 4. Timeline entry
                $this->history->record(
                    $submissionId, 'completed', 'completed', 'admin', $adminId,
                    'Completion updated (remarks ' . ($oldRemarks !== $remarks ? 'changed' : 'unchanged')
                        . ', docs removed ' . $deletedCount . ', docs added ' . $addedCount . ')'
                );

                // 5. Audit
                $this->audit->log(
                    'COMPLETED_TASK_UPDATED', 'submission', $submissionId,
                    'Admin edited completed submission ' . $row['tracking_id'],
                    oldValues: [
                        'remarks' => $oldRemarks,
                    ],
                    newValues: [
                        'remarks'       => $remarks,
                        'docs_removed'  => $deletedCount,
                        'docs_added'    => $addedCount,
                        'notify_client' => $notifyClient,
                    ],
                    userType: 'admin', userId: $adminId
                );

                // 6. Optionally notify the client
                if ($notifyClient) {
                    $this->notify->notifyClient(
                        (int) $row['user_id'],
                        'Submission updated',
                        'The processing team updated your completed submission ' . $row['tracking_id']
                            . '. Please review the updated remarks or documents.',
                        'submission',
                        '/client/submission-view?id=' . $submissionId
                    );
                }

                return [
                    'ok'             => true,
                    'updated'        => ($oldRemarks !== $remarks ? 1 : 0) + $deletedCount + $addedCount,
                    'remarks_changed'=> ($oldRemarks !== $remarks),
                    'docs_removed'   => $deletedCount,
                    'docs_added'     => $addedCount,
                ];
            });

            return $result;
        } catch (Throwable $e) {
            Logger::error('Update completed failed', ['error' => $e->getMessage(), 'submission_id' => $submissionId]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }
}
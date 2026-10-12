<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\SubmissionLockRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use Throwable;

final class TaskLockService
{
    private static array $sweepRan = [];

    public function __construct(
        private readonly SubmissionLockRepository          $locks   = new SubmissionLockRepository(),
        private readonly SubmissionStatusHistoryRepository $history = new SubmissionStatusHistoryRepository(),
        private readonly NotificationService               $notify  = new NotificationService(),
        private readonly AuditService                      $audit   = new AuditService(),
    ) {}

    // ----------------------------------------------------------------
    // CLAIM
    // ----------------------------------------------------------------

    public function claim(int $submissionId, array $admin): array
    {
        if (($admin['status'] ?? '') !== STATUS_ACTIVE) {
            return ['ok' => false, 'error' => 'ADMIN_INACTIVE'];
        }
        $adminId = (int) $admin['id'];

        $limit = (int) ($admin['max_concurrent_tasks'] ?? 0);
        if ($limit > 0 && $this->locks->countActiveClaimsForAdmin($adminId) >= $limit) {
            return ['ok' => false, 'error' => 'MAX_CONCURRENT_REACHED', 'limit' => $limit];
        }

        try {
            return Database::transaction(function () use ($submissionId, $adminId): array {
                // 1. Exclusive lock
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) return ['ok' => false, 'error' => 'NOT_FOUND'];

                if ($row['status'] !== 'pending') {
                    $assignedName = null;
                    $assignedId   = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;
                    if ($assignedId) {
                        $assignedName = (string) (Database::selectOne(
                            'SELECT full_name FROM admins WHERE id = :id',
                            [':id' => $assignedId]
                        )['full_name'] ?? null);
                    }
                    return [
                        'ok'                  => false,
                        'error'               => 'ALREADY_CLAIMED',
                        'assigned_admin_id'   => $assignedId,
                        'assigned_admin_name' => $assignedName,
                        'status'              => $row['status'],
                    ];
                }

                // 2. Conditional UPDATE
                $won = $this->locks->conditionalClaim($submissionId, $adminId);
                if (!$won) return ['ok' => false, 'error' => 'ALREADY_CLAIMED'];

                // 3. Read deadline back
                $claimed = Database::selectOne(
                    'SELECT deadline_at FROM submissions WHERE id = :id',
                    [':id' => $submissionId]
                );

                // 4. Status history
                $this->history->record(
                    $submissionId, 'pending', 'processing', 'admin', $adminId,
                    'Task claimed by admin #' . $adminId
                );

                // 5. Audit
                $this->audit->log(
                    'TASK_CLAIMED', 'submission', $submissionId,
                    'Admin claimed task',
                    newValues: [
                        'assigned_admin_id' => $adminId,
                        'deadline_at'       => $claimed['deadline_at'] ?? null,
                    ],
                    userType: 'admin', userId: $adminId
                );

                // 6. Notifications
                $this->notify->notifyAdmin(
                    $adminId,
                    'Task claimed',
                    'You claimed submission ' . $row['tracking_id'] . '. Complete it before the deadline.',
                    'task',
                    '/admin/service?id=' . (int) $row['service_id']
                );
                $this->notify->notifyClient(
                    (int) $row['user_id'],
                    'Your submission is being processed',
                    'Submission ' . $row['tracking_id'] . ' is now being processed by our team.',
                    'submission',
                    '/client/submission-view?id=' . $submissionId
                );

                $deadlineTs = $claimed['deadline_at'] ? strtotime($claimed['deadline_at'] . ' UTC') : null;
                $remaining  = $deadlineTs ? max(0, $deadlineTs - time()) : null;

                return [
                    'ok'                => true,
                    'deadline'          => $claimed['deadline_at'] ?? null,
                    'seconds_remaining' => $remaining,
                    'service_id'        => (int) $row['service_id'],
                ];
            });
        } catch (Throwable $e) {
            Logger::error('Task claim failed', [
                'error'         => $e->getMessage(),
                'submission_id' => $submissionId,
                'admin_id'      => $adminId,
            ]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // EXPLICIT RELEASE
    // ----------------------------------------------------------------

    public function release(int $submissionId, array $admin, ?string $reason = null): array
    {
        $adminId = (int) $admin['id'];

        try {
            return Database::transaction(function () use ($submissionId, $adminId, $reason): array {
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) return ['ok' => false, 'error' => 'NOT_FOUND'];

                if ((int) $row['assigned_admin_id'] !== $adminId || $row['status'] !== 'processing') {
                    return ['ok' => false, 'error' => 'NOT_YOUR_TASK'];
                }

                $released = $this->locks->releaseByAdmin($submissionId, $adminId);
                if (!$released) return ['ok' => false, 'error' => 'RELEASE_FAILED'];

                $this->history->record(
                    $submissionId, 'processing', 'pending', 'admin', $adminId,
                    $reason ?: 'Admin released the task'
                );
                $this->audit->log(
                    'TASK_RELEASED', 'submission', $submissionId,
                    'Admin released the task',
                    userType: 'admin', userId: $adminId
                );
                $this->notify->notifyClient(
                    (int) $row['user_id'],
                    'Submission returned to queue',
                    'Submission ' . $row['tracking_id'] . ' has been returned to the queue.',
                    'submission',
                    '/client/submission-view?id=' . $submissionId
                );
                return ['ok' => true];
            });
        } catch (Throwable $e) {
            Logger::error('Task release failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    public function releaseAllForAdmin(int $adminId, string $reason = 'Admin deactivated'): int
    {
        try {
            return Database::transaction(function () use ($adminId, $reason): int {
                $ids = $this->locks->releaseAllForAdmin($adminId);
                foreach ($ids as $sid) {
                    $this->history->record(
                        (int) $sid, 'processing', 'pending', 'system', null, $reason
                    );
                    $this->audit->log(
                        'TASK_RELEASED_SYSTEM', 'submission', (int) $sid, $reason
                    );
                }
                return count($ids);
            });
        } catch (Throwable $e) {
            Logger::error('Release all for admin failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

        // ----------------------------------------------------------------
    // SUPER ADMIN RELEASE
    // ----------------------------------------------------------------

   
    public function releaseBySuperAdmin(int $submissionId, array $superAdmin, string $reason = ''): array
    {
        $superAdminId = (int) ($superAdmin['id'] ?? 0);
        if ($superAdminId <= 0) {
            return ['ok' => false, 'error' => 'INVALID_ACTOR'];
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Released by Super Admin';
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        try {
            return Database::transaction(function () use ($submissionId, $superAdminId, $reason): array {
                $row = $this->locks->lockForUpdate($submissionId);
                if (!$row) {
                    return ['ok' => false, 'error' => 'NOT_FOUND'];
                }

                // Only held states can be released. Terminal states (completed,
                // cancelled) and pending are refused.
                if (!in_array($row['status'], ['processing', 'error_detected'], true)) {
                    return ['ok' => false, 'error' => 'NOT_RELEASABLE'];
                }

                $previousStatus  = (string) $row['status'];
                $previousAdminId = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;

                // Conditional update so nothing else can sneak in between
                // the lock read and the write.
                $affected = Database::execute(
                    'UPDATE submissions
                        SET status            = "pending",
                            assigned_admin_id = NULL,
                            claimed_at        = NULL,
                            deadline_at       = NULL,
                            error_remarks     = NULL,
                            release_count     = release_count + 1
                      WHERE id = :id
                        AND status IN ("processing","error_detected")',
                    [':id' => $submissionId]
                );

                if ($affected !== 1) {
                    return ['ok' => false, 'error' => 'RELEASE_FAILED'];
                }

                // Timeline
                $this->history->record(
                    $submissionId,
                    $previousStatus,
                    'pending',
                    'super_admin',
                    $superAdminId,
                    $reason
                );

                // Audit
                $this->audit->log(
                    'TASK_RELEASED_BY_SUPERADMIN',
                    'submission',
                    $submissionId,
                    'Super Admin released task ' . $row['tracking_id'] . ': ' . $reason,
                    oldValues: [
                        'status'            => $previousStatus,
                        'assigned_admin_id' => $previousAdminId,
                        'deadline_at'       => $row['deadline_at'],
                    ],
                    newValues: [
                        'status'            => 'pending',
                        'assigned_admin_id' => null,
                        'release_count'     => ((int) $row['release_count']) + 1,
                    ],
                    userType: 'super_admin',
                    userId: $superAdminId
                );

                // Notify the previous holder (if any)
                if ($previousAdminId) {
                    $this->notify->notifyAdmin(
                        $previousAdminId,
                        'Task released by Super Admin',
                        'Submission ' . $row['tracking_id'] . ' was released back to the pool. Reason: ' . $reason,
                        'task',
                        '/admin/service?id=' . (int) $row['service_id']
                    );
                }

                // Broadcast to all active admins so the pool is discoverable
                $this->notify->notifyAllAdmins(
                    'Task available',
                    'Submission ' . $row['tracking_id'] . ' is available for claiming.',
                    'task',
                    '/admin/service?id=' . (int) $row['service_id']
                );

                // Notify the client only when the previous status was `processing`.
                // If the task was already in `error_detected`, the client was
                // told about the error and would be confused by a second message.
                if ($previousStatus === 'processing') {
                    $this->notify->notifyClient(
                        (int) $row['user_id'],
                        'Submission returned to queue',
                        'Submission ' . $row['tracking_id'] . ' has been returned to the queue for processing.',
                        'submission',
                        '/client/submission-view?id=' . $submissionId
                    );
                }

                return [
                    'ok'                => true,
                    'previous_admin_id' => $previousAdminId,
                    'previous_status'   => $previousStatus,
                ];
            });
        } catch (Throwable $e) {
            Logger::error('Super Admin release failed', [
                'error'         => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['ok' => false, 'error' => 'SERVER_ERROR'];
        }
    }

    // ----------------------------------------------------------------
    // EXPIRY SWEEP
    // ----------------------------------------------------------------

    public function sweepExpired(int $limit = 200, bool $force = false): array
    {
        if (!$force && !empty(self::$sweepRan['sweep'])) {
            return ['released' => 0, 'ids' => []];
        }
        self::$sweepRan['sweep'] = true;

        $expired = $this->locks->findExpired($limit);
        if (empty($expired)) return ['released' => 0, 'ids' => []];

        $releasedIds = [];

        foreach ($expired as $row) {
            try {
                $won = Database::transaction(function () use ($row): bool {
                    $locked = $this->locks->lockForUpdate((int) $row['id']);
                    if (!$locked) return false;
                    if ($locked['status'] !== 'processing') return false;

                    $released = $this->locks->conditionalRelease((int) $row['id'], 'expired');
                    if (!$released) return false;

                    $previousAdminId = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;

                    $this->history->record(
                        (int) $row['id'], 'processing', 'pending', 'system', null,
                        'Deadline expired — returned to pool'
                    );
                    $this->audit->log(
                        'TASK_EXPIRED', 'submission', (int) $row['id'],
                        'Deadline expired; task returned to pool',
                        oldValues: [
                            'assigned_admin_id' => $previousAdminId,
                            'deadline_at'       => $row['deadline_at'],
                        ]
                    );

                    if ($previousAdminId) {
                        $this->notify->notifyAdmin(
                            $previousAdminId,
                            'Task expired',
                            'Submission ' . $row['tracking_id'] . ' was returned to the pool.',
                            'task',
                            '/admin/service?id=' . (int) $locked['service_id']
                        );
                    }
                    $this->notify->notifyAllAdmins(
                        'Task available',
                        'Submission ' . $row['tracking_id'] . ' is available for claiming.',
                        'task',
                        '/admin/service?id=' . (int) $locked['service_id']
                    );

                    return true;
                });

                if ($won) $releasedIds[] = (int) $row['id'];
            } catch (Throwable $e) {
                Logger::warning('Expiry release failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }

        return ['released' => count($releasedIds), 'ids' => $releasedIds];
    }

    public function resetSweepGuard(): void
    {
        self::$sweepRan = [];
    }

    public function warnApproachingDeadlines(int $withinMinutes = 60): int
    {
        $rows = $this->locks->findApproachingDeadline($withinMinutes);
        $sent = 0;
        foreach ($rows as $row) {
            $adminId = $row['assigned_admin_id'] ? (int) $row['assigned_admin_id'] : null;
            if (!$adminId) continue;

            $serviceId = (int) (Database::selectOne(
                'SELECT service_id FROM submissions WHERE id = :id',
                [':id' => (int) $row['id']]
            )['service_id'] ?? 0);

            $this->notify->notifyAdmin(
                $adminId,
                'Deadline approaching',
                'Submission ' . $row['tracking_id'] . ' must be completed before ' . $row['deadline_at'] . ' UTC.',
                'task',
                '/admin/service?id=' . $serviceId
            );
            $sent++;
        }
        return $sent;
    }
}
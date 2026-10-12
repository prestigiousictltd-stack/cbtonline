<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\DocumentRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Core\Csrf;

$superAdminUser = Auth::requireSuperAdmin();

// ------------------------------------------------------------
// Super Admin action: release a held task back to the pool
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'release_task') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/submission-view?id=' . $id);

    $reason = trim((string) ($_POST['release_reason'] ?? ''));
    $result = (new App\Services\TaskLockService())->releaseBySuperAdmin($id, $superAdminUser, $reason);

    if (!empty($result['ok'])) {
        $msg = $result['previous_admin_id']
            ? 'Task released. The previous holder has been notified.'
            : 'Task released back to the pool.';
        flash('success', $msg);
    } else {
        $reason = match ($result['error'] ?? '') {
            'NOT_RELEASABLE' => 'This task cannot be released in its current state.',
            'NOT_FOUND'      => 'Submission not found.',
            'RELEASE_FAILED' => 'The task was already released by someone else.',
            default          => 'Could not release the task. Please try again.',
        };
        flash('danger', $reason);
    }
    redirect('/superadmin/submission-view?id=' . $id);
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Submission not found.'); redirect('/superadmin/submissions'); }

$subRepo = new SubmissionRepository();
$sub = $subRepo->findById($id);
if (!$sub) { flash('danger', 'Submission not found.'); redirect('/superadmin/submissions'); }

$fields    = (new SubmissionFieldValueRepository())->listForSubmission($id);
$history   = (new SubmissionStatusHistoryRepository())->listForSubmission($id);
$documents = (new DocumentRepository())->listForSubmission($id);
$feedback  = (new FeedbackRepository())->listForSubmission($id);

$pageTitle = 'Submission ' . $sub['tracking_id'];
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="small text-muted mb-1">
            <a class="text-decoration-none" href="<?= e(url('/superadmin/submissions')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Submissions
            </a>
        </div>
        <h1 class="h4 mb-1"><?= e($sub['service_name_snapshot']) ?></h1>
        <div class="small text-muted">
            Tracking ID <code><?= e($sub['tracking_id']) ?></code>
            · Submitted <?= e(fmt_dt($sub['created_at'])) ?>
            · <span class="badge bg-<?= e(status_badge($sub['status'])) ?>">
                <?= e(ucwords(str_replace('_', ' ', $sub['status']))) ?>
              </span>
        </div>
    </div>
</div>

<?php if (in_array($sub['status'], ['processing', 'error_detected'], true)): ?>
    <!-- ================= Super Admin control ================= -->
    <div class="card border-0 shadow-sm mb-4 admin-control-card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-start gap-3">
                <div class="admin-control-card__icon">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <div>
                    <div class="fw-semibold">Super Admin control</div>
                    <div class="small text-muted">
                        This task is currently held by
                        <strong><?= e($sub['assigned_admin_name'] ?? 'an admin') ?></strong>
                        <?php if ($sub['status'] === 'error_detected'): ?>
                            and is paused pending client correction.
                        <?php else: ?>
                            and has a live deadline.
                        <?php endif; ?>
                        Releasing it returns it to the pool so any admin can claim it.
                    </div>
                </div>
            </div>
            <button class="btn release-task-btn"
        data-bs-toggle="modal" data-bs-target="#releaseTaskModal">
    <i class="bi bi-arrow-counterclockwise me-1"></i> Release task
</button>
        </div>
    </div>

    <!-- Release confirmation modal -->
    <div class="modal fade" id="releaseTaskModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" class="modal-content" novalidate>
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="release_task">
                <div class="modal-header">
                    <h5 class="modal-title">Release task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">
                        Releasing <code><?= e($sub['tracking_id']) ?></code> will:
                    </p>
                    <ul class="small text-muted">
                        <li>Return the task to the general pool</li>
                        <li>Notify the current holder (<?= e($sub['assigned_admin_name'] ?? 'the admin') ?>)</li>
                        <li>Notify all active admins that the task is available</li>
                        <li>Reset the deadline — a fresh one applies when it is re-claimed</li>
                    </ul>

                    <label class="form-label">Reason (optional)</label>
                    <textarea name="release_reason" class="form-control" rows="2" maxlength="500"
                              placeholder="e.g. Admin unresponsive; reassignment needed."></textarea>
                    <div class="form-text">Recorded in the audit log and shown to the previous admin.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-warning"
                            onclick="return confirm('Release this task back to the pool?');">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Release task
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Summary</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6"><div class="text-muted small">Client</div>
                        <div class="fw-semibold"><?= e($sub['client_name']) ?></div>
                        <div class="small text-muted"><?= e($sub['client_email']) ?></div>
                    </div>
                    <div class="col-sm-6"><div class="text-muted small">Assigned admin</div>
                        <div><?= e($sub['assigned_admin_name'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-6"><div class="text-muted small">Amount charged</div>
                        <div class="fw-semibold"><?= e(money($sub['price_charged'])) ?></div>
                    </div>
                    <div class="col-sm-6"><div class="text-muted small">Commission</div>
                        <div class="fw-semibold text-success"><?= e(money($sub['admin_commission_amount'])) ?></div>
                    </div>
                    <?php if ($sub['completed_at']): ?>
                        <div class="col-sm-6"><div class="text-muted small">Completed</div>
                            <div><?= e(fmt_dt($sub['completed_at'])) ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($sub['release_count'] > 0): ?>
                        <div class="col-sm-6"><div class="text-muted small">Release count</div>
                            <div><?= (int) $sub['release_count'] ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Submitted information</strong></div>
            <div class="card-body">
                <?php if (empty($fields)): ?>
                    <div class="text-muted">—</div>
                <?php else: ?>
                    <dl class="row mb-0">
                        <?php foreach ($fields as $f): ?>
                            <dt class="col-sm-4 text-muted small"><?= e($f['field_label_snapshot']) ?></dt>
                            <dd class="col-sm-8"><?= nl2br(e($f['value_text'] ?? '')) ?: '—' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($feedback)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Feedback</strong></div>
                <div class="card-body">
                    <?php foreach ($feedback as $fb): ?>
                        <div class="mb-3">
                            <div class="small text-muted">
                                <?= e(ucwords(str_replace('_', ' ', $fb['feedback_type']))) ?>
                                · <?= e(fmt_dt($fb['created_at'])) ?>
                            </div>
                            <div><?= nl2br(e($fb['remarks'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($documents)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Documents</strong></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($documents as $doc): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="small"><?= e($doc['original_name']) ?> (<?= number_format($doc['file_size'] / 1024, 1) ?> KB)</span>
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('/download-document?id=' . (int) $doc['id'])) ?>">
                                <i class="bi bi-download"></i>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Timeline</strong></div>
            <div class="card-body">
                <?php foreach ($history as $h): ?>
                    <div class="mb-3">
                        <div class="fw-semibold small">
                            <?= e(ucwords(str_replace('_', ' ', $h['to_status']))) ?>
                            <span class="text-muted fw-normal">· <?= e($h['changed_by_type']) ?></span>
                        </div>
                        <div class="small text-muted"><?= e(fmt_dt($h['created_at'])) ?></div>
                        <?php if (!empty($h['reason'])): ?>
                            <div class="small"><?= e($h['reason']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* ---------- Super Admin control card ---------- */
    .admin-control-card {
        border-left: 4px solid #f59e0b !important;
        background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, #ffffff 100%);
    }
    .admin-control-card__icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        background: rgba(245, 158, 11, 0.15);
        color: #d97706;
        flex-shrink: 0;
    }

    /* ---------- Release task button ---------- */
    .release-task-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 22px;
        font-weight: 700;
        font-size: .95rem;
        color: #ffffff;
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        border: 1px solid #b45309;
        border-radius: 12px;
        box-shadow:
            0 6px 16px rgba(217, 119, 6, 0.28),
            inset 0 1px 0 rgba(255, 255, 255, 0.20);
        transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
        animation: release-pulse 2.4s ease-in-out infinite;
    }
    .release-task-btn i {
        font-size: 1.1rem;
    }
    .release-task-btn:hover,
    .release-task-btn:focus {
        color: #ffffff;
        background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);
        transform: translateY(-2px);
        box-shadow:
            0 12px 24px rgba(217, 119, 6, 0.42),
            inset 0 1px 0 rgba(255, 255, 255, 0.25);
        animation-play-state: paused;
    }
    .release-task-btn:active {
        transform: translateY(0);
        box-shadow: 0 4px 10px rgba(217, 119, 6, 0.35);
    }
    .release-task-btn:focus-visible {
        outline: 3px solid rgba(251, 146, 60, 0.45);
        outline-offset: 2px;
    }

    @keyframes release-pulse {
        0%, 100% {
            box-shadow:
                0 6px 16px rgba(217, 119, 6, 0.28),
                inset 0 1px 0 rgba(255, 255, 255, 0.20),
                0 0 0 0 rgba(245, 158, 11, 0.45);
        }
        50% {
            box-shadow:
                0 6px 16px rgba(217, 119, 6, 0.28),
                inset 0 1px 0 rgba(255, 255, 255, 0.20),
                0 0 0 10px rgba(245, 158, 11, 0.0);
        }
    }

    /* Honour reduced-motion */
    @media (prefers-reduced-motion: reduce) {
        .release-task-btn { animation: none; }
    }
</style>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
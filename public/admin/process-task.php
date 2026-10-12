<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\FeedbackRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Core\Database;

$adminUser = Auth::requireAdmin();

// Lazy sweep so any stale locked task is released before this page renders
(new App\Services\TaskLockService())->sweepExpired();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('warning', 'No submission selected.');
    redirect('/admin/dashboard');
}

$subRepo = new SubmissionRepository();
$sub = $subRepo->findById($id);
if (!$sub) {
    flash('danger', 'Submission not found.');
    redirect('/admin/dashboard');
}

$isOwner    = (int) $sub['assigned_admin_id'] === (int) $adminUser['id'];
$isSuperAdm = Auth::superAdminCheck();

if (!$isOwner && !$isSuperAdm) {
    if ($sub['status'] === 'pending') {
        flash('warning', 'This task is not yet claimed. Claim it from the queue first.');
        redirect('/admin/service?id=' . (int) $sub['service_id']);
    }
    flash('danger', 'This task is being processed by another admin.');
    redirect('/admin/service?id=' . (int) $sub['service_id']);
}
if ($sub['status'] !== 'processing') {
    flash('info', 'This task is no longer in processing.');
    redirect('/admin/service?id=' . (int) $sub['service_id']);
}

$fieldValues = (new SubmissionFieldValueRepository())->listForSubmission($id);
$timeline    = (new SubmissionStatusHistoryRepository())->listForSubmission($id);
$priorFb     = (new FeedbackRepository())->listForSubmission($id);

$deadlineTs = $sub['deadline_at'] ? strtotime($sub['deadline_at'] . ' UTC') : null;
$remaining  = $deadlineTs !== null ? max(0, $deadlineTs - time()) : null;

$adminSidebarServices = (new App\Repositories\ServiceRepository())->allActive();

$pageTitle = 'Process ' . $sub['tracking_id'];
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="text-muted small mb-1">
            <a class="text-decoration-none" href="<?= e(url('/admin/service?id=' . (int) $sub['service_id'])) ?>">
                <i class="bi bi-arrow-left me-1"></i> <?= e($sub['service_name_snapshot']) ?> queue
            </a>
        </div>
        <h1 class="h4 mb-1">Process task</h1>
        <div class="text-muted small">
            Tracking ID <code><?= e($sub['tracking_id']) ?></code>
            · Claimed <?= e(fmt_dt($sub['claimed_at'])) ?>
        </div>
    </div>
    <div class="text-end mt-2 mt-md-0">
        <div class="text-muted small text-uppercase">Time remaining</div>
        <div id="taskTimer"
             class="countdown <?= ($remaining !== null && $remaining < 3600) ? 'urgent' : '' ?>"
             style="font-size:1.5rem"
             data-id="<?= (int) $sub['id'] ?>"
             data-server-now="<?= time() ?>"
             data-deadline="<?= (int) ($deadlineTs ?? 0) ?>"
             data-timer-url="<?= e(url('/api/task-timer')) ?>">
            <?= e($remaining !== null ? human_countdown($remaining) : '—') ?>
        </div>
        <div class="text-muted small">
            Deadline <?= e(fmt_dt($sub['deadline_at'])) ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left column: submission -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Submission information</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Tracking ID</div>
                        <div><code><?= e($sub['tracking_id']) ?></code></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Service</div>
                        <div class="fw-semibold"><?= e($sub['service_name_snapshot']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Client</div>
                        <div class="fw-semibold"><?= e($sub['client_name']) ?></div>
                        <div class="small text-muted"><?= e($sub['client_email']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Client phone</div>
                        <div><?= e($sub['client_phone']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Submitted</div>
                        <div><?= e(fmt_dt($sub['created_at'])) ?></div>
                    </div>
                    
                    <div class="col-sm-6">
                        <div class="text-muted small">Your commission on completion</div>
                        <div class="fw-semibold text-success"><?= e(money($sub['admin_commission_amount'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Submitted information</strong></div>
            <div class="card-body">
                <?php if (empty($fieldValues)): ?>
                    <div class="text-muted">No additional fields were submitted.</div>
                <?php else: ?>
                    <dl class="row mb-0">
                        <?php foreach ($fieldValues as $fv): ?>
                            <dt class="col-sm-4 text-muted small"><?= e($fv['field_label_snapshot']) ?></dt>
                            <dd class="col-sm-8"><?= nl2br(e($fv['value_text'] ?? '')) ?: '<span class="text-muted">—</span>' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($priorFb)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Prior feedback</strong></div>
                <div class="card-body">
                    <?php foreach ($priorFb as $fb): ?>
                        <div class="mb-3">
                            <div class="small text-muted mb-1">
                                <?= e(ucwords(str_replace('_',' ', $fb['feedback_type']))) ?>
                                · <?= e(fmt_dt($fb['created_at'])) ?>
                            </div>
                            <div><?= nl2br(e($fb['remarks'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right column: completion form -->
    <div class="col-lg-5">
        <form method="post" action="<?= e(url('/admin/complete-task')) ?>"
              enctype="multipart/form-data" novalidate id="completionForm">
            <?= Csrf::field() ?>
            <input type="hidden" name="submission_id" value="<?= (int) $sub['id'] ?>">

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><strong>Complete the task</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Written remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="5" required maxlength="5000"
                                  placeholder="Describe the outcome of the request."></textarea>
                        <div class="form-text">Visible to the client on completion.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Result documents</label>
                        <input type="file" name="documents[]" class="form-control" multiple
                               accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text">
                            PDF, JPG, JPEG or PNG. Each file up to
                            <?= (int) round(UPLOAD_MAX_BYTES / 1024 / 1024, 1) ?> MB.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-brand w-100"
                            onclick="return confirm('Complete this task? The client will be notified immediately.');">
                        <i class="bi bi-check2-circle me-1"></i> COMPLETE TASK
                    </button>
                </div>
            </div>
        </form>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Report a problem</strong></div>
            <div class="card-body">
                <p class="text-muted small">
                    Use this when the client's submission is missing information or cannot be processed.
                </p>
                <form method="post" action="<?= e(url('/admin/report-error')) ?>" novalidate>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="submission_id" value="<?= (int) $sub['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label">Error description <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="3" required maxlength="2000"
                                  placeholder="Explain what the client needs to fix."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">What should happen next?</label>
                        <select name="disposition" class="form-select">
                            <option value="release">Return to queue (client sees error, admin can retry)</option>
                            <option value="hold">Hold as “Error detected” (client must respond first)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-outline-danger w-100"
                            onclick="return confirm('Report this task as an error?');">
                        <i class="bi bi-exclamation-octagon me-1"></i> REPORT ERROR
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white"><strong>Release task</strong></div>
            <div class="card-body">
                <p class="text-muted small">
                    Released tasks return to the pool and can be claimed by any admin.
                </p>
                <form method="post" action="<?= e(url('/admin/complete-task')) ?>"
                      onsubmit="return confirm('Release this task back to the pool?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="submission_id" value="<?= (int) $sub['id'] ?>">
                    <input type="hidden" name="action" value="release">
                    <button class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Release task
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Timeline -->
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white"><strong>Timeline</strong></div>
    <div class="card-body">
        <ol class="timeline mb-0">
            <?php foreach ($timeline as $h): ?>
                <li class="timeline-item">
                    <div class="timeline-dot bg-<?= e(status_badge($h['to_status'])) ?>"></div>
                    <div class="timeline-body">
                        <div class="fw-semibold small">
                            <?= e(ucwords(str_replace('_', ' ', $h['to_status']))) ?>
                            <span class="text-muted fw-normal">
                                · <?= e($h['changed_by_type']) ?>
                            </span>
                        </div>
                        <div class="text-muted small"><?= e(fmt_dt($h['created_at'])) ?></div>
                        <?php if (!empty($h['reason'])): ?>
                            <div class="small mt-1"><?= e($h['reason']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>

<style>
    .timeline { list-style: none; padding-left: 0; }
    .timeline-item { position: relative; padding-left: 24px; padding-bottom: 18px; }
    .timeline-item::before {
        content: ''; position: absolute; left: 6px; top: 18px; bottom: 0;
        width: 2px; background: #e2e8f0;
    }
    .timeline-item:last-child::before { display: none; }
    .timeline-dot {
        position: absolute; left: 0; top: 4px;
        width: 14px; height: 14px; border-radius: 50%;
        border: 2px solid #fff; box-shadow: 0 0 0 2px rgba(15,23,42,.06);
    }
</style>

<script src="<?= e(asset('js/task-timer.js')) ?>"></script>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
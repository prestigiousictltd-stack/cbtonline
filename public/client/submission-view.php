<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\DocumentRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Services\ClientCorrectionService;
use App\Support\DynamicForm;
use App\Core\Database;

$clientUser = Auth::requireClient();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Submission not found.');
    redirect('/client/submissions');
}

$subRepo    = new SubmissionRepository();
$submission = $subRepo->findById($id);

if (!$submission || (int) $submission['user_id'] !== (int) $clientUser['id']) {
    (new App\Services\AuditService())->log(
        'UNAUTHORIZED_SUBMISSION_VIEW', 'submission', $id,
        'Client attempted to view another submission',
        userType: 'client', userId: (int) $clientUser['id']
    );
    http_response_code(403);
    flash('danger', 'You cannot view that submission.');
    redirect('/client/submissions');
}

$fieldValueRepo = new SubmissionFieldValueRepository();
$historyRepo    = new SubmissionStatusHistoryRepository();
$docRepo        = new DocumentRepository();

$errors          = [];
$oldFieldValues  = [];

// ------------------------------------------------------------
// POST — client submits a correction
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_correction') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/client/submission-view?id=' . $id);

    if ($submission['status'] !== SUB_ERROR) {
        flash('warning', 'This submission is not open for correction.');
        redirect('/client/submission-view?id=' . $id);
    }

    $fieldsRaw      = is_array($_POST['fields'] ?? null) ? (array) $_POST['fields'] : [];
    $oldFieldValues = $fieldsRaw;

    $result = (new ClientCorrectionService())->respondToError(
        (int) $clientUser['id'],
        $id,
        $fieldsRaw
    );

    if (!empty($result['ok'])) {
        flash('success', 'Your corrections were submitted successfully. The processing team has been notified.');
        redirect('/client/submission-view?id=' . $id);
    }

    if (!empty($result['errors'])) {
        $errors = $result['errors'];
    } else {
        $msg = match ($result['error'] ?? '') {
            'NOT_YOURS'     => 'You cannot correct this submission.',
            'NOT_IN_ERROR'  => 'This submission is no longer open for correction.',
            'NOT_FOUND'     => 'Submission not found.',
            default         => 'Could not submit your correction. Please try again.',
        };
        $errors['general'] = $msg;
    }
}

// ------------------------------------------------------------
// Fresh read after any POST (status may have changed)
// ------------------------------------------------------------
$submission = $subRepo->findById($id) ?? $submission;

$fieldValues = $fieldValueRepo->listForSubmission($id);
$timeline    = $historyRepo->listForSubmission($id);
$documents   = $docRepo->listForSubmission($id);
$feedback    = Database::selectAll(
    'SELECT * FROM feedback WHERE submission_id = :s AND is_client_visible = 1 ORDER BY id ASC',
    [':s' => $id]
);

// Latest admin error remarks (if any)
$errorFeedback = null;
foreach (array_reverse($feedback) as $fb) {
    if ($fb['feedback_type'] === 'error_report') {
        $errorFeedback = $fb;
        break;
    }
}

// Build pre-fill map for the dynamic form (keyed by field_key)
$prefill = [];
foreach ($fieldValues as $fv) {
    $prefill[$fv['field_key_snapshot']] = $fv['value_text'] ?? '';
}

// If the POST failed validation, keep the client's attempted values instead
if (!empty($oldFieldValues)) {
    $prefill = array_merge($prefill, $oldFieldValues);
}

$isNew          = !empty($_GET['new']);
$canCorrect     = $submission['status'] === SUB_ERROR;
$adminStillHolds = $canCorrect && !empty($submission['assigned_admin_id']);

$pageTitle = 'Submission ' . $submission['tracking_id'];
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="text-muted small mb-1">
            <a href="<?= e(url('/client/submissions')) ?>" class="text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> Back to submissions
            </a>
        </div>
        <h1 class="h4 mb-1">
            <?= e($submission['service_name_snapshot']) ?>
            <span class="badge bg-<?= e(status_badge($submission['status'])) ?> fs-6 align-middle ms-2">
                <?= e(ucwords(str_replace('_', ' ', $submission['status']))) ?>
            </span>
        </h1>
        <div class="text-muted small">
            Tracking ID: <code><?= e($submission['tracking_id']) ?></code>
            · Submitted <?= e(fmt_dt($submission['created_at'])) ?>
        </div>
    </div>
    <a href="<?= e(url('/client/services')) ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="bi bi-plus-circle me-1"></i> New submission
    </a>
</div>

<?php if ($isNew): ?>
    <div class="alert alert-success">
        <strong>Your request has been received.</strong>
        Tracking ID: <code><?= e($submission['tracking_id']) ?></code>.
        Save this for reference — you can track progress any time.
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($canCorrect): ?>
    <!-- ================= Action required: correction form ================= -->
    <div class="card border-0 shadow-sm mb-4 action-required-card">
        <div class="action-required-card__header">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <div>
                <div class="action-required-card__title">Action required</div>
                <div class="action-required-card__subtitle">
                    The processing team found an issue with your submission. Please correct the details below.
                </div>
            </div>
        </div>

        <?php if ($errorFeedback): ?>
            <div class="action-required-card__remarks">
                <div class="text-muted small mb-1">Message from the processing team:</div>
                <div><?= nl2br(e($errorFeedback['remarks'])) ?></div>
                <div class="text-muted small mt-2"><?= e(fmt_dt($errorFeedback['created_at'])) ?></div>
            </div>
        <?php elseif (!empty($submission['error_remarks'])): ?>
            <div class="action-required-card__remarks">
                <div class="text-muted small mb-1">Message from the processing team:</div>
                <div><?= nl2br(e($submission['error_remarks'])) ?></div>
            </div>
        <?php endif; ?>

        <form method="post" class="action-required-card__form" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="submit_correction">

            <?php
            $dynamicForm = DynamicForm::forService((int) $submission['service_id']);
            echo $dynamicForm->render($prefill, $errors);
            ?>

            <div class="alert alert-light small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <?php if ($adminStillHolds): ?>
                    These corrections go straight back to the admin who is processing your task.
                <?php else: ?>
                    Your corrections will return the task to the queue for the next available admin.
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2">
                <a href="<?= e(url('/client/submissions')) ?>" class="btn btn-outline-secondary">
                    Cancel
                </a>
                <button type="submit" class="btn btn-brand flex-grow-1"
                        onclick="return confirm('Submit the corrected details?');">
                    <i class="bi bi-send me-1"></i> Submit correction
                </button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- Summary -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Submission details</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="text-muted small">Tracking ID</div>
                        <div><code><?= e($submission['tracking_id']) ?></code></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Service</div>
                        <div class="fw-semibold"><?= e($submission['service_name_snapshot']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Amount charged</div>
                        <div class="fw-semibold"><?= e(money($submission['price_charged'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Processing time</div>
                        <div class="fw-semibold"><?= e(human_duration((int) $submission['processing_minutes_snapshot'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Submitted</div>
                        <div><?= e(fmt_dt($submission['created_at'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">
                            <?= $submission['completed_at'] ? 'Completed' : 'Last update' ?>
                        </div>
                        <div><?= e(fmt_dt($submission['completed_at'] ?: $submission['updated_at'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submitted information (current values) -->
        <?php if (!empty($fieldValues)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Information on file</strong>
                    <?php if ($canCorrect): ?>
                        <span class="badge bg-warning text-dark small">Being corrected</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <?php foreach ($fieldValues as $fv): ?>
                            <dt class="col-sm-4 text-muted small"><?= e($fv['field_label_snapshot']) ?></dt>
                            <dd class="col-sm-8"><?= nl2br(e($fv['value_text'] ?? '')) ?: '<span class="text-muted">—</span>' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        <?php endif; ?>

        <!-- Feedback history -->
        <?php if (!empty($feedback)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex align-items-center gap-2">
                    <i class="bi bi-chat-left-text"></i> <strong>Feedback from processing team</strong>
                </div>
                <div class="card-body">
                    <?php foreach ($feedback as $fb):
                        $typeLabel = match ($fb['feedback_type']) {
                            'completion'   => ['label' => 'Completed',   'badge' => 'success'],
                            'error_report' => ['label' => 'Error',       'badge' => 'danger'],
                            default        => ['label' => 'Update',      'badge' => 'info'],
                        };
                    ?>
                        <div class="mb-3 pb-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-<?= e($typeLabel['badge']) ?>"><?= e($typeLabel['label']) ?></span>
                                <small class="text-muted"><?= e(fmt_dt($fb['created_at'])) ?></small>
                            </div>
                            <div><?= nl2br(e($fb['remarks'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Documents -->
        <?php if (!empty($documents)): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-arrow-down"></i> <strong>Documents</strong>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($documents as $doc): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi <?= str_starts_with($doc['mime_type'], 'image/') ? 'bi-file-earmark-image' : 'bi-file-earmark-pdf' ?> me-2"></i>
                                <?= e($doc['original_name']) ?>
                                <span class="text-muted small ms-2">(<?= number_format($doc['file_size'] / 1024, 1) ?> KB)</span>
                            </div>
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('/download-document?id=' . (int) $doc['id'])) ?>">
                                <i class="bi bi-download me-1"></i> Download
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Timeline -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Status timeline</strong></div>
            <div class="card-body">
                <ol class="timeline mb-0">
                    <?php foreach ($timeline as $h): ?>
                        <li class="timeline-item">
                            <div class="timeline-dot bg-<?= e(status_badge($h['to_status'])) ?>"></div>
                            <div class="timeline-body">
                                <div class="fw-semibold small">
                                    <?= e(ucwords(str_replace('_', ' ', $h['to_status']))) ?>
                                    <span class="text-muted fw-normal">· <?= e($h['changed_by_type']) ?></span>
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

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body small text-muted">
                <div class="fw-semibold text-dark mb-1"><i class="bi bi-shield-check me-1"></i> Need help?</div>
                Quote your tracking ID in any correspondence:
                <code><?= e($submission['tracking_id']) ?></code>
            </div>
        </div>
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

    /* ================= Action required card ================= */
    .action-required-card {
        border-left: 5px solid #f59e0b !important;
        overflow: hidden;
    }
    .action-required-card__header {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        padding: 20px 22px;
        background: linear-gradient(90deg, rgba(245, 158, 11, 0.10) 0%, rgba(245, 158, 11, 0.02) 100%);
        border-bottom: 1px solid rgba(245, 158, 11, 0.20);
    }
    .action-required-card__header > i {
        font-size: 1.6rem;
        color: #d97706;
        flex-shrink: 0;
        margin-top: 2px;
    }
    .action-required-card__title {
        font-size: 1.05rem;
        font-weight: 700;
        color: #78350f;
        letter-spacing: -0.2px;
    }
    .action-required-card__subtitle {
        color: #92400e;
        font-size: .9rem;
        margin-top: 3px;
    }
    .action-required-card__remarks {
        padding: 16px 22px;
        background: #fffbeb;
        border-bottom: 1px solid rgba(245, 158, 11, 0.15);
        color: #451a03;
        font-size: .95rem;
    }
    .action-required-card__form {
        padding: 22px;
    }
</style>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
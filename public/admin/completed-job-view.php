<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\DocumentRepository;
use App\Repositories\FeedbackRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;
use App\Repositories\SubmissionStatusHistoryRepository;
use App\Services\TaskCompletionService;

$adminUser = Auth::requireAdmin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('warning', 'No submission selected.');
    redirect('/admin/completed-jobs');
}

$subRepo = new SubmissionRepository();
$sub     = $subRepo->findById($id);
if (!$sub) {
    flash('danger', 'Submission not found.');
    redirect('/admin/completed-jobs');
}
if ($sub['status'] !== 'completed') {
    flash('warning', 'This submission is not marked as completed.');
    redirect('/admin/service?id=' . (int) $sub['service_id']);
}
if ((int) $sub['assigned_admin_id'] !== (int) $adminUser['id'] && !Auth::superAdminCheck()) {
    flash('danger', 'You can only view or edit submissions you processed.');
    redirect('/admin/completed-jobs');
}

$fbRepo   = new FeedbackRepository();
$docRepo  = new DocumentRepository();
$histRepo = new SubmissionStatusHistoryRepository();
$fvRepo   = new SubmissionFieldValueRepository();

$errors = [];

// ------------------------------------------------------------
// POST — update the completed submission
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/admin/completed-job-view?id=' . $id);

    $remarks      = (string) ($_POST['remarks'] ?? '');
    $removeDocIds = array_map('intval', (array) ($_POST['remove_documents'] ?? []));
    $notifyClient = !empty($_POST['notify_client']);
    $newFiles     = $_FILES['documents'] ?? [];

    $result = (new TaskCompletionService())->updateCompleted(
        $adminUser, $id, $remarks, $removeDocIds, $newFiles, $notifyClient
    );

    if (!empty($result['ok'])) {
        $parts = [];
        if (!empty($result['remarks_changed'])) $parts[] = 'remarks updated';
        if (!empty($result['docs_removed']))    $parts[] = $result['docs_removed'] . ' document(s) removed';
        if (!empty($result['docs_added']))      $parts[] = $result['docs_added'] . ' document(s) added';
        $msg = $parts ? implode(', ', $parts) : 'no changes';
        flash('success', 'Submission updated (' . $msg . ').');
        redirect('/admin/completed-job-view?id=' . $id);
    }

    if (!empty($result['errors'])) {
        $errors = $result['errors'];
    } else {
        $msg = match ($result['error'] ?? '') {
            'NOT_YOUR_TASK'   => 'You can only edit submissions you processed.',
            'NOT_COMPLETED'   => 'Only completed submissions can be edited here.',
            'NO_FEEDBACK'     => 'No completion feedback exists for this submission.',
            'NOT_FOUND'       => 'Submission not found.',
            default           => 'Could not update the submission. Please try again.',
        };
        $errors = ['general' => $msg];
    }
}

// ------------------------------------------------------------
// Load data for rendering
// ------------------------------------------------------------
$fields    = $fvRepo->listForSubmission($id);
$feedback  = $fbRepo->findLatestForSubmission($id, 'completion');
$documents = $docRepo->listForSubmission($id);
$timeline  = $histRepo->listForSubmission($id);

$currentRemarks = $feedback['remarks'] ?? '';

$adminSidebarServices = (new App\Repositories\ServiceRepository())->allActive();

$pageTitle = 'Completed — ' . $sub['tracking_id'];
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="text-muted small mb-1">
            <a class="text-decoration-none" href="<?= e(url('/admin/completed-jobs')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Completed jobs
            </a>
        </div>
        <h1 class="h4 mb-1">
            <?= e($sub['service_name_snapshot']) ?>
            <span class="badge bg-success ms-2 align-middle">Completed</span>
        </h1>
        <div class="text-muted small">
            Tracking ID <code><?= e($sub['tracking_id']) ?></code>
            · Completed <?= e(fmt_dt($sub['completed_at'])) ?>
        </div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">

    <!-- Left: summary + submitted values + edit form -->
    <div class="col-lg-7">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Submission summary</strong></div>
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
                        <div class="text-muted small">Completed</div>
                        <div><?= e(fmt_dt($sub['completed_at'])) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="text-muted small">Your commission</div>
                        <div class="fw-semibold text-success"><?= e(money($sub['admin_commission_amount'])) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Information submitted by client</strong></div>
            <div class="card-body">
                <?php if (empty($fields)): ?>
                    <div class="text-muted">—</div>
                <?php else: ?>
                    <dl class="row mb-0">
                        <?php foreach ($fields as $fv): ?>
                            <dt class="col-sm-4 text-muted small"><?= e($fv['field_label_snapshot']) ?></dt>
                            <dd class="col-sm-8"><?= nl2br(e($fv['value_text'] ?? '')) ?: '<span class="text-muted">—</span>' ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit form -->
        <form method="post" enctype="multipart/form-data" novalidate>
            <?= Csrf::field() ?>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square text-primary"></i>
                    <strong>Edit result sent to client</strong>
                </div>
                <div class="card-body">

                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Use this form to correct a mistake made when the task was completed. Every change
                        is recorded in the audit log.
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Written remarks <span class="text-danger">*</span></label>
                        <textarea name="remarks" class="form-control" rows="5" required maxlength="5000"><?= e($currentRemarks) ?></textarea>
                        <div class="form-text">Visible to the client on completion.</div>
                    </div>

                    <?php if (!empty($documents)): ?>
                        <div class="mb-3">
                            <label class="form-label">Attached result documents</label>
                            <div class="list-group">
                                <?php foreach ($documents as $doc): ?>
                                    <label class="list-group-item d-flex align-items-center">
                                        <input class="form-check-input me-3" type="checkbox"
                                               name="remove_documents[]" value="<?= (int) $doc['id'] ?>">
                                        <span class="flex-grow-1">
                                            <i class="bi <?= str_starts_with($doc['mime_type'], 'image/') ? 'bi-file-earmark-image' : 'bi-file-earmark-pdf' ?> me-2"></i>
                                            <?= e($doc['original_name']) ?>
                                            <span class="text-muted small ms-1">
                                                (<?= number_format($doc['file_size'] / 1024, 1) ?> KB)
                                            </span>
                                        </span>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= e(url('/download-document?id=' . (int) $doc['id'])) ?>"
                                           target="_blank" rel="noopener"
                                           onclick="event.stopPropagation();">
                                            <i class="bi bi-download"></i>
                                        </a>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Tick a document to remove it from the client's view.</div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Add new result documents</label>
                        <input type="file" name="documents[]" class="form-control" multiple
                               accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text">
                            PDF, JPG, JPEG or PNG. Each file up to
                            <?= (int) round(UPLOAD_MAX_BYTES / 1024 / 1024, 1) ?> MB.
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="notify_client"
                               id="notify_client" value="1" checked>
                        <label class="form-check-label" for="notify_client">
                            Notify the client that their submission was updated
                        </label>
                    </div>

                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/completed-jobs')) ?>">Cancel</a>
                        <button type="submit" class="btn btn-brand"
                                onclick="return confirm('Save these changes to the completed submission?');">
                            <i class="bi bi-save me-1"></i> Save changes
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Right: timeline -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Timeline</strong></div>
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

        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <div class="fw-semibold text-dark mb-1"><i class="bi bi-shield-check me-1"></i> Note</div>
                Client-submitted field values are read-only here. If the client's input needs to change,
                report an error on the submission instead so it goes back through the normal flow.
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
</style>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
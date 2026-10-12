<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\SubmissionFieldValueRepository;
use App\Repositories\SubmissionRepository;

// If the user is an authenticated client, we still let them use this page —
// it becomes a quick "look up my own submission" tool.

$errors  = [];
$result  = null;
$old     = ['tracking_id' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/track');

    $old['tracking_id'] = strtoupper(trim((string) ($_POST['tracking_id'] ?? '')));
    $old['email']       = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($old['tracking_id'] === '') {
        $errors['tracking_id'] = 'Tracking ID is required.';
    }
    if ($old['email'] === '') {
        $errors['email'] = 'Email is required for verification.';
    }

    if (!$errors) {
        $subRepo = new SubmissionRepository();
        $submission = $subRepo->findByTrackingId($old['tracking_id']);

        // Always require the email to match to prevent ID enumeration
        if (!$submission) {
            $errors['general'] = 'No submission found for that tracking ID and email.';
        } elseif (strtolower((string) $submission['client_email']) !== $old['email']) {
            // Do not reveal whether the tracking ID exists
            $errors['general'] = 'No submission found for that tracking ID and email.';

            (new App\Services\AuditService())->log(
                'TRACKING_LOOKUP_DENIED', 'submission', (int) $submission['id'],
                'Public tracking email mismatch for ' . $old['tracking_id'],
                userType: 'guest'
            );
        } else {
            $id = (int) $submission['id'];

            $fields  = (new SubmissionFieldValueRepository())->listForSubmission($id);
            $docs    = (new DocumentRepository())->listForSubmission($id);

            $feedback = Database::selectAll(
                'SELECT * FROM feedback
                 WHERE submission_id = :s AND is_client_visible = 1
                 ORDER BY id ASC',
                [':s' => $id]
            );

            // Public tracking only exposes safe fields
            $publicFields = array_filter($fields, function ($f) {
                // Do not expose email-like values or bank details publicly
                return !preg_match('/(email|bank|account|password)/i', (string) $f['field_key_snapshot']);
            });

            $result = [
                'submission' => $submission,
                'fields'     => $publicFields,
                'documents'  => $docs,
                'feedback'   => $feedback,
            ];

            (new App\Services\AuditService())->log(
                'TRACKING_LOOKUP_SUCCESS', 'submission', $id,
                'Public tracking lookup for ' . $old['tracking_id'],
                userType: 'guest'
            );
        }
    }
}

$pageTitle = 'Track a submission';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Track your submission</h1>
<p class="subtitle">Enter your tracking ID and the email you used when submitting.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<?php if ($result === null): ?>

    <form method="post" novalidate>
        <?= Csrf::field() ?>

        <div class="form-floating mb-3">
            <input type="text" name="tracking_id" id="tracking_id" class="form-control"
                   placeholder="Tracking ID" required maxlength="30" autofocus
                   value="<?= e($old['tracking_id']) ?>">
            <label for="tracking_id">Tracking ID</label>
        </div>

        <div class="form-floating mb-3">
            <input type="email" name="email" id="email" class="form-control"
                   placeholder="Email" required maxlength="190"
                   value="<?= e($old['email']) ?>">
            <label for="email">Email used at submission</label>
        </div>

        <button type="submit" class="btn btn-brand w-100">Track submission</button>

        <p class="text-center text-muted mt-3 mb-0 small">
            <a href="<?= e(url('/')) ?>">Back to home</a>
            · <a href="<?= e(url('/login')) ?>">Sign in</a>
        </p>
    </form>

<?php else: $s = $result['submission']; ?>

    <div class="alert alert-success">
        <strong>Tracking ID <?= e($s['tracking_id']) ?></strong>
    </div>

    <dl class="row small">
        <dt class="col-5 text-muted">Service</dt>
        <dd class="col-7"><?= e($s['service_name_snapshot']) ?></dd>

        <dt class="col-5 text-muted">Status</dt>
        <dd class="col-7">
            <span class="badge bg-<?= e(status_badge($s['status'])) ?>">
                <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
            </span>
        </dd>

        <dt class="col-5 text-muted">Submitted</dt>
        <dd class="col-7"><?= e(fmt_dt($s['created_at'])) ?></dd>

        <?php if ($s['completed_at']): ?>
            <dt class="col-5 text-muted">Completed</dt>
            <dd class="col-7"><?= e(fmt_dt($s['completed_at'])) ?></dd>
        <?php endif; ?>

        <?php if (!empty($s['error_remarks'])): ?>
            <dt class="col-5 text-muted">Error</dt>
            <dd class="col-7 text-danger"><?= e($s['error_remarks']) ?></dd>
        <?php endif; ?>
    </dl>

    <?php if (!empty($result['feedback'])): ?>
        <hr>
        <h6 class="text-uppercase small text-muted">Admin remarks</h6>
        <?php foreach ($result['feedback'] as $fb): ?>
            <div class="mb-2">
                <div class="small text-muted"><?= e(fmt_dt($fb['created_at'])) ?></div>
                <div><?= nl2br(e($fb['remarks'])) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($result['documents'])): ?>
        <hr>
        <h6 class="text-uppercase small text-muted">Documents</h6>
        <div class="list-group">
            <?php foreach ($result['documents'] as $doc): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="small"><?= e($doc['original_name']) ?></span>
                    <a class="btn btn-sm btn-outline-primary"
                       href="<?= e(url('/download-document?id=' . (int) $doc['id'])) ?>">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-2">
            <i class="bi bi-info-circle me-1"></i>
            You may be asked to sign in to download documents for security.
        </div>
    <?php endif; ?>

    <hr>
    <a href="<?= e(url('/track')) ?>" class="btn btn-outline-secondary btn-sm">Track another</a>

<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
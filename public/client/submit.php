<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\ServiceRepository;
use App\Repositories\WalletRepository;
use App\Services\SubmissionService;
use App\Support\DynamicForm;

$clientUser = Auth::requireClient();

$serviceRepo = new ServiceRepository();
$walletRepo  = new WalletRepository();

// Selected service: from ?service=slug or ?service_id=ID or ?id=ID
$service = null;
$slug    = (string) ($_GET['service'] ?? $_POST['service_slug'] ?? '');
$sid     = (int)    ($_GET['service_id'] ?? $_POST['service_id'] ?? 0);

if ($slug !== '') {
    $service = $serviceRepo->findBySlug($slug);
} elseif ($sid > 0) {
    $service = $serviceRepo->findById($sid);
}

$allServices = $serviceRepo->allActiveForSelect();
$wallet      = $walletRepo->findByUserId((int) $clientUser['id']);
$balance     = $wallet['balance'] ?? '0.00';

$errors         = [];
$oldFieldValues = [];
$flashInsufficient = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/client/submit');

    // Re-resolve the service from POST
    $postSid     = (int)    ($_POST['service_id'] ?? 0);
    $postService = $serviceRepo->findById($postSid);
    if (!$postService) {
        $errors['service_id'] = 'Please choose a valid service.';
    } else {
        $service = $postService;
    }

    $oldFieldValues = is_array($_POST['fields'] ?? null) ? (array) $_POST['fields'] : [];

    if (!$errors && $service) {
        $result = (new SubmissionService())->submit(
            (int) $clientUser['id'],
            (int) $service['id'],
            ['fields' => $oldFieldValues]
        );

        if ($result['ok']) {
            flash('success', 'Submission received. Tracking ID: ' . $result['tracking_id']);
            redirect('/client/submission-view?id=' . (int) $result['submission_id'] . '&new=1');
        }

        if (($result['error'] ?? '') === 'INSUFFICIENT_FUNDS') {
            $flashInsufficient = [
                'required'  => $result['required'] ?? '0.00',
                'available' => $result['available'] ?? $balance,
            ];
        } elseif (!empty($result['errors'])) {
            $errors = array_merge($errors, $result['errors']);
        } else {
            $errors['general'] = $result['error'] ?? 'Submission failed. Please try again.';
        }
    }
}

$pageTitle = 'Submit a request';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Submit a request</h1>
        <p class="text-muted small mb-0">
            Fill the form below. Your wallet will be charged when you submit.
        </p>
    </div>
    <div class="mt-2 mt-md-0">
        <span class="small text-muted me-2">Wallet balance</span>
        <span class="badge bg-primary-subtle text-primary fs-6"><?= e(money($balance)) ?></span>
    </div>
</div>

<?php if ($flashInsufficient): ?>
    <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <strong>Insufficient wallet balance.</strong><br>
            You need <strong><?= e(money($flashInsufficient['required'])) ?></strong> but you only have
            <strong><?= e(money($flashInsufficient['available'])) ?></strong>.
        </div>
        <a href="<?= e(url('/client/fund-wallet')) ?>" class="btn btn-brand">
            <i class="bi bi-wallet2 me-1"></i> Fund wallet
        </a>
    </div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= e($errors['general']) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" novalidate id="submitForm">
                    <?= Csrf::field() ?>

                    <!-- Service selector -->
                    <div class="mb-3">
                        <label class="form-label">Service <span class="text-danger">*</span></label>
                        <select name="service_id" id="service_id" class="form-select" required>
                            <option value="">— choose a service —</option>
                            <?php foreach ($allServices as $opt): ?>
                                <option value="<?= (int) $opt['id'] ?>"
                                        data-slug="<?= e($opt['slug']) ?>"
                                        data-price="<?= e($opt['price']) ?>"
                                        data-duration="<?= e($opt['duration_label'] ?: human_duration((int) $opt['processing_minutes'])) ?>"
                                    <?= ($service && (int) $service['id'] === (int) $opt['id']) ? 'selected' : '' ?>>
                                    <?= e($opt['name']) ?> — <?= e(money($opt['price'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['service_id'])): ?>
                            <div class="text-danger small mt-1"><?= e($errors['service_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Dynamic fields -->
                    <div id="dynamicFields">
                        <?php if ($service):
                            $form = DynamicForm::forService((int) $service['id']);
                            echo $form->render($oldFieldValues, $errors);
                        else: ?>
                            <div class="alert alert-info mb-0">
                                Choose a service above to load its submission form.
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($service): ?>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <div class="small text-muted">You will be charged</div>
                                <div class="h4 mb-0"><?= e(money($service['price'])) ?></div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="<?= e(url('/client/services')) ?>" class="btn btn-outline-secondary">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-brand"
                                        onclick="return confirm('Submit this request and charge your wallet?');">
                                    <i class="bi bi-send me-1"></i> Submit request
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($service): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h6 class="text-uppercase small text-muted mb-3">About this service</h6>
                    <div class="fw-semibold mb-2"><?= e($service['name']) ?></div>
                    <p class="small text-muted mb-3"><?= e($service['description'] ?: '') ?></p>
                    <div class="small d-flex justify-content-between">
                        <span class="text-muted">Cost</span>
                        <strong><?= e(money($service['price'])) ?></strong>
                    </div>
                    <div class="small d-flex justify-content-between">
                        <span class="text-muted">Processing time</span>
                        <strong><?= e($service['duration_label'] ?: human_duration((int) $service['processing_minutes'])) ?></strong>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <div class="fw-semibold text-dark mb-2"><i class="bi bi-info-circle me-1"></i> Good to know</div>
                <ul class="mb-0 ps-3">
                    <li>Your wallet is debited the moment you submit.</li>
                    <li>A unique tracking ID is generated instantly.</li>
                    <li>You can track progress any time from <em>My Submissions</em>.</li>
                    <li>Documents will be attached to the submission when complete.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
    // Reload the page when the service changes so the dynamic form is rebuilt server-side
    document.getElementById('service_id')?.addEventListener('change', function () {
        const slug = this.options[this.selectedIndex].dataset.slug;
        if (slug) {
            window.location.href = <?= json_encode(url('/client/submit?service=')) ?> + encodeURIComponent(slug);
        } else {
            window.location.href = <?= json_encode(url('/client/submit')) ?>;
        }
    });
</script>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Settings;
use App\Payments\GatewayManager;
use App\Repositories\WalletRepository;
use App\Services\FundingService;
use App\Services\OnlinePaymentService;

$clientUser = Auth::requireClient();
$wallet     = (new WalletRepository())->findByUserId((int) $clientUser['id']);

$errors    = [];
$oldAmount = '';
$oldMethod = 'bank';

$bank = Settings::get('bank_transfer_details', []);
if (!is_array($bank)) $bank = [];

$drivers = GatewayManager::enabledDrivers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/client/fund-wallet');

    $oldMethod = (string) ($_POST['method'] ?? 'bank');
    $oldAmount = trim((string) ($_POST['amount'] ?? ''));

    if ($oldMethod === 'bank') {
        if (!isset($_FILES['proof'])) {
            $errors['proof'] = 'Please attach your payment proof.';
        } else {
            $result = (new FundingService())->submitBankTransfer(
                (int) $clientUser['id'],
                $oldAmount,
                $_FILES['proof']
            );
            if ($result['ok']) {
                flash('success', 'Funding request submitted. Reference: ' . $result['payment_ref']);
                redirect('/client/transactions');
            }
            $errors['general'] = $result['error'] ?? 'Could not submit funding request.';
        }
    } else {
        // Online gateway
        if (!isset($drivers[$oldMethod])) {
            $errors['general'] = 'That payment method is not available.';
        } else {
            $result = (new OnlinePaymentService())->initiate(
                (int) $clientUser['id'],
                $oldMethod,
                $oldAmount,
                (string) $clientUser['email']
            );

            if (!empty($result['ok']) && !empty($result['redirect_url'])) {
                // Fire-and-forget: hand the browser to the gateway
                header('Location: ' . $result['redirect_url']);
                exit;
            }
            $errors['general'] = $result['error'] ?? 'Could not start the payment.';
        }
    }
}

$pageTitle = 'Fund Wallet';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>



<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">

<br />

    <div>
        <h1 class="h4 mb-1">Fund your wallet</h1>
        <p class="text-muted small mb-0">
            Current balance: <strong><?= e(money($wallet['balance'] ?? '0.00')) ?></strong>
        </p>
    </div>
    <a href="<?= e(url('/client/wallet')) ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="bi bi-arrow-left me-1"></i> Back to wallet
    </a>
</div>
<!-- ================= Wallet balance card ================= -->
<div class="wallet-banner">
    <div class="wallet-banner__left">
        <div class="wallet-banner__icon">
            <i class="bi bi-wallet2"></i>
        </div>
        <div>
            <div class="wallet-banner__label">Wallet balance</div>
            <div class="wallet-banner__amount"><?= e(money($wallet['balance'] ?? '0.00')) ?></div>
            <div class="wallet-banner__hint">
                Fund your wallet, then choose any service to begin.
            </div>
        </div>
    </div>

    <div class="wallet-banner__right">
        <a href="<?= e(url('/client/services')) ?>" class="wallet-banner__cta">
            <i class="bi bi-grid"></i>
            Browse services
        </a>
        <a href="<?= e(url('/client/submissions')) ?>" class="wallet-banner__cta wallet-banner__cta--ghost">
            <i class="bi bi-file-earmark-text"></i>
            My submissions
        </a>
    </div>
</div>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($drivers)): ?>
    <div class="alert alert-warning">
        No payment methods are currently available. Please contact support.
    </div>
<?php else: ?>

    <!-- Method chooser -->
    <ul class="nav nav-pills mb-4">
        <?php $first = true; foreach ($drivers as $name => $driver): ?>
            <li class="nav-item me-2">
                <a class="nav-link <?= $first ? 'active' : '' ?>"
                   data-bs-toggle="pill"
                   href="#panel-<?= e($name) ?>">
                    <i class="bi <?= $name === 'bank_transfer' ? 'bi-bank' : 'bi-credit-card' ?> me-1"></i>
                    <?= e($driver->label()) ?>
                </a>
            </li>
            <?php $first = false; endforeach; ?>
    </ul>

    <div class="tab-content">
        <?php $first = true; foreach ($drivers as $name => $driver): ?>

            <?php if ($name === 'bank_transfer'): ?>
                <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="panel-bank_transfer">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h5 class="mb-3">Bank Transfer</h5>
                                    <p class="text-muted small mb-3">
                                        Transfer the exact amount to the account below, then upload your proof of payment.
                                    </p>

                                    <div class="bg-light rounded p-3 mb-4">
                                        <div class="row g-3">
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Bank</div>
                                                <div class="fw-semibold"><?= e($bank['bank_name'] ?? '—') ?></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Account Name</div>
                                                <div class="fw-semibold"><?= e($bank['account_name'] ?? '—') ?></div>
                                            </div>
                                            <div class="col-sm-6">
                                                <div class="text-muted small">Account Number</div>
                                                <div class="fw-semibold"><?= e($bank['account_number'] ?? '—') ?></div>
                                            </div>
                                            <div class="col-12">
                                                <div class="text-muted small">Instructions</div>
                                                <div><?= e($bank['instructions'] ?? 'Transfer the exact amount and upload your proof.') ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <form method="post" enctype="multipart/form-data" novalidate>
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="method" value="bank">

                                        <div class="mb-3">
                                            <label class="form-label">Amount you transferred (₦)</label>
                                            <input type="number" step="0.01" min="100" name="amount" class="form-control"
                                                   required value="<?= e($oldMethod === 'bank' ? $oldAmount : '') ?>">
                                            <div class="form-text">Minimum ₦100.00.</div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Upload payment proof</label>
                                            <input type="file" name="proof" class="form-control"
                                                   accept=".pdf,.jpg,.jpeg,.png" required>
                                            <div class="form-text">
                                                PDF, JPG, JPEG or PNG. Max
                                                <?= (int) round(UPLOAD_MAX_BYTES / 1024 / 1024, 1) ?> MB.
                                            </div>
                                        </div>

                                        <button class="btn btn-brand w-100">
                                            <i class="bi bi-cloud-upload me-1"></i> Submit funding request
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body small text-muted">
                                    <div class="fw-semibold text-dark mb-2">
                                        <i class="bi bi-info-circle me-1"></i> How bank transfer works
                                    </div>
                                    <ol class="mb-0 ps-3">
                                        <li>Transfer the exact amount to the account shown.</li>
                                        <li>Upload a clear screenshot or receipt of the transfer.</li>
                                        <li>Our team verifies it — usually within minutes.</li>
                                        <li>Your wallet is credited and you receive a notification.</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="panel-<?= e($name) ?>">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <h5 class="mb-3"><?= e($driver->label()) ?></h5>
                                    <p class="text-muted small mb-3">
                                        You'll be redirected to <?= e(ucfirst($name)) ?> to complete the payment securely.
                                        Your wallet is credited immediately after payment.
                                    </p>

                                    <form method="post" novalidate>
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="method" value="<?= e($name) ?>">

                                        <div class="mb-3">
                                            <label class="form-label">Amount (₦)</label>
                                            <input type="number" step="0.01" min="100" name="amount" class="form-control"
                                                   required
                                                   value="<?= e($oldMethod === $name ? $oldAmount : '') ?>">
                                            <div class="form-text">Minimum ₦100.00, maximum ₦10,000,000.00.</div>
                                        </div>

                                        <button class="btn btn-brand w-100">
                                            <i class="bi bi-arrow-right-circle me-1"></i> Pay with <?= e(ucfirst($name)) ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body small text-muted">
                                    <div class="fw-semibold text-dark mb-2">
                                        <i class="bi bi-shield-check me-1"></i> Secure payment
                                    </div>
                                    <ul class="mb-0 ps-3">
                                        <li>Processed by <?= e(ucfirst($name)) ?> — your card details never touch our servers.</li>
                                        <li>Your wallet is credited as soon as the payment is confirmed.</li>
                                        <li>Every transaction is recorded in your ledger for reference.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php $first = false; endforeach; ?>
    </div>

<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
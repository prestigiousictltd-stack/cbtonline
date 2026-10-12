<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\OnlinePaymentService;
use App\Services\PaymentVerificationService;

// No auth required — the reference itself is the proof of involvement.
// We never expose another user's data; the return page simply shows
// the outcome of the reference and lets the client sign in to see details.

$gateway   = (string) ($_GET['gateway']   ?? $_GET['gateway_name'] ?? '');
$reference = (string) ($_GET['reference'] ?? $_GET['tx_ref']       ?? $_GET['trxref'] ?? '');

if ($gateway === '' || $reference === '') {
    http_response_code(400);
    exit('Invalid callback.');
}

$svc    = new OnlinePaymentService();
$result = $svc->confirmReturn($gateway, $reference);

$status       = $result['status']  ?? null;
$payment_ref  = $result['payment_ref'] ?? $reference;
$error        = $result['error']   ?? null;

$pageTitle = 'Payment result';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Payment result</h1>

<?php if (!empty($result['ok']) && $status === 'successful'): ?>
    <div class="alert alert-success">
        <strong>Payment successful.</strong>
        Your wallet has been credited for reference <code><?= e($payment_ref) ?></code>.
    </div>
    <div class="d-grid gap-2">
        <?php if (Auth::clientCheck()): ?>
            <a class="btn btn-brand" href="<?= e(url('/client/wallet')) ?>">Go to wallet</a>
            <a class="btn btn-outline-secondary" href="<?= e(url('/client/services')) ?>">View Services</a>
        <?php else: ?>
            <a class="btn btn-brand" href="<?= e(url('/login')) ?>">Sign in to view your wallet</a>
        <?php endif; ?>
    </div>

<?php elseif (!empty($result['ok']) && $status === 'pending'): ?>
    <div class="alert alert-warning">
        <strong>Payment pending.</strong>
        We haven't received a successful confirmation yet for
        <code><?= e($payment_ref) ?></code>. This can take a minute. Please refresh in a moment.
    </div>
    <div class="d-grid gap-2">
        <a class="btn btn-brand" href="<?= e(url('/payment/callback?gateway=' . urlencode($gateway) . '&reference=' . urlencode($reference))) ?>">
            <i class="bi bi-arrow-clockwise me-1"></i> Check again
        </a>
        <?php if (Auth::clientCheck()): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/client/transactions')) ?>">View transactions</a>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="alert alert-danger">
        <strong>Payment could not be confirmed.</strong>
        <?= e($error ?? 'Please try again or contact support.') ?>
        <div class="small mt-2">Reference: <code><?= e($payment_ref) ?></code></div>
    </div>
    <div class="d-grid gap-2">
        <?php if (Auth::clientCheck()): ?>
            <a class="btn btn-brand" href="<?= e(url('/client/fund-wallet')) ?>">Try again</a>
            <a class="btn btn-outline-secondary" href="<?= e(url('/client/transactions')) ?>">View transactions</a>
        <?php else: ?>
            <a class="btn btn-brand" href="<?= e(url('/login')) ?>">Sign in</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
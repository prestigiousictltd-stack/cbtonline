<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletTransactionRepository;

$clientUser = Auth::requireClient();

$type = (string) ($_GET['type'] ?? '');
$ref  = (string) ($_GET['ref']  ?? '');

$receipt = null;
$rows    = [];

if ($type === 'payment' && $ref !== '') {
    $p = (new PaymentRepository())->findByRef($ref);
    if ($p && (int) $p['user_id'] === (int) $clientUser['id']) {
        $receipt = [
            'heading'   => 'Funding Receipt',
            'reference' => $p['payment_ref'],
            'status'    => $p['status'],
            'amount'    => $p['amount'],
            'method'    => ucwords(str_replace('_', ' ', $p['method'])),
            'date'      => $p['created_at'],
            'notes'     => 'Wallet funding via ' . str_replace('_', ' ', $p['method']),
        ];
    }
} elseif ($type === 'wallet' && $ref !== '') {
    $t = (new WalletTransactionRepository())->findByRef($ref);
    if ($t && (int) $t['user_id'] === (int) $clientUser['id']) {
        $receipt = [
            'heading'   => 'Wallet Transaction Receipt',
            'reference' => $t['transaction_ref'],
            'status'    => $t['status'],
            'amount'    => ($t['type'] === 'credit' ? '+' : '−') . money($t['amount'], ''),
            'method'    => ucwords(str_replace('_', ' ', $t['category'])),
            'date'      => $t['created_at'],
            'notes'     => $t['description'] ?? '',
        ];
    }
}

if (!$receipt) {
    flash('danger', 'Receipt not found.');
    redirect('/client/transactions');
}

$pageTitle = 'Receipt';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/client.css')) ?>" rel="stylesheet">
</head>
<body class="receipt-page">

<div class="container no-print mb-3">
    <a href="<?= e(url('/client/transactions')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Back
    </a>
    <button onclick="window.print()" class="btn btn-brand btn-sm ms-2">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

<div class="receipt-card">
    <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
            <div class="receipt-brand"><?= e(APP_NAME) ?></div>
            <div class="receipt-meta"><?= e(App\Core\Settings::get('site_email', 'support@example.com')) ?></div>
            <div class="receipt-meta"><?= e(App\Core\Settings::get('site_phone', '')) ?></div>
        </div>
        <div class="text-end">
            <div class="text-muted small"><?= e($receipt['heading']) ?></div>
            <div class="fw-bold"><?= e($receipt['reference']) ?></div>
            <div class="text-muted small"><?= e(fmt_dt($receipt['date'], 'd M Y, H:i')) ?></div>
        </div>
    </div>

    <hr>

    <table class="receipt-table">
        <tr><th>Customer</th><td><?= e($clientUser['full_name']) ?></td></tr>
        <tr><th>Email</th><td><?= e($clientUser['email']) ?></td></tr>
        <tr><th>Method</th><td><?= e($receipt['method']) ?></td></tr>
        <tr><th>Status</th>
            <td><span class="badge bg-<?= e(status_badge($receipt['status'])) ?>">
                <?= e(ucwords(str_replace('_', ' ', $receipt['status']))) ?>
            </span></td></tr>
        <?php if (!empty($receipt['notes'])): ?>
            <tr><th>Notes</th><td><?= e($receipt['notes']) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="text-end mt-4">
        <div class="text-muted small">Amount</div>
        <div class="receipt-amount"><?= e(is_numeric($receipt['amount']) ? money($receipt['amount']) : $receipt['amount']) ?></div>
    </div>

    <hr class="mt-4">
    <p class="text-muted small text-center mb-0">
        This is a system-generated receipt. No signature is required.
    </p>
</div>

</body>
</html>
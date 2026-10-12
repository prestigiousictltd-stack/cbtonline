<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\WalletRepository;
use App\Repositories\WalletTransactionRepository;

$clientUser = Auth::requireClient();
$wallet     = (new WalletRepository())->findByUserId((int) $clientUser['id']);
$ledger     = new WalletTransactionRepository();

$funded = $ledger->sumByCategory((int) $clientUser['id'], WALLET_CAT_FUNDING, WALLET_CREDIT);
$spent  = $ledger->sumByCategory((int) $clientUser['id'], WALLET_CAT_SERVICE_CHARGE, WALLET_DEBIT);

$recent = $ledger->paginateForUser((int) $clientUser['id'], 1, 8)['rows'];

$pageTitle = 'Wallet';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Wallet</h1>
        <p class="text-muted small mb-0">Manage your balance and funding.</p>
    </div>
    <a href="<?= e(url('/client/fund-wallet')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-plus-circle me-1"></i> Fund wallet
    </a>
</div>

<!-- ================= Quick actions card ================= -->
<div class="action-banner">
    <div class="action-banner__left">
        <div class="action-banner__icon">
            <i class="bi bi-wallet2"></i>
        </div>
        <div>
            <div class="action-banner__label">Wallet balance</div>
            <div class="action-banner__balance"><?= e(money($wallet['balance'] ?? '0.00')) ?></div>
            <div class="action-banner__hint">
                Fund your wallet to start a new request.
            </div>
        </div>
    </div>

    <div class="action-banner__right">
        <a href="<?= e(url('/client/fund-wallet')) ?>" class="action-banner__btn action-banner__btn--primary">
            <i class="bi bi-plus-circle-fill"></i>
            Fund wallet
        </a>
        <a href="<?= e(url('/client/services')) ?>" class="action-banner__btn action-banner__btn--secondary">
            <i class="bi bi-grid"></i>
            Browse services
        </a>
        <a href="<?= e(url('/client/submissions')) ?>" class="action-banner__btn action-banner__btn--secondary">
            <i class="bi bi-file-earmark-text"></i>
            My submissions
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card brand">
            <div class="label">Current balance</div>
            <div class="value"><?= e(money($wallet['balance'] ?? '0.00')) ?></div>
            <div class="small text-muted mt-2">Currency: <?= e($wallet['currency'] ?? 'NGN') ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card green">
            <div class="label">Total funded</div>
            <div class="value"><?= e(money($funded)) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card amber">
            <div class="label">Total spent</div>
            <div class="value"><?= e(money($spent)) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Recent wallet activity</strong>
        <a href="<?= e(url('/client/transactions')) ?>" class="small">View all</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recent)): ?>
            <div class="p-4 text-center text-muted">No transactions yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance after</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $t): ?>
                            <tr>
                                <td><code class="small"><?= e($t['transaction_ref']) ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $t['type'] === 'credit' ? 'success' : 'secondary' ?>">
                                        <?= e(ucfirst($t['type'])) ?>
                                    </span>
                                    <span class="small text-muted ms-1"><?= e(str_replace('_', ' ', $t['category'])) ?></span>
                                </td>
                                <td class="<?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= $t['type'] === 'credit' ? '+' : '−' ?><?= e(money($t['amount'], '')) ?>
                                </td>
                                <td><?= e(money($t['balance_after'])) ?></td>
                                <td class="small text-muted"><?= e(fmt_dt($t['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
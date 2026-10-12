<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\PaymentRepository;
use App\Repositories\WalletTransactionRepository;

$clientUser = Auth::requireClient();

$tab      = (string) ($_GET['tab'] ?? 'wallet');   // wallet | payments
$page     = max(1, (int) ($_GET['page'] ?? 1));
$category = (string) ($_GET['category'] ?? '');

$walletRepo = new WalletTransactionRepository();
$payRepo    = new PaymentRepository();

$walletsPager  = $walletRepo->paginateForUser((int) $clientUser['id'], $page, 20, $category ?: null);
$paymentsPager = $payRepo->paginateForUser((int) $clientUser['id'], $page, 20);

$pageTitle = 'Transactions';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Transactions</h1>
        <p class="text-muted small mb-0">Your complete wallet and funding history.</p>
    </div>
    <a href="<?= e(url('/client/fund-wallet')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-plus-circle me-1"></i> Fund wallet
    </a>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'wallet' ? 'active' : '' ?>"
           href="<?= e(url('/client/transactions?tab=wallet')) ?>">Wallet ledger</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'payments' ? 'active' : '' ?>"
           href="<?= e(url('/client/transactions?tab=payments')) ?>">Funding requests</a>
    </li>
</ul>

<?php if ($tab === 'payments'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($paymentsPager['rows'])): ?>
                <div class="p-4 text-center text-muted">No funding requests yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Reference</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentsPager['rows'] as $p): ?>
                                <tr>
                                    <td><code class="small"><?= e($p['payment_ref']) ?></code></td>
                                    <td><?= e(ucwords(str_replace('_', ' ', $p['method']))) ?></td>
                                    <td class="fw-semibold"><?= e(money($p['amount'])) ?></td>
                                    <td>
                                        <span class="badge bg-<?= e(status_badge($p['status'])) ?>">
                                            <?= e(ucwords(str_replace('_', ' ', $p['status']))) ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?= e(fmt_dt($p['created_at'])) ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= e(url('/receipt?type=payment&ref=' . urlencode($p['payment_ref']))) ?>">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex gap-2 flex-wrap align-items-center">
            <form class="d-flex gap-2 flex-grow-1" method="get">
                <input type="hidden" name="tab" value="wallet">
                <select name="category" class="form-select form-select-sm" style="max-width:220px">
                    <option value="">All categories</option>
                    <?php foreach (['funding','service_charge','refund','reversal','adjustment'] as $c): ?>
                        <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $c))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-secondary">Filter</button>
            </form>
        </div>
        <div class="card-body p-0">
            <?php if (empty($walletsPager['rows'])): ?>
                <div class="p-4 text-center text-muted">No wallet transactions yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Reference</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Balance after</th>
                                <th>Description</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($walletsPager['rows'] as $t): ?>
                                <tr>
                                    <td><code class="small"><?= e($t['transaction_ref']) ?></code></td>
                                    <td>
                                        <span class="badge bg-<?= $t['type'] === 'credit' ? 'success' : 'secondary' ?>">
                                            <?= e(ucfirst($t['type'])) ?>
                                        </span>
                                        <span class="small text-muted d-block"><?= e(str_replace('_', ' ', $t['category'])) ?></span>
                                    </td>
                                    <td class="<?= $t['type'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-semibold">
                                        <?= $t['type'] === 'credit' ? '+' : '−' ?><?= e(money($t['amount'], '')) ?>
                                    </td>
                                    <td><?= e(money($t['balance_after'])) ?></td>
                                    <td class="small text-muted"><?= e($t['description'] ?? '') ?></td>
                                    <td class="small text-muted"><?= e(fmt_dt($t['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
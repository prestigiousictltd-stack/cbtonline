<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AdminCommissionRepository;
use App\Repositories\CommissionTransactionRepository;

$adminUser = Auth::requireAdmin();

$page = max(1, (int) ($_GET['page'] ?? 1));
$type = trim((string) ($_GET['type'] ?? ''));

$ledgerRepo = new CommissionTransactionRepository();
$adminRepo  = new AdminCommissionRepository();

$pager = $ledgerRepo->paginateForAdmin((int) $adminUser['id'], $page, 20, $type ?: null);

$currentBalance  = $adminRepo->currentBalance((int) $adminUser['id']);
$currentReserved = $adminRepo->currentReserved((int) $adminUser['id']);

try {
    $available = money_sub($currentBalance, $currentReserved);
} catch (\Throwable) {
    $available = '0.00';
}

$earned    = $ledgerRepo->sumEarnedForAdmin((int) $adminUser['id']);
$withdrawn = $ledgerRepo->sumWithdrawnForAdmin((int) $adminUser['id']);

$adminSidebarServices = (new App\Repositories\ServiceRepository())->allActive();

$pageTitle = 'Commission';
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Commission</h1>
        <p class="text-muted small mb-0">Your complete commission ledger.</p>
    </div>
    <a href="<?= e(url('/admin/withdrawal-request')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-cash-stack me-1"></i> Request withdrawal
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="admin-stat brand">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Current balance</div>
                    <div class="value"><?= e(money($currentBalance)) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="admin-stat amber">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Reserved</div>
                    <div class="value"><?= e(money($currentReserved)) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-lock"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="admin-stat green">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Available</div>
                    <div class="value"><?= e(money($available)) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-unlock"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="admin-stat purple">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Total earned</div>
                    <div class="value"><?= e(money($earned)) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Filter by type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    <?php foreach ([
                        'earned'               => 'Earned',
                        'withdrawal_request'   => 'Withdrawal request',
                        'withdrawal_reversal'  => 'Withdrawal reversal',
                        'withdrawal'           => 'Withdrawal (paid)',
                        'adjustment'           => 'Adjustment',
                        'reversal'             => 'Reversal',
                    ] as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $type === $k ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <?php if ($type !== ''): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/commissions')) ?>">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-cash-coin fs-2 d-block mb-2"></i>
                No commission transactions yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance after</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $t): ?>
                            <tr>
                                <td><code class="small"><?= e($t['transaction_ref']) ?></code></td>
                                <td>
                                    <span class="badge bg-<?= $t['direction'] === 'credit' ? 'success' : 'secondary' ?>">
                                        <?= e(ucfirst($t['direction'])) ?>
                                    </span>
                                    <span class="small text-muted d-block"><?= e(str_replace('_', ' ', $t['type'])) ?></span>
                                </td>
                                <td class="<?= $t['direction'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-semibold">
                                    <?= $t['direction'] === 'credit' ? '+' : '−' ?><?= e(money($t['amount'], '')) ?>
                                </td>
                                <td><?= e(money($t['balance_after'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($t['status'])) ?>">
                                        <?= e($t['status']) ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= e($t['description'] ?? '') ?></td>
                                <td class="small text-muted"><?= e(fmt_dt($t['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pager['pages'] > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                        <?php
                        $qs = array_filter(['page' => $i, 'type' => $type]);
                        ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/admin/commissions?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
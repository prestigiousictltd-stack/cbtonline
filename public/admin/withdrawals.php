<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\AdminCommissionRepository;
use App\Repositories\WithdrawalRepository;
use App\Services\WithdrawalService;

$adminUser = Auth::requireAdmin();

// Handle cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/admin/withdrawals');

    if (($_POST['action'] ?? '') === 'cancel') {
        $wid    = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Cancelled by admin'));
        $result = (new WithdrawalService())->cancelByAdmin($wid, (int) $adminUser['id'], $reason);

        if ($result['ok']) {
            flash('success', 'Withdrawal cancelled and funds released.');
        } else {
            $msg = match ($result['error'] ?? '') {
                'NOT_YOURS'       => 'You can only cancel your own withdrawals.',
                'NOT_CANCELLABLE' => 'Only pending withdrawals can be cancelled.',
                'NOT_FOUND'       => 'Withdrawal not found.',
                default           => 'Could not cancel the withdrawal.',
            };
            flash('danger', $msg);
        }
    }
    redirect('/admin/withdrawals');
}

$page   = max(1, (int) ($_GET['page'] ?? 1));
$status = trim((string) ($_GET['status'] ?? ''));

$wdRepo    = new WithdrawalRepository();
$adminRepo = new AdminCommissionRepository();

$pager = $wdRepo->paginateForAdmin((int) $adminUser['id'], $page, 20, $status ?: null);

$balance  = $adminRepo->currentBalance((int) $adminUser['id']);
$reserved = $adminRepo->currentReserved((int) $adminUser['id']);
try { $available = money_sub($balance, $reserved); } catch (\Throwable) { $available = '0.00'; }

$adminSidebarServices = (new App\Repositories\ServiceRepository())->allActive();

$pageTitle = 'Withdrawals';
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Withdrawals</h1>
        <p class="text-muted small mb-0">Your payout requests.</p>
    </div>
    <a href="<?= e(url('/admin/withdrawal-request')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-cash-stack me-1"></i> New withdrawal request
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="admin-stat brand">
            <div class="label">Available for withdrawal</div>
            <div class="value"><?= e(money($available)) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="admin-stat amber">
            <div class="label">Reserved (pending/approved)</div>
            <div class="value"><?= e(money($reserved)) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="admin-stat green">
            <div class="label">Current balance</div>
            <div class="value"><?= e(money($balance)) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach (['pending', 'approved', 'paid', 'rejected'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>>
                            <?= e(ucfirst($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <?php if ($status !== ''): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/withdrawals')) ?>">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-cash-stack fs-2 d-block mb-2"></i>
                No withdrawal requests yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Bank</th>
                            <th>Account</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $w): ?>
                            <tr>
                                <td><code class="small"><?= e($w['withdrawal_ref']) ?></code></td>
                                <td class="fw-semibold"><?= e(money($w['amount'])) ?></td>
                                <td>
                                    <div class="small"><?= e($w['bank_name']) ?></div>
                                    <div class="small text-muted"><?= e($w['account_number']) ?></div>
                                </td>
                                <td class="small"><?= e($w['account_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($w['status'])) ?>">
                                        <?= e(ucfirst($w['status'])) ?>
                                    </span>
                                    <?php if ($w['status'] === 'rejected' && !empty($w['super_admin_note'])): ?>
                                        <div class="small text-muted mt-1"><?= e($w['super_admin_note']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($w['status'] === 'paid' && !empty($w['payment_reference'])): ?>
                                        <div class="small text-muted mt-1">Ref: <?= e($w['payment_reference']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($w['created_at'])) ?></td>
                                <td class="text-end">
                                    <?php if ($w['status'] === 'pending'): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Cancel this withdrawal request? Reserved funds will be released.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
                                            <input type="hidden" name="reason" value="Cancelled by admin">
                                            <button class="btn btn-sm btn-outline-danger">
                                                Cancel
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
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
                        <?php $qs = array_filter(['page' => $i, 'status' => $status]); ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/admin/withdrawals?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
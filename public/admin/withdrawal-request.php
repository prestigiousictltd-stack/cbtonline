<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Settings;
use App\Repositories\AdminCommissionRepository;
use App\Services\WithdrawalService;

$adminUser = Auth::requireAdmin();

$adminRepo = new AdminCommissionRepository();
$balance   = $adminRepo->currentBalance((int) $adminUser['id']);
$reserved  = $adminRepo->currentReserved((int) $adminUser['id']);
try { $available = money_sub($balance, $reserved); } catch (\Throwable) { $available = '0.00'; }

$min = money_normalize((string) Settings::get('minimum_withdrawal', '5000.00'));
$max = money_normalize((string) Settings::get('maximum_withdrawal', '500000.00'));

$errors = [];
$old = [
    'amount'         => '',
    'bank_name'      => '',
    'account_name'   => $adminUser['full_name'],
    'account_number' => '',
    'admin_note'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/admin/withdrawal-request');

    $old = [
        'amount'         => trim((string) ($_POST['amount']         ?? '')),
        'bank_name'      => trim((string) ($_POST['bank_name']      ?? '')),
        'account_name'   => trim((string) ($_POST['account_name']   ?? '')),
        'account_number' => trim((string) ($_POST['account_number'] ?? '')),
        'admin_note'     => trim((string) ($_POST['admin_note']     ?? '')),
    ];

    $result = (new WithdrawalService())->request((int) $adminUser['id'], $old);

    if ($result['ok']) {
        flash('success', 'Withdrawal request submitted. Reference: ' . $result['withdrawal_ref']);
        redirect('/admin/withdrawals');
    }

    if (($result['error'] ?? '') === 'INSUFFICIENT_AVAILABLE') {
        $errors['amount'] = 'You only have ' . money($result['available'] ?? '0.00') . ' available.';
    } elseif (!empty($result['errors'])) {
        $errors = $result['errors'];
    } else {
        $errors['general'] = 'Could not submit your request. Please try again.';
    }
}

$adminSidebarServices = (new App\Repositories\ServiceRepository())->allActive();

$pageTitle = 'Request withdrawal';
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Request withdrawal</h1>
        <p class="text-muted small mb-0">Withdraw your available commission.</p>
    </div>
    <a href="<?= e(url('/admin/withdrawals')) ?>" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0">
        <i class="bi bi-arrow-left me-1"></i> Back to withdrawals
    </a>
</div>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= e($errors['general']) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="post" novalidate>
                    <?= Csrf::field() ?>

                    <div class="mb-3">
                        <label class="form-label">Amount (₦) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control"
                               min="<?= e($min) ?>" max="<?= e($max) ?>"
                               placeholder="<?= e($min) ?>" required
                               value="<?= e($old['amount']) ?>">
                        <div class="form-text">
                            Minimum <?= e(money($min)) ?> · Maximum <?= e(money($max)) ?> ·
                            Available <strong><?= e(money($available)) ?></strong>
                        </div>
                        <?php if (!empty($errors['amount'])): ?>
                            <div class="text-danger small mt-1"><?= e($errors['amount']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Bank name <span class="text-danger">*</span></label>
                        <input type="text" name="bank_name" class="form-control" required maxlength="120"
                               placeholder="e.g. Access Bank"
                               value="<?= e($old['bank_name']) ?>">
                        <?php if (!empty($errors['bank_name'])): ?>
                            <div class="text-danger small mt-1"><?= e($errors['bank_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Account name <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control" required maxlength="150"
                               value="<?= e($old['account_name']) ?>">
                        <?php if (!empty($errors['account_name'])): ?>
                            <div class="text-danger small mt-1"><?= e($errors['account_name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Account number <span class="text-danger">*</span></label>
                        <input type="text" name="account_number" class="form-control" required
                               pattern="[0-9]{6,20}" maxlength="20"
                               placeholder="e.g. 0123456789"
                               value="<?= e($old['account_number']) ?>">
                        <?php if (!empty($errors['account_number'])): ?>
                            <div class="text-danger small mt-1"><?= e($errors['account_number']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Note (optional)</label>
                        <textarea name="admin_note" class="form-control" rows="2" maxlength="500"
                                  placeholder="Anything the Super Admin should know."><?= e($old['admin_note']) ?></textarea>
                    </div>

                    <button class="btn btn-brand w-100">
                        <i class="bi bi-send me-1"></i> Submit withdrawal request
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h6 class="text-uppercase small text-muted mb-3">Your balance</h6>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Current balance</span>
                    <strong><?= e(money($balance)) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Reserved (pending/approved)</span>
                    <strong><?= e(money($reserved)) ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="text-muted small">Available for withdrawal</span>
                    <strong class="text-success"><?= e(money($available)) ?></strong>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body small text-muted">
                <div class="fw-semibold text-dark mb-2"><i class="bi bi-info-circle me-1"></i> Good to know</div>
                <ul class="mb-0 ps-3">
                    <li>Funds are reserved the moment you submit and released if the request is rejected or you cancel it.</li>
                    <li>Your balance is only reduced when the Super Admin marks the payout as paid.</li>
                    <li>You can cancel any pending request from the Withdrawals page.</li>
                    <li>Approval typically takes 1–3 business days.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
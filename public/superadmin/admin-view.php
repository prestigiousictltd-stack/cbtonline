<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Repositories\AdminRepository;
use App\Services\AdminManagementService;

$superAdminUser = Auth::requireSuperAdmin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { flash('danger', 'Admin not found.'); redirect('/superadmin/admins'); }

$repo = new AdminRepository();
$admin = $repo->findById($id);
if (!$admin) { flash('danger', 'Admin not found.'); redirect('/superadmin/admins'); }

$svc = new AdminManagementService();

$errors          = [];
$generatedPass   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/admin-view?id=' . $id);

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update') {
        $result = $svc->update((int) $superAdminUser['id'], $id, $_POST);
        if ($result['ok']) {
            flash('success', 'Admin updated.');
            redirect('/superadmin/admin-view?id=' . $id);
        }
        $errors = $result['errors'];
    }

    elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        $result = $svc->setStatus((int) $superAdminUser['id'], $id, $status);
        if ($result['ok']) {
            $extra = !empty($result['released']) ? ' (' . (int) $result['released'] . ' tasks released)' : '';
            flash('success', 'Status updated.' . $extra);
        } else {
            flash('danger', $result['errors']['general'] ?? 'Action failed.');
        }
        redirect('/superadmin/admin-view?id=' . $id);
    }

    elseif ($action === 'reset_password') {
        $custom = trim((string) ($_POST['new_password'] ?? '')) ?: null;
        $force  = !empty($_POST['force_change']);
        $result = $svc->resetPassword((int) $superAdminUser['id'], $id, $custom, $force);
        if ($result['ok']) {
            $generatedPass = $result['password'] ?? null;
            flash('success', 'Password reset.');
        } else {
            $errors = $result['errors'];
        }
    }

    elseif ($action === 'force_change') {
        $result = $svc->forcePasswordChange((int) $superAdminUser['id'], $id);
        flash($result['ok'] ? 'success' : 'danger',
              $result['ok'] ? 'Password change required at next login.' : 'Action failed.');
        redirect('/superadmin/admin-view?id=' . $id);
    }
}

// Reload fresh row in case update happened this request
$admin = $repo->findById($id) ?? $admin;

$stats = $repo->taskStatsForAdmin($id);
$hasPendingWithdrawals = $repo->hasPendingWithdrawals($id);
$hasAnyWithdrawals     = $repo->hasAnyWithdrawals($id);
$hasAnySubmissions     = $repo->hasAnySubmissions($id);

$recentSubmissions = Database::selectAll(
    'SELECT s.id, s.tracking_id, s.status, s.admin_commission_amount, s.completed_at, s.created_at,
            sv.name AS service_name, u.full_name AS client_name
     FROM submissions s
     INNER JOIN services sv ON sv.id = s.service_id
     INNER JOIN users u ON u.id = s.user_id
     WHERE s.assigned_admin_id = :a
     ORDER BY s.created_at DESC
     LIMIT 15',
    [':a' => $id]
);

$commissionRows = Database::selectAll(
    'SELECT transaction_ref, type, direction, amount, balance_after, status, description, created_at
     FROM commission_transactions
     WHERE admin_id = :a
     ORDER BY id DESC
     LIMIT 15',
    [':a' => $id]
);

$withdrawalRows = Database::selectAll(
    'SELECT withdrawal_ref, amount, status, bank_name, account_number, created_at, paid_at
     FROM withdrawals
     WHERE admin_id = :a
     ORDER BY id DESC
     LIMIT 15',
    [':a' => $id]
);

$available = '0.00';
try { $available = money_sub((string) $admin['commission_balance'], (string) $admin['commission_reserved']); }
catch (\Throwable) {}

$pageTitle = $admin['full_name'];
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="small text-muted mb-1">
            <a class="text-decoration-none" href="<?= e(url('/superadmin/admins')) ?>">
                <i class="bi bi-arrow-left me-1"></i> Admins
            </a>
        </div>
        <h1 class="h4 mb-1"><?= e($admin['full_name']) ?></h1>
        <div class="small text-muted">
            @<?= e($admin['username']) ?> · <?= e($admin['email']) ?> · <?= e($admin['phone']) ?>
            · <span class="badge bg-<?= e(status_badge($admin['status'])) ?>"><?= e($admin['status']) ?></span>
            <?php if (!empty($admin['must_change_password'])): ?>
                · <span class="badge bg-warning text-dark">password change required</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($generatedPass): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">New password generated</div>
        <div class="small">
            Show this to the admin — it will not be displayed again:
            <code class="ms-1"><?= e($generatedPass) ?></code>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="sa-stat brand">
            <div class="label">Commission balance</div>
            <div class="value"><?= e(money($admin['commission_balance'])) ?></div>
            <div class="small text-muted mt-1">Available: <?= e(money($available)) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat amber">
            <div class="label">Reserved</div>
            <div class="value"><?= e(money($admin['commission_reserved'])) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat green">
            <div class="label">Completed jobs</div>
            <div class="value"><?= (int) $stats['completed'] ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="sa-stat red">
            <div class="label">Errored</div>
            <div class="value"><?= (int) $stats['error_detected'] ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left column: profile + actions -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Profile</strong></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="update">

                    <div class="mb-3">
                        <label class="form-label">Full name</label>
                        <input type="text" name="full_name" class="form-control" required maxlength="150"
                               value="<?= e($admin['full_name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" required maxlength="30"
                               value="<?= e($admin['phone']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" value="<?= e($admin['email']) ?>" disabled>
                        <div class="form-text">Email and username are fixed after creation.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" value="<?= e($admin['username']) ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max concurrent tasks</label>
                        <input type="number" name="max_concurrent_tasks" class="form-control" min="0"
                               value="<?= (int) $admin['max_concurrent_tasks'] ?>">
                        <div class="form-text">0 = unlimited.</div>
                    </div>
                    <button class="btn btn-brand w-100">
                        <i class="bi bi-save me-1"></i> Save profile
                    </button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Status</strong></div>
            <div class="card-body d-flex flex-wrap gap-2">
                <?php foreach (['active' => 'Activate', 'inactive' => 'Deactivate', 'suspended' => 'Suspend'] as $s => $label): ?>
                    <?php if ($admin['status'] !== $s): ?>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Set status to <?= e($s) ?>?<?= $s !== 'active' ? ' Any held tasks will be released to the pool.' : '' ?>');">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="status" value="<?= e($s) ?>">
                            <button class="btn btn-sm btn-outline-<?= $s === 'active' ? 'success' : ($s === 'suspended' ? 'danger' : 'secondary') ?>">
                                <?= e($label) ?>
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Password</strong></div>
            <div class="card-body">
                <form method="post" class="mb-3"
                      onsubmit="return confirm('Reset this admin\\'s password?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="reset_password">

                    <div class="mb-2">
                        <label class="form-label">New password</label>
                        <input type="text" name="new_password" class="form-control" minlength="8"
                               placeholder="Leave blank to auto-generate a strong one">
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="force_change" id="force_change" value="1" checked>
                        <label class="form-check-label small" for="force_change">
                            Require change at next login
                        </label>
                    </div>
                    <button class="btn btn-outline-danger w-100">
                        <i class="bi bi-key me-1"></i> Reset password
                    </button>
                </form>

                <?php if (empty($admin['must_change_password'])): ?>
                    <form method="post" onsubmit="return confirm('Force a password change at next login?');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="force_change">
                        <button class="btn btn-outline-secondary w-100">
                            <i class="bi bi-shield-lock me-1"></i> Force change at next login
                        </button>
                    </form>
                <?php else: ?>
                    <div class="small text-muted text-center">
                        <i class="bi bi-info-circle"></i> Password change already required.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Danger zone</strong></div>
            <div class="card-body">
                <p class="small text-muted">
                    Admins with any task or withdrawal history can only be deactivated, never
                    hard-deleted. This preserves historic ledger integrity.
                </p>
                <form method="post"
                      onsubmit="return confirm('Delete this admin? Admins with history are only deactivated.');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="status" value="inactive">
                    <input type="hidden" name="id" value="<?= (int) $admin['id'] ?>">
                    <button class="btn btn-outline-danger w-100" disabled>
                        <i class="bi bi-trash me-1"></i> Delete permanently
                    </button>
                    <div class="form-text text-center mt-1">
                        Use <strong>Deactivate</strong> above. Hard delete is disabled when the admin has any history.
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right column: history -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Recent submissions</strong></div>
            <div class="card-body p-0">
                <?php if (empty($recentSubmissions)): ?>
                    <div class="p-4 text-muted">No submissions.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tracking ID</th>
                                    <th>Service</th>
                                    <th>Client</th>
                                    <th class="text-end">Commission</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentSubmissions as $s): ?>
                                    <tr>
                                        <td>
                                            <a class="text-decoration-none"
                                               href="<?= e(url('/superadmin/submission-view?id=' . (int) $s['id'])) ?>">
                                                <code class="small"><?= e($s['tracking_id']) ?></code>
                                            </a>
                                        </td>
                                        <td class="small"><?= e($s['service_name']) ?></td>
                                        <td class="small text-muted"><?= e($s['client_name']) ?></td>
                                        <td class="text-end small"><?= e(money($s['admin_commission_amount'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= e(status_badge($s['status'])) ?>">
                                                <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted">
                                            <?= e(fmt_dt($s['completed_at'] ?: $s['created_at'], 'd M Y')) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Commission ledger</strong></div>
            <div class="card-body p-0">
                <?php if (empty($commissionRows)): ?>
                    <div class="p-4 text-muted">No commission transactions.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Balance after</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($commissionRows as $t): ?>
                                    <tr>
                                        <td><code class="small"><?= e($t['transaction_ref']) ?></code></td>
                                        <td class="small">
                                            <span class="badge bg-<?= $t['direction'] === 'credit' ? 'success' : 'secondary' ?>">
                                                <?= e(ucfirst($t['direction'])) ?>
                                            </span>
                                            <span class="d-block text-muted"><?= e(str_replace('_', ' ', $t['type'])) ?></span>
                                        </td>
                                        <td class="<?= $t['direction'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                                            <?= $t['direction'] === 'credit' ? '+' : '−' ?><?= e(money($t['amount'], '')) ?>
                                        </td>
                                        <td><?= e(money($t['balance_after'])) ?></td>
                                        <td class="small">
                                            <span class="badge bg-<?= e(status_badge($t['status'])) ?>"><?= e($t['status']) ?></span>
                                        </td>
                                        <td class="small text-muted"><?= e(fmt_dt($t['created_at'], 'd M Y')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong>Withdrawal history</strong>
                <?php if ($hasPendingWithdrawals): ?>
                    <span class="badge bg-warning text-dark ms-2">has pending withdrawals</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($withdrawalRows)): ?>
                    <div class="p-4 text-muted">No withdrawals.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Reference</th>
                                    <th>Amount</th>
                                    <th>Bank</th>
                                    <th>Status</th>
                                    <th>Requested</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($withdrawalRows as $w): ?>
                                    <tr>
                                        <td><code class="small"><?= e($w['withdrawal_ref']) ?></code></td>
                                        <td class="fw-semibold"><?= e(money($w['amount'])) ?></td>
                                        <td class="small">
                                            <?= e($w['bank_name']) ?><br>
                                            <span class="text-muted"><?= e($w['account_number']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= e(status_badge($w['status'])) ?>"><?= e($w['status']) ?></span>
                                        </td>
                                        <td class="small text-muted"><?= e(fmt_dt($w['created_at'], 'd M Y')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
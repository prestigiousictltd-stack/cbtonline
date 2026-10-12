<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\AdminRepository;
use App\Services\AdminManagementService;

$superAdminUser = Auth::requireSuperAdmin();

$repo  = new AdminRepository();
$svc   = new AdminManagementService();

$errors   = [];
$created  = null;   // ['email'=>..,'password'=>..]
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/admins');

    $action  = (string) ($_POST['action'] ?? '');
    $adminId = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $oldInput = $_POST;
        $result = $svc->create((int) $superAdminUser['id'], $_POST);
        if ($result['ok']) {
            $fresh = $repo->findById((int) $result['id']);
            $created = [
                'email'    => $fresh['email'] ?? '',
                'username' => $fresh['username'] ?? '',
                'password' => $result['password'] ?? null,
                'name'     => $fresh['full_name'] ?? '',
            ];
            flash('success', 'Admin account created.');
        } else {
            $errors = $result['errors'] ?? ['general' => 'Could not create the admin.'];
        }
    }

    elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? 'active');
        $result = $svc->setStatus((int) $superAdminUser['id'], $adminId, $status);
        if ($result['ok']) {
            $extra = !empty($result['released']) ? ' (' . (int) $result['released'] . ' tasks released)' : '';
            flash('success', 'Status updated.' . $extra);
        } else {
            flash('danger', $result['errors']['general'] ?? 'Could not update status.');
        }
        redirect('/superadmin/admins');
    }

    elseif ($action === 'force_change') {
        $result = $svc->forcePasswordChange((int) $superAdminUser['id'], $adminId);
        flash($result['ok'] ? 'success' : 'danger',
              $result['ok'] ? 'Admin will be required to change password at next login.' : 'Action failed.');
        redirect('/superadmin/admins');
    }

    elseif ($action === 'delete') {
        $result = $svc->delete((int) $superAdminUser['id'], $adminId);
        if ($result['ok']) {
            flash('success', !empty($result['soft'])
                ? 'Admin has financial or task history — deactivated instead of deleted.'
                : 'Admin deleted.');
        } else {
            flash('danger', $result['errors']['general'] ?? 'Could not delete the admin.');
        }
        redirect('/superadmin/admins');
    }
}

$page   = max(1, (int) ($_GET['page'] ?? 1));
$status = trim((string) ($_GET['status'] ?? ''));
$q      = trim((string) ($_GET['q'] ?? ''));

$pager = $repo->paginate(['status' => $status, 'q' => $q], $page, 20);

$pageTitle = 'Admins';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Administrators</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['total'] ?> admin(s).</p>
    </div>
    <button class="btn btn-brand btn-sm mt-2 mt-md-0" data-bs-toggle="modal" data-bs-target="#newAdminModal">
        <i class="bi bi-person-plus me-1"></i> Add admin
    </button>
</div>

<?php if ($created): ?>
    <div class="alert alert-success">
        <div class="fw-semibold mb-1">Admin account created</div>
        <div class="small">
            Email: <code><?= e($created['email']) ?></code><br>
            Username: <code><?= e($created['username']) ?></code>
            <?php if ($created['password']): ?>
                <br>Password: <code><?= e($created['password']) ?></code>
                <div class="text-muted mt-1">
                    <i class="bi bi-info-circle me-1"></i>
                    This password is shown only once. The admin will be required to change it at first login.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['active','inactive','suspended'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Name, email, username or phone" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/admins')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">No admins match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th class="text-center">Jobs</th>
                            <th class="text-end">Commission</th>
                            <th class="text-center">Withdrawals</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $a):
                            $available = '0.00';
                            try { $available = money_sub((string) $a['commission_balance'], (string) $a['commission_reserved']); }
                            catch (\Throwable) {}
                        ?>
                            <tr>
                                <td>
                                    <a class="text-decoration-none fw-semibold small"
                                       href="<?= e(url('/superadmin/admin-view?id=' . (int) $a['id'])) ?>">
                                        <?= e($a['full_name']) ?>
                                    </a>
                                    <div class="small text-muted">@<?= e($a['username']) ?></div>
                                </td>
                                <td class="small">
                                    <?= e($a['email']) ?><br>
                                    <span class="text-muted"><?= e($a['phone']) ?></span>
                                </td>
                                <td class="text-center small">
                                    <span class="badge bg-success"><?= (int) $a['jobs_completed'] ?> done</span>
                                    <span class="badge bg-warning text-dark"><?= (int) $a['jobs_processing'] ?> active</span>
                                </td>
                                <td class="text-end small">
                                    <div class="fw-semibold"><?= e(money($a['commission_balance'])) ?></div>
                                    <div class="text-muted">avail <?= e(money($available)) ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if ((int) $a['pending_withdrawals'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?= (int) $a['pending_withdrawals'] ?> pending</span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($a['status'])) ?>"><?= e($a['status']) ?></span>
                                    <?php if (!empty($a['must_change_password'])): ?>
                                        <div class="small text-warning mt-1">
                                            <i class="bi bi-key"></i> change required
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/superadmin/admin-view?id=' . (int) $a['id'])) ?>">
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <?php if ($a['status'] !== 'active'): ?>
                                        <form method="post" class="d-inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="status">
                                            <input type="hidden" name="status" value="active">
                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                            <button class="btn btn-sm btn-outline-success" title="Activate">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($a['status'] === 'active'): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Deactivate this admin? Any in-flight tasks will be released to the pool.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="status">
                                            <input type="hidden" name="status" value="inactive">
                                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary" title="Deactivate">
                                                <i class="bi bi-pause-fill"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Delete this admin? Admins with any task or withdrawal history are only deactivated.');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
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
                        <?php $qs = array_filter(['page' => $i, 'status' => $status, 'q' => $q]); ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/admins?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- New admin modal -->
<div class="modal fade" id="newAdminModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" novalidate>
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title">New admin account</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required maxlength="150"
                               value="<?= e($oldInput['full_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control" required maxlength="30"
                               value="<?= e($oldInput['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required maxlength="190"
                               value="<?= e($oldInput['email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required maxlength="60"
                               pattern="[a-z0-9_\.]{3,60}"
                               value="<?= e($oldInput['username'] ?? '') ?>">
                        <div class="form-text">3–60 chars; a–z, 0–9, dot or underscore.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password</label>
                        <input type="text" name="password" class="form-control" minlength="8"
                               placeholder="Leave blank to auto-generate">
                        <div class="form-text">If blank, a strong password is generated and shown once.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm password</label>
                        <input type="text" name="confirm_password" class="form-control" minlength="8">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Max concurrent tasks</label>
                        <input type="number" name="max_concurrent_tasks" class="form-control" min="0"
                               value="<?= e($oldInput['max_concurrent_tasks'] ?? '0') ?>">
                        <div class="form-text">0 = unlimited.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="must_change_password"
                                   id="must_change_password" value="1"
                                <?= !empty($oldInput['must_change_password']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="must_change_password">
                                Require password change at first login
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Create admin</button>
            </div>
        </form>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\ClientRepository;

$superAdminUser = Auth::requireSuperAdmin();

$repo = new ClientRepository();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/clients');
    $uid    = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $status = match ($action) {
        'activate'   => 'active',
        'deactivate' => 'inactive',
        'suspend'    => 'suspended',
        default      => null,
    };
    if ($status && $uid > 0) {
        $repo->setStatus($uid, $status);
        (new \App\Services\AuditService())->log(
            'CLIENT_STATUS_CHANGED', 'user', $uid,
            "Client status changed to {$status}",
            userType: 'super_admin', userId: (int) $superAdminUser['id']
        );
        flash('success', 'Client status updated.');
    }
    redirect('/superadmin/clients');
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$status  = trim((string) ($_GET['status'] ?? ''));
$q       = trim((string) ($_GET['q'] ?? ''));

$pager = $repo->paginate(['status' => $status, 'q' => $q], $page, 20);

$pageTitle = 'Clients';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Clients</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['total'] ?> client(s).</p>
    </div>
</div>

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
                       placeholder="Name, email or phone" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/clients')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">No clients match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th>Wallet</th>
                            <th>Submissions</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $u): ?>
                            <tr>
                                <td>
                                    <a class="text-decoration-none fw-semibold small"
                                       href="<?= e(url('/superadmin/client-view?id=' . (int) $u['id'])) ?>">
                                        <?= e($u['full_name']) ?>
                                    </a>
                                    <?php if (!empty($u['state'])): ?>
                                        <div class="small text-muted"><?= e($u['state']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?= e($u['email']) ?><br>
                                    <span class="text-muted"><?= e($u['phone']) ?></span>
                                </td>
                                <td class="fw-semibold"><?= e(money($u['wallet_balance'])) ?></td>
                                <td><?= (int) $u['submissions_count'] ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($u['status'])) ?>"><?= e($u['status']) ?></span>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($u['created_at'], 'd M Y')) ?></td>
                                <td class="text-end">
                                    <?php if ($u['status'] !== 'active'): ?>
                                        <form method="post" class="d-inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="activate">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <button class="btn btn-sm btn-success">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($u['status'] !== 'inactive'): ?>
                                        <form method="post" class="d-inline">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($u['status'] !== 'suspended'): ?>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Suspend this client? They will not be able to sign in.');">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="action" value="suspend">
                                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Suspend</button>
                                        </form>
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
                        <?php $qs = array_filter(['page' => $i, 'status' => $status, 'q' => $q]); ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/superadmin/clients?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
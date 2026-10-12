<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Repositories\ServiceFieldRepository;
use App\Repositories\ServiceRepository;
use App\Services\ServiceAdminService;

$superAdminUser = Auth::requireSuperAdmin();

$svcRepo   = new ServiceRepository();
$fieldRepo = new ServiceFieldRepository();
$admin     = new ServiceAdminService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/services');

    $id     = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle' && $id > 0) {
        $current = $svcRepo->findById($id);
        if ($current) {
            $next = $current['status'] === 'active' ? 'inactive' : 'active';
            $result = $admin->setServiceStatus((int) $superAdminUser['id'], $id, $next);
            if ($result['ok']) {
                flash('success', 'Service status changed to ' . $next . '.');
            } else {
                flash('danger', $result['errors']['general'] ?? 'Could not change status.');
            }
        }
    }
    redirect('/superadmin/services');
}

$page   = max(1, (int) ($_GET['page'] ?? 1));
$q      = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

$pager = $svcRepo->paginate(['q' => $q, 'status' => $status], $page, 30);
$counts = $svcRepo->countsPerService();

$pageTitle = 'Services';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Services</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['total'] ?> service(s) configured.</p>
    </div>
    <a href="<?= e(url('/superadmin/service-edit')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-plus-circle me-1"></i> New service
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Name, slug or description" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(url('/superadmin/services')) ?>"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">No services match.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Service</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Commission</th>
                            <th>Duration</th>
                            <th class="text-center">Fields</th>
                            <th>Jobs</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $s):
                            $sid       = (int) $s['id'];
                            $fieldCnt  = $fieldRepo->countForService($sid, true);
                            $pending   = $counts[$sid]['pending']    ?? 0;
                            $processing= $counts[$sid]['processing'] ?? 0;
                            $completed = $counts[$sid]['completed']  ?? 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= e($s['name']) ?></div>
                                    <div class="small text-muted">
                                        <code><?= e($s['slug']) ?></code>
                                    </div>
                                </td>
                                <td class="text-end fw-semibold"><?= e(money($s['price'])) ?></td>
                                <td class="text-end text-success"><?= e(money($s['admin_commission'])) ?></td>
                                <td class="small">
                                    <?= e($s['duration_label'] ?: human_duration((int) $s['processing_minutes'])) ?>
                                    <div class="text-muted"><?= (int) $s['processing_minutes'] ?> min</div>
                                </td>
                                <td class="text-center">
                                    <a class="small" href="<?= e(url('/superadmin/service-fields?service=' . $sid)) ?>">
                                        <?= (int) $fieldCnt ?> fields
                                    </a>
                                </td>
                                <td class="small text-muted">
                                    <?= (int) $pending ?> pending ·
                                    <?= (int) $processing ?> in progress ·
                                    <?= (int) $completed ?> done
                                </td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($s['status'])) ?>"><?= e($s['status']) ?></span>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= e(url('/superadmin/service-fields?service=' . $sid)) ?>">
                                        <i class="bi bi-list-check"></i>
                                    </a>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/superadmin/service-edit?id=' . $sid)) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline"
                                          onsubmit="return confirm('Change service status?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= $sid ?>">
                                        <button class="btn btn-sm btn-<?= $s['status'] === 'active' ? 'outline-danger' : 'outline-success' ?>">
                                            <i class="bi <?= $s['status'] === 'active' ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i>
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
                            <a class="page-link" href="<?= e(url('/superadmin/services?' . http_build_query($qs))) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\AdminQueueService;

$adminUser = Auth::requireAdmin();
$page      = max(1, (int) ($_GET['page'] ?? 1));

$svc   = new AdminQueueService();
$pager = $svc->completedJobsForAdmin((int) $adminUser['id'], $page, 20);

// Quick filter by tracking id from ?q=
$q = trim((string) ($_GET['q'] ?? ''));

$rows = $pager['rows'];
if ($q !== '') {
    $rows = array_values(array_filter($rows, fn($r) =>
        stripos((string) $r['tracking_id'], $q) !== false
    ));
}

$pageTitle = 'My completed jobs';
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">My completed jobs</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['total'] ?> jobs completed</p>
    </div>
    <form class="d-flex gap-2 mt-2 mt-md-0" method="get">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search tracking ID" value="<?= e($q) ?>">
        <button class="btn btn-sm btn-brand">Search</button>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-check2-square fs-2 d-block mb-2"></i>
                No completed jobs yet.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tracking ID</th>
                            <th>Service</th>
                            <th>Client</th>
                         
                            <th>Commission</th>
                            <th>Completed</th>
                             <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><code class="small"><?= e($r['tracking_id']) ?></code></td>
                                <td class="small fw-semibold"><?= e($r['service_name']) ?></td>
                                <td>
                                    <div class="small"><?= e($r['client_name']) ?></div>
                                    <div class="small text-muted"><?= e($r['client_email']) ?></div>
                                </td>
                                
                                <td class="text-success fw-semibold">+<?= e(money($r['admin_commission_amount'], '')) ?></td>
                                <td class="small text-muted"><?= e(fmt_dt($r['completed_at'])) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary"
                                    href="<?= e(url('/admin/completed-job-view?id=' . (int) $r['id'])) ?>">
                                        <i class="bi bi-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($q === '' && $pager['pages'] > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e(url('/admin/completed-jobs?page=' . $i)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
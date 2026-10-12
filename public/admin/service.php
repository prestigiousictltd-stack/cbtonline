<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\ServiceRepository;
use App\Services\AdminQueueService;

$adminUser = Auth::requireAdmin();
(new App\Services\TaskLockService())->sweepExpired();
$serviceId = (int) ($_GET['id'] ?? $_GET['service_id'] ?? 0);
if ($serviceId <= 0) {
    flash('warning', 'Please choose a service from the sidebar.');
    redirect('/admin/dashboard');
}

$service = (new ServiceRepository())->findById($serviceId);
if (!$service || $service['status'] !== 'active') {
    flash('danger', 'Service not found or is inactive.');
    redirect('/admin/dashboard');
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'status' => (string) ($_GET['status'] ?? ''),
    'q'      => trim((string) ($_GET['q'] ?? '')),
];

$queueSvc = new AdminQueueService();
$pager    = $queueSvc->queueForService($serviceId, $filters, $page, 20);
$counts   = $queueSvc->countsForService($serviceId);

$adminSidebarServices = (new ServiceRepository())->allActive();

$pageTitle = $service['name'];
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <div class="text-muted small mb-1">
            <a href="<?= e(url('/admin/dashboard')) ?>" class="text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> Dashboard
            </a>
        </div>
        <h1 class="h4 mb-1"><?= e($service['name']) ?></h1>
        <div class="text-muted small">
            Task queue ·
            <strong class="text-warning"><?= (int) $counts['pending'] ?> pending</strong>,
            <strong class="text-primary"><?= (int) $counts['processing'] ?> processing</strong>,
            <strong class="text-success"><?= (int) $counts['completed'] ?> completed</strong>
            <?php if ($counts['error_detected']): ?>
                , <strong class="text-danger"><?= (int) $counts['error_detected'] ?> errored</strong>
            <?php endif; ?>
        </div>
    </div>
    <div class="text-end mt-2 mt-md-0">
        <div class="small text-muted">Commission per completed task</div>
        <div class="h5 mb-0"><?= e(money($service['admin_commission'])) ?></div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="id" value="<?= (int) $serviceId ?>">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Tracking ID, client name or email" value="<?= e($filters['q']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <option value="pending"        <?= $filters['status'] === 'pending'        ? 'selected' : '' ?>>Pending</option>
                    <option value="processing"     <?= $filters['status'] === 'processing'     ? 'selected' : '' ?>>Processing</option>
                    <option value="completed"      <?= $filters['status'] === 'completed'      ? 'selected' : '' ?>>Completed</option>
                    <option value="error_detected" <?= $filters['status'] === 'error_detected' ? 'selected' : '' ?>>Error detected</option>
                    <option value="cancelled"      <?= $filters['status'] === 'cancelled'      ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <?php if ($filters['status'] || $filters['q']): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/service?id=' . $serviceId)) ?>">
                        <i class="bi bi-x-lg"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <?php if (empty($pager['rows'])): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                No tasks match your filters.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table queue-table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tracking ID</th>
                            <th>Client</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Time remaining</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $row):
                            $isMine      = (int) $row['assigned_admin_id'] === (int) $adminUser['id'];
                            $isOther     = $row['assigned_admin_id'] !== null && !$isMine;
                            $canProcess  = $row['status'] === 'pending';
                            $canContinue = $row['status'] === 'processing' && $isMine;

                            $rowClass = match ($row['status']) {
                                'pending'    => 'queue-row-pending',
                                'processing' => 'queue-row-processing',
                                default      => '',
                            };
                        ?>
                            <tr class="<?= e($rowClass) ?>">
                                <td><code class="small"><?= e($row['tracking_id']) ?></code></td>
                                <td>
                                    <div class="small fw-semibold"><?= e($row['client_name']) ?></div>
                                    <div class="small text-muted"><?= e($row['client_email']) ?></div>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($row['created_at'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($row['status'])) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $row['status']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'processing' && $row['seconds_remaining'] !== null): ?>
                                        <span class="countdown <?= (int) $row['seconds_remaining'] < 3600 ? 'urgent' : '' ?>"
                                              data-deadline-utc="<?= e((string) ($row['deadline_at'] ? strtotime($row['deadline_at'] . ' UTC') : '')) ?>">
                                            <?= e(human_countdown((int) $row['seconds_remaining'])) ?>
                                        </span>
                                        <?php if ($isOther): ?>
                                            <div class="locked-label">
                                                <i class="bi bi-lock-fill"></i>
                                                by <?= e($row['assigned_admin_name'] ?? 'another admin') ?>
                                            </div>
                                        <?php elseif ($isMine): ?>
                                            <div class="locked-label"><i class="bi bi-person-check"></i> your task</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($canProcess): ?>
                                        <button type="button" class="btn btn-sm btn-brand"
                                                data-claim-id="<?= (int) $row['id'] ?>"
                                                data-claim-tracking="<?= e($row['tracking_id']) ?>"
                                                title="Click to claim task">
                                            <i class="bi bi-play-fill me-1"></i> PROCESS
                                        </button>
                                   <?php elseif ($canContinue): ?>
                                        <a class="btn btn-sm btn-primary"
                                        href="<?= e(url('/admin/process-task?id=' . (int) $row['id'])) ?>"
                                        title="Return to this task">
                                            <i class="bi bi-hourglass-split me-1"></i> Continue
                                        </a>
                                    <?php elseif ($row['status'] === 'completed'): ?>
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= e(url('/admin/completed-jobs?q=' . urlencode($row['tracking_id']))) ?>">
                                            <i class="bi bi-eye me-1"></i> View
                                        </a>
                                    <?php elseif ($row['status'] === 'error_detected'): ?>
                                        <span class="badge bg-danger-subtle text-danger small">
                                            <i class="bi bi-exclamation-triangle"></i> Error reported
                                        </span>
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
            <?php
            $buildPage = function (int $p) use ($serviceId, $filters): string {
                $qs = array_filter([
                    'id'     => $serviceId,
                    'page'   => $p,
                    'status' => $filters['status'],
                    'q'      => $filters['q'],
                ]);
                return url('/admin/service?' . http_build_query($qs));
            };
            ?>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= $pager['page'] <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($buildPage(max(1, $pager['page'] - 1))) ?>">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $pager['pages']; $i++): ?>
                        <li class="page-item <?= $i === $pager['page'] ? 'active' : '' ?>">
                            <a class="page-link" href="<?= e($buildPage($i)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pager['page'] >= $pager['pages'] ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= e($buildPage(min($pager['pages'], $pager['page'] + 1))) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const csrfToken = <?= json_encode(\App\Core\Csrf::token()) ?>;
    const claimUrl  = <?= json_encode(url('/api/claim-task')) ?>;

    document.querySelectorAll('[data-claim-id]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id = btn.dataset.claimId;
            if (!id) return;

            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Claiming…';

            try {
                const body = new URLSearchParams();
                body.append('submission_id', id);
             body.append('<?= \CSRF_FIELD_NAME ?>', csrfToken);
                const res = await fetch(claimUrl, {
                    method:      'POST',
                    credentials: 'same-origin',
                    headers:     { 'X-CSRF-Token': csrfToken, 'X-Requested-With': 'fetch' },
                    body,
                });
                const data = await res.json().catch(() => ({ ok: false, error: 'BAD_JSON' }));

                if (res.ok && data.ok) {
                    // Redirect straight to the processing page
                    window.location.href = data.redirect;
                    return;
                }

                if (res.status === 409 && data.error === 'ALREADY_CLAIMED') {
                    btn.classList.remove('btn-brand');
                    btn.classList.add('btn-outline-secondary');
                    btn.disabled = true;
                    btn.innerHTML = '<i class="bi bi-lock-fill me-1"></i> Locked'
                        + (data.assigned_admin_name ? ' by ' + data.assigned_admin_name : '');
                    return;
                }

                // Other errors — restore the button and inform
                btn.disabled = false;
                btn.innerHTML = original;
                alert(data.message || data.error || 'Could not claim this task.');
            } catch (e) {
                btn.disabled = false;
                btn.innerHTML = original;
                alert('Network error. Please try again.');
            }
        });
    });
})();
</script>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
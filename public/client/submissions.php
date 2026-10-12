<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\ServiceRepository;
use App\Repositories\SubmissionRepository;

$clientUser = Auth::requireClient();

$page    = max(1, (int) ($_GET['page'] ?? 1));
$filters = [
    'status'     => (string) ($_GET['status'] ?? ''),
    'service_id' => (int)    ($_GET['service_id'] ?? 0),
    'q'          => trim((string) ($_GET['q'] ?? '')),
];

$subRepo     = new SubmissionRepository();
$serviceRepo = new ServiceRepository();

$pager    = $subRepo->paginateForUser((int) $clientUser['id'], $filters, $page, 20);
$services = $serviceRepo->all();

$pageTitle = 'My Submissions';
require APP_ROOT . '/app/Views/layouts/client-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">My submissions</h1>
        <p class="text-muted small mb-0"><?= (int) $pager['total'] ?> total submissions</p>
    </div>
    <a href="<?= e(url('/client/services')) ?>" class="btn btn-brand btn-sm mt-2 mt-md-0">
        <i class="bi bi-plus-circle me-1"></i> Back to Services
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Tracking ID or service" value="<?= e($filters['q']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach ([
                        SUB_PENDING    => 'Pending',
                        SUB_PROCESSING => 'Processing',
                        SUB_COMPLETED  => 'Completed',
                        SUB_ERROR      => 'Error detected',
                        SUB_CANCELLED  => 'Cancelled',
                    ] as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Service</label>
                <select name="service_id" class="form-select form-select-sm">
                    <option value="">All services</option>
                    <?php foreach ($services as $svc): ?>
                        <option value="<?= (int) $svc['id'] ?>" <?= $filters['service_id'] === (int) $svc['id'] ? 'selected' : '' ?>>
                            <?= e($svc['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-brand flex-grow-1">Filter</button>
                <?php if ($filters['status'] || $filters['service_id'] || $filters['q']): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/client/submissions')) ?>">
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
                No submissions match your filters.
                <div class="mt-2">
                    <a href="<?= e(url('/client/services')) ?>">Browse services</a>
                </div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tracking ID</th>
                            <th>Applicant</th>
                            <th>Service</th>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Last update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pager['rows'] as $s): ?>
                            <tr>
                               <td><code class="small"><?= e($s['tracking_id']) ?></code></td>
                                <td>
                                    <div class="small fw-semibold text-truncate" style="max-width:180px"
                                        title="<?= e($s['applicant_name'] ?? '') ?>">
                                        <?= e($s['applicant_name'] ?? '—') ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold small"><?= e($s['service_name']) ?></div>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($s['created_at'])) ?></td>
                                <td class="fw-semibold"><?= e(money($s['price_charged'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= e(status_badge($s['status'])) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $s['status']))) ?>
                                    </span>
                                </td>
                                <td class="small text-muted"><?= e(fmt_dt($s['updated_at'])) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="<?= e(url('/client/submission-view?id=' . (int) $s['id'])) ?>">
                                        View
                                    </a>
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
            $buildPage = function (int $p) use ($filters): string {
                $qs = array_filter([
                    'page'       => $p,
                    'status'     => $filters['status'],
                    'service_id' => $filters['service_id'] ?: '',
                    'q'          => $filters['q'],
                ]);
                return url('/client/submissions?' . http_build_query($qs));
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

<?php require APP_ROOT . '/app/Views/layouts/client-bottom.php'; ?>
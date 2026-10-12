<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\PlatformStatsService;
use App\Services\ReportService;

$superAdminUser = Auth::requireSuperAdmin();

$preset = (string) ($_GET['range'] ?? 'month');
$fromRaw = (string) ($_GET['from'] ?? '');
$toRaw   = (string) ($_GET['to']   ?? '');

$stats   = new PlatformStatsService();
$range   = $stats->resolveRange($preset, $fromRaw ?: null, $toRaw ?: null);

$svc = new ReportService();
$revenueBySvc   = $svc->revenueByService($range['from'], $range['to']);
$revenueByDay   = $svc->revenueByDay($range['from'], $range['to']);
$clients        = $svc->clientReport($range['from'], $range['to']);
$adminsReport   = $svc->adminReport($range['from'], $range['to']);
$servicesUsage  = $svc->serviceUsageReport($range['from'], $range['to']);

$pageTitle = 'Reports';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h4 mb-1">Reports</h1>
        <p class="text-muted small mb-0">
            <?php if ($preset === 'custom' && $fromRaw && $toRaw): ?>
                Custom: <?= e($fromRaw) ?> → <?= e($toRaw) ?>
            <?php elseif ($preset !== 'all'): ?>
                Range: <span class="text-capitalize"><?= e($preset) ?></span>
            <?php else: ?>
                All time
            <?php endif; ?>
        </p>
    </div>
    <form class="sa-filterbar d-flex flex-wrap gap-2 align-items-end mt-2 mt-md-0" method="get">
        <div>
            <label class="form-label small text-muted mb-1">Range</label>
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ([
                    'today' => 'Today','week' => 'This week','month' => 'This month',
                    'year' => 'This year','custom' => 'Custom range','all' => 'All time',
                ] as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $preset === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($preset === 'custom'): ?>
            <div>
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromRaw) ?>">
            </div>
            <div>
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toRaw) ?>">
            </div>
            <button class="btn btn-sm btn-brand">Apply</button>
        <?php endif; ?>
    </form>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-revenue">Revenue</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-clients">Clients</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-admins">Admins</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-services">Services</a>
    </li>
</ul>

<div class="tab-content">

    <!-- Revenue -->
    <div class="tab-pane fade show active" id="tab-revenue">
        <div class="d-flex justify-content-end mb-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(url('/superadmin/export?type=revenue_by_service&' . http_build_query(['from' => $range['from'], 'to' => $range['to']]))) ?>">
                <i class="bi bi-download me-1"></i> Export by service (CSV)
            </a>
            <a class="btn btn-sm btn-outline-secondary ms-2"
               href="<?= e(url('/superadmin/export?type=revenue_by_day&' . http_build_query(['from' => $range['from'], 'to' => $range['to']]))) ?>">
                <i class="bi bi-download me-1"></i> Export by day (CSV)
            </a>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white"><strong>Revenue by service</strong></div>
            <div class="card-body p-0">
                <?php if (empty($revenueBySvc)): ?>
                    <div class="p-5 text-center text-muted">No completed submissions in this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Service</th>
                                    <th class="text-end">Jobs</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Commission</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $totG = '0.00'; $totC = '0.00'; $totR = '0.00'; $totJ = 0; ?>
                                <?php foreach ($revenueBySvc as $r):
                                    $totJ += (int) $r['jobs'];
                                    $totG = money_add($totG, $r['gross']);
                                    $totC = money_add($totC, $r['commission']);
                                    $totR = money_add($totR, $r['revenue']);
                                ?>
                                    <tr>
                                        <td><?= e($r['name']) ?></td>
                                        <td class="text-end"><?= (int) $r['jobs'] ?></td>
                                        <td class="text-end"><?= e(money($r['gross'])) ?></td>
                                        <td class="text-end text-danger">−<?= e(money($r['commission'], '')) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= e(money($r['revenue'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Totals</th>
                                    <th class="text-end"><?= (int) $totJ ?></th>
                                    <th class="text-end"><?= e(money($totG)) ?></th>
                                    <th class="text-end text-danger">−<?= e(money($totC, '')) ?></th>
                                    <th class="text-end text-success"><?= e(money($totR)) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><strong>Revenue by day</strong></div>
            <div class="card-body p-0">
                <?php if (empty($revenueByDay)): ?>
                    <div class="p-5 text-center text-muted">No completed submissions in this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date (UTC)</th>
                                    <th class="text-end">Jobs</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($revenueByDay as $r): ?>
                                    <tr>
                                        <td><?= e($r['d']) ?></td>
                                        <td class="text-end"><?= (int) $r['jobs'] ?></td>
                                        <td class="text-end"><?= e(money($r['gross'])) ?></td>
                                        <td class="text-end fw-semibold text-success"><?= e(money($r['revenue'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Clients -->
    <div class="tab-pane fade" id="tab-clients">
        <div class="d-flex justify-content-end mb-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(url('/superadmin/export?type=clients&' . http_build_query(['from' => $range['from'], 'to' => $range['to']]))) ?>">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($clients)): ?>
                    <div class="p-5 text-center text-muted">No clients in this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Client</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th class="text-end">Wallet</th>
                                    <th class="text-end">Funded</th>
                                    <th class="text-end">Spent</th>
                                    <th class="text-end">Submissions</th>
                                    <th>Joined</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $u): ?>
                                    <tr>
                                        <td class="small"><?= e($u['full_name']) ?></td>
                                        <td class="small">
                                            <?= e($u['email']) ?><br>
                                            <span class="text-muted"><?= e($u['phone']) ?></span>
                                        </td>
                                        <td class="small">
                                            <span class="badge bg-<?= e(status_badge($u['status'])) ?>"><?= e($u['status']) ?></span>
                                        </td>
                                        <td class="text-end"><?= e(money($u['wallet_balance'])) ?></td>
                                        <td class="text-end text-success"><?= e(money($u['total_funded'])) ?></td>
                                        <td class="text-end text-danger"><?= e(money($u['total_spent'])) ?></td>
                                        <td class="text-end"><?= (int) $u['submissions'] ?></td>
                                        <td class="small text-muted"><?= e(fmt_dt($u['created_at'], 'd M Y')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Admins -->
    <div class="tab-pane fade" id="tab-admins">
        <div class="d-flex justify-content-end mb-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(url('/superadmin/export?type=admins&' . http_build_query(['from' => $range['from'], 'to' => $range['to']]))) ?>">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($adminsReport)): ?>
                    <div class="p-5 text-center text-muted">No admins.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Admin</th>
                                    <th>Status</th>
                                    <th class="text-end">Jobs done (range)</th>
                                    <th class="text-end">Active</th>
                                    <th class="text-end">Commission earned</th>
                                    <th class="text-end">Withdrawn</th>
                                    <th class="text-end">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminsReport as $a): ?>
                                    <tr>
                                        <td class="small">
                                            <a class="text-decoration-none" href="<?= e(url('/superadmin/admin-view?id=' . (int) $a['id'])) ?>">
                                                <?= e($a['full_name']) ?>
                                            </a>
                                            <div class="text-muted">@<?= e($a['username']) ?></div>
                                        </td>
                                        <td class="small">
                                            <span class="badge bg-<?= e(status_badge($a['status'])) ?>"><?= e($a['status']) ?></span>
                                        </td>
                                        <td class="text-end"><?= (int) $a['jobs_completed'] ?></td>
                                        <td class="text-end"><?= (int) $a['jobs_processing'] ?></td>
                                        <td class="text-end"><?= e(money($a['commission_earned'])) ?></td>
                                        <td class="text-end"><?= e(money($a['commission_withdrawn'])) ?></td>
                                        <td class="text-end fw-semibold"><?= e(money($a['commission_balance'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Services -->
    <div class="tab-pane fade" id="tab-services">
        <div class="d-flex justify-content-end mb-2">
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(url('/superadmin/export?type=services&' . http_build_query(['from' => $range['from'], 'to' => $range['to']]))) ?>">
                <i class="bi bi-download me-1"></i> Export CSV
            </a>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <?php if (empty($servicesUsage)): ?>
                    <div class="p-5 text-center text-muted">No services.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Service</th>
                                    <th>Status</th>
                                    <th class="text-end">Submissions</th>
                                    <th class="text-end">Pending</th>
                                    <th class="text-end">Processing</th>
                                    <th class="text-end">Completed</th>
                                    <th class="text-end">Errored</th>
                                    <th class="text-end">Gross</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($servicesUsage as $s): ?>
                                    <tr>
                                        <td class="small"><?= e($s['name']) ?></td>
                                        <td class="small">
                                            <span class="badge bg-<?= e(status_badge($s['service_status'])) ?>"><?= e($s['service_status']) ?></span>
                                        </td>
                                        <td class="text-end"><?= (int) $s['submissions'] ?></td>
                                        <td class="text-end"><?= (int) $s['pending'] ?></td>
                                        <td class="text-end"><?= (int) $s['processing'] ?></td>
                                        <td class="text-end"><?= (int) $s['completed'] ?></td>
                                        <td class="text-end"><?= (int) $s['errored'] ?></td>
                                        <td class="text-end"><?= e(money($s['gross'])) ?></td>
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
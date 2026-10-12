<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Services\PlatformStatsService;
use App\Services\TaskLockService;

$superAdminUser = Auth::requireSuperAdmin();

// Lazy release — the dashboard is a natural chokepoint for the sweep.
(new TaskLockService())->sweepExpired();

$rangePreset = (string) ($_GET['range'] ?? 'all');
$customFrom  = (string) ($_GET['from']  ?? '');
$customTo    = (string) ($_GET['to']    ?? '');

$statsSvc = new PlatformStatsService();
$range    = $statsSvc->resolveRange($rangePreset, $customFrom, $customTo);

$summary      = $statsSvc->summary($range);
$revenueBySvc = $statsSvc->revenueByService($range, 8);

$pageTitle = 'Dashboard';
require APP_ROOT . '/app/Views/layouts/superadmin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
    <div>
        <h1 class="h4 mb-1">Platform overview</h1>
        <p class="text-muted small mb-0">
            <?php if ($rangePreset === 'custom' && $customFrom && $customTo): ?>
                Custom range: <?= e($customFrom) ?> → <?= e($customTo) ?>
            <?php elseif ($rangePreset !== 'all'): ?>
                Range: <span class="text-capitalize"><?= e($rangePreset) ?></span>
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
                    'today'  => 'Today',
                    'week'   => 'This week',
                    'month'  => 'This month',
                    'year'   => 'This year',
                    'custom' => 'Custom range',
                    'all'    => 'All time',
                ] as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $rangePreset === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($rangePreset === 'custom'): ?>
            <div>
                <label class="form-label small text-muted mb-1">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($customFrom) ?>">
            </div>
            <div>
                <label class="form-label small text-muted mb-1">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($customTo) ?>">
            </div>
            <button class="btn btn-sm btn-brand">Apply</button>
        <?php endif; ?>
    </form>
</div>

<!-- Financial tiles -->
<h6 class="text-uppercase text-muted small mb-3">Financials</h6>
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="sa-stat brand">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Platform revenue</div>
                    <div class="value"><?= e(money($summary['financial']['revenue'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-graph-up-arrow"></i></span>
            </div>
            <div class="small text-muted mt-2">Service charges − admin commission</div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="sa-stat green">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Wallet funding</div>
                    <div class="value"><?= e(money($summary['financial']['total_wallet_funding'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-wallet2"></i></span>
            </div>
            <div class="small text-muted mt-2">All-time client wallet balance: <?= e(money($summary['clients']['wallet_total'])) ?></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="sa-stat amber">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Admin commission</div>
                    <div class="value"><?= e(money($summary['financial']['total_commission'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-cash-coin"></i></span>
            </div>
            <div class="small text-muted mt-2">Withdrawn: <?= e(money($summary['financial']['total_withdrawals'])) ?></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="sa-stat purple">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Service charges</div>
                    <div class="value"><?= e(money($summary['financial']['total_service_charges'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-receipt"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Clients & Admins -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <h6 class="text-uppercase text-muted small mb-3">Clients</h6>
        <div class="row g-3">
            <div class="col-6">
                <div class="sa-stat brand">
                    <div class="label">Total</div>
                    <div class="value"><?= (int) $summary['clients']['total'] ?></div>
                    <div class="small text-muted mt-1">
                        <?= (int) $summary['new_clients'] ?> new in range
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="sa-stat green">
                    <div class="label">Active</div>
                    <div class="value"><?= (int) $summary['clients']['active'] ?></div>
                    <div class="small text-muted mt-1">
                        <?= (int) $summary['clients']['inactive'] ?> inactive ·
                        <?= (int) $summary['clients']['suspended'] ?> suspended
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <h6 class="text-uppercase text-muted small mb-3">Admins</h6>
        <div class="row g-3">
            <div class="col-6">
                <div class="sa-stat cyan">
                    <div class="label">Total admins</div>
                    <div class="value"><?= (int) $summary['admins']['total'] ?></div>
                    <div class="small text-muted mt-1"><?= (int) $summary['admins']['active'] ?> active</div>
                </div>
            </div>
            <div class="col-6">
                <div class="sa-stat amber">
                    <div class="label">Jobs completed</div>
                    <div class="value"><?= (int) $summary['admins']['jobs_completed'] ?></div>
                    <div class="small text-muted mt-1">
                        Pending withdrawals: <?= (int) $summary['admins']['pending_withdrawals'] ?>
                        (<?= e(money($summary['admins']['pending_withdrawals_amount'])) ?>)
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submissions -->
<h6 class="text-uppercase text-muted small mb-3">Submissions in range</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="sa-stat amber">
            <div class="label">Pending</div>
            <div class="value"><?= (int) $summary['services']['pending'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sa-stat brand">
            <div class="label">Processing</div>
            <div class="value"><?= (int) $summary['services']['processing'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sa-stat green">
            <div class="label">Completed</div>
            <div class="value"><?= (int) $summary['services']['completed'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="sa-stat red">
            <div class="label">Error / cancelled</div>
            <div class="value"><?= (int) ($summary['services']['error_detected'] + $summary['services']['cancelled']) ?></div>
        </div>
    </div>
</div>

<!-- Revenue by service -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong>Revenue by service</strong>
        <a class="small" href="<?= e(url('/superadmin/reports')) ?>">Full reports</a>
    </div>
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
                        <?php foreach ($revenueBySvc as $r): ?>
                            <tr>
                                <td><?= e($r['name']) ?></td>
                                <td class="text-end"><?= (int) $r['jobs_completed'] ?></td>
                                <td class="text-end"><?= e(money($r['gross'])) ?></td>
                                <td class="text-end text-danger">−<?= e(money($r['commission'], '')) ?></td>
                                <td class="text-end fw-semibold text-success"><?= e(money($r['revenue'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require APP_ROOT . '/app/Views/layouts/superadmin-bottom.php'; ?>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AdminStatsRepository;
use App\Repositories\ServiceRepository;

$adminUser = Auth::requireAdmin();
// Lazy release of expired tasks on every dashboard view.
// The sweep is idempotent and re-entrant safe; cron provides the guaranteed backstop.
(new App\Services\TaskLockService())->sweepExpired();

$statsRepo    = new AdminStatsRepository();
$serviceRepo  = new ServiceRepository();
$myStats      = $statsRepo->summaryForAdmin((int) $adminUser['id']);
$queueStats   = $statsRepo->queueSummary();
$countsPerSvc = $statsRepo->countsPerService();
$services     = $serviceRepo->allActive();

$adminSidebarServices = $services;

$pageTitle = 'Dashboard';
require APP_ROOT . '/app/Views/layouts/admin-top.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h4 mb-1">Welcome, <?= e($adminUser['full_name']) ?></h1>
        <p class="text-muted small mb-0">Your performance and the platform task pool at a glance.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="<?= e(url('/admin/completed-jobs')) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-check2-square me-1"></i> Completed jobs
        </a>
        <a href="<?= e(url('/admin/withdrawals')) ?>" class="btn btn-brand btn-sm">
            <i class="bi bi-cash-coin me-1"></i> Commission
        </a>
    </div>
</div>

<!-- My performance -->
<h6 class="text-uppercase text-muted small mb-3">My performance</h6>
<div class="row g-3 mb-4">
    <div class="col-md-4 col-xl-3">
        <div class="admin-stat brand">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Completed jobs</div>
                    <div class="value"><?= (int) $myStats['jobs_completed'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-check2-circle"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="admin-stat amber">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Processing</div>
                    <div class="value"><?= (int) $myStats['jobs_processing'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-hourglass-split"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="admin-stat red">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Errored</div>
                    <div class="value"><?= (int) $myStats['jobs_errored'] ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-exclamation-octagon"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-xl-3">
        <div class="admin-stat green">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Commission balance</div>
                    <div class="value"><?= e(money($myStats['commission_balance'])) ?></div>
                </div>
                <span class="icon-circle"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
</div>

<!-- Commission breakdown + queue -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Commission breakdown</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Total earned</span>
                    <strong><?= e(money($myStats['commission_earned'])) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Withdrawn</span>
                    <strong><?= e(money($myStats['commission_withdrawn'])) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Pending withdrawal</span>
                    <strong><?= e(money($myStats['commission_pending_wd'])) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted small">Reserved</span>
                    <strong><?= e(money($myStats['commission_reserved'])) ?></strong>
                </div>
                <hr>
                <div class="d-flex justify-content-between">
                    <span class="text-muted small">Available for withdrawal</span>
                    <strong class="text-success">
                        <?php
                        try {
                            $available = money_sub((string) $myStats['commission_balance'], (string) $myStats['commission_reserved']);
                        } catch (\Throwable) {
                            $available = '0.00';
                        }
                        echo e(money($available));
                        ?>
                    </strong>
                </div>
                <a href="<?= e(url('/admin/withdrawals')) ?>" class="btn btn-outline-secondary btn-sm w-100 mt-3">
                    <i class="bi bi-cash-stack me-1"></i> Request withdrawal
                </a>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white"><strong>Platform queue</strong></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-muted small">Pending in pool</div>
                        <div class="h4 mb-0"><?= (int) $queueStats['pending'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Currently processing</div>
                        <div class="h4 mb-0"><?= (int) $queueStats['processing'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Completed</div>
                        <div class="h4 mb-0"><?= (int) $queueStats['completed'] ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small">Error detected</div>
                        <div class="h4 mb-0"><?= (int) $queueStats['errored'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Services -->
<h6 class="text-uppercase text-muted small mb-3">Services</h6>
<div class="row g-3">
    <?php foreach ($services as $svc):
        $sid    = (int) $svc['id'];
        $pending   = $countsPerSvc[$sid]['pending'] ?? 0;
        $processing= $countsPerSvc[$sid]['processing'] ?? 0;
        $completed = $countsPerSvc[$sid]['completed'] ?? 0;
    ?>
        <div class="col-md-6 col-xl-4">
            <a class="text-decoration-none" href="<?= e(url('/admin/service?id=' . $sid)) ?>">
                <div class="admin-stat brand h-100">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <div class="label">Service</div>
                            <div class="fw-bold text-dark"><?= e($svc['name']) ?></div>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-clock-history me-1"></i>
                                <?= e($svc['duration_label'] ?: human_duration((int) $svc['processing_minutes'])) ?>
                            </div>
                        </div>
                        <span class="icon-circle"><i class="bi bi-briefcase"></i></span>
                    </div>
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="small text-muted">Pending</div>
                            <div class="fw-bold text-dark"><?= (int) $pending ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Processing</div>
                            <div class="fw-bold text-dark"><?= (int) $processing ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Completed</div>
                            <div class="fw-bold text-dark"><?= (int) $completed ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/app/Views/layouts/admin-bottom.php'; ?>
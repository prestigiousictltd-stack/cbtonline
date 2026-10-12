<?php
use App\Repositories\AdminStatsRepository;
use App\Repositories\ServiceRepository;

$__adminStatsRepo = new AdminStatsRepository();
$__serviceRepo    = new ServiceRepository();

$__services  = $__serviceRepo->allActive();
$__queuePerSvc = $__adminStatsRepo->countsPerService();
$__myCounts    = $__adminStatsRepo->myCountsPerService((int) $adminUser['id']);
$__unread      = (new App\Services\NotificationService())->unreadCount('admin', (int) $adminUser['id']);

$__renderNav = function (string $path, string $icon, string $label, ?int $badge = null) {
    $active = is_active($path, false) ? 'active' : '';
    echo '<a class="admin-nav-item ' . e($active) . '" href="' . e(url($path)) . '">';
    echo '<i class="bi ' . e($icon) . '"></i>';
    echo '<span>' . e($label) . '</span>';
    if ($badge !== null && $badge > 0) {
        echo '<span class="badge bg-primary ms-auto">' . (int) $badge . '</span>';
    }
    echo '</a>';
};

$__renderServices = function () use ($__services, $__queuePerSvc, $__myCounts) {
    foreach ($__services as $svc) {
        $sid       = (int) $svc['id'];
        $pending   = $__queuePerSvc[$sid]['pending'] ?? 0;
        $mine      = ($__myCounts[$sid]['processing'] ?? 0);
        $active    = is_active('/admin/service', false) && (int) ($_GET['id'] ?? $_GET['service_id'] ?? 0) === $sid;
        $cls       = $active ? 'active' : '';
        echo '<a class="admin-nav-item admin-nav-sub ' . e($cls) . '" href="'
             . e(url('/admin/service?id=' . $sid)) . '">';
        echo '<i class="bi bi-circle-fill" style="font-size:.4rem"></i>';
        echo '<span class="text-truncate">' . e($svc['name']) . '</span>';
        if ($mine > 0) {
            echo '<span class="badge bg-warning text-dark ms-auto" title="Your active tasks">' . (int) $mine . '</span>';
        } elseif ($pending > 0) {
            echo '<span class="badge bg-primary ms-auto">' . (int) $pending . '</span>';
        }
        echo '</a>';
    }
};
?>

<!-- Desktop sidebar -->
<aside class="col-lg-2 admin-sidebar d-none d-lg-block">
    <div class="admin-sidebar-inner">
        <?php $__renderNav('/admin/dashboard', 'bi-speedometer2', 'Dashboard'); ?>

        <div class="admin-nav-section">Services</div>
        <?php $__renderServices(); ?>

        <div class="admin-nav-section">My performance</div>
        <?php $__renderNav('/admin/completed-jobs', 'bi-check2-square', 'Completed jobs'); ?>
        <?php $__renderNav('/admin/commissions',    'bi-cash-coin',    'Commission'); ?>
        <?php $__renderNav('/admin/withdrawals',    'bi-cash-stack',   'Withdrawals'); ?>

        <div class="admin-nav-section">Account</div>
        <?php $__renderNav('/admin/notifications', 'bi-bell', 'Notifications', $__unread); ?>
        <?php $__renderNav('/admin/profile',       'bi-person', 'Profile'); ?>
        <a class="admin-nav-item text-danger" href="<?= e(url('/admin/logout')) ?>">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start admin-offcanvas" tabindex="-1" id="adminSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= e(APP_NAME) ?> Admin</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php $__renderNav('/admin/dashboard', 'bi-speedometer2', 'Dashboard'); ?>
        <div class="admin-nav-section">Services</div>
        <?php $__renderServices(); ?>
        <div class="admin-nav-section">My performance</div>
        <?php $__renderNav('/admin/completed-jobs', 'bi-check2-square', 'Completed jobs'); ?>
        <?php $__renderNav('/admin/commissions',    'bi-cash-coin',    'Commission'); ?>
        <?php $__renderNav('/admin/withdrawals',    'bi-cash-stack',   'Withdrawals'); ?>
        <div class="admin-nav-section">Account</div>
        <?php $__renderNav('/admin/notifications', 'bi-bell', 'Notifications', $__unread); ?>
        <?php $__renderNav('/admin/profile',       'bi-person', 'Profile'); ?>
        <a class="admin-nav-item text-danger" href="<?= e(url('/admin/logout')) ?>">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</div>
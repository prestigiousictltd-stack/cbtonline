<?php
$__unread = (new App\Services\NotificationService())->unreadCount('super_admin', (int) $superAdminUser['id']);
$__render = function (string $path, string $icon, string $label, ?int $badge = null) {
    $active = is_active($path, false) ? 'active' : '';
    echo '<a class="sa-nav-item ' . e($active) . '" href="' . e(url($path)) . '">';
    echo '<i class="bi ' . e($icon) . '"></i>';
    echo '<span>' . e($label) . '</span>';
    if ($badge !== null && $badge > 0) {
        echo '<span class="badge bg-danger ms-auto">' . (int) $badge . '</span>';
    }
    echo '</a>';
};
?>

<aside class="col-lg-2 sa-sidebar d-none d-lg-block">
    <div class="sa-sidebar-inner">
        <?php $__render('/superadmin/dashboard',  'bi-speedometer2',  'Dashboard'); ?>

        <div class="sa-nav-section">Operations</div>
        <?php $__render('/superadmin/clients',    'bi-people',        'Clients'); ?>
        <?php $__render('/superadmin/admins',     'bi-person-badge',  'Admins'); ?>
        <?php $__render('/superadmin/services',   'bi-grid-3x3-gap',  'Services'); ?>
        <?php $__render('/superadmin/submissions','bi-file-earmark-text','Submissions'); ?>

        <div class="sa-nav-section">Finance</div>
        <?php $__render('/superadmin/payments',   'bi-receipt',       'Payments'); ?>
        <?php $__render('/superadmin/wallets',    'bi-wallet2',       'Wallets'); ?>
        <?php $__render('/superadmin/withdrawals','bi-cash-stack',    'Withdrawals'); ?>

        <div class="sa-nav-section">Insights</div>
        <?php $__render('/superadmin/reports',    'bi-graph-up',      'Reports'); ?>
        <?php $__render('/superadmin/audit-logs', 'bi-shield-check',  'Audit logs'); ?>

        <div class="sa-nav-section">Account</div>
        <?php $__render('/superadmin/notifications','bi-bell',        'Notifications', $__unread); ?>
        <?php $__render('/superadmin/settings',   'bi-sliders',       'Settings'); ?>
        <?php $__render('/superadmin/profile',    'bi-person',        'Profile'); ?>
        <a class="sa-nav-item text-danger" href="<?= e(url('/superadmin/logout')) ?>">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</aside>

<div class="offcanvas offcanvas-start sa-offcanvas" tabindex="-1" id="saSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= e(APP_NAME) ?> Super Admin</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php $__render('/superadmin/dashboard',  'bi-speedometer2',  'Dashboard'); ?>
        <div class="sa-nav-section">Operations</div>
        <?php $__render('/superadmin/clients',    'bi-people',        'Clients'); ?>
        <?php $__render('/superadmin/admins',     'bi-person-badge',  'Admins'); ?>
        <?php $__render('/superadmin/services',   'bi-grid-3x3-gap',  'Services'); ?>
        <?php $__render('/superadmin/submissions','bi-file-earmark-text','Submissions'); ?>
        <div class="sa-nav-section">Finance</div>
        <?php $__render('/superadmin/payments',   'bi-receipt',       'Payments'); ?>
        <?php $__render('/superadmin/wallets',    'bi-wallet2',       'Wallets'); ?>
        <?php $__render('/superadmin/withdrawals','bi-cash-stack',    'Withdrawals'); ?>
        <div class="sa-nav-section">Insights</div>
        <?php $__render('/superadmin/reports',    'bi-graph-up',      'Reports'); ?>
        <?php $__render('/superadmin/audit-logs', 'bi-shield-check',  'Audit logs'); ?>
        <div class="sa-nav-section">Account</div>
        <?php $__render('/superadmin/notifications','bi-bell',        'Notifications', $__unread); ?>
        <?php $__render('/superadmin/settings',   'bi-sliders',       'Settings'); ?>
        <?php $__render('/superadmin/profile',    'bi-person',        'Profile'); ?>
        <a class="sa-nav-item text-danger" href="<?= e(url('/superadmin/logout')) ?>">
            <i class="bi bi-box-arrow-right"></i><span>Logout</span>
        </a>
    </div>
</div>
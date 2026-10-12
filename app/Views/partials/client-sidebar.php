<?php
$nav = [
    ['/client/dashboard',      'bi-speedometer2',  'Dashboard'],
    ['/client/services',       'bi-grid',          'Services'],
    ['/client/submissions',    'bi-file-earmark-text', 'My Submissions'],
    ['/client/wallet',         'bi-wallet2',       'Wallet'],
    ['/client/transactions',   'bi-list-ul',       'Transactions'],
    ['/client/notifications',  'bi-bell',          'Notifications'],
    ['/client/profile',        'bi-person',        'Profile'],
];
$__unread = (new App\Services\NotificationService())->unreadCount('client', (int) $clientUser['id']);
?>

<!-- Desktop sidebar -->
<aside class="col-lg-2 client-sidebar d-none d-lg-block">
    <div class="client-sidebar-inner">
        <?php foreach ($nav as [$path, $icon, $label]): ?>
            <a class="client-nav-item <?= e(is_active($path)) ?>" href="<?= e(url($path)) ?>">
                <i class="bi <?= e($icon) ?>"></i>
                <span><?= e($label) ?></span>
                <?php if ($path === '/client/notifications' && $__unread > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= (int) $__unread ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</aside>

<!-- Mobile offcanvas -->
<div class="offcanvas offcanvas-start client-offcanvas" tabindex="-1" id="clientSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= e(APP_NAME) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?php foreach ($nav as [$path, $icon, $label]): ?>
            <a class="client-nav-item <?= e(is_active($path)) ?>" href="<?= e(url($path)) ?>">
                <i class="bi <?= e($icon) ?>"></i>
                <span><?= e($label) ?></span>
                <?php if ($path === '/client/notifications' && $__unread > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= (int) $__unread ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
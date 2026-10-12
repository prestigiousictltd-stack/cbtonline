<?php
/** @var string $pageTitle */
/** @var array  $clientUser */
$__title   = $pageTitle ?? APP_NAME;
$__flashes = take_flashes();
$__unread  = (new App\Services\NotificationService())->unreadCount('client', (int) $clientUser['id']);

$__nav = [
    ['/client/dashboard',    'bi-speedometer2',      'Dashboard'],
    ['/client/services',     'bi-grid',              'Services'],
    ['/client/submissions',  'bi-file-earmark-text', 'My Submissions'],
    ['/client/wallet',       'bi-wallet2',           'Wallet'],
    ['/client/transactions', 'bi-list-ul',           'Transactions'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($__title) ?> — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/client.css')) ?>?v=<?= filemtime(APP_ROOT . '/public/assets/css/client.css') ?>" rel="stylesheet">
</head>
<body class="client-body">

<nav class="navbar navbar-expand-lg client-topbar sticky-top">
    <div class="container-fluid px-3 px-lg-4">

        <!-- Brand -->
        <a class="navbar-brand client-brand" href="<?= e(url('/client/dashboard')) ?>">
            <i class="bi bi-shield-check"></i>
            <span><?= e(APP_NAME) ?></span>
        </a>

        <!-- Mobile toggle -->
        <button class="navbar-toggler client-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#clientNavbar"
                aria-controls="clientNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>

        <!-- Primary nav -->
        <div class="collapse navbar-collapse" id="clientNavbar">
            <ul class="navbar-nav client-nav me-auto mb-2 mb-lg-0">
                <?php foreach ($__nav as [$path, $icon, $label]): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= e(is_active($path, false)) ?>" href="<?= e(url($path)) ?>">
                            <i class="bi <?= e($icon) ?>"></i>
                            <span><?= e($label) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Right cluster (always visible on desktop, moves into collapse on mobile) -->
            <div class="client-topbar-actions d-flex align-items-center gap-2">
                <a href="<?= e(url('/client/notifications')) ?>"
                   class="btn btn-sm client-icon-btn position-relative"
                   title="Notifications">
                    <i class="bi bi-bell"></i>
                    <?php if ($__unread > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= (int) $__unread ?>
                        </span>
                    <?php endif; ?>
                </a>

                <div class="dropdown">
                    <button class="btn btn-sm client-user-btn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline ms-1"><?= e($clientUser['full_name']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= e(url('/client/profile')) ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/client/change-password')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/client/notifications')) ?>">
                            <i class="bi bi-bell me-2"></i>Notifications
                            <?php if ($__unread > 0): ?>
                                <span class="badge bg-danger ms-2"><?= (int) $__unread ?></span>
                            <?php endif; ?>
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= e(url('/logout')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<main class="client-main">
    <div class="client-main-inner">
        <?php require APP_ROOT . '/app/Views/partials/flash.php'; ?>
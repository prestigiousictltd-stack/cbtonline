<?php
/** @var string $pageTitle */
/** @var array  $superAdminUser */
$__title   = $pageTitle ?? APP_NAME;
$__flashes = take_flashes();
$__unread  = (new App\Services\NotificationService())->unreadCount('super_admin', (int) $superAdminUser['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($__title) ?> — <?= e(APP_NAME) ?> Super Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/superadmin.css')) ?>" rel="stylesheet">
</head>
<body class="sa-body">

<nav class="navbar navbar-dark sa-topbar sticky-top">
    <div class="container-fluid px-3">
        <button class="btn btn-sm btn-outline-light d-lg-none" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#saSidebar">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand fw-bold ms-2 ms-lg-0" href="<?= e(url('/superadmin/dashboard')) ?>">
            <i class="bi bi-shield-fill-check"></i>
            <?= e(APP_NAME) ?> <span class="opacity-75 fw-normal">Super Admin</span>
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <a href="<?= e(url('/superadmin/notifications')) ?>" class="btn btn-sm btn-outline-light position-relative">
                <i class="bi bi-bell"></i>
                <?php if ($__unread > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                        <?= (int) $__unread ?>
                    </span>
                <?php endif; ?>
            </a>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-light dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle"></i>
                    <span class="d-none d-md-inline ms-1"><?= e($superAdminUser['full_name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="<?= e(url('/superadmin/profile')) ?>"><i class="bi bi-person me-2"></i>Profile</a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/superadmin/change-password')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?= e(url('/superadmin/logout')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <?php require APP_ROOT . '/app/Views/partials/superadmin-sidebar.php'; ?>

        <main class="col-lg-10 sa-main">
            <div class="sa-main-inner">
                <?php require APP_ROOT . '/app/Views/partials/flash.php'; ?>
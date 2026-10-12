<?php
/** @var string $pageTitle */
$__title = $pageTitle ?? APP_NAME;
$__flashes = take_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($__title) ?> — <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-wrapper">
   <div class="auth-brand d-flex align-items-center justify-content-between">
    <a href="<?= e(url('/')) ?>" class="text-decoration-none d-inline-flex align-items-center gap-2">
        <i class="bi bi-shield-check"></i>
        <span><?= e(APP_NAME) ?></span>
    </a>
    <a href="<?= e(url('/')) ?>"
       class="auth-home-link"
       title="Back to home"
       aria-label="Back to home">
        <i class="bi bi-house-door"></i>
    </a>
</div>

    <div class="auth-card">
        <?php require APP_ROOT . '/app/Views/partials/flash.php'; ?>
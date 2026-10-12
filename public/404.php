<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
http_response_code(404);
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><title>Not found — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="d-flex align-items-center" style="min-height:100vh;background:#f5f7fb">
<div class="container text-center">
    <h1 class="display-4 fw-bold">404</h1>
    <p class="lead text-muted">We couldn't find that page.</p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Go home</a>
</div>
</body></html>
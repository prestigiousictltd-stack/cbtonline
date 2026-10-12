<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\AuthService;

if (Auth::clientCheck()) {
    redirect('/client/services');
}

$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/login');

    $old = [
        'email'    => trim((string) ($_POST['email'] ?? '')),
    ];
    $password  = (string) ($_POST['password'] ?? '');
    $remember  = !empty($_POST['remember']);

    $result = (new AuthService())->attemptClient($old['email'], $password, $remember);

    if ($result['ok']) {
        Auth::loginClient($result['user'], $remember);

        if (!empty($result['user']['must_change_password'])) {
            flash('warning', 'Please change your password.');
            // Future: redirect to change-password page. For now continue.
        }
        flash('success', 'Welcome back, ' . $result['user']['full_name'] . '.');
        redirect('/client/services');
    }
    $errors['general'] = $result['error'];
}

$pageTitle = 'Sign in';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Welcome back</h1>
<p class="subtitle">Sign in to continue to your dashboard.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="form-floating mb-3">
        <input type="email" name="email" id="email" class="form-control"
               placeholder="Email address" required maxlength="190"
               value="<?= e($old['email']) ?>" autofocus>
        <label for="email">Email address</label>
    </div>

    <div class="form-floating mb-2">
        <input type="password" name="password" id="password" class="form-control"
               placeholder="Password" required autocomplete="current-password">
        <label for="password">Password</label>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
            <label class="form-check-label small" for="remember">Remember me</label>
        </div>
        <a class="small" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a>
    </div>

    <button type="submit" class="btn btn-brand w-100">Sign in</button>

    <p class="text-center text-muted mt-3 mb-0 small">
        Don't have an account?
        <a href="<?= e(url('/register')) ?>">Create one</a>
    </p>
</form>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\AuthService;

if (Auth::superAdminCheck()) {
    redirect('/superadmin/dashboard');
}

$errors = [];
$old = ['identifier' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/superadmin/login');

    $old['identifier'] = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    $result = (new AuthService())->attemptSuperAdmin($old['identifier'], $password, $remember);

    if ($result['ok']) {
        Auth::loginSuperAdmin($result['user'], $remember);
        flash('success', 'Welcome, ' . $result['user']['full_name'] . '.');
        redirect('/superadmin/dashboard');
    }

    $errors['general'] = $result['error'];
}

$pageTitle = 'Super Admin sign in';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Super Admin</h1>
<p class="subtitle">Platform-owner access only.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="form-floating mb-3">
        <input type="text" name="identifier" id="identifier" class="form-control"
               placeholder="Email or username" required maxlength="190"
               value="<?= e($old['identifier']) ?>" autofocus>
        <label for="identifier">Email or username</label>
    </div>

    <div class="form-floating mb-2">
        <input type="password" name="password" id="password" class="form-control"
               placeholder="Password" required autocomplete="current-password">
        <label for="password">Password</label>
    </div>

    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
        <label class="form-check-label small" for="remember">Remember me</label>
    </div>

    <button type="submit" class="btn btn-brand w-100">Sign in</button>
</form>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
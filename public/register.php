<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\RegistrationService;

if (Auth::clientCheck()) {
    redirect('/client/dashboard');
}

$errors = [];
$old = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'address'   => '',
    'state'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/register');

    $old = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'email'     => trim((string) ($_POST['email'] ?? '')),
      
    ];

    $result = (new RegistrationService())->register([
        'full_name'             => $old['full_name'],
        'email'                 => $old['email'],
        'password'              => (string) ($_POST['password'] ?? ''),
        'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
    ]);

    if ($result['ok']) {
        Auth::loginClient($result['user']);
        flash('success', 'Welcome, ' . $result['user']['full_name'] . '! Your account is ready.');
        redirect('/client/services');
    }
    $errors = $result['errors'];
}

$pageTitle = 'Create Account';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Create your account</h1>
<p class="subtitle">Set up your client profile in under a minute.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="form-floating mb-3">
        <input type="text" name="full_name" id="full_name" class="form-control"
               placeholder="Full name" required maxlength="150"
               value="<?= e($old['full_name']) ?>">
        <label for="full_name">Full name</label>
    </div>

    <div class="form-floating mb-3">
        <input type="email" name="email" id="email" class="form-control"
               placeholder="Email address" required maxlength="190"
               value="<?= e($old['email']) ?>">
        <label for="email">Email address</label>
    </div>

    
    
       
   
    <div class="form-floating mb-3">
        <input type="password" name="password" id="password" class="form-control"
               placeholder="Password" required minlength="8" autocomplete="new-password">
        <label for="password">Password</label>
    </div>

    <div class="form-floating mb-3">
        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control"
               placeholder="Confirm password" required minlength="8" autocomplete="new-password">
        <label for="password_confirmation">Confirm password</label>
    </div>

    <button type="submit" class="btn btn-brand w-100">Create account</button>

    <p class="text-center text-muted mt-3 mb-0 small">
        Already have an account?
        <a href="<?= e(url('/login')) ?>">Sign in</a>
    </p>
</form>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
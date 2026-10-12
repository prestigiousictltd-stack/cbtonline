<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Services\AuditService;

$rawToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$email    = strtolower(trim((string) ($_GET['email'] ?? $_POST['email'] ?? '')));

$errors = [];
$done   = false;

$reset = null;
if ($rawToken !== '' && $email !== '') {
    $reset = Database::selectOne(
        'SELECT * FROM password_resets
          WHERE user_type = "client"
            AND email = :e
            AND token_hash = :h
            AND used_at IS NULL
            AND expires_at >= UTC_TIMESTAMP()
          ORDER BY id DESC
          LIMIT 1',
        [':e' => $email, ':h' => hash('sha256', $rawToken)]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null,
        '/reset-password?token=' . urlencode($rawToken) . '&email=' . urlencode($email));

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirmation'] ?? '');

    if (!$reset) {
        $errors['general'] = 'This reset link is invalid or has expired.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $errors['password_confirmation'] = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        Database::transaction(function () use ($reset, $hash, $rawToken, $email) {
            (new UserRepository())->updatePassword((int) $reset['user_id'], $hash);

            Database::execute(
                'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id',
                [':id' => (int) $reset['id']]
            );
            // Invalidate any other outstanding tokens for this user
            Database::execute(
                'UPDATE password_resets SET used_at = UTC_TIMESTAMP()
                 WHERE user_type = "client" AND user_id = :uid AND used_at IS NULL',
                [':uid' => (int) $reset['user_id']]
            );

            (new AuditService())->log(
                'PASSWORD_RESET_COMPLETED', 'user', (int) $reset['user_id'],
                'Client reset password', userType: 'client', userId: (int) $reset['user_id']
            );
        });

        $done = true;
    }
}

$pageTitle = 'Reset password';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Reset password</h1>
<p class="subtitle">Choose a new password for your account.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<?php if ($done): ?>
    <div class="alert alert-success">
        Your password has been updated. <a href="<?= e(url('/login')) ?>">Sign in</a>.
    </div>
<?php elseif (!$reset): ?>
    <div class="alert alert-danger">
        This reset link is invalid or has expired.
        <a href="<?= e(url('/forgot-password')) ?>">Request a new one</a>.
    </div>
<?php else: ?>
    <form method="post" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($rawToken) ?>">
        <input type="hidden" name="email" value="<?= e($email) ?>">

        <div class="form-floating mb-3">
            <input type="password" name="password" id="password" class="form-control"
                   placeholder="New password" required minlength="8" autocomplete="new-password" autofocus>
            <label for="password">New password</label>
        </div>

        <div class="form-floating mb-3">
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control"
                   placeholder="Confirm new password" required minlength="8" autocomplete="new-password">
            <label for="password_confirmation">Confirm new password</label>
        </div>

        <button type="submit" class="btn btn-brand w-100">Reset password</button>
    </form>
<?php endif; ?>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>
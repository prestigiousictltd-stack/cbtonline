<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Csrf;
use App\Core\Database;
use App\Services\AuditService;

$errors  = [];
$notice  = null;
$old     = ['email' => ''];
$devLink = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verifyOrFail($_POST[CSRF_FIELD_NAME] ?? null, '/forgot-password');

    $old['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } else {
        $user = Database::selectOne(
            'SELECT id, email, status FROM users WHERE email = :e LIMIT 1',
            [':e' => $old['email']]
        );

        // Always show the same message to avoid enumeration
        $notice = 'If an account exists for that email, a reset link has been generated.';

        if ($user && $user['status'] === STATUS_ACTIVE) {
            $raw   = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $raw);
            $exp   = date('Y-m-d H:i:s', time() + 60 * 60); // 1 hour

            Database::execute(
                'INSERT INTO password_resets (user_type, user_id, email, token_hash, expires_at, ip_address)
                 VALUES ("client", :uid, :e, :h, :x, :ip)',
                [
                    ':uid' => (int) $user['id'],
                    ':e'   => $user['email'],
                    ':h'   => $hash,
                    ':x'   => $exp,
                    ':ip'  => client_ip(),
                ]
            );

            (new AuditService())->log(
                'PASSWORD_RESET_REQUESTED', 'user', (int) $user['id'],
                'Client requested a password reset', userType: 'client', userId: (int) $user['id']
            );

            $link = url('/reset-password?token=' . $raw . '&email=' . urlencode($user['email']));

            // --- Send the reset link via Resend ---
            $resetEmailHtml = sprintf(
                '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px;">'
                . '<h2 style="color:#1F82B6;">Reset your %s password</h2>'
                . '<p>Someone requested a password reset for your account. Click the button below to choose a new password. This link is valid for one hour.</p>'
                . '<p style="text-align:center;margin:32px 0;">'
                . '<a href="%s" style="background:#1F82B6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;font-weight:bold;">Reset password</a>'
                . '</p>'
                . '<p style="color:#64748b;font-size:13px;">If the button does not work, paste this link into your browser:<br><code>%s</code></p>'
                . '<p style="color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0;padding-top:12px;">If you did not request this, you can safely ignore this email.</p>'
                . '</div>',
                e(APP_NAME),
                e($link),
                e($link)
            );

            (new \App\Services\ResendMailer())::fromEnv()->send(
                $user['email'],
                'Reset your ' . APP_NAME . ' password',
                $resetEmailHtml
            );

            $delivered = true;
                    if ($delivered === false) {
            Logger::warning('Password reset email failed to send', ['email' => $user['email']]);
            // Still show the same notice to prevent email enumeration
        }
            // In production this would be emailed. In local dev we surface it.
            if (APP_DEBUG) {
                $devLink = $link;
            }
        }
    }
}

$pageTitle = 'Forgot password';
require APP_ROOT . '/app/Views/layouts/auth-top.php';
?>

<h1>Forgot password</h1>
<p class="subtitle">Enter your email and we'll send you a reset link.</p>

<?php require APP_ROOT . '/app/Views/partials/alerts.php'; ?>

<?php if ($notice): ?>
    <div class="alert alert-info"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($devLink): ?>
    <div class="alert alert-warning small">
        <strong>Dev mode link:</strong><br>
        <a href="<?= e($devLink) ?>" class="text-break"><?= e($devLink) ?></a>
    </div>
<?php endif; ?>

<form method="post" novalidate>
    <?= Csrf::field() ?>

    <div class="form-floating mb-3">
        <input type="email" name="email" id="email" class="form-control"
               placeholder="Email address" required maxlength="190"
               value="<?= e($old['email']) ?>" autofocus>
        <label for="email">Email address</label>
    </div>

    <button type="submit" class="btn btn-brand w-100">Send reset link</button>

    <p class="text-center text-muted mt-3 mb-0 small">
        <a href="<?= e(url('/login')) ?>">Back to sign in</a>
    </p>
</form>

<?php require APP_ROOT . '/app/Views/layouts/auth-bottom.php'; ?>

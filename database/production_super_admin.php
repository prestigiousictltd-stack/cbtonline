<?php
/**
 * scripts/create_super_admin.php
 * CLI only.
 *
 * Usage:
 *   php scripts/create_super_admin.php
 *
 * Prompts for name, email, username, phone and password. Creates the first
 * Super Admin (or updates an existing one by email).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require __DIR__ . '/../config/database.php';

function prompt(string $label, bool $required = true): string
{
    do {
        fwrite(STDOUT, $label . ': ');
        $value = trim((string) fgets(STDIN));
    } while ($required && $value === '');
    return $value;
}

echo "=== ServiceHub NG — Super Admin Creator ===\n\n";

$fullName = prompt('Full name');
$email    = strtolower(prompt('Email'));
$username = strtolower(prompt('Username'));
$phone    = prompt('Phone');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

$password = prompt('Password');
$confirm  = prompt('Confirm password');

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

/** @var PDO $pdo */
$hash = password_hash($password, PASSWORD_DEFAULT);

$existing = $pdo->prepare('SELECT id FROM super_admins WHERE email = :e OR username = :u LIMIT 1');
$existing->execute([':e' => $email, ':u' => $username]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $sql = 'UPDATE super_admins
               SET full_name = :fn, email = :e, username = :u, phone = :p,
                   password_hash = :h, status = "active", must_change_password = 0
             WHERE id = :id';
    $pdo->prepare($sql)->execute([
        ':fn' => $fullName, ':e' => $email, ':u' => $username,
        ':p'  => $phone,    ':h' => $hash,  ':id' => (int) $row['id'],
    ]);
    echo "✔ Super Admin updated (id={$row['id']}).\n";
} else {
    $sql = 'INSERT INTO super_admins
              (full_name, email, username, phone, password_hash, status, must_change_password)
            VALUES (:fn, :e, :u, :p, :h, "active", 0)';
    $pdo->prepare($sql)->execute([
        ':fn' => $fullName, ':e' => $email, ':u' => $username,
        ':p'  => $phone,    ':h' => $hash,
    ]);
    echo '✔ Super Admin created (id=' . $pdo->lastInsertId() . ").\n";
}

echo "You may now log in at /superadmin/login\n";
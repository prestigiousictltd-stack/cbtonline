<?php
declare(strict_types=1);
/**
 * scripts/verify_all.php
 *
 * Reconciles every monetary ledger with its cached balance, checks task
 * ownership consistency, and verifies that every stored file actually exists.
 *
 * Usage:
 *   php scripts/verify_all.php            # report
 *   php scripts/verify_all.php --json     # machine-readable output
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$json  = in_array('--json', $argv ?? [], true);
$fails = [];
$warns = [];

// -----------------------------------------------------------------------------
// 1. Wallets — balance must equal credits − debits from wallet_transactions.
// -----------------------------------------------------------------------------
$walletRows = Database::selectAll(
    'SELECT w.id, w.user_id, w.balance,
            COALESCE((SELECT SUM(amount) FROM wallet_transactions wt
                      WHERE wt.wallet_id = w.id AND wt.type = "credit" AND wt.status = "successful"), 0.00) AS credits,
            COALESCE((SELECT SUM(amount) FROM wallet_transactions wt
                      WHERE wt.wallet_id = w.id AND wt.type = "debit"  AND wt.status = "successful"), 0.00) AS debits
     FROM wallets w'
);

$walletChecked = 0;
foreach ($walletRows as $row) {
    $walletChecked++;
    try {
        $expected = money_sub((string) $row['credits'], (string) $row['debits']);
    } catch (\Throwable) {
        $expected = '0.00';
    }
    if (money_cmp((string) $row['balance'], $expected) !== 0) {
        $fails[] = sprintf(
            'WALLET wallet#%d user#%d: cached=%s expected=%s (credits=%s debits=%s)',
            $row['id'], $row['user_id'], $row['balance'], $expected,
            $row['credits'], $row['debits']
        );
    }
}

// -----------------------------------------------------------------------------
// 2. Admin commissions — balance must equal earned − withdrawal(successful).
//    reserved must equal sum of pending+approved withdrawals.
// -----------------------------------------------------------------------------
$adminRows = Database::selectAll(
    'SELECT a.id, a.full_name, a.commission_balance, a.commission_reserved,
            COALESCE((SELECT SUM(amount) FROM commission_transactions ct
                      WHERE ct.admin_id = a.id AND ct.type = "earned"
                        AND ct.status = "successful"), 0.00) AS earned,
            COALESCE((SELECT SUM(amount) FROM commission_transactions ct
                      WHERE ct.admin_id = a.id AND ct.type = "withdrawal"
                        AND ct.status = "successful"), 0.00) AS withdrawn,
            COALESCE((SELECT SUM(amount) FROM withdrawals w
                      WHERE w.admin_id = a.id AND w.status IN ("pending","approved")), 0.00) AS reserved_expected
     FROM admins a'
);

$adminChecked = 0;
foreach ($adminRows as $row) {
    $adminChecked++;
    try { $expectedBalance = money_sub((string) $row['earned'], (string) $row['withdrawn']); }
    catch (\Throwable) { $expectedBalance = '0.00'; }

    if (money_cmp((string) $row['commission_balance'], $expectedBalance) !== 0) {
        $fails[] = sprintf(
            'COMMISSION admin#%d (%s): balance=%s expected=%s (earned=%s withdrawn=%s)',
            $row['id'], $row['full_name'], $row['commission_balance'], $expectedBalance,
            $row['earned'], $row['withdrawn']
        );
    }
    if (money_cmp((string) $row['commission_reserved'], (string) $row['reserved_expected']) !== 0) {
        $fails[] = sprintf(
            'RESERVED admin#%d (%s): reserved=%s expected=%s',
            $row['id'], $row['full_name'], $row['commission_reserved'], $row['reserved_expected']
        );
    }
}

// -----------------------------------------------------------------------------
// 3. Task ownership — completed/processing submissions must have an admin.
//    Pending must have none.
// -----------------------------------------------------------------------------
$misowned = Database::selectAll(
    "SELECT id, tracking_id, status, assigned_admin_id
     FROM submissions
     WHERE (status = 'processing' AND assigned_admin_id IS NULL)
        OR (status = 'completed'  AND assigned_admin_id IS NULL)
        OR (status = 'pending'    AND assigned_admin_id IS NOT NULL)"
);
foreach ($misowned as $s) {
    $fails[] = sprintf(
        'TASK submission#%d (%s) status=%s assigned=%s',
        $s['id'], $s['tracking_id'], $s['status'], $s['assigned_admin_id'] ?? 'NULL'
    );
}

// Expired processing tasks (deadline passed but still locked)
$expiredStillLocked = Database::selectAll(
    "SELECT id, tracking_id, deadline_at FROM submissions
     WHERE status = 'processing' AND deadline_at IS NOT NULL AND deadline_at < UTC_TIMESTAMP()"
);
foreach ($expiredStillLocked as $s) {
    $warns[] = sprintf(
        'EXPIRED_LOCK submission#%d (%s) deadline=%s — release the task or wait for the next sweep',
        $s['id'], $s['tracking_id'], $s['deadline_at']
    );
}

// -----------------------------------------------------------------------------
// 4. Files on disk — every documents and payment_proofs row must resolve.
// -----------------------------------------------------------------------------
$missingFiles = 0;
$fileRows = Database::selectAll('SELECT id, file_path, stored_name FROM documents');
foreach ($fileRows as $r) {
    $abs = APP_ROOT . '/storage/uploads/' . ltrim((string) $r['file_path'], '/');
    if (!is_file($abs)) {
        $fails[] = sprintf('FILE documents#%d missing at %s', $r['id'], $r['file_path']);
        $missingFiles++;
    }
}
$fileRows = Database::selectAll('SELECT id, file_path FROM payment_proofs');
foreach ($fileRows as $r) {
    $abs = APP_ROOT . '/storage/uploads/' . ltrim((string) $r['file_path'], '/');
    if (!is_file($abs)) {
        $fails[] = sprintf('FILE payment_proofs#%d missing at %s', $r['id'], $r['file_path']);
        $missingFiles++;
    }
}

// -----------------------------------------------------------------------------
// 5. Payments — successful payments must have one matching wallet credit.
// -----------------------------------------------------------------------------
$orphanCredits = Database::selectAll(
    "SELECT p.id, p.payment_ref
     FROM payments p
     WHERE p.status = 'successful'
       AND NOT EXISTS (
            SELECT 1 FROM wallet_transactions wt
            WHERE wt.reference_type = 'payment'
              AND wt.reference_id = p.id
              AND wt.type = 'credit'
              AND wt.status = 'successful'
       )"
);
foreach ($orphanCredits as $p) {
    $fails[] = sprintf('PAYMENT payment#%d (%s) is successful but has no wallet credit', $p['id'], $p['payment_ref']);
}

// -----------------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------------
$summary = [
    'checked' => [
        'wallets'   => $walletChecked,
        'admins'    => $adminChecked,
        'documents' => count($fileRows),
    ],
    'fails' => count($fails),
    'warns' => count($warns),
];

if ($json) {
    echo json_encode(['summary' => $summary, 'fails' => $fails, 'warns' => $warns], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($summary['fails'] > 0 ? 1 : 0);
}

echo "=== ServiceHub NG — integrity check ===\n";
echo sprintf("Wallets checked:  %d\n", $walletChecked);
echo sprintf("Admins checked:   %d\n", $adminChecked);
echo sprintf("Files checked:    %d\n\n", count($fileRows));

if ($warns) {
    echo "--- WARNINGS ---\n";
    foreach ($warns as $w) echo "  [WARN] $w\n";
    echo "\n";
}

if ($fails) {
    echo "--- FAILURES ---\n";
    foreach ($fails as $f) echo "  [FAIL] $f\n";
    echo "\n";
    echo sprintf("FAILED — %d discrepanc%s found.\n", count($fails), count($fails) === 1 ? 'y' : 'ies');
    exit(1);
}

echo "OK — no inconsistencies found.\n";
exit(0);
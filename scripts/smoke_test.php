<?php
declare(strict_types=1);
/**
 * scripts/smoke_test.php
 *
 * Exercises the happy path end-to-end inside a transaction and rolls back.
 * Proves that the critical services work with the current code.
 *
 * Usage:
 *   php scripts/smoke_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Services\CommissionService;
use App\Services\RegistrationService;
use App\Services\SubmissionService;
use App\Services\TaskCompletionService;
use App\Services\TaskLockService;
use App\Services\WalletService;

$pass = 0;
$fail = 0;

function section(string $title): void {
    echo "\n== $title ==\n";
}
function ok(string $msg): void   { global $pass; $pass++; echo "  ✔ $msg\n"; }
function bad(string $msg): void  { global $fail; $fail++; echo "  ✘ $msg\n"; }

$start = microtime(true);

// -------------------------------------------------------------------------
// Preflight
// -------------------------------------------------------------------------
section('Preflight');
$services = Database::selectAll('SELECT id, price, admin_commission, processing_minutes FROM services WHERE status="active" LIMIT 1');
if (!$services) { bad('No active service in the database — seed data missing.'); exit(1); }
$service = $services[0];
ok('Found an active service to test with (id=' . $service['id'] . ').');

// -------------------------------------------------------------------------
// Everything runs inside a transaction that we roll back at the end so
// the smoke test never leaves residue behind.
// -------------------------------------------------------------------------
Database::begin();

try {
    // 1. Register a scratch client
    section('Client registration');
    $email = 'smoke_' . bin2hex(random_bytes(4)) . '@example.test';
    $reg = (new RegistrationService())->register([
        'full_name'             => 'Smoke Tester',
        'email'                 => $email,
        'phone'                 => '08000000000',
        'password'              => 'smokePass!23',
        'password_confirmation' => 'smokePass!23',
    ]);
    if (empty($reg['ok'])) { bad('Registration failed: ' . json_encode($reg)); throw new RuntimeException('registration'); }
    $userId = (int) $reg['user_id'];
    ok("Client #$userId registered.");

    $wallet = Database::selectOne('SELECT * FROM wallets WHERE user_id = :u', [':u' => $userId]);
    if (!$wallet) { bad('Wallet was not auto-created.'); throw new RuntimeException('wallet'); }
    ok('Wallet auto-created with balance ' . $wallet['balance'] . '.');

    // 2. Fund the wallet by simulating an approved payment (direct ledger credit)
    section('Wallet funding');
    $creditAmount = money_add((string) $service['price'], '5000.00');
    (new WalletService())->credit([
        'user_id'         => $userId,
        'amount'          => $creditAmount,
        'category'        => WALLET_CAT_FUNDING,
        'reference_type'  => 'smoke_test',
        'description'     => 'Smoke test funding',
        'created_by_type' => 'system',
    ]);
    $wallet = Database::selectOne('SELECT * FROM wallets WHERE user_id = :u', [':u' => $userId]);
    if (money_cmp((string) $wallet['balance'], $creditAmount) !== 0) {
        bad('Wallet balance mismatch after funding: ' . $wallet['balance'] . ' vs ' . $creditAmount);
        throw new RuntimeException('funding');
    }
    ok('Wallet funded to ' . $wallet['balance'] . '.');

    // 3. Submit a service (dynamic field values must match service_fields)
    section('Service submission');
    $fields = Database::selectAll('SELECT field_key, is_required FROM service_fields WHERE service_id = :s AND status = "active"', [':s' => (int) $service['id']]);
    $fieldValues = [];
    foreach ($fields as $f) {
        if ((int) $f['is_required'] === 1) {
            $fieldValues[$f['field_key']] = 'smoke value';
        }
    }
    $sub = (new SubmissionService())->submit($userId, (int) $service['id'], ['fields' => $fieldValues]);
    if (empty($sub['ok'])) { bad('Submission failed: ' . json_encode($sub)); throw new RuntimeException('submission'); }
    $submissionId = (int) $sub['submission_id'];
    $trackingId   = (string) $sub['tracking_id'];
    ok("Submission created with tracking ID $trackingId.");

    $wallet = Database::selectOne('SELECT * FROM wallets WHERE user_id = :u', [':u' => $userId]);
    $expectedAfter = money_sub($creditAmount, (string) $service['price']);
    if (money_cmp((string) $wallet['balance'], $expectedAfter) !== 0) {
        bad('Wallet balance after submission: ' . $wallet['balance'] . ' expected ' . $expectedAfter);
        throw new RuntimeException('submission-debit');
    }
    ok('Wallet debited by ' . $service['price'] . '; balance now ' . $wallet['balance'] . '.');

    // 4. Register a scratch admin and claim the task
    section('Admin task claim');
    $adminEmail = 'smoke_admin_' . bin2hex(random_bytes(4)) . '@example.test';
    Database::execute(
        'INSERT INTO admins (full_name, email, username, phone, password_hash, status)
         VALUES (:fn, :e, :u, :p, :h, "active")',
        [
            ':fn' => 'Smoke Admin',
            ':e'  => $adminEmail,
            ':u'  => 'smoke_' . substr(md5($adminEmail), 0, 8),
            ':p'  => '08000000000',
            ':h'  => password_hash('smokeAdmin!23', PASSWORD_DEFAULT),
        ]
    );
    $adminId = Database::lastInsertId();
    $adminRow = Database::selectOne('SELECT * FROM admins WHERE id = :i', [':i' => $adminId]);
    ok("Admin #$adminId created.");

    $claim = (new TaskLockService())->claim($submissionId, $adminRow);
    if (empty($claim['ok'])) { bad('Claim failed: ' . json_encode($claim)); throw new RuntimeException('claim'); }
    ok('Admin claimed the task; deadline = ' . $claim['deadline'] . '.');

    // 5. Second admin cannot claim
    section('Locking is exclusive');
    Database::execute(
        'INSERT INTO admins (full_name, email, username, phone, password_hash, status)
         VALUES (:fn, :e, :u, :p, :h, "active")',
        [
            ':fn' => 'Smoke Admin 2',
            ':e'  => 'smoke_admin2_' . bin2hex(random_bytes(4)) . '@example.test',
            ':u'  => 'smoke2_' . bin2hex(random_bytes(4)),
            ':p'  => '08000000001',
            ':h'  => password_hash('smokeAdmin!23', PASSWORD_DEFAULT),
        ]
    );
    $admin2Id = Database::lastInsertId();
    $admin2Row = Database::selectOne('SELECT * FROM admins WHERE id = :i', [':i' => $admin2Id]);
    $claim2 = (new TaskLockService())->claim($submissionId, $admin2Row);
    if (!empty($claim2['ok'])) { bad('Second admin unexpectedly claimed the same task.'); throw new RuntimeException('locking'); }
    if (($claim2['error'] ?? '') !== 'ALREADY_CLAIMED') { bad('Unexpected claim error: ' . json_encode($claim2)); throw new RuntimeException('locking-error'); }
    ok('Second admin correctly rejected with ALREADY_CLAIMED.');

    // 6. Complete the task
    section('Task completion');
    $complete = (new TaskCompletionService())->complete($adminRow, $submissionId, 'Smoke test completion remarks.', [
        'name'     => [],
        'type'     => [],
        'tmp_name' => [],
        'error'    => [],
        'size'     => [],
    ]);
    if (empty($complete['ok'])) { bad('Completion failed: ' . json_encode($complete)); throw new RuntimeException('complete'); }
    ok('Task completed; commission earned ' . $complete['commission'] . '.');

    $submission = Database::selectOne('SELECT * FROM submissions WHERE id = :i', [':i' => $submissionId]);
    if ($submission['status'] !== 'completed') { bad('Submission status is not completed.'); throw new RuntimeException('status'); }
    ok('Submission status is completed.');

    $commission = Database::selectOne('SELECT * FROM commission_transactions WHERE submission_id = :s AND type = "earned"', [':s' => $submissionId]);
    if (!$commission) { bad('No commission_transactions row for the completed task.'); throw new RuntimeException('commission'); }
    if (money_cmp((string) $commission['amount'], (string) $service['admin_commission']) !== 0) {
        bad('Commission amount mismatch: ' . $commission['amount'] . ' vs ' . $service['admin_commission']);
        throw new RuntimeException('commission-amount');
    }
    ok('Commission ledger row matches the service snapshot.');

    // 7. Ledger reconciliation on the scratch admin
    section('Ledger reconciliation');
    $adminAfter = Database::selectOne('SELECT * FROM admins WHERE id = :i', [':i' => $adminId]);
    if (money_cmp((string) $adminAfter['commission_balance'], (string) $service['admin_commission']) !== 0) {
        bad('Admin commission_balance is ' . $adminAfter['commission_balance'] . ' expected ' . $service['admin_commission']);
        throw new RuntimeException('reconcile');
    }
    ok('Admin commission_balance reconciles.');

    // 8. Rollback
    Database::rollback();
    section('Cleanup');
    ok('Transaction rolled back; database left untouched.');

} catch (\Throwable $e) {
    Database::rollback();
    bad('Aborted: ' . $e->getMessage());
}

$elapsed = round(microtime(true) - $start, 3);

echo "\n=== Summary ===\n";
echo "Passed: $pass\n";
echo "Failed: $fail\n";
echo "Time:   {$elapsed}s\n";

exit($fail === 0 ? 0 : 1);
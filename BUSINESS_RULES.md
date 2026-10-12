# Business Rules

The 15 core rules from the specification, where each is enforced, and what
happens when the rule is tested.

---

### Rule 1 — A client cannot submit a service if wallet balance < service price

**Where:** `App\Services\SubmissionService::submit()`
**How:** Inside the transaction, the wallet row is locked with `FOR UPDATE`,
then compared to the snapshot price. If insufficient, the method returns
`['ok' => false, 'error' => 'INSUFFICIENT_FUNDS']` *before* inserting the
submission. The client sees an inline alert with a "Fund wallet" button.
**Test:** fund wallet with ₦1,000; attempt a ₦5,000 service → blocked, wallet unchanged.

---

### Rule 2 — A wallet cannot become negative

**Where:** `App\Services\WalletService::debitTx()`, `wallets.balance` CHECK constraint
**How:** The debit path throws `INSUFFICIENT_FUNDS` if `balance < amount`. The
schema also enforces `balance >= 0`.
**Test:** rapid concurrent debits → only as many as the balance allows succeed.

---

### Rule 3 — A successful service submission immediately debits the client's wallet

**Where:** `App\Services\SubmissionService::submit()`
**How:** `WalletService::debitTx()` runs inside the same transaction as the
submission insert. If the debit fails, the submission is rolled back.
**Test:** after submitting, the wallet ledger shows one debit and the balance
drops by exactly the service price.

---

### Rule 4 — Every submission receives exactly one unique tracking ID

**Where:** `App\Services\TrackingIdService::generate()`
**How:** Uses the `tracking_sequences` table with a `SELECT … FOR UPDATE`
increment per year. Format: `PREFIX-YYYY-NNNNNN`.
**Test:** two clients submit simultaneously → both get IDs, never duplicates.

---

### Rule 5 — Only one admin can own a task at a time

**Where:** `App\Services\TaskLockService::claim()`
**How:** `SELECT … FOR UPDATE` on the submission row, then a conditional
`UPDATE submissions SET assigned_admin_id = ? WHERE status='pending' AND
assigned_admin_id IS NULL`, then verify `affected_rows === 1`.
**Test:** two admins POST `/api/claim-task` with the same ID → exactly one 200,
one 409.

---

### Rule 6 — A task automatically returns to the queue when its deadline expires

**Where:** `App\Services\TaskLockService::sweepExpired()`
**How:** Called on every admin dashboard and service-queue view (lazy sweep)
**and** by `cron/release_expired_tasks.php`. It uses a conditional UPDATE to
make the release idempotent.
**Test:** set a submission's `deadline_at` to the past; reload the admin
dashboard → status flips to `pending`, `release_count` increments.

---

### Rule 7 — An admin earns commission only when the task is successfully completed

**Where:** `App\Services\TaskCompletionService::complete()`
**How:** Commission is credited inside the same transaction that flips the
submission to `completed`. Reporting errors or releasing tasks never credits.
**Test:** complete a task → `commission_transactions` gets one `earned` row and
the admin's balance increases by the snapshot amount.

---

### Rule 8 — Commission withdrawals require Super Admin approval

**Where:** `App\Services\WithdrawalService::approve() / reject() / markPaid()`
**How:** These methods are only reachable from `/superadmin/withdrawals`, guarded
by `Auth::requireSuperAdmin()`. Admins can only **request** and **cancel**.
**Test:** as an admin, attempt to POST to a hypothetical approve endpoint →
redirected by the guard.

---

### Rule 9 — Every financial transaction must have a ledger record

**Where:**
- Wallet: `wallet_transactions` (every credit/debit)
- Commission: `commission_transactions` (earned, withdrawal_request,
  withdrawal_reversal, withdrawal, adjustment, reversal)
- Payments: `payments` + ledger on finalisation

**How:** Every service-layer method that mutates a balance writes the
corresponding ledger row inside the same transaction.
**Test:** run `scripts/verify_all.php` → reconciles exactly.

---

### Rule 10 — Every administrative action affecting money or task ownership is auditable

**Where:** `App\Services\AuditService` + `audit_logs` table
**How:** Every service calls `audit->log()` with action, entity type/id,
old/new values, actor, IP, UA.
**Test:** sign in as super admin, view `/superadmin/audit-logs`, filter by
`TASK_CLAIMED`, `WITHDRAWAL_PAID`, `SERVICE_UPDATED` → rows present with diffs.

---

### Rule 11 — Clients can only access their own submissions and documents

**Where:** `client/submission-view.php`, `download-document.php`,
`SubmissionRepository::paginateForUser()`
**How:** Every read filters by `user_id = current`. Downloads verify the
parent submission belongs to the caller.
**Test:** sign in as client B, paste a URL for client A's submission or
document → 403 / redirect + audit entry.

---

### Rule 12 — Admins cannot change service prices

**Where:** Route separation. Every price-changing endpoint lives under
`/superadmin/services` and requires the Super Admin guard.
**Test:** as an admin, view `/superadmin/service-edit?id=1` → redirected to
`/superadmin/login`.

---

### Rule 13 — Admins cannot approve their own withdrawal requests

**Where:** Route separation + role model. Admins have no path to the approve
endpoints; Super Admins have no `admins.id` row so they can't be the owner of
a withdrawal.
**Test:** as an admin, try to reach `/superadmin/withdrawals` → 403.

---

### Rule 14 — Super Admin has authority over services, administrators, withdrawals and system configuration

**Where:**
- Services: `/superadmin/services`
- Admins: `/superadmin/admins`
- Withdrawals: `/superadmin/withdrawals`
- Settings: `/superadmin/settings`
- Everything under `/superadmin/*` requires `Auth::requireSuperAdmin()`.

**Test:** sign in as Super Admin → all pages reachable. Sign in as admin → all
`/superadmin/*` requests redirect.

---

### Rule 15 — Deactivated users cannot authenticate

**Where:** `Auth::requireClient() / requireAdmin() / requireSuperAdmin()`
**How:** Each guard re-reads the row and rejects any status other than `active`.
Deactivating an admin also releases any tasks they hold via
`TaskLockService::releaseAllForAdmin()`.
**Test:** deactivate an admin mid-session → next request redirects to login.
Deactivate an admin with a locked task → the task returns to the pool and the
`release_count` increments.
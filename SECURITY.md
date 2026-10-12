# Security

The platform follows a defence-in-depth model. This document lists what is
protected, how, and where in the code.

---

## 1. Authentication

- Passwords are hashed with `password_hash($pwd, PASSWORD_DEFAULT)` (bcrypt).
  No plaintext or reversible hashes are ever stored.
- Three **independent** auth guards: client, admin, super admin. Sessions hold
  separate keys (`client_id`, `admin_id`, `superadmin_id`).
- `session_regenerate_id(true)` is called on every privilege change (login,
  logout, password reset).
- Cookie attributes: `HttpOnly`, `SameSite=Strict` on admin/superadmin,
  `SameSite=Lax` on client, `Secure` when HTTPS.
- Login throttling via the `login_attempts` table (`RateLimiter`). Default
  lockout: 5 failed attempts per identifier, 15 minutes.
- Deactivated accounts are blocked by `Auth::require*()`. Login also checks
  `status = 'active'` explicitly.
- Password reset tokens are single-use, expire after 1 hour, stored as
  SHA-256 hashes, and invalidate any other outstanding tokens for the same user.
- `must_change_password` flag forces a password change at next login.

## 2. Authorisation

- Every protected page begins with a guard call: `Auth::requireClient()`,
  `Auth::requireAdmin()`, or `Auth::requireSuperAdmin()`.
- Every resource-level endpoint re-checks ownership:
  - `client/submission-view.php` → `submissions.user_id = client.id`
  - `download-document.php` → `documents.submission_id` belongs to client, or
    caller is admin/super admin
  - `TaskCompletionService::complete()` → `submissions.assigned_admin_id = admin.id`
  - `WithdrawalService::cancelByAdmin()` → `withdrawals.admin_id = admin.id`
- Super admin routes are **unreachable** from an admin session and vice versa.

## 3. Input validation

- Every POST handler uses `App\Core\Validator` or a service-level validator.
- All SQL uses PDO prepared statements with named placeholders.
  `PDO::ATTR_EMULATE_PREPARES = false` to force real server-side prepares.
- Dynamic service fields (`DynamicForm`) run type-specific validation
  (email format, numeric, regex, max length, select-option membership).
- File uploads: extension whitelist + MIME check via `finfo` + size limit +
  random `stored_name` + SHA-256 checksum + stored outside web root.

## 4. Output encoding

- All dynamic HTML output passes through `e()` (`htmlspecialchars`).
- JSON responses use `json_encode` with `JSON_UNESCAPED_*` flags — never raw
  string interpolation.
- `download-document.php` sets `Content-Security-Policy: default-src 'none'`
  on the response and forces download via `Content-Disposition: attachment`.

## 5. CSRF

- Every state-changing form includes `Csrf::field()`.
- Every POST handler calls `Csrf::verifyOrFail()` before any mutation.
- Tokens rotate on login and on password change.
- AJAX requests send the CSRF token via `X-CSRF-Token` header (see
  `public/api/claim-task.php`).

## 6. Session security

- `session.use_strict_mode = 1` — PHP rejects client-supplied session IDs.
- `session.use_only_cookies = 1` — no URL session IDs.
- Idle timeout: `SESSION_LIFETIME` seconds (default 7200 = 2 hours).
- For `/admin/*` routes, the session is bound to the first-seen IP address.
  Client sessions are not bound (mobile IPs change frequently).

## 7. Financial integrity

- Money is `DECIMAL(15,2)` in MySQL — never `FLOAT`.
- Wallet mutations are atomic: `SELECT … FOR UPDATE`, check balance, update,
  insert ledger row, all inside one `Database::transaction()`.
- Admin commission credits and withdrawal reservations use the same pattern.
- Idempotency: every payment and every wallet credit carries a UNIQUE
  `transaction_ref`; the payment finalisation path re-checks `status` inside
  the lock and short-circuits on repeats.
- Reconciliation: `scripts/verify_all.php` recomputes every balance from the
  ledgers and reports mismatches.

## 8. Task locking

- Atomic claim: `SELECT … FOR UPDATE` on the submission row, then
  `UPDATE submissions … WHERE status='pending' AND assigned_admin_id IS NULL`,
  then check `affected_rows === 1`.
- Two admins racing: exactly one wins. The other receives HTTP 409.
- Timeout is enforced server-side in `TaskLockService::sweepExpired()`.

## 9. Payments

- Webhook signatures validated:
  - Paystack: HMAC-SHA512 over the raw body with the secret key.
  - Flutterwave: constant-time comparison against the shared `verif-hash`.
- Amounts are compared to the intent — a mismatch rejects the credit.
- Gateway references are UNIQUE — replaying a webhook is a no-op.
- Return URL flow is idempotent: it goes through the same `finaliseFromGateway()`
  as the webhook.

## 10. File storage

- Uploads live in `storage/uploads/`, **outside** the DocumentRoot.
- `.htaccess` in `storage/` denies all access and disables PHP engines.
- Downloads go through `download-document.php` after an auth + ownership check.
- Response headers on downloads: `Content-Security-Policy: default-src 'none'`,
  `X-Content-Type-Options: nosniff`, `Content-Disposition: attachment`.

## 11. Headers

Emitted from `App\Core\Response::emitSecurityHeaders()` **and** duplicated in
`public/.htaccess`:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()`
- `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://checkout.paystack.com https://checkout.flutterwave.com; …; frame-ancestors 'none'`
- `Strict-Transport-Security` (HTTPS only)

`X-Powered-By` and `Server` are removed.

## 12. Error handling

- `display_errors` is off whenever `APP_DEBUG=false`.
- Errors are logged to `storage/logs/php-error.log`.
- Application-level errors go through `App\Core\Logger` → `storage/logs/app-YYYY-MM-DD.log`.
- The production bootstrap runs `security_preflight()`, which fails fast if a
  sensitive directory is reachable under the DocumentRoot.

## 13. Audit trail

Every sensitive action is written to `audit_logs` with `old_values` /
`new_values` where applicable. Covered events include:

- `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGOUT`
- `REGISTER`, `PASSWORD_RESET_REQUESTED`, `PASSWORD_RESET_COMPLETED`, `PASSWORD_CHANGED`
- `WALLET_CREDIT`, `WALLET_DEBIT`
- `SUBMISSION_CREATED`, `TRACKING_LOOKUP_SUCCESS`, `TRACKING_LOOKUP_DENIED`
- `TASK_CLAIMED`, `TASK_CLAIM_DENIED`, `TASK_RELEASED`, `TASK_RELEASED_SYSTEM`,
  `TASK_EXPIRED`, `TASK_COMPLETED`, `TASK_ERROR_REPORTED`,
  `TASK_COMPLETE_DENIED*`
- `COMMISSION_EARNED`
- `WITHDRAWAL_REQUESTED`, `WITHDRAWAL_CANCELLED`, `WITHDRAWAL_APPROVED`,
  `WITHDRAWAL_REJECTED`, `WITHDRAWAL_PAID`
- `PAYMENT_INITIATED`, `PAYMENT_WEBHOOK_RECEIVED`, `PAYMENT_VERIFIED`,
  `PAYMENT_REJECTED`, `PAYMENT_DUPLICATE_IGNORED`, `PAYMENT_AMOUNT_MISMATCH`
- `SERVICE_CREATED`, `SERVICE_UPDATED`, `SERVICE_STATUS_CHANGED`,
  `SERVICE_FIELD_*`
- `ADMIN_CREATED`, `ADMIN_UPDATED`, `ADMIN_STATUS_CHANGED`,
  `ADMIN_PASSWORD_RESET`, `ADMIN_FORCE_PASSWORD_CHANGE`, `ADMIN_DELETED`
- `CLIENT_STATUS_CHANGED`
- `SETTING_UPDATED`
- `EXPORT_GENERATED`
- `DOCUMENT_DOWNLOADED`, `UNAUTHORIZED_DOWNLOAD_ATTEMPT`
- `UNAUTHORIZED_SUBMISSION_VIEW`

## 14. Static analysis

`scripts/security_check.php` scans for:

- Dangerous functions (`eval`, `system`, `exec`, …)
- Dynamic `include`/`require`
- `extract()` / single-argument `parse_str()`
- Direct echoes of superglobals
- SQL string interpolation
- `move_uploaded_file` targets outside `storage/uploads/`
- `display_errors=On` outside `bootstrap.php`
- Hard-coded password literals
- Missing `.htaccess` in sensitive folders
- World-readable `.env`; world-writable `storage/`

Run it before every deploy; hook it into CI.

## 15. OWASP Top 10 (2021) mapping

| ID | Category | Coverage |
|---|---|---|
| A01 | Broken Access Control | Guards on every page; ownership checks on every resource; separate portals; verified by `security_check.php` |
| A02 | Cryptographic Failures | `password_hash`, HSTS, `Secure` cookies, secrets outside web root |
| A03 | Injection | PDO prepared statements only; `e()` output escaping; scanner flags string-concatenated SQL |
| A04 | Insecure Design | Snapshot pricing; ledger-first model; funds reservation; atomic locking |
| A05 | Security Misconfiguration | Layered `.htaccess`; central headers; deny-all in sensitive dirs; `security_preflight()` |
| A06 | Vulnerable & Outdated Components | Bootstrap 5.3.3, jQuery 3.7.1 (both current); no server dependencies |
| A07 | Identification & Authentication Failures | Rate limiter; session regeneration; forced password change; deactivation releases tasks |
| A08 | Software & Data Integrity Failures | SHA-256 on every upload; idempotent credit; audit trail |
| A09 | Security Logging & Monitoring Failures | `audit_logs`; error logs; CSV export; external monitor recommended |
| A10 | Server-Side Request Forgery | Outbound HTTP limited to two hard-coded gateway hosts |

## 16. Incident response

If you suspect a compromise:

1. **Rotate secrets** — `.env` keys, `PAYSTACK_SECRET_KEY`, `FLUTTERWAVE_SECRET_KEY`,
   the `sweep_token`.
2. **Invalidate sessions** — delete `storage/logs/../../*` session files, or
   flush the sessions table if `session.save_handler = database`. Simplest on
   XAMPP: delete `C:\xampp\tmp\sess_*`.
3. **Audit**: query `audit_logs` filtered by `ip_address` and date:
   ```sql
   SELECT * FROM audit_logs
    WHERE ip_address = '1.2.3.4' AND created_at >= '2026-09-20 00:00:00'
    ORDER BY id DESC;
   ```
4. **Check the ledger:**
   ```bash
   php scripts/verify_all.php
   ```
   Any output with `[FAIL]` identifies a wallet or commission inconsistency.
5. **Freeze withdrawals** while investigating — set
   `minimum_withdrawal` to a very high value in `system_settings`.
6. **Notify** affected users if personal data or money was exposed.
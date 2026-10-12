# Changelog

## Step 1 — Architecture & planning
Delivered the full architectural blueprint: system diagram, user-role matrix,
module list, page list, workflow diagrams, ERD description, project structure,
development sequence, and risk register.

## Step 2 — Database schema & seed
`database/schema.sql` (22 tables, foreign keys, CHECK constraints, `DECIMAL(15,2)` money)
and `database/seed.sql` (10 initial services, dynamic fields, 26 settings).
`scripts/create_super_admin.php` for the first production admin.

## Step 3 — Core framework & authentication
Config loader (`.env`), PSR-4 autoloader, error handler, session, CSRF,
rate limiter, validator, logger, settings cache, `Auth` guards for client/admin/super
admin, remember-me, registration, login, logout, forgot/reset password pages for
all three roles, Bootstrap 5 auth layout.

## Step 4 — Client dashboard & wallet
Dashboard stats, wallet page, bank-transfer funding with proof upload,
transaction ledger, notifications inbox, profile, change-password, printable
receipts, secure document download endpoint.

## Step 5 — Services & submission engine
DB-driven service catalogue, dynamic per-service form builder at runtime,
`SubmissionService` with atomic wallet debit and snapshot pricing,
`TrackingIdService` (gap-free sequence), client submission list + detail, public
tracking with verification.

## Step 7 — Admin portal & task queue
Separate admin authentication, sidebar of active services with live counters,
per-service queue with filters, completed jobs page, notifications, profile,
change-password.

## Step 8 — Atomic task locking & timeout
`TaskLockService::claim()` (row-lock + conditional UPDATE), `/api/claim-task`,
`/api/task-timer`, lazy sweep + `/api/release-expired`, `cron/release_expired_tasks.php`,
`cron/deadline_warnings.php`. Idempotent release; advisory per-request guard.

## Step 9 — Processing page, document feedback & commission
`/admin/process-task` with countdown, remarks, multi-file upload;
`TaskCompletionService::complete()` performing the atomic completion + commission
credit + client notification; `reportError()` for the error path.

## Step 10 — Commission & withdrawal
`/admin/commissions` ledger view, `/admin/withdrawals` request list, `/admin/withdrawal-request`
form; `WithdrawalService` with reserve-on-request, cancel, and the primitives
Super Admin uses for approve/reject/mark-paid.

## Step 11 — Super Admin dashboard & withdrawal management
Platform stats with Today/Week/Month/Year/Custom/All ranges, revenue-by-service,
`/superadmin/withdrawals`, `/superadmin/payments` (bank-transfer verification),
`/superadmin/wallets`, `/superadmin/clients`, `/superadmin/client-view`,
`/superadmin/submissions`, `/superadmin/submission-view`.

## Step 12 — Service management & dynamic field builder
`/superadmin/services` (list, status flip), `/superadmin/service-edit` (create/update),
`/superadmin/service-fields` (add/edit/toggle/reorder/delete fields with live preview).
Price/commission changes are audit-logged and never retroactive — snapshot columns
in `submissions` guarantee this at the DB level.

## Step 13 — Admin user management
`/superadmin/admins` (list, filter, quick actions) and `/superadmin/admin-view`
(profile, status controls, password reset, force-change, task history, commission
ledger, withdrawal history). Deactivation auto-releases held tasks. Hard delete
is soft when history exists.

## Step 14 — Notifications, audit, reports & settings
`/superadmin/notifications`, `/superadmin/audit-logs` with diff modal,
`/superadmin/reports` with four tabs and CSV export per tab,
`/superadmin/export.php`, `/superadmin/settings` (all `system_settings` grouped,
validated, audited), Super Admin profile + change-password.

## Step 15 — Payment gateways & wallet funding
Real Paystack + Flutterwave integrations (`initialize`, `verify`, `handleWebhook`),
`OnlinePaymentService::initiate()` + idempotent `finaliseFromGateway()`,
`/payment/callback` return URL, `/api/webhook/{paystack,flutterwave}.php`,
client fund-wallet picker gated by `system_settings`.

## Step 16 — Security hardening
Central header emitter, CSP, HSTS, SameSite=Strict for admin portals,
production-only integrity preflight, hardened `.htaccess` at every level,
sanitised download filenames + strict CSP on downloads, `scripts/security_check.php`
static analyser, OWASP mapping.

## Step 17 — Testing & documentation
`docs/INSTALL.md`, `DEPLOYMENT.md`, `CRON.md`, `SECURITY.md`, `BUSINESS_RULES.md`,
`TEST_PLAN.md`, `CHANGELOG.md`, root `README.md`,
`scripts/verify_all.php`, `scripts/smoke_test.php`.
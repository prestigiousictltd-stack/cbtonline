# Functional Test Plan

Manual test matrix covering every module. Each test lists the action, the
expected UI outcome, and the database evidence to verify.

Run the whole matrix after any significant change. Target: 45–60 minutes end to end.

---

## 0. Setup

- Fresh database (schema + seed).
- One real Super Admin created via CLI.
- Two admins created via `/superadmin/admins` (Admin A, Admin B).
- One client funded with ₦20,000 (approve a bank-transfer proof).

---

## 1. Public site

| # | Action | Expected |
|---|---|---|
| 1.1 | Visit `/` | Landing page renders; no errors |
| 1.2 | Visit `/about`, `/contact`, `/services` | Each renders |
| 1.3 | Visit `/nonexistent` | 404 page |
| 1.4 | Try `/config/database.php` in the browser | 403 / 404 — file not served |
| 1.5 | Try `/storage/uploads/` | 403 / 404 |

---

## 2. Client registration & login

| # | Action | Expected |
|---|---|---|
| 2.1 | Register with a new email | Redirect to `/client/dashboard` |
| 2.2 | DB: `SELECT * FROM wallets WHERE user_id=(new id)` | One row, `balance=0.00` |
| 2.3 | DB: `SELECT title FROM notifications WHERE user_type='client'` | Welcome notification |
| 2.4 | Register with the same email again | Inline error "already registered" |
| 2.5 | Register with invalid email / short password | Inline errors on each field |
| 2.6 | Logout, log in with correct credentials | Redirect to dashboard |
| 2.7 | Log in with wrong password 5 times | 6th attempt: "Too many failed attempts" |
| 2.8 | Request password reset → use dev link | New password accepted; old rejected |
| 2.9 | Reuse the reset link | "invalid or expired" |

---

## 3. Wallet & funding

| # | Action | Expected |
|---|---|---|
| 3.1 | Client → `/client/wallet` | Shows ₦0.00 initially |
| 3.2 | `/client/fund-wallet` → Bank Transfer → upload a JPG, submit | Redirect to transactions, flash with reference |
| 3.3 | DB: `SELECT status FROM payments ORDER BY id DESC LIMIT 1` | `awaiting_verification` |
| 3.4 | Client wallet balance | Unchanged |
| 3.5 | Super Admin → `/superadmin/payments` → Approve | Flash; payment becomes `successful` |
| 3.6 | Client wallet balance | Increased by the amount; ledger row visible |
| 3.7 | Repeat 3.2 with a PDF | Same outcome |
| 3.8 | Attempt to upload a `.exe` renamed `.jpg` | Rejected (MIME check) |
| 3.9 | Attempt to upload a 10 MB file | Rejected (size) |

---

## 4. Services & submission

| # | Action | Expected |
|---|---|---|
| 4.1 | Client → `/client/services` | All active services with prices |
| 4.2 | Click Apply on a service | Dynamic form loads with the right fields |
| 4.3 | Change service in the dropdown | Page reloads with the new form |
| 4.4 | Submit with a required field empty | Inline error, no submission |
| 4.5 | Submit a service that costs more than the balance | "Insufficient wallet balance" + Fund button |
| 4.6 | Submit a valid service | Redirect to submission-view with tracking ID `PREFIX-YYYY-NNNNNN` |
| 4.7 | DB: `SELECT * FROM submissions WHERE id=...` | Snapshot columns populated; `status='pending'` |
| 4.8 | Wallet ledger | Debit row for the exact price |
| 4.9 | `/client/submissions` | Row present with the correct status |
| 4.10 | `/track` with tracking ID + correct email | Status shown |
| 4.11 | `/track` with wrong email | "No submission found" |
| 4.12 | `/track` with someone else's tracking ID | Same generic message |

---

## 5. Admin task queue & claiming

| # | Action | Expected |
|---|---|---|
| 5.1 | Admin A → `/admin/dashboard` | Service cards; pending count increments after 4.6 |
| 5.2 | Admin A → `/admin/service?id=<id>` | The pending submission listed with a green PROCESS button |
| 5.3 | Click PROCESS | Redirect to `/admin/process-task?id=X` |
| 5.4 | DB: `SELECT status, assigned_admin_id, deadline_at` | `processing`, A's id, `deadline_at` = claimed + snapshot |
| 5.5 | Admin B (other browser) opens the queue | Button locked; "Locked by Admin A" |
| 5.6 | Admin B clicks a stale PROCESS (or curls the endpoint) | HTTP 409, `ALREADY_CLAIMED` |
| 5.7 | Force the submission's `deadline_at` to the past; Admin B refreshes dashboard | Status flips to `pending`, `release_count = 1` |
| 5.8 | Admin B clicks PROCESS | Redirect to process-task |

---

## 6. Task completion & commission

| # | Action | Expected |
|---|---|---|
| 6.1 | Admin B fills remarks, attaches a JPG + PDF, clicks COMPLETE TASK | Redirect to completed-jobs; flash with commission amount |
| 6.2 | DB: `submissions.status` | `completed`, `completed_at` set |
| 6.3 | DB: `SELECT COUNT(*) FROM documents WHERE submission_id=X` | 2 |
| 6.4 | DB: `SELECT COUNT(*) FROM feedback WHERE submission_id=X` | 1 |
| 6.5 | DB: `commission_transactions` | One `earned` row for the correct amount |
| 6.6 | DB: `admins.commission_balance` for Admin B | Increased by the commission |
| 6.7 | Client → `/client/submission-view?id=X` | Remarks and 2 document download links |
| 6.8 | Client clicks a download | File downloads; `audit_logs` has `DOCUMENT_DOWNLOADED` |
| 6.9 | Client 2 pastes the download URL | 403 |
| 6.10 | Client 2 pastes the submission-view URL | Redirect to their own submissions |

---

## 7. Error reporting

| # | Action | Expected |
|---|---|---|
| 7.1 | Claim a new task; click REPORT ERROR with remarks | Status → `pending`; feedback row created |
| 7.2 | Claim another; REPORT ERROR with disposition=hold | Status → `error_detected` |
| 7.3 | Client sees the error remark | `/client/submission-view` shows the remark |

---

## 8. Commission & withdrawals

| # | Action | Expected |
|---|---|---|
| 8.1 | Admin A → `/admin/commissions` | Balance, reserved, available tiles |
| 8.2 | Request ₦5,000 | Redirect to withdrawals; status Pending |
| 8.3 | Admin A → `/admin/commissions` | Reserved +5000; Available −5000; Balance unchanged |
| 8.4 | Request more than available | Inline error with available figure |
| 8.5 | Cancel the pending withdrawal | Status → `rejected`; Reserved released |
| 8.6 | Create another pending withdrawal | Requested for Super Admin tests |
| 8.7 | Super Admin → `/superadmin/withdrawals` → Approve | Status Approved |
| 8.8 | Mark as paid with ref `TEST-001` | Status Paid |
| 8.9 | DB: `admins.commission_balance` and `commission_reserved` | Both reduced by the amount |
| 8.10 | Admin A `/admin/commissions` | Ledger shows `withdrawal_request` `successful` |

---

## 9. Super Admin — services

| # | Action | Expected |
|---|---|---|
| 9.1 | `/superadmin/services` → New service | Form renders |
| 9.2 | Save with price < commission | Inline error |
| 9.3 | Save a valid service | New row appears; slug auto-generated if blank |
| 9.4 | `/superadmin/service-fields?service=<id>` | No fields; Add field button |
| 9.5 | Add a text field required; add a select field with options | Both save; preview shows them |
| 9.6 | Toggle a field inactive | Preview removes it |
| 9.7 | Reorder fields with up/down | Order persists |
| 9.8 | Attempt to change the key of a field already used | Refused with an inline message |
| 9.9 | Attempt to delete an already-used field | Soft-deactivated; historic data intact |

---

## 10. Super Admin — admins

| # | Action | Expected |
|---|---|---|
| 10.1 | Add admin (leave password blank) | Yellow panel with a one-time password |
| 10.2 | Sign in as that admin | Redirect to `/admin/change-password` |
| 10.3 | Deactivate the admin while they hold a task | `(N tasks released)` message; queue shows the task available |
| 10.4 | Reactivate | Admin can sign in again |
| 10.5 | Reset the admin's password | New password shown once; old one rejected at login |
| 10.6 | Delete an admin with history | Deactivated instead; green flash explains why |
| 10.7 | Delete an admin with no history | Row removed |
| 10.8 | Validation: duplicate email, invalid username, bad phone | Inline errors |

---

## 11. Reports & audit logs

| # | Action | Expected |
|---|---|---|
| 11.1 | `/superadmin/reports` → Revenue tab | By-service and by-day tables |
| 11.2 | Switch range to Today / Week / Month / Year / Custom / All | Tables update |
| 11.3 | Export each tab to CSV | Downloads; opens correctly |
| 11.4 | `/superadmin/audit-logs` → filter by `WITHDRAWAL_PAID` | Only those rows |
| 11.5 | Click `<>` on a row with a diff | Pretty-printed JSON |
| 11.6 | Export the filtered audit log | CSV with those rows only |

---

## 12. Settings

| # | Action | Expected |
|---|---|---|
| 12.1 | `/superadmin/settings` | All groups render |
| 12.2 | Change `site_name` | Save → next page shows the new name |
| 12.3 | Change `minimum_withdrawal` to `1000` | Admin withdrawal below ₦1,000 rejected |
| 12.4 | Disable `payments_enabled_online` | Client funding page hides Paystack/FLW |
| 12.5 | Disable `payments_enabled_bank` too | "No payment methods" warning shown |
| 12.6 | Re-enable both | Original state restored |
| 12.7 | Attempt to set `login_max_attempts=abc` | Inline error |
| 12.8 | `/superadmin/audit-logs` filter `SETTING_UPDATED` | Rows with old/new values |

---

## 13. Payments (online)

Prereq: Paystack test keys configured.

| # | Action | Expected |
|---|---|---|
| 13.1 | Client → `/client/fund-wallet` → Paystack tab | Amount form |
| 13.2 | Pay ₦500 with test card `4084 0840 8408 4081` | Redirect to callback; "Payment successful" |
| 13.3 | Client wallet | Credited exactly ₦500 |
| 13.4 | Refresh the callback URL | Same message; balance unchanged |
| 13.5 | Manually POST a webhook with valid signature | 200, `duplicate: true` |
| 13.6 | Manually POST a webhook with wrong signature | 400 |
| 13.7 | Manually POST a webhook with wrong amount | 500, `PAYMENT_AMOUNT_MISMATCH` audit |

---

## 14. Security

| # | Action | Expected |
|---|---|---|
| 14.1 | Client visits `/admin/dashboard` | Redirect to `/admin/login` |
| 14.2 | Admin visits `/superadmin/dashboard` | Redirect to `/superadmin/login` |
| 14.3 | POST any form without CSRF | Redirect with session warning |
| 14.4 | Paste a URL to another client's document | 403 + audit entry |
| 14.5 | Paste a URL to `storage/uploads/...` | 403 |
| 14.6 | `php scripts/security_check.php` | Zero HIGH findings |
| 14.7 | `php scripts/verify_all.php` | Zero FAIL |
| 14.8 | Check browser headers on `/` | CSP + X-Frame-Options + no X-Powered-By |
| 14.9 | Admin cookie in DevTools | `HttpOnly`, `SameSite=Strict` |

---

## 15. Cron

| # | Action | Expected |
|---|---|---|
| 15.1 | Run `php cron/release_expired_tasks.php` twice | First: N released; second: 0 |
| 15.2 | Run `php cron/deadline_warnings.php 60` | Notifications for admins with <60 min |
| 15.3 | `GET /api/release-expired?token=<token>` | JSON success |
| 15.4 | `GET /api/release-expired?token=wrong` | 403 |

---

## 16. CLI verifiers

| # | Action | Expected |
|---|---|---|
| 16.1 | `php scripts/verify_all.php` | `OK` with counts |
| 16.2 | Manually corrupt a wallet (make it inconsistent), rerun | `[FAIL]` reported |
| 16.3 | `php scripts/smoke_test.php` | All stages pass |
-- =============================================================================
-- CBTOnline — Pre-launch cleanup (DELETE-based, FK-safe)
-- =============================================================================
-- Replace <DB> below with your actual database name (see Step 1).
-- Replace the super-admin email with the one you want to KEEP.
-- =============================================================================

USE servicehub_ng;

-- ----------------------------------------------------------------
-- 1. Delete in child-first order so FK constraints stay satisfied
-- ----------------------------------------------------------------

-- Commission and withdrawals (children first)
DELETE FROM commission_transactions;
DELETE FROM withdrawals;

-- Wallet ledger and wallets
DELETE FROM wallet_transactions;
DELETE FROM wallets;

-- Documents, feedback, status history, field values, submissions
DELETE FROM documents;
DELETE FROM feedback;
DELETE FROM submission_status_history;
DELETE FROM submission_field_values;
DELETE FROM submissions;

-- Payment proofs and payments
DELETE FROM payment_proofs;
DELETE FROM payments;

-- Notifications and audit logs
DELETE FROM notifications;
DELETE FROM audit_logs;

-- Login attempts and password resets
DELETE FROM login_attempts;
DELETE FROM password_resets;

-- Users last (referenced by wallets, submissions, payments, etc.)
DELETE FROM users;

-- ----------------------------------------------------------------
-- 2. Accounts — keep only the Super Admin you'll log in with
-- ----------------------------------------------------------------
-- Change the email below to YOUR production super admin email.
-- DELETE FROM super_admins WHERE email <> 'YOUR_EMAIL@example.com';

-- Admins — wipe all (recreate via Super Admin UI after launch).
-- To keep specific admins instead, replace with:
   DELETE FROM admins WHERE email NOT IN ('admin1@example.com','admin2@example.com');
-- DELETE FROM admins;

-- ----------------------------------------------------------------
-- 3. Reset the tracking sequence for the current year
-- ----------------------------------------------------------------
DELETE FROM tracking_sequences;
INSERT INTO tracking_sequences (year, last_number)
VALUES (YEAR(UTC_TIMESTAMP()), 0);

-- ----------------------------------------------------------------
-- 4. Reset AUTO_INCREMENT so the first live id is 1
-- ----------------------------------------------------------------
ALTER TABLE users                     AUTO_INCREMENT = 1;
ALTER TABLE admins                    AUTO_INCREMENT = 1;
ALTER TABLE super_admins              AUTO_INCREMENT = 1;
ALTER TABLE password_resets           AUTO_INCREMENT = 1;
ALTER TABLE login_attempts            AUTO_INCREMENT = 1;
ALTER TABLE wallets                   AUTO_INCREMENT = 1;
ALTER TABLE payments                  AUTO_INCREMENT = 1;
ALTER TABLE payment_proofs            AUTO_INCREMENT = 1;
ALTER TABLE submissions               AUTO_INCREMENT = 1;
ALTER TABLE submission_field_values   AUTO_INCREMENT = 1;
ALTER TABLE submission_status_history AUTO_INCREMENT = 1;
ALTER TABLE feedback                  AUTO_INCREMENT = 1;
ALTER TABLE documents                 AUTO_INCREMENT = 1;
ALTER TABLE wallet_transactions       AUTO_INCREMENT = 1;
ALTER TABLE withdrawals               AUTO_INCREMENT = 1;
ALTER TABLE commission_transactions   AUTO_INCREMENT = 1;
ALTER TABLE notifications             AUTO_INCREMENT = 1;
ALTER TABLE audit_logs                AUTO_INCREMENT = 1;
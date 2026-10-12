USE CBTOnline;

SELECT 'users' AS tbl, COUNT(*) AS rows_ FROM users
UNION ALL SELECT 'admins',                 COUNT(*) FROM admins
UNION ALL SELECT 'super_admins',           COUNT(*) FROM super_admins
UNION ALL SELECT 'password_resets',        COUNT(*) FROM password_resets
UNION ALL SELECT 'login_attempts',         COUNT(*) FROM login_attempts
UNION ALL SELECT 'services',               COUNT(*) FROM services
UNION ALL SELECT 'service_fields',         COUNT(*) FROM service_fields
UNION ALL SELECT 'wallets',                COUNT(*) FROM wallets
UNION ALL SELECT 'payments',               COUNT(*) FROM payments
UNION ALL SELECT 'payment_proofs',         COUNT(*) FROM payment_proofs
UNION ALL SELECT 'tracking_sequences',     COUNT(*) FROM tracking_sequences
UNION ALL SELECT 'submissions',            COUNT(*) FROM submissions
UNION ALL SELECT 'submission_field_values',COUNT(*) FROM submission_field_values
UNION ALL SELECT 'submission_status_history',COUNT(*) FROM submission_status_history
UNION ALL SELECT 'feedback',               COUNT(*) FROM feedback
UNION ALL SELECT 'documents',              COUNT(*) FROM documents
UNION ALL SELECT 'wallet_transactions',    COUNT(*) FROM wallet_transactions
UNION ALL SELECT 'withdrawals',            COUNT(*) FROM withdrawals
UNION ALL SELECT 'commission_transactions',COUNT(*) FROM commission_transactions
UNION ALL SELECT 'notifications',          COUNT(*) FROM notifications
UNION ALL SELECT 'audit_logs',             COUNT(*) FROM audit_logs
UNION ALL SELECT 'system_settings',        COUNT(*) FROM system_settings;
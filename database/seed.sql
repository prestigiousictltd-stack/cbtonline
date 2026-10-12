-- =============================================================================
-- ServiceHub NG — Seed Data
-- Run AFTER schema.sql
-- =============================================================================

USE `servicehub_ng`;
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1. SUPER ADMIN
-- -----------------------------------------------------------------------------
-- !! SECURITY WARNING !!
-- This seeds a DEMO super admin. The password below is the literal string:
--     password
-- and the account is flagged must_change_password = 1 so the first login
-- forces a password change. For production, DELETE this row and create
-- the first Super Admin with the provided CLI script:
--     php scripts/create_super_admin.php
--
-- The bcrypt hash below is the standard well-known hash for "password".
-- -----------------------------------------------------------------------------
INSERT INTO `super_admins`
  (`id`, `full_name`, `email`, `username`, `phone`, `password_hash`, `status`, `must_change_password`)
VALUES
  (1,
   'System Administrator',
   'superadmin@example.com',
   'superadmin',
   '08000000000',
   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
   'active',
   1);

-- -----------------------------------------------------------------------------
-- 2. SERVICES (initial 10)
-- -----------------------------------------------------------------------------
INSERT INTO `services`
  (`id`, `name`, `slug`, `description`, `short_description`, `price`, `admin_commission`,
   `processing_minutes`, `duration_label`, `icon`, `sort_order`, `status`)
VALUES
  (1,  'JAMB O\'Level Upload', 'jamb-olevel-upload',
       'Upload and process your O\'Level result information on the JAMB portal.',
       'Upload O\'Level result to JAMB profile.',
       5000.00, 500.00, 1440, '24 Hours', 'jamb.svg', 1, 'active'),

  (2,  'WAEC/NECO Result Upload', 'waec-neco-result-upload',
       'Upload your WAEC or NECO result to the relevant admission portal.',
       'Upload WAEC/NECO result to admission portal.',
       7500.00, 750.00, 1440, '24 Hours', 'waec.svg', 2, 'active'),

  (3,  'JAMB Original Result', 'jamb-original-result',
       'Request your original JAMB result slip.',
       'Request original JAMB result slip.',
       8000.00, 800.00, 720, '12 Hours', 'jamb.svg', 3, 'active'),

  (4,  'JAMB Change of Course/Institution', 'jamb-change-of-course',
       'Change your course or institution on the JAMB portal.',
       'Change JAMB course or institution.',
       3500.00, 350.00, 1440, '24 Hours', 'jamb.svg', 4, 'active'),

  (5,  'JAMB Regularisation', 'jamb-regularisation',
       'Regularise your JAMB admission for NYSC and other purposes.',
       'JAMB regularisation for NYSC.',
       15000.00, 1500.00, 2880, '48 Hours', 'jamb.svg', 5, 'active'),

  (6,  'JAMB Password Reset', 'jamb-password-reset',
       'Reset your JAMB profile password.',
       'Reset JAMB profile password.',
       2000.00, 250.00, 360, '6 Hours', 'jamb.svg', 6, 'active'),

  (7,  'POST-UTME Application', 'post-utme-application',
       'Apply for POST-UTME screening at your chosen institution.',
       'Apply for POST-UTME screening.',
       4500.00, 450.00, 2880, '48 Hours', 'post-utme.svg', 7, 'active'),

  (8,  'WAEC Result PIN', 'waec-result-pin',
       'Purchase a WAEC result checker PIN.',
       'Purchase WAEC result checker PIN.',
       3500.00, 350.00, 720, '12 Hours', 'waec.svg', 8, 'active'),

  (9,  'NECO Result PIN', 'neco-result-pin',
       'Purchase a NECO result checker PIN.',
       'Purchase NECO result checker PIN.',
       3500.00, 350.00, 720, '12 Hours', 'neco.svg', 9, 'active'),

  (10, 'NABTEB Result PIN', 'nabteb-result-pin',
       'Purchase a NABTEB result checker PIN.',
       'Purchase NABTEB result checker PIN.',
       3500.00, 350.00, 720, '12 Hours', 'nabteb.svg', 10, 'active');

-- -----------------------------------------------------------------------------
-- 3. SERVICE FIELDS
-- Every service gets: full_name, exam_number, additional_information.
-- Service-specific fields are added below each block.
-- -----------------------------------------------------------------------------

-- Common fields for all services (full_name, exam_number, additional_information)
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`, `help_text`,
   `is_required`, `max_length`, `sort_order`, `status`)
SELECT s.id, 'full_name', 'Full Name', 'text',
       'Enter full name as it appears on your document', NULL,
       1, 150, 1, 'active'
FROM `services` s;

INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`, `help_text`,
   `is_required`, `max_length`, `sort_order`, `status`)
SELECT s.id, 'exam_number', 'Exam Number', 'text',
       'Enter your exam number', NULL,
       1, 60, 2, 'active'
FROM `services` s;

INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`, `help_text`,
   `is_required`, `max_length`, `sort_order`, `status`)
SELECT s.id, 'additional_information', 'Additional Information', 'textarea',
       'Any additional information the processing team should know', NULL,
       0, 2000, 90, 'active'
FROM `services` s;

-- ---- Service-specific fields -------------------------------------------------

-- 1. JAMB O'Level Upload: subjects, exam_year
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`, `help_text`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (1, 'subjects', 'Subjects to Upload', 'textarea',
   'e.g. English, Mathematics, Biology, Chemistry', 'List the subjects you want uploaded',
   1, 500, 10),
  (1, 'exam_year', 'Exam Year', 'number',
   'e.g. 2023', NULL, 1, 4, 11);

-- 2. WAEC/NECO Result Upload: exam_type, exam_year
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `field_options`, `placeholder`,
   `is_required`, `sort_order`)
VALUES
  (2, 'exam_type', 'Exam Type', 'select',
   JSON_ARRAY('WAEC','NECO','NABTEB','GCE'),
   NULL, 1, 10),
  (2, 'exam_year', 'Exam Year', 'number', NULL,
   'e.g. 2023', 1, 11);

-- 3. JAMB Original Result: registration_number, exam_year
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (3, 'registration_number', 'JAMB Registration Number', 'text',
   'e.g. 12345678AB', 1, 30, 10),
  (3, 'exam_year', 'JAMB Exam Year', 'number',
   'e.g. 2023', 1, 4, 11);

-- 4. JAMB Change of Course/Institution
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (4, 'current_course', 'Current Course', 'text', 'e.g. Computer Science', 1, 150, 10),
  (4, 'new_course', 'New Course', 'text', 'e.g. Software Engineering', 1, 150, 11),
  (4, 'new_institution', 'New Institution', 'text', 'e.g. University of Lagos', 1, 200, 12);

-- 5. JAMB Regularisation: registration_number, jamb_year
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (5, 'registration_number', 'JAMB Registration Number', 'text', 'e.g. 12345678AB', 1, 30, 10),
  (5, 'jamb_year', 'JAMB Year', 'number', 'e.g. 2020', 1, 4, 11);

-- 6. JAMB Password Reset: jamb_email, jamb_registration
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (6, 'jamb_email', 'JAMB Profile Email', 'email', 'you@example.com', 1, 190, 10),
  (6, 'jamb_registration', 'JAMB Registration Number', 'text', 'e.g. 12345678AB', 0, 30, 11);

-- 7. POST-UTME Application: institution, course
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `max_length`, `sort_order`)
VALUES
  (7, 'institution', 'Institution', 'text', 'e.g. University of Ibadan', 1, 200, 10),
  (7, 'course', 'Course', 'text', 'e.g. Medicine and Surgery', 1, 150, 11);

-- 8/9/10. Result PINs: quantity, exam_year
INSERT INTO `service_fields`
  (`service_id`, `field_key`, `field_label`, `field_type`, `placeholder`,
   `is_required`, `sort_order`)
VALUES
  (8, 'quantity',     'Quantity',  'number', 'e.g. 1', 1, 10),
  (8, 'exam_year',    'Exam Year', 'number', 'e.g. 2023', 0, 11),
  (9, 'quantity',     'Quantity',  'number', 'e.g. 1', 1, 10),
  (9, 'exam_year',    'Exam Year', 'number', 'e.g. 2023', 0, 11),
  (10,'quantity',     'Quantity',  'number', 'e.g. 1', 1, 10),
  (10,'exam_year',    'Exam Year', 'number', 'e.g. 2023', 0, 11);

-- -----------------------------------------------------------------------------
-- 4. SYSTEM SETTINGS
-- -----------------------------------------------------------------------------
INSERT INTO `system_settings`
  (`setting_key`, `setting_value`, `setting_group`, `value_type`, `description`, `is_public`)
VALUES
  ('site_name',                    'ServiceHub NG',                  'general',    'string',  'Public site name',                                  1),
  ('site_tagline',                 'Fast, secure digital services.', 'general',    'string',  'Short tagline shown on landing page',               1),
  ('site_email',                   'support@example.com',            'general',    'string',  'Primary contact email',                             1),
  ('site_phone',                   '+234 800 000 0000',              'general',    'string',  'Primary contact phone',                             1),
  ('site_address',                 'Lagos, Nigeria',                 'general',    'string',  'Business address',                                  1),
  ('currency',                     'NGN',                            'general',    'string',  'ISO currency code',                                 1),
  ('currency_symbol',              '₦',                              'general',    'string',  'Currency symbol',                                   1),
  ('timezone',                     'Africa/Lagos',                   'general',    'string',  'Display timezone',                                  0),

  ('tracking_id_prefix',           'ESC',                            'submission', 'string',  'Prefix used in tracking IDs',                       1),

  ('default_task_duration_minutes','1440',                           'submission', 'integer', 'Fallback processing duration when a service is missing one', 0),
  ('max_concurrent_claims',        '0',                              'submission', 'integer', '0 = unlimited. Per-admin override available on admins table.', 0),

  ('minimum_withdrawal',           '5000.00',                        'finance',    'decimal', 'Minimum commission withdrawal amount',              0),
  ('maximum_withdrawal',           '500000.00',                      'finance',    'decimal', 'Maximum single commission withdrawal amount',        0),
  ('platform_commission_percent',  '0.00',                           'finance',    'decimal', 'Optional extra platform cut (0 = disabled)',        0),

  ('maximum_upload_size_kb',       '5120',                           'uploads',    'integer', 'Max upload size per file, in KB',                   0),
  ('allowed_file_types',           'pdf,jpg,jpeg,png',               'uploads',    'string',  'Comma-separated allowed extensions',                0),

  ('login_max_attempts',           '5',                              'security',   'integer', 'Failed logins before lockout',                      0),
  ('login_lockout_minutes',        '15',                             'security',   'integer', 'Lockout duration in minutes',                       0),
  ('session_timeout_minutes',      '120',                            'security',   'integer', 'Idle session timeout in minutes',                   0),

  ('bank_transfer_details',
   JSON_OBJECT(
     'bank_name',      'Your Bank Name',
     'account_name',   'ServiceHub NG Ltd',
     'account_number', '0123456789',
     'instructions',   'Transfer the exact amount, then upload your payment proof.'
   ),
   'finance', 'json',
   'Bank details shown to clients on the funding page', 1),

  ('payments_enabled_online',      '1',                              'finance',    'boolean', 'Toggle online payment methods',                     1),
  ('payments_enabled_bank',        '1',                              'finance',    'boolean', 'Toggle bank transfer method',                       1);

-- -----------------------------------------------------------------------------
-- 5. TRACKING SEQUENCE FOR CURRENT YEAR
-- -----------------------------------------------------------------------------
INSERT INTO `tracking_sequences` (`year`, `last_number`)
VALUES (2026, 0)
ON DUPLICATE KEY UPDATE `last_number` = `last_number`;

-- =============================================================================
-- END seed.sql
-- =============================================================================
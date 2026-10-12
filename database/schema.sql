-- =============================================================================
-- ServiceHub NG — Complete MySQL 8 Database Schema
-- =============================================================================
-- Engine:    InnoDB
-- Charset:   utf8mb4 / utf8mb4_unicode_ci
-- Money:     DECIMAL(15,2) — never FLOAT
-- Time:      Stored in UTC; app converts to configured timezone
-- Author:    ServiceHub NG
-- Version:   1.0.0
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `servicehub_ng`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE `servicehub_ng`;

-- =============================================================================
-- 1. IDENTITY & ACCESS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- super_admins — platform owners
-- -----------------------------------------------------------------------------
CREATE TABLE `super_admins` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`             VARCHAR(150)    NOT NULL,
  `email`                 VARCHAR(190)    NOT NULL,
  `username`              VARCHAR(60)     NOT NULL,
  `phone`                 VARCHAR(30)     NOT NULL,
  `password_hash`         VARCHAR(255)    NOT NULL,
  `status`                ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `must_change_password`  TINYINT(1)      NOT NULL DEFAULT 0,
  `last_login_at`         DATETIME        DEFAULT NULL,
  `last_login_ip`         VARCHAR(45)     DEFAULT NULL,
  `remember_token_hash`   VARCHAR(100)    DEFAULT NULL,
  `created_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_super_admins_email`    (`email`),
  UNIQUE KEY `uq_super_admins_username` (`username`),
  KEY `idx_super_admins_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- users — clients
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`          VARCHAR(150)    NOT NULL,
  `email`              VARCHAR(190)    NOT NULL,
  `phone`              VARCHAR(30)     NOT NULL,
  `password_hash`      VARCHAR(255)    NOT NULL,
  `address`            VARCHAR(255)    DEFAULT NULL,
  `state`              VARCHAR(80)     DEFAULT NULL,
  `profile_picture`    VARCHAR(255)    DEFAULT NULL,
  `status`             ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `email_verified_at`  DATETIME        DEFAULT NULL,
  `must_change_password` TINYINT(1)    NOT NULL DEFAULT 0,
  `last_login_at`      DATETIME        DEFAULT NULL,
  `last_login_ip`      VARCHAR(45)     DEFAULT NULL,
  `remember_token_hash` VARCHAR(100)   DEFAULT NULL,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_status`     (`status`),
  KEY `idx_users_phone`      (`phone`),
  KEY `idx_users_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- admins — processing staff
-- -----------------------------------------------------------------------------
CREATE TABLE `admins` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`             VARCHAR(150)    NOT NULL,
  `email`                 VARCHAR(190)    NOT NULL,
  `username`              VARCHAR(60)     NOT NULL,
  `phone`                 VARCHAR(30)     NOT NULL,
  `password_hash`         VARCHAR(255)    NOT NULL,
  `commission_balance`    DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `commission_reserved`   DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `max_concurrent_tasks`  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
  `status`                ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `must_change_password`  TINYINT(1)      NOT NULL DEFAULT 0,
  `profile_picture`       VARCHAR(255)    DEFAULT NULL,
  `created_by`            BIGINT UNSIGNED DEFAULT NULL COMMENT 'super_admins.id',
  `last_login_at`         DATETIME        DEFAULT NULL,
  `last_login_ip`         VARCHAR(45)     DEFAULT NULL,
  `remember_token_hash`   VARCHAR(100)    DEFAULT NULL,
  `created_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_email`    (`email`),
  UNIQUE KEY `uq_admins_username` (`username`),
  KEY `idx_admins_status`     (`status`),
  KEY `idx_admins_created_at` (`created_at`),
  KEY `fk_admins_created_by`  (`created_by`),
  CONSTRAINT `chk_admins_balance_nonneg`  CHECK (`commission_balance`  >= 0),
  CONSTRAINT `chk_admins_reserved_nonneg` CHECK (`commission_reserved` >= 0),
  CONSTRAINT `fk_admins_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `super_admins`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- password_resets — for all three role types
-- -----------------------------------------------------------------------------
CREATE TABLE `password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('client','admin','super_admin') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `email`      VARCHAR(190)    NOT NULL,
  `token_hash` CHAR(64)        NOT NULL COMMENT 'SHA-256 of the raw token',
  `expires_at` DATETIME        NOT NULL,
  `used_at`    DATETIME        DEFAULT NULL,
  `ip_address` VARCHAR(45)     DEFAULT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_password_resets_token_hash` (`token_hash`),
  KEY `idx_password_resets_user`    (`user_type`,`user_id`),
  KEY `idx_password_resets_email`   (`email`,`user_type`),
  KEY `idx_password_resets_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- login_attempts — throttling
-- -----------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`   ENUM('client','admin','super_admin') NOT NULL,
  `identifier`  VARCHAR(190)    NOT NULL COMMENT 'email or username attempted',
  `ip_address`  VARCHAR(45)     NOT NULL,
  `user_agent`  VARCHAR(255)    DEFAULT NULL,
  `successful`  TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_identifier` (`identifier`,`user_type`,`created_at`),
  KEY `idx_attempts_ip`         (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 2. CATALOGUE
-- =============================================================================

-- -----------------------------------------------------------------------------
-- services — DB-driven service catalogue
-- -----------------------------------------------------------------------------
CREATE TABLE `services` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(150)    NOT NULL,
  `slug`                VARCHAR(160)    NOT NULL,
  `description`         TEXT            DEFAULT NULL,
  `short_description`   VARCHAR(255)    DEFAULT NULL,
  `price`               DECIMAL(15,2)   NOT NULL,
  `admin_commission`    DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `processing_minutes`  INT UNSIGNED    NOT NULL DEFAULT 1440 COMMENT 'task deadline in minutes',
  `duration_label`      VARCHAR(60)     DEFAULT NULL COMMENT 'human-readable, e.g. "24 Hours"',
  `icon`                VARCHAR(255)    DEFAULT NULL COMMENT 'path under /assets/img/service-icons/',
  `sort_order`          INT             NOT NULL DEFAULT 0,
  `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_slug` (`slug`),
  KEY `idx_services_status`     (`status`,`sort_order`),
  CONSTRAINT `chk_services_price_nonneg`      CHECK (`price`            >= 0),
  CONSTRAINT `chk_services_commission_nonneg` CHECK (`admin_commission` >= 0),
  CONSTRAINT `chk_services_commission_le_price` CHECK (`admin_commission` <= `price`),
  CONSTRAINT `chk_services_duration_pos`      CHECK (`processing_minutes` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- service_fields — dynamic per-service form fields
-- -----------------------------------------------------------------------------
CREATE TABLE `service_fields` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id`       BIGINT UNSIGNED NOT NULL,
  `field_key`        VARCHAR(60)     NOT NULL COMMENT 'machine name, e.g. exam_number',
  `field_label`      VARCHAR(150)    NOT NULL,
  `field_type`       ENUM('text','textarea','number','email','tel','select','radio','checkbox','date','file')
                     NOT NULL DEFAULT 'text',
  `field_options`    JSON            DEFAULT NULL COMMENT 'for select/radio/checkbox',
  `placeholder`      VARCHAR(150)    DEFAULT NULL,
  `help_text`        VARCHAR(255)    DEFAULT NULL,
  `is_required`      TINYINT(1)      NOT NULL DEFAULT 0,
  `validation_regex` VARCHAR(255)    DEFAULT NULL,
  `max_length`       INT             DEFAULT NULL,
  `sort_order`       INT             NOT NULL DEFAULT 0,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_fields_key` (`service_id`,`field_key`),
  KEY `idx_service_fields_service` (`service_id`,`status`,`sort_order`),
  CONSTRAINT `fk_service_fields_service`
    FOREIGN KEY (`service_id`) REFERENCES `services`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 3. WALLET & PAYMENTS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- wallets — one per client; materialised balance (source of truth = ledger)
-- -----------------------------------------------------------------------------
CREATE TABLE `wallets` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `balance`     DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `currency`    CHAR(3)         NOT NULL DEFAULT 'NGN',
  `status`      ENUM('active','frozen') NOT NULL DEFAULT 'active',
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user` (`user_id`),
  CONSTRAINT `chk_wallets_balance_nonneg` CHECK (`balance` >= 0),
  CONSTRAINT `fk_wallets_user`
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- payments — funding intents (online or bank transfer)
-- -----------------------------------------------------------------------------
CREATE TABLE `payments` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_ref`        VARCHAR(40)     NOT NULL COMMENT 'e.g. PAY-2026-00012345',
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `wallet_id`          BIGINT UNSIGNED NOT NULL,
  `gateway`            VARCHAR(40)     NOT NULL COMMENT 'paystack | flutterwave | bank_transfer',
  `method`             ENUM('online','bank_transfer') NOT NULL,
  `amount`             DECIMAL(15,2)   NOT NULL,
  `fee`                DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `net_amount`         DECIMAL(15,2)   NOT NULL,
  `status`             ENUM('pending','awaiting_verification','successful','failed','rejected','cancelled')
                       NOT NULL DEFAULT 'pending',
  `gateway_reference`  VARCHAR(191)    DEFAULT NULL,
  `gateway_payload`    JSON            DEFAULT NULL,
  `verified_by_type`   ENUM('admin','super_admin') DEFAULT NULL,
  `verified_by_id`     BIGINT UNSIGNED DEFAULT NULL,
  `verified_at`        DATETIME        DEFAULT NULL,
  `rejection_reason`   VARCHAR(500)    DEFAULT NULL,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_ref` (`payment_ref`),
  UNIQUE KEY `uq_payments_gateway_ref` (`gateway_reference`),
  KEY `idx_payments_user_status` (`user_id`,`status`,`created_at`),
  KEY `idx_payments_status`      (`status`,`created_at`),
  CONSTRAINT `chk_payments_amount_pos`     CHECK (`amount`     > 0),
  CONSTRAINT `chk_payments_fee_nonneg`     CHECK (`fee`        >= 0),
  CONSTRAINT `chk_payments_net_nonneg`     CHECK (`net_amount` >= 0),
  CONSTRAINT `fk_payments_user`
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_wallet`
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- payment_proofs — bank-transfer evidence
-- -----------------------------------------------------------------------------
CREATE TABLE `payment_proofs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255)    NOT NULL,
  `stored_name`   VARCHAR(100)    NOT NULL,
  `file_path`     VARCHAR(255)    NOT NULL COMMENT 'relative to storage root',
  `mime_type`     VARCHAR(100)    NOT NULL,
  `file_size`     INT UNSIGNED    NOT NULL,
  `checksum_sha256` CHAR(64)      DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_proofs_stored` (`stored_name`),
  KEY `idx_payment_proofs_payment` (`payment_id`),
  KEY `idx_payment_proofs_user`    (`user_id`),
  CONSTRAINT `fk_payment_proofs_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_proofs_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 4. TRACKING & SUBMISSIONS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- tracking_sequences — gap-free tracking ID counter (one row per year)
-- -----------------------------------------------------------------------------
CREATE TABLE `tracking_sequences` (
  `year`        SMALLINT UNSIGNED NOT NULL,
  `last_number` INT UNSIGNED      NOT NULL DEFAULT 0,
  `updated_at`  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- submissions — the core work item
-- -----------------------------------------------------------------------------
CREATE TABLE `submissions` (
  `id`                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tracking_id`                   VARCHAR(30)     NOT NULL COMMENT 'e.g. ESC-2026-000123',
  `user_id`                       BIGINT UNSIGNED NOT NULL,
  `service_id`                    BIGINT UNSIGNED NOT NULL,
  `status`                        ENUM('pending','processing','completed','error_detected','cancelled')
                                  NOT NULL DEFAULT 'pending',

  -- snapshot of the service at submission time (Rule: never change history)
  `service_name_snapshot`         VARCHAR(150)    NOT NULL,
  `price_charged`                 DECIMAL(15,2)   NOT NULL,
  `admin_commission_amount`       DECIMAL(15,2)   NOT NULL DEFAULT 0.00,
  `processing_minutes_snapshot`   INT UNSIGNED    NOT NULL,

  -- locking / deadline
  `assigned_admin_id`             BIGINT UNSIGNED DEFAULT NULL,
  `claimed_at`                    DATETIME        DEFAULT NULL,
  `deadline_at`                   DATETIME        DEFAULT NULL,
  `completed_at`                  DATETIME        DEFAULT NULL,
  `cancelled_at`                  DATETIME        DEFAULT NULL,
  `release_count`                 INT UNSIGNED    NOT NULL DEFAULT 0,
  `resubmission_count`            INT UNSIGNED    NOT NULL DEFAULT 0,
  `parent_submission_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'for resubmissions',

  `error_remarks`                 VARCHAR(500)    DEFAULT NULL,

  `created_at`                    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submissions_tracking`     (`tracking_id`),
  KEY `idx_submissions_service_queue`      (`service_id`,`status`,`created_at`),
  KEY `idx_submissions_user_history`       (`user_id`,`status`,`created_at`),
  KEY `idx_submissions_deadline`           (`status`,`deadline_at`),
  KEY `idx_submissions_assigned`           (`assigned_admin_id`,`status`),
  KEY `idx_submissions_created_at`         (`created_at`),
  KEY `fk_submissions_parent`              (`parent_submission_id`),

  CONSTRAINT `chk_submissions_price_nonneg`      CHECK (`price_charged`           >= 0),
  CONSTRAINT `chk_submissions_commission_nonneg` CHECK (`admin_commission_amount` >= 0),
  CONSTRAINT `chk_submissions_duration_pos`      CHECK (`processing_minutes_snapshot` > 0),

  CONSTRAINT `fk_submissions_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users`(`id`)    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_service`
    FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_admin`
    FOREIGN KEY (`assigned_admin_id`) REFERENCES `admins`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_submissions_parent`
    FOREIGN KEY (`parent_submission_id`) REFERENCES `submissions`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- submission_field_values — answers to dynamic fields
-- -----------------------------------------------------------------------------
CREATE TABLE `submission_field_values` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`        BIGINT UNSIGNED NOT NULL,
  `field_id`             BIGINT UNSIGNED NOT NULL,
  `field_key_snapshot`   VARCHAR(60)     NOT NULL,
  `field_label_snapshot` VARCHAR(150)    NOT NULL,
  `value_text`           TEXT            DEFAULT NULL,
  `value_file_id`        BIGINT UNSIGNED DEFAULT NULL,
  `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_submission_field` (`submission_id`,`field_id`),
  KEY `idx_sfv_submission` (`submission_id`),
  KEY `idx_sfv_field`      (`field_id`),
  CONSTRAINT `fk_sfv_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sfv_field`
    FOREIGN KEY (`field_id`)      REFERENCES `service_fields`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- submission_status_history — full lifecycle trail
-- -----------------------------------------------------------------------------
CREATE TABLE `submission_status_history` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`    BIGINT UNSIGNED NOT NULL,
  `from_status`      ENUM('pending','processing','completed','error_detected','cancelled') DEFAULT NULL,
  `to_status`        ENUM('pending','processing','completed','error_detected','cancelled') NOT NULL,
  `changed_by_type`  ENUM('client','admin','super_admin','system') NOT NULL,
  `changed_by_id`    BIGINT UNSIGNED DEFAULT NULL,
  `reason`           VARCHAR(255)    DEFAULT NULL,
  `meta`             JSON            DEFAULT NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ssh_submission` (`submission_id`,`created_at`),
  CONSTRAINT `fk_ssh_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- feedback — admin remarks against a submission
-- -----------------------------------------------------------------------------
CREATE TABLE `feedback` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`      BIGINT UNSIGNED NOT NULL,
  `admin_id`           BIGINT UNSIGNED DEFAULT NULL,
  `feedback_type`      ENUM('completion','error_report','interim') NOT NULL DEFAULT 'completion',
  `remarks`            TEXT            NOT NULL,
  `status_snapshot`    VARCHAR(30)     NOT NULL,
  `is_client_visible`  TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_feedback_submission` (`submission_id`,`feedback_type`),
  KEY `idx_feedback_admin`      (`admin_id`),
  CONSTRAINT `fk_feedback_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_feedback_admin`
    FOREIGN KEY (`admin_id`)      REFERENCES `admins`(`id`)      ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- documents — all uploaded files (task output, supporting, evidence)
-- -----------------------------------------------------------------------------
CREATE TABLE `documents` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_id`     BIGINT UNSIGNED DEFAULT NULL,
  `feedback_id`       BIGINT UNSIGNED DEFAULT NULL,
  `payment_id`        BIGINT UNSIGNED DEFAULT NULL,
  `uploaded_by_type`  ENUM('client','admin','super_admin','system') NOT NULL,
  `uploaded_by_id`    BIGINT UNSIGNED DEFAULT NULL,
  `document_type`     ENUM('completion_result','supporting_document','error_evidence','payment_proof','other')
                      NOT NULL DEFAULT 'other',
  `original_name`     VARCHAR(255)    NOT NULL,
  `stored_name`       VARCHAR(100)    NOT NULL,
  `file_path`         VARCHAR(255)    NOT NULL COMMENT 'relative to storage root',
  `mime_type`         VARCHAR(100)    NOT NULL,
  `file_size`         INT UNSIGNED    NOT NULL,
  `checksum_sha256`   CHAR(64)        DEFAULT NULL,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_documents_stored` (`stored_name`),
  KEY `idx_documents_submission` (`submission_id`),
  KEY `idx_documents_feedback`   (`feedback_id`),
  KEY `idx_documents_payment`    (`payment_id`),
  KEY `idx_documents_uploader`   (`uploaded_by_type`,`uploaded_by_id`),
  CONSTRAINT `fk_documents_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_feedback`
    FOREIGN KEY (`feedback_id`)   REFERENCES `feedback`(`id`)    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_payment`
    FOREIGN KEY (`payment_id`)    REFERENCES `payments`(`id`)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 5. LEDGERS
-- =============================================================================

-- -----------------------------------------------------------------------------
-- wallet_transactions — wallet ledger (source of truth)
-- -----------------------------------------------------------------------------
CREATE TABLE `wallet_transactions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_ref`  VARCHAR(40)     NOT NULL COMMENT 'e.g. WTX-2026-00012345',
  `wallet_id`        BIGINT UNSIGNED NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL COMMENT 'denormalised for reporting',
  `type`             ENUM('credit','debit') NOT NULL,
  `category`         ENUM('funding','service_charge','refund','reversal','adjustment') NOT NULL,
  `amount`           DECIMAL(15,2)   NOT NULL,
  `balance_before`   DECIMAL(15,2)   NOT NULL,
  `balance_after`    DECIMAL(15,2)   NOT NULL,
  `status`           ENUM('pending','successful','failed','reversed') NOT NULL DEFAULT 'successful',
  `reference_type`   VARCHAR(50)     DEFAULT NULL COMMENT 'payment | submission | manual',
  `reference_id`     BIGINT UNSIGNED DEFAULT NULL,
  `description`      VARCHAR(255)    DEFAULT NULL,
  `created_by_type`  ENUM('system','client','admin','super_admin') NOT NULL DEFAULT 'system',
  `created_by_id`    BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wtx_ref` (`transaction_ref`),
  KEY `idx_wtx_wallet`      (`wallet_id`,`created_at`),
  KEY `idx_wtx_user`        (`user_id`,`created_at`),
  KEY `idx_wtx_reference`   (`reference_type`,`reference_id`),
  KEY `idx_wtx_category`    (`category`,`created_at`),
  CONSTRAINT `chk_wtx_amount_pos`       CHECK (`amount` > 0),
  CONSTRAINT `chk_wtx_balance_nonneg`   CHECK (`balance_after` >= 0),
  CONSTRAINT `fk_wtx_wallet`
    FOREIGN KEY (`wallet_id`) REFERENCES `wallets`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_wtx_user`
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 6. WITHDRAWALS & COMMISSION
-- =============================================================================

-- -----------------------------------------------------------------------------
-- withdrawals — admin payout requests
-- -----------------------------------------------------------------------------
CREATE TABLE `withdrawals` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `withdrawal_ref`     VARCHAR(40)     NOT NULL COMMENT 'e.g. WDR-2026-000123',
  `admin_id`           BIGINT UNSIGNED NOT NULL,
  `amount`             DECIMAL(15,2)   NOT NULL,
  `bank_name`          VARCHAR(120)    NOT NULL,
  `account_name`       VARCHAR(150)    NOT NULL,
  `account_number`     VARCHAR(30)     NOT NULL,
  `status`             ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by_type`   ENUM('super_admin') DEFAULT NULL,
  `reviewed_by_id`     BIGINT UNSIGNED DEFAULT NULL,
  `reviewed_at`        DATETIME        DEFAULT NULL,
  `paid_at`            DATETIME        DEFAULT NULL,
  `payment_reference`  VARCHAR(100)    DEFAULT NULL,
  `admin_note`         VARCHAR(500)    DEFAULT NULL,
  `super_admin_note`   VARCHAR(500)    DEFAULT NULL,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_withdrawals_ref` (`withdrawal_ref`),
  KEY `idx_withdrawals_admin_status` (`admin_id`,`status`,`created_at`),
  KEY `idx_withdrawals_status`       (`status`,`created_at`),
  CONSTRAINT `chk_withdrawals_amount_pos` CHECK (`amount` > 0),
  CONSTRAINT `fk_withdrawals_admin`
    FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_withdrawals_reviewer`
    FOREIGN KEY (`reviewed_by_id`) REFERENCES `super_admins`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- commission_transactions — admin commission ledger (source of truth)
-- -----------------------------------------------------------------------------
CREATE TABLE `commission_transactions` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_ref`    VARCHAR(40)     NOT NULL,
  `admin_id`           BIGINT UNSIGNED NOT NULL,
  `submission_id`      BIGINT UNSIGNED DEFAULT NULL,
  `withdrawal_id`      BIGINT UNSIGNED DEFAULT NULL,
  `type`               ENUM('earned','withdrawal_request','withdrawal_reversal','withdrawal','adjustment','reversal') NOT NULL,
  `direction`          ENUM('credit','debit') NOT NULL,
  `amount`             DECIMAL(15,2)   NOT NULL,
  `balance_before`     DECIMAL(15,2)   NOT NULL,
  `balance_after`      DECIMAL(15,2)   NOT NULL,
  `status`             ENUM('pending','successful','failed','reversed') NOT NULL DEFAULT 'successful',
  `description`        VARCHAR(255)    DEFAULT NULL,
  `created_by_type`    ENUM('system','admin','super_admin') NOT NULL DEFAULT 'system',
  `created_by_id`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ctrx_ref` (`transaction_ref`),
  KEY `idx_ctrx_admin`      (`admin_id`,`created_at`),
  KEY `idx_ctrx_submission` (`submission_id`),
  KEY `idx_ctrx_withdrawal` (`withdrawal_id`),
  KEY `idx_ctrx_type`       (`type`,`created_at`),
  CONSTRAINT `chk_ctrx_amount_pos`     CHECK (`amount` > 0),
  CONSTRAINT `chk_ctrx_balance_nonneg` CHECK (`balance_after` >= 0),
  CONSTRAINT `fk_ctrx_admin`
    FOREIGN KEY (`admin_id`)      REFERENCES `admins`(`id`)        ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ctrx_submission`
    FOREIGN KEY (`submission_id`) REFERENCES `submissions`(`id`)   ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ctrx_withdrawal`
    FOREIGN KEY (`withdrawal_id`) REFERENCES `withdrawals`(`id`)   ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 7. GOVERNANCE
-- =============================================================================

-- -----------------------------------------------------------------------------
-- notifications — internal messaging for all roles
-- -----------------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('client','admin','super_admin') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `title`      VARCHAR(150)    NOT NULL,
  `message`    TEXT            NOT NULL,
  `type`       VARCHAR(40)     NOT NULL DEFAULT 'system'
               COMMENT 'system | submission | wallet | task | commission | withdrawal',
  `link`       VARCHAR(255)    DEFAULT NULL,
  `is_read`    TINYINT(1)      NOT NULL DEFAULT 0,
  `read_at`    DATETIME        DEFAULT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_inbox`   (`user_type`,`user_id`,`is_read`,`created_at`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_logs — immutable trail of every impactful action
-- -----------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`    ENUM('guest','client','admin','super_admin','system') NOT NULL DEFAULT 'system',
  `user_id`      BIGINT UNSIGNED DEFAULT NULL,
  `action`       VARCHAR(80)     NOT NULL COMMENT 'e.g. TASK_CLAIMED, WALLET_DEBIT',
  `entity_type`  VARCHAR(60)     DEFAULT NULL COMMENT 'e.g. submission, payment, withdrawal',
  `entity_id`    BIGINT UNSIGNED DEFAULT NULL,
  `description`  VARCHAR(500)    DEFAULT NULL,
  `old_values`   JSON            DEFAULT NULL,
  `new_values`   JSON            DEFAULT NULL,
  `ip_address`   VARCHAR(45)     DEFAULT NULL,
  `user_agent`   VARCHAR(255)    DEFAULT NULL,
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor`     (`user_type`,`user_id`,`created_at`),
  KEY `idx_audit_action`    (`action`,`created_at`),
  KEY `idx_audit_entity`    (`entity_type`,`entity_id`),
  KEY `idx_audit_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- system_settings — configurable key/value store
-- -----------------------------------------------------------------------------
CREATE TABLE `system_settings` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`       VARCHAR(100)    NOT NULL,
  `setting_value`     TEXT            DEFAULT NULL,
  `setting_group`     VARCHAR(50)     NOT NULL DEFAULT 'general',
  `value_type`        ENUM('string','integer','decimal','boolean','json','text') NOT NULL DEFAULT 'string',
  `description`       VARCHAR(255)    DEFAULT NULL,
  `is_public`         TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = safe to expose to client-facing pages',
  `updated_by_type`   ENUM('super_admin','system') DEFAULT NULL,
  `updated_by_id`     BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`),
  KEY `idx_settings_group` (`setting_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- END schema.sql
-- =============================================================================
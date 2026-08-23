-- =====================================================================
--  Recruitment Lead Management System - MySQL Schema
--  Target: MySQL 5.7+ / MariaDB 10.3+ (cPanel shared hosting)
--  Charset: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- USERS  (3-level hierarchy: admin -> partner -> telecaller)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`      INT UNSIGNED NULL COMMENT 'telecaller -> owning partner; partner -> NULL',
  `role`           ENUM('admin','partner','telecaller') NOT NULL,
  `name`           VARCHAR(120) NOT NULL,
  `phone`          VARCHAR(20)  NOT NULL,
  `email`          VARCHAR(160) NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `agency_name`    VARCHAR(160) NULL COMMENT 'franchise / sub-agent business name',
  `city`           VARCHAR(100) NULL,
  `state`          VARCHAR(100) NULL,
  `photo_path`     VARCHAR(255) NULL,
  `max_telecallers` SMALLINT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'limit for partners',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at`  DATETIME NULL,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `ix_users_parent` (`parent_id`),
  KEY `ix_users_role` (`role`, `is_active`),
  CONSTRAINT `fk_users_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AUTH TOKENS  (mobile app bearer tokens + FCM registration)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `token_hash`   CHAR(64) NOT NULL COMMENT 'sha256 of the bearer token',
  `device_id`    VARCHAR(120) NULL,
  `device_name`  VARCHAR(160) NULL,
  `fcm_token`    VARCHAR(255) NULL,
  `app_version`  VARCHAR(30)  NULL,
  `last_used_at` DATETIME NULL,
  `expires_at`   DATETIME NOT NULL,
  `revoked_at`   DATETIME NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tokens_hash` (`token_hash`),
  KEY `ix_tokens_user` (`user_id`, `revoked_at`),
  CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LOOKUPS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_sources` (
  `id`        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(80) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `job_categories` (
  `id`        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(120) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_types` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(120) NOT NULL,
  `code`        VARCHAR(40)  NOT NULL,
  `applies_to`  ENUM('lead','project','both') NOT NULL DEFAULT 'project',
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `has_expiry`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'passport / visa / medical / PCC expire',
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctype_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LEADS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_id`         INT UNSIGNED NULL COMMENT 'owning franchise; NULL = held by head office',
  `assigned_to`        INT UNSIGNED NULL COMMENT 'telecaller (or partner) responsible',
  `name`               VARCHAR(160) NOT NULL,
  `phone`              VARCHAR(20)  NOT NULL,
  `phone_normalized`   VARCHAR(15)  NOT NULL COMMENT 'last 10 digits - used to match device call logs',
  `alt_phone`          VARCHAR(20)  NULL,
  `alt_phone_normalized` VARCHAR(15) NULL,
  `whatsapp`           VARCHAR(20)  NULL,
  `email`              VARCHAR(160) NULL,
  `city`               VARCHAR(100) NULL,
  `district`           VARCHAR(100) NULL,
  `state`              VARCHAR(100) NULL,
  `source_id`          SMALLINT UNSIGNED NULL,
  `job_category_id`    SMALLINT UNSIGNED NULL,
  `preferred_country`  VARCHAR(80)  NULL,
  `qualification`      VARCHAR(160) NULL,
  `experience_years`   DECIMAL(4,1) NULL,
  `current_salary`     DECIMAL(12,2) NULL,
  `expected_salary`    DECIMAL(12,2) NULL,
  `passport_status`    ENUM('not_applied','applied','ready','expired') NULL,
  `status`             ENUM('new','contacted','interested','not_interested','follow_up',
                            'documents_pending','converted','lost','invalid','dnd')
                       NOT NULL DEFAULT 'new',
  `priority`           ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `next_follow_up_at`  DATETIME NULL,
  `last_contacted_at`  DATETIME NULL,
  `call_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `total_talk_time_sec` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes`              TEXT NULL,
  `converted_at`       DATETIME NULL,
  `created_by`         INT UNSIGNED NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lead_phone_partner` (`phone_normalized`, `partner_id`),
  KEY `ix_leads_assigned` (`assigned_to`, `status`),
  KEY `ix_leads_partner` (`partner_id`, `status`),
  KEY `ix_leads_followup` (`next_follow_up_at`),
  KEY `ix_leads_phone_norm` (`phone_normalized`),
  KEY `ix_leads_altphone_norm` (`alt_phone_normalized`),
  CONSTRAINT `fk_leads_partner`  FOREIGN KEY (`partner_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_source`   FOREIGN KEY (`source_id`)   REFERENCES `lead_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leads_category` FOREIGN KEY (`job_category_id`) REFERENCES `job_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CALL LOGS  (auto-captured on the device, batch-synced to server)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `call_logs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`          BIGINT UNSIGNED NULL COMMENT 'matched by phone_normalized; NULL if unknown number',
  `user_id`          INT UNSIGNED NOT NULL,
  `phone_number`     VARCHAR(25) NOT NULL,
  `phone_normalized` VARCHAR(15) NOT NULL,
  `direction`        ENUM('outgoing','incoming','missed','rejected','blocked','unknown') NOT NULL DEFAULT 'outgoing',
  `started_at`       DATETIME NOT NULL,
  `ended_at`         DATETIME NULL,
  `duration_sec`     INT UNSIGNED NOT NULL DEFAULT 0,
  `answered`         TINYINT(1) NOT NULL DEFAULT 0,
  `disposition`      ENUM('connected','no_answer','busy','switched_off','wrong_number',
                          'call_back_later','not_reachable','other') NULL,
  `status_set`       VARCHAR(30) NULL COMMENT 'lead status chosen in the post-call popup',
  `notes`            TEXT NULL,
  `device_call_id`   VARCHAR(64) NULL COMMENT 'android CallLog._ID, for idempotent sync',
  `sim_slot`         TINYINT NULL,
  `recording_path`   VARCHAR(255) NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_call_device` (`user_id`, `device_call_id`),
  KEY `ix_calls_lead` (`lead_id`, `started_at`),
  KEY `ix_calls_user_date` (`user_id`, `started_at`),
  CONSTRAINT `fk_calls_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_calls_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LEAD STATUS HISTORY (audit trail of every status change)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_status_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status`   VARCHAR(30) NOT NULL,
  `remarks`     VARCHAR(500) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_lsh_lead` (`lead_id`, `created_at`),
  CONSTRAINT `fk_lsh_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- FOLLOW UPS  ("when should I call back?")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `follow_ups` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL COMMENT 'who must make the call',
  `scheduled_at`  DATETIME NOT NULL,
  `remarks`       VARCHAR(500) NULL,
  `status`        ENUM('pending','done','missed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`  DATETIME NULL,
  `reminded_at`   DATETIME NULL,
  `created_by`    INT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_fu_user_sched` (`user_id`, `status`, `scheduled_at`),
  KEY `ix_fu_lead` (`lead_id`),
  CONSTRAINT `fk_fu_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fu_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PROJECTS  (a lead that converted into a real overseas placement case)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id`             BIGINT UNSIGNED NOT NULL,
  `project_code`        VARCHAR(30) NOT NULL COMMENT 'e.g. PRJ-2026-00042',
  `partner_id`          INT UNSIGNED NULL,
  `assigned_to`         INT UNSIGNED NULL,
  -- candidate snapshot
  `candidate_name`      VARCHAR(160) NOT NULL,
  `candidate_phone`     VARCHAR(20)  NOT NULL,
  `candidate_email`     VARCHAR(160) NULL,
  `dob`                 DATE NULL,
  `gender`              ENUM('male','female','other') NULL,
  `passport_no`         VARCHAR(30)  NULL,
  `passport_expiry`     DATE NULL,
  -- placement details
  `job_category_id`     SMALLINT UNSIGNED NULL,
  `position`            VARCHAR(160) NULL,
  `employer_name`       VARCHAR(200) NULL,
  `destination_country` VARCHAR(80)  NULL,
  `visa_type`           VARCHAR(80)  NULL,
  `offered_salary`      DECIMAL(12,2) NULL,
  `salary_currency`     VARCHAR(10) NULL DEFAULT 'AED',
  -- commercials
  `agreed_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `paid_amount`         DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- pipeline
  `status`              ENUM('initiated','documents_pending','documents_verified',
                             'interview_scheduled','selected','medical_pending','medical_cleared',
                             'pcc_pending','visa_processing','visa_approved','ticket_booked',
                             'deployed','on_hold','cancelled','completed')
                        NOT NULL DEFAULT 'initiated',
  `interview_date`      DATETIME NULL,
  `medical_date`        DATE NULL,
  `visa_number`         VARCHAR(60) NULL,
  `visa_expiry`         DATE NULL,
  `deployment_date`     DATE NULL,
  `remarks`             TEXT NULL,
  `created_by`          INT UNSIGNED NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_lead` (`lead_id`),
  UNIQUE KEY `uq_project_code` (`project_code`),
  KEY `ix_projects_partner` (`partner_id`, `status`),
  KEY `ix_projects_assigned` (`assigned_to`, `status`),
  CONSTRAINT `fk_proj_lead`     FOREIGN KEY (`lead_id`)     REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_proj_partner`  FOREIGN KEY (`partner_id`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_proj_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_status_history` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status`   VARCHAR(30) NOT NULL,
  `remarks`     VARCHAR(500) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_psh_project` (`project_id`, `created_at`),
  CONSTRAINT `fk_psh_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- FORM TEMPLATES  (admin uploads blank forms -> partner/telecaller download)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `form_templates` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(200) NOT NULL,
  `description`  VARCHAR(500) NULL,
  `category`     VARCHAR(80)  NULL COMMENT 'application / medical / visa / agreement ...',
  `file_name`    VARCHAR(255) NOT NULL COMMENT 'original name shown to user',
  `stored_name`  VARCHAR(255) NOT NULL COMMENT 'randomised name on disk',
  `mime_type`    VARCHAR(120) NOT NULL,
  `file_size`    INT UNSIGNED NOT NULL,
  `version`      VARCHAR(20) NOT NULL DEFAULT '1.0',
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `uploaded_by`  INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_templates_active` (`is_active`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- DOCUMENTS  (partner/telecaller uploads filled forms & candidate papers,
--             admin downloads + verifies)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `documents` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`          BIGINT UNSIGNED NULL,
  `lead_id`             BIGINT UNSIGNED NULL,
  `document_type_id`    SMALLINT UNSIGNED NULL,
  `title`               VARCHAR(200) NULL,
  `file_name`           VARCHAR(255) NOT NULL,
  `stored_name`         VARCHAR(255) NOT NULL,
  `mime_type`           VARCHAR(120) NOT NULL,
  `file_size`           INT UNSIGNED NOT NULL,
  `document_number`     VARCHAR(80) NULL COMMENT 'passport no / visa no / PCC ref',
  `issue_date`          DATE NULL,
  `expiry_date`         DATE NULL,
  `verification_status` ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason`       VARCHAR(500) NULL,
  `verified_by`         INT UNSIGNED NULL,
  `verified_at`         DATETIME NULL,
  `uploaded_by`         INT UNSIGNED NOT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_docs_project` (`project_id`, `verification_status`),
  KEY `ix_docs_lead` (`lead_id`),
  KEY `ix_docs_uploader` (`uploaded_by`),
  CONSTRAINT `fk_docs_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docs_lead`    FOREIGN KEY (`lead_id`)    REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_docs_type`    FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_docs_user`    FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `title`      VARCHAR(200) NOT NULL,
  `body`       VARCHAR(500) NULL,
  `type`       VARCHAR(50) NOT NULL DEFAULT 'general',
  `ref_type`   VARCHAR(40) NULL,
  `ref_id`     BIGINT UNSIGNED NULL,
  `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_notif_user` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ACTIVITY LOG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `action`      VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(40) NULL,
  `entity_id`   BIGINT UNSIGNED NULL,
  `meta`        TEXT NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_activity_user` (`user_id`, `created_at`),
  KEY `ix_activity_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SETTINGS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name`   VARCHAR(80) NOT NULL,
  `value`      TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

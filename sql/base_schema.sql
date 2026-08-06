-- 도깨비 배달 — 핵심 테이블 (php migrate.php)
-- admins · riders · 정산 업로드 · 출금 신청 등

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `login_id`       VARCHAR(60)      NOT NULL,
    `password_hash`  VARCHAR(255)     NOT NULL,
    `name`           VARCHAR(80)      NOT NULL,
    `email`          VARCHAR(120)     NULL,
    `role`           ENUM('super','admin','operation','settlement','manager') NOT NULL DEFAULT 'admin',
    `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
    `last_login_at`  DATETIME         NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_login` (`login_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_codes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category`    VARCHAR(32)  NOT NULL,
    `code`        VARCHAR(40)  NOT NULL,
    `label`       VARCHAR(80)  NOT NULL,
    `sort_order`  SMALLINT     NOT NULL DEFAULT 100,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sc_cat_code` (`category`, `code`),
    KEY `idx_sc_category` (`category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `riders` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rider_code`           VARCHAR(32)  NOT NULL,
    `login_id`             VARCHAR(60)  NOT NULL,
    `password_hash`        VARCHAR(255) NOT NULL,
    `name`                 VARCHAR(80)  NOT NULL,
    `phone`                VARCHAR(20)  NOT NULL DEFAULT '',
    `email`                VARCHAR(120) NOT NULL DEFAULT '',
    `birth_date`           DATE         NULL,
    `status`               ENUM('active','suspended','leave_request','offboarded') NOT NULL DEFAULT 'active',
    `kyc_status`           ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'pending',
    `team_code`            VARCHAR(32)  NOT NULL DEFAULT 'etc',
    `vehicle_type`         VARCHAR(20)  NOT NULL DEFAULT 'motor',
    `address`              VARCHAR(255) NOT NULL DEFAULT '',
    `bank_code`            VARCHAR(10)  NULL,
    `bank_account`         VARCHAR(40)  NULL,
    `account_holder`       VARCHAR(80)  NULL,
    `admin_memo`           TEXT         NULL,
    `withdrawal_hold`      TINYINT(1)   NOT NULL DEFAULT 0,
    `is_daily_settlement`  TINYINT(1)   NOT NULL DEFAULT 0,
    `last_login_at`        DATETIME     NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_riders_code` (`rider_code`),
    UNIQUE KEY `uq_riders_login` (`login_id`),
    KEY `idx_riders_status` (`status`),
    KEY `idx_riders_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rider_platforms` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rider_id`      INT UNSIGNED NOT NULL,
    `platform`      ENUM('baemin','coupang','other') NOT NULL,
    `is_connected`  TINYINT(1)   NOT NULL DEFAULT 1,
    `external_id`   VARCHAR(50)  NOT NULL DEFAULT '',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rp_rider` (`rider_id`),
    KEY `idx_rp_platform_ext` (`platform`, `external_id`),
    CONSTRAINT `fk_rp_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settlement_uploads` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind`                ENUM('daily','weekly') NOT NULL DEFAULT 'daily',
    `platform`            ENUM('baemin','coupang','other') NOT NULL DEFAULT 'baemin',
    `original_filename`   VARCHAR(255) NOT NULL DEFAULT '',
    `stored_path`         TEXT         NULL,
    `settlement_date`     DATE         NOT NULL,
    `total_rows`          INT UNSIGNED NOT NULL DEFAULT 0,
    `ok_rows`             INT UNSIGNED NOT NULL DEFAULT 0,
    `skipped_rows`        INT UNSIGNED NOT NULL DEFAULT 0,
    `error_rows`          INT UNSIGNED NOT NULL DEFAULT 0,
    `status`              ENUM('uploaded','parsing','parsed','applied','error') NOT NULL DEFAULT 'uploaded',
    `operator_id`         INT UNSIGNED NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_su_date_pf_kind` (`settlement_date`, `platform`, `kind`),
    CONSTRAINT `fk_su_operator` FOREIGN KEY (`operator_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settlement_weekly_deductions` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`        INT UNSIGNED NOT NULL,
    `week_start`       DATE         NULL,
    `order_date`       DATE         NULL,
    `order_no`         VARCHAR(40)  NOT NULL DEFAULT '',
    `rider_id`         INT UNSIGNED NULL,
    `rider_name_raw`   VARCHAR(100) NOT NULL DEFAULT '',
    `deduction_type`   VARCHAR(80)  NOT NULL DEFAULT '',
    `store_name`       VARCHAR(120) NOT NULL DEFAULT '',
    `assigned_at`      DATETIME     NULL,
    `menu_price`       INT          NOT NULL DEFAULT 0,
    `delivery_fee`     INT          NOT NULL DEFAULT 0,
    `amount`           INT          NOT NULL DEFAULT 0,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_swd_upload` (`upload_id`),
    CONSTRAINT `fk_swd_upload` FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_swd_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
    `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rider_id`             INT UNSIGNED NOT NULL,
    `kind`                 ENUM('rider_manual','auto_daily') NOT NULL DEFAULT 'rider_manual',
    `amount`               INT          NOT NULL DEFAULT 0 COMMENT '실지급액',
    `gross_amount`         INT          NOT NULL DEFAULT 0,
    `withhold_tax`         INT          NOT NULL DEFAULT 0,
    `withhold_refund`      INT          NOT NULL DEFAULT 0,
    `withhold_other`       INT          NOT NULL DEFAULT 0,
    `withhold_min_retain`  INT          NOT NULL DEFAULT 0,
    `withhold_round_trim`  INT          NOT NULL DEFAULT 0,
    `accrued_days`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `settlement_date`      DATE         NULL,
    `bank_code`            VARCHAR(10)  NOT NULL DEFAULT '',
    `bank_account`         VARCHAR(40)  NOT NULL DEFAULT '',
    `account_holder`       VARCHAR(80)  NOT NULL DEFAULT '',
    `status`               ENUM('pending','downloaded','completed','rejected') NOT NULL DEFAULT 'pending',
    `download_batch_id`    INT UNSIGNED NULL,
    `rejected_reason`      VARCHAR(300) NOT NULL DEFAULT '',
    `note`                 VARCHAR(500) NOT NULL DEFAULT '',
    `requested_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`         DATETIME     NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wr_rider_status` (`rider_id`, `status`),
    KEY `idx_wr_status_requested` (`status`, `requested_at`),
    CONSTRAINT `fk_wr_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `deduction_entries` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rider_id`      INT UNSIGNED NOT NULL,
    `applied_date`  DATE         NOT NULL,
    `kind`          VARCHAR(40)  NOT NULL DEFAULT 'manual',
    `amount`        INT          NOT NULL DEFAULT 0,
    `note`          VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_de_rider_date` (`rider_id`, `applied_date`),
    CONSTRAINT `fk_de_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

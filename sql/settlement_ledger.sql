-- 정산 완료·수수료 내역 (라이더별 정산 사이클)
-- 실행: php migrate_settlement_ledger.php

CREATE TABLE IF NOT EXISTS `settlement_rider_cycles` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `rider_id`         INT UNSIGNED     NOT NULL,
    `upload_id`        INT UNSIGNED     NULL,
    `daily_rider_id`   INT UNSIGNED     NULL COMMENT 'settlement_daily_riders.id',
    `settlement_date`  DATE             NOT NULL,
    `platform`         ENUM('baemin','coupang','other') NOT NULL DEFAULT 'baemin',
    `gross_amount`     INT              NOT NULL DEFAULT 0 COMMENT '플랫폼 총액',
    `platform_payout`  INT              NOT NULL DEFAULT 0 COMMENT '플랫폼 지급액(payout)',
    `total_fee_amount` INT              NOT NULL DEFAULT 0 COMMENT '정산 수수료 합',
    `net_amount`       INT              NOT NULL DEFAULT 0 COMMENT '지갑 반영액',
    `order_count`      INT UNSIGNED     NOT NULL DEFAULT 0,
    `status`           ENUM('completed') NOT NULL DEFAULT 'completed',
    `completed_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_by`     INT UNSIGNED     NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_src_rider_date_pf` (`rider_id`, `settlement_date`, `platform`),
    KEY `idx_src_date` (`settlement_date`),
    KEY `idx_src_upload` (`upload_id`),
    CONSTRAINT `fk_src_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_src_upload` FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settlement_fee_items` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cycle_id`   INT UNSIGNED NOT NULL,
    `fee_code`   VARCHAR(40)  NOT NULL,
    `label`      VARCHAR(80)  NOT NULL,
    `amount`     INT          NOT NULL DEFAULT 0 COMMENT '차감액(양수)',
    `sort_order` SMALLINT     NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_sfi_cycle` (`cycle_id`),
    CONSTRAINT `fk_sfi_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `settlement_rider_cycles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

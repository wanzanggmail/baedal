-- =====================================================================
-- 정산 업로드 보조 테이블 (기존 settlement_uploads 스키마와 함께 사용)
-- settlement_uploads / settlement_weekly_deductions 는 초기 CREATE TABLE에 포함됨
-- =====================================================================

CREATE TABLE IF NOT EXISTS `settlement_daily_riders` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `upload_id`         INT UNSIGNED    NOT NULL,
    `settlement_date`   DATE            NOT NULL,
    `platform`          ENUM('baemin','coupang','other') NOT NULL,
    `rider_id`          INT UNSIGNED    NULL,
    `license_id`        VARCHAR(50)     NOT NULL DEFAULT '',
    `rider_name_raw`    VARCHAR(100)    NOT NULL DEFAULT '',
    `order_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `gross_amount`      INT             NOT NULL DEFAULT 0,
    `fee_pickup`        INT             NOT NULL DEFAULT 0,
    `fee_delivery`      INT             NOT NULL DEFAULT 0,
    `fee_area`          INT             NOT NULL DEFAULT 0,
    `fee_dist_cnt`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fee_dist_surge`    INT             NOT NULL DEFAULT 0,
    `fee_pickup_cnt`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fee_pickup_surge`  INT             NOT NULL DEFAULT 0,
    `fee_dest_cnt`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fee_dest_surge`    INT             NOT NULL DEFAULT 0,
    `fee_weather_cnt`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fee_weather`       INT             NOT NULL DEFAULT 0,
    `fee_promo1`        INT             NOT NULL DEFAULT 0,
    `fee_promo2`        INT             NOT NULL DEFAULT 0,
    `fee_promo3`        INT             NOT NULL DEFAULT 0,
    `fee_promo4`        INT             NOT NULL DEFAULT 0,
    `payout_amount`     INT             NOT NULL DEFAULT 0,
    `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sdr_upload_license` (`upload_id`, `license_id`),
    KEY `idx_sdr_date_rider` (`settlement_date`, `rider_id`),
    CONSTRAINT `fk_sdr_upload`
        FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sdr_rider`
        FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='일간 정산서 라이더별 요약';

<?php

/**
 * 정산 보조 테이블 마이그레이션 (기존 settlement_uploads 스키마 유지)
 * 실행: php migrate_settlement.php  또는  브라우저에서 /migrate_settlement.php
 * !! 실행 후 이 파일을 삭제하세요 !!
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `settlement_daily_riders` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `upload_id`         INT UNSIGNED    NOT NULL,
    `settlement_date`   DATE            NOT NULL,
    `platform`          ENUM('baemin','coupang','other') NOT NULL,
    `rider_id`          INT UNSIGNED    NULL,
    `license_id`        VARCHAR(50)     NOT NULL DEFAULT '' COMMENT '배민 라이선스 ID',
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
  COMMENT='일간 정산서 라이더별 요약(일별 시트)';
SQL;

try {
    db_execute($sql);
    echo "OK    settlement_daily_riders\n";
} catch (Throwable $e) {
    echo 'ERROR settlement_daily_riders → ' . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=====================================\n";
echo "완료. 이제 정산 엑셀 업로드를 다시 시도하세요.\n";
echo "이 파일(migrate_settlement.php)을 삭제하세요!\n";
echo "=====================================\n";

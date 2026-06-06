-- 정산 선공제(대행 수수료) — 적립 일수 구간별 건당 정액 (신규 DB용)
-- 실행: php migrate.php

CREATE TABLE IF NOT EXISTS `deduction_global_config` (
    `id`                       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `withholding_tax_pct`      DECIMAL(5,2)     NOT NULL DEFAULT 3.30,
    `employment_ins_pct`       DECIMAL(5,2)     NOT NULL DEFAULT 9.12,
    `agency_fee_pct`           DECIMAL(5,2)     NOT NULL DEFAULT 0.00 COMMENT '구 비율(미사용)',
    `agency_fee_day_threshold` SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT '적립 일수 기준',
    `agency_fee_short`         INT              NOT NULL DEFAULT 80 COMMENT '기준 미만 건당(원)',
    `agency_fee_long`          INT              NOT NULL DEFAULT 40 COMMENT '기준 이상 건당(원)',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

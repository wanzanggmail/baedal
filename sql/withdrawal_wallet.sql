-- 출금 지갑·설정 (정산 일원화 전 출금 전용)
-- 실행: php migrate.php

CREATE TABLE IF NOT EXISTS `withdrawal_config` (
    `id`                  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `reserve_amount`      INT              NOT NULL DEFAULT 50000 COMMENT '보증금(지갑 잔留)',
    `fee_day_threshold`   SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT '적립 일수 기준',
    `fee_per_tx_short`    INT              NOT NULL DEFAULT 80 COMMENT '기준 미만 건당 수수료(원)',
    `fee_per_tx_long`     INT              NOT NULL DEFAULT 40 COMMENT '기준 이상 건당 수수료(원)',
    `updated_at`          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`          INT UNSIGNED     NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO withdrawal_config (id, reserve_amount, fee_day_threshold, fee_per_tx_short, fee_per_tx_long)
VALUES (1, 50000, 7, 80, 40);

CREATE TABLE IF NOT EXISTS `rider_wallets` (
    `rider_id`      INT UNSIGNED NOT NULL,
    `balance`       INT          NOT NULL DEFAULT 0 COMMENT '누적 잔액(정산 반영 전 임시)',
    `accrued_days`  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '쌓인 정산 일수',
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`rider_id`),
    CONSTRAINT `fk_rw_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- withdrawal_requests: 적립 일수 기록 (수수료 구간 판단용)
-- MariaDB: 컬럼 없을 때만 추가 (migrate 스크립트에서 PHP로 처리)

-- 지원금 / 추가지원금 (쿠팡이츠 정산서 "지원금" · "추가지원금" 탭)
-- parser.py 확인 결과: 정산금액과 별개로 존재하며 최종 지급액에 "+가산"되는 항목.
-- 우리 시스템은 이 탭을 파싱하지 않아 라이더 지급액이 그만큼 누락돼 있었음(2026-07-30 발견).
-- 실행: php migrate.php · 참고: LOGIC.md §5.3

CREATE TABLE IF NOT EXISTS `settlement_support_amounts` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`        INT UNSIGNED NOT NULL,
    `settlement_date`  DATE         NOT NULL,
    `rider_id`         INT UNSIGNED NULL,
    `rider_name_raw`   VARCHAR(100) NOT NULL DEFAULT '',
    `kind`             ENUM('support', 'add_support') NOT NULL COMMENT 'support=지원금, add_support=추가지원금',
    `order_no`         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '축약형ID(지원금 탭만)',
    `store_name`       VARCHAR(120) NOT NULL DEFAULT '',
    `pickup_area`      VARCHAR(200) NOT NULL DEFAULT '',
    `delivery_area`    VARCHAR(200) NOT NULL DEFAULT '',
    `assigned_at`      DATETIME     NULL,
    `accepted_at`      DATETIME     NULL,
    `delivered_at`     DATETIME     NULL,
    `duration_minutes` DECIMAL(6,1) NOT NULL DEFAULT 0,
    `peak_time`        VARCHAR(40)  NOT NULL DEFAULT '',
    `category`         VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '추가지원금의 "구분"',
    `amount`           INT          NOT NULL DEFAULT 0,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ssa_upload` (`upload_id`),
    KEY `idx_ssa_rider_date` (`rider_id`, `settlement_date`),
    CONSTRAINT `fk_ssa_upload` FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ssa_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='지원금/추가지원금(라이더 지급액에 가산되는 항목)';

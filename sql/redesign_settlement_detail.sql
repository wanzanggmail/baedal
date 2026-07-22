-- 정산 원본 row-level 상세 저장 (쿠팡이츠 정산서 "오더별 상세 내역서" · "시간제보험" 탭)
-- 실행: php migrate.php · 참고: LOGIC.md §5.3

-- 오더별 상세내역 — 주문 1건 = 1행. 실제 배정/수락/배달 시각을 담아 age-bucket 계산 등에 재사용.
CREATE TABLE IF NOT EXISTS `settlement_order_details` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`        INT UNSIGNED NOT NULL,
    `settlement_date`  DATE         NOT NULL,
    `rider_id`         INT UNSIGNED NULL,
    `rider_name_raw`   VARCHAR(100) NOT NULL DEFAULT '',
    `order_no`         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '축약형 주문번호',
    `store_name`       VARCHAR(120) NOT NULL DEFAULT '',
    `pickup_area`      VARCHAR(200) NOT NULL DEFAULT '',
    `delivery_area`    VARCHAR(200) NOT NULL DEFAULT '',
    `assigned_at`      DATETIME     NULL COMMENT '배정시간',
    `accepted_at`      DATETIME     NULL COMMENT '수락시간',
    `delivered_at`     DATETIME     NULL COMMENT '배달(완료)시간',
    `duration_minutes` DECIMAL(6,1) NOT NULL DEFAULT 0 COMMENT '배달소요시간(분)',
    `peak_time`        VARCHAR(40)  NOT NULL DEFAULT '',
    `distance_m`       INT          NOT NULL DEFAULT 0 COMMENT '배달거리(m)',
    `delivery_type`    VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '멀티배달1 등',
    `fee_pickup`       INT          NOT NULL DEFAULT 0,
    `fee_delivery`     INT          NOT NULL DEFAULT 0,
    `fee_area`         INT          NOT NULL DEFAULT 0,
    `fee_dist_surge`   INT          NOT NULL DEFAULT 0,
    `fee_pickup_surge` INT          NOT NULL DEFAULT 0,
    `fee_dest_surge`   INT          NOT NULL DEFAULT 0,
    `fee_weather`      INT          NOT NULL DEFAULT 0,
    `fee_promo1`       INT          NOT NULL DEFAULT 0,
    `fee_promo2`       INT          NOT NULL DEFAULT 0,
    `fee_promo3`       INT          NOT NULL DEFAULT 0,
    `fee_promo4`       INT          NOT NULL DEFAULT 0,
    `net_amount`       INT          NOT NULL DEFAULT 0 COMMENT '정산금액(오더 단위)',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sod_upload` (`upload_id`),
    KEY `idx_sod_rider_date` (`rider_id`, `settlement_date`),
    KEY `idx_sod_delivered` (`delivered_at`),
    CONSTRAINT `fk_sod_upload` FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sod_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='오더별 상세내역(주문 단위 원본)';

-- 시간제보험 내역 — 라이더·일자별 1행(종합탭 AH합계의 원본 근거, 별도 조회/신고용 보관)
CREATE TABLE IF NOT EXISTS `settlement_hourly_insurance` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upload_id`        INT UNSIGNED NOT NULL,
    `settlement_date`  DATE         NOT NULL,
    `occurred_date`    DATE         NULL COMMENT '발생일자(파일 값)',
    `rider_id`         INT UNSIGNED NULL,
    `rider_name_raw`   VARCHAR(100) NOT NULL DEFAULT '',
    `amount`           INT          NOT NULL DEFAULT 0,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_shi_upload` (`upload_id`),
    KEY `idx_shi_rider_date` (`rider_id`, `settlement_date`),
    CONSTRAINT `fk_shi_upload` FOREIGN KEY (`upload_id`) REFERENCES `settlement_uploads` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shi_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='시간제보험 내역(라이더·일자별)';

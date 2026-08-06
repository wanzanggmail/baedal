-- 프로모션 지급 — 엑셀 업로드로 라이더에게 프로모션1/2 금액을 지급한다.
-- 지급 시 라이더별로 카드결제(프로모션액 + 플랫폼 수수료)를 실행하고 성공 건만 지갑에 적립.
-- 실행: php migrate.php · 참고: LOGIC.md §5.8

-- 업로드 배치(지급 1회분) — 대리점 + 지급일자 단위
CREATE TABLE IF NOT EXISTS `promotion_batches` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agency_id`         INT UNSIGNED NOT NULL COMMENT '지급 주체 대리점',
    `pay_date`          DATE         NOT NULL COMMENT '지급 귀속일자(관리자가 선택)',
    `original_filename` VARCHAR(255) NOT NULL DEFAULT '',
    `memo`              VARCHAR(255) NOT NULL DEFAULT '',
    `status`            ENUM('draft','paid','partial','failed') NOT NULL DEFAULT 'draft'
                        COMMENT 'draft=미리보기/확정전, paid=전건 지급, partial=일부 실패, failed=전건 실패',
    `total_riders`      INT UNSIGNED NOT NULL DEFAULT 0,
    `total_amount`      INT          NOT NULL DEFAULT 0 COMMENT '프로모션1+2 합계(지급 예정/실지급)',
    `paid_riders`       INT UNSIGNED NOT NULL DEFAULT 0,
    `paid_amount`       INT          NOT NULL DEFAULT 0 COMMENT '실제 지급 성공 합계',
    `fee_amount`        INT          NOT NULL DEFAULT 0 COMMENT '카드결제 시 붙은 플랫폼 수수료 합계',
    `operator_id`       INT UNSIGNED NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pb_agency_date` (`agency_id`, `pay_date`),
    KEY `idx_pb_status` (`status`),
    CONSTRAINT `fk_pb_operator` FOREIGN KEY (`operator_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='프로모션 지급 배치';

-- 배치 내 라이더별 지급 내역 — 엑셀 1행 = 1건
CREATE TABLE IF NOT EXISTS `promotion_entries` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id`       INT UNSIGNED NOT NULL,
    `rider_id`       INT UNSIGNED NULL COMMENT '매칭된 라이더(미매칭이면 NULL)',
    `rider_code_raw` VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '엑셀에 적힌 라이더코드(원본)',
    `rider_name_raw` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '엑셀에 적힌 이름(참고용)',
    `promo1_amount`  INT          NOT NULL DEFAULT 0,
    `promo2_amount`  INT          NOT NULL DEFAULT 0,
    `total_amount`   INT          NOT NULL DEFAULT 0 COMMENT 'promo1+promo2',
    `fee_amount`     INT          NOT NULL DEFAULT 0 COMMENT '이 건에 붙은 플랫폼 수수료',
    `status`         ENUM('pending','paid','failed','skipped') NOT NULL DEFAULT 'pending'
                     COMMENT 'skipped=미매칭/금액0 등으로 지급 대상 아님',
    `pg_payment_id`  BIGINT UNSIGNED NULL COMMENT '성공한 카드결제 건',
    `fail_reason`    VARCHAR(300) NOT NULL DEFAULT '',
    `paid_at`        DATETIME     NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pe_batch` (`batch_id`, `status`),
    KEY `idx_pe_rider` (`rider_id`),
    CONSTRAINT `fk_pe_batch` FOREIGN KEY (`batch_id`) REFERENCES `promotion_batches` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pe_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='프로모션 지급 라이더별 내역';

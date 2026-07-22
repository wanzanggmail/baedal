-- 2026 재설계 Phase F: PG 카드결제 · 오픈뱅킹 연동 스키마 (뼈대)
-- 실행: php migrate.php · 참고: LOGIC.md §5.4 · §7 #8·#10
-- FK는 앱 레벨 정합성 정책에 따라 생략(인덱스만).

-- 대리점 등록 카드(빌링키 다건) — 우선순위 순 대체결제
CREATE TABLE IF NOT EXISTS `agency_cards` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agency_id`    INT UNSIGNED NOT NULL COMMENT '대리점 조직(organizations.id)',
    `alias`        VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '카드 별칭',
    `billing_key`  VARCHAR(255) NOT NULL COMMENT 'PG 빌링키(모의 값 가능)',
    `brand`        VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '카드사',
    `last4`        VARCHAR(4)   NOT NULL DEFAULT '' COMMENT '끝 4자리',
    `priority`     SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT '결제 우선순위(낮을수록 먼저)',
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `mock_limit`   INT          NOT NULL DEFAULT 0 COMMENT '개발용 모의 한도(0=무제한). 실 연동 시 미사용',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ac_agency_pri` (`agency_id`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PG 결제 이력(라이더별 건건히) — 성공 시 대리점 잔액 충전
CREATE TABLE IF NOT EXISTS `pg_payments` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agency_id`     INT UNSIGNED NOT NULL,
    `rider_id`      INT UNSIGNED NULL COMMENT '충전 대상 라이더(있으면)',
    `upload_id`     INT UNSIGNED NULL COMMENT '연관 정산 업로드',
    `card_id`       INT UNSIGNED NULL COMMENT '실제 승인된 카드',
    `net_amount`    INT          NOT NULL DEFAULT 0 COMMENT '지갑 충전분(라이더 net)',
    `service_fee`   INT          NOT NULL DEFAULT 0 COMMENT '영업대행수수료',
    `total_charged` INT          NOT NULL DEFAULT 0 COMMENT '카드 청구 총액(net+fee)',
    `status`        ENUM('success','failed') NOT NULL DEFAULT 'success',
    `pg_tid`        VARCHAR(80)  NOT NULL DEFAULT '' COMMENT 'PG 거래번호',
    `fail_reason`   VARCHAR(300) NOT NULL DEFAULT '',
    `attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '시도한 카드 수',
    `created_by`    INT UNSIGNED NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pg_agency_created` (`agency_id`, `created_at`),
    KEY `idx_pg_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 대리점 명의 오픈뱅킹 출금 계좌(핀테크이용번호)
CREATE TABLE IF NOT EXISTS `agency_bank_accounts` (
    `agency_id`      INT UNSIGNED NOT NULL COMMENT '대리점 조직(organizations.id)',
    `bank_code`      VARCHAR(10)  NOT NULL DEFAULT '',
    `account_no`     VARCHAR(40)  NOT NULL DEFAULT '',
    `holder`         VARCHAR(80)  NOT NULL DEFAULT '',
    `fintech_use_num`VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '오픈뱅킹 핀테크이용번호(모의 가능)',
    `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

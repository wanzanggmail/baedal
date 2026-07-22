-- 2026 재설계: 대리점 지갑 (PG 카드결제 조달 → 오픈뱅킹 지급)
-- 실행: php migrate.php
-- 참고: LOGIC.md §5.4 · §5.5 · §7 #9
-- FK는 앱 레벨 정합성 정책에 따라 생략(organizations.id 참조, 인덱스만).

CREATE TABLE IF NOT EXISTS `agency_wallets` (
    `agency_id`            INT UNSIGNED NOT NULL COMMENT '대리점 조직(organizations.id, level=agency)',
    `balance`              BIGINT NOT NULL DEFAULT 0 COMMENT 'PG 충전 잔액(시스템 내부, 원)',
    `withholding_reserve`  BIGINT NOT NULL DEFAULT 0 COMMENT '원천세 예수금 누적(대상 라이더분, 원)',
    `updated_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 영업대행수수료 분배 요율 (조직별, 본사가 관리) — LOGIC §5.4 · §7 #12
-- 대리점의 PG 결제 시 총 영업대행수수료% = 대리점.pct + 상위총판.pct + 본사.pct
-- 각 조직(본사·총판·대리점)이 자기 몫 요율을 가짐. 기본 1.00%(임시, 추후 재조정).
CREATE TABLE IF NOT EXISTS `org_fee_config` (
    `org_id`               INT UNSIGNED NOT NULL COMMENT '조직(organizations.id) — 본사·총판·대리점 각각',
    `pg_service_fee_pct`   DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT '이 조직의 영업대행수수료 몫(%)',
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`           INT UNSIGNED NULL,
    PRIMARY KEY (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 대리점 잔액 변동 이력 (PG충전·지급·자체인출·수동조정 감사)
CREATE TABLE IF NOT EXISTS `agency_wallet_ledger` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agency_id`      INT UNSIGNED NOT NULL,
    `direction`      ENUM('credit','debit') NOT NULL COMMENT 'credit=충전, debit=차감',
    `reason`         VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'pg_fund / rider_payout / agency_payout / manual_adjust',
    `amount`         BIGINT       NOT NULL DEFAULT 0 COMMENT '변동액(원, 양수)',
    `balance_after`  BIGINT       NOT NULL DEFAULT 0 COMMENT '변동 후 잔액',
    `ref_id`         BIGINT UNSIGNED NULL COMMENT '연관 레코드(pg_payments/withdrawal_requests.id 등)',
    `note`           VARCHAR(300) NOT NULL DEFAULT '',
    `created_by`     INT UNSIGNED NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_awl_agency_created` (`agency_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

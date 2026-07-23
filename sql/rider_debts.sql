-- 라이더 부채 원장 — 대여금(loan)·리스/렌탈(lease)·선지급(advance)
-- PDF 정산명세서의 "대여금 차감 상세 / 리스·렌탈 차감 상세 / 선지급금 차감 명세" 대응.
-- 잔액이 주 단위로 이월되고 일납(daily_amount) × 차감일수(days)만큼 상환/부과된다.
-- 실행: php migrate.php · 참고: LOGIC.md §5.5

-- 부채 헤더(원장) — 라이더 1명이 종류별로 여러 건 가질 수 있음
CREATE TABLE IF NOT EXISTS `rider_debts` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rider_id`         INT UNSIGNED NOT NULL,
    `kind`             ENUM('loan','lease','advance') NOT NULL COMMENT 'loan=대여금, lease=리스/렌탈, advance=선지급금',
    `title`            VARCHAR(120) NOT NULL DEFAULT '' COMMENT '표시명(예: 차량대여금)',
    `principal_amount` INT          NOT NULL DEFAULT 0 COMMENT '원금(대여금·선지급). 리스는 0',
    `balance_amount`   INT          NOT NULL DEFAULT 0 COMMENT '남은 잔액(상각형). 리스는 미납 누적(보통 0)',
    `daily_amount`     INT          NOT NULL DEFAULT 0 COMMENT '일납금액(1일 상환/부과액)',
    `creditor`         VARCHAR(120) NOT NULL DEFAULT '' COMMENT '채권자/구분',
    `status`           ENUM('active','paused','closed') NOT NULL DEFAULT 'active',
    `opened_on`        DATE         NULL COMMENT '개시일/출고일',
    `closed_on`        DATE         NULL COMMENT '완납/종료일',
    `due_updated_on`   DATE         NULL COMMENT '미납갱신일(마지막 차감 반영일)',
    `note`             VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rider_debts_rider` (`rider_id`, `status`),
    KEY `idx_rider_debts_kind` (`kind`),
    CONSTRAINT `fk_rider_debts_rider` FOREIGN KEY (`rider_id`) REFERENCES `riders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='라이더 부채 원장(대여금/리스/선지급)';

-- 차감(상환/부과) 이력 — 한 번 차감할 때마다 1행. 생성한 deduction_entries와 연결.
CREATE TABLE IF NOT EXISTS `rider_debt_entries` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `debt_id`            INT UNSIGNED NOT NULL,
    `rider_id`           INT UNSIGNED NOT NULL,
    `applied_date`       DATE         NOT NULL COMMENT '차감 귀속일(정산 반영 applied_date)',
    `days`               SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '차감일수(근무일수)',
    `amount`             INT          NOT NULL DEFAULT 0 COMMENT '이번 차감액',
    `balance_after`      INT          NOT NULL DEFAULT 0 COMMENT '차감후잔액(대여금·선지급)',
    `deduction_entry_id` INT UNSIGNED NULL COMMENT '생성된 deduction_entries.id',
    `memo`               VARCHAR(255) NOT NULL DEFAULT '',
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rde_debt` (`debt_id`, `applied_date`),
    KEY `idx_rde_rider` (`rider_id`, `applied_date`),
    CONSTRAINT `fk_rde_debt` FOREIGN KEY (`debt_id`) REFERENCES `rider_debts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='부채 차감 이력';

-- 정산 엑셀 파일 열기 암호 (플랫폼별)
-- 실행: php migrate.php

CREATE TABLE IF NOT EXISTS `settlement_excel_config` (
    `platform`      VARCHAR(20)  NOT NULL COMMENT 'baemin|coupang|other',
    `open_password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '파일 열기 암호',
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED NULL,
    PRIMARY KEY (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠️ 여기서 기본행을 INSERT 하지 않는다.
-- 예전엔 `INSERT IGNORE ... VALUES ('baemin',''),…` 로 빈 행을 심었는데, 이후 마이그레이션에서
-- `org_id`가 추가되고 유니크키가 (org_id, platform)으로 바뀌면서 문제가 됐다 —
-- **MySQL 유니크키는 NULL을 서로 다른 값으로 본다.** 그래서 전역행(org_id NULL)은 IGNORE가
-- 걸리지 않고 `php migrate.php`를 돌릴 때마다 3행씩 새로 쌓였다(실제로 48행까지 늘어나 있었음).
-- 조회는 `LIMIT 1`이라 정렬 보장 없이 아무 행이나 집게 되고, 빈 암호 행을 집으면 복호화가 실패한다.
-- 기본행은 필요 없다 — 저장할 때 `SettlementExcelConfig::save()`가 없으면 만든다.

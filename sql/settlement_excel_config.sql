-- 정산 엑셀 파일 열기 암호 (플랫폼별)
-- 실행: php migrate.php

CREATE TABLE IF NOT EXISTS `settlement_excel_config` (
    `platform`      VARCHAR(20)  NOT NULL COMMENT 'baemin|coupang|other',
    `open_password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '파일 열기 암호',
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED NULL,
    PRIMARY KEY (`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settlement_excel_config (platform, open_password) VALUES
    ('baemin',  ''),
    ('coupang', ''),
    ('other',   '');

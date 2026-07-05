-- 조직 계층 (어드민 > 총판 > 대리점) — php migrate.php
-- 계정(admins)은 admins.org_id 로 조직에 소속, 라이더는 riders.agency_id 로 대리점에 소속

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `organizations` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id`     INT UNSIGNED NULL COMMENT '상위 조직 (admin 루트는 NULL)',
    `level`         ENUM('admin','distributor','agency') NOT NULL,
    `code`          VARCHAR(40)  NOT NULL COMMENT '조직 식별 코드(유일)',
    `name`          VARCHAR(120) NOT NULL,
    `contact_name`  VARCHAR(80)  NOT NULL DEFAULT '',
    `contact_phone` VARCHAR(30)  NOT NULL DEFAULT '',
    `memo`          VARCHAR(500) NOT NULL DEFAULT '',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_org_code` (`code`),
    KEY `idx_org_parent` (`parent_id`),
    KEY `idx_org_level` (`level`),
    CONSTRAINT `fk_org_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `organizations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='조직 계층(어드민>총판>대리점)';

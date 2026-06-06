-- 콘텐츠: 공지·배너 (php migrate.php)
CREATE TABLE IF NOT EXISTS `content_notices` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `public_id`     VARCHAR(32)     NOT NULL COMMENT '표시용 ID (nt-YYYYMMDD-###)',
    `title`         VARCHAR(200)    NOT NULL,
    `body`          MEDIUMTEXT      NOT NULL,
    `category`      VARCHAR(20)     NOT NULL DEFAULT '일반' COMMENT '일반|안내|긴급',
    `pinned`        TINYINT(1)      NOT NULL DEFAULT 0,
    `status`        ENUM('draft','published','hidden') NOT NULL DEFAULT 'draft',
    `published_at`  DATETIME        NULL,
    `created_by`    INT UNSIGNED    NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notice_public_id` (`public_id`),
    KEY `idx_notice_status_pub` (`status`, `published_at`),
    KEY `idx_notice_pinned` (`pinned`, `status`),
    CONSTRAINT `fk_notice_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='라이더 앱 공지';

-- 콘텐츠: 광고 배너
CREATE TABLE IF NOT EXISTS `content_banners` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `public_id`     VARCHAR(32)     NOT NULL COMMENT '표시용 ID (ad-YYYYMMDD-###)',
    `title`         VARCHAR(120)    NOT NULL,
    `subtitle`      VARCHAR(200)    NOT NULL DEFAULT '',
    `link_url`      VARCHAR(500)    NOT NULL DEFAULT '',
    `image_url`     VARCHAR(500)    NOT NULL DEFAULT '',
    `slot`          VARCHAR(32)     NOT NULL DEFAULT 'rider_app' COMMENT 'home_top|home_middle|rider_app',
    `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    `status`        ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    `start_at`      DATE            NULL,
    `end_at`        DATE            NULL,
    `created_by`    INT UNSIGNED    NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_banner_public_id` (`public_id`),
    KEY `idx_banner_slot_active` (`slot`, `status`, `sort_order`),
    CONSTRAINT `fk_banner_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='라이더 앱 광고 배너';

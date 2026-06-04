-- 감사 로그 (운영 스키마 — 이미 서버에 있으면 migrate 불필요)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `actor_type` enum('admin','rider','system') NOT NULL DEFAULT 'admin',
    `actor_id` int(10) unsigned DEFAULT NULL,
    `action` varchar(80) NOT NULL COMMENT 'CREATE / UPDATE / DELETE / LOGIN 등',
    `target_table` varchar(60) NOT NULL DEFAULT '',
    `target_id` int(10) unsigned DEFAULT NULL,
    `before_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_value`)),
    `after_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_value`)),
    `ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IPv6 포함',
    `user_agent` varchar(300) NOT NULL DEFAULT '',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_al_actor` (`actor_type`,`actor_id`),
    KEY `idx_al_target` (`target_table`,`target_id`),
    KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='감사 로그';

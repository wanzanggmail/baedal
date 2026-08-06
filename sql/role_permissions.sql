CREATE TABLE IF NOT EXISTS role_permissions (
    role       VARCHAR(20) NOT NULL COMMENT 'admin|operation|settlement (super 제외, 항상 전권)',
    area       VARCHAR(30) NOT NULL COMMENT 'dashboard|settlement|deduction|promotion|withdrawal|content|riders',
    can_view   TINYINT(1) NOT NULL DEFAULT 0,
    can_write  TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT UNSIGNED NULL,
    PRIMARY KEY (role, area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='역할별 화면(area) 조회·쓰기 권한 (super 제외)';

INSERT IGNORE INTO role_permissions (role, area, can_view, can_write) VALUES
('admin',      'dashboard',  1, 0),
('operation',  'dashboard',  1, 0),
('settlement', 'dashboard',  1, 0),
('operation',  'settlement', 1, 0),
('settlement', 'settlement', 1, 1),
('settlement', 'deduction',  1, 1),
('settlement', 'promotion',  1, 1),
('admin',      'withdrawal', 1, 0),
('operation',  'withdrawal', 1, 1),
('settlement', 'withdrawal', 1, 0),
('operation',  'content',    1, 1),
('operation',  'riders',     1, 1);

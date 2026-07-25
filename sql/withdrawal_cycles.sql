-- 출금 ↔ 정산 사이클 연결 (§7 #18 정산수수료 age-bucket 모델)
-- 출금 신청 시 "어떤 사이클에서 얼마를 가져갔는지"를 기록한다.
-- 이 기록이 있어야 ① 수수료를 주문 건별 요율로 계산하고 ② 반려 시 정확히 되돌릴 수 있다.
-- 실행: php migrate.php · 참고: LOGIC.md §5.4 · §7 #18

CREATE TABLE IF NOT EXISTS `withdrawal_request_cycles` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`  INT UNSIGNED NOT NULL,
    `cycle_id`    INT UNSIGNED NOT NULL,
    `amount`      INT NOT NULL DEFAULT 0 COMMENT '이 출금에서 해당 사이클로부터 가져간 금액',
    `order_count` INT NOT NULL DEFAULT 0 COMMENT '수수료 부과 기준 건수(부분출금 시 금액 비율로 안분)',
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wrc_request_cycle` (`request_id`, `cycle_id`),
    KEY `idx_wrc_cycle` (`cycle_id`),
    CONSTRAINT `fk_wrc_request` FOREIGN KEY (`request_id`) REFERENCES `withdrawal_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wrc_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `settlement_rider_cycles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='출금-정산사이클 연결(수수료 age-bucket 근거)';

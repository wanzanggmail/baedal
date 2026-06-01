-- ============================================================
-- 도깨비 배달대행 — 초기 Seed 데이터
-- 실행: mysql -u 계정 -p my_web_db < seed.sql
-- !! 운영 서버 실행 후 이 파일을 삭제하거나 접근 차단하세요 !!
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;


-- ============================================================
-- 1. 관리자 계정
--    비밀번호: Admin1234!  (bcrypt cost=12, 로그인 후 즉시 변경 권장)
-- ============================================================
INSERT IGNORE INTO admins (login_id, password_hash, name, email, role)
VALUES
  ('admin',
   '$2y$12$S9z7wlPUPFHdRfYWLl5a7e8Q5Kv3mUkGK3X9Oz4IZK7Pu4Aw0aGmO',
   '최고관리자', 'admin@baedal.local', 'super'),

  ('settlement01',
   '$2y$12$S9z7wlPUPFHdRfYWLl5a7e8Q5Kv3mUkGK3X9Oz4IZK7Pu4Aw0aGmO',
   '정산담당', 'settlement@baedal.local', 'settlement'),

  ('operation01',
   '$2y$12$S9z7wlPUPFHdRfYWLl5a7e8Q5Kv3mUkGK3X9Oz4IZK7Pu4Aw0aGmO',
   '운영담당', 'operation@baedal.local', 'operation');

-- ⚠️  위 해시는 SQL용 임시 값입니다.
--     아래 명령어로 서버에서 직접 해시를 생성한 뒤 UPDATE 하세요:
--     php -r "echo password_hash('Admin1234!', PASSWORD_BCRYPT, ['cost'=>12]);"


-- ============================================================
-- 2. 시스템 코드 마스터
-- ============================================================
INSERT IGNORE INTO system_codes (category, code, label, sort_order) VALUES

  -- 은행
  ('bank', '004', '국민은행',     10),
  ('bank', '088', '신한은행',     20),
  ('bank', '020', '우리은행',     30),
  ('bank', '090', '카카오뱅크',   40),
  ('bank', '081', '하나은행',     50),
  ('bank', '011', '농협',         60),
  ('bank', '003', 'IBK기업은행',  70),
  ('bank', '092', '토스뱅크',     80),
  ('bank', '023', 'SC제일은행',   90),
  ('bank', '032', '부산은행',    100),
  ('bank', '039', '경남은행',    110),
  ('bank', '045', '새마을금고',  120),
  ('bank', '071', '우체국',      130),

  -- 차량 종류
  ('vehicle', 'motor', '오토바이',    10),
  ('vehicle', 'bike',  '자전거',      20),
  ('vehicle', 'kick',  '전동킥보드',  30),
  ('vehicle', 'car',   '자동차',      40),
  ('vehicle', 'walk',  '도보',        50),

  -- 라이더 상태
  ('rider_status', 'active',        '활동 중',   10),
  ('rider_status', 'suspended',     '일시 정지', 20),
  ('rider_status', 'leave_request', '탈퇴 요청', 30),
  ('rider_status', 'offboarded',    '계약 종료', 40),

  -- 정산 업로드 상태
  ('settlement_status', 'uploaded', '업로드됨',  10),
  ('settlement_status', 'parsing',  '파싱 중',   20),
  ('settlement_status', 'parsed',   '파싱 완료', 30),
  ('settlement_status', 'applied',  '반영 완료', 40),
  ('settlement_status', 'error',    '오류',      50),

  -- 출금 상태
  ('withdrawal_status', 'pending',    '대기',          10),
  ('withdrawal_status', 'downloaded', '다운로드 완료', 20),
  ('withdrawal_status', 'completed',  '처리 완료',     30),
  ('withdrawal_status', 'rejected',   '반려',          40),

  -- 플랫폼
  ('platform', 'baemin',  '배달의민족', 10),
  ('platform', 'coupang', '쿠팡이츠',   20),
  ('platform', 'other',   '기타',       30),

  -- 차감 종류
  ('deduction_kind', 'withholding',    '원천세',       10),
  ('deduction_kind', 'employment_ins', '고용·산재',    20),
  ('deduction_kind', 'agency_fee',     '정산 수수료',  30),
  ('deduction_kind', 'hourly_ins',     '시간제 보험',  40),
  ('deduction_kind', 'ins_refund',     '보험료 환급',  50),
  ('deduction_kind', 'rental',         '대여금 차감',  60),
  ('deduction_kind', 'advance',        '선지급 정산',  70),
  ('deduction_kind', 'manual',         '수동 조정',    80);


-- ============================================================
-- 3. 전역 차감 규칙 초기값
-- ============================================================
INSERT IGNORE INTO deduction_global_config
  (id, withholding_tax_pct, employment_ins_pct, agency_fee_pct)
VALUES
  (1, 3.30, 9.12, 2.00);


-- ============================================================
-- 4. 자동 일일정산 설정 초기값
-- ============================================================
INSERT IGNORE INTO daily_auto_config
  (id, tax_withhold_pct, refund_reserve_pct, refund_reserve_fixed,
   min_retain_amount, round_unit, skip_dup, skip_manual_pending)
VALUES
  (1, 3.30, 1.00, 30000, 50000, 1000, 1, 0);


-- 완료 확인
SELECT '✔ admins'               AS tbl, COUNT(*) AS rows FROM admins
UNION ALL
SELECT '✔ system_codes',                 COUNT(*)        FROM system_codes
UNION ALL
SELECT '✔ deduction_global_config',      COUNT(*)        FROM deduction_global_config
UNION ALL
SELECT '✔ daily_auto_config',            COUNT(*)        FROM daily_auto_config;

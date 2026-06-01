<?php

/**
 * 초기 데이터 Seed 스크립트
 * 실행: php seed.php  또는  브라우저에서 /seed.php 접근 후 삭제
 *
 * !! 운영 서버에서 사용 후 반드시 이 파일을 삭제하세요 !!
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$results = [];

function seed_log(string $msg): void
{
    global $results;
    $results[] = $msg;
    echo $msg . "\n";
}

// ================================================================
// 1. 관리자 계정
// ================================================================
$admins = [
    [
        'login_id'      => 'admin',
        'password'      => 'Admin1234!',
        'name'          => '최고관리자',
        'email'         => 'admin@baedal.local',
        'role'          => 'super',
    ],
    [
        'login_id'      => 'settlement01',
        'password'      => 'Admin1234!',
        'name'          => '정산담당',
        'email'         => 'settlement@baedal.local',
        'role'          => 'settlement',
    ],
    [
        'login_id'      => 'operation01',
        'password'      => 'Admin1234!',
        'name'          => '운영담당',
        'email'         => 'operation@baedal.local',
        'role'          => 'operation',
    ],
];

seed_log("\n[1] 관리자 계정 Seed");
foreach ($admins as $a) {
    $exists = db_row(
        'SELECT id FROM admins WHERE login_id = ?',
        [$a['login_id']]
    );
    if ($exists) {
        seed_log("  SKIP  admins.login_id = {$a['login_id']} (이미 존재)");
        continue;
    }
    $hash = password_hash($a['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    db_insert(
        'INSERT INTO admins (login_id, password_hash, name, email, role)
         VALUES (?, ?, ?, ?, ?)',
        [$a['login_id'], $hash, $a['name'], $a['email'], $a['role']]
    );
    seed_log("  OK    admins.login_id = {$a['login_id']} / role={$a['role']}");
}

// ================================================================
// 2. 시스템 코드
// ================================================================
$codes = [
    // 은행
    ['bank', '004', '국민은행',      10],
    ['bank', '088', '신한은행',      20],
    ['bank', '020', '우리은행',      30],
    ['bank', '090', '카카오뱅크',    40],
    ['bank', '081', '하나은행',      50],
    ['bank', '011', '농협',          60],
    ['bank', '003', 'IBK기업은행',   70],
    ['bank', '092', '토스뱅크',      80],
    ['bank', '023', 'SC제일은행',    90],
    ['bank', '032', '부산은행',     100],
    ['bank', '039', '경남은행',     110],
    ['bank', '045', '새마을금고',   120],
    ['bank', '071', '우체국',       130],

    // 차량
    ['vehicle', 'motor', '오토바이',     10],
    ['vehicle', 'bike',  '자전거',       20],
    ['vehicle', 'kick',  '전동킥보드',   30],
    ['vehicle', 'car',   '자동차',       40],
    ['vehicle', 'walk',  '도보',         50],

    // 라이더 상태
    ['rider_status', 'active',        '활동 중',    10],
    ['rider_status', 'suspended',     '일시 정지',  20],
    ['rider_status', 'leave_request', '탈퇴 요청',  30],
    ['rider_status', 'offboarded',    '계약 종료',  40],

    // 정산 업로드 상태
    ['settlement_status', 'uploaded', '업로드됨',   10],
    ['settlement_status', 'parsing',  '파싱 중',    20],
    ['settlement_status', 'parsed',   '파싱 완료',  30],
    ['settlement_status', 'applied',  '반영 완료',  40],
    ['settlement_status', 'error',    '오류',       50],

    // 출금 상태
    ['withdrawal_status', 'pending',    '대기',           10],
    ['withdrawal_status', 'downloaded', '다운로드 완료',  20],
    ['withdrawal_status', 'completed',  '처리 완료',      30],
    ['withdrawal_status', 'rejected',   '반려',           40],

    // 플랫폼
    ['platform', 'baemin',  '배달의민족', 10],
    ['platform', 'coupang', '쿠팡이츠',   20],
    ['platform', 'other',   '기타',       30],

    // 차감 종류
    ['deduction_kind', 'withholding',    '원천세',        10],
    ['deduction_kind', 'employment_ins', '고용·산재',     20],
    ['deduction_kind', 'agency_fee',     '정산 수수료',   30],
    ['deduction_kind', 'hourly_ins',     '시간제 보험',   40],
    ['deduction_kind', 'ins_refund',     '보험료 환급',   50],
    ['deduction_kind', 'rental',         '대여금 차감',   60],
    ['deduction_kind', 'advance',        '선지급 정산',   70],
    ['deduction_kind', 'manual',         '수동 조정',     80],
];

seed_log("\n[2] 시스템 코드 Seed");
foreach ($codes as [$cat, $code, $label, $sort]) {
    $exists = db_row(
        'SELECT id FROM system_codes WHERE category = ? AND code = ?',
        [$cat, $code]
    );
    if ($exists) {
        seed_log("  SKIP  system_codes [{$cat}] {$code}");
        continue;
    }
    db_insert(
        'INSERT INTO system_codes (category, code, label, sort_order)
         VALUES (?, ?, ?, ?)',
        [$cat, $code, $label, $sort]
    );
    seed_log("  OK    system_codes [{$cat}] {$code} = {$label}");
}

// ================================================================
// 3. 전역 차감 규칙 초기값 (없을 때만)
// ================================================================
seed_log("\n[3] 전역 차감 규칙 초기값");
$dgc = db_row('SELECT id FROM deduction_global_config LIMIT 1');
if ($dgc) {
    seed_log("  SKIP  deduction_global_config (이미 존재)");
} else {
    db_insert(
        'INSERT INTO deduction_global_config
            (withholding_tax_pct, employment_ins_pct, agency_fee_pct)
         VALUES (3.30, 9.12, 2.00)'
    );
    seed_log("  OK    deduction_global_config 초기값 삽입");
}

// ================================================================
// 4. 자동 일일정산 설정 초기값 (없을 때만)
// ================================================================
seed_log("\n[4] 자동 일일정산 설정 초기값");
$dac = db_row('SELECT id FROM daily_auto_config LIMIT 1');
if ($dac) {
    seed_log("  SKIP  daily_auto_config (이미 존재)");
} else {
    db_insert(
        'INSERT INTO daily_auto_config
            (tax_withhold_pct, refund_reserve_pct, refund_reserve_fixed,
             min_retain_amount, round_unit, skip_dup, skip_manual_pending)
         VALUES (3.30, 1.00, 30000, 50000, 1000, 1, 0)'
    );
    seed_log("  OK    daily_auto_config 초기값 삽입");
}

// ================================================================
// 완료
// ================================================================
seed_log("\n====================================");
seed_log("Seed 완료. 이 파일(seed.php)을 삭제하세요!");
seed_log("====================================\n");

// 브라우저 접근 시 읽기 편하게 출력
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

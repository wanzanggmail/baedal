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
// 0. 조직 트리 (어드민 > 총판 > 대리점)
// ================================================================
// code 로 멱등 upsert, id 반환
function seed_org(string $code, string $level, string $name, ?int $parentId): int
{
    $exists = db_row('SELECT id FROM organizations WHERE code = ? LIMIT 1', [$code]);
    if ($exists) {
        seed_log("  SKIP  organizations [{$code}] (이미 존재)");
        return (int) $exists['id'];
    }
    db_insert(
        'INSERT INTO organizations (parent_id, level, code, name) VALUES (?, ?, ?, ?)',
        [$parentId, $level, $code, $name]
    );
    $id = (int) db_row('SELECT id FROM organizations WHERE code = ? LIMIT 1', [$code])['id'];
    seed_log("  OK    organizations [{$code}] {$level} = {$name} (id={$id})");
    return $id;
}

seed_log("\n[0] 조직 트리 Seed");
$orgRoot   = seed_org('HQ',      'admin',       '도깨비 본사', null);
$orgDist   = seed_org('DIST-01', 'distributor', '서울총판',    $orgRoot);
$orgAgency = seed_org('AG-01',   'agency',      '강남대리점',  $orgDist);

// ================================================================
// 1. 관리자 계정 (각 계층 테스트 계정 — org_id 로 소속)
// ================================================================
$admins = [
    [
        'login_id'      => 'admin',
        'password'      => 'Admin1234!',
        'name'          => '최고관리자',
        'email'         => 'admin@baedal.local',
        'role'          => 'super',
        'org_id'        => $orgRoot,
    ],
    [
        'login_id'      => 'operation01',
        'password'      => 'Admin1234!',
        'name'          => '총판운영담당',
        'email'         => 'operation@baedal.local',
        'role'          => 'operation',
        'org_id'        => $orgDist,
    ],
    [
        'login_id'      => 'settlement01',
        'password'      => 'Admin1234!',
        'name'          => '대리점정산담당',
        'email'         => 'settlement@baedal.local',
        'role'          => 'settlement',
        'org_id'        => $orgAgency,
    ],
];

seed_log("\n[1] 관리자 계정 Seed");
foreach ($admins as $a) {
    $exists = db_row(
        'SELECT id, org_id FROM admins WHERE login_id = ?',
        [$a['login_id']]
    );
    if ($exists) {
        // 멀티테넌시 도입 전 생성된 계정이면 org_id 백필
        if ($exists['org_id'] === null) {
            db_execute('UPDATE admins SET org_id = ? WHERE id = ?', [$a['org_id'], (int) $exists['id']]);
            seed_log("  OK    admins.login_id = {$a['login_id']} org_id 백필 → {$a['org_id']}");
        } else {
            seed_log("  SKIP  admins.login_id = {$a['login_id']} (이미 존재)");
        }
        continue;
    }
    $hash = password_hash($a['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    db_insert(
        'INSERT INTO admins (login_id, password_hash, name, email, role, org_id)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$a['login_id'], $hash, $a['name'], $a['email'], $a['role'], $a['org_id']]
    );
    seed_log("  OK    admins.login_id = {$a['login_id']} / role={$a['role']} / org_id={$a['org_id']}");
}

// 기존 라이더를 샘플 대리점에 귀속 (멀티테넌시 백필)
if (db_table_exists('riders')) {
    $missing = (int) (db_row('SELECT COUNT(*) AS c FROM riders WHERE agency_id IS NULL')['c'] ?? 0);
    if ($missing > 0) {
        db_execute('UPDATE riders SET agency_id = ? WHERE agency_id IS NULL', [$orgAgency]);
        seed_log("  OK    riders.agency_id 백필 → {$orgAgency} ({$missing}명)");
    } else {
        seed_log("  SKIP  riders.agency_id 백필 (대상 없음)");
    }
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
// 완료
// ================================================================
seed_log("\n====================================");
seed_log("Seed 완료. 이 파일(seed.php)을 삭제하세요!");
seed_log("====================================\n");

// 브라우저 접근 시 읽기 편하게 출력
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

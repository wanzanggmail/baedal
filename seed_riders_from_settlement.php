<?php

/**
 * settlement_daily_riders 에 있는 엑셀 이름·라이선스 ID 기준 라이더 더미 데이터 생성
 *
 * 실행: php seed_riders_from_settlement.php
 *       또는 브라우저 /seed_riders_from_settlement.php
 *
 * - rider_platforms.external_id = 배민 라이선스 ID (업로드 매칭용)
 * - 생성 후 settlement_daily_riders.rider_id 자동 연결
 * - 초기 비밀번호: Rider1234!
 *
 * !! 실행 후 이 파일을 삭제하세요 !!
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$pwHash = password_hash('Rider1234!', PASSWORD_BCRYPT, ['cost' => 12]);

// 엑셀 업로드에 등장한 라이더 (DB에 없을 때 참고용 — 실제는 DB에서 DISTINCT 조회)
$fallbackFromExcel = [
    ['license_id' => '130721117156', 'rider_name_raw' => '노동현0647', 'platform' => 'baemin'],
    ['license_id' => '130724535737', 'rider_name_raw' => '김태원1795', 'platform' => 'baemin'],
    ['license_id' => '130722882654', 'rider_name_raw' => '이인용9205', 'platform' => 'baemin'],
    ['license_id' => '130724137855', 'rider_name_raw' => '이정욱2708', 'platform' => 'baemin'],
    ['license_id' => '130721263774', 'rider_name_raw' => '서원빈5224', 'platform' => 'baemin'],
    ['license_id' => '130725246949', 'rider_name_raw' => '권성진4418', 'platform' => 'baemin'],
    ['license_id' => '130725262033', 'rider_name_raw' => '이지성3824', 'platform' => 'baemin'],
    ['license_id' => '130723558883', 'rider_name_raw' => '김상완8544', 'platform' => 'baemin'],
    ['license_id' => '130721093949', 'rider_name_raw' => '박현수5589', 'platform' => 'baemin'],
    ['license_id' => '130721937033', 'rider_name_raw' => '고재창8362', 'platform' => 'baemin'],
    ['license_id' => '130721014470', 'rider_name_raw' => '박재효8200', 'platform' => 'baemin'],
    ['license_id' => '130722496238', 'rider_name_raw' => '이충회4339', 'platform' => 'baemin'],
    ['license_id' => '130723706710', 'rider_name_raw' => '최진수8604', 'platform' => 'baemin'],
    ['license_id' => '130724109153', 'rider_name_raw' => '김종표1528', 'platform' => 'baemin'],
    ['license_id' => '130722507500', 'rider_name_raw' => '이효섭0268', 'platform' => 'baemin'],
    ['license_id' => '130723113359', 'rider_name_raw' => '한다빈7174', 'platform' => 'baemin'],
    ['license_id' => '130724706176', 'rider_name_raw' => '한다빈7174', 'platform' => 'baemin'],
];

/** 엑셀 이름(rider_name_raw)당 1명 — 배민 정산이므로 platform은 baemin 고정 */
function settlement_rider_sources(array $fallback): array
{
    $rows = [];
    try {
        $rows = db_rows(
            'SELECT license_id, rider_name_raw, platform
               FROM settlement_daily_riders
              WHERE license_id <> \'\'
              ORDER BY rider_name_raw, platform = \'baemin\' DESC, LENGTH(license_id) DESC'
        );
    } catch (Throwable $e) {
        echo "DB 조회 실패, 엑셀 기준 목록 사용: {$e->getMessage()}\n\n";
        return $fallback;
    }

    if ($rows === []) {
        return $fallback;
    }

    $byName = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['rider_name_raw'] ?? ''));
        if ($key === '' || isset($byName[$key])) {
            continue;
        }
        $byName[$key] = [
            'license_id'     => trim((string) $row['license_id']),
            'rider_name_raw' => $key,
            'platform'       => 'baemin',
        ];
    }

    return array_values($byName);
}

$sources = settlement_rider_sources($fallbackFromExcel);

if ($sources === []) {
    echo "생성할 대상이 없습니다. 먼저 정산 엑셀을 업로드하세요.\n";
    exit;
}

$inserted = 0;
$skipped  = 0;
$linked   = 0;
$errors   = [];

foreach ($sources as $src) {
    $licenseId = trim((string) ($src['license_id'] ?? ''));
    $nameRaw   = trim((string) ($src['rider_name_raw'] ?? ''));
    $platform  = trim((string) ($src['platform'] ?? 'baemin'));

    if ($licenseId === '' || $nameRaw === '') {
        continue;
    }

    if (!in_array($platform, ['baemin', 'coupang', 'other'], true)) {
        $platform = 'baemin';
    }

    $name = preg_replace('/\d+$/u', '', $nameRaw) ?: $nameRaw;

    // 같은 엑셀 이름으로 이미 생성된 라이더가 있으면 플랫폼 연동만 보강
    $existingByName = db_row(
        'SELECT r.id AS rider_id
           FROM riders r
          WHERE r.name = ?
            AND r.admin_memo LIKE \'%정산 업로드%\'
          ORDER BY r.id
          LIMIT 1',
        [$name]
    );
    if ($existingByName) {
        $riderId = (int) $existingByName['rider_id'];
        $hasPlatform = db_row(
            'SELECT id FROM rider_platforms WHERE rider_id = ? AND platform = ? AND external_id = ?',
            [$riderId, $platform, $licenseId]
        );
        if (!$hasPlatform) {
            db_insert(
                'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id)
                 VALUES (?, ?, 1, ?)',
                [$riderId, $platform, $licenseId]
            );
        }
        echo "SKIP  {$nameRaw} — 기존 라이더 rider_id={$riderId} (이름 기준)\n";
        $skipped++;
        continue;
    }

    // 라이선스 ID로 이미 연동된 경우 스킵
    $existingPlatform = db_row(
        'SELECT rider_id FROM rider_platforms WHERE platform = ? AND external_id = ?',
        [$platform, $licenseId]
    );
    if ($existingPlatform) {
        echo "SKIP  {$nameRaw} — 라이선스 {$licenseId} 이미 연동 (rider_id={$existingPlatform['rider_id']})\n";
        $skipped++;
        continue;
    }

    $nameSuffix = preg_replace('/^[^\d]+/u', '', $nameRaw);
    $phone      = '010' . str_pad(substr($nameSuffix !== '' ? $nameSuffix : substr($licenseId, -4), 0, 4), 4, '0', STR_PAD_LEFT)
        . str_pad((string) (abs(crc32($licenseId)) % 10000), 4, '0', STR_PAD_LEFT);

    $loginId = 'mb' . substr($licenseId, -10);
    if (db_row('SELECT id FROM riders WHERE login_id = ?', [$loginId])) {
        $loginId = 'mb' . $licenseId;
    }

    try {
        $riderId = db_transaction(static function () use (
            $licenseId, $nameRaw, $name, $phone, $platform, $pwHash, $loginId
        ): int {
            $riderCode = 'R-SET-' . substr($licenseId, -8);

            if (db_row('SELECT id FROM riders WHERE rider_code = ?', [$riderCode])) {
                $riderCode = 'R-SET-' . substr($licenseId, -6) . '-' . substr(bin2hex(random_bytes(2)), 0, 2);
            }

            $id = db_insert(
                'INSERT INTO riders
                    (rider_code, login_id, password_hash, name, phone, email,
                     team_code, vehicle_type, status,
                     bank_code, bank_account, account_holder,
                     is_daily_settlement, kyc_status, admin_memo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, ?, 1, \'verified\', ?)',
                [
                    $riderCode,
                    $loginId,
                    $pwHash,
                    $name,
                    $phone,
                    $loginId . '@baedal.local',
                    'gangseo_a',
                    'motor',
                    '004',
                    '1234567890' . substr($licenseId, -4),
                    $name,
                    '정산 업로드 기준 자동 생성 (' . $nameRaw . ')',
                ]
            );

            db_insert(
                'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id)
                 VALUES (?, ?, 1, ?)',
                [$id, $platform, $licenseId]
            );

            return $id;
        });

        echo "OK    {$nameRaw} → rider_id={$riderId}, login_id={$loginId}, license={$licenseId}\n";
        $inserted++;
    } catch (Throwable $e) {
        echo "ERROR {$nameRaw} → {$e->getMessage()}\n";
        $errors[] = $nameRaw;
    }
}

// 정산 행에 rider_id 일괄 연결
try {
    $linked = db_execute(
        'UPDATE settlement_daily_riders dr
            INNER JOIN rider_platforms rp
                ON rp.external_id = dr.license_id AND rp.platform = dr.platform
           SET dr.rider_id = rp.rider_id
         WHERE dr.rider_id IS NULL'
    );
} catch (Throwable $e) {
    echo "\n연결 UPDATE 실패: {$e->getMessage()}\n";
}

echo "\n=====================================\n";
echo "완료: 생성 {$inserted}명 / 스킵 {$skipped}명 / 오류 " . count($errors) . "건\n";
echo "정산 행 rider_id 연결: {$linked}건 갱신\n";
echo "초기 비밀번호: Rider1234!\n";
echo "업로드 상세 화면에서 매칭 상태를 확인하세요.\n";
echo "이 파일(seed_riders_from_settlement.php)을 삭제하세요!\n";
echo "=====================================\n";

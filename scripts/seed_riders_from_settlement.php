<?php

declare(strict_types=1);

/**
 * 정산 업로드 미매칭 행 → 더미 라이더 seed (로컬·서버 1회성)
 *
 * 사용:
 *   php scripts/seed_riders_from_settlement.php --upload-id=3
 *   php scripts/seed_riders_from_settlement.php --upload-id=3 --dry-run
 *   php scripts/seed_riders_from_settlement.php --upload-id=3 --sql-only > seed_riders.sql
 *
 * 옵션:
 *   --upload-id=N   (필수) settlement_uploads.id
 *   --password=...  초기 비밀번호 (기본 Rider1234!)
 *   --dry-run       DB 변경 없이 대상만 출력
 *   --sql-only      INSERT/UPDATE SQL만 출력 (실행 안 함)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI 전용입니다.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$opts = getopt('', ['upload-id:', 'password::', 'dry-run', 'sql-only']);
$uploadId = (int) ($opts['upload-id'] ?? 0);
$password = (string) ($opts['password'] ?? 'Rider1234!');
$dryRun   = array_key_exists('dry-run', $opts);
$sqlOnly  = array_key_exists('sql-only', $opts);

if ($uploadId <= 0) {
    fwrite(STDERR, "사용법: php scripts/seed_riders_from_settlement.php --upload-id=N [--password=...] [--dry-run] [--sql-only]\n");
    exit(1);
}

if (strlen($password) < 4) {
    fwrite(STDERR, "비밀번호는 4자 이상이어야 합니다.\n");
    exit(1);
}

$upload = db_row(
    'SELECT id, platform, settlement_date, original_filename FROM settlement_uploads WHERE id = ? AND kind = ?',
    [$uploadId, 'daily']
);
if ($upload === null) {
    fwrite(STDERR, "업로드 #{$uploadId} 를 찾을 수 없습니다.\n");
    exit(1);
}

$platform = (string) $upload['platform'];
$rows = db_rows(
    'SELECT id, license_id, rider_name_raw
       FROM settlement_daily_riders
      WHERE upload_id = ? AND rider_id IS NULL
      ORDER BY id ASC',
    [$uploadId]
);

if ($rows === []) {
    echo "미매칭 라이더가 없습니다. (upload_id={$uploadId})\n";
    exit(0);
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$created = 0;
$linked  = 0;
$sqlLines = ["-- upload_id={$uploadId} platform={$platform} rows=" . count($rows), 'START TRANSACTION;', ''];

foreach ($rows as $row) {
    $dailyId   = (int) $row['id'];
    $licenseId = trim((string) ($row['license_id'] ?? ''));
    $nameRaw   = trim((string) ($row['rider_name_raw'] ?? ''));
    $name      = cleanRiderName($nameRaw);

    if ($name === '' && $licenseId === '') {
        echo "건너뜀 #{$dailyId}: 이름·라이선스 없음\n";
        continue;
    }
    if ($name === '') {
        $name = '더미' . ($licenseId !== '' ? $licenseId : (string) $dailyId);
    }

    $existingId = findExistingRiderId($platform, $licenseId, $name);

    if ($sqlOnly) {
        if ($existingId !== null) {
            $sqlLines[] = "-- 연결: {$nameRaw} → rider #{$existingId}";
            $sqlLines[] = "UPDATE settlement_daily_riders SET rider_id = {$existingId} WHERE id = {$dailyId} AND rider_id IS NULL;";
            $linked++;
        } else {
            $loginId   = uniqueLoginId($licenseId);
            $riderCode = uniqueRiderCode($licenseId);
            $memo      = sqlQuote("더미 seed · 업로드 #{$uploadId}");
            $sqlLines[] = "-- 생성: {$nameRaw} login={$loginId}";
            $sqlLines[] = "INSERT INTO riders (rider_code, login_id, password_hash, name, phone, email, status, team_code, vehicle_type, admin_memo)"
                . " VALUES ('{$riderCode}', '{$loginId}', " . sqlQuote($passwordHash) . ', '
                . sqlQuote($name) . ", '01000000000', '', 'active', 'etc', 'motor', {$memo});";
            $sqlLines[] = "SET @rid = LAST_INSERT_ID();";
            $sqlLines[] = "INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id)"
                . " VALUES (@rid, " . sqlQuote($platform) . ", 1, " . sqlQuote($licenseId) . ');';
            $sqlLines[] = "UPDATE settlement_daily_riders SET rider_id = @rid WHERE id = {$dailyId} AND rider_id IS NULL;";
            $sqlLines[] = '';
            $created++;
        }
        continue;
    }

    echo ($existingId !== null ? '[연결] ' : '[생성] ')
        . "{$nameRaw} | 이름={$name} | 라이선스={$licenseId}\n";

    if ($dryRun) {
        if ($existingId !== null) {
            $linked++;
        } else {
            $created++;
        }
        continue;
    }

    if ($existingId !== null) {
        db_execute(
            'UPDATE settlement_daily_riders SET rider_id = ? WHERE id = ? AND rider_id IS NULL',
            [$existingId, $dailyId]
        );
        ensurePlatformLink($existingId, $platform, $licenseId);
        $linked++;
        continue;
    }

    $loginId   = uniqueLoginId($licenseId);
    $riderCode = uniqueRiderCode($licenseId);
    $memo      = "더미 seed · 업로드 #{$uploadId}";

    $riderId = db_insert(
        'INSERT INTO riders
            (rider_code, login_id, password_hash, name, phone, email, status, team_code, vehicle_type, admin_memo)
         VALUES (?, ?, ?, ?, ?, ?, \'active\', \'etc\', \'motor\', ?)',
        [$riderCode, $loginId, $passwordHash, $name, '01000000000', '', $memo]
    );

    db_insert(
        'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)',
        [$riderId, $platform, $licenseId]
    );

    db_execute(
        'UPDATE settlement_daily_riders SET rider_id = ? WHERE id = ? AND rider_id IS NULL',
        [$riderId, $dailyId]
    );

    echo "  → rider_id={$riderId} login_id={$loginId} code={$riderCode}\n";
    $created++;
}

if ($sqlOnly) {
    $sqlLines[] = refreshUploadCountsSql($uploadId);
    $sqlLines[] = 'COMMIT;';
    echo implode("\n", $sqlLines) . "\n";
    echo "\n-- 생성 {$created} · 연결 {$linked}\n";
    exit(0);
}

if (!$dryRun && ($created > 0 || $linked > 0)) {
    refreshUploadCounts($uploadId);
}

echo "\n완료: 신규 {$created} · 연결 {$linked} · 비밀번호={$password}\n";
if ($created > 0) {
    echo "※ 더미 전화번호 01000000000 — 나중에 수정하세요.\n";
}

function cleanRiderName(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    return trim((string) preg_replace('/\d+$/', '', $raw)) ?: $raw;
}

function findExistingRiderId(string $platform, string $licenseId, string $name): ?int
{
    if ($licenseId !== '') {
        $row = db_row(
            'SELECT rider_id FROM rider_platforms WHERE platform = ? AND external_id = ? LIMIT 1',
            [$platform, $licenseId]
        );
        if ($row !== null) {
            return (int) $row['rider_id'];
        }
    }

    $row = db_row('SELECT id FROM riders WHERE name = ? LIMIT 1', [$name]);

    return $row !== null ? (int) $row['id'] : null;
}

function uniqueLoginId(string $licenseId): string
{
    $base = ($licenseId !== '' && ctype_digit($licenseId)) ? 'r' . $licenseId : 'r' . date('ymd') . substr(bin2hex(random_bytes(3)), 0, 5);

    if (!db_row('SELECT id FROM riders WHERE login_id = ?', [$base])) {
        return $base;
    }

    for ($n = 2; $n <= 99; $n++) {
        $try = substr($base, 0, 58) . $n;
        if (!db_row('SELECT id FROM riders WHERE login_id = ?', [$try])) {
            return $try;
        }
    }

    throw new RuntimeException("login_id 생성 실패: {$base}");
}

function uniqueRiderCode(string $licenseId): string
{
    if ($licenseId !== '' && preg_match('/^[A-Za-z0-9._\-]+$/', $licenseId)) {
        $try = 'R-L' . substr($licenseId, 0, 24);
        if (!db_row('SELECT id FROM riders WHERE rider_code = ?', [$try])) {
            return $try;
        }
    }

    do {
        $code = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    } while (db_row('SELECT id FROM riders WHERE rider_code = ?', [$code]));

    return $code;
}

function ensurePlatformLink(int $riderId, string $platform, string $licenseId): void
{
    if ($licenseId === '') {
        return;
    }

    $rp = db_row(
        'SELECT id, external_id FROM rider_platforms WHERE rider_id = ? AND platform = ? LIMIT 1',
        [$riderId, $platform]
    );
    if ($rp === null) {
        db_insert(
            'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)',
            [$riderId, $platform, $licenseId]
        );

        return;
    }

    if (trim((string) ($rp['external_id'] ?? '')) === '') {
        db_execute('UPDATE rider_platforms SET external_id = ?, is_connected = 1 WHERE id = ?', [$licenseId, (int) $rp['id']]);
    }
}

function refreshUploadCounts(int $uploadId): void
{
    $stats = db_row(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN rider_id IS NOT NULL THEN 1 ELSE 0 END) AS matched
           FROM settlement_daily_riders WHERE upload_id = ?',
        [$uploadId]
    ) ?: ['total' => 0, 'matched' => 0];

    $total   = (int) $stats['total'];
    $matched = (int) $stats['matched'];

    db_execute(
        'UPDATE settlement_uploads SET ok_rows = ?, error_rows = ? WHERE id = ?',
        [$matched, max(0, $total - $matched), $uploadId]
    );
}

function refreshUploadCountsSql(int $uploadId): string
{
    return "UPDATE settlement_uploads u SET\n"
        . "  ok_rows = (SELECT COUNT(*) FROM settlement_daily_riders WHERE upload_id = {$uploadId} AND rider_id IS NOT NULL),\n"
        . "  error_rows = (SELECT COUNT(*) FROM settlement_daily_riders WHERE upload_id = {$uploadId} AND rider_id IS NULL)\n"
        . "WHERE u.id = {$uploadId};";
}

function sqlQuote(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
}

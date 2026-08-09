<?php

declare(strict_types=1);

/**
 * 라이더 일괄등록 엑셀 업로드 — 신규 대리점 입점 시 기존 라이더를 한 번에 옮겨 적기 위한 기능.
 * POST multipart: file, agency_id, mode=preview|confirm
 *
 *  mode=preview : 파싱 + 검증만(저장 안 함) — 행별 성공/실패 미리 보여줌
 *  mode=confirm : 미리보기에서 "성공" 판정난 행만 실제 등록(RiderRegistration::create 재사용,
 *                 단건등록과 완전히 같은 검증 규칙)
 *
 * settlement_upload.php의 두 단계(미리보기→확정) UX와 promotion_upload.php의 헤더-이름 매핑
 * 파싱 패턴을 그대로 재사용한다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/RiderRegistration.php';
require_once INC_PATH . '/XlsxParser.php';
require_once INC_PATH . '/AuditLog.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST만 허용합니다.', 405);
}
admin_deny_write_json('riders');

if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId = (int) ($_POST['agency_id'] ?? 0);
    if ($agencyId < 1) {
        $err('대리점을 선택하세요.');
    }
}
if (!Org::canAccessAgency($agencyId)) {
    $err('이 대리점에 접근할 권한이 없습니다.', 403);
}

$mode = (string) ($_POST['mode'] ?? 'preview') === 'confirm' ? 'confirm' : 'preview';

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $err('엑셀 파일을 선택하세요.');
}
$origName = (string) ($_FILES['file']['name'] ?? '');
$tmpPath  = (string) ($_FILES['file']['tmp_name'] ?? '');
if (strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'xlsx') {
    $err('xlsx 파일만 업로드할 수 있습니다.');
}
if ((int) ($_FILES['file']['size'] ?? 0) > 20 * 1024 * 1024) {
    $err('파일이 너무 큽니다. (최대 20MB)');
}

XlsxParser::assertRequirements();

$prev = set_error_handler(static fn (int $sev): bool => $sev === E_DEPRECATED || $sev === E_USER_DEPRECATED);
try {
    $book = IOFactory::load($tmpPath);
    $ws   = $book->getActiveSheet();
    $all  = $ws->toArray(null, true, false, false);
} catch (Throwable $e) {
    $err('엑셀을 읽을 수 없습니다: ' . $e->getMessage());
} finally {
    if ($prev !== null) {
        set_error_handler($prev);
    } else {
        restore_error_handler();
    }
}

// 헤더 행 찾기 — "이름"과 "휴대전화"가 함께 있는 행
$headerIdx = null;
$colMap    = [];
foreach ($all as $i => $cols) {
    $rowHas = ['name' => null, 'phone' => null];
    foreach ((array) $cols as $c => $v) {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            continue;
        }
        if ($rowHas['name'] === null && str_contains($s, '이름')) {
            $rowHas['name'] = $c;
        }
        if ($rowHas['phone'] === null && str_contains($s, '휴대전화')) {
            $rowHas['phone'] = $c;
        }
    }
    if ($rowHas['name'] !== null && $rowHas['phone'] !== null) {
        $headerIdx = $i;
        break;
    }
}
if ($headerIdx === null) {
    $err('헤더를 찾을 수 없습니다. 템플릿을 다운로드해 그 양식 그대로 사용하세요. (필요 컬럼: 이름 / 휴대전화)');
}

$fieldMatchers = [
    'name'           => ['이름'],
    'phone'          => ['휴대전화'],
    'login_id'       => ['로그인ID', '로그인 ID'],
    'rider_code'     => ['라이더코드', '라이더 코드'],
    'vehicle_type'   => ['차량종류', '차량 종류'],
    'team_code'      => ['팀코드', '팀 코드'],
    'bank_code'      => ['은행코드', '은행 코드'],
    'bank_account'   => ['계좌번호', '계좌 번호'],
    'account_holder' => ['예금주'],
    'email'          => ['이메일'],
    'coupang_id'     => ['쿠팡ID', '쿠팡 ID'],
    'baemin_id'      => ['배민ID', '배민 ID'],
];
foreach ((array) $all[$headerIdx] as $c => $v) {
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        continue;
    }
    foreach ($fieldMatchers as $field => $needles) {
        if (isset($colMap[$field])) {
            continue;
        }
        foreach ($needles as $needle) {
            if (str_contains($s, $needle)) {
                $colMap[$field] = $c;
                break;
            }
        }
    }
}

$rows = [];
foreach ($all as $i => $cols) {
    if ($i <= $headerIdx) {
        continue;
    }
    $get = static fn (string $field): string => isset($colMap[$field])
        ? trim((string) ($cols[$colMap[$field]] ?? ''))
        : '';

    $name  = $get('name');
    $phone = $get('phone');
    if ($name === '' && $phone === '') {
        continue; // 빈 행
    }

    $rows[] = [
        'row_no'         => $i + 1,
        'name'           => $name,
        'phone'          => $phone,
        'login_id'       => $get('login_id'),
        'rider_code'     => $get('rider_code'),
        'vehicle_type'   => $get('vehicle_type') !== '' ? $get('vehicle_type') : 'motor',
        'team_code'      => $get('team_code') !== '' ? $get('team_code') : 'etc',
        'bank_code'      => $get('bank_code'),
        'bank_account'   => $get('bank_account'),
        'account_holder' => $get('account_holder'),
        'email'          => $get('email'),
        'coupang_id'     => $get('coupang_id'),
        'baemin_id'      => $get('baemin_id'),
    ];
}

if ($rows === []) {
    $err('읽을 데이터가 없습니다.');
}

// 파일 안에서도 같은 값끼리 중복될 수 있으므로(휴대전화 두 번, 로그인ID 두 번 등) 미리 표시.
$phoneSeen  = [];
$loginSeen  = [];
$codeSeen   = [];
foreach ($rows as $r) {
    if ($r['phone'] !== '') { $phoneSeen[$r['phone']] = ($phoneSeen[$r['phone']] ?? 0) + 1; }
    if ($r['login_id'] !== '') { $loginSeen[$r['login_id']] = ($loginSeen[$r['login_id']] ?? 0) + 1; }
    if ($r['rider_code'] !== '') { $codeSeen[$r['rider_code']] = ($codeSeen[$r['rider_code']] ?? 0) + 1; }
}

$results = [];
$okCount = 0;
$failCount = 0;

foreach ($rows as $r) {
    $rowErr = null;
    if (($phoneSeen[$r['phone']] ?? 0) > 1) {
        $rowErr = '파일 안에서 휴대전화가 중복됩니다.';
    } elseif ($r['login_id'] !== '' && ($loginSeen[$r['login_id']] ?? 0) > 1) {
        $rowErr = '파일 안에서 로그인ID가 중복됩니다.';
    } elseif ($r['rider_code'] !== '' && ($codeSeen[$r['rider_code']] ?? 0) > 1) {
        $rowErr = '파일 안에서 라이더코드가 중복됩니다.';
    }

    if ($mode === 'preview') {
        if ($rowErr === null) {
            // 미리보기에서는 실제로 만들지 않고 검증만 — create()와 완전히 같은 규칙(validate())을 재사용.
            try {
                RiderRegistration::validate(array_merge($r, ['agency_id' => $agencyId]));
            } catch (InvalidArgumentException $e) {
                $rowErr = $e->getMessage();
            }
        }
    } else {
        if ($rowErr === null) {
            try {
                $created = RiderRegistration::create(array_merge($r, ['agency_id' => $agencyId]));
                $r['id']         = $created['id'];
                $r['rider_code'] = $created['rider_code'];
                $r['login_id']   = $created['login_id'];
            } catch (InvalidArgumentException $e) {
                $rowErr = $e->getMessage();
            }
        }
    }

    if ($rowErr === null) {
        $okCount++;
    } else {
        $failCount++;
    }

    $results[] = [
        'row_no'     => $r['row_no'],
        'name'       => $r['name'],
        'phone'      => $r['phone'],
        'login_id'   => $r['login_id'],
        'rider_code' => $r['rider_code'],
        'ok'         => $rowErr === null,
        'error'      => $rowErr,
    ];
}

if ($mode === 'preview') {
    echo json_encode([
        'ok'        => true,
        'preview'   => true,
        'agency_id' => $agencyId,
        'summary'   => [
            'total' => count($results),
            'ok'    => $okCount,
            'fail'  => $failCount,
        ],
        'rows' => $results,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

AuditLog::record(
    'riders.bulk_register',
    $origName,
    "{$agencyId} · 성공 {$okCount}명 · 실패 {$failCount}명"
);

echo json_encode([
    'ok'      => true,
    'summary' => [
        'total' => count($results),
        'ok'    => $okCount,
        'fail'  => $failCount,
    ],
    'rows'    => $results,
    'message' => "{$okCount}명 등록 완료" . ($failCount > 0 ? " (실패 {$failCount}명)" : ''),
], JSON_UNESCAPED_UNICODE);

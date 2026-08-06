<?php

declare(strict_types=1);

/**
 * 프로모션 엑셀 업로드 (LOGIC §5.8)
 * POST multipart: file, agency_id, pay_date, memo, mode=preview|confirm
 *
 *  mode=preview : 파싱 + 라이더 매칭 결과만 반환(저장 안 함)
 *  mode=confirm : 배치 저장 후 **즉시 지급 실행**(라이더별 카드결제 → 성공 건만 지갑 적립)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Promotion.php';
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
admin_deny_write_json('settlement');

if (!Promotion::tableReady()) {
    $err('프로모션 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

// ── 대리점 결정 ──
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

$payDate = trim((string) ($_POST['pay_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
    $err('지급일자를 선택하세요.');
}
$mode = (string) ($_POST['mode'] ?? 'preview') === 'confirm' ? 'confirm' : 'preview';
$memo = trim((string) ($_POST['memo'] ?? ''));

// ── 파일 확인 ──
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

// ── 파싱: 헤더(라이더코드/이름/프로모션1/프로모션2)를 찾아 그 아래 행을 읽는다 ──
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

/** 헤더 행 찾기 — "라이더코드"가 있는 행 */
$headerIdx = null;
$colMap    = [];
foreach ($all as $i => $cols) {
    foreach ((array) $cols as $c => $v) {
        $s = trim((string) ($v ?? ''));
        if ($s === '') {
            continue;
        }
        if (str_contains($s, '라이더코드') || str_contains($s, '라이더아이디')) {
            $headerIdx = $i;
            break 2;
        }
    }
}
if ($headerIdx === null) {
    $err('헤더를 찾을 수 없습니다. 템플릿을 다운로드해 그 양식 그대로 사용하세요. (필요 컬럼: ' . implode(' / ', Promotion::TEMPLATE_HEADERS) . ')');
}

foreach ((array) $all[$headerIdx] as $c => $v) {
    $s = trim((string) ($v ?? ''));
    if ($s === '') {
        continue;
    }
    if (!isset($colMap['rider_code']) && (str_contains($s, '라이더코드') || str_contains($s, '라이더아이디'))) {
        $colMap['rider_code'] = $c;
    } elseif (!isset($colMap['name']) && str_contains($s, '이름')) {
        $colMap['name'] = $c;
    } elseif (!isset($colMap['promo1']) && (str_contains($s, '프로모션1') || str_contains($s, '프로모션 1'))) {
        $colMap['promo1'] = $c;
    } elseif (!isset($colMap['promo2']) && (str_contains($s, '프로모션2') || str_contains($s, '프로모션 2'))) {
        $colMap['promo2'] = $c;
    }
}
if (!isset($colMap['rider_code']) || (!isset($colMap['promo1']) && !isset($colMap['promo2']))) {
    $err('프로모션1/프로모션2 컬럼을 찾을 수 없습니다. 템플릿 양식을 확인하세요.');
}

/** 금액 정규화 — "12,000", "12000원", 빈칸 등 처리 */
$toAmount = static function (mixed $v): int {
    if ($v === null || $v === '') {
        return 0;
    }
    $s = preg_replace('/[^0-9\-]/', '', (string) $v) ?? '';

    return $s === '' || $s === '-' ? 0 : (int) $s;
};

$rows = [];
foreach ($all as $i => $cols) {
    if ($i <= $headerIdx) {
        continue;
    }
    $code = trim((string) ($cols[$colMap['rider_code']] ?? ''));
    $name = isset($colMap['name']) ? trim((string) ($cols[$colMap['name']] ?? '')) : '';
    $p1   = isset($colMap['promo1']) ? $toAmount($cols[$colMap['promo1']] ?? null) : 0;
    $p2   = isset($colMap['promo2']) ? $toAmount($cols[$colMap['promo2']] ?? null) : 0;

    if ($code === '' && $name === '' && $p1 === 0 && $p2 === 0) {
        continue; // 빈 행
    }
    $rows[] = ['rider_code' => $code, 'name' => $name, 'promo1' => $p1, 'promo2' => $p2];
}

if ($rows === []) {
    $err('읽을 데이터가 없습니다.');
}

$preview = Promotion::preview($rows, $agencyId, $payDate);

if ($mode === 'preview') {
    echo json_encode([
        'ok'      => true,
        'preview' => true,
        'pay_date' => $payDate,
        'agency_id' => $agencyId,
        'summary' => [
            'total_rows'   => count($preview['rows']),
            'matched'      => $preview['matched'],
            'unmatched'    => $preview['unmatched'],
            'skipped'      => $preview['skipped'],
            'total_amount' => $preview['total_amount'],
            'dup_riders'   => $preview['dup_riders'],
        ],
        'rows'    => $preview['rows'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 확정: 배치 저장 후 즉시 지급 ──
try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $batchId = Promotion::createBatch($agencyId, $payDate, $preview['rows'], $origName, $memo, $adminId > 0 ? $adminId : null);
    $result  = Promotion::pay($batchId, $adminId > 0 ? $adminId : null);

    AuditLog::record(
        'promotion.pay',
        (string) $batchId,
        sprintf('%s 프로모션 지급 · %d명 %s원 (실패 %d명)',
            $payDate, $result['paid'], number_format($result['paid_amount']), $result['failed'])
    );

    echo json_encode([
        'ok'       => true,
        'batch_id' => $batchId,
        'paid'     => $result['paid'],
        'failed'   => $result['failed'],
        'paid_amount' => $result['paid_amount'],
        'fee_amount'  => $result['fee_amount'],
        'errors'   => $result['errors'],
        'message'  => sprintf('%d명에게 %s원 지급 완료%s',
            $result['paid'], number_format($result['paid_amount']),
            $result['failed'] > 0 ? " (실패 {$result['failed']}명)" : ''),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('지급 처리 실패: ' . $e->getMessage(), 500);
}

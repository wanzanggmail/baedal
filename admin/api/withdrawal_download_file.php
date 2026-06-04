<?php

declare(strict_types=1);

/**
 * 신한 BizBank 이체 파일 다운로드
 * POST JSON: { ids: [1,2], format: "xlsx"|"txt"|"csv", payout_date, include_header, mark_downloaded }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/ShinhanTransferFile.php';
require_once INC_PATH . '/download_response.php';
require_once INC_PATH . '/AuditLog.php';

ini_set('display_errors', '0');

if (ob_get_level() === 0) {
    ob_start();
}

if (!admin_is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'POST 만 허용'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_deny_write_json('withdrawal');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$ids = Withdrawal::normalizeIds((array) ($body['ids'] ?? []));
if ($ids === []) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '다운로드할 건을 선택하세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$format = strtolower(trim((string) ($body['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'txt', 'csv'], true)) {
    $format = 'xlsx';
}

$includeHeader = !isset($body['include_header']) || !empty($body['include_header']);
$markDownloaded = !isset($body['mark_downloaded']) || !empty($body['mark_downloaded']);
$payoutDate     = trim((string) ($body['payout_date'] ?? ''));

try {
    $withdrawals = Withdrawal::listByIds($ids, 'pending');
    if ($withdrawals === []) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => '출금 대기 상태인 건이 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $built = ShinhanTransferFile::buildDataRows($withdrawals);
    if ($built['rows'] === []) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => false,
            'message'=> '파일로 만들 수 있는 건이 없습니다.',
            'errors' => $built['errors'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $filename = ShinhanTransferFile::suggestFilename(
        $format === 'xlsx' ? 'xlsx' : ($format === 'txt' ? 'txt' : 'csv'),
        $payoutDate !== '' ? $payoutDate : null
    );

    $warnHeader = ['X-Baedal-Warnings' => base64_encode(json_encode($built['errors'], JSON_UNESCAPED_UNICODE))];

    if ($format === 'xlsx') {
        $binary = ShinhanTransferFile::toXlsxBinary($built['rows'], $includeHeader);
    } elseif ($format === 'txt') {
        $binary = "\xEF\xBB\xBF" . ShinhanTransferFile::toTabText($built['rows']);
    } else {
        $binary = ShinhanTransferFile::toCsvUtf8($built['rows'], $includeHeader);
    }

    if ($markDownloaded) {
        $updatedIds = array_map(static fn (array $w): int => (int) $w['db_id'], $withdrawals);
        Withdrawal::markDownloaded($updatedIds);
    }

    AuditLog::record(
        'withdrawal.export',
        $filename,
        count($withdrawals) . '건 · ' . strtoupper($format) . ' 다운로드'
    );

    if ($format === 'xlsx') {
        send_download_response(
            $binary,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $built['errors'] !== [] ? $warnHeader : []
        );
    }

    if ($format === 'txt') {
        send_download_response(
            $binary,
            $filename,
            'text/plain; charset=UTF-8',
            $built['errors'] !== [] ? $warnHeader : []
        );
    }

    send_download_response(
        $binary,
        $filename,
        'text/csv; charset=UTF-8',
        $built['errors'] !== [] ? $warnHeader : []
    );
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => '파일 생성 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

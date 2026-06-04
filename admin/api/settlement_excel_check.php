<?php

declare(strict_types=1);

/**
 * 정산 엑셀 복호화 환경 진단 (관리자)
 * GET /admin/api/settlement_excel_check.php
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/XlsxDecrypt.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('settlement/upload')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$diag = XlsxDecrypt::diagnostics();
$ok   = false;
foreach ($diag['python_binaries'] as $p) {
    if (!empty($p['msoffcrypto_ok'])) {
        $ok = true;
        break;
    }
}
if (!$ok && $diag['msoffcrypto_cli'] !== []) {
    $ok = true;
}

echo json_encode([
    'ok'       => $ok,
    'ready'    => $ok && $diag['exec_enabled'],
    'message'  => $ok
        ? '엑셀 암호 해제 가능한 Python 환경이 감지되었습니다.'
        : '웹(PHP)에서 msoffcrypto를 사용할 수 없습니다. 아래 diagnostics를 확인하세요.',
    'diagnostics' => $diag,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

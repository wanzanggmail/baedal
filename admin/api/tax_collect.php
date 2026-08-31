<?php

declare(strict_types=1);

/**
 * 세무대리 — 고용·산재 예수금 수집 API.
 * GET  — 대리점별 현황 + 세무대리 지갑 + 수집 이력
 * POST { action:'collect', period:'YYYY-MM', agency_id?:N } — 예수금 수집(대리점→세무대리 지갑)
 *
 * 권한: 세무대리(tax_agent 레벨) 계정만. (본사 super 도 점검용으로 허용)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/TaxAgent.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
$isTax = admin_org_level() === Org::LEVEL_TAX_AGENT;
if (!$isTax && !admin_has_role('super')) {
    $err('세무대리 계정만 사용할 수 있습니다.', 403);
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode([
        'ok'            => true,
        'agencies'      => TaxAgent::agencySummary(),
        'collectible'   => TaxAgent::collectibleTotal(),
        'wallet_balance' => TaxAgent::walletBalance(),
        'history'       => TaxAgent::history(50),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

if (trim((string) ($body['action'] ?? '')) !== 'collect') {
    $err('action=collect', 400);
}
$period   = trim((string) ($body['period'] ?? date('Y-m')));
$agencyId = isset($body['agency_id']) && (int) $body['agency_id'] > 0 ? (int) $body['agency_id'] : null;

try {
    $res = TaxAgent::collect($agencyId, $period, $adminId > 0 ? $adminId : null);
    if ($res['count'] < 1) {
        $err('수집할 예수금이 없습니다.', 422);
    }
    AuditLog::record(
        'tax.insurance_collect',
        $period,
        sprintf('고용·산재 예수금 수집 %d개 대리점 · %s원(%s)', $res['count'], number_format($res['total']), $period)
    );
    echo json_encode([
        'ok'      => true,
        'message' => sprintf('%d개 대리점에서 %s원을 수집했습니다.', $res['count'], number_format($res['total'])),
        'result'  => $res,
        'agencies' => TaxAgent::agencySummary(),
        'collectible' => TaxAgent::collectibleTotal(),
        'wallet_balance' => TaxAgent::walletBalance(),
        'history' => TaxAgent::history(50),
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('수집 실패: ' . $e->getMessage(), 500);
}

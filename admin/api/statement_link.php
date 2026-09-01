<?php

declare(strict_types=1);

/**
 * 모바일 명세서 공개 링크 생성 API — 발급 화면에서 관리자가 링크를 복사할 때(2026-09-01 갑).
 * POST { rider_id, from, to } → { ok, url }
 *
 * 권한: 로그인 + 그 라이더 명세서를 볼 수 있는 계정(본사, 또는 자기 라이더인 대리점)만.
 *       "명세서를 볼 수 있으면 링크도 만들 수 있다"는 규칙(정산명세서 발급과 동일 게이트).
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/StatementLink.php';
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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST만 허용합니다.', 405);
}

// 정산명세서 발급 화면과 동일한 접근 게이트(본사·대리점, 총판 제외).
$level = admin_org_level();
if ($level !== Org::LEVEL_ADMIN && $level !== Org::LEVEL_AGENCY) {
    $err('명세서 링크를 만들 권한이 없습니다.', 403);
}
if ($level === Org::LEVEL_AGENCY && !Org::statementFlag((int) admin_org_id(), 'stmt_weekly_enabled')) {
    $err('정산명세서 발급 기능이 꺼져 있습니다.', 403);
}
if (!StatementLink::ready()) {
    $err('statement_links 테이블이 없습니다. php migrate.php 를 실행하세요.', 500);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;

$riderId = (int) ($body['rider_id'] ?? 0);
$from    = trim((string) ($body['from'] ?? ''));
$to      = trim((string) ($body['to'] ?? ''));

if ($riderId < 1) {
    $err('라이더가 필요합니다.');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $err('정산기간(from·to)이 올바르지 않습니다.');
}

// 스코프: 대리점은 자기 라이더만.
$rider = db_row('SELECT agency_id, name FROM riders WHERE id = ? LIMIT 1', [$riderId]);
if ($rider === null) {
    $err('라이더를 찾을 수 없습니다.', 404);
}
if (!Org::canAccessAgency((int) ($rider['agency_id'] ?? 0))) {
    $err('이 라이더의 명세서에 접근할 권한이 없습니다.', 403);
}

try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $link    = StatementLink::create($riderId, $from, $to, $adminId > 0 ? $adminId : null);

    AuditLog::record('statement.link', (string) $riderId, sprintf('명세서 링크 생성 %s~%s', $from, $to));

    echo json_encode(['ok' => true, 'url' => $link['url']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $err('링크 생성 실패: ' . $e->getMessage(), 500);
}

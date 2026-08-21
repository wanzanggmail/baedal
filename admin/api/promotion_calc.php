<?php

declare(strict_types=1);

/**
 * 프로모션 계산기 API — 기간 + 건수 구간 룰로 라이더별 프로모션 금액을 계산한다.
 *
 * POST { agency_id?, from, to, tiers:[{from,to,amount},…] }
 *
 * 권한: 프로모션 조회 권한(`promotion` area). 대리점 계정은 자기 대리점 고정,
 *       본사·총판은 접근 가능한 대리점을 지정한다.
 *
 * ⚠️ 계산만 한다 — 저장·지급은 하지 않는다(2026-08-16 갑 확정: "계산·조회만").
 *    실제 지급은 결과를 엑셀로 받아 기존 「프로모션 지급」에 업로드하는 경로를 그대로 쓴다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/PromotionCalculator.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!admin_can_access_route('promotion')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '프로모션 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($ct, 'application/json')) {
    $decoded = json_decode($raw ?: '{}', true);
    if (!is_array($decoded)) {
        $err('요청 본문(JSON)을 읽을 수 없습니다: ' . json_last_error_msg(), 400);
    }
    $body = $decoded;
} else {
    $body = $_POST;
}

// 대상 대리점 — 대리점 계정은 자기 것 고정(남의 실적을 계산해 볼 수 없다).
if (admin_org_level() === Org::LEVEL_AGENCY) {
    $agencyId = admin_org_id();
} else {
    $agencyId = (int) ($body['agency_id'] ?? 0);
    if ($agencyId < 1) {
        $err('대리점을 선택하세요.');
    }
    if (!Org::canAccessAgency($agencyId)) {
        $err('이 대리점에 접근할 권한이 없습니다.', 403);
    }
}

$normDate = static function ($v): ?string {
    $v = trim((string) ($v ?? ''));

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
};

$from = $normDate($body['from'] ?? null);
$to   = $normDate($body['to'] ?? null);
if ($from === null || $to === null) {
    $err('기간(시작일·종료일)을 올바르게 입력하세요.');
}
if ($from > $to) {
    $err('종료일이 시작일보다 앞설 수 없습니다.');
}

try {
    $tiers  = PromotionCalculator::normalizeTiers((array) ($body['tiers'] ?? []));
    $result = PromotionCalculator::calculate($agencyId, $from, $to, $tiers);

    echo json_encode([
        'ok'      => true,
        'from'    => $from,
        'to'      => $to,
        'tiers'   => $tiers,
        'rule'    => PromotionCalculator::describeTiers($tiers),
        'rows'    => $result['rows'],
        'summary' => $result['summary'],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('계산 실패: ' . $e->getMessage(), 500);
}

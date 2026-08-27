<?php

declare(strict_types=1);

/**
 * 연동 모드 전환(모의 ↔ 실연동) — 본사 super 전용.
 *
 * 이 한 번의 호출로 **진짜 돈이 움직이기 시작**한다. 그래서
 *   - 권한을 본사 최고관리자로 좁히고,
 *   - 모든 전환을 감사로그에 남기며,
 *   - 실연동으로 켤 때는 화면에서 한 번 더 확인받는다.
 *
 * 검증은 각 Config 의 `save()` 가 한다 — 자격증명이 없으면 스스로 거절한다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/IntegrationMode.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}
if (!admin_has_role('super') || admin_org_level() !== Org::LEVEL_ADMIN) {
    $err('연동 모드 전환은 본사 최고관리자만 할 수 있습니다.', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $err('요청 본문을 해석할 수 없습니다.');
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$action  = trim((string) ($body['action'] ?? 'switch'));

try {
    if ($action === 'status') {
        echo json_encode(['ok' => true, 'status' => IntegrationMode::status()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'all_mock') {
        $r = IntegrationMode::allToMock($adminId);
        AuditLog::record('integration.all_mock', '-', '전체 연동을 모의로 전환' . ($r['errors'] ? ' (일부 실패)' : ''));

        echo json_encode([
            'ok'      => $r['ok'],
            'message' => $r['message'] . ($r['errors'] ? ' — ' . implode(' / ', $r['errors']) : ''),
            'status'  => IntegrationMode::status(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'switch') {
        $channel = trim((string) ($body['channel'] ?? ''));
        if (!in_array($channel, IntegrationMode::channels(), true)) {
            $err('연동 구분이 올바르지 않습니다.');
        }
        $live = (bool) ($body['live'] ?? false);

        $r = IntegrationMode::switchTo($channel, $live, $adminId);

        AuditLog::record(
            'integration.switch',
            $channel,
            sprintf('%s → %s%s', $channel, $live ? '실연동' : '모의', $r['live'] === $live ? '' : ' (자격증명 부족으로 모의 유지)')
        );

        // 요청한 모드와 실제 결과가 다르면 그 사실을 알린다 —
        // 드라이버만 바꾸고 자격증명이 없으면 조용히 모의로 도는데, 그걸 모르면 위험하다.
        $msg = $r['message'];
        if ($live && !$r['live']) {
            $msg = '실연동으로 바꿨지만 자격증명이 부족해 **모의로 동작**합니다. 설정 화면에서 값을 채우세요.';
        }

        echo json_encode([
            'ok'      => true,
            'live'    => $r['live'],
            'message' => $msg,
            'status'  => IntegrationMode::status(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.');
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('전환 실패: ' . $e->getMessage(), 500);
}

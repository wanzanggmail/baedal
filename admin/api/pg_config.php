<?php

declare(strict_types=1);

/**
 * PG 연동 설정 저장 — 본사 super 전용.
 *
 * 비밀값(pay_key·sign_key·api_key·enc_key·enc_iv·login_pw)은 **입력했을 때만** 보낸다.
 * 화면이 마스킹된 값을 보여주므로 빈 값을 보내면 지우려는 게 아니라 "안 건드림"이며,
 * `PgConfig::save()`가 그렇게 해석한다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/PgConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

$err = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!admin_is_logged_in()) {
    $err('인증이 필요합니다.', 401);
}

require_once INC_PATH . '/Org.php';
if (!admin_has_role('super') || admin_org_level() !== Org::LEVEL_ADMIN) {
    $err('본사 최고관리자만 변경할 수 있습니다.', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$raw  = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $err('요청 본문을 해석할 수 없습니다.');
}

try {
    $before = PgConfig::publicView();
    PgConfig::save($body, (int) ($_SESSION['admin_id'] ?? 0));
    $after = PgConfig::publicView();

    // 어떤 비밀값이 새로 채워졌는지만 남긴다 — 값 자체는 절대 로그에 쓰지 않는다.
    $filled = [];
    foreach (['has_pay_key' => '결제KEY', 'has_sign_key' => '서명KEY', 'has_api_key' => 'API KEY', 'has_enc_key' => '암호화KEY'] as $k => $label) {
        if (empty($before[$k]) && !empty($after[$k])) {
            $filled[] = $label . ' 설정';
        }
    }

    AuditLog::record(
        'pg.config.save',
        'pg_config',
        sprintf(
            'driver=%s · MID=%s · 허용IP=%s%s',
            (string) $after['driver'],
            (string) $after['mid'],
            (string) (PgConfig::get()['noti_allow_ips'] ?? ''),
            $filled !== [] ? ' · ' . implode(', ', $filled) : ''
        )
    );

    echo json_encode(['ok' => true, 'message' => '저장했습니다.', 'config' => $after], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('저장 실패: ' . $e->getMessage(), 500);
}

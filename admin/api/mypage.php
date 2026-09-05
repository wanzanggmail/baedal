<?php

declare(strict_types=1);

/**
 * 내 계정 — 본인 정보·비밀번호 변경 (2026-09-06 갑).
 *
 * POST { action: 'profile',  name, email }
 *      { action: 'password', current, new, confirm }
 *
 * **로그인한 본인 계정만** 대상이다. 남의 계정은 여기서 못 건드린다
 * (그건 시스템 > 관리자·권한 / 대표·서브계정 관리 쪽 일이다).
 * 그래서 역할 검사 없이 로그인만 확인한다 — 누구든 자기 비밀번호는 바꿀 수 있어야 한다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
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
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = $_POST;
}

$myId = (int) ($_SESSION['admin_id'] ?? 0);
$me   = $myId > 0 ? db_row('SELECT * FROM admins WHERE id = ? LIMIT 1', [$myId]) : null;
if ($me === null) {
    $err('계정을 찾을 수 없습니다.', 404);
}

$action = trim((string) ($body['action'] ?? ''));

// ── 비밀번호 변경 ─────────────────────────────────────────────────────────
if ($action === 'password') {
    $current = (string) ($body['current'] ?? '');
    $new     = (string) ($body['new'] ?? '');
    $confirm = (string) ($body['confirm'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $err('현재 비밀번호와 새 비밀번호를 모두 입력하세요.');
    }
    // 현재 비밀번호 확인 — 자리를 비운 사이 남이 바꾸는 걸 막는다.
    if (!password_verify($current, (string) $me['password_hash'])) {
        $err('현재 비밀번호가 맞지 않습니다.');
    }
    if ($new !== $confirm) {
        $err('새 비밀번호와 확인이 서로 다릅니다.');
    }
    if (strlen($new) < 8) {
        $err('새 비밀번호는 8자 이상이어야 합니다.');   // AdminAccount 와 같은 기준
    }
    if ($new === $current) {
        $err('현재 비밀번호와 다른 값으로 바꿔주세요.');
    }
    // 마이그레이션이 만드는 기본 비밀번호로는 되돌리지 못하게 막는다(알려진 값이라 위험).
    if (in_array($new, ['Admin1234!', '00000000', '12345678'], true)) {
        $err('너무 흔한 비밀번호입니다. 다른 값으로 정해주세요.');
    }

    db_execute(
        'UPDATE admins SET password_hash = ?, updated_at = NOW() WHERE id = ?',
        [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $myId]
    );
    // ⚠️ 비밀번호 자체는 절대 로그에 남기지 않는다 — 누가·언제 바꿨는지만 남긴다.
    AuditLog::record('admin.self_password', (string) $me['login_id'], '본인 비밀번호 변경');

    echo json_encode(['ok' => true, 'message' => '비밀번호를 변경했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 이름·이메일 변경 ──────────────────────────────────────────────────────
if ($action === 'profile') {
    $name  = trim((string) ($body['name'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));

    if ($name === '') {
        $err('이름을 입력하세요.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err('이메일 형식이 올바르지 않습니다.');
    }

    db_execute(
        'UPDATE admins SET name = ?, email = ?, updated_at = NOW() WHERE id = ?',
        [mb_substr($name, 0, 80), $email !== '' ? mb_substr($email, 0, 120) : null, $myId]
    );
    $_SESSION['admin_name'] = mb_substr($name, 0, 80);   // 헤더 표기를 바로 갱신
    AuditLog::record('admin.self_profile', (string) $me['login_id'], "이름 {$name}");

    echo json_encode(['ok' => true, 'message' => '내 정보를 저장했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err("action은 'password' 또는 'profile' 입니다.", 400);

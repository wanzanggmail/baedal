<?php

declare(strict_types=1);

/**
 * 조직 대표·서브계정 관리 API (조직 대표계정 전용) — LOGIC §7 #14
 * GET  — 내 조직 계정 목록
 * POST { action:'create', login_id, name, email, role, password }
 *      { action:'update', id, name, email, role, password? }
 *      { action:'set_active', id, active }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/OrgAccount.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!admin_can_manage_team()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '조직 대표계정만 사용할 수 있습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$orgId  = admin_org_id();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode(['ok' => true, 'rows' => OrgAccount::listForOrg($orgId)], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'create') {
        $acc = OrgAccount::create($orgId, $body);
        AuditLog::record('admin.create', (string) $acc['id'], sprintf('서브계정 생성 %s (%s)', (string) $acc['login_id'], (string) $acc['role_label']));
        echo json_encode(['ok' => true, 'message' => '계정이 생성되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'update') {
        $acc = OrgAccount::update($orgId, (int) ($body['id'] ?? 0), $body);
        AuditLog::record('admin.update', (string) $acc['id'], sprintf('서브계정 수정 %s (%s)', (string) $acc['login_id'], (string) $acc['role_label']));
        echo json_encode(['ok' => true, 'message' => '수정되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'set_active') {
        $acc = OrgAccount::setActive($orgId, (int) ($body['id'] ?? 0), !empty($body['active']));
        AuditLog::record('admin.update', (string) $acc['id'], $acc['active'] ? '서브계정 활성화' : '서브계정 비활성화');
        echo json_encode(['ok' => true, 'message' => '변경되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'update_org_info') {
        // orgId는 body가 아니라 현재 로그인 계정의 소속 조직(admin_org_id())으로 고정 —
        // 대표계정이 다른 조직 id를 넘겨 남의 조직 정보를 고치는 것을 원천 차단한다.
        $row = Organization::updateSelf($orgId, $body);
        AuditLog::record('org.update', (string) $orgId, '조직 정보 수정(자기서비스) · ' . (string) $row['name']);
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $err('알 수 없는 action 입니다.', 400);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

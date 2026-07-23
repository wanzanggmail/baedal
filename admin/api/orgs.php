<?php

declare(strict_types=1);

/**
 * 조직(총판/대리점) 관리 API
 * GET  — 관리 가능한 조직 목록
 * POST { "action": "save", ... }            — 생성(조직+계정) / 수정
 *      { "action": "toggle_active", id, active }
 *
 * 권한: 본사(admin) 레벨 + 운영/최고 역할만 (admin_can_manage_orgs) — 총판 생성권한 회수(2026-07)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_manage_orgs()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '조직을 관리할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // 상세 조회: ?detail=<orgId>
    $detailId = (int) ($_GET['detail'] ?? 0);
    if ($detailId > 0) {
        try {
            echo json_encode(['ok' => true, 'detail' => Organization::detail($detailId)], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $e) {
            $err($e->getMessage(), 404);
        } catch (Throwable $e) {
            $err('상세 조회 실패: ' . $e->getMessage(), 500);
        }
        exit;
    }

    // 코드 자동 추천: ?suggest_code=<level>
    $suggestLevel = trim((string) ($_GET['suggest_code'] ?? ''));
    if ($suggestLevel !== '') {
        if (!in_array($suggestLevel, [Org::LEVEL_DISTRIBUTOR, Org::LEVEL_AGENCY], true)) {
            $err('레벨이 올바르지 않습니다.', 400);
        }
        echo json_encode(['ok' => true, 'code' => Organization::suggestCode($suggestLevel)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $rows = Organization::listManageable();
        echo json_encode(['ok' => true, 'rows' => $rows, 'count' => count($rows)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('목록 조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$action = trim((string) ($body['action'] ?? 'save'));

try {
    if ($action === 'toggle_active') {
        $id = (int) ($body['id'] ?? 0);
        if ($id < 1) {
            $err('조직 ID가 없습니다.');
        }
        $active = filter_var($body['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $err('active 값이 올바르지 않습니다.');
        }
        $row = Organization::setActive($id, $active);
        AuditLog::record(
            $active ? 'org.activate' : 'org.deactivate',
            (string) $id,
            ($active ? '조직 활성화 · ' : '조직 비활성화 · ') . (string) $row['name']
        );
        echo json_encode(['ok' => true, 'message' => '상태가 변경되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 조직 소속 계정 관리 (본사가 특정 총판·대리점 계정 CRUD)
    if ($action === 'account_add') {
        $orgId = (int) ($body['org_id'] ?? 0);
        $acc   = Organization::addAccount($orgId, $body);
        AuditLog::record('admin.create', (string) $acc['id'], sprintf('조직#%d 계정 추가 · %s (%s)', $orgId, (string) $acc['login_id'], (string) $acc['role_label']));
        echo json_encode(['ok' => true, 'message' => '계정이 추가되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'account_update') {
        $orgId = (int) ($body['org_id'] ?? 0);
        $acc   = Organization::updateAccount($orgId, (int) ($body['account_id'] ?? 0), $body);
        AuditLog::record('admin.update', (string) $acc['id'], sprintf('조직#%d 계정 수정 · %s (%s)', $orgId, (string) $acc['login_id'], (string) $acc['role_label']));
        echo json_encode(['ok' => true, 'message' => '계정이 수정되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'account_toggle') {
        $orgId  = (int) ($body['org_id'] ?? 0);
        $active = filter_var($body['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            $err('active 값이 올바르지 않습니다.');
        }
        $acc = Organization::setAccountActive($orgId, (int) ($body['account_id'] ?? 0), $active);
        AuditLog::record('admin.update', (string) $acc['id'], sprintf('조직#%d 계정 %s · %s', $orgId, $active ? '활성화' : '비활성화', (string) $acc['login_id']));
        echo json_encode(['ok' => true, 'message' => '변경되었습니다.', 'account' => $acc], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action !== 'save') {
        $err('action=save 또는 toggle_active / account_*', 400);
    }

    $editId = (int) ($body['id'] ?? 0);
    if ($editId > 0) {
        $row = Organization::update($editId, $body);
        AuditLog::record('org.update', (string) $editId, '조직 수정 · ' . (string) $row['name']);
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = Organization::create($body);
    AuditLog::record(
        'org.create',
        (string) $row['id'],
        '조직 생성 · ' . (string) $row['level_label'] . ' · ' . (string) $row['name'] . ' (' . (string) $row['primary_login'] . ')'
    );
    echo json_encode(['ok' => true, 'message' => '조직과 계정이 생성되었습니다.', 'row' => $row], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage());
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

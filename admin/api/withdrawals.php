<?php

declare(strict_types=1);

/**
 * 출금 신청 API
 * GET  ?action=list&status=&kind=&from=&to=&q=
 * POST { "action": "complete"|"complete_bulk"|"mark_downloaded"|"reject", "ids": [...] }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = trim((string) ($_GET['action'] ?? 'list'));

    if ($action === 'summary') {
        try {
            echo json_encode(['ok' => true, 'summary' => Withdrawal::summary()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            $err('조회 실패: ' . $e->getMessage(), 500);
        }
        exit;
    }

    if ($action !== 'list') {
        $err('action=list 또는 summary', 400);
    }

    try {
        $rows = Withdrawal::list([
            'status' => $_GET['status'] ?? '',
            'kind'   => $_GET['kind'] ?? '',
            'from'   => $_GET['from'] ?? '',
            'to'     => $_GET['to'] ?? '',
            'q'      => $_GET['q'] ?? '',
            'limit'  => $_GET['limit'] ?? 300,
        ]);
        echo json_encode([
            'ok'    => true,
            'rows'  => $rows,
            'count' => count($rows),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('목록 조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method === 'POST') {
    admin_deny_write_json('withdrawal');
    $raw  = file_get_contents('php://input');
    $ct   = $_SERVER['CONTENT_TYPE'] ?? '';
    $body = str_contains($ct, 'application/json')
        ? (array) json_decode($raw ?: '{}', true)
        : $_POST;

    $action = trim((string) ($body['action'] ?? ''));
    $ids    = Withdrawal::normalizeIds((array) ($body['ids'] ?? []));

    if ($ids === []) {
        $err('처리할 출금 건을 선택하세요.');
    }

    // 멀티테넌시: 스코프 밖 출금 건 제거
    $ids = Withdrawal::scopeFilterIds($ids);
    if ($ids === []) {
        $err('처리 권한이 있는 출금 건이 없습니다.', 403);
    }

    try {
        if ($action === 'mark_downloaded') {
            $n = Withdrawal::markDownloaded($ids);
            if ($n === 0) {
                $err('다운로드 완료 처리할 대기 건이 없습니다.');
            }
            AuditLog::record('withdrawal.mark_downloaded', implode(',', $ids), "{$n}건 다운로드 완료 처리");
            echo json_encode([
                'ok'      => true,
                'updated' => $n,
                'message' => "{$n}건을 다운로드 완료로 변경했습니다.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'complete' || $action === 'complete_bulk') {
            $n = Withdrawal::markCompleted($ids);
            if ($n === 0) {
                $err('입금 완료 처리할 수 있는 건이 없습니다. (다운로드 완료 상태만 가능)');
            }
            AuditLog::record('withdrawal.complete', implode(',', $ids), "{$n}건 출금 처리 완료");
            echo json_encode([
                'ok'      => true,
                'updated' => $n,
                'message' => "{$n}건을 처리 완료했습니다.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'reject') {
            $reason = trim((string) ($body['reason'] ?? ''));
            $n      = Withdrawal::markRejected($ids, $reason);
            if ($n === 0) {
                $err('반려할 수 있는 건이 없습니다.');
            }
            AuditLog::record('withdrawal.reject', implode(',', $ids), "{$n}건 반려" . ($reason !== '' ? " · {$reason}" : ''));
            echo json_encode([
                'ok'      => true,
                'updated' => $n,
                'message' => "{$n}건을 반려했습니다.",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $err('알 수 없는 action 입니다.');
    } catch (Throwable $e) {
        $err('처리 실패: ' . $e->getMessage(), 500);
    }
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);

<?php

declare(strict_types=1);

/**
 * 문자·알림톡 큐 API (본사 super 전용)
 * GET  — 큐 목록 + 상태별 건수
 * POST { action:'enqueue', channel, recipient_phone, recipient_name?, title?, content, scheduled_at? }
 *      { action:'send', id }         — 한 건 발송
 *      { action:'send_all' }         — 대기 전체 발송
 *      { action:'cancel', id }       — 취소
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/MessageQueue.php';
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
if (!admin_has_role('super')) {
    $err('문자·알림톡은 본사 최고관리자만 관리할 수 있습니다.', 403);
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$payload = static function (): array {
    $counts = MessageQueue::counts();

    return [
        'counts' => $counts,
        'rows'   => array_map(static function (array $r): array {
            return [
                'id'              => (int) $r['id'],
                'channel'         => (string) $r['channel'],
                'channel_label'   => MessageQueue::channelLabel((string) $r['channel']),
                'recipient_name'  => (string) ($r['recipient_name'] ?? ''),
                'recipient_phone' => (string) $r['recipient_phone'],
                'title'           => (string) ($r['title'] ?? ''),
                'content'         => (string) $r['content'],
                'status'          => (string) $r['status'],
                'status_label'    => MessageQueue::statusLabel((string) $r['status']),
                'provider_ref'    => (string) ($r['provider_ref'] ?? ''),
                'error'           => (string) ($r['error'] ?? ''),
                'scheduled_at'    => (string) ($r['scheduled_at'] ?? ''),
                'sent_at'         => (string) ($r['sent_at'] ?? ''),
                'created_at'      => (string) $r['created_at'],
            ];
        }, MessageQueue::listQueue($_GET ?? [], 200)),
    ];
};

if ($method === 'GET') {
    echo json_encode(['ok' => true] + $payload(), JSON_UNESCAPED_UNICODE);
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
    if ($action === 'enqueue') {
        $id = MessageQueue::enqueue($body, $adminId > 0 ? $adminId : null);
        AuditLog::record('message.enqueue', (string) $id, sprintf('%s 큐 적재 → %s', MessageQueue::channelLabel((string) ($body['channel'] ?? 'sms')), (string) ($body['recipient_phone'] ?? '')));
        echo json_encode(['ok' => true, 'message' => '큐에 추가했습니다.', 'id' => $id] + $payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'send') {
        $res = MessageQueue::send((int) ($body['id'] ?? 0), $adminId > 0 ? $adminId : null);
        if (!$res['ok']) {
            $err('발송 실패: ' . ($res['error'] ?? ''), 502);
        }
        AuditLog::record('message.send', (string) $res['id'], '메시지 발송(mock) · ' . ($res['ref'] ?? ''));
        echo json_encode(['ok' => true, 'message' => '발송했습니다. (모의 연동)'] + $payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'send_all') {
        $res = MessageQueue::processQueued(200, $adminId > 0 ? $adminId : null);
        AuditLog::record('message.send_all', '', sprintf('대기 일괄 발송 성공 %d · 실패 %d', $res['sent'], $res['failed']));
        echo json_encode(['ok' => true, 'message' => sprintf('발송 %d건 · 실패 %d건 (모의 연동)', $res['sent'], $res['failed'])] + $payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'cancel') {
        MessageQueue::cancel((int) ($body['id'] ?? 0));
        echo json_encode(['ok' => true, 'message' => '취소했습니다.'] + $payload(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $err('알 수 없는 action 입니다.', 400);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

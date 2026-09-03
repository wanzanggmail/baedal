<?php

declare(strict_types=1);

/**
 * 알림톡 템플릿 관리 API (본사 super 전용) — 2026-09-03.
 * POST { action:'save', event_key, name, template_code, title, content, channel_policy, is_active }
 *      { action:'seed' }            — 기본 템플릿 생성(없는 상황만)
 *      { action:'preview', event_key, content }  — 치환변수 미리보기
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AlimtalkTemplate.php';
require_once INC_PATH . '/MessagingConfig.php';
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
    $err('알림톡 템플릿은 본사 최고관리자만 관리할 수 있습니다.', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST만 허용합니다.', 405);
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0) ?: null;
$body    = (array) json_decode(file_get_contents('php://input') ?: '{}', true);
$action  = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'save') {
        $id = AlimtalkTemplate::save($body, $adminId);
        AuditLog::record('alimtalk.template', (string) $id, '템플릿 저장 · ' . (string) ($body['event_key'] ?? ''));
        echo json_encode(['ok' => true, 'message' => '저장했습니다.', 'rows' => AlimtalkTemplate::all()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'seed') {
        $n = AlimtalkTemplate::seedDefaults($adminId);
        AuditLog::record('alimtalk.template', '', "기본 템플릿 {$n}건 생성");
        echo json_encode(['ok' => true, 'message' => "기본 템플릿 {$n}건을 만들었습니다.", 'rows' => AlimtalkTemplate::all()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'preview') {
        $content = (string) ($body['content'] ?? '');
        $vars    = AlimtalkTemplate::extractVars($content);
        // 미리보기용 더미 값
        $sample = [];
        foreach ($vars as $v) {
            $sample[$v] = match ($v) {
                'name'   => '홍길동',
                'period' => date('Y-m-d'),
                'orders' => '66',
                'amount' => '299,485원',
                'link'   => rtrim(MessagingConfig::publicBaseUrl() ?: 'https://oxpay.kr', '/') . '/rider/p/statement.php?t=…',
                'bank'   => '신한 110-***-1234',
                'date'   => date('Y-m-d H:i'),
                'reason' => '예금주 불일치',
                'message' => '내일 정산 일정 안내드립니다.',
                default  => '(' . $v . ')',
            };
        }
        $rendered = AlimtalkTemplate::render($content, $sample);
        $bytes    = MessagingConfig::smsByteLength($rendered);
        echo json_encode([
            'ok'        => true,
            'vars'      => $vars,
            'rendered'  => $rendered,
            'bytes'     => $bytes,
            'sms_channel' => MessagingConfig::smsChannelFor($rendered),
            'price_alimtalk' => MessagingConfig::priceFor('alimtalk'),
            'price_sms'      => MessagingConfig::priceFor(MessagingConfig::smsChannelFor($rendered)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.', 400);
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

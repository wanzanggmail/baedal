<?php

declare(strict_types=1);

/**
 * 관리자 패널에서 실행하는 배포(production 전용, 슈퍼관리자만) — 2026-09-02.
 * POST { action: 'check' }  — origin/production 최신 상태 확인(반영은 안 함)
 * POST { action: 'deploy' } — 실제 배포(fetch → reset --hard → composer install)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Deployer.php';
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
    $err('배포는 최고관리자만 실행할 수 있습니다.', 403);
}
if (!Deployer::ready()) {
    $err('이 서버는 git 배포 대상이 아닙니다(rsync 배포 서버).', 409);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST만 허용합니다.', 405);
}

$body   = (array) json_decode(file_get_contents('php://input') ?: '{}', true);
$action = trim((string) ($body['action'] ?? ''));

if ($action === 'check') {
    $res = Deployer::check();
    AuditLog::record('deploy.check', '', ($res['ok'] ? "확인됨 · 대기 {$res['ahead']}건" : '확인 실패'));
    echo json_encode(['ok' => true] + $res + ['current' => Deployer::currentCommit()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'deploy') {
    $before = Deployer::currentCommit();
    $res    = Deployer::deploy();
    $after  = Deployer::currentCommit();
    AuditLog::record(
        'deploy.run',
        (string) $after['short'],
        sprintf('%s → %s (%s)', $before['short'], $after['short'], $res['ok'] ? '성공' : '실패')
    );
    // 릴리즈 노트 — 이 배포에 실제로 들어간 커밋을 그대로 남긴다(장애 때 되짚기용).
    Deployer::record(
        'deploy',
        $res['ok'],
        (string) $before['hash'],
        (string) $after['hash'],
        Deployer::commitsBetween((string) $before['hash'], (string) $after['hash']),
        $res['ok']
            ? sprintf('%s → %s', $before['short'], $after['short'])
            : '배포 실패 · ' . mb_substr(trim((string) $res['output']), -300)
    );
    echo json_encode(['ok' => $res['ok'], 'output' => $res['output'], 'current' => $after], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'migrate') {
    $res = Deployer::migrate();
    AuditLog::record('deploy.migrate', '', 'DB 마이그레이션 ' . ($res['ok'] ? '성공' : '실패'));
    // 마이그레이션도 같은 줄에 남겨야 «배포는 했는데 스키마는 안 올린» 상태가 눈에 띈다.
    $applied = [];
    foreach (explode("\n", (string) $res['output']) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'OK')) {
            $applied[] = trim((string) preg_replace('/\s+/', ' ', substr($line, 2)));
        }
    }
    Deployer::record(
        'migrate',
        $res['ok'],
        '',
        (string) Deployer::currentCommit()['hash'],
        [],
        $res['ok']
            ? ($applied === [] ? '변경 없음(전부 SKIP)' : '적용 ' . count($applied) . '건 · ' . implode(' · ', array_slice($applied, 0, 8)))
            : '마이그레이션 실패 · ' . mb_substr(trim((string) $res['output']), -300)
    );
    echo json_encode(['ok' => $res['ok'], 'output' => $res['output']], JSON_UNESCAPED_UNICODE);
    exit;
}

$err('알 수 없는 action 입니다.', 400);

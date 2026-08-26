<?php

declare(strict_types=1);

/**
 * 펌뱅킹(바움P&S) 연동 설정 저장 · 연결 테스트 — 본사 super 전용.
 *
 * 비밀값(secret_key·enc_key·enc_iv)은 **입력했을 때만** 보낸다. 화면이 마스킹된 값을
 * 보여주므로 빈 값은 "지움" 이 아니라 "안 건드림" 이며 `FirmConfig::save()` 가 그렇게 해석한다.
 *
 * 여기는 **돈이 나가는 통로의 설정**이다. PG 설정보다 권한을 더 좁힐 수는 없어 동일하게
 * 본사 super 로 두되, 변경은 전부 감사로그에 남긴다.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/auth.php';
require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/FirmConfig.php';
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
if (!admin_has_role('super') || admin_org_level() !== Org::LEVEL_ADMIN) {
    $err('본사 최고관리자만 변경할 수 있습니다.', 403);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $err('POST 만 허용합니다.', 405);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $err('요청 본문을 해석할 수 없습니다.');
}

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$action  = trim((string) ($body['action'] ?? 'save'));

try {
    if ($action === 'save') {
        FirmConfig::save($body, $adminId);
        // 자격증명이 바뀌면 캐시된 토큰은 더 이상 유효하지 않다.
        FirmConfig::clearAccessToken();
        AuditLog::record('firm.config_save', '1', '펌뱅킹 연동 설정 변경 (driver=' . (string) ($body['driver'] ?? '') . ')');

        echo json_encode(['ok' => true, 'message' => '저장했습니다.', 'config' => FirmConfig::publicView()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'test') {
        if (!FirmConfig::isReady()) {
            $err('실 연동에 필요한 값이 아직 없습니다. Client ID·Secret Key·암호화 KEY/IV 를 먼저 저장하세요.', 422);
        }

        require_once INC_PATH . '/BaumFirmGateway.php';
        $gw = new BaumFirmGateway();

        // ① 토큰 발급 → ② 잔액 조회. 둘 다 되면 인증·암호화가 모두 정상이라는 뜻이다.
        //    (잔액 조회는 **돈을 움직이지 않는** 읽기 전용이라 연결 확인에 안전하다.)
        $gw->accessToken(true);
        $bal = $gw->balance();

        if (!$bal['ok']) {
            $err('토큰은 발급됐으나 잔액 조회에 실패했습니다: ' . ($bal['message'] !== '' ? $bal['message'] : '응답을 해석하지 못했습니다.'), 422);
        }

        AuditLog::record('firm.config_test', '1', '펌뱅킹 연결 테스트 성공');

        echo json_encode([
            'ok'      => true,
            'message' => sprintf(
                '연결 성공 — 잔액 %s원 (출금예정 %s원) · 포켓 %d개',
                number_format($bal['total']),
                number_format($bal['pending']),
                count($bal['pockets'])
            ),
            'balance' => $bal,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'account_holder') {
        if (!FirmConfig::isReady()) {
            $err('실 연동 설정이 필요합니다.', 422);
        }
        $bank = trim((string) ($body['bank_code'] ?? ''));
        $acct = trim((string) ($body['account_no'] ?? ''));
        if ($bank === '' || $acct === '') {
            $err('은행과 계좌번호를 입력하세요.', 422);
        }

        require_once INC_PATH . '/BaumFirmGateway.php';
        $res = (new BaumFirmGateway())->accountHolder($bank, $acct);

        echo json_encode([
            'ok'      => $res['ok'],
            'holder'  => $res['holder'],
            'message' => $res['ok'] ? ('예금주: ' . $res['holder']) : ($res['message'] !== '' ? $res['message'] : '예금주를 확인하지 못했습니다.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reconcile') {
        // 웹훅이 못 온 건을 우리가 물어본다. 접수 직후 건은 건드리지 않는다(웹훅이 올 시간을 준다).
        require_once INC_PATH . '/FirmReconciler.php';
        $minAge = max(0, min(1440, (int) ($body['min_age'] ?? 5)));
        $r      = FirmReconciler::run($minAge);

        AuditLog::record('firm.reconcile', '1', sprintf('펌뱅킹 보정 조회 — 확인 %d건 / 확정 %d건', $r['checked'], $r['finalized']));

        echo json_encode([
            'ok'      => true,
            'message' => sprintf(
                '조회 %d건 · 확정 %d건 · 진행중 %d건 · 오류 %d건',
                $r['checked'], $r['finalized'], $r['still_pending'], $r['errors']
            ),
            'result'  => $r,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.');
} catch (InvalidArgumentException $e) {
    $err($e->getMessage(), 422);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

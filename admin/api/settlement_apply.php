<?php

declare(strict_types=1);

/**
 * 정산 반영 API — 업로드 → 라이더별 수수료·지갑 적립
 * POST { "upload_id": n }
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST만 허용'], JSON_UNESCAPED_UNICODE);
    exit;
}

admin_deny_write_json('settlement');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$uploadId = (int) ($body['upload_id'] ?? 0);
if ($uploadId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'upload_id가 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 멀티테넌시: 업로드 소유 대리점 스코프 밖이면 차단
require_once INC_PATH . '/Org.php';
$uploadRow = db_row('SELECT agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [$uploadId]);
if ($uploadRow === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => '업로드를 찾을 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!Org::canAccessAgency((int) ($uploadRow['agency_id'] ?? 0))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '이 업로드에 접근할 권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    $result  = SettlementLedger::applyUpload($uploadId, $adminId > 0 ? $adminId : null);

    if ($result['applied'] === 0 && $result['errors'] !== []) {
        throw new InvalidArgumentException(implode(' / ', $result['errors']));
    }

    AuditLog::record(
        'settlement.apply',
        (string) $uploadId,
        "정산 반영 {$result['applied']}명 · 건너뜀 {$result['skipped']}명"
    );

    // ── 자금 조달(PG 카드결제) — 플랫폼 수수료가 여기서 발생한다 ──
    // 정산이 반영되면 대리점은 라이더에게 줄 돈을 카드로 조달하고, 그 청구액에 플랫폼
    // 수수료(본사·총판·대리점 몫)가 붙는다. 즉 **플랫폼 수수료는 정산 반영 시점에 발생**하므로
    // 여기서 실행해야 「플랫폼 수수료 내역」 화면에 곧바로 잡힌다.
    // ⚠️ 순서: 정산 반영 → PG 조달(대리점 지갑 충전) → 라이더 출금. 돈이 대리점→라이더로
    //    흐르므로 조달이 출금보다 먼저여야 한다.
    require_once INC_PATH . '/PgPayment.php';
    $fund = PgPayment::fundAppliedUpload($uploadId, (int) ($uploadRow['agency_id'] ?? 0), $adminId > 0 ? $adminId : null);

    if ($fund['charged'] > 0) {
        AuditLog::record(
            'settlement.pg_fund',
            (string) $uploadId,
            sprintf(
                'PG 자금조달 %d건 · 조달 %s원 · 플랫폼수수료 %s원 · 실패 %d건',
                $fund['charged'],
                number_format($fund['funded']),
                number_format($fund['fee']),
                count($fund['failed'])
            )
        );
    }

    // 일일정산(선정산) 라이더는 반영 직후 곧바로 출금까지 실행한다(보증금은 자동 제외).
    // ⚠️ applyUpload()의 트랜잭션이 커밋된 뒤여야 지갑 잔액이 확정돼 출금액이 맞게 계산된다.
    // 대상은 "이번에 새로 반영된 사람"이 아니라 "미출금 정산분이 남은 사람" — 계좌를 뒤늦게
    // 등록하고 재반영했을 때 자동출금이 재시도돼야 하기 때문이다(runForUpload 주석 참고).
    require_once INC_PATH . '/DailyAutoWithdrawal.php';
    $auto = DailyAutoWithdrawal::runForUpload($uploadId);

    // 이 업로드에 일일정산 라이더가 아예 없는 경우와, 있는데 이미 다 지급된 경우를 구분해
    // 안내하기 위한 값(전자는 자동출금 얘기를 꺼낼 필요가 없다).
    $dailyTotal = (int) (db_row(
        'SELECT COUNT(DISTINCT dr.rider_id) AS cnt
           FROM settlement_daily_riders dr
           INNER JOIN riders r ON r.id = dr.rider_id
          WHERE dr.upload_id = ? AND r.is_daily_settlement = 1',
        [$uploadId]
    )['cnt'] ?? 0);

    if ($auto['targets'] > 0) {
        AuditLog::record(
            'settlement.auto_withdraw',
            (string) $uploadId,
            sprintf(
                '일일정산 자동출금 대상 %d명 · 지급 %d명(%s원) · 실패 %d명 · 건너뜀 %d명',
                $auto['targets'],
                $auto['paid'],
                number_format($auto['paid_amount']),
                $auto['failed'],
                $auto['skipped']
            )
        );
    }

    // 일정산 명세서 알림톡 자동 발송 — 대리점 설정(stmt_daily_alimtalk)이 켜져 있을 때만 큐 적재.
    require_once INC_PATH . '/RiderStatement.php';
    $stmt = RiderStatement::enqueueDailyStatements($uploadId, (int) ($uploadRow['agency_id'] ?? 0), $adminId > 0 ? $adminId : null);
    if ($stmt['queued'] > 0) {
        AuditLog::record(
            'settlement.statement_alimtalk',
            (string) $uploadId,
            sprintf('일정산 명세서 알림톡 큐 적재 %d건 · 실패 %d건', $stmt['queued'], $stmt['skipped'])
        );
    }

    $message = "정산 반영 {$result['applied']}명 완료" . ($result['skipped'] > 0 ? " (건너뜀 {$result['skipped']}명)" : '');
    if ($result['applied'] === 0) {
        $message .= "\n(이미 반영된 건은 다시 반영되지 않습니다)";
    }

    // 자금 조달(플랫폼 수수료 발생) 결과
    if ($fund['skipped_reason'] !== '') {
        $message .= "\n\n자금 조달(플랫폼 수수료): " . $fund['skipped_reason'];
    } elseif ($fund['charged'] > 0) {
        $message .= sprintf(
            "\n\n자금 조달 %d건: %s원 충전 · 플랫폼 수수료 %s원 발생",
            $fund['charged'],
            number_format($fund['funded']),
            number_format($fund['fee'])
        );
        if ($fund['failed'] !== []) {
            $message .= ' · 실패 ' . count($fund['failed']) . '건';
        }
    }

    // 반영이 0건이어도 자동출금은 별도로 시도되므로 결과를 항상 보여준다.
    // — 계좌를 뒤늦게 채우고 재반영한 관리자가 "아무 일도 안 일어났다"고 오해하지 않도록.
    if ($auto['targets'] > 0) {
        $message .= sprintf(
            "\n\n일일정산 자동출금 (대상 %d명): 지급 %d명 (%s원)",
            $auto['targets'],
            $auto['paid'],
            number_format($auto['paid_amount'])
        );
        if ($auto['failed'] > 0) {
            $message .= " · 실패 {$auto['failed']}명";
        }
        if ($auto['skipped'] > 0) {
            $message .= " · 미지급 {$auto['skipped']}명";
        }
    } elseif ($dailyTotal > 0) {
        $message .= "\n\n일일정산 자동출금: 출금할 정산분이 남은 대상이 없습니다(이미 전부 지급됨).";
    }

    if ($stmt['queued'] > 0) {
        $message .= sprintf("\n\n일정산 명세서 알림톡: %d건 발송 대기(큐)", $stmt['queued']);
        if ($stmt['skipped'] > 0) {
            $message .= " · 실패 {$stmt['skipped']}건";
        }
    }

    echo json_encode([
        'ok'        => true,
        'message'   => $message,
        'result'    => $result,
        'pg_fund'   => $fund,
        'auto_withdraw' => $auto,
        'statement_alimtalk' => $stmt,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => '처리 실패: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

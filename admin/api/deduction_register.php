<?php

declare(strict_types=1);

/**
 * 업로드된 차감내역(settlement_weekly_deductions) → deduction_entries 등록
 * GET  ?upload_id=N        — 해당 업로드의 차감내역 목록(매칭·등록상태 포함)
 * POST { action:'register',   id }  — deduction_entries 생성(rider_id 매칭된 행만 가능)
 * POST { action:'unregister', id }  — 등록 취소(생성된 deduction_entries 삭제)
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/Org.php';
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

/** 업로드 소유 대리점 스코프 확인 */
$assertUploadAccess = static function (int $uploadId) use ($err): array {
    $u = db_row('SELECT id, agency_id FROM settlement_uploads WHERE id = ? LIMIT 1', [$uploadId]);
    if ($u === null) {
        $err('업로드를 찾을 수 없습니다.', 404);
    }
    if (!Org::canAccessAgency((int) ($u['agency_id'] ?? 0))) {
        $err('이 업로드에 접근할 권한이 없습니다.', 403);
    }

    return $u;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $uploadId = (int) ($_GET['upload_id'] ?? 0);
    if ($uploadId < 1) {
        $err('upload_id가 필요합니다.', 400);
    }
    $assertUploadAccess($uploadId);

    $rows = db_rows(
        'SELECT swd.id, swd.order_date, swd.order_no, swd.rider_id, swd.rider_name_raw,
                swd.deduction_type, swd.store_name, swd.amount, swd.registered_entry_id,
                r.name AS rider_name, r.rider_code
           FROM settlement_weekly_deductions swd
           LEFT JOIN riders r ON r.id = swd.rider_id
          WHERE swd.upload_id = ?
          ORDER BY swd.order_date ASC, swd.id ASC',
        [$uploadId]
    );

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

if (!admin_can_write('deduction')) {
    $err('차감정보를 등록할 권한이 없습니다.', 403);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? ''));

try {
    if ($action === 'register') {
        $id  = (int) ($body['id'] ?? 0);
        $ded = db_row(
            'SELECT swd.*, u.agency_id AS upload_agency_id
               FROM settlement_weekly_deductions swd
               INNER JOIN settlement_uploads u ON u.id = swd.upload_id
              WHERE swd.id = ? LIMIT 1',
            [$id]
        );
        if ($ded === null) {
            $err('차감내역을 찾을 수 없습니다.', 404);
        }
        if (!Org::canAccessAgency((int) ($ded['upload_agency_id'] ?? 0))) {
            $err('접근 권한이 없습니다.', 403);
        }
        if ((int) ($ded['registered_entry_id'] ?? 0) > 0) {
            $err('이미 등록된 차감내역입니다.');
        }
        $riderId = (int) ($ded['rider_id'] ?? 0);
        if ($riderId < 1) {
            $err('라이더가 매칭되지 않아 등록할 수 없습니다. (라이더 관리에서 이름을 확인하세요)');
        }
        $appliedDate = (string) ($ded['order_date'] ?? '');
        if ($appliedDate === '') {
            $err('주문일자가 없어 등록할 수 없습니다.');
        }
        $amount = abs((int) ($ded['amount'] ?? 0));
        if ($amount <= 0) {
            $err('차감 금액이 0원이라 등록할 필요가 없습니다.');
        }
        $type = trim((string) ($ded['deduction_type'] ?? ''));
        $kind = $type !== '' ? mb_substr($type, 0, 40) : 'manual';
        $note = trim($type . ' · ' . (string) ($ded['store_name'] ?? ''), ' ·');

        $entryId = db_transaction(static function () use ($id, $riderId, $appliedDate, $kind, $amount, $note): int {
            $eid = db_insert(
                'INSERT INTO deduction_entries (rider_id, applied_date, kind, amount, note)
                 VALUES (?, ?, ?, ?, ?)',
                [$riderId, $appliedDate, $kind, $amount, $note]
            );
            db_execute('UPDATE settlement_weekly_deductions SET registered_entry_id = ? WHERE id = ?', [$eid, $id]);

            return $eid;
        });

        AuditLog::record('deduction.register', (string) $riderId, sprintf('업로드 차감내역 등록 · %s원 (%s)', number_format($amount), $kind));
        echo json_encode(['ok' => true, 'message' => '차감정보로 등록되었습니다.', 'entry_id' => $entryId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'unregister') {
        $id  = (int) ($body['id'] ?? 0);
        $ded = db_row(
            'SELECT swd.registered_entry_id, u.agency_id AS upload_agency_id
               FROM settlement_weekly_deductions swd
               INNER JOIN settlement_uploads u ON u.id = swd.upload_id
              WHERE swd.id = ? LIMIT 1',
            [$id]
        );
        if ($ded === null) {
            $err('차감내역을 찾을 수 없습니다.', 404);
        }
        if (!Org::canAccessAgency((int) ($ded['upload_agency_id'] ?? 0))) {
            $err('접근 권한이 없습니다.', 403);
        }
        $entryId = (int) ($ded['registered_entry_id'] ?? 0);
        if ($entryId < 1) {
            $err('등록된 내역이 없습니다.');
        }

        db_transaction(static function () use ($id, $entryId): void {
            db_execute('DELETE FROM deduction_entries WHERE id = ?', [$entryId]);
            db_execute('UPDATE settlement_weekly_deductions SET registered_entry_id = NULL WHERE id = ?', [$id]);
        });

        AuditLog::record('deduction.register', (string) $id, '업로드 차감내역 등록 취소');
        echo json_encode(['ok' => true, 'message' => '등록이 취소되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.', 400);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

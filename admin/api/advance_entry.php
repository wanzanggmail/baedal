<?php

declare(strict_types=1);

/**
 * 선지급(대여금) 입력 API — deduction_entries.kind = advance (LOGIC §5.3)
 * GET  ?rider_id=N — 해당 라이더의 선지급 내역 / (없으면 최근 스코프 내 목록)
 * POST { action:'create', rider_id, amount, applied_date, note }
 * POST { action:'delete', id }
 *
 * 정산 반영 시 SettlementLedger가 해당 날짜의 advance를 차감 항목으로 반영한다.
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

$assertRider = static function (int $riderId) use ($err): array {
    $r = db_row('SELECT id, name, rider_code, agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
    if ($r === null) {
        $err('라이더를 찾을 수 없습니다.', 404);
    }
    if (!Org::canAccessAgency((int) $r['agency_id'])) {
        $err('이 라이더에 접근할 권한이 없습니다.', 403);
    }

    return $r;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $riderId = (int) ($_GET['rider_id'] ?? 0);
    $where  = ["de.kind = 'advance'"];
    $params = [];
    if ($riderId > 0) {
        $assertRider($riderId);
        $where[]  = 'de.rider_id = ?';
        $params[] = $riderId;
    } else {
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
        if ($scopeSql !== '') {
            $where[] = $scopeSql;
            $params  = array_merge($params, $scopeParams);
        }
    }
    $whereStr = implode(' AND ', $where);
    $rows = db_rows(
        "SELECT de.id, de.rider_id, de.applied_date, de.amount, de.note, de.created_at,
                r.name AS rider_name, r.rider_code
           FROM deduction_entries de
           INNER JOIN riders r ON r.id = de.rider_id
          WHERE {$whereStr}
          ORDER BY de.applied_date DESC, de.id DESC
          LIMIT 200",
        $params
    );
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

// 쓰기 권한: 정산 역할(대리점 settlement/operation 또는 본사 super)
if (!admin_can_write('deduction')) {
    $err('선지급을 입력할 권한이 없습니다.', 403);
}

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json') ? (array) json_decode($raw ?: '{}', true) : $_POST;
$action = trim((string) ($body['action'] ?? 'create'));

try {
    if ($action === 'create') {
        $riderId = (int) ($body['rider_id'] ?? 0);
        $rider   = $assertRider($riderId);
        $amount  = (int) ($body['amount'] ?? 0);
        $date    = trim((string) ($body['applied_date'] ?? ''));
        $note    = trim((string) ($body['note'] ?? ''));
        if ($amount <= 0) {
            $err('선지급 금액을 올바르게 입력하세요.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $newId = db_insert(
            "INSERT INTO deduction_entries (rider_id, applied_date, kind, amount, note)
             VALUES (?, ?, 'advance', ?, ?)",
            [$riderId, $date, $amount, mb_substr($note, 0, 255)]
        );
        AuditLog::record('deduction.advance', (string) $riderId, sprintf('%s 선지급 %s원 입력', (string) $rider['name'], number_format($amount)));
        echo json_encode(['ok' => true, 'message' => '선지급이 입력되었습니다.', 'id' => $newId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id  = (int) ($body['id'] ?? 0);
        $row = db_row("SELECT de.rider_id, r.agency_id FROM deduction_entries de INNER JOIN riders r ON r.id = de.rider_id WHERE de.id = ? AND de.kind = 'advance' LIMIT 1", [$id]);
        if ($row === null) {
            $err('내역을 찾을 수 없습니다.', 404);
        }
        if (!Org::canAccessAgency((int) $row['agency_id'])) {
            $err('삭제 권한이 없습니다.', 403);
        }
        db_execute("DELETE FROM deduction_entries WHERE id = ? AND kind = 'advance'", [$id]);
        AuditLog::record('deduction.advance', (string) $id, '선지급 내역 삭제');
        echo json_encode(['ok' => true, 'message' => '삭제되었습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $err('알 수 없는 action 입니다.', 400);
} catch (Throwable $e) {
    $err('처리 실패: ' . $e->getMessage(), 500);
}

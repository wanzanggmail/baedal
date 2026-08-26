<?php

declare(strict_types=1);

require_once __DIR__ . '/BaumFirmGateway.php';

/**
 * 펌뱅킹 비동기 이체 장부 — 접수와 결과 확정을 잇는다.
 *
 * 바움은 접수(RECEPTION)만 즉시 응답하고 성공/실패는 나중에 웹훅으로 온다. 그 사이
 * "무엇을 접수했는지" 를 우리가 들고 있어야 웹훅이 왔을 때 어느 건인지 찾을 수 있고,
 * 웹훅이 유실돼도 미확정 행을 뒤져 재조회할 수 있다.
 *
 * `kind` + `ref_id` 로 원본을 가리킨다 — 출금(withdrawal)뿐 아니라 일일지급·대리점 인출도
 * 같은 게이트웨이를 쓰므로 공용으로 만들었다.
 */
final class FirmTransfer
{
    public const KIND_WITHDRAWAL   = 'withdrawal';
    public const KIND_DAILY_PAYOUT = 'daily_payout';
    public const KIND_AGENCY_PAYOUT = 'agency_payout';

    public static function tableExists(): bool
    {
        return db_table_exists('firm_transfers');
    }

    /** 계좌번호는 뒤 4자리만 남긴다 — 대사할 때 "어느 계좌였나"는 알아야 한다. */
    public static function maskAccount(string $account): string
    {
        $d = preg_replace('/\D/', '', $account) ?? '';
        if ($d === '') {
            return '';
        }

        return str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4);
    }

    /**
     * 접수 기록. **이 시점에 돈은 아직 나가지 않았다.**
     *
     * @param array<string,mixed> $d
     */
    public static function record(array $d): int
    {
        if (!self::tableExists()) {
            throw new RuntimeException('firm_transfers 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        return db_insert(
            'INSERT INTO firm_transfers
                (transaction_id, reception_id, kind, ref_id, agency_id, rider_id,
                 amount, bank_code, account_masked, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $d['transaction_id'],
                mb_substr((string) ($d['reception_id'] ?? ''), 0, 60),
                (string) $d['kind'],
                (int) ($d['ref_id'] ?? 0),
                ($d['agency_id'] ?? 0) > 0 ? (int) $d['agency_id'] : null,
                ($d['rider_id'] ?? 0) > 0 ? (int) $d['rider_id'] : null,
                (int) ($d['amount'] ?? 0),
                mb_substr((string) ($d['bank_code'] ?? ''), 0, 10),
                self::maskAccount((string) ($d['account'] ?? '')),
                (string) ($d['status'] ?? BaumFirmGateway::ST_RECEPTION),
            ]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findByTransactionId(string $txId): ?array
    {
        if (!self::tableExists() || trim($txId) === '') {
            return null;
        }

        return db_row('SELECT * FROM firm_transfers WHERE transaction_id = ? LIMIT 1', [$txId]);
    }

    /** 결과가 확정된 상태인가 — 더 기다릴 필요가 없다. */
    public static function isFinal(string $status): bool
    {
        return in_array(strtoupper(trim($status)), BaumFirmGateway::FINAL_STATES, true);
    }

    /**
     * 상태 갱신.
     *
     * ⚠️ **확정된 건은 다시 바꾸지 않는다.** 웹훅 재전송(최대 10회)과 보정 조회가 같은 건을
     *    두 번 처리할 수 있는데, 그때 지갑을 두 번 깎으면 안 된다. 실제로 바뀐 행이 있을 때만
     *    true 를 돌려주고, 호출부는 그 값을 보고 후속 처리를 한 번만 한다.
     */
    public static function updateStatus(string $txId, string $status, string $failReason = ''): bool
    {
        if (!self::tableExists()) {
            return false;
        }
        $status = strtoupper(trim($status));
        $final  = self::isFinal($status);

        $n = db_execute(
            'UPDATE firm_transfers
                SET status = ?, fail_reason = ?, finalized_at = ' . ($final ? 'NOW()' : 'finalized_at') . ',
                    last_checked_at = NOW()
              WHERE transaction_id = ? AND finalized_at IS NULL',
            [$status, mb_substr($failReason, 0, 300), $txId]
        );

        return $n > 0;
    }

    /** 보정 조회를 돌렸다는 표시만 남긴다(상태 변화 없이). */
    public static function touchChecked(string $txId): void
    {
        if (self::tableExists()) {
            db_execute('UPDATE firm_transfers SET last_checked_at = NOW() WHERE transaction_id = ?', [$txId]);
        }
    }

    /**
     * 아직 결과가 안 온 건 — 보정 조회 대상.
     *
     * `$minAgeMinutes` 이전에 접수된 것만 본다. 방금 접수한 건까지 조회하면
     * 정상 흐름(웹훅이 곧 온다)을 헛되이 앞질러 API 를 두드리게 된다.
     *
     * @return list<array<string,mixed>>
     */
    public static function pending(int $minAgeMinutes = 5, int $limit = 100): array
    {
        if (!self::tableExists()) {
            return [];
        }

        return db_rows(
            'SELECT * FROM firm_transfers
              WHERE finalized_at IS NULL
                AND submitted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
              ORDER BY submitted_at ASC
              LIMIT ' . max(1, min(500, $limit)),
            [max(0, $minAgeMinutes)]
        );
    }

    /** 미확정 건수 — 화면 배지용. */
    public static function pendingCount(): int
    {
        if (!self::tableExists()) {
            return 0;
        }

        return (int) (db_row('SELECT COUNT(*) c FROM firm_transfers WHERE finalized_at IS NULL')['c'] ?? 0);
    }

    /**
     * 최근 목록(화면용).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function recent(array $filters = [], int $limit = 200): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $where  = [];
        $params = [];

        $only = trim((string) ($filters['only'] ?? ''));
        if ($only === 'pending') {
            $where[] = 'finalized_at IS NULL';
        } elseif ($only === 'failed') {
            $where[] = "status IN ('FAILED','CANCELLED')";
        } elseif ($only === 'success') {
            $where[] = "status = 'SUCCESS'";
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[]  = '(transaction_id LIKE ? OR reception_id LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        return db_rows(
            'SELECT * FROM firm_transfers'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }
}

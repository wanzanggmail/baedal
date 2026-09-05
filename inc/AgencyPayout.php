<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';

/**
 * 대리점 자체 정산금 인출 (withdrawal_requests.kind = agency_payout).
 *
 * LOGIC §5.5: 인출가능액(= balance − 라이더 정산금 − 원천세예수금)이 이미 "순수 대리점 몫"이므로
 * 본사 승인 없이 대리점이 신청하면 즉시 실행(잔액 차감). 실제 계좌이체는 오픈뱅킹(Phase F).
 * 신청 시점에 잔액을 차감하고 status=pending(지급 대기)으로 둔다.
 */
final class AgencyPayout
{
    /** @var array<string,array{0:string,1:string}> status → [라벨, 뱃지색] */
    private const STATUS_LABELS = [
        'pending'   => ['지급 대기', 'warning'],
        'completed' => ['지급 완료', 'success'],
        'failed'    => ['이체 실패', 'danger'],
        'rejected'  => ['취소', 'secondary'],
    ];

    /**
     * 대리점 자체 인출 신청 — 잔액 검증 후 즉시 차감·요청 생성(트랜잭션).
     *
     * @return array<string,mixed>
     */
    public static function create(int $agencyId, int $amount, ?int $adminId = null): array
    {
        if ($agencyId < 1) {
            throw new InvalidArgumentException('대리점 정보가 없습니다.');
        }
        if (!db_table_exists('withdrawal_requests') || !AgencyWallet::tableExists()) {
            throw new RuntimeException('지갑/출금 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }

        // 자체 인출은 자기 조직 지갑을 빼는 것 — 대리점·총판, 세무대리(수집한 원천세 납입),
        // 개발사(정산수수료 배분 몫)까지 허용.
        $org = db_row('SELECT id, level, name FROM organizations WHERE id = ? LIMIT 1', [$agencyId]);
        if ($org === null || !in_array((string) $org['level'], ['agency', 'distributor', 'tax_agent', 'developer'], true)) {
            throw new InvalidArgumentException('대리점·총판·세무대리·개발사 조직만 자체 인출할 수 있습니다.');
        }

        $amount = (int) $amount;
        if ($amount <= 0) {
            throw new InvalidArgumentException('인출 금액을 올바르게 입력하세요.');
        }

        $wd = AgencyWallet::withdrawable($agencyId);
        if ($amount > (int) $wd['withdrawable']) {
            throw new InvalidArgumentException(sprintf(
                '인출가능액(%s원)을 초과했습니다. (잔액 %s − 라이더 정산금 %s − 원천세예수금 %s)',
                number_format((int) $wd['withdrawable']),
                number_format((int) $wd['balance']),
                number_format((int) $wd['rider_debt']),
                number_format((int) $wd['withholding_reserve'])
            ));
        }

        // 승인 절차 없이 즉시 오픈뱅킹 이체(현재 mock).
        // 나가는 곳은 **본사 단일 출금 원천 계좌**(Disbursement 안에서 결정), 받는 곳은 대리점 정산금 수령 계좌.
        require_once __DIR__ . '/Disbursement.php';
        require_once __DIR__ . '/BankAccount.php';
        $acct   = BankAccount::get($agencyId);
        $toBank = (string) ($acct['bank_code'] ?? '');
        $toAcc  = (string) ($acct['account_no'] ?? '');
        if ($toBank === '' || $toAcc === '') {
            throw new RuntimeException('정산금 수령 계좌가 등록돼 있지 않습니다. 「결제 설정(카드·계좌)」에서 먼저 등록하세요.');
        }
        $res    = Disbursement::transfer($agencyId, $toBank, $toAcc, $amount);

        if (!$res->success) {
            $failId = db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount, status, fail_reason, note, requested_at)
                 VALUES (NULL, ?, 'agency_payout', ?, ?, 'failed', ?, ?, NOW())",
                [$agencyId, $amount, $amount, mb_substr($res->failReason, 0, 300), '대리점 자체 인출 실패']
            );

            throw new RuntimeException('이체 실패 — ' . $res->failReason);
        }

        $newId = db_transaction(static function () use ($agencyId, $amount, $adminId, $org, $res): int {
            $id = db_insert(
                "INSERT INTO withdrawal_requests
                    (rider_id, agency_id, kind, amount, gross_amount, status, note, requested_at, completed_at)
                 VALUES (NULL, ?, 'agency_payout', ?, ?, 'completed', ?, NOW(), NOW())",
                [$agencyId, $amount, $amount, '대리점 자체 정산금 인출 · ' . $res->txId]
            );

            AgencyWallet::debit($agencyId, $amount, 'agency_payout', $id, (string) $org['name'] . ' 자체 인출', $adminId);

            return $id;
        });

        return self::find($newId);
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $row = db_row(
            'SELECT wr.*, o.name AS agency_name, o.code AS agency_code
               FROM withdrawal_requests wr
               LEFT JOIN organizations o ON o.id = wr.agency_id
              WHERE wr.id = ? AND wr.kind = \'agency_payout\' LIMIT 1',
            [$id]
        );
        if ($row === null) {
            throw new RuntimeException('인출 요청을 찾을 수 없습니다.');
        }

        return self::mapRow($row);
    }

    /**
     * 목록 — agencyId 지정 시 해당 대리점, null이면 스코프(본사=전체/총판=하위) 전체.
     *
     * @return list<array<string,mixed>>
     */
    public static function listScoped(?int $agencyId, int $limit = 200): array
    {
        if (!db_table_exists('withdrawal_requests')) {
            return [];
        }
        $where  = ["wr.kind = 'agency_payout'"];
        $params = [];

        if ($agencyId !== null && $agencyId > 0) {
            $where[]  = 'wr.agency_id = ?';
            $params[] = $agencyId;
        } else {
            // 멀티테넌시: 대리점 조직 스코프 (본사=전체)
            [$scopeSql, $scopeParams] = Org::orgScopeClause('wr.agency_id');
            if ($scopeSql !== '') {
                $where[] = $scopeSql;
                $params  = array_merge($params, $scopeParams);
            }
        }

        $limit    = max(1, min(500, $limit));
        $whereStr = implode(' AND ', $where);

        $rows = db_rows(
            "SELECT wr.*, o.name AS agency_name, o.code AS agency_code
               FROM withdrawal_requests wr
               LEFT JOIN organizations o ON o.id = wr.agency_id
              WHERE {$whereStr}
              ORDER BY wr.id DESC
              LIMIT {$limit}",
            $params
        );

        return array_map([self::class, 'mapRow'], $rows);
    }

    /** @param array<string,mixed> $w @return array<string,mixed> */
    private static function mapRow(array $w): array
    {
        $status = (string) ($w['status'] ?? 'pending');
        [$label, $cls] = self::STATUS_LABELS[$status] ?? [$status, 'secondary'];

        return [
            'id'           => (int) $w['id'],
            'agency_id'    => (int) ($w['agency_id'] ?? 0),
            'agency_name'  => (string) ($w['agency_name'] ?? ''),
            'agency_code'  => (string) ($w['agency_code'] ?? ''),
            'amount'       => (int) $w['amount'],
            'status'       => $status,
            'status_label' => $label,
            'status_class' => $cls,
            'fail_reason'  => (string) ($w['fail_reason'] ?? ''),
            'note'         => (string) ($w['note'] ?? ''),
            'requested_at' => substr((string) ($w['requested_at'] ?? $w['created_at'] ?? ''), 0, 19),
            'completed_at' => $w['completed_at'] ? substr((string) $w['completed_at'], 0, 19) : '',
        ];
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';

/**
 * 대리점 자체 정산금 인출 (withdrawal_requests.kind = agency_payout).
 *
 * LOGIC §5.5: 인출가능액(= balance − 라이더 정산금 − 원천세예수금)이 이미 "순수 대리점 몫"이므로
 * 본사 승인 없이 대리점이 신청하면 즉시 이체를 건다.
 *
 * ⚠️ **2026-09-20 수정 — 예전에는 모의(mock) 오픈뱅킹으로 보내고 「지급 완료」로 찍었다.**
 *    `OpenBankingGatewayFactory` 가 지금도 항상 모의라, 실제로는 **돈이 나가지 않았는데
 *    완료로 기록되고 지갑만 줄었다**(실서버에서 100원 인출이 펌뱅킹 이력에 없어 발견).
 *    이제 라이더 출금과 **같은 펌뱅킹 경로**를 탄다:
 *
 *      신청 → executeTransfers(접수) → status=transferring → 웹훅 SUCCESS → completed + 지갑 차감
 *
 *    즉 **지갑은 이체가 확정될 때 줄어든다.** 접수만 된 상태에서는 아직 돈이 나간 게 아니다.
 */
final class AgencyPayout
{
    /** @var array<string,array{0:string,1:string}> status → [라벨, 뱃지색] */
    private const STATUS_LABELS = [
        'pending'      => ['지급 대기', 'warning'],
        'transferring' => ['이체 접수중', 'info'],
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
        // 지갑 차감은 **이체가 확정될 때** 일어나므로, 이미 접수해 둔 인출액을 빼고 봐야 한다.
        // 안 그러면 확정 전에 같은 돈을 여러 번 인출 신청할 수 있다.
        $inflight = (int) (db_row(
            "SELECT COALESCE(SUM(amount), 0) AS s FROM withdrawal_requests
              WHERE agency_id = ? AND kind = 'agency_payout' AND status = 'transferring'",
            [$agencyId]
        )['s'] ?? 0);
        $avail = (int) $wd['withdrawable'] - $inflight;
        if ($amount > $avail) {
            throw new InvalidArgumentException(sprintf(
                '인출가능액(%s원)을 초과했습니다. (잔액 %s − 라이더 정산금 %s − 원천세예수금 %s%s)',
                number_format(max(0, $avail)),
                number_format((int) $wd['balance']),
                number_format((int) $wd['rider_debt']),
                number_format((int) $wd['withholding_reserve']),
                $inflight > 0 ? ' − 이체 접수중 ' . number_format($inflight) : ''
            ));
        }

        // 받는 곳은 대리점 정산금 수령 계좌. 나가는 곳은 본사 단일 출금 계좌(펌뱅킹 포켓).
        require_once __DIR__ . '/BankAccount.php';
        require_once __DIR__ . '/Withdrawal.php';
        $acct   = BankAccount::get($agencyId);
        $toBank = (string) ($acct['bank_code'] ?? '');
        $toAcc  = (string) ($acct['account_no'] ?? '');
        $holder = (string) ($acct['holder'] ?? $org['name']);
        if ($toBank === '' || $toAcc === '') {
            throw new RuntimeException('정산금 수령 계좌가 등록돼 있지 않습니다. 「결제 설정(카드·계좌)」에서 먼저 등록하세요.');
        }

        // 신청을 먼저 남기고(아직 돈은 그대로) 펌뱅킹으로 접수한다 — 라이더 출금과 같은 경로다.
        // 계좌는 **요청 행에 암호화해 담는다**(executeTransfers 가 거기서 읽는다).
        $newId = db_insert(
            "INSERT INTO withdrawal_requests
                (rider_id, agency_id, kind, amount, gross_amount, status, bank_code, bank_account, account_holder, note, requested_at)
             VALUES (NULL, ?, 'agency_payout', ?, ?, 'pending', ?, ?, ?, ?, NOW())",
            [$agencyId, $amount, $amount, $toBank, Crypto::encrypt($toAcc), $holder, (string) $org['name'] . ' 자체 인출']
        );

        $res = Withdrawal::executeTransfers([$newId]);
        if ((int) $res['completed'] < 1 && (int) $res['accepted'] < 1) {
            $msg = '';
            foreach ($res['results'] as $r) {
                if ((int) $r['id'] === $newId) {
                    $msg = (string) $r['message'];
                }
            }

            throw new RuntimeException($msg !== '' ? $msg : '이체 접수에 실패했습니다.');
        }

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

<?php

declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

/**
 * 조직 계좌 (agency_bank_accounts) — LOGIC §5.4 · §7 #10.
 *
 * ⚠️ **2026-08-15 의미 재정의.** 갑 확정("결제하는 상점은 하나. PG로 돈 받는 계좌,
 * 라이더에게 출금하는 계좌는 하나")에 따라 이 테이블의 행은 **레벨별로 역할이 다르다**:
 *
 *   - **본사(admin) 행** = **출금 원천 계좌**(실제로 돈이 나가는 단 하나의 계좌).
 *     라이더 이체·대리점 인출이 전부 여기서 나간다. 오픈뱅킹/펌뱅킹 이체에 필요한
 *     `fintech_use_num`이 있어야 하는 건 **이 행뿐**이다.
 *   - **대리점(agency) 행** = **정산금 수령 계좌**. 대리점이 자체 인출할 때 **받을** 곳이다.
 *     수취에는 은행코드·계좌번호·예금주만 있으면 되고 핀테크번호는 쓰지 않는다.
 *
 * 재정의 전에는 두 역할이 섞여 있어서, 대리점 자체 인출이 **자기 계좌에서 자기 계좌로**
 * 보내는 꼴이었다(`Disbursement::transfer($agencyId, ...)`가 출금 원천도 대리점으로 조회).
 */
final class BankAccount
{
    public static function tableExists(): bool
    {
        return db_table_exists('agency_bank_accounts');
    }

    /** @return array<string,mixed>|null */
    public static function get(int $agencyId): ?array
    {
        if ($agencyId < 1 || !self::tableExists()) {
            return null;
        }

        $row = db_row('SELECT * FROM agency_bank_accounts WHERE agency_id = ? LIMIT 1', [$agencyId]);
        if ($row === null) {
            return null;
        }

        // 🔒 계좌번호·핀테크이용번호는 암호화 저장한다. **읽기 지점이 여기 하나뿐**이라
        //    (fintechNum·AgencyPayout·화면 모두 get() 을 거친다) 여기서만 풀면 된다.
        //    이관 전 평문 행도 그대로 통과한다.
        foreach (['account_no', 'fintech_use_num'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) {
                $row[$f] = Crypto::decrypt((string) $row[$f]);
            }
        }

        return $row;
    }

    /**
     * 이체 실행에 필요한 핀테크이용번호(없으면 빈 문자열).
     *
     * ⚠️ 대부분의 호출에는 `payerFintechNum()`을 써야 한다. 이 함수는 특정 조직 행을
     *    그대로 읽을 뿐이라, 대리점 id를 넘기면 **수령 계좌**의 번호가 나온다.
     */
    public static function fintechNum(int $orgId): string
    {
        $row = self::get($orgId);

        return $row !== null ? (string) ($row['fintech_use_num'] ?? '') : '';
    }

    /**
     * **출금 원천 계좌**(본사 단일)의 핀테크이용번호.
     * 라이더 이체·대리점 인출 등 "돈이 나가는" 모든 경로가 이걸 쓴다.
     */
    public static function payerFintechNum(): string
    {
        require_once __DIR__ . '/Org.php';
        $hq = Org::hqId();

        return $hq > 0 ? self::fintechNum($hq) : '';
    }

    /** 출금 원천 계좌 정보(화면 표시용). @return array<string,mixed>|null */
    public static function payerAccount(): ?array
    {
        require_once __DIR__ . '/Org.php';
        $hq = Org::hqId();

        return $hq > 0 ? self::get($hq) : null;
    }

    /** 이 조직이 출금 원천 계좌(본사)를 들고 있는 조직인지 */
    public static function isPayerOrg(int $orgId): bool
    {
        require_once __DIR__ . '/Org.php';

        return $orgId > 0 && $orgId === Org::hqId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function save(int $agencyId, array $data): void
    {
        if ($agencyId < 1 || !self::tableExists()) {
            throw new RuntimeException('계좌 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        $bankCode = trim((string) ($data['bank_code'] ?? ''));
        $account  = trim((string) ($data['account_no'] ?? ''));
        $holder   = trim((string) ($data['holder'] ?? ''));
        $fintech  = trim((string) ($data['fintech_use_num'] ?? ''));

        if ($bankCode === '' || $account === '') {
            throw new InvalidArgumentException('은행과 계좌번호를 입력하세요.');
        }

        $exists = self::get($agencyId);

        // 핀테크이용번호는 개설기관이 계좌에 발급한 식별자다(이체 실행 키 — Disbursement::transfer).
        // 입력이 비었다고 매번 새로 만들면 예금주명만 고쳐도 번호가 바뀌어 이체가 끊기므로,
        // 기존 값이 있으면 그대로 유지하고 정말 없을 때만 (실 연동 전) 모의 값을 발급한다.
        if ($fintech === '') {
            $fintech = (string) ($exists['fintech_use_num'] ?? '');
        }
        // 핀테크번호가 필요한 건 **출금 원천 계좌(본사)** 뿐이다. 대리점 행은 수령 계좌라
        // 은행코드·계좌번호·예금주면 충분하므로 굳이 발급하지 않는다.
        if ($fintech === '' && self::isPayerOrg($agencyId)) {
            $fintech = 'MOCK-FT-' . strtoupper(bin2hex(random_bytes(6)));
        }

        if ($exists !== null) {
            db_execute(
                'UPDATE agency_bank_accounts SET bank_code = ?, account_no = ?, holder = ?, fintech_use_num = ?, updated_at = NOW() WHERE agency_id = ?',
                [$bankCode, Crypto::encrypt($account), $holder, Crypto::encrypt($fintech), $agencyId]
            );
        } else {
            db_insert(
                'INSERT INTO agency_bank_accounts (agency_id, bank_code, account_no, holder, fintech_use_num) VALUES (?, ?, ?, ?, ?)',
                [$agencyId, $bankCode, Crypto::encrypt($account), $holder, Crypto::encrypt($fintech)]
            );
        }
    }
}

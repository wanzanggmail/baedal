<?php

declare(strict_types=1);

/**
 * 대리점 오픈뱅킹 출금계좌 (agency_bank_accounts) — LOGIC §5.4 · §7 #10.
 *
 * 대리점 명의 계좌를 오픈뱅킹에 등록(핀테크이용번호). 라이더·대리점 이체 시 출금 계좌로 사용.
 * 본사가 오픈뱅킹 이용기관 계약 주체(실 연동 전까지 모의 핀테크번호 저장 가능).
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

        return db_row('SELECT * FROM agency_bank_accounts WHERE agency_id = ? LIMIT 1', [$agencyId]);
    }

    /**
     * 이체 실행에 필요한 핀테크이용번호(없으면 빈 문자열).
     */
    public static function fintechNum(int $agencyId): string
    {
        $row = self::get($agencyId);

        return $row !== null ? (string) ($row['fintech_use_num'] ?? '') : '';
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
        // 실 연동 전: 핀테크이용번호 미입력 시 모의 값 자동 발급
        if ($fintech === '') {
            $fintech = 'MOCK-FT-' . strtoupper(bin2hex(random_bytes(6)));
        }

        $exists = self::get($agencyId);
        if ($exists !== null) {
            db_execute(
                'UPDATE agency_bank_accounts SET bank_code = ?, account_no = ?, holder = ?, fintech_use_num = ?, updated_at = NOW() WHERE agency_id = ?',
                [$bankCode, $account, $holder, $fintech, $agencyId]
            );
        } else {
            db_insert(
                'INSERT INTO agency_bank_accounts (agency_id, bank_code, account_no, holder, fintech_use_num) VALUES (?, ?, ?, ?, ?)',
                [$agencyId, $bankCode, $account, $holder, $fintech]
            );
        }
    }
}

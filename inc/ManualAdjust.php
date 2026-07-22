<?php

declare(strict_types=1);

require_once __DIR__ . '/AgencyWallet.php';
require_once __DIR__ . '/RiderWallet.php';
require_once __DIR__ . '/AuditLog.php';

/**
 * 정산/잔액 수동 조정 (본사 super 전용) — LOGIC §5.4a.
 *
 * PG결제·오픈뱅킹 자동 롤백/환불 연동은 만들지 않는다. 문제가 생기면 본사가 이 화면에서
 * 라이더 지갑·대리점 잔액을 직접 바로잡는다. 모든 조정은 사유 필수 + before/after 감사로그.
 */
final class ManualAdjust
{
    /**
     * 라이더 지갑 잔액을 특정 값으로 직접 설정.
     *
     * @return array{before:int, after:int}
     */
    public static function adjustRiderWallet(int $riderId, int $newBalance, string $reason, ?int $adminId = null): array
    {
        $reason = trim($reason);
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더를 선택하세요.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('조정 사유는 필수입니다.');
        }
        if ($newBalance < 0) {
            throw new InvalidArgumentException('잔액은 0원 이상이어야 합니다.');
        }
        if (!db_table_exists('rider_wallets')) {
            throw new RuntimeException('지갑 테이블이 없습니다.');
        }

        $rider = db_row('SELECT id, name, rider_code FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        if ($rider === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }

        RiderWallet::ensure($riderId);
        $before = (int) (db_row('SELECT balance FROM rider_wallets WHERE rider_id = ? LIMIT 1', [$riderId])['balance'] ?? 0);

        db_execute('UPDATE rider_wallets SET balance = ?, updated_at = NOW() WHERE rider_id = ?', [$newBalance, $riderId]);

        self::audit('settlement.manual_adjust.rider', 'rider_wallets', $riderId, $adminId, $reason, [
            'target'  => 'rider_wallet',
            'rider'   => (string) $rider['rider_code'] . '/' . (string) $rider['name'],
            'balance' => $before,
        ], [
            'balance' => $newBalance,
            'reason'  => $reason,
        ]);

        return ['before' => $before, 'after' => $newBalance];
    }

    /**
     * 대리점 지갑 잔액을 특정 값으로 직접 설정.
     *
     * @return array{before:int, after:int}
     */
    public static function adjustAgencyBalance(int $agencyId, int $newBalance, string $reason, ?int $adminId = null): array
    {
        $reason = trim($reason);
        if ($agencyId < 1) {
            throw new InvalidArgumentException('대리점을 선택하세요.');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('조정 사유는 필수입니다.');
        }
        if ($newBalance < 0) {
            throw new InvalidArgumentException('잔액은 0원 이상이어야 합니다.');
        }

        $org = db_row('SELECT id, level, name FROM organizations WHERE id = ? LIMIT 1', [$agencyId]);
        if ($org === null || (string) $org['level'] !== 'agency') {
            throw new InvalidArgumentException('대리점 조직이 아닙니다.');
        }

        $before = AgencyWallet::get($agencyId)['balance'];
        AgencyWallet::setBalance($agencyId, $newBalance, '수동조정: ' . $reason, $adminId);

        self::audit('settlement.manual_adjust.agency', 'agency_wallets', $agencyId, $adminId, $reason, [
            'target'  => 'agency_balance',
            'agency'  => (string) $org['name'],
            'balance' => $before,
        ], [
            'balance' => $newBalance,
            'reason'  => $reason,
        ]);

        return ['before' => $before, 'after' => $newBalance];
    }

    /**
     * 대리점 원천세 예수금을 특정 값으로 직접 설정.
     *
     * @return array{before:int, after:int}
     */
    public static function adjustAgencyReserve(int $agencyId, int $newReserve, string $reason, ?int $adminId = null): array
    {
        $reason = trim($reason);
        if ($agencyId < 1 || $reason === '') {
            throw new InvalidArgumentException('대리점과 조정 사유는 필수입니다.');
        }
        if ($newReserve < 0) {
            throw new InvalidArgumentException('예수금은 0원 이상이어야 합니다.');
        }
        $org = db_row('SELECT id, level, name FROM organizations WHERE id = ? LIMIT 1', [$agencyId]);
        if ($org === null || (string) $org['level'] !== 'agency') {
            throw new InvalidArgumentException('대리점 조직이 아닙니다.');
        }

        $before = AgencyWallet::get($agencyId)['withholding_reserve'];
        AgencyWallet::ensure($agencyId);
        db_execute('UPDATE agency_wallets SET withholding_reserve = ?, updated_at = NOW() WHERE agency_id = ?', [$newReserve, $agencyId]);

        self::audit('settlement.manual_adjust.reserve', 'agency_wallets', $agencyId, $adminId, $reason, [
            'target'  => 'agency_reserve',
            'agency'  => (string) $org['name'],
            'reserve' => $before,
        ], [
            'reserve' => $newReserve,
            'reason'  => $reason,
        ]);

        return ['before' => $before, 'after' => $newReserve];
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function audit(string $action, string $table, int $targetId, ?int $adminId, string $reason, array $before, array $after): void
    {
        AuditLog::write([
            'actor_type'   => 'admin',
            'actor_id'     => ($adminId !== null && $adminId > 0) ? $adminId : null,
            'action'       => 'MANUAL_ADJUST',
            'target_table' => $table,
            'target_id'    => $targetId,
            'before_value' => $before,
            'after_value'  => $after,
        ]);
    }
}

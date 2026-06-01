<?php

declare(strict_types=1);

/**
 * 라이더 로그인·세션 (riders 테이블)
 */
final class RiderAuth
{
    private const LOGIN_FAIL_MAX = 5;
    private const LOGIN_FAIL_WINDOW = 600;

    /** @var array<string, string> */
    private const STATUS_MESSAGES = [
        'active'        => '',
        'suspended'     => '일시 정지된 계정입니다. 운영센터에 문의하세요.',
        'leave_request' => '탈퇴 요청 처리 중이라 로그인할 수 없습니다.',
        'offboarded'    => '계약이 종료된 계정입니다.',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function authenticate(string $loginId, string $password): ?array
    {
        $loginId = trim($loginId);
        if ($loginId === '' || $password === '') {
            return null;
        }

        $row = self::findByLoginInput($loginId);
        if (!$row) {
            return null;
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status !== 'active') {
            throw new RuntimeException(self::STATUS_MESSAGES[$status] ?? '로그인할 수 없는 계정 상태입니다.');
        }

        return self::mapSessionRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = db_row(
            'SELECT id, rider_code, login_id, name, phone, email, status, vehicle_type,
                    bank_code, bank_account, account_holder, is_daily_settlement
             FROM riders WHERE id = ? LIMIT 1',
            [$id]
        );

        return $row ? self::mapSessionRow($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findByLoginInput(string $loginInput): ?array
    {
        $loginInput = trim($loginInput);
        if ($loginInput === '') {
            return null;
        }

        $row = db_row(
            'SELECT id, rider_code, login_id, password_hash, name, phone, email, status, vehicle_type,
                    bank_code, bank_account, account_holder, is_daily_settlement
             FROM riders WHERE login_id = ? LIMIT 1',
            [$loginInput]
        );
        if ($row) {
            return $row;
        }

        $digits = preg_replace('/\D/', '', $loginInput);
        if ($digits !== '' && strlen($digits) >= 10) {
            $row = db_row(
                'SELECT id, rider_code, login_id, password_hash, name, phone, email, status, vehicle_type,
                        bank_code, bank_account, account_holder, is_daily_settlement
                 FROM riders
                 WHERE REPLACE(REPLACE(phone, \'-\', \'\'), \' \', \'\') = ?
                 LIMIT 1',
                [$digits]
            );
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    public static function mapSessionRow(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'rider_code'           => (string) ($row['rider_code'] ?? ''),
            'login_id'             => (string) ($row['login_id'] ?? ''),
            'name'                 => (string) ($row['name'] ?? ''),
            'phone'                => (string) ($row['phone'] ?? ''),
            'email'                => (string) ($row['email'] ?? ''),
            'status'               => (string) ($row['status'] ?? ''),
            'vehicle_type'         => (string) ($row['vehicle_type'] ?? ''),
            'bank_code'            => (string) ($row['bank_code'] ?? ''),
            'bank_account'         => (string) ($row['bank_account'] ?? ''),
            'account_holder'       => (string) ($row['account_holder'] ?? ''),
            'is_daily_settlement'  => (int) ($row['is_daily_settlement'] ?? 0) === 1,
        ];
    }

    /** @param array<string, mixed> $rider */
    public static function touchLastLogin(int $riderId): void
    {
        try {
            db_execute('UPDATE riders SET last_login_at = NOW() WHERE id = ?', [$riderId]);
        } catch (Throwable) {
        }
    }

    public static function checkLoginThrottle(): ?string
    {
        $now   = time();
        $fails = (int) ($_SESSION['rider_login_fail_count'] ?? 0);
        $last  = (int) ($_SESSION['rider_login_fail_at'] ?? 0);
        if ($fails >= self::LOGIN_FAIL_MAX && ($now - $last) < self::LOGIN_FAIL_WINDOW) {
            $wait = self::LOGIN_FAIL_WINDOW - ($now - $last);

            return "로그인 시도 횟수를 초과했습니다. {$wait}초 후 다시 시도하세요.";
        }
        if (($now - $last) >= self::LOGIN_FAIL_WINDOW) {
            $_SESSION['rider_login_fail_count'] = 0;
        }

        return null;
    }

    public static function recordLoginFailure(): void
    {
        $_SESSION['rider_login_fail_count'] = (int) ($_SESSION['rider_login_fail_count'] ?? 0) + 1;
        $_SESSION['rider_login_fail_at']    = time();
    }

    public static function clearLoginFailures(): void
    {
        unset($_SESSION['rider_login_fail_count'], $_SESSION['rider_login_fail_at']);
    }

    /**
     * @throws InvalidArgumentException 검증 실패
     */
    public static function changePassword(int $riderId, string $currentPassword, string $newPassword, string $newPasswordConfirm): void
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('로그인이 필요합니다.');
        }
        if ($currentPassword === '') {
            throw new InvalidArgumentException('현재 비밀번호를 입력하세요.');
        }
        if ($newPassword === '') {
            throw new InvalidArgumentException('새 비밀번호를 입력하세요.');
        }
        if (strlen($newPassword) < 4) {
            throw new InvalidArgumentException('새 비밀번호는 4자 이상이어야 합니다.');
        }
        if ($newPassword !== $newPasswordConfirm) {
            throw new InvalidArgumentException('새 비밀번호 확인이 일치하지 않습니다.');
        }
        if ($currentPassword === $newPassword) {
            throw new InvalidArgumentException('새 비밀번호는 현재 비밀번호와 달라야 합니다.');
        }

        $row = db_row('SELECT id, password_hash, status FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        if (!$row) {
            throw new InvalidArgumentException('계정을 찾을 수 없습니다.');
        }
        if ((string) ($row['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('비밀번호를 변경할 수 없는 계정 상태입니다.');
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            throw new InvalidArgumentException('현재 비밀번호가 올바르지 않습니다.');
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        db_execute('UPDATE riders SET password_hash = ? WHERE id = ?', [$newHash, $riderId]);
    }
}

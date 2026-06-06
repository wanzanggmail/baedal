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
        if ($row === null) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status !== 'active') {
            $msg = self::STATUS_MESSAGES[$status] ?? '로그인할 수 없는 계정입니다.';
            if ($msg !== '') {
                throw new RuntimeException($msg);
            }

            return null;
        }

        if (!password_verify($password, (string) ($row['password_hash'] ?? ''))) {
            return null;
        }

        return self::mapSessionRow($row);
    }

    /** @return array<string, mixed>|null */
    public static function findById(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = db_row('SELECT * FROM riders WHERE id = ? LIMIT 1', [$id]);

        return $row !== null ? self::mapSessionRow($row) : null;
    }

    /** @return array<string, mixed>|null */
    public static function findByLoginInput(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $row = db_row(
            'SELECT * FROM riders WHERE login_id = ? LIMIT 1',
            [$input]
        );
        if ($row !== null) {
            return $row;
        }

        $digits = preg_replace('/\D/', '', $input);
        if ($digits === '') {
            return null;
        }

        return db_row(
            'SELECT * FROM riders
              WHERE REPLACE(REPLACE(REPLACE(phone, "-", ""), " ", ""), ".", "") = ?
              LIMIT 1',
            [$digits]
        );
    }

    public static function touchLastLogin(int $riderId): void
    {
        if ($riderId < 1) {
            return;
        }

        db_execute('UPDATE riders SET last_login_at = NOW() WHERE id = ?', [$riderId]);
    }

    public static function checkLoginThrottle(): ?string
    {
        $now   = time();
        $fails = (int) ($_SESSION['rider_login_fail_count'] ?? 0);
        $last  = (int) ($_SESSION['rider_login_fail_at'] ?? 0);

        if ($fails >= self::LOGIN_FAIL_MAX && ($now - $last) < self::LOGIN_FAIL_WINDOW) {
            $wait = self::LOGIN_FAIL_WINDOW - ($now - $last);

            return "로그인 시도 횟수를 초과했습니다. {$wait}초 후 다시 시도해 주세요.";
        }

        if (($now - $last) >= self::LOGIN_FAIL_WINDOW) {
            self::clearLoginFailures();
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

    public static function changePassword(int $riderId, string $current, string $newPw, string $confirm): void
    {
        if ($riderId < 1) {
            throw new InvalidArgumentException('라이더 정보가 없습니다.');
        }
        if ($newPw === '' || strlen($newPw) < 4) {
            throw new InvalidArgumentException('새 비밀번호는 4자 이상이어야 합니다.');
        }
        if ($newPw !== $confirm) {
            throw new InvalidArgumentException('새 비밀번호 확인이 일치하지 않습니다.');
        }

        $row = db_row('SELECT password_hash FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        if ($row === null) {
            throw new InvalidArgumentException('라이더를 찾을 수 없습니다.');
        }
        if (!password_verify($current, (string) ($row['password_hash'] ?? ''))) {
            throw new InvalidArgumentException('현재 비밀번호가 올바르지 않습니다.');
        }

        $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
        db_execute('UPDATE riders SET password_hash = ?, updated_at = NOW() WHERE id = ?', [$hash, $riderId]);
    }

    /** @param array<string, mixed> $row */
    public static function mapSessionRow(array $row): array
    {
        return [
            'id'                  => (int) ($row['id'] ?? 0),
            'rider_code'          => (string) ($row['rider_code'] ?? ''),
            'login_id'            => (string) ($row['login_id'] ?? ''),
            'name'                => (string) ($row['name'] ?? ''),
            'phone'               => (string) ($row['phone'] ?? ''),
            'email'               => (string) ($row['email'] ?? ''),
            'status'              => (string) ($row['status'] ?? 'active'),
            'vehicle_type'        => (string) ($row['vehicle_type'] ?? ''),
            'bank_code'           => (string) ($row['bank_code'] ?? ''),
            'bank_account'        => (string) ($row['bank_account'] ?? ''),
            'account_holder'      => (string) ($row['account_holder'] ?? ''),
            'is_daily_settlement' => (int) ($row['is_daily_settlement'] ?? 0) === 1,
            'password_hash'       => (string) ($row['password_hash'] ?? ''),
        ];
    }
}

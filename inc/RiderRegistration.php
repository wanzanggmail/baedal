<?php

declare(strict_types=1);

require_once __DIR__ . '/Crypto.php';

require_once __DIR__ . '/RiderAuth.php';
require_once __DIR__ . '/RiderLoginId.php';
require_once __DIR__ . '/Org.php';

/**
 * 라이더 신규 등록 — 단건(admin/api/riders.php)과 대량(admin/api/riders_bulk_upload.php)이
 * **같은 검증·생성 규칙**을 쓰도록 공용화한 것. 두 곳에 따로 구현하면 나중에 한쪽만
 * 고치고 다른 쪽을 빠뜨리는 사고가 나기 쉽다(이 프로젝트에서 실제로 몇 번 있었던 패턴).
 */
final class RiderRegistration
{
    public const VEHICLE_TYPES = ['motor', 'bike', 'car', 'walk', 'kick'];

    /**
     * 같은 대리점 안에 같은 휴대전화번호가 이미 있는지 — 있으면 그 라이더 정보를 돌려준다.
     *
     * ⚠️ **대리점 범위 안에서만** 검사한다. 라이더가 대리점을 옮기거나 겸업하면 다른 대리점에
     *    같은 번호로 또 등록되는 게 정상이다(2026-07-02 갑 확정 — 이관이 아니라 신규 등록,
     *    번호·이름 중복 허용). 전역 UNIQUE를 걸면 이 정상 케이스가 막힌다.
     *
     * 비교는 **숫자만 남겨서** 한다 — `010-1234-5678`과 `01012345678`이 같은 번호인데
     * 문자열 그대로 비교하면 서로 다른 값으로 통과해 중복이 그대로 들어온다.
     *
     * @return array{id:int, name:string, rider_code:string, status:string}|null
     */
    public static function findByPhoneInAgency(string $phone, int $agencyId, int $excludeRiderId = 0): ?array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '' || $agencyId < 1) {
            return null;
        }

        $sql = "SELECT id, name, rider_code, status
                  FROM riders
                 WHERE agency_id = ?
                   AND REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?";
        $params = [$agencyId, $digits];
        if ($excludeRiderId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeRiderId;
        }
        $row = db_row($sql . ' LIMIT 1', $params);

        return $row === null ? null : [
            'id'         => (int) $row['id'],
            'name'       => (string) $row['name'],
            'rider_code' => (string) $row['rider_code'],
            'status'     => (string) $row['status'],
        ];
    }

    /**
     * 같은 대리점 내 번호 중복이면 예외. 메시지에 **누구와 겹치는지**를 담는다 —
     * "중복입니다"만 뜨면 관리자가 기존 라이더를 찾아 헤매게 된다.
     */
    public static function assertPhoneFreeInAgency(string $phone, int $agencyId, int $excludeRiderId = 0): void
    {
        $dup = self::findByPhoneInAgency($phone, $agencyId, $excludeRiderId);
        if ($dup === null) {
            return;
        }

        $statusLabel = match ($dup['status']) {
            'suspended'     => ' · 일시정지',
            'leave_request' => ' · 탈퇴요청',
            'offboarded'    => ' · 계약종료',
            default         => '',
        };

        throw new InvalidArgumentException(sprintf(
            '이 대리점에 같은 휴대전화번호로 등록된 라이더가 이미 있습니다 — %s(%s)%s. 같은 사람이면 「기존 라이더에 연결」을 쓰세요.',
            $dup['name'],
            $dup['rider_code'],
            $statusLabel
        ));
    }

    /**
     * 검증만 수행하고 DB에는 쓰지 않는다 — 대량등록 미리보기에서 사용.
     * (create()가 내부에서 db_transaction()을 여는데, 미리보기를 위해 그걸 또 감싸면
     *  PDO가 트랜잭션 중첩을 지원하지 않아 beginTransaction()에서 바로 에러가 난다.
     *  그래서 "검증 → 쓰기"를 완전히 분리해 미리보기는 쓰기 없이 검증만 하게 했다.)
     *
     * @param array<string,mixed> $in
     * @return array{riderCode:string, loginId:string, phone:string, email:string,
     *               birth:string, teamCode:string, vehicle:string, address:string,
     *               bankCode:string, bankAccount:string, accHolder:string,
     *               platformIds:array<string,string>, agencyId:int, name:string}
     * @throws InvalidArgumentException 검증 실패 시(사용자에게 그대로 보여줄 메시지)
     */
    public static function validate(array $in): array
    {
        $name  = trim((string) ($in['name']  ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($name === '')  { throw new InvalidArgumentException('이름을 입력하세요.'); }
        if ($phone === '') { throw new InvalidArgumentException('휴대전화를 입력하세요.'); }

        $agencyId = (int) ($in['agency_id'] ?? 0);
        if ($agencyId < 1) {
            throw new InvalidArgumentException('소속 대리점을 선택하세요.');
        }
        $agencyOrg = Org::find($agencyId);
        if ($agencyOrg === null || (string) $agencyOrg['level'] !== Org::LEVEL_AGENCY || !Org::canAccessAgency($agencyId)) {
            throw new InvalidArgumentException('선택한 대리점에 접근할 수 없습니다.');
        }

        // 🆕 2026-08-22 같은 대리점 안에서는 번호 중복 금지(빠른 등록과 동일 규칙).
        // create()가 validate()를 거치므로 단건 등록·엑셀 대량등록(미리보기·확정) 모두 여기서 걸린다.
        self::assertPhoneFreeInAgency($phone, $agencyId);

        $loginId = trim((string) ($in['login_id'] ?? ''));
        if ($loginId === '') {
            $loginId = RiderLoginId::generate($phone);
        } else {
            if (!preg_match('/^[a-zA-Z0-9_.@\-]{3,60}$/', $loginId)) {
                throw new InvalidArgumentException('로그인 ID는 영문·숫자·_·.·@·- 3~60자입니다.');
            }
            if (db_row('SELECT id FROM riders WHERE login_id = ?', [$loginId]) !== null) {
                throw new InvalidArgumentException("이미 사용 중인 로그인 ID입니다. ({$loginId})");
            }
        }

        $customCode = trim((string) ($in['rider_code'] ?? ''));
        if ($customCode !== '') {
            if (!preg_match('/^R-[A-Za-z0-9._\-]+$/', $customCode)) {
                throw new InvalidArgumentException('라이더 코드는 R-로 시작해야 합니다.');
            }
            if (db_row('SELECT id FROM riders WHERE rider_code = ?', [$customCode]) !== null) {
                throw new InvalidArgumentException("이미 사용 중인 라이더 코드입니다. ({$customCode})");
            }
            $riderCode = $customCode;
        } else {
            do {
                $riderCode = 'R-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            } while (db_row('SELECT id FROM riders WHERE rider_code = ?', [$riderCode]) !== null);
        }

        $vehicle = trim((string) ($in['vehicle_type'] ?? 'motor'));
        if (!in_array($vehicle, self::VEHICLE_TYPES, true)) {
            $vehicle = 'motor';
        }

        $teamCode    = trim((string) ($in['team_code']      ?? 'etc')) ?: 'etc';
        $birth       = trim((string) ($in['birth_date']     ?? ''));
        $address     = trim((string) ($in['address']        ?? ''));
        $bankCode    = trim((string) ($in['bank_code']      ?? ''));
        $bankAccount = trim((string) ($in['bank_account']   ?? ''));
        $accHolder   = trim((string) ($in['account_holder'] ?? ''));
        $email       = trim((string) ($in['email']          ?? ''));
        $coupangId   = trim((string) ($in['coupang_id']     ?? ''));
        $baeminId    = trim((string) ($in['baemin_id']      ?? ''));

        $platformIds = array_filter([
            'coupang' => $coupangId,
            'baemin'  => $baeminId,
        ], static fn (string $v): bool => $v !== '');

        // 같은 대리점 내 동일 플랫폼 ID 중복 방지(§5.2 — 대리점 간 중복은 정상, 대리점 안에서만 유일)
        foreach ($platformIds as $pf => $ext) {
            $dup = db_row(
                'SELECT rp.id FROM rider_platforms rp INNER JOIN riders r ON r.id = rp.rider_id
                  WHERE rp.platform = ? AND rp.external_id = ? AND r.agency_id = ? LIMIT 1',
                [$pf, $ext, $agencyId]
            );
            if ($dup !== null) {
                $label = $pf === 'coupang' ? '쿠팡' : '배민';
                throw new InvalidArgumentException("{$label} ID({$ext})가 이미 다른 라이더에 연결돼 있습니다.");
            }
        }

        return [
            'riderCode'   => $riderCode,
            'loginId'     => $loginId,
            'name'        => $name,
            'phone'       => $phone,
            'email'       => $email,
            'birth'       => $birth,
            'teamCode'    => $teamCode,
            'vehicle'     => $vehicle,
            'address'     => $address,
            'bankCode'    => $bankCode,
            'bankAccount' => $bankAccount,
            'accHolder'   => $accHolder,
            'platformIds' => $platformIds,
            'agencyId'    => $agencyId,
        ];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{id:int, rider_code:string, login_id:string}
     * @throws InvalidArgumentException 검증 실패 시(사용자에게 그대로 보여줄 메시지)
     */
    public static function create(array $in): array
    {
        $v = self::validate($in);
        $passwordHash = password_hash(RiderAuth::INITIAL_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]);

        $newId = db_transaction(static function () use ($v, $passwordHash): int {
            $id = db_insert(
                'INSERT INTO riders
                    (rider_code, login_id, password_hash, must_change_password, name, phone, email, birth_date,
                     status, team_code, vehicle_type, address,
                     bank_code, bank_account, account_holder, agency_id)
                 VALUES (?, ?, ?, 1, ?, ?, ?, ?, \'active\', ?, ?, ?, ?, ?, ?, ?)',
                [
                    $v['riderCode'], $v['loginId'], $passwordHash, $v['name'], $v['phone'], $v['email'],
                    $v['birth'] !== '' ? $v['birth'] : null,
                    $v['teamCode'], $v['vehicle'], $v['address'],
                    $v['bankCode'] !== '' ? $v['bankCode'] : null,
                    $v['bankAccount'] !== '' ? Crypto::encrypt($v['bankAccount']) : null,
                    $v['accHolder'] !== '' ? $v['accHolder'] : null,
                    $v['agencyId'],
                ]
            );

            foreach ($v['platformIds'] as $pf => $ext) {
                db_insert(
                    'INSERT INTO rider_platforms (rider_id, platform, is_connected, external_id) VALUES (?, ?, 1, ?)',
                    [$id, $pf, $ext]
                );
            }

            return $id;
        });

        return ['id' => $newId, 'rider_code' => $v['riderCode'], 'login_id' => $v['loginId']];
    }
}

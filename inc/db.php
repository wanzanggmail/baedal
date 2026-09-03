<?php

declare(strict_types=1);

/**
 * DB 연결 설정
 * 환경 변수 > 아래 기본값 순으로 적용됩니다.
 * 운영: 프로젝트 루트 .env (inc/env.php) 또는 Apache SetEnv
 */
define('DB_HOST',    getenv('DB_HOST')    ?: '13.209.173.109');   // 2026-08-25 서버 이전
define('DB_PORT',    (int)(getenv('DB_PORT')    ?: 3306));
define('DB_NAME',    getenv('DB_NAME')    ?: 'my_web_db');
define('DB_USER',    getenv('DB_USER')    ?: 'dev_user');
define('DB_PASS',    getenv('DB_PASS')    ?: 'ehRoql1!');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');
// RDS 등 require_secure_transport=ON 인 DB용 — CA 번들 경로를 주면 SSL로 접속한다.
// 값이 없으면(기존 서버) 지금처럼 평문 접속 그대로 — 하위호환 유지.
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: '');

/**
 * PDO 인스턴스를 싱글턴으로 반환합니다.
 *
 * 연결 실패 시 운영 환경에서는 503, 개발 환경에서는 예외 메시지를 그대로 노출합니다.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $initCmdKey = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
        ? \Pdo\Mysql::ATTR_INIT_COMMAND
        : PDO::MYSQL_ATTR_INIT_COMMAND;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        $initCmdKey                  => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    if (DB_SSL_CA !== '' && is_file(DB_SSL_CA)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
        // RDS 엔드포인트는 인증서 CN/SAN이 실제 호스트명과 일치하므로 서버 인증서 검증을 켠다.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        $isDev = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
                 || (defined('APP_ENV') && APP_ENV === 'development');

        if ($isDev) {
            throw $e;
        }

        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'DB 연결 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
        exit;
    }

    return $pdo;
}

/**
 * SELECT 단일 행 — 없으면 null
 *
 * @param  list<mixed>  $params
 * @return array<string,mixed>|null
 */
function db_row(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * SELECT 전체 — 없으면 []
 *
 * @param  list<mixed>            $params
 * @return list<array<string,mixed>>
 */
function db_rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * INSERT / UPDATE / DELETE 실행 — 영향받은 행 수 반환
 *
 * @param list<mixed> $params
 */
function db_execute(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * INSERT 후 마지막 auto_increment ID 반환
 *
 * @param list<mixed> $params
 */
function db_insert(string $sql, array $params = []): int
{
    db_execute($sql, $params);

    return (int) db()->lastInsertId();
}

/**
 * 트랜잭션 헬퍼 — $callback 안에서 예외 발생 시 롤백하고 예외를 다시 던집니다.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
/**
 * 트랜잭션 실행 — **중첩 안전**(2026-09-04).
 *
 * 이미 트랜잭션 안이면 새로 열지 않고 **합류**한다. 안 그러면 PDO 가
 * "There is already an active transaction" 로 죽어서, 트랜잭션 안에서 도는
 * 돈 이동 헬퍼(AgencyWallet::move 등)를 원자적으로 감쌀 수 없었다.
 * 합류 시 내부 예외는 그대로 위로 전파돼 **바깥 트랜잭션이 전체를 롤백**한다
 * (부분 커밋이 생기지 않는다).
 */
function db_transaction(callable $callback): mixed
{
    $pdo = db();

    // 이미 열린 트랜잭션에 합류 — begin/commit 은 최외곽에서만 한다.
    if ($pdo->inTransaction()) {
        return $callback();
    }

    $pdo->beginTransaction();
    try {
        $result = $callback();
        $pdo->commit();

        return $result;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * IN절 플레이스홀더 생성 헬퍼
 * 예: db_in([1,2,3]) → "?,?,?"
 *
 * @param list<mixed> $values
 */
function db_in(array $values): string
{
    if ($values === []) {
        throw new \InvalidArgumentException('db_in: 빈 배열은 사용할 수 없습니다.');
    }

    return implode(',', array_fill(0, count($values), '?'));
}

/**
 * 연결 상태 확인 (헬스체크용)
 */
function db_ping(): bool
{
    try {
        db()->query('SELECT 1');

        return true;
    } catch (\Throwable) {
        return false;
    }
}

/**
 * 현재 DB에 테이블 존재 여부 (SHOW TABLES LIKE ? 는 네이티브 prepare 미지원)
 *
 * ⚡ **요청 단위로 캐시한다.** 스키마는 한 요청 안에서 바뀌지 않는데, 도메인 코드가
 * 방어적으로 매번 호출하는 자리가 많다(`WithdrawalCycles::tableReady()`는 한 번에
 * information_schema를 2번 친다). DB가 원격이라 쿼리 1건이 곧 네트워크 왕복이고,
 * 목록 화면처럼 라이더 N명을 도는 경로에서 이 호출만 수백 번 쌓였다.
 *
 * ⚠️ 마이그레이션(`php migrate.php`)처럼 **같은 요청 안에서 테이블을 만드는** 코드는
 *    생성 직후 이 캐시를 비워야 한다 — `db_forget_table_exists()` 사용.
 */
function db_table_exists(string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    if (isset($GLOBALS['__db_table_exists'][$table])) {
        return $GLOBALS['__db_table_exists'][$table];
    }

    $exists = db_row(
        'SELECT 1 AS ok FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?
         LIMIT 1',
        [$table]
    ) !== null;

    // 없는 테이블은 캐시하지 않는다 — 마이그레이션이 같은 요청 안에서 만들 수 있고,
    // "없음"을 캐시해두면 그 뒤 검사가 계속 false 로 나와 생성 직후 로직이 건너뛰어진다.
    if ($exists) {
        $GLOBALS['__db_table_exists'][$table] = true;
    }

    return $exists;
}

/**
 * `db_table_exists()` 캐시 비우기 — 같은 요청 안에서 테이블을 **지운** 경우에만 필요하다.
 * (생성은 위에서 "없음"을 캐시하지 않으므로 따로 비울 필요가 없다.)
 */
function db_forget_table_exists(?string $table = null): void
{
    if ($table === null) {
        $GLOBALS['__db_table_exists'] = [];

        return;
    }
    unset($GLOBALS['__db_table_exists'][$table]);
}

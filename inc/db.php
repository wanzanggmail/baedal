<?php

declare(strict_types=1);

/**
 * DB 연결 설정
 * 환경 변수 > 아래 기본값 순으로 적용됩니다.
 * 운영: 프로젝트 루트 .env (inc/env.php) 또는 Apache SetEnv
 */
define('DB_HOST',    getenv('DB_HOST')    ?: '');
define('DB_PORT',    (int)(getenv('DB_PORT')    ?: 3306));
define('DB_NAME',    getenv('DB_NAME')    ?: 'my_web_db');
define('DB_USER',    getenv('DB_USER')    ?: 'dev_user');
define('DB_PASS',    getenv('DB_PASS')    ?: 'ehRoql1!');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

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
function db_transaction(callable $callback): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback();
        $pdo->commit();

        return $result;
    } catch (\Throwable $e) {
        $pdo->rollBack();
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
 */
function db_table_exists(string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    return db_row(
        'SELECT 1 AS ok FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?
         LIMIT 1',
        [$table]
    ) !== null;
}

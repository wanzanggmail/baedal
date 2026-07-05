<?php

declare(strict_types=1);

/**
 * DB 스키마 마이그레이션 (sql/*.sql + 점진적 ALTER)
 */
final class MigrateRunner
{
    public static function run(): void
    {
        require_once INC_PATH . '/AuditLog.php';

        self::runSqlFile('base_schema.sql');
        self::runSqlFile('content_tables.sql');
        self::runSqlFile('settlement_tables.sql');
        self::runSqlFile('settlement_ledger.sql');
        self::runSqlFile('settlement_excel_config.sql');
        self::runSqlFile('withdrawal_wallet.sql');
        self::runSqlFile('agency_fee_config.sql');
        self::runSqlFile('organizations.sql');

        self::migrateAgencyFeeColumns();
        self::migrateWithdrawalWalletExtras();
        self::migrateAuditLogs();
        self::migrateOrgColumns();
        self::migrateTenantConfig();

        echo "\n완료. (초기 데이터는 php seed.php)\n";
    }

    private static function runSqlFile(string $basename): void
    {
        $path = ROOT_PATH . '/sql/' . $basename;
        if (!is_file($path)) {
            echo "ERROR sql/{$basename} 없음\n";
            exit(1);
        }

        echo "== sql/{$basename} ==\n";
        $sql   = file_get_contents($path);
        $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];

        foreach ($parts as $stmt) {
            $stmt = trim(preg_replace('/--[^\r\n]*/', '', $stmt) ?? '');
            if ($stmt === '') {
                continue;
            }
            try {
                db_execute($stmt);
                if (preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/i', $stmt, $m)) {
                    echo "OK    {$m[1]}\n";
                } elseif (stripos($stmt, 'INSERT') === 0) {
                    echo "OK    insert\n";
                }
            } catch (Throwable $e) {
                echo 'ERROR → ' . $e->getMessage() . "\n";
                exit(1);
            }
        }
    }

    private static function migrateAgencyFeeColumns(): void
    {
        echo "== agency_fee columns ==\n";

        if (!db_table_exists('deduction_global_config')) {
            echo "SKIP  deduction_global_config (테이블 없음 — seed.php 먼저)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
        $adds = [
            'agency_fee_day_threshold' => "ADD COLUMN agency_fee_day_threshold SMALLINT UNSIGNED NOT NULL DEFAULT 7 COMMENT '적립 일수 기준' AFTER agency_fee_pct",
            'agency_fee_short'         => "ADD COLUMN agency_fee_short INT NOT NULL DEFAULT 80 COMMENT '기준 미만 건당(원)' AFTER agency_fee_day_threshold",
            'agency_fee_long'          => "ADD COLUMN agency_fee_long INT NOT NULL DEFAULT 40 COMMENT '기준 이상 건당(원)' AFTER agency_fee_short",
        ];

        foreach ($adds as $col => $alter) {
            if (in_array($col, $cols, true)) {
                echo "SKIP  deduction_global_config.{$col}\n";
                continue;
            }
            try {
                db_execute("ALTER TABLE deduction_global_config {$alter}");
                echo "OK    deduction_global_config.{$col}\n";
            } catch (Throwable $e) {
                echo "ERROR {$col} → " . $e->getMessage() . "\n";
                exit(1);
            }
        }

        $exists = db_row('SELECT id FROM deduction_global_config WHERE id = 1 LIMIT 1');
        if ($exists === null) {
            db_insert(
                'INSERT INTO deduction_global_config
                    (id, withholding_tax_pct, employment_ins_pct, agency_fee_pct,
                     agency_fee_day_threshold, agency_fee_short, agency_fee_long)
                 VALUES (1, 3.30, 9.12, 0, 7, 80, 40)'
            );
            echo "OK    deduction_global_config row\n";
        }
    }

    private static function migrateWithdrawalWalletExtras(): void
    {
        echo "== withdrawal wallet extras ==\n";

        if (db_table_exists('withdrawal_requests')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
            if (!in_array('accrued_days', $cols, true)) {
                try {
                    db_execute(
                        'ALTER TABLE withdrawal_requests
                         ADD COLUMN accrued_days SMALLINT UNSIGNED NOT NULL DEFAULT 0
                         COMMENT \'신청 시점 적립 일수\' AFTER withhold_round_trim'
                    );
                    echo "OK    withdrawal_requests.accrued_days\n";
                } catch (Throwable $e) {
                    echo 'ERROR accrued_days → ' . $e->getMessage() . "\n";
                    exit(1);
                }
            } else {
                echo "SKIP  withdrawal_requests.accrued_days\n";
            }
        }

        if (db_table_exists('riders') && db_table_exists('rider_wallets')) {
            $missing = (int) (db_row(
                'SELECT COUNT(*) AS c FROM riders r
                 LEFT JOIN rider_wallets w ON w.rider_id = r.id
                 WHERE w.rider_id IS NULL'
            )['c'] ?? 0);
            if ($missing > 0) {
                db_execute(
                    'INSERT INTO rider_wallets (rider_id, balance, accrued_days)
                     SELECT r.id, 0, 0 FROM riders r
                     LEFT JOIN rider_wallets w ON w.rider_id = r.id
                     WHERE w.rider_id IS NULL'
                );
                echo "OK    rider_wallets backfill ({$missing})\n";
            } else {
                echo "SKIP  rider_wallets backfill\n";
            }
        }
    }

    /**
     * 멀티테넌시 — 기존 테이블에 조직 소속 컬럼 추가 (멱등)
     * organizations.sql 이후 실행. FK는 생략(앱 레벨 정합성 + 인덱스).
     */
    private static function migrateOrgColumns(): void
    {
        echo "== org columns ==\n";

        $specs = [
            ['admins',             'org_id',    "ADD COLUMN org_id INT UNSIGNED NULL COMMENT '소속 조직(organizations.id)' AFTER role, ADD KEY idx_admins_org (org_id)"],
            ['riders',             'agency_id', "ADD COLUMN agency_id INT UNSIGNED NULL COMMENT '소속 대리점(organizations.id, level=agency)' AFTER team_code, ADD KEY idx_riders_agency (agency_id)"],
            ['settlement_uploads', 'agency_id', "ADD COLUMN agency_id INT UNSIGNED NULL COMMENT '업로드 소유 대리점' AFTER platform, ADD KEY idx_su_agency (agency_id)"],
            ['content_notices',    'org_id',    "ADD COLUMN org_id INT UNSIGNED NULL COMMENT '작성 조직(broadcast 기준)' AFTER category, ADD KEY idx_notice_org (org_id)"],
            ['content_banners',    'org_id',    "ADD COLUMN org_id INT UNSIGNED NULL COMMENT '작성 조직(broadcast 기준)' AFTER slot, ADD KEY idx_banner_org (org_id)"],
        ];

        foreach ($specs as [$table, $col, $alter]) {
            if (!db_table_exists($table)) {
                echo "SKIP  {$table} (테이블 없음)\n";
                continue;
            }
            $cols = array_column(db_rows("SHOW COLUMNS FROM {$table}"), 'Field');
            if (in_array($col, $cols, true)) {
                echo "SKIP  {$table}.{$col}\n";
                continue;
            }
            try {
                db_execute("ALTER TABLE {$table} {$alter}");
                echo "OK    {$table}.{$col}\n";
            } catch (Throwable $e) {
                echo "ERROR {$table}.{$col} → " . $e->getMessage() . "\n";
                exit(1);
            }
        }
    }

    /**
     * 멀티테넌시 — 싱글톤 설정 테이블을 조직별로 전환 (멱등)
     * org_id NULL = 전역 기본값, 대리점별 행은 distinct org_id.
     */
    private static function migrateTenantConfig(): void
    {
        echo "== tenant config (org_id) ==\n";

        // id=1 싱글톤 → AUTO_INCREMENT + org_id (NULL=전역 기본)
        foreach (['withdrawal_config', 'deduction_global_config'] as $table) {
            if (!db_table_exists($table)) {
                echo "SKIP  {$table} (테이블 없음)\n";
                continue;
            }
            $cols = array_column(db_rows("SHOW COLUMNS FROM {$table}"), 'Field');
            if (in_array('org_id', $cols, true)) {
                echo "SKIP  {$table}.org_id\n";
                continue;
            }
            try {
                db_execute(
                    "ALTER TABLE {$table}
                        MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        ADD COLUMN org_id INT UNSIGNED NULL COMMENT '대리점 조직(NULL=전역 기본)' AFTER id,
                        ADD UNIQUE KEY `uq_{$table}_org` (`org_id`)"
                );
                echo "OK    {$table}.org_id\n";
            } catch (Throwable $e) {
                echo "ERROR {$table}.org_id → " . $e->getMessage() . "\n";
                exit(1);
            }
        }

        // settlement_excel_config: platform PK → 대리점 surrogate id + (org_id, platform) unique
        if (db_table_exists('settlement_excel_config')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_excel_config'), 'Field');
            if (in_array('org_id', $cols, true)) {
                echo "SKIP  settlement_excel_config.org_id\n";
            } else {
                try {
                    db_execute(
                        "ALTER TABLE settlement_excel_config
                            DROP PRIMARY KEY,
                            ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                            ADD PRIMARY KEY (id),
                            ADD COLUMN org_id INT UNSIGNED NULL COMMENT '대리점 조직(NULL=전역 기본)' AFTER platform,
                            ADD UNIQUE KEY `uq_sec_org_pf` (`org_id`, `platform`)"
                    );
                    echo "OK    settlement_excel_config.org_id\n";
                } catch (Throwable $e) {
                    echo "ERROR settlement_excel_config.org_id → " . $e->getMessage() . "\n";
                    exit(1);
                }
            }
        }
    }

    private static function migrateAuditLogs(): void
    {
        echo "== audit_logs ==\n";

        if (AuditLog::tableExists()) {
            $cnt = (int) (db_row('SELECT COUNT(*) AS c FROM audit_logs')['c'] ?? 0);
            echo "SKIP  audit_logs ({$cnt}건)\n";

            return;
        }

        self::runSqlFile('audit_tables.sql');
    }
}

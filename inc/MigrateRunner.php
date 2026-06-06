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

        self::migrateAgencyFeeColumns();
        self::migrateWithdrawalWalletExtras();
        self::migrateAuditLogs();

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

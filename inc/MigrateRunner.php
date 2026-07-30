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
        self::runSqlFile('redesign_wallet.sql');
        self::runSqlFile('redesign_gateway.sql');
        self::runSqlFile('redesign_settlement_detail.sql');
        self::runSqlFile('rider_debts.sql');
        self::runSqlFile('withdrawal_cycles.sql');
        self::runSqlFile('settlement_support.sql');

        self::migrateAgencyFeeColumns();
        self::migrateWithdrawalWalletExtras();
        self::migrateAuditLogs();
        self::migrateOrgColumns();
        self::migrateTenantConfig();
        self::migrateWithdrawalRedesign();
        self::migrateDeductionSplit();
        self::migrateRiderWithholdingFlag();
        self::migrateAgencyWalletBackfill();
        self::migrateOrgFeeBackfill();
        self::migrateHourlyInsuranceColumn();
        self::migrateDeductionRegisterColumn();
        self::migrateDailyRidersUniqueDate();
        self::migrateCycleWithdrawnColumn();
        self::migrateSupportAmountColumn();
        self::migrateCycleSupportAmountColumn();
        self::migrateLeasePlannedEndColumn();
        self::migrateDebtEntryUniqueKey();

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
                // 고용보험 기본 0.80 (산재 컬럼은 migrateDeductionSplit에서 추가)
                'INSERT INTO deduction_global_config
                    (id, withholding_tax_pct, employment_ins_pct, agency_fee_pct,
                     agency_fee_day_threshold, agency_fee_short, agency_fee_long)
                 VALUES (1, 3.30, 0.80, 0, 7, 80, 40)'
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

    /**
     * §7 #1 — 대리점 자체 인출(agency_payout) 저장을 위한 withdrawal_requests 스키마 조정.
     * rider_id를 nullable로(대리점 인출은 라이더 없음), agency_id·fail_reason 추가,
     * kind에 agency_payout, status에 failed 추가. (dev 데이터 0건, 멱등)
     */
    private static function migrateWithdrawalRedesign(): void
    {
        echo "== withdrawal redesign (agency_payout/failed) ==\n";

        if (!db_table_exists('withdrawal_requests')) {
            echo "SKIP  withdrawal_requests (테이블 없음)\n";

            return;
        }

        $cols     = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
        $riderCol = db_row("SHOW COLUMNS FROM withdrawal_requests LIKE 'rider_id'");

        if ($riderCol !== null && strtoupper((string) ($riderCol['Null'] ?? '')) === 'NO') {
            db_execute('ALTER TABLE withdrawal_requests MODIFY COLUMN rider_id INT UNSIGNED NULL COMMENT \'라이더(대리점 자체 인출이면 NULL)\'');
            echo "OK    withdrawal_requests.rider_id → NULL\n";
        } else {
            echo "SKIP  withdrawal_requests.rider_id nullable\n";
        }

        if (!in_array('agency_id', $cols, true)) {
            db_execute(
                "ALTER TABLE withdrawal_requests
                 ADD COLUMN agency_id INT UNSIGNED NULL COMMENT '대리점 자체 인출 소유 대리점(organizations.id)' AFTER rider_id,
                 ADD KEY idx_wr_agency (agency_id)"
            );
            echo "OK    withdrawal_requests.agency_id\n";
        } else {
            echo "SKIP  withdrawal_requests.agency_id\n";
        }

        if (!in_array('fail_reason', $cols, true)) {
            db_execute(
                "ALTER TABLE withdrawal_requests
                 ADD COLUMN fail_reason VARCHAR(300) NOT NULL DEFAULT '' COMMENT '오픈뱅킹 이체 실패 사유' AFTER rejected_reason"
            );
            echo "OK    withdrawal_requests.fail_reason\n";
        } else {
            echo "SKIP  withdrawal_requests.fail_reason\n";
        }

        // enum 확장 (MODIFY는 반복 실행에 안전)
        db_execute("ALTER TABLE withdrawal_requests MODIFY COLUMN kind ENUM('rider_manual','auto_daily','agency_payout') NOT NULL DEFAULT 'rider_manual'");
        db_execute("ALTER TABLE withdrawal_requests MODIFY COLUMN status ENUM('pending','downloaded','completed','rejected','failed') NOT NULL DEFAULT 'pending'");
        echo "OK    withdrawal_requests.kind/status enum\n";
    }

    /**
     * §7 #4 — 고용보험(0.8%)·산재보험(0.88%) 컬럼 분리. 기존 employment_ins_pct는
     * 합산 placeholder(9.12)였음 — 고용 전용(0.80)으로 교정하고 산재 컬럼 신규.
     */
    private static function migrateDeductionSplit(): void
    {
        echo "== deduction 고용/산재 분리 ==\n";

        if (!db_table_exists('deduction_global_config')) {
            echo "SKIP  deduction_global_config (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
        if (in_array('industrial_accident_ins_pct', $cols, true)) {
            echo "SKIP  deduction_global_config.industrial_accident_ins_pct\n";

            return;
        }

        db_execute(
            "ALTER TABLE deduction_global_config
             ADD COLUMN industrial_accident_ins_pct DECIMAL(5,2) NOT NULL DEFAULT 0.88 COMMENT '산재보험료율(%)' AFTER employment_ins_pct"
        );
        db_execute("ALTER TABLE deduction_global_config MODIFY COLUMN employment_ins_pct DECIMAL(5,2) NOT NULL DEFAULT 0.80 COMMENT '고용보험료율(%)'");
        // 전역 기본행이 옛 합산값(>2%)이면 0.80/0.88로 교정 (per-agency 의도값은 건드리지 않음)
        db_execute('UPDATE deduction_global_config SET employment_ins_pct = 0.80, industrial_accident_ins_pct = 0.88 WHERE org_id IS NULL AND employment_ins_pct > 2.00');
        echo "OK    deduction_global_config.industrial_accident_ins_pct (+전역 교정)\n";
    }

    /**
     * §7 #15 — 원천세 공제 대상 여부를 라이더별로 설정(대리점이 상세화면에서 토글).
     */
    private static function migrateRiderWithholdingFlag(): void
    {
        echo "== riders.withholding_tax_enabled ==\n";

        if (!db_table_exists('riders')) {
            echo "SKIP  riders (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        if (in_array('withholding_tax_enabled', $cols, true)) {
            echo "SKIP  riders.withholding_tax_enabled\n";

            return;
        }

        db_execute(
            "ALTER TABLE riders
             ADD COLUMN withholding_tax_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '원천세 공제 대상(대리점 설정)' AFTER is_daily_settlement"
        );
        echo "OK    riders.withholding_tax_enabled\n";
    }

    /**
     * §7 #9 — 대리점(agency) 조직마다 지갑 1행 보장.
     */
    private static function migrateAgencyWalletBackfill(): void
    {
        echo "== agency_wallets backfill ==\n";

        if (!db_table_exists('agency_wallets') || !db_table_exists('organizations')) {
            echo "SKIP  agency_wallets backfill (테이블 없음)\n";

            return;
        }

        $missing = (int) (db_row(
            "SELECT COUNT(*) AS c FROM organizations o
             LEFT JOIN agency_wallets w ON w.agency_id = o.id
             WHERE o.level = 'agency' AND w.agency_id IS NULL"
        )['c'] ?? 0);

        if ($missing > 0) {
            db_execute(
                "INSERT INTO agency_wallets (agency_id, balance, withholding_reserve)
                 SELECT o.id, 0, 0 FROM organizations o
                 LEFT JOIN agency_wallets w ON w.agency_id = o.id
                 WHERE o.level = 'agency' AND w.agency_id IS NULL"
            );
            echo "OK    agency_wallets backfill ({$missing})\n";
        } else {
            echo "SKIP  agency_wallets backfill\n";
        }
    }

    /**
     * §7 #12 — 모든 조직(본사·총판·대리점)에 영업대행수수료 요율 행 보장(기본 1.00%).
     */
    private static function migrateOrgFeeBackfill(): void
    {
        echo "== org_fee_config backfill ==\n";

        if (!db_table_exists('org_fee_config') || !db_table_exists('organizations')) {
            echo "SKIP  org_fee_config backfill (테이블 없음)\n";

            return;
        }

        $missing = (int) (db_row(
            'SELECT COUNT(*) AS c FROM organizations o
             LEFT JOIN org_fee_config f ON f.org_id = o.id
             WHERE f.org_id IS NULL'
        )['c'] ?? 0);

        if ($missing > 0) {
            db_execute(
                'INSERT INTO org_fee_config (org_id, pg_service_fee_pct)
                 SELECT o.id, 1.00 FROM organizations o
                 LEFT JOIN org_fee_config f ON f.org_id = o.id
                 WHERE f.org_id IS NULL'
            );
            echo "OK    org_fee_config backfill ({$missing})\n";
        } else {
            echo "SKIP  org_fee_config backfill\n";
        }
    }

    /**
     * §7 #6 — 시간제보험(쿠팡 정산서 파일에 포함된 값)을 담을 컬럼.
     * 계산이 아니라 파일에서 파싱해 넣는 값(파서 매핑은 실 자료 도착 시 확정).
     */
    private static function migrateHourlyInsuranceColumn(): void
    {
        echo "== settlement_daily_riders.hourly_insurance ==\n";

        if (!db_table_exists('settlement_daily_riders')) {
            echo "SKIP  settlement_daily_riders (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_daily_riders'), 'Field');
        if (in_array('hourly_insurance', $cols, true)) {
            echo "SKIP  settlement_daily_riders.hourly_insurance\n";

            return;
        }
        db_execute(
            "ALTER TABLE settlement_daily_riders
             ADD COLUMN hourly_insurance INT NOT NULL DEFAULT 0 COMMENT '시간제보험(파일 파싱값, 계산 아님)'"
        );
        echo "OK    settlement_daily_riders.hourly_insurance\n";
    }

    /**
     * §7 — 업로드된 차감내역(settlement_weekly_deductions)을 deduction_entries로 "등록"한
     * 이력 추적. 중복 등록 방지용 FK-less 참조 컬럼.
     */
    private static function migrateDeductionRegisterColumn(): void
    {
        echo "== settlement_weekly_deductions.registered_entry_id ==\n";

        if (!db_table_exists('settlement_weekly_deductions')) {
            echo "SKIP  settlement_weekly_deductions (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_weekly_deductions'), 'Field');
        if (in_array('registered_entry_id', $cols, true)) {
            echo "SKIP  settlement_weekly_deductions.registered_entry_id\n";

            return;
        }
        db_execute(
            "ALTER TABLE settlement_weekly_deductions
             ADD COLUMN registered_entry_id INT UNSIGNED NULL COMMENT 'deduction_entries.id (등록됨)' AFTER amount,
             ADD KEY idx_swd_registered (registered_entry_id)"
        );
        echo "OK    settlement_weekly_deductions.registered_entry_id\n";
    }

    /**
     * 배민 대응 — 한 업로드가 여러 날짜(운행일)를 포함할 수 있으므로
     * settlement_daily_riders UNIQUE를 (upload_id, license_id) → (upload_id, license_id, settlement_date)로 확장.
     * 쿠팡은 upload당 단일 날짜라 영향 없음(멱등).
     */
    private static function migrateDailyRidersUniqueDate(): void
    {
        echo "== settlement_daily_riders UNIQUE(+date) ==\n";

        if (!db_table_exists('settlement_daily_riders')) {
            echo "SKIP  settlement_daily_riders (테이블 없음)\n";

            return;
        }

        // 새 유니크가 이미 있으면 skip
        $idx = db_rows('SHOW INDEX FROM settlement_daily_riders');
        $hasNew = false;
        $hasOld = false;
        foreach ($idx as $r) {
            if (($r['Key_name'] ?? '') === 'uq_sdr_upload_license_date') {
                $hasNew = true;
            }
            if (($r['Key_name'] ?? '') === 'uq_sdr_upload_license') {
                $hasOld = true;
            }
        }
        if ($hasNew) {
            echo "SKIP  uq_sdr_upload_license_date (이미 있음)\n";

            return;
        }

        try {
            $sql = 'ALTER TABLE settlement_daily_riders ';
            if ($hasOld) {
                $sql .= 'DROP INDEX uq_sdr_upload_license, ';
            }
            $sql .= 'ADD UNIQUE KEY uq_sdr_upload_license_date (upload_id, license_id, settlement_date)';
            db_execute($sql);
            echo "OK    uq_sdr_upload_license_date\n";
        } catch (Throwable $e) {
            echo 'ERROR uq date → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * §7 #18 — 정산 사이클별 출금 추적 컬럼.
     * withdrawn_amount 는 부분출금까지 표현 가능(0=미출금, net_amount=완전출금).
     */
    private static function migrateCycleWithdrawnColumn(): void
    {
        echo "== settlement_rider_cycles.withdrawn_amount ==\n";

        if (!db_table_exists('settlement_rider_cycles')) {
            echo "SKIP  settlement_rider_cycles (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field');
        if (in_array('withdrawn_amount', $cols, true)) {
            echo "SKIP  withdrawn_amount (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE settlement_rider_cycles
                    ADD COLUMN withdrawn_amount INT NOT NULL DEFAULT 0
                        COMMENT '출금 완료/예약된 금액. net_amount와 같으면 완전 출금' AFTER net_amount,
                    ADD KEY idx_src_rider_withdrawn (rider_id, withdrawn_amount)"
            );
            echo "OK    withdrawn_amount + idx_src_rider_withdrawn\n";
        } catch (Throwable $e) {
            echo 'ERROR withdrawn_amount → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 지원금/추가지원금 합계 — settlement_daily_riders에 채워 net_amount 계산 시 가산.
     * parser.py 확인 결과 지원금은 정산금액과 별개로 존재하고 최종 지급액에 +가산된다.
     */
    private static function migrateSupportAmountColumn(): void
    {
        echo "== settlement_daily_riders.support_amount ==\n";

        if (!db_table_exists('settlement_daily_riders')) {
            echo "SKIP  settlement_daily_riders (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_daily_riders'), 'Field');
        if (in_array('support_amount', $cols, true)) {
            echo "SKIP  support_amount (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE settlement_daily_riders
                    ADD COLUMN support_amount INT NOT NULL DEFAULT 0
                        COMMENT '지원금+추가지원금 합계(정산금액과 별개, 지급액에 가산)' AFTER hourly_insurance"
            );
            echo "OK    support_amount\n";
        } catch (Throwable $e) {
            echo 'ERROR support_amount → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /** 정산 사이클에 지원금 합계를 남겨 net_amount 산출 근거를 명시적으로 보여준다. */
    private static function migrateCycleSupportAmountColumn(): void
    {
        echo "== settlement_rider_cycles.support_amount ==\n";

        if (!db_table_exists('settlement_rider_cycles')) {
            echo "SKIP  settlement_rider_cycles (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field');
        if (in_array('support_amount', $cols, true)) {
            echo "SKIP  support_amount (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE settlement_rider_cycles
                    ADD COLUMN support_amount INT NOT NULL DEFAULT 0
                        COMMENT '지원금+추가지원금 합계(gross_amount에 가산돼 net_amount 계산에 반영됨)' AFTER gross_amount"
            );
            echo "OK    support_amount\n";
        } catch (Throwable $e) {
            echo 'ERROR support_amount → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 리스/렌탈 계약 종료 예정일 — parser.py는 "출고일~종료일"로 계약기간을 정의하고
     * 정산기간과 겹치는 일수를 자동 계산해 차감한다. 우리는 opened_on(출고일)만 있어서
     * 종료일 관리가 안 됐음.
     */
    private static function migrateLeasePlannedEndColumn(): void
    {
        echo "== rider_debts.planned_end_on ==\n";

        if (!db_table_exists('rider_debts')) {
            echo "SKIP  rider_debts (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM rider_debts'), 'Field');
        if (in_array('planned_end_on', $cols, true)) {
            echo "SKIP  planned_end_on (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE rider_debts
                    ADD COLUMN planned_end_on DATE NULL
                        COMMENT '계약 종료 예정일(리스/렌탈). opened_on과 함께 계약기간을 이룸' AFTER opened_on"
            );
            echo "OK    planned_end_on\n";
        } catch (Throwable $e) {
            echo 'ERROR planned_end_on → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 부채 차감 재실행 멱등성 — 같은 정산(같은 귀속일)을 다시 반영해도 이중 차감되지 않도록
     * (debt_id, applied_date) UNIQUE. parser.py의 "처리키" 기반 재실행 방지와 같은 목적.
     */
    private static function migrateDebtEntryUniqueKey(): void
    {
        echo "== rider_debt_entries UNIQUE(debt_id, applied_date) ==\n";

        if (!db_table_exists('rider_debt_entries')) {
            echo "SKIP  rider_debt_entries (테이블 없음)\n";

            return;
        }

        $idx = db_rows('SHOW INDEX FROM rider_debt_entries');
        foreach ($idx as $r) {
            if (($r['Key_name'] ?? '') === 'uq_rde_debt_applied') {
                echo "SKIP  uq_rde_debt_applied (이미 있음)\n";

                return;
            }
        }

        // 기존 데이터에 중복(debt_id, applied_date)이 있으면 UNIQUE 추가가 실패하므로 먼저 점검.
        $dup = db_row(
            "SELECT debt_id, applied_date, COUNT(*) c
               FROM rider_debt_entries
              GROUP BY debt_id, applied_date
             HAVING c > 1
              LIMIT 1"
        );
        if ($dup !== null) {
            echo "SKIP  uq_rde_debt_applied (기존 중복 데이터 존재 — 수동 정리 후 재실행 필요)\n";

            return;
        }

        try {
            db_execute('ALTER TABLE rider_debt_entries ADD UNIQUE KEY uq_rde_debt_applied (debt_id, applied_date)');
            echo "OK    uq_rde_debt_applied\n";
        } catch (Throwable $e) {
            echo 'ERROR uq_rde_debt_applied → ' . $e->getMessage() . "\n";
            exit(1);
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

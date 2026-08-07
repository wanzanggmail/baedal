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
        self::runSqlFile('promotions.sql');
        self::runSqlFile('role_permissions.sql');

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
        self::migrateUploadTeamRegionColumns();
        self::migrateCycleTeamRegion();
        self::migrateTeamRegionNormalize();
        self::migrateRiderPlatformMultiId();
        self::migrateRiderMustChangePassword();
        self::migratePlatformFeeSplit();
        self::migrateAdminManagerRole();
        self::migratePgPaymentFeeSplit();

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

    /**
     * 팀지역별 업로드 분리 — 한 대리점이 같은 날 여러 팀지역 정산서를 올릴 수 있어야 한다.
     * 기존엔 팀/지역이 stored_path JSON에만 들어 있어 조회·중복판정에 쓸 수 없었다.
     */
    private static function migrateUploadTeamRegionColumns(): void
    {
        echo "== settlement_uploads.team_name / region_name ==\n";

        if (!db_table_exists('settlement_uploads')) {
            echo "SKIP  settlement_uploads (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_uploads'), 'Field');
        if (in_array('team_name', $cols, true) && in_array('region_name', $cols, true)) {
            echo "SKIP  team_name/region_name (이미 있음)\n";

            return;
        }

        try {
            if (!in_array('team_name', $cols, true)) {
                db_execute(
                    "ALTER TABLE settlement_uploads
                        ADD COLUMN team_name VARCHAR(60) NOT NULL DEFAULT ''
                            COMMENT '팀명(정산서 기준). 같은 날 여러 팀지역 업로드 구분용' AFTER agency_id"
                );
                echo "OK    team_name\n";
            }
            if (!in_array('region_name', $cols, true)) {
                db_execute(
                    "ALTER TABLE settlement_uploads
                        ADD COLUMN region_name VARCHAR(60) NOT NULL DEFAULT ''
                            COMMENT '지역명(정산서 기준)' AFTER team_name"
                );
                echo "OK    region_name\n";
            }

            // 기존 행의 stored_path JSON에 들어 있던 team/region을 새 컬럼으로 승격(백필)
            $filled = 0;
            foreach (db_rows("SELECT id, stored_path FROM settlement_uploads WHERE team_name = '' AND region_name = ''") as $row) {
                $meta = json_decode((string) ($row['stored_path'] ?? ''), true);
                if (!is_array($meta)) {
                    continue;
                }
                $team   = trim((string) ($meta['team'] ?? ''));
                $region = trim((string) ($meta['region'] ?? ''));
                if ($team === '' && $region === '') {
                    continue;
                }
                db_execute(
                    'UPDATE settlement_uploads SET team_name = ?, region_name = ? WHERE id = ?',
                    [mb_substr($team, 0, 60), mb_substr($region, 0, 60), (int) $row['id']]
                );
                $filled++;
            }
            echo "OK    기존 {$filled}건 팀/지역 백필\n";

            $idx = array_column(db_rows('SHOW INDEX FROM settlement_uploads'), 'Key_name');
            if (!in_array('idx_su_agency_date_team', $idx, true)) {
                db_execute(
                    'ALTER TABLE settlement_uploads
                        ADD KEY idx_su_agency_date_team (agency_id, settlement_date, team_name, region_name)'
                );
                echo "OK    idx_su_agency_date_team\n";
            }
        } catch (Throwable $e) {
            echo 'ERROR team/region → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 정산 사이클을 팀지역 단위로 분리 — 한 라이더가 같은 날 두 팀지역에서 일하면 사이클 2건.
     * 기존 UNIQUE(rider_id, settlement_date, platform)로는 두 번째가 조용히 skip 됐다.
     */
    private static function migrateCycleTeamRegion(): void
    {
        echo "== settlement_rider_cycles.team_region ==\n";

        if (!db_table_exists('settlement_rider_cycles')) {
            echo "SKIP  settlement_rider_cycles (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field');
        $idx  = array_column(db_rows('SHOW INDEX FROM settlement_rider_cycles'), 'Key_name');

        if (in_array('team_region', $cols, true) && in_array('uq_src_rider_date_pf_team', $idx, true)) {
            echo "SKIP  team_region + UNIQUE (이미 있음)\n";

            return;
        }

        try {
            if (!in_array('team_region', $cols, true)) {
                db_execute(
                    "ALTER TABLE settlement_rider_cycles
                        ADD COLUMN team_region VARCHAR(120) NOT NULL DEFAULT ''
                            COMMENT '팀지역(업로드의 team_name/region_name 결합). 같은 날 다른 팀지역 정산을 분리' AFTER platform"
                );
                echo "OK    team_region\n";
            }

            // 기존 사이클에 업로드의 팀지역 백필(업로드가 지워진 건은 빈 값 유지 → 기존 동작과 동일)
            $n = db_execute(
                "UPDATE settlement_rider_cycles c
                   INNER JOIN settlement_uploads u ON u.id = c.upload_id
                    SET c.team_region = TRIM(CONCAT(u.team_name, ' ', u.region_name))
                  WHERE c.team_region = ''"
            );
            echo "OK    기존 {$n}건 team_region 백필\n";

            if (in_array('uq_src_rider_date_pf', $idx, true)) {
                db_execute('ALTER TABLE settlement_rider_cycles DROP INDEX uq_src_rider_date_pf');
                echo "OK    기존 UNIQUE 제거\n";
            }
            if (!in_array('uq_src_rider_date_pf_team', $idx, true)) {
                db_execute(
                    'ALTER TABLE settlement_rider_cycles
                        ADD UNIQUE KEY uq_src_rider_date_pf_team (rider_id, settlement_date, platform, team_region)'
                );
                echo "OK    uq_src_rider_date_pf_team\n";
            }
        } catch (Throwable $e) {
            echo 'ERROR team_region → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 팀/지역명 유니코드 정규화 백필 — 조합형(NFD)과 완성형(NFC)이 섞여 들어와
     * "눈에는 같은데 다른 값"이 되어 팀지역 UNIQUE가 무력화되는 것을 막는다.
     * 저장 시점 정규화는 settlement_upload.php에서 하고, 여기서는 기존 데이터를 정리한다.
     */
    private static function migrateTeamRegionNormalize(): void
    {
        echo "== 팀/지역명 유니코드 정규화(NFD→NFC) ==\n";

        if (!db_table_exists('settlement_uploads')) {
            echo "SKIP  settlement_uploads (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_uploads'), 'Field');
        if (!in_array('team_name', $cols, true)) {
            echo "SKIP  team_name 컬럼 없음\n";

            return;
        }

        try {
            $fixed = 0;
            foreach (db_rows('SELECT id, team_name, region_name FROM settlement_uploads') as $r) {
                $t = normalize_hangul_nfc((string) $r['team_name']);
                $g = normalize_hangul_nfc((string) $r['region_name']);
                if ($t !== (string) $r['team_name'] || $g !== (string) $r['region_name']) {
                    db_execute('UPDATE settlement_uploads SET team_name = ?, region_name = ? WHERE id = ?', [$t, $g, (int) $r['id']]);
                    $fixed++;
                }
            }
            echo "OK    uploads {$fixed}건 정규화\n";

            if (db_table_exists('settlement_rider_cycles')
                && in_array('team_region', array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field'), true)) {
                $fixedC = 0;
                foreach (db_rows("SELECT id, team_region FROM settlement_rider_cycles WHERE team_region <> ''") as $r) {
                    $v = normalize_hangul_nfc((string) $r['team_region']);
                    if ($v !== (string) $r['team_region']) {
                        db_execute('UPDATE settlement_rider_cycles SET team_region = ? WHERE id = ?', [$v, (int) $r['id']]);
                        $fixedC++;
                    }
                }
                echo "OK    cycles {$fixedC}건 정규화\n";
            }
        } catch (Throwable $e) {
            // 정규화 후 같은 (rider,date,platform,team_region)이 겹치면 UNIQUE 위반 → 수동 확인 필요
            echo 'ERROR 팀지역 정규화 → ' . $e->getMessage() . "\n";
            echo "      (정규화 시 중복이 생기는 사이클이 있습니다. 해당 건을 확인 후 정리하세요.)\n";
            exit(1);
        }
    }

    /**
     * 쿠팡 ID 다중 보유 — 한 라이더가 팀지역별로 여러 쿠팡ID를 가질 수 있다.
     * 기존 코드는 플랫폼당 1개만 두고 덮어썼는데, 정식으로 여러 행을 허용하되
     * **완전히 같은 (라이더, 플랫폼, ID)** 중복만 UNIQUE로 막는다.
     */
    private static function migrateRiderPlatformMultiId(): void
    {
        echo "== rider_platforms UNIQUE(rider, platform, external_id) ==\n";

        if (!db_table_exists('rider_platforms')) {
            echo "SKIP  rider_platforms (테이블 없음)\n";

            return;
        }

        $idx = array_column(db_rows('SHOW INDEX FROM rider_platforms'), 'Key_name');
        if (in_array('uq_rp_rider_pf_ext', $idx, true)) {
            echo "SKIP  uq_rp_rider_pf_ext (이미 있음)\n";

            return;
        }

        // 기존 중복 정리(같은 라이더·플랫폼·ID가 여러 행이면 가장 오래된 것만 남김)
        $dups = db_rows(
            'SELECT rider_id, platform, external_id, COUNT(*) c, MIN(id) keep_id
               FROM rider_platforms
              GROUP BY rider_id, platform, external_id
             HAVING c > 1'
        );
        foreach ($dups as $d) {
            db_execute(
                'DELETE FROM rider_platforms
                  WHERE rider_id = ? AND platform = ? AND external_id = ? AND id <> ?',
                [(int) $d['rider_id'], (string) $d['platform'], (string) $d['external_id'], (int) $d['keep_id']]
            );
        }
        if ($dups !== []) {
            echo 'OK    중복 ' . count($dups) . "종 정리\n";
        }

        try {
            db_execute('ALTER TABLE rider_platforms ADD UNIQUE KEY uq_rp_rider_pf_ext (rider_id, platform, external_id)');
            echo "OK    uq_rp_rider_pf_ext\n";
        } catch (Throwable $e) {
            echo 'ERROR uq_rp_rider_pf_ext → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /** 라이더 최초 로그인 시 비밀번호 강제 변경 플래그 */
    private static function migrateRiderMustChangePassword(): void
    {
        echo "== riders.must_change_password ==\n";

        if (!db_table_exists('riders')) {
            echo "SKIP  riders (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        if (in_array('must_change_password', $cols, true)) {
            echo "SKIP  must_change_password (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE riders
                    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT '1=초기 비밀번호(0000) 상태. 최초 로그인 시 변경 강제' AFTER password_hash"
            );
            echo "OK    must_change_password\n";
        } catch (Throwable $e) {
            echo 'ERROR must_change_password → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * 플랫폼 수수료(구 영업대행수수료) — 대리점 기준 3분할.
     * 기존: 조직마다 "내 몫" 1개(본사 1개·총판 1개를 모든 하위가 공유).
     * 변경: **대리점마다** 본사/총판/대리점 몫을 각각 설정(대리점별로 다르게 줄 수 있음).
     */
    private static function migratePlatformFeeSplit(): void
    {
        echo "== org_fee_config 대리점별 3분할 ==\n";

        if (!db_table_exists('org_fee_config')) {
            echo "SKIP  org_fee_config (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM org_fee_config'), 'Field');
        $need = ['hq_pct', 'distributor_pct', 'agency_pct'];
        if (count(array_intersect($need, $cols)) === count($need)) {
            echo "SKIP  hq/distributor/agency_pct (이미 있음)\n";

            return;
        }

        try {
            foreach ([
                'hq_pct'          => '본사 몫(%)',
                'distributor_pct' => '총판 몫(%)',
                'agency_pct'      => '대리점 몫(%)',
            ] as $col => $comment) {
                if (in_array($col, $cols, true)) {
                    continue;
                }
                db_execute(
                    "ALTER TABLE org_fee_config
                        ADD COLUMN {$col} DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '{$comment}'"
                );
                echo "OK    {$col}\n";
            }

            // 기존 값 이관: 대리점 행마다 본사/상위총판/자기 pct를 조회해 3개 컬럼으로 펼친다.
            if (!db_table_exists('organizations')) {
                echo "SKIP  백필 (organizations 없음)\n";

                return;
            }
            $hqPct = 0.0;
            foreach (db_rows(
                "SELECT f.pg_service_fee_pct p FROM org_fee_config f
                   INNER JOIN organizations o ON o.id = f.org_id
                  WHERE o.level = 'admin' LIMIT 1"
            ) as $r) {
                $hqPct = (float) $r['p'];
            }
            $distPct = [];
            foreach (db_rows(
                "SELECT f.org_id, f.pg_service_fee_pct p FROM org_fee_config f
                   INNER JOIN organizations o ON o.id = f.org_id
                  WHERE o.level = 'distributor'"
            ) as $r) {
                $distPct[(int) $r['org_id']] = (float) $r['p'];
            }

            $n = 0;
            foreach (db_rows(
                "SELECT o.id, o.parent_id, COALESCE(f.pg_service_fee_pct, 0) p
                   FROM organizations o
                   LEFT JOIN org_fee_config f ON f.org_id = o.id
                  WHERE o.level = 'agency'"
            ) as $r) {
                $agencyId = (int) $r['id'];
                $own      = (float) $r['p'];
                $dist     = $distPct[(int) $r['parent_id']] ?? 0.0;
                if (db_row('SELECT org_id FROM org_fee_config WHERE org_id = ?', [$agencyId]) === null) {
                    db_execute(
                        'INSERT INTO org_fee_config (org_id, pg_service_fee_pct, hq_pct, distributor_pct, agency_pct)
                         VALUES (?, ?, ?, ?, ?)',
                        [$agencyId, $own, $hqPct, $dist, $own]
                    );
                } else {
                    db_execute(
                        'UPDATE org_fee_config SET hq_pct = ?, distributor_pct = ?, agency_pct = ? WHERE org_id = ?',
                        [$hqPct, $dist, $own, $agencyId]
                    );
                }
                $n++;
            }
            echo "OK    대리점 {$n}곳 3분할 백필(본사 {$hqPct}% + 상위총판 + 자기몫)\n";
        } catch (Throwable $e) {
            echo 'ERROR platform fee split → ' . $e->getMessage() . "\n";
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

    /**
     * 총괄 관리자 역할(manager) 추가 — 대리점·총판 소속 계정 1명이 그 조직의
     * 모든 화면(시스템관리 제외)을 조회·쓰기할 수 있게 하는 역할.
     */
    private static function migrateAdminManagerRole(): void
    {
        echo "== admins.role ENUM에 manager 추가 ==\n";

        if (!db_table_exists('admins')) {
            echo "SKIP  admins (테이블 없음)\n";

            return;
        }

        $col = db_row("SHOW COLUMNS FROM admins WHERE Field = 'role'");
        if ($col !== null && str_contains((string) $col['Type'], "'manager'")) {
            echo "SKIP  manager (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "ALTER TABLE admins
                    MODIFY COLUMN role ENUM('super','admin','operation','settlement','manager')
                        NOT NULL DEFAULT 'admin'"
            );
            echo "OK    manager\n";
        } catch (Throwable $e) {
            echo 'ERROR admins.role manager → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * pg_payments에 결제 시점 본사/총판/대리점 수수료 분배 스냅샷 컬럼 추가.
     * 이전엔 분배 비율(org_fee_config)이 바뀌면 과거 결제건의 "몫"도 재계산돼
     * 실제 그 시점에 나눈 금액과 달라지는 문제가 있었다 — 결제 시점 요율·금액을 그대로 저장한다.
     */
    private static function migratePgPaymentFeeSplit(): void
    {
        echo "== pg_payments 수수료 분배 스냅샷 ==\n";

        if (!db_table_exists('pg_payments')) {
            echo "SKIP  pg_payments (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM pg_payments'), 'Field');
        $need = ['hq_amount', 'distributor_amount', 'agency_amount', 'hq_pct', 'distributor_pct', 'agency_pct'];
        if (count(array_intersect($need, $cols)) === count($need)) {
            echo "SKIP  분배 컬럼 (이미 있음)\n";

            return;
        }

        try {
            $adds = [];
            foreach (['hq_amount', 'distributor_amount', 'agency_amount'] as $c) {
                if (!in_array($c, $cols, true)) {
                    $adds[] = "ADD COLUMN {$c} INT NOT NULL DEFAULT 0";
                }
            }
            foreach (['hq_pct', 'distributor_pct', 'agency_pct'] as $c) {
                if (!in_array($c, $cols, true)) {
                    $adds[] = "ADD COLUMN {$c} DECIMAL(5,2) NOT NULL DEFAULT 0.00";
                }
            }
            db_execute('ALTER TABLE pg_payments ' . implode(', ', $adds));
            echo 'OK    ' . count($adds) . "개 컬럼 추가\n";

            // 기존 행 백필 — 과거 실제 분배 비율은 알 수 없으므로 "현재 설정값" 기준 최선 근사치.
            // (신규 결제부터는 PgPayment::chargeForRider()가 결제 시점 값을 정확히 저장)
            require_once __DIR__ . '/PgFeeConfig.php';
            $rows = db_rows("SELECT id, agency_id, service_fee FROM pg_payments WHERE status = 'success' AND hq_pct = 0 AND distributor_pct = 0 AND agency_pct = 0");
            $n = 0;
            foreach ($rows as $r) {
                $bd = PgFeeConfig::breakdownForAgency((int) $r['agency_id']);
                if ($bd['total'] <= 0) {
                    continue;
                }
                $fee = (int) $r['service_fee'];
                db_execute(
                    'UPDATE pg_payments
                        SET hq_pct = ?, distributor_pct = ?, agency_pct = ?,
                            hq_amount = ?, distributor_amount = ?, agency_amount = ?
                      WHERE id = ?',
                    [
                        $bd['hq'], $bd['distributor'], $bd['agency'],
                        (int) round($fee * $bd['hq'] / $bd['total']),
                        (int) round($fee * $bd['distributor'] / $bd['total']),
                        (int) round($fee * $bd['agency'] / $bd['total']),
                        (int) $r['id'],
                    ]
                );
                $n++;
            }
            echo "OK    기존 결제 {$n}건 백필(현재 설정값 근사치)\n";
        } catch (Throwable $e) {
            echo 'ERROR pg_payments 분배 스냅샷 → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

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
        self::migrateLeaseProviderAndVin();
        self::migratePromotionDeductionColumns();
        self::migrateOrgCeoBizColumns();
        self::migrateWithdrawalFeeShare();
        self::migrateAutoTransferOnRequest();
        self::migratePgWebhook();
        self::migrateCardIssuerCodes();
        self::migratePgIntegrationSchema();
        self::migrateNoticeEndsAt();
        self::migrateSettlementExcelKind();
        self::migrateWeeklyRiders();

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
            // 🆕 2026-08-15 본사가 정하는 하한. **전역 행(org_id NULL)의 값만 의미가 있다** —
            // 대리점 행에도 컬럼은 생기지만 읽지 않는다(대리점이 자기 하한을 정하면 하한이 아니므로).
            'agency_fee_min_short'     => "ADD COLUMN agency_fee_min_short INT NOT NULL DEFAULT 0 COMMENT '본사 지정 최저 — 기준 미만 건당(원)' AFTER agency_fee_long",
            'agency_fee_min_long'      => "ADD COLUMN agency_fee_min_long INT NOT NULL DEFAULT 0 COMMENT '본사 지정 최저 — 기준 이상 건당(원)' AFTER agency_fee_min_short",
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
    /**
     * 리스 제공주체·차대번호·수수료 배분(일 단위 정액) — 2026-08-08.
     *
     * 리스를 누가 제공하느냐(본사/총판/대리점)에 따라 걷은 리스료를 나눠 갖는 구조.
     * 배분액은 **일 단위 정액(원)**으로 계약 건마다 직접 입력하며, 합계는 일납을 넘을 수 없다.
     * 오토바이 리스라 차대번호(VIN)도 함께 보관한다.
     */
    private static function migrateLeaseProviderAndVin(): void
    {
        echo "== 리스 제공주체·차대번호·수수료 배분 ==\n";

        if (!db_table_exists('rider_debts')) {
            echo "SKIP  rider_debts (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM rider_debts'), 'Field');
        $adds = [];
        if (!in_array('lease_provider', $cols, true)) {
            $adds[] = "ADD COLUMN lease_provider ENUM('hq','distributor','agency') NULL COMMENT '리스 제공 주체(리스 전용)'";
        }
        if (!in_array('vin', $cols, true)) {
            $adds[] = "ADD COLUMN vin VARCHAR(30) NOT NULL DEFAULT '' COMMENT '차대번호(오토바이 리스)'";
        }
        foreach (['fee_hq' => '본사', 'fee_distributor' => '총판', 'fee_agency' => '대리점'] as $c => $label) {
            if (!in_array($c, $cols, true)) {
                $adds[] = "ADD COLUMN {$c} INT NOT NULL DEFAULT 0 COMMENT '리스 수수료 {$label} 몫(일 단위, 원)'";
            }
        }

        if ($adds === []) {
            echo "SKIP  리스 배분 컬럼 (이미 있음)\n";
        } else {
            db_execute('ALTER TABLE rider_debts ' . implode(', ', $adds));
            echo 'OK    rider_debts ' . count($adds) . "개 컬럼 추가\n";
        }

        // 차감 이력에도 그 시점 배분액 스냅샷을 남긴다(설정이 바뀌어도 과거 정산은 보존).
        if (db_table_exists('rider_debt_entries')) {
            $ecols = array_column(db_rows('SHOW COLUMNS FROM rider_debt_entries'), 'Field');
            $eadds = [];
            foreach (['fee_hq' => '본사', 'fee_distributor' => '총판', 'fee_agency' => '대리점'] as $c => $label) {
                if (!in_array($c, $ecols, true)) {
                    $eadds[] = "ADD COLUMN {$c} INT NOT NULL DEFAULT 0 COMMENT '이 차감 건의 {$label} 몫(원)'";
                }
            }
            if ($eadds === []) {
                echo "SKIP  rider_debt_entries 배분 컬럼 (이미 있음)\n";
            } else {
                db_execute('ALTER TABLE rider_debt_entries ' . implode(', ', $eadds));
                echo 'OK    rider_debt_entries ' . count($eadds) . "개 컬럼 추가\n";
            }
        }
    }

    /**
     * 프로모션도 정산과 동일하게 원천세·고용보험·산재보험을 공제하고 순액만 지갑에 적립하도록
     * 확장(§5.8). 배분액 스냅샷을 남겨야 나중에 요율이 바뀌어도 과거 지급 건은 그대로 보인다.
     */
    private static function migratePromotionDeductionColumns(): void
    {
        echo "== promotion_entries 원천세·보험 공제 컬럼 ==\n";

        if (!db_table_exists('promotion_entries')) {
            echo "SKIP  promotion_entries (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM promotion_entries'), 'Field');
        $adds = [];
        foreach ([
            'withholding_amount'    => '원천세',
            'employment_ins_amount' => '고용보험',
            'accident_ins_amount'   => '산재보험',
        ] as $c => $label) {
            if (!in_array($c, $cols, true)) {
                $adds[] = "ADD COLUMN {$c} INT NOT NULL DEFAULT 0 COMMENT '{$label} 공제액(지급 시점 스냅샷)'";
            }
        }
        if (!in_array('net_amount', $cols, true)) {
            $adds[] = "ADD COLUMN net_amount INT NOT NULL DEFAULT 0 COMMENT '공제 후 라이더 실지급액(total_amount - 세금·보험)'";
        }

        if ($adds === []) {
            echo "SKIP  공제 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE promotion_entries ' . implode(', ', $adds));
        echo 'OK    promotion_entries ' . count($adds) . "개 컬럼 추가\n";

        // 기존에 지급 완료된(paid) 건은 net_amount가 비어 있으면 total_amount로 백필(공제 없이 전액
        // 지급됐던 과거 데이터 그대로 — 소급 재계산·재출금은 하지 않는다).
        db_execute("UPDATE promotion_entries SET net_amount = total_amount WHERE status = 'paid' AND net_amount = 0 AND total_amount > 0");
    }

    /**
     * 총판·대리점 조직에 대표자 정보·사업자 정보 컬럼 추가.
     */
    private static function migrateOrgCeoBizColumns(): void
    {
        echo "== organizations 대표자·사업자 정보 컬럼 ==\n";

        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM organizations'), 'Field');
        $adds = [];
        foreach ([
            'ceo_name'     => ["VARCHAR(80)  NOT NULL DEFAULT ''", '대표자명'],
            'ceo_phone'    => ["VARCHAR(30)  NOT NULL DEFAULT ''", '대표자 휴대폰'],
            'ceo_birth'    => ["VARCHAR(10)  NOT NULL DEFAULT ''", '대표자 생년월일(YYMMDD, 자유 입력)'],
            'biz_name'     => ["VARCHAR(120) NOT NULL DEFAULT ''", '사업자명(상호)'],
            'biz_reg_no'   => ["VARCHAR(20)  NOT NULL DEFAULT ''", '사업자번호'],
            'biz_type'     => ["VARCHAR(60)  NOT NULL DEFAULT ''", '업태'],
            'biz_category' => ["VARCHAR(60)  NOT NULL DEFAULT ''", '업종(종목)'],
            'biz_address'  => ["VARCHAR(200) NOT NULL DEFAULT ''", '사업장 주소'],
        ] as $c => [$def, $label]) {
            if (!in_array($c, $cols, true)) {
                $adds[] = "ADD COLUMN {$c} {$def} COMMENT '{$label}'";
            }
        }

        if ($adds === []) {
            echo "SKIP  대표자·사업자 정보 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE organizations ' . implode(', ', $adds));
        echo 'OK    organizations ' . count($adds) . "개 컬럼 추가\n";
    }

    /**
     * 정산수수료 3분할(본사·총판·대리점) 설정 — 2026-08-12 갑 확정.
     *
     * 본사 몫은 **배달 건당 정액**(hq_fee_per_order)이고, 총 수수료에서 본사 몫을 뺀 나머지를
     * 총판·대리점이 비율로 나눈다. 값은 `withdrawal_config`가 이미 대리점별(org_id) 오버라이드
     * 구조라 여기에 얹으면 "대리점별 설정"(갑 확정)이 그대로 성립한다.
     */
    private static function migrateWithdrawalFeeShare(): void
    {
        echo "== withdrawal_config 정산수수료 분배 컬럼 ==\n";

        if (!db_table_exists('withdrawal_config')) {
            echo "SKIP  withdrawal_config (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
        $adds = [];
        if (!in_array('hq_fee_per_order', $cols, true)) {
            $adds[] = "ADD COLUMN hq_fee_per_order INT NOT NULL DEFAULT 0 COMMENT '정산수수료 중 본사 몫(배달 건당 정액 원)'";
        }
        if (!in_array('fee_share_distributor_pct', $cols, true)) {
            $adds[] = "ADD COLUMN fee_share_distributor_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '본사 몫을 뺀 나머지 중 총판 몫(%%) — 대리점은 잔여'";
        }

        if ($adds === []) {
            echo "SKIP  분배 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE withdrawal_config ' . implode(', ', $adds));
        echo 'OK    withdrawal_config ' . count($adds) . "개 컬럼 추가\n";
    }

    /**
     * 출금 신청 즉시 이체 스위치 — 대리점별 on/off.
     *
     * 기본값은 0(끔)이다. 켜면 라이더가 신청하는 순간 펌뱅킹으로 바로 나가므로
     * 관리자가 검토할 틈이 없다. 쓰겠다고 명시한 대리점만 켜게 한다.
     */
    private static function migrateAutoTransferOnRequest(): void
    {
        echo "== withdrawal_config 신청 즉시 이체 ==\n";

        if (!db_table_exists('withdrawal_config')) {
            echo "SKIP  withdrawal_config (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
        if (in_array('auto_transfer_on_request', $cols, true)) {
            echo "SKIP  auto_transfer_on_request (이미 있음)\n";

            return;
        }

        db_execute(
            "ALTER TABLE withdrawal_config
             ADD COLUMN auto_transfer_on_request TINYINT(1) NOT NULL DEFAULT 0
                 COMMENT '라이더 출금 신청 시 즉시 펌뱅킹 이체(0=관리자 확인 후)'"
        );
        echo "OK    auto_transfer_on_request 추가\n";
    }

    /**
     * PG 결제통지(Webhook) — 수신 기록 + 허용 IP.
     *
     * 우리 결제는 요청→응답 동기 흐름이라 지갑은 이미 그 자리에서 충전된다.
     * 웹훅은 **돈을 움직이는 경로가 아니라 대사(확인) 경로**다 — 받은 통지를 기록하고
     * 기존 pg_payments 행에 붙여, 우리 기록과 PG 기록이 어긋나는 건을 드러내는 게 목적이다.
     * (여기서 지갑을 또 충전하면 같은 결제가 두 번 반영된다.)
     *
     * 재전송이 1분 간격으로 오므로 `trx_id` UNIQUE 로 멱등을 보장한다.
     */
    private static function migratePgWebhook(): void
    {
        echo "== PG 결제통지(webhook) ==\n";

        if (db_table_exists('pg_config')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM pg_config'), 'Field');

            // 위루트는 카드번호 등 민감 필드를 AES 로 암호화해 보내라고 요구한다 —
            // 키와 IV 를 가맹점별로 발급해 준다(2026-08-23 갑 전달).
            $encAdds = [];
            if (!in_array('enc_key', $cols, true)) {
                $encAdds[] = "ADD COLUMN enc_key VARCHAR(255) NOT NULL DEFAULT '' COMMENT '외부연동 암호화 KEY(AES)'";
            }
            if (!in_array('enc_iv', $cols, true)) {
                $encAdds[] = "ADD COLUMN enc_iv VARCHAR(64) NOT NULL DEFAULT '' COMMENT '외부연동 Initialization Vector'";
            }
            if ($encAdds !== []) {
                db_execute('ALTER TABLE pg_config ' . implode(', ', $encAdds));
                echo 'OK    pg_config ' . count($encAdds) . "개 암호화 컬럼 추가\n";
            }

            if (!in_array('noti_allow_ips', $cols, true)) {
                db_execute(
                    "ALTER TABLE pg_config
                     ADD COLUMN noti_allow_ips VARCHAR(255) NOT NULL DEFAULT '221.168.33.162'
                         COMMENT '결제통지 허용 IP(쉼표 구분). 비우면 IP 검사 안 함'"
                );
                echo "OK    pg_config.noti_allow_ips 추가\n";
            } else {
                echo "SKIP  noti_allow_ips (이미 있음)\n";
            }
        }

        if (db_table_exists('pg_webhook_events')) {
            echo "SKIP  pg_webhook_events (이미 있음)\n";

            return;
        }

        db_execute(
            "CREATE TABLE `pg_webhook_events` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `trx_id`      VARCHAR(100)  NOT NULL COMMENT 'PG 거래 고유번호 — 멱등 키',
                `ord_num`     VARCHAR(60)   NOT NULL DEFAULT '' COMMENT '우리가 채번한 주문번호',
                `mid`         VARCHAR(50)   NOT NULL DEFAULT '',
                `module_type` VARCHAR(10)   NOT NULL DEFAULT '' COMMENT '4=빌링(우리 건)',
                `amount`      INT           NOT NULL DEFAULT 0,
                `payment_id`  BIGINT UNSIGNED NULL COMMENT '대조된 pg_payments.id',
                `match_state` ENUM('matched','unmatched','mismatch','ignored') NOT NULL DEFAULT 'unmatched'
                              COMMENT 'matched=금액까지 일치 / mismatch=찾았으나 금액 불일치 / ignored=우리 모듈 아님',
                `verified`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '서명 검증 통과 여부',
                `source_ip`   VARCHAR(45)   NOT NULL DEFAULT '',
                `raw_body`    TEXT          NULL COMMENT '원문 — 대사·분쟁 대비',
                `note`        VARCHAR(300)  NOT NULL DEFAULT '',
                `received_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_trx` (`trx_id`),
                KEY `idx_ord` (`ord_num`),
                KEY `idx_payment` (`payment_id`),
                KEY `idx_state` (`match_state`, `received_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='PG 결제통지 수신 기록 — 대사용, 지갑을 움직이지 않는다'"
        );
        echo "OK    pg_webhook_events 생성\n";
    }

    /**
     * PG(위루트) 발급사/매입사 코드 마스터 — `issuer_code`·`acquirer_code` 해석용.
     *
     * ⚠️ 은행코드(`bank`)와 **다른 체계**다. 같은 숫자가 다른 뜻(04=삼성카드 vs 004=국민은행).
     * 우리 `bank` 13종은 위루트 은행코드표와 이미 전부 일치해 손댈 것이 없다(2026-08-15 대조).
     */
    private static function migrateCardIssuerCodes(): void
    {
        echo "== system_codes: card_issuer(PG 발급사/매입사) ==\n";

        if (!db_table_exists('system_codes')) {
            echo "SKIP  system_codes (테이블 없음)\n";

            return;
        }

        // 위루트 「발급사/매입사 정의」 전문(2025-12-10 기준)
        $codes = [
            '01' => '비씨',   '02' => '국민',   '03' => '외환',   '04' => '삼성',
            '06' => '신한',   '07' => '현대',   '08' => '롯데',   '09' => '한미',
            '10' => '신세계', '11' => '씨티',   '12' => '농협',   '13' => '수협',
            '14' => '평화',   '15' => '우리',   '16' => '하나',   '17' => '동남',
            '18' => '주택',   '19' => '조흥',   '20' => '축협',   '21' => '광주',
            '22' => '전북',   '23' => '제주',   '24' => '산은',   '25' => '해외비자',
            '26' => '해외마스터', '27' => '해외다이너스', '28' => '해외AMX', '29' => '해외JCB',
            '30' => '해외',   '31' => 'SK-OKCashBag', '32' => '우체국', '33' => 'MG새마을체크',
            '34' => '중국은행체크', '38' => '은련', '39' => '해외DISCOVER', '46' => '카카오',
            '47' => '강원',   '48' => '토스',   '49' => '신협',   '50' => 'IBK기업',
            '51' => '케이뱅크', '99' => '기타',
        ];

        $added = 0;
        $sort  = 0;
        foreach ($codes as $code => $label) {
            $sort += 10;
            $exists = db_row(
                'SELECT id FROM system_codes WHERE category = ? AND code = ? LIMIT 1',
                ['card_issuer', $code]
            );
            if ($exists !== null) {
                continue;
            }
            db_insert(
                'INSERT INTO system_codes (category, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, 1)',
                ['card_issuer', $code, $label, $sort]
            );
            $added++;
        }

        echo $added > 0
            ? "OK    card_issuer {$added}건 추가\n"
            : "SKIP  card_issuer (이미 있음)\n";
    }

    /**
     * PG 실연동(위루트) 준비 스키마 — REF_PG_WEROUTE.md §8 의 1~3번.
     *
     * ① `pg_config`  : 자격증명 1세트. 갑 확정(2026-08-15) "결제하는 상점은 하나"에 따라
     *                  대리점별이 아니라 **시스템 전역 단일 행**(id=1)으로 둔다.
     * ② `pg_payments.ord_num` : 위루트는 결제 **요청 시** 주문번호를 요구하는데 우리는 결제가
     *                  성공해야 id가 생긴다(순서 역전). 사전 채번한 값을 저장해야 웹훅·대사·취소에서
     *                  우리 레코드를 찾을 수 있다.
     * ③ `agency_cards.bill_code`/`issuer_code` : 빌키 생성 응답 보관.
     */
    private static function migratePgIntegrationSchema(): void
    {
        echo "== PG 실연동 준비 스키마 ==\n";

        // ① 자격증명 저장소
        if (!db_table_exists('pg_config')) {
            db_execute(
                "CREATE TABLE `pg_config` (
                    `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `driver`           VARCHAR(20)  NOT NULL DEFAULT 'mock' COMMENT 'mock | weroute',
                    `mid`              VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '가맹점 ID',
                    `tid`              VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '단말기 ID(없으면 빈값)',
                    `pay_key`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '거래 API 인증(Authorization 원문, Bearer 아님)',
                    `sign_key`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT '결제통지 서명 검증용',
                    `api_key`          VARCHAR(255) NOT NULL DEFAULT '' COMMENT '대사 API External-Api 키',
                    `login_id`         VARCHAR(190) NOT NULL DEFAULT '' COMMENT '대사 API 로그인 ID',
                    `login_pw`         VARCHAR(190) NOT NULL DEFAULT '' COMMENT '대사 API 로그인 PW(로그인에 원문 필요)',
                    `access_token`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '대사 API 토큰 캐시',
                    `token_expires_at` DATETIME     NULL COMMENT '토큰 만료(발급 후 30시간)',
                    `updated_by`       INT UNSIGNED NULL,
                    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                  COMMENT='PG(위루트) 연동 자격증명 — 단일 가맹점이라 1행만 사용'"
            );
            db_execute("INSERT INTO pg_config (id, driver) VALUES (1, 'mock')");
            echo "OK    pg_config 생성 + 기본행(mock)\n";
        } else {
            db_execute("INSERT IGNORE INTO pg_config (id, driver) VALUES (1, 'mock')");
            echo "SKIP  pg_config (이미 있음)\n";
        }

        // ② pg_payments.ord_num
        if (db_table_exists('pg_payments')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM pg_payments'), 'Field');
            if (!in_array('ord_num', $cols, true)) {
                db_execute(
                    "ALTER TABLE pg_payments
                     ADD COLUMN ord_num VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'PG 주문번호(결제 전 사전 채번)' AFTER upload_id,
                     ADD KEY idx_pg_ord_num (ord_num)"
                );
                echo "OK    pg_payments.ord_num\n";
            } else {
                echo "SKIP  pg_payments.ord_num (이미 있음)\n";
            }
        }

        // ③ agency_cards 빌키 응답 보관
        if (db_table_exists('agency_cards')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM agency_cards'), 'Field');
            $adds = [];
            if (!in_array('bill_code', $cols, true)) {
                $adds[] = "ADD COLUMN bill_code VARCHAR(50) NOT NULL DEFAULT '' COMMENT '빌키 코드(위루트 응답)'";
            }
            if (!in_array('issuer_code', $cols, true)) {
                $adds[] = "ADD COLUMN issuer_code VARCHAR(3) NOT NULL DEFAULT '' COMMENT '발급사 코드(system_codes.card_issuer)'";
            }
            if ($adds !== []) {
                db_execute('ALTER TABLE agency_cards ' . implode(', ', $adds));
                echo 'OK    agency_cards ' . count($adds) . "개 컬럼 추가\n";
            } else {
                echo "SKIP  agency_cards 빌키 컬럼 (이미 있음)\n";
            }
        }
    }

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

    /**
     * 공지 노출 종료일 — `published_at`(기존 컬럼)이 "노출 시작", 신규 `ends_at`이
     * "노출 종료"다. NULL이면 종료일 없음(계속 노출).
     */
    private static function migrateNoticeEndsAt(): void
    {
        echo "== content_notices.ends_at ==\n";

        if (!db_table_exists('content_notices')) {
            echo "SKIP  content_notices (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM content_notices'), 'Field');
        if (in_array('ends_at', $cols, true)) {
            echo "SKIP  content_notices.ends_at\n";

            return;
        }
        db_execute(
            "ALTER TABLE content_notices
             ADD COLUMN ends_at DATETIME NULL COMMENT '노출 종료일시(NULL=계속 노출)' AFTER published_at"
        );
        echo "OK    content_notices.ends_at\n";
    }

    /**
     * 정산 엑셀 암호를 **일일/주간 따로** 저장할 수 있게 `kind` 추가 + 중복 전역행 정리.
     *
     * 배민은 일일정산서와 주간정산서의 열기 암호가 다르다(2026-08-22 실파일 확인 —
     * 주간 3060454741 / 일일 siook00). 플랫폼 하나에 암호 하나만 저장되면 둘 중 하나는 못 연다.
     */
    private static function migrateSettlementExcelKind(): void
    {
        echo "== settlement_excel_config.kind ==\n";

        if (!db_table_exists('settlement_excel_config')) {
            echo "SKIP  settlement_excel_config (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_excel_config'), 'Field');
        if (!in_array('kind', $cols, true)) {
            db_execute(
                "ALTER TABLE settlement_excel_config
                 ADD COLUMN kind ENUM('daily','weekly') NOT NULL DEFAULT 'daily'
                     COMMENT '일일/주간 정산서 구분(암호가 서로 다름)' AFTER platform"
            );
            echo "OK    settlement_excel_config.kind\n";
        } else {
            echo "SKIP  settlement_excel_config.kind\n";
        }

        // 🧹 구 시드(`INSERT IGNORE`)가 남긴 중복 전역행 정리.
        // MySQL 유니크키는 NULL을 구분하지 않아 migrate 실행마다 전역행이 새로 쌓였다.
        // (org_id NULL, platform, kind)당 **암호가 있는 행 우선, 없으면 가장 오래된 행**만 남긴다.
        $dupes = db_rows(
            "SELECT platform, kind, COUNT(*) AS c
               FROM settlement_excel_config
              WHERE org_id IS NULL
              GROUP BY platform, kind
             HAVING c > 1"
        );
        $removed = 0;
        foreach ($dupes as $d) {
            $keep = db_row(
                "SELECT id FROM settlement_excel_config
                  WHERE org_id IS NULL AND platform = ? AND kind = ?
                  ORDER BY (open_password <> '') DESC, id ASC
                  LIMIT 1",
                [(string) $d['platform'], (string) $d['kind']]
            );
            if ($keep === null) {
                continue;
            }
            $removed += db_execute(
                "DELETE FROM settlement_excel_config
                  WHERE org_id IS NULL AND platform = ? AND kind = ? AND id <> ?",
                [(string) $d['platform'], (string) $d['kind'], (int) $keep['id']]
            );
        }
        echo $removed > 0
            ? "OK    중복 전역행 {$removed}건 정리(암호 있는 행 유지)\n"
            : "SKIP  중복 전역행 없음\n";

        // 유니크키에 kind를 포함시킨다. 기존 (org_id, platform)만으로는 같은 대리점·플랫폼에
        // 일일/주간 두 행을 만들 수 없어 저장이 막힌다.
        $idx = db_rows('SHOW INDEX FROM settlement_excel_config');
        $keyCols = [];
        foreach ($idx as $i) {
            if ((string) $i['Key_name'] === 'uq_sec_org_pf') {
                $keyCols[(int) $i['Seq_in_index']] = (string) $i['Column_name'];
            }
        }
        ksort($keyCols);
        if ($keyCols !== [] && !in_array('kind', $keyCols, true)) {
            try {
                db_execute('ALTER TABLE settlement_excel_config DROP INDEX uq_sec_org_pf');
                db_execute('ALTER TABLE settlement_excel_config ADD UNIQUE KEY uq_sec_org_pf_kind (org_id, platform, kind)');
                echo "OK    유니크키 (org_id, platform, kind)로 교체\n";
            } catch (Throwable $e) {
                echo 'ERROR 유니크키 교체 → ' . $e->getMessage() . "\n";
                exit(1);
            }
        } else {
            echo "SKIP  유니크키 (이미 kind 포함)\n";
        }
    }

    /**
     * 배민 주간정산서(을지) 라이더별 결과 저장 테이블.
     *
     * 일일정산서에는 배달료와 주문 상세만 있고, **프로모션·시간제보험료·고용/산재보험·원천세·
     * 최종 지급액은 주간정산서에만** 있다(2026-08-22 실파일 확인 — 프로모션만 237만원).
     * 일자별 사이클(`settlement_rider_cycles`)과 달리 여기는 **주 단위 1행**이다.
     */
    private static function migrateWeeklyRiders(): void
    {
        echo "== settlement_weekly_riders ==\n";

        if (db_table_exists('settlement_weekly_riders')) {
            echo "SKIP  settlement_weekly_riders (이미 있음)\n";

            return;
        }

        try {
            db_execute(
                "CREATE TABLE settlement_weekly_riders (
                    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    upload_id     INT UNSIGNED NOT NULL,
                    agency_id     INT UNSIGNED NULL,
                    week_start    DATE NOT NULL COMMENT '정산 시작일',
                    week_end      DATE NOT NULL COMMENT '정산 종료일',
                    rider_id      INT UNSIGNED NULL COMMENT '매칭된 라이더(미매칭이면 NULL)',
                    user_id_raw   VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '배민 User ID(매칭 키)',
                    rider_name_raw VARCHAR(100) NOT NULL DEFAULT '',
                    order_count   INT NOT NULL DEFAULT 0 COMMENT '처리건수(픽업완료·금액>0 기준)',
                    delivery_fee  INT NOT NULL DEFAULT 0 COMMENT '배달료 A',
                    extra_pay     INT NOT NULL DEFAULT 0 COMMENT '추가지급 B(프로모션·할증)',
                    total_fee     INT NOT NULL DEFAULT 0 COMMENT '총 배달료 C(A+B)',
                    hourly_ins    INT NOT NULL DEFAULT 0 COMMENT '시간제보험료',
                    expense       INT NOT NULL DEFAULT 0 COMMENT '필요경비',
                    reward        INT NOT NULL DEFAULT 0 COMMENT '보수액',
                    emp_ins_rider INT NOT NULL DEFAULT 0 COMMENT '고용보험 라이더부담',
                    acc_ins_rider INT NOT NULL DEFAULT 0 COMMENT '산재보험 라이더부담',
                    settle_amount INT NOT NULL DEFAULT 0 COMMENT '라이더별 정산금액',
                    income_tax    INT NOT NULL DEFAULT 0 COMMENT '소득세',
                    resident_tax  INT NOT NULL DEFAULT 0 COMMENT '주민세',
                    withholding   INT NOT NULL DEFAULT 0 COMMENT '원천징수세액',
                    payout        INT NOT NULL DEFAULT 0 COMMENT '라이더별 지급금액(최종)',
                    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_swr_upload_user (upload_id, user_id_raw),
                    KEY idx_swr_rider (rider_id, week_start),
                    KEY idx_swr_agency (agency_id, week_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "OK    settlement_weekly_riders 생성\n";
        } catch (Throwable $e) {
            echo 'ERROR settlement_weekly_riders → ' . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

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
        self::migrateBootstrapDefaults();
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
        self::migratePgApiLogs();
        self::migratePgCancel();
        self::migrateCardBuyer();
        self::migrateOrderDetailScaleIndexes();
        self::migrateSecretEncryption();
        self::migrateFirmBanking();
        self::migrateFirmTransfers();
        self::migrateAccountVerified();
        self::migrateFirmEnvCredentials();
        self::migrateCardIssuerCodes();
        self::migratePgIntegrationSchema();
        self::migrateNoticeEndsAt();
        self::migrateSettlementExcelKind();
        self::migrateWeeklyRiders();
        self::migrateTransferFee();
        self::migrateAgencyFeePayer();
        self::migrateTaxAgent();
        self::migrateRiderReserveOverride();
        self::migrateMessaging();
        self::migrateStatementFlags();
        self::migrateStatementLinks();
        self::migrateStatementNotified();
        self::migrateMessagingConfig();
        self::migrateAlimtalkFallback();
        self::migrateTaxAgentToWithholding();
        self::migrateMessagingBilling();
        self::migrateAlimtalkTemplates();
        self::migrateDebtAccrualBaseline();
        self::migrateCarryForward();
        self::migrateLeaseBalance();
        self::migrateTaxReportFields();
        self::migrateTaxFeeShare();
        self::migrateDeveloperOrg();
        self::migrateTransferBankCodes();
        self::migrateOrgFeeGlobalRow();
        self::migrateAgencyPredeductFee();

        echo "\n완료.\n";
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
            echo "SKIP  deduction_global_config (테이블 없음)\n";

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
            $adds[] = "ADD COLUMN hq_fee_per_order INT NOT NULL DEFAULT 0 COMMENT '[구] 본사 몫 단일 정액 — 구간별 hq_fee_short/long 로 대체'";
        }
        if (!in_array('fee_share_distributor_pct', $cols, true)) {
            $adds[] = "ADD COLUMN fee_share_distributor_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '[구] 총판 몫 비율 — 구간별 dist_fee_short/long 로 대체'";
        }
        // 2026-08-31 갑 지시: 본사·총판 몫을 「기준 미만/기준 이상」 두 구간으로 나눠 각각
        // 배달 건당 정액(원)으로 배분한다. 총판도 비율(%)이 아니라 건당 정액으로 바뀐다.
        if (!in_array('hq_fee_short', $cols, true)) {
            $adds[] = "ADD COLUMN hq_fee_short INT NOT NULL DEFAULT 0 COMMENT '본사 몫 — 기준 미만 배달 건당(원)'";
        }
        if (!in_array('hq_fee_long', $cols, true)) {
            $adds[] = "ADD COLUMN hq_fee_long INT NOT NULL DEFAULT 0 COMMENT '본사 몫 — 기준 이상 배달 건당(원)'";
        }
        if (!in_array('dist_fee_short', $cols, true)) {
            $adds[] = "ADD COLUMN dist_fee_short INT NOT NULL DEFAULT 0 COMMENT '총판 몫 — 기준 미만 배달 건당(원)'";
        }
        if (!in_array('dist_fee_long', $cols, true)) {
            $adds[] = "ADD COLUMN dist_fee_long INT NOT NULL DEFAULT 0 COMMENT '총판 몫 — 기준 이상 배달 건당(원)'";
        }
        // min_agency_fee 는 한때 여기 뒀다가 폐기했다 — 본사 몫 하한값은 별도 필드가 아니라
        // 「대행수수료 설정」(deduction_global_config.agency_fee_min_short/long)을 그대로 쓴다
        // (2026-08-31 갑: "대행수수료 최저 금액은 대행수수료 설정 부분에 되어 있어"). 이미 만들어졌으면 제거.
        if (in_array('min_agency_fee', $cols, true)) {
            db_execute('ALTER TABLE withdrawal_config DROP COLUMN min_agency_fee');
            echo "OK    withdrawal_config.min_agency_fee 제거(대행수수료 설정의 최저 금액을 참조)\n";
        }

        if ($adds === []) {
            echo "SKIP  분배 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE withdrawal_config ' . implode(', ', $adds));
        echo 'OK    withdrawal_config ' . count($adds) . "개 컬럼 추가\n";

        // 기존 단일 본사 몫(hq_fee_per_order)이 설정돼 있던 대리점은 두 구간에 같은 값으로 옮긴다
        // (구간 구분이 없던 시절의 값을 그대로 이어받게 — 새 컬럼을 방금 만들었을 때만).
        if (in_array('hq_fee_per_order', $cols, true) && !in_array('hq_fee_short', $cols, true)) {
            db_execute('UPDATE withdrawal_config SET hq_fee_short = hq_fee_per_order, hq_fee_long = hq_fee_per_order WHERE hq_fee_per_order > 0');
            echo "OK    기존 본사 몫(단일) → 구간별(hq_fee_short/long) 이전\n";
        }
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
     * 문자·알림톡 발송 큐 + 라이더 문자 수신용 전화번호(2026-09-01 갑).
     */
    private static function migrateMessaging(): void
    {
        echo "== 문자·알림톡 큐 ==\n";

        // 라이더 문자 수신용 번호 — 기본 휴대전화와 별개(문자 전용으로 받고 싶은 번호).
        if (db_table_exists('riders')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
            if (!in_array('sms_phone', $cols, true)) {
                db_execute("ALTER TABLE riders ADD COLUMN sms_phone VARCHAR(30) NULL DEFAULT NULL COMMENT '문자 수신용 전화번호(비면 phone 사용)'");
                echo "OK    riders.sms_phone 추가\n";
            } else {
                echo "SKIP  riders.sms_phone (이미 있음)\n";
            }
        }

        if (!db_table_exists('message_queue')) {
            db_execute(
                "CREATE TABLE message_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    channel ENUM('sms','alimtalk') NOT NULL DEFAULT 'sms',
                    rider_id INT NULL,
                    recipient_name VARCHAR(80) NULL,
                    recipient_phone VARCHAR(30) NOT NULL,
                    title VARCHAR(120) NULL COMMENT 'SMS 제목/알림톡 템플릿명',
                    content TEXT NOT NULL,
                    status ENUM('queued','sending','sent','failed','canceled') NOT NULL DEFAULT 'queued',
                    provider VARCHAR(40) NULL,
                    provider_ref VARCHAR(120) NULL,
                    error VARCHAR(255) NULL,
                    scheduled_at DATETIME NULL,
                    sent_at DATETIME NULL,
                    created_by INT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_status (status),
                    INDEX idx_rider (rider_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            echo "OK    message_queue 생성\n";
        } else {
            echo "SKIP  message_queue (이미 있음)\n";
        }

        // 발송 로그 — **append-only**. 재발송해도 큐 행이 덮이므로 시도마다 1행씩 영구 기록한다
        // (발송 성공/실패 이력·provider ref·에러를 남겨 조회한다). 큐가 정리돼도 로그는 남는다.
        if (!db_table_exists('message_send_logs')) {
            db_execute(
                "CREATE TABLE message_send_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id INT NULL COMMENT 'message_queue.id (있으면)',
                    channel ENUM('sms','alimtalk') NOT NULL DEFAULT 'sms',
                    rider_id INT NULL,
                    recipient_name VARCHAR(80) NULL,
                    recipient_phone VARCHAR(30) NOT NULL,
                    title VARCHAR(120) NULL,
                    content TEXT NULL,
                    status ENUM('sent','failed') NOT NULL,
                    provider VARCHAR(40) NULL,
                    provider_ref VARCHAR(120) NULL,
                    error VARCHAR(255) NULL,
                    attempted_by INT NULL,
                    attempted_at DATETIME NOT NULL,
                    INDEX idx_attempted (attempted_at),
                    INDEX idx_status (status),
                    INDEX idx_channel (channel),
                    INDEX idx_phone (recipient_phone),
                    INDEX idx_message (message_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            echo "OK    message_send_logs 생성\n";
        } else {
            echo "SKIP  message_send_logs (이미 있음)\n";
        }
    }

    /**
     * 라이더 예외 보증금 — 라이더별로 대리점 기본 보증금 대신 쓸 개별 금액(2026-09-01 갑).
     * NULL = 대리점 기준(withdrawal_config.reserve_amount) 사용. 값이 있으면 그 금액 우선.
     */
    private static function migrateRiderReserveOverride(): void
    {
        echo "== riders 예외 보증금 ==\n";
        if (!db_table_exists('riders')) {
            echo "SKIP  riders 없음\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        if (in_array('reserve_override', $cols, true)) {
            echo "SKIP  reserve_override (이미 있음)\n";

            return;
        }
        db_execute("ALTER TABLE riders ADD COLUMN reserve_override INT NULL DEFAULT NULL COMMENT '예외 보증금(원) — NULL이면 대리점 기본, 값 있으면 우선'");
        echo "OK    riders.reserve_override 추가\n";
    }

    /**
     * 세무대리 — 독립 조직 + 고용·산재 예수금 수집(2026-09-01 갑 지시).
     *
     * 고용·산재보험 공제분을 원천세처럼 대리점 지갑에 **예수금(insurance_reserve)** 으로 누적하고,
     * 세무대리(독립 조직)가 월별로 각 대리점 지갑에서 자기 지갑으로 가져와 신고·납입한다.
     */
    private static function migrateTaxAgent(): void
    {
        echo "== 세무대리(고용·산재 예수금) ==\n";
        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations 없음\n";

            return;
        }

        // 1) organizations.level 에 'tax_agent' 추가
        $col = db_row("SHOW COLUMNS FROM organizations LIKE 'level'");
        if ($col !== null && !str_contains((string) $col['Type'], "tax_agent")) {
            db_execute("ALTER TABLE organizations MODIFY COLUMN level ENUM('admin','distributor','agency','tax_agent') NOT NULL");
            echo "OK    organizations.level 에 tax_agent 추가\n";
        }

        // 2) 세무대리 조직(단일) — 없으면 생성
        $tax = db_row("SELECT id FROM organizations WHERE level='tax_agent' ORDER BY id ASC LIMIT 1");
        if ($tax === null) {
            $taxId = db_insert(
                "INSERT INTO organizations (parent_id, level, code, name, is_active, created_at)
                 VALUES (NULL, 'tax_agent', 'TAX', '세무대리', 1, NOW())"
            );
            echo "OK    세무대리 조직 생성(id={$taxId})\n";
        } else {
            $taxId = (int) $tax['id'];
            echo "SKIP  세무대리 조직 (이미 있음, id={$taxId})\n";
        }

        // 3) 세무대리 대표계정 — 없으면 생성(로그인 tax / 비번 Admin1234! · 데모 공통)
        if (db_table_exists('admins')) {
            $exists = db_row('SELECT id FROM admins WHERE org_id = ? LIMIT 1', [$taxId]);
            if ($exists === null) {
                db_insert(
                    "INSERT INTO admins (login_id, password_hash, name, role, org_id, is_active)
                     VALUES ('tax', ?, '세무대리', 'manager', ?, 1)",
                    [password_hash('Admin1234!', PASSWORD_BCRYPT, ['cost' => 12]), $taxId]
                );
                echo "OK    세무대리 계정 생성(tax / Admin1234!)\n";
            }
        }

        // 4) agency_wallets.insurance_reserve (고용·산재 예수금) + 세무대리 지갑 행
        if (db_table_exists('agency_wallets')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM agency_wallets'), 'Field');
            $freshCol = !in_array('insurance_reserve', $cols, true);
            if ($freshCol) {
                db_execute("ALTER TABLE agency_wallets ADD COLUMN insurance_reserve INT NOT NULL DEFAULT 0 COMMENT '고용·산재 예수금(세무대리가 수집)'");
                echo "OK    agency_wallets.insurance_reserve 추가\n";
            }
            db_execute('INSERT IGNORE INTO agency_wallets (agency_id, balance, withholding_reserve) VALUES (?, 0, 0)', [$taxId]);

            // 백필 — 기존 정산 고용·산재 공제분을 예수금으로 1회 채운다(신규 컬럼일 때만).
            if ($freshCol && db_table_exists('settlement_fee_items') && db_table_exists('settlement_rider_cycles')) {
                db_execute(
                    "UPDATE agency_wallets w
                        JOIN (
                            SELECT r.agency_id AS aid, COALESCE(SUM(fi.amount),0) AS ins
                              FROM settlement_fee_items fi
                              JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
                              JOIN riders r ON r.id = c.rider_id
                             WHERE fi.fee_code IN ('employment_ins','accident_ins')
                               AND r.agency_id IS NOT NULL
                             GROUP BY r.agency_id
                        ) s ON s.aid = w.agency_id
                        SET w.insurance_reserve = s.ins"
                );
                echo "OK    기존 고용·산재 공제분을 예수금으로 백필\n";
            }
        }

        // 5) 수집 이력 테이블
        if (!db_table_exists('tax_insurance_collections')) {
            db_execute(
                "CREATE TABLE tax_insurance_collections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tax_org_id INT NOT NULL,
                    agency_id INT NOT NULL,
                    period VARCHAR(7) NOT NULL COMMENT '수집 귀속월 YYYY-MM',
                    amount INT NOT NULL,
                    collected_by INT NULL,
                    collected_at DATETIME NOT NULL,
                    note VARCHAR(255) NULL,
                    INDEX idx_agency (agency_id),
                    INDEX idx_period (period)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            echo "OK    tax_insurance_collections 생성\n";
        } else {
            echo "SKIP  tax_insurance_collections (이미 있음)\n";
        }
    }

    /**
     * 세무대리 재설계(2026-09-04 갑) — 세무대리가 가져가는 건 **원천세**만이고,
     * **고용·산재는 대리점이 갖는 돈**이다(라이더 공제분을 대리점이 보유, 예수금 아님).
     *
     * 되돌리는 것:
     *  ① 이미 수집된 고용·산재(`tax_insurance_collections`)를 **자동 환원** — 세무대리 지갑에서
     *     대리점 지갑으로 되돌린다(원장 `ins_collect_rev`). 세무대리 지갑은 비고, 대리점은 회복.
     *  ② 모든 대리점의 `insurance_reserve`(고용·산재 예수금 잠금)를 **0으로 해제** → 인출가능액에 포함.
     *  ③ `tax_insurance_collections` → `tax_withholding_collections` 로 **용도 변경**(원천세 수집 기록).
     *     기존 고용·산재 수집 행은 ①에서 환원했으므로 비운 뒤 이름만 바꾼다.
     * 멱등: `tax_withholding_collections` 가 이미 있으면 전부 건너뛴다.
     */
    private static function migrateTaxAgentToWithholding(): void
    {
        echo "== 세무대리 재설계(원천세 수집) ==\n";
        if (!db_table_exists('organizations') || !db_table_exists('agency_wallets')) {
            echo "SKIP  조직/지갑 테이블 없음\n";

            return;
        }
        if (db_table_exists('tax_withholding_collections')) {
            echo "SKIP  이미 원천세 수집 구조 (tax_withholding_collections 있음)\n";

            return;
        }

        require_once __DIR__ . '/AgencyWallet.php';
        require_once __DIR__ . '/Org.php';
        $taxId = Org::taxAgentOrgId();

        // ① 기존 고용·산재 수집분 자동 환원 (세무대리 지갑 → 대리점 지갑)
        if (db_table_exists('tax_insurance_collections')) {
            $byAgency = db_rows(
                'SELECT agency_id, COALESCE(SUM(amount),0) AS amt
                   FROM tax_insurance_collections GROUP BY agency_id HAVING amt <> 0'
            );
            $reverted = 0;
            $totalRev = 0;
            foreach ($byAgency as $r) {
                $aid = (int) $r['agency_id'];
                $amt = (int) $r['amt'];
                if ($aid < 1 || $amt <= 0) {
                    continue;
                }
                // 대리점 지갑으로 되돌림 + 세무대리 지갑에서 뺌 (원장 기록으로 추적 가능)
                AgencyWallet::credit($aid, $amt, 'ins_collect_rev', null, '고용·산재 예수금 환원(세무대리 재설계)');
                if ($taxId > 0) {
                    AgencyWallet::debit($taxId, $amt, 'ins_collect_rev', $aid, '고용·산재 예수금 환원(대리점으로)');
                }
                $reverted++;
                $totalRev += $amt;
            }
            if ($reverted > 0) {
                echo "OK    고용·산재 수집 {$reverted}개 대리점 환원 (" . number_format($totalRev) . "원 세무대리→대리점)\n";
            }
            // 수집 이력 비움(환원 완료 → 원천세 수집용으로 재사용)
            db_execute('DELETE FROM tax_insurance_collections');
        }

        // ② insurance_reserve 전액 해제 (고용·산재는 대리점 보유 → 인출가능)
        $cols = array_column(db_rows('SHOW COLUMNS FROM agency_wallets'), 'Field');
        if (in_array('insurance_reserve', $cols, true)) {
            $released = db_row('SELECT COALESCE(SUM(insurance_reserve),0) AS s FROM agency_wallets');
            db_execute('UPDATE agency_wallets SET insurance_reserve = 0, updated_at = NOW() WHERE insurance_reserve <> 0');
            echo "OK    insurance_reserve 전액 해제 (" . number_format((int) ($released['s'] ?? 0)) . "원 → 대리점 인출가능)\n";
        }

        // ③ 수집 테이블을 원천세용으로 용도 변경
        if (db_table_exists('tax_insurance_collections')) {
            db_execute('RENAME TABLE tax_insurance_collections TO tax_withholding_collections');
            echo "OK    tax_insurance_collections → tax_withholding_collections 로 용도 변경\n";
        } else {
            db_execute(
                "CREATE TABLE tax_withholding_collections (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tax_org_id INT NOT NULL,
                    agency_id INT NOT NULL,
                    period CHAR(7) NOT NULL COMMENT '정산 귀속월 YYYY-MM',
                    amount INT NOT NULL,
                    collected_by INT NULL,
                    collected_at DATETIME NOT NULL,
                    note VARCHAR(255) NULL,
                    INDEX idx_agency (agency_id),
                    INDEX idx_period (period)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            echo "OK    tax_withholding_collections 생성\n";
        }
    }

    /**
     * 대행수수료 부담 주체 — 대리점별로 라이더/대리점 중 누가 낼지 (2026-09-01 갑 지시).
     *
     * 'rider'(기본): 선정산 정산 반영 시 라이더 net 에서 공제(기존 동작).
     * 'agency'    : 라이더는 전액 정산받고, 대행수수료만큼 대리점 지갑에서 본사로 이체(대리점 부담).
     * 두 경우 모두 대행수수료는 **본사 귀속**이다(반영 시 대리점→본사 이체).
     */
    private static function migrateAgencyFeePayer(): void
    {
        echo "== organizations 대행수수료 부담 주체 ==\n";
        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM organizations'), 'Field');
        if (in_array('agency_fee_payer', $cols, true)) {
            echo "SKIP  agency_fee_payer (이미 있음)\n";

            return;
        }
        db_execute(
            "ALTER TABLE organizations
             ADD COLUMN agency_fee_payer ENUM('rider','agency') NOT NULL DEFAULT 'rider'
                 COMMENT '대행수수료 부담 주체 — rider=라이더 공제 / agency=대리점 지갑 부담(본사 귀속은 동일)'"
        );
        echo "OK    organizations.agency_fee_payer 추가(기본 rider)\n";
    }

    /**
     * 이체 수수료 — 펌뱅킹 이체(일일이체·출금신청·출금대행) 1건당 라이더에게 부과하는 정액.
     * 2026-09-01 갑 지시. 실지급액에서 빼고 **본사**로 귀속된다.
     *
     * - `withdrawal_config.transfer_fee`(기본 330) : 대리점별 설정 가능(전역/오버라이드).
     * - `withdrawal_requests.withhold_transfer_fee` : 출금 건별로 실제 부과한 이체 수수료 기록.
     */
    /**
     * 정산서(명세서) 기능 대리점별 on/off (2026-09-01 갑).
     *  - stmt_weekly_enabled : 주급(정산) 명세서 발급 메뉴 사용 여부(기본 켬).
     *  - stmt_daily_alimtalk : 일정산 반영 시 라이더에게 요약 명세서 알림톡 자동 발송(기본 끔).
     * 대리점마다 기능별로 켜고 끌 수 있게 organizations 에 플래그를 둔다.
     */
    private static function migrateStatementFlags(): void
    {
        echo "== organizations 정산서 기능 플래그 ==\n";
        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM organizations'), 'Field');
        if (!in_array('stmt_weekly_enabled', $cols, true)) {
            db_execute(
                "ALTER TABLE organizations
                 ADD COLUMN stmt_weekly_enabled TINYINT(1) NOT NULL DEFAULT 1
                     COMMENT '주급 명세서 발급 메뉴 사용(대리점)'"
            );
            echo "OK    organizations.stmt_weekly_enabled 추가(기본 1)\n";
        } else {
            echo "SKIP  stmt_weekly_enabled (이미 있음)\n";
        }
        if (!in_array('stmt_daily_alimtalk', $cols, true)) {
            db_execute(
                "ALTER TABLE organizations
                 ADD COLUMN stmt_daily_alimtalk TINYINT(1) NOT NULL DEFAULT 0
                     COMMENT '일정산 반영 시 명세서 알림톡 자동 발송(대리점)'"
            );
            echo "OK    organizations.stmt_daily_alimtalk 추가(기본 0)\n";
        } else {
            echo "SKIP  stmt_daily_alimtalk (이미 있음)\n";
        }
    }

    /**
     * 모바일 명세서 공개 링크(2026-09-01 갑) — 알림톡 링크로 로그인 없이 명세서를 여는 토큰.
     * 토큰 하나가 (라이더 + 정산기간)에 매핑되며 만료된다.
     */
    private static function migrateStatementLinks(): void
    {
        echo "== 모바일 명세서 공개 링크 ==\n";
        if (db_table_exists('statement_links')) {
            echo "SKIP  statement_links (이미 있음)\n";

            return;
        }
        db_execute(
            "CREATE TABLE statement_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token CHAR(40) NOT NULL,
                rider_id INT NOT NULL,
                period_from DATE NOT NULL,
                period_to DATE NOT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                view_count INT NOT NULL DEFAULT 0,
                last_viewed_at DATETIME NULL,
                UNIQUE KEY uq_token (token),
                INDEX idx_rider_period (rider_id, period_from, period_to),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "OK    statement_links 생성\n";
    }

    /**
     * 일정산 명세서 알림톡 **중복 발송 방지**(2026-09-02) — 정산 사이클에 "명세서 알림톡을 보냈다"
     * 표시. 재반영(같은 업로드 재적용) 시 이미 보낸 라이더에게 다시 큐에 쌓지 않도록 한다.
     */
    private static function migrateStatementNotified(): void
    {
        echo "== settlement_rider_cycles 명세서 알림톡 표시 ==\n";
        if (!db_table_exists('settlement_rider_cycles')) {
            echo "SKIP  settlement_rider_cycles (테이블 없음)\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field');
        if (in_array('statement_notified_at', $cols, true)) {
            echo "SKIP  statement_notified_at (이미 있음)\n";

            return;
        }
        db_execute("ALTER TABLE settlement_rider_cycles ADD COLUMN statement_notified_at DATETIME NULL DEFAULT NULL COMMENT '명세서 알림톡 큐 적재 시각(중복 방지)'");
        echo "OK    settlement_rider_cycles.statement_notified_at 추가\n";
    }

    /**
     * 알림톡·문자 발송 설정(2026-09-02 갑) — 발신번호·알림톡 채널·명세서 템플릿·링크 도메인·링크 유효기간.
     * 전역 단일 행(본사 설정). 발송사 자격증명(id/pw)은 여기 두지 않고 연동 시 암호화 저장한다.
     */
    private static function migrateMessagingConfig(): void
    {
        echo "== 알림톡·문자 설정 ==\n";
        if (db_table_exists('messaging_config')) {
            echo "SKIP  messaging_config (이미 있음)\n";

            return;
        }
        db_execute(
            "CREATE TABLE messaging_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_phone VARCHAR(30) NULL COMMENT '문자·알림톡 발신번호',
                alimtalk_channel VARCHAR(60) NULL COMMENT '알림톡 발신 프로필/채널 ID(플러스친구)',
                statement_template VARCHAR(60) NULL COMMENT '명세서 알림톡 템플릿 코드',
                public_base_url VARCHAR(200) NULL COMMENT '명세서 링크 기본 도메인(예: https://oxpay.kr)',
                link_ttl_days INT NOT NULL DEFAULT 90 COMMENT '명세서 링크 유효기간(일)',
                updated_by INT NULL,
                updated_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        db_execute('INSERT INTO messaging_config (link_ttl_days, created_at) VALUES (90, NOW())');
        echo "OK    messaging_config 생성(기본 링크 유효 90일)\n";
    }

    /**
     * 알림톡 실패 시 SMS 대체발송(2026-09-02 갑) — 카카오 수신불가(미설치·차단·미사용자 등)로 실패한
     * 알림톡을 같은 내용으로 SMS 재발송한다. `message_queue.fallback_from`(원본 알림톡 id)으로
     * 추적하고, `messaging_config.alimtalk_fallback_sms`(기본 켬)로 on/off.
     */
    private static function migrateAlimtalkFallback(): void
    {
        echo "== 알림톡 SMS 대체발송 ==\n";
        if (db_table_exists('message_queue')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM message_queue'), 'Field');
            if (!in_array('fallback_from', $cols, true)) {
                db_execute("ALTER TABLE message_queue ADD COLUMN fallback_from INT NULL DEFAULT NULL COMMENT '알림톡 실패 대체발송이면 원본 알림톡 message_queue.id'");
                db_execute('ALTER TABLE message_queue ADD INDEX idx_fallback (fallback_from)');
                echo "OK    message_queue.fallback_from 추가\n";
            } else {
                echo "SKIP  message_queue.fallback_from (이미 있음)\n";
            }
        }
        if (db_table_exists('message_send_logs')) {
            $lcols = array_column(db_rows('SHOW COLUMNS FROM message_send_logs'), 'Field');
            if (!in_array('reason_code', $lcols, true)) {
                db_execute("ALTER TABLE message_send_logs ADD COLUMN reason_code VARCHAR(40) NULL DEFAULT NULL COMMENT '실패 분류 코드(대체발송 판정용)'");
                echo "OK    message_send_logs.reason_code 추가\n";
            } else {
                echo "SKIP  message_send_logs.reason_code (이미 있음)\n";
            }
        }
        if (db_table_exists('messaging_config')) {
            $ccols = array_column(db_rows('SHOW COLUMNS FROM messaging_config'), 'Field');
            if (!in_array('alimtalk_fallback_sms', $ccols, true)) {
                db_execute("ALTER TABLE messaging_config ADD COLUMN alimtalk_fallback_sms TINYINT(1) NOT NULL DEFAULT 1 COMMENT '알림톡 수신불가 시 SMS 대체발송'");
                echo "OK    messaging_config.alimtalk_fallback_sms 추가(기본 1)\n";
            } else {
                echo "SKIP  messaging_config.alimtalk_fallback_sms (이미 있음)\n";
            }
        }
    }

    /**
     * 메시지 발송 과금(2026-09-03 갑) — 발송 1건당 **대리점 지갑 → 본사**로 요금을 옮긴다.
     * 단가는 messaging_config 에서 설정(기본 알림톡 10 / SMS 10 / LMS 50원).
     * SMS 는 EUC-KR 90바이트를 넘으면 LMS 로 자동 전환하므로 channel enum 에 lms 를 추가한다.
     */
    private static function migrateMessagingBilling(): void
    {
        echo "== 메시지 발송 과금 ==\n";

        if (db_table_exists('messaging_config')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM messaging_config'), 'Field');
            $adds = [];
            if (!in_array('price_alimtalk', $cols, true)) {
                $adds[] = "ADD COLUMN price_alimtalk INT NOT NULL DEFAULT 10 COMMENT '알림톡 1건 단가(원)'";
            }
            if (!in_array('price_sms', $cols, true)) {
                $adds[] = "ADD COLUMN price_sms INT NOT NULL DEFAULT 10 COMMENT 'SMS 1건 단가(원)'";
            }
            if (!in_array('price_lms', $cols, true)) {
                $adds[] = "ADD COLUMN price_lms INT NOT NULL DEFAULT 50 COMMENT 'LMS 1건 단가(원)'";
            }
            if (!in_array('sms_max_bytes', $cols, true)) {
                $adds[] = "ADD COLUMN sms_max_bytes INT NOT NULL DEFAULT 90 COMMENT 'SMS 최대 바이트(EUC-KR). 초과하면 LMS'";
            }
            if ($adds !== []) {
                db_execute('ALTER TABLE messaging_config ' . implode(', ', $adds));
                echo 'OK    messaging_config 과금 컬럼 ' . count($adds) . "개 추가\n";
            } else {
                echo "SKIP  messaging_config 과금 컬럼 (이미 있음)\n";
            }
        }

        if (db_table_exists('message_queue')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM message_queue'), 'Field');

            // channel 에 lms 추가 (enum 확장은 컬럼 정의를 통째로 다시 쓴다)
            $ch = db_row("SHOW COLUMNS FROM message_queue LIKE 'channel'");
            if ($ch !== null && !str_contains((string) $ch['Type'], "'lms'")) {
                db_execute("ALTER TABLE message_queue MODIFY COLUMN channel ENUM('sms','lms','alimtalk') NOT NULL DEFAULT 'sms'");
                echo "OK    message_queue.channel 에 lms 추가\n";
            } else {
                echo "SKIP  message_queue.channel lms (이미 있음)\n";
            }

            $adds = [];
            if (!in_array('agency_id', $cols, true)) {
                $adds[] = "ADD COLUMN agency_id INT NULL DEFAULT NULL COMMENT '과금 대상 대리점(라이더 소속). NULL이면 과금 안 함'";
            }
            if (!in_array('charged_amount', $cols, true)) {
                $adds[] = "ADD COLUMN charged_amount INT NOT NULL DEFAULT 0 COMMENT '실제 과금액(원). 발송 성공 시 기록'";
            }
            if (!in_array('template_code', $cols, true)) {
                $adds[] = "ADD COLUMN template_code VARCHAR(60) NULL DEFAULT NULL COMMENT '알림톡 템플릿 코드'";
            }
            if ($adds !== []) {
                db_execute('ALTER TABLE message_queue ' . implode(', ', $adds));
                echo 'OK    message_queue 과금 컬럼 ' . count($adds) . "개 추가\n";
            } else {
                echo "SKIP  message_queue 과금 컬럼 (이미 있음)\n";
            }
        }

        if (db_table_exists('message_send_logs')) {
            $lcols = array_column(db_rows('SHOW COLUMNS FROM message_send_logs'), 'Field');
            $lch = db_row("SHOW COLUMNS FROM message_send_logs LIKE 'channel'");
            if ($lch !== null && !str_contains((string) $lch['Type'], "'lms'")) {
                db_execute("ALTER TABLE message_send_logs MODIFY COLUMN channel ENUM('sms','lms','alimtalk') NOT NULL DEFAULT 'sms'");
                echo "OK    message_send_logs.channel 에 lms 추가\n";
            }
            if (!in_array('charged_amount', $lcols, true)) {
                db_execute("ALTER TABLE message_send_logs ADD COLUMN charged_amount INT NOT NULL DEFAULT 0 COMMENT '과금액(원)'");
                echo "OK    message_send_logs.charged_amount 추가\n";
            }
        }
    }

    /**
     * 알림톡 템플릿 관리(2026-09-03 갑) — 어떤 상황(event_key)에 어떤 템플릿을 쓸지,
     * 치환변수는 무엇인지, 실패 시 SMS 대체를 할지를 화면에서 관리한다.
     */
    private static function migrateAlimtalkTemplates(): void
    {
        echo "== 알림톡 템플릿 ==\n";
        if (db_table_exists('alimtalk_templates')) {
            echo "SKIP  alimtalk_templates (이미 있음)\n";

            return;
        }
        db_execute(
            "CREATE TABLE alimtalk_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_key VARCHAR(40) NOT NULL COMMENT '발송 상황 키(settlement_statement 등)',
                name VARCHAR(80) NOT NULL COMMENT '관리용 이름',
                template_code VARCHAR(60) NOT NULL DEFAULT '' COMMENT '카카오 승인 템플릿 코드',
                title VARCHAR(120) NULL COMMENT '알림톡 강조표기/제목',
                content TEXT NOT NULL COMMENT '템플릿 본문(치환변수 #{키})',
                variables VARCHAR(500) NOT NULL DEFAULT '' COMMENT '치환변수 목록(쉼표 구분, 안내용)',
                channel_policy ENUM('alimtalk_first','sms_only') NOT NULL DEFAULT 'alimtalk_first'
                    COMMENT 'alimtalk_first=알림톡 우선(실패 시 SMS 대체) / sms_only=문자만',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by INT NULL,
                updated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_event (event_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        echo "OK    alimtalk_templates 생성\n";
    }

    private static function migrateTransferFee(): void
    {
        echo "== 이체 수수료 컬럼 ==\n";

        if (db_table_exists('withdrawal_config')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
            if (!in_array('transfer_fee', $cols, true)) {
                db_execute(
                    "ALTER TABLE withdrawal_config
                     ADD COLUMN transfer_fee INT NOT NULL DEFAULT 330
                         COMMENT '펌뱅킹 이체 1건당 이체 수수료(원) — 실지급액에서 빼 본사 귀속'"
                );
                echo "OK    withdrawal_config.transfer_fee 추가(기본 330)\n";
            } else {
                echo "SKIP  withdrawal_config.transfer_fee (이미 있음)\n";
            }
        } else {
            echo "SKIP  withdrawal_config (테이블 없음)\n";
        }

        if (db_table_exists('withdrawal_requests')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_requests'), 'Field');
            if (!in_array('withhold_transfer_fee', $cols, true)) {
                db_execute(
                    "ALTER TABLE withdrawal_requests
                     ADD COLUMN withhold_transfer_fee INT NOT NULL DEFAULT 0
                         COMMENT '이 출금에 부과한 이체 수수료(원) — 본사 귀속'"
                );
                echo "OK    withdrawal_requests.withhold_transfer_fee 추가\n";
            } else {
                echo "SKIP  withdrawal_requests.withhold_transfer_fee (이미 있음)\n";
            }
        } else {
            echo "SKIP  withdrawal_requests (테이블 없음)\n";
        }
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
                     ADD COLUMN noti_allow_ips VARCHAR(255) NOT NULL DEFAULT '221.168.33.227'
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
     * PG API 호출 이력 — 요청/응답을 남겨 결제 문제를 사후에 추적한다.
     *
     * pg_payments 는 결과만 남긴다. 승인이 안 되거나 금액이 어긋났을 때 "우리가 뭘 보냈고
     * PG가 뭘 돌려줬나"가 없으면 원인을 못 찾아서 따로 둔다.
     * 🔒 카드번호·비밀번호·키는 PgApiLog::mask() 가 지우고 넣는다.
     */
    private static function migratePgApiLogs(): void
    {
        echo "== PG API 호출 이력 ==\n";

        if (db_table_exists('pg_api_logs')) {
            echo "SKIP  pg_api_logs (이미 있음)\n";

            return;
        }

        db_execute(
            "CREATE TABLE `pg_api_logs` (
                `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `endpoint`      VARCHAR(120) NOT NULL DEFAULT '',
                `method`        VARCHAR(10)  NOT NULL DEFAULT 'POST',
                `ord_num`       VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '우리 주문번호 — 결제 건과 연결',
                `http_code`     SMALLINT     NOT NULL DEFAULT 0,
                `result_cd`     VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '위루트 result_cd',
                `result_msg`    VARCHAR(300) NOT NULL DEFAULT '',
                `ok`            TINYINT(1)   NOT NULL DEFAULT 0,
                `duration_ms`   INT          NOT NULL DEFAULT 0,
                `request_body`  TEXT         NULL COMMENT '민감값 마스킹 후 저장',
                `response_body` TEXT         NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_ord` (`ord_num`),
                KEY `idx_created` (`created_at`),
                KEY `idx_ok` (`ok`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='PG API 호출 이력 — 카드정보는 저장하지 않는다'"
        );
        echo "OK    pg_api_logs 생성\n";
    }

    /**
     * 결제 취소 — `pg_payments` 에 취소 상태·이력 컬럼.
     *
     * 정산이 D+1 이라 **당일 취소는 PG 에서 받아준다**(갑 확인). 승인이 잘못 나갔을 때
     * 우리 시스템에서 바로 되돌릴 수 있어야 하므로, 기존 `status` enum 에 'canceled' 를 더한다.
     * 부분취소(cxl_seq)는 쓰지 않는다 — 우리 결제는 라이더 1명 조달 단위라 쪼갤 이유가 없다.
     */
    private static function migratePgCancel(): void
    {
        echo "== pg_payments 결제취소 ==\n";

        if (!db_table_exists('pg_payments')) {
            echo "SKIP  pg_payments (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM pg_payments'), 'Field');
        $adds = [];
        if (!in_array('canceled_at', $cols, true)) {
            $adds[] = "ADD COLUMN canceled_at DATETIME NULL COMMENT '취소 시각'";
        }
        if (!in_array('cancel_reason', $cols, true)) {
            $adds[] = "ADD COLUMN cancel_reason VARCHAR(300) NOT NULL DEFAULT '' COMMENT '취소 사유(필수 입력)'";
        }
        if (!in_array('canceled_by', $cols, true)) {
            $adds[] = "ADD COLUMN canceled_by INT UNSIGNED NULL COMMENT '취소 실행 관리자'";
        }
        if (!in_array('cancel_trx_id', $cols, true)) {
            $adds[] = "ADD COLUMN cancel_trx_id VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'PG 취소 거래번호'";
        }
        if ($adds !== []) {
            db_execute('ALTER TABLE pg_payments ' . implode(', ', $adds));
            echo 'OK    pg_payments ' . count($adds) . "개 취소 컬럼 추가\n";
        }

        // status enum 확장 — 기존 값은 그대로 두고 'canceled' 만 더한다.
        $type = (string) (db_row("SHOW COLUMNS FROM pg_payments LIKE 'status'")['Type'] ?? '');
        if (!str_contains($type, 'canceled')) {
            db_execute(
                "ALTER TABLE pg_payments
                 MODIFY COLUMN status ENUM('success','failed','canceled') NOT NULL DEFAULT 'failed'
                 COMMENT 'canceled=승인 후 취소(지갑도 되돌림)'"
            );
            echo "OK    status enum 에 canceled 추가\n";
        } else {
            echo "SKIP  status enum (이미 canceled 있음)\n";
        }
    }

    /**
     * 카드 명의자 정보 — `buyer_name`·`buyer_phone`.
     *
     * 빌키 **생성**뿐 아니라 빌키 **결제**에도 PG 필수값이다(없으면 PV422). 그런데 결제는
     * 라이더 조달·프로모션·수동충전 등 여러 경로로 들어오고 그중 「PG 충전」처럼 라이더가
     * 아예 없는 경우도 있다. 매번 어디선가 긁어모으는 대신 **카드에 붙여 저장**한다 —
     * 어차피 그 카드로 긁는 것이므로 명의자는 카드에 종속된 값이 맞다.
     */
    private static function migrateCardBuyer(): void
    {
        echo "== agency_cards 명의자 정보 ==\n";

        if (!db_table_exists('agency_cards')) {
            echo "SKIP  agency_cards (테이블 없음)\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM agency_cards'), 'Field');
        $adds = [];
        if (!in_array('buyer_name', $cols, true)) {
            $adds[] = "ADD COLUMN buyer_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT '카드 명의자 — PG 결제 필수값'";
        }
        if (!in_array('buyer_phone', $cols, true)) {
            $adds[] = "ADD COLUMN buyer_phone VARCHAR(20) NOT NULL DEFAULT '' COMMENT '명의자 연락처(숫자만) — PG 결제 필수값'";
        }
        if ($adds === []) {
            echo "SKIP  명의자 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE agency_cards ' . implode(', ', $adds));
        echo 'OK    agency_cards ' . count($adds) . "개 컬럼 추가\n";
    }

    /**
     * 대용량 대비 인덱스 — `settlement_order_details`.
     *
     * 이 표는 **라이더 1명당 하루 31건**(실측) 쌓인다. 라이더 2,000명이면 하루 6.3만 건,
     * 연 2,280만 건이다. 그런데 「오더별 상세 내역」의 기본 조회는 기간 범위인데
     * `settlement_date` 단독 인덱스가 없어 **풀스캔 + filesort** 로 돌고 있었다
     * (2026-08-25 EXPLAIN: type=ALL, Using filesort). 지금은 9천 건이라 티가 안 나지만
     * 수백만 건이 되면 화면이 멈춘다.
     *
     * `(settlement_date, id)` 로 잡는 이유: 화면이 `ORDER BY settlement_date DESC, id DESC`
     * 로 정렬하므로 정렬까지 인덱스로 끝나 filesort 가 사라진다.
     */
    private static function migrateOrderDetailScaleIndexes(): void
    {
        echo "== settlement_order_details 대용량 인덱스 ==\n";

        if (!db_table_exists('settlement_order_details')) {
            echo "SKIP  테이블 없음\n";

            return;
        }

        $have = [];
        foreach (db_rows('SHOW INDEX FROM settlement_order_details') as $i) {
            $have[(string) $i['Key_name']] = true;
        }

        $adds = [];
        if (!isset($have['idx_sod_date_id'])) {
            $adds[] = 'ADD INDEX idx_sod_date_id (settlement_date, id)';
        }
        // 대리점 스코프 조회는 uploads 를 거쳐 걸리므로 그쪽도 함께 본다.
        if (!isset($have['idx_sod_upload_date'])) {
            $adds[] = 'ADD INDEX idx_sod_upload_date (upload_id, settlement_date)';
        }

        if ($adds === []) {
            echo "SKIP  인덱스 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE settlement_order_details ' . implode(', ', $adds));
        echo 'OK    ' . count($adds) . "개 인덱스 추가\n";
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

        // ⚠️ 신규 설치 보정 — enc_key·enc_iv·noti_allow_ips 는 원래 migratePgWebhook() 에서
        //    ALTER 로 추가하는데, 그 함수는 호출 순서가 **여기보다 앞**이라 새 DB 에서는
        //    "pg_config 없음"으로 건너뛴다. 그 결과 테이블이 이 세 컬럼 없이 만들어져
        //    PG 설정 저장이 `Unknown column 'enc_key'` 로 실패했다(2026-09-02 oxpay.kr 신규 구축에서 발견).
        //    테이블이 확실히 존재하는 이 시점에서 다시 점검해, 신규 설치도 이미 만들어진 DB 도 복구된다.
        $pgCols = array_column(db_rows('SHOW COLUMNS FROM pg_config'), 'Field');
        $lateAdds = [];
        if (!in_array('enc_key', $pgCols, true)) {
            $lateAdds[] = "ADD COLUMN enc_key VARCHAR(255) NOT NULL DEFAULT '' COMMENT '외부연동 암호화 KEY(AES)'";
        }
        if (!in_array('enc_iv', $pgCols, true)) {
            $lateAdds[] = "ADD COLUMN enc_iv VARCHAR(64) NOT NULL DEFAULT '' COMMENT '외부연동 Initialization Vector'";
        }
        if (!in_array('noti_allow_ips', $pgCols, true)) {
            $lateAdds[] = "ADD COLUMN noti_allow_ips VARCHAR(255) NOT NULL DEFAULT '221.168.33.227'"
                . " COMMENT '결제통지 허용 IP(쉼표 구분). 비우면 IP 검사 안 함'";
        }
        if ($lateAdds !== []) {
            db_execute('ALTER TABLE pg_config ' . implode(', ', $lateAdds));
            echo 'OK    pg_config 누락 컬럼 ' . count($lateAdds) . "개 보정\n";
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


    /**
     * 비밀값 암호화 이관 — 컬럼 폭 확장 + 기존 평문 행 암호화.
     *
     * 암호문은 평문보다 길다(`enc:v1:` + base64(iv+tag+본문) ≈ 원문 40자 → 99자).
     * 계좌 컬럼이 varchar(40) 이라 **폭을 먼저 늘리지 않으면 암호문이 잘려 들어가** 복구가
     * 불가능해진다. 그래서 ALTER 를 반드시 먼저 한다.
     *
     * 이미 암호화된 행(`enc:v1:` 접두사)은 건너뛴다 — 여러 번 돌려도 안전하다.
     * `APP_ENC_KEY` 가 없으면 아무것도 하지 않고 안내만 한다(평문으로 남는 게 잘린 것보다 낫다).
     */
    private static function migrateSecretEncryption(): void
    {
        echo "== 비밀값 암호화(계좌·PG키·빌키) ==\n";

        require_once __DIR__ . '/Crypto.php';
        if (!Crypto::available()) {
            echo "  ! APP_ENC_KEY 가 없어 건너뜁니다. php tools/gen_enc_key.php 로 만들어 .env 에 넣고 다시 실행하세요.\n";

            return;
        }

        // ── 1단계: 암호문이 들어갈 폭 확보 ──
        $widen = [
            ['riders', 'bank_account', 'VARCHAR(255)'],
            ['withdrawal_requests', 'bank_account', 'VARCHAR(255)'],
            ['agency_bank_accounts', 'account_no', 'VARCHAR(255)'],
            ['agency_bank_accounts', 'fintech_use_num', 'VARCHAR(255)'],
            // 대사 토큰은 JWT 라 길 수 있다 → 255 로는 모자랄 수 있어 TEXT 로 둔다.
            ['pg_config', 'access_token', 'TEXT'],
            ['daily_payouts', 'bank_account', 'VARCHAR(255)'],
            // pg_config 비밀값도 암호문이 원문보다 길어진다. enc_iv 는 varchar(64) 라
            // 16자짜리 IV 를 암호화하면(≈66자) 바로 넘친다 — 실제로 1406 으로 막혔다.
            ['pg_config', 'pay_key', 'VARCHAR(255)'],
            ['pg_config', 'sign_key', 'VARCHAR(255)'],
            ['pg_config', 'api_key', 'VARCHAR(255)'],
            ['pg_config', 'enc_key', 'VARCHAR(255)'],
            ['pg_config', 'enc_iv', 'VARCHAR(255)'],
            ['pg_config', 'login_pw', 'VARCHAR(255)'],
        ];
        foreach ($widen as [$table, $col, $type]) {
            if (!db_table_exists($table)) {
                continue;
            }
            $cur = db_row(
                'SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]
            );
            if ($cur === null || strcasecmp((string) $cur['t'], $type) === 0) {
                continue;
            }
            db_execute("ALTER TABLE `{$table}` MODIFY `{$col}` {$type} NULL");
            echo "  + {$table}.{$col} → {$type}\n";
        }

        // ── 2단계: 기존 평문 행 암호화 ──
        $targets = [
            ['riders', 'id', ['bank_account']],
            ['withdrawal_requests', 'id', ['bank_account']],
            ['agency_bank_accounts', 'agency_id', ['account_no', 'fintech_use_num']],
            ['agency_cards', 'id', ['billing_key']],
            ['settlement_excel_config', 'id', ['open_password']],
            ['daily_payouts', 'id', ['bank_account']],
            ['pg_config', 'id', ['pay_key', 'sign_key', 'api_key', 'enc_key', 'enc_iv', 'login_pw', 'access_token']],
        ];
        foreach ($targets as [$table, $pk, $cols]) {
            if (!db_table_exists($table)) {
                continue;
            }
            $have = array_column(db_rows("SHOW COLUMNS FROM `{$table}`"), 'Field');
            $cols = array_values(array_intersect($cols, $have));
            if ($cols === []) {
                continue;
            }
            $sel  = '`' . implode('`, `', $cols) . '`';
            $rows = db_rows("SELECT `{$pk}` AS __pk, {$sel} FROM `{$table}`");
            $n    = 0;
            foreach ($rows as $r) {
                $set = [];
                $val = [];
                foreach ($cols as $c) {
                    $v = (string) ($r[$c] ?? '');
                    if ($v === '' || Crypto::isEncrypted($v)) {
                        continue;
                    }
                    $set[] = "`{$c}` = ?";
                    $val[] = Crypto::encrypt($v);
                }
                if ($set === []) {
                    continue;
                }
                $val[] = $r['__pk'];
                db_execute("UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE `{$pk}` = ?", $val);
                $n++;
            }
            echo $n > 0 ? "  + {$table}: {$n}행 암호화\n" : "  = {$table}: 이관할 평문 없음\n";
        }
    }


    /**
     * 펌뱅킹(바움P&S) 연동 설정 + API 호출 로그.
     *
     * 비밀값은 `Crypto`(APP_ENC_KEY)로 암호화해 저장하므로 컬럼을 넉넉히 잡는다
     * (원문 40자 → 약 99자. 좁게 잡으면 잘려 들어가 복구가 안 된다).
     */
    private static function migrateFirmBanking(): void
    {
        echo "== 펌뱅킹(바움P&S) 연동 ==\n";

        if (!db_table_exists('firm_config')) {
            db_execute(
                "CREATE TABLE `firm_config` (
                    `id`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `driver`           VARCHAR(20)  NOT NULL DEFAULT 'mock' COMMENT 'mock | baum',
                    `env`              VARCHAR(10)  NOT NULL DEFAULT 'dev'  COMMENT 'dev | prod',
                    `client_id`        VARCHAR(190) NOT NULL DEFAULT ''     COMMENT '식별자라 평문',
                    `secret_key`       VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '🔒 암호화 저장',
                    `enc_key`          VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '🔒 바움 발급 AES KEY(Base64)',
                    `enc_iv`           VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '🔒 바움 발급 AES IV(Base64)',
                    `pocket_code`      VARCHAR(40)  NOT NULL DEFAULT ''     COMMENT '출금 포켓(비우면 기본 포켓)',
                    `noti_allow_ips`   VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '처리결과 통보 허용 IP',
                    `access_token`     TEXT         NULL                    COMMENT '🔒 암호화 저장',
                    `token_expires_at` DATETIME     NULL,
                    `updated_by`       INT UNSIGNED NULL,
                    `updated_at`       DATETIME     NULL,
                    PRIMARY KEY (`id`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            db_execute('INSERT INTO `firm_config` (`id`) VALUES (1)');
            echo "  + firm_config 생성(기본 mock/dev)\n";
        } else {
            echo "SKIP  firm_config (이미 있음)\n";
        }

        if (!db_table_exists('firm_api_logs')) {
            db_execute(
                "CREATE TABLE `firm_api_logs` (
                    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `endpoint`      VARCHAR(120) NOT NULL DEFAULT '',
                    `method`        VARCHAR(10)  NOT NULL DEFAULT '',
                    `ref`           VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'transactionId 등 추적 키',
                    `http_code`     SMALLINT     NOT NULL DEFAULT 0,
                    `result_code`   VARCHAR(40)  NOT NULL DEFAULT '',
                    `result_msg`    VARCHAR(300) NOT NULL DEFAULT '',
                    `ok`            TINYINT(1)   NOT NULL DEFAULT 0,
                    `duration_ms`   INT          NOT NULL DEFAULT 0,
                    `request_body`  TEXT         NULL COMMENT '복호화된 평문(민감값 마스킹 후)',
                    `response_body` TEXT         NULL,
                    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_firmlog_created` (`created_at`),
                    KEY `idx_firmlog_ref` (`ref`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "  + firm_api_logs 생성\n";
        } else {
            echo "SKIP  firm_api_logs (이미 있음)\n";
        }
    }


    /**
     * 펌뱅킹 비동기 이체 추적 — 접수와 결과 확정을 잇는 장부.
     *
     * 바움 API 는 접수(RECEPTION)만 즉시 응답하고 성공/실패는 웹훅으로 온다.
     * 그 사이를 이어 주는 게 이 표다. 웹훅이 오면 `transaction_id` 로 우리 건을 찾고,
     * 웹훅이 유실되면 여기 남은 미확정 행을 `transfer-info` 로 재조회한다.
     *
     * 출금(withdrawal)뿐 아니라 일일지급·대리점 인출도 같은 게이트웨이를 쓰므로
     * `kind` + `ref_id` 로 어느 건인지 가리키는 공용 표로 만든다.
     */
    private static function migrateFirmTransfers(): void
    {
        echo "== 펌뱅킹 이체 추적 ==\n";

        if (!db_table_exists('firm_transfers')) {
            db_execute(
                "CREATE TABLE `firm_transfers` (
                    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `transaction_id`  VARCHAR(60)  NOT NULL COMMENT '우리가 만든 고유 ID(바움 조회·취소 키)',
                    `reception_id`    VARCHAR(60)  NOT NULL DEFAULT '' COMMENT '바움 접수 ID',
                    `kind`            VARCHAR(20)  NOT NULL COMMENT 'withdrawal | daily_payout | agency_payout',
                    `ref_id`          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '해당 표의 id',
                    `agency_id`       INT UNSIGNED NULL,
                    `rider_id`        INT UNSIGNED NULL,
                    `amount`          INT          NOT NULL DEFAULT 0,
                    `bank_code`       VARCHAR(10)  NOT NULL DEFAULT '',
                    `account_masked`  VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '뒤 4자리만 — 대사 확인용',
                    `status`          VARCHAR(20)  NOT NULL DEFAULT 'RECEPTION',
                    `fail_reason`     VARCHAR(300) NOT NULL DEFAULT '',
                    `submitted_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `finalized_at`    DATETIME     NULL COMMENT '결과가 확정된 시각',
                    `last_checked_at` DATETIME     NULL COMMENT '보정 조회를 마지막으로 돌린 시각',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_firm_tx` (`transaction_id`),
                    KEY `idx_firm_tx_ref` (`kind`, `ref_id`),
                    KEY `idx_firm_tx_status` (`status`, `submitted_at`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "  + firm_transfers 생성\n";
        } else {
            echo "SKIP  firm_transfers (이미 있음)\n";
        }

        if (!db_table_exists('firm_webhook_events')) {
            db_execute(
                "CREATE TABLE `firm_webhook_events` (
                    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `transaction_id` VARCHAR(60)  NOT NULL DEFAULT '',
                    `reception_id`   VARCHAR(60)  NOT NULL DEFAULT '',
                    `transfer_status` VARCHAR(20) NOT NULL DEFAULT '',
                    `amount`         INT          NOT NULL DEFAULT 0,
                    `amount_sign`    VARCHAR(2)   NOT NULL DEFAULT '' COMMENT '- 출금 / + 입금',
                    `matched`        TINYINT(1)   NOT NULL DEFAULT 0,
                    `note`           VARCHAR(300) NOT NULL DEFAULT '',
                    `source_ip`      VARCHAR(45)  NOT NULL DEFAULT '',
                    `raw_body`       TEXT         NULL COMMENT '복호화된 평문(계좌번호 마스킹)',
                    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_firm_wh_tx` (`transaction_id`),
                    KEY `idx_firm_wh_created` (`created_at`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            echo "  + firm_webhook_events 생성\n";
        } else {
            echo "SKIP  firm_webhook_events (이미 있음)\n";
        }

        // 출금 상태에 '이체 접수됨(결과 대기)' 를 추가한다.
        // ⚠️ 이 상태가 없으면 접수만 된 건을 'completed' 로 찍게 되고, 지갑이 먼저 깎인다.
        $col = db_row(
            "SELECT COLUMN_TYPE t FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawal_requests' AND COLUMN_NAME = 'status'"
        );
        if ($col !== null && !str_contains((string) $col['t'], "'transferring'")) {
            db_execute(
                "ALTER TABLE `withdrawal_requests`
                 MODIFY `status` ENUM('pending','downloaded','transferring','completed','rejected','failed')
                 NOT NULL DEFAULT 'pending'"
            );
            echo "  + withdrawal_requests.status 에 transferring 추가\n";
        } else {
            echo "SKIP  withdrawal_requests.status (이미 transferring 있음)\n";
        }
    }


    /** 라이더 계좌 확인 기록 — 「예금주 조회」로 실재를 확인한 시각과 그때의 예금주명. */
    private static function migrateAccountVerified(): void
    {
        echo "== 라이더 계좌 확인 기록 ==\n";

        if (!db_table_exists('riders')) {
            echo "SKIP  riders 없음\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
        $adds = [];
        if (!in_array('bank_verified_at', $cols, true)) {
            $adds[] = "ADD COLUMN `bank_verified_at` DATETIME NULL COMMENT '예금주 조회로 확인한 시각'";
        }
        if (!in_array('bank_verified_name', $cols, true)) {
            $adds[] = "ADD COLUMN `bank_verified_name` VARCHAR(80) NOT NULL DEFAULT '' COMMENT '확인 당시 예금주명'";
        }
        if ($adds === []) {
            echo "SKIP  riders 계좌 확인 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE `riders` ' . implode(', ', $adds));
        echo '  + riders 계좌 확인 컬럼 ' . count($adds) . "개\n";
    }


    /**
     * 펌뱅킹 자격증명을 **환경별로** 나눠 저장.
     *
     * 바움은 개발/운영 서버의 Client ID·Secret·암호화 KEY/IV·포켓코드가 **전부 다르다**.
     * 한 벌만 저장하면 「서버」를 운영으로 바꾸는 순간 개발 자격증명이 따라가 버린다.
     * 인증이 실패하니 사고는 안 나지만, 왜 안 되는지 알기 어렵다.
     */
    private static function migrateFirmEnvCredentials(): void
    {
        echo "== 펌뱅킹 환경별 자격증명 ==\n";

        if (!db_table_exists('firm_config')) {
            echo "SKIP  firm_config 없음\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM firm_config'), 'Field');
        $adds = [];
        foreach (['dev', 'prod'] as $env) {
            foreach ([
                'client_id'   => "VARCHAR(190) NOT NULL DEFAULT ''",
                'secret_key'  => "VARCHAR(255) NOT NULL DEFAULT ''",
                'enc_key'     => "VARCHAR(255) NOT NULL DEFAULT ''",
                'enc_iv'      => "VARCHAR(255) NOT NULL DEFAULT ''",
                'pocket_code' => "VARCHAR(40) NOT NULL DEFAULT ''",
            ] as $c => $type) {
                $name = $env . '_' . $c;
                if (!in_array($name, $cols, true)) {
                    $adds[] = "ADD COLUMN `{$name}` {$type}";
                }
            }
        }
        if ($adds === []) {
            echo "SKIP  환경별 자격증명 컬럼 (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE `firm_config` ' . implode(', ', $adds));
        echo '  + 환경별 자격증명 컬럼 ' . count($adds) . "개\n";

        // 기존 단일 자격증명을 **현재 env 쪽으로** 옮긴다. 지금까지 쓰던 값이 어느
        // 서버의 것인지는 `env` 가 말해 준다.
        $row = db_row('SELECT env, client_id, secret_key, enc_key, enc_iv, pocket_code FROM firm_config WHERE id = 1');
        if ($row !== null) {
            $env = ((string) $row['env']) === 'prod' ? 'prod' : 'dev';
            db_execute(
                "UPDATE firm_config
                    SET `{$env}_client_id` = ?, `{$env}_secret_key` = ?, `{$env}_enc_key` = ?,
                        `{$env}_enc_iv` = ?, `{$env}_pocket_code` = ?
                  WHERE id = 1",
                [
                    (string) $row['client_id'], (string) $row['secret_key'], (string) $row['enc_key'],
                    (string) $row['enc_iv'], (string) $row['pocket_code'],
                ]
            );
            echo "  + 기존 자격증명을 {$env} 쪽으로 이관\n";
        }
    }


    /**
     * 미수금 일납 자동부과 **기준일 세팅** — DB당 딱 한 번만 실행된다(2026-09-04).
     *
     * 이날 대여금·선지급금까지 자동부과 대상이 되고 계산도 달력일 기준으로 바뀌면서,
     * 그동안 부과되지 않았던 과거 구간이 다음 정산 반영 때 한꺼번에 청구될 수 있었다
     * (개발 DB 실측 6,837,000원 / 24건). 갑 지시는 **"소급은 안해도되"** 이므로,
     * 기존 미수금의 마지막 반영일을 오늘로 당겨 **과거분을 청구하지 않고** 오늘 이후만 쌓이게 한다.
     *
     * 마커 테이블 존재 여부로 1회성을 보장한다 — 두 번째 실행부터는 아무것도 하지 않는다
     * (매번 돌면 기준일이 계속 밀려 정상 부과분까지 사라진다).
     */
    private static function migrateDebtAccrualBaseline(): void
    {
        echo "== 미수금 자동부과 기준일 세팅 ==\n";

        if (!db_table_exists('rider_debts')) {
            echo "SKIP  rider_debts 없음\n";

            return;
        }
        if (db_table_exists('rider_debt_accrual_baseline')) {
            echo "SKIP  이미 기준일이 잡혀 있음(1회성)\n";

            return;
        }

        $n = db_execute(
            "UPDATE rider_debts
                SET due_updated_on = CURDATE()
              WHERE status = 'active' AND daily_amount > 0
                AND (due_updated_on IS NULL OR due_updated_on < CURDATE())"
        );

        db_execute(
            "CREATE TABLE rider_debt_accrual_baseline (
                id         TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
                applied_on DATE NOT NULL,
                rows_set   INT  NOT NULL DEFAULT 0,
                note       VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='미수금 일납 자동부과 기준일(1회성 마커)'"
        );
        db_insert(
            'INSERT INTO rider_debt_accrual_baseline (id, applied_on, rows_set, note) VALUES (1, CURDATE(), ?, ?)',
            [$n, '2026-09-04 갑 지시 "소급은 안해도되" — 과거 미부과분 청구하지 않음']
        );

        echo "OK    기준일 = " . date('Y-m-d') . " · 대상 {$n}건 (과거분 청구 안 함)\n";
    }

    /**
     * 라이더 차감 이월 원장 — 그날 정산액이 정액 차감보다 적을 때 못 걷은 금액을 담는다.
     * 이전에는 net = max(0, base - fee) 로 초과분이 증발했다(실측 9건 135,034원).
     */
    private static function migrateCarryForward(): void
    {
        echo "== 라이더 차감 이월 원장 ==\n";

        if (db_table_exists('rider_carry_forward')) {
            echo "SKIP  rider_carry_forward (이미 있음)\n";

            return;
        }
        db_execute(
            "CREATE TABLE rider_carry_forward (
                id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                rider_id           INT UNSIGNED NOT NULL,
                origin_cycle_id    INT UNSIGNED NULL COMMENT '이월이 발생한 정산 사이클',
                collected_cycle_id INT UNSIGNED NULL COMMENT '회수가 시작된 정산 사이클',
                fee_code           VARCHAR(40)  NOT NULL COMMENT '원래 차감 코드(excel_deduction 등)',
                label              VARCHAR(100) NOT NULL DEFAULT '',
                amount             INT NOT NULL COMMENT '최초 이월액',
                remaining_amount   INT NOT NULL COMMENT '아직 못 걷은 금액',
                closed_at          DATETIME NULL,
                updated_at         DATETIME NULL,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rcf_rider (rider_id, remaining_amount),
                KEY idx_rcf_origin (origin_cycle_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
               COMMENT='라이더 차감 이월(정산액 부족으로 못 걷은 정액 차감)'"
        );
        echo "OK    rider_carry_forward 생성\n";
    }

    /**
     * 리스를 상각(잔액) 모델로 전환 — 총액·잔액 백필 (2026-09-04 갑 확정).
     *
     * 예전엔 리스에 잔액을 두지 않아(항상 0) 잔여 리스료가 미수금 집계에 안 잡혔다
     * (실측 15,030,000원). 리스 계약서 제5조가 "일일 리스료 × 365일 = 예정 총액"을
     * 명시하는 구조이므로 대여금과 같은 상각 모델로 통일한다.
     *
     *   총액 = 일납 × 계약일수(개시일~종료예정일, 양끝 포함)
     *   잔액 = 총액 − 이미 걷은 금액(rider_debt_entries 합)
     *
     * 멱등: principal_amount 가 이미 채워진 리스는 건너뛴다.
     */
    private static function normDateOrNull(mixed $v): ?string
    {
        $x = trim((string) ($v ?? ''));
        if ($x === '') { return null; }
        $d = DateTime::createFromFormat('Y-m-d', substr($x, 0, 10));

        return ($d && $d->format('Y-m-d') === substr($x, 0, 10)) ? substr($x, 0, 10) : null;
    }

    private static function migrateLeaseBalance(): void
    {
        echo "== 리스 상각 모델 전환(총액·잔액 백필) ==\n";

        if (!db_table_exists('rider_debts')) {
            echo "SKIP  rider_debts 없음\n";

            return;
        }
        $rows = db_rows(
            "SELECT id, daily_amount, opened_on, planned_end_on, due_updated_on
               FROM rider_debts
              WHERE kind = 'lease' AND principal_amount = 0
                AND daily_amount > 0 AND opened_on IS NOT NULL AND planned_end_on IS NOT NULL"
        );
        if ($rows === []) {
            echo "SKIP  전환할 리스 없음\n";

            return;
        }

        $done = 0;
        $sum  = 0;
        foreach ($rows as $r) {
            $id    = (int) $r['id'];
            $daily = (int) $r['daily_amount'];
            $days  = (int) ((new DateTime((string) $r['opened_on']))
                        ->diff(new DateTime((string) $r['planned_end_on']))->days) + 1;
            $principal = $daily * $days;
            // 잔액은 **앞으로 실제로 부과될 금액**으로 잡는다 — 마지막 반영일 다음날부터 종료예정일까지.
            // 총액(principal)은 계약서 그대로 두고, 그 차이는 2026-09-04 "소급은 안해도되" 결정으로
            // 청구하지 않기로 한 과거분이다. 이렇게 해야 계약 만료 시 잔액이 0이 되어 정상 완납된다.
            $from    = self::normDateOrNull($r['due_updated_on'] ?? null)
                       ?? (new DateTime((string) $r['opened_on']))->modify('-1 day')->format('Y-m-d');
            $remain  = (int) ((new DateTime($from))->diff(new DateTime((string) $r['planned_end_on']))->days);
            $balance = $from >= (string) $r['planned_end_on'] ? 0 : max(0, $remain * $daily);
            db_execute(
                'UPDATE rider_debts SET principal_amount = ?, balance_amount = ? WHERE id = ?',
                [$principal, $balance, $id]
            );
            $done++;
            $sum += $balance;
        }
        echo "OK    리스 {$done}건 전환 · 잔여 리스료 합 " . number_format($sum) . "원\n";
    }

    /**
     * 신규 설치 기본 데이터 — 본사 조직 · 최고관리자 · 시스템 코드 (2026-09-05).
     *
     * seed.php 를 제거하면서(갑 지시) 그 안에 있던 것 중 **시스템이 돌아가는 데 꼭 필요한 것만**
     * 여기로 옮겼다. 샘플 총판·대리점은 옮기지 않는다 — 갑: "기본적인 총판 대리점은 필요없고".
     *
     * 특히 **본사 조직이 없으면 Org::hqId() 가 실패**해 대행수수료 본사 귀속 같은 지갑 이동이
     * 통째로 깨진다. 계정보다 이쪽이 더 치명적이라 반드시 함께 만든다.
     *
     * 전부 "없을 때만" 만들므로 재실행해도 되살아나지 않는다 — seed.php 가 멱등하지 않아
     * 배포 버튼에 못 넣었던 문제가 여기서는 생기지 않는다.
     */
    private static function migrateBootstrapDefaults(): void
    {
        echo "== 신규 설치 기본 데이터 ==\n";

        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations 없음\n";

            return;
        }

        // ── 1) 본사 조직 ──────────────────────────────────────────────
        $hq = db_row("SELECT id FROM organizations WHERE level = 'admin' ORDER BY id ASC LIMIT 1");
        if ($hq === null) {
            // `code` 에 UNIQUE(uq_org_code)가 걸려 있다. 'HQ' 가 이미 다른 조직에 쓰였으면
            // 그대로 INSERT 하다 1062 로 죽어 **마이그레이션 전체가 중단**된다 — 다른 코드를 쓴다.
            $code = db_row("SELECT id FROM organizations WHERE code = 'HQ' LIMIT 1") === null
                ? 'HQ'
                : 'HQ-' . date('ymdHis');
            $hqId = db_insert(
                "INSERT INTO organizations (parent_id, level, code, name, is_active, created_at)
                 VALUES (NULL, 'admin', ?, 'OXPAY 본사', 1, NOW())",
                [$code]
            );
            echo "OK    본사 조직 생성(id={$hqId}, code={$code})\n";
        } else {
            $hqId = (int) $hq['id'];
            echo "SKIP  본사 조직 (이미 있음, id={$hqId})\n";
        }

        // ── 2) 최고관리자 계정 ────────────────────────────────────────
        // 비밀번호는 환경변수 ADMIN_INIT_PASSWORD 를 우선 쓴다. 없으면 기본값을 쓰되
        // **즉시 바꾸라고 크게 알린다** — 알려진 비밀번호가 운영에 남는 건 위험하다.
        if (db_table_exists('admins')) {
            $cols   = array_column(db_rows('SHOW COLUMNS FROM admins'), 'Field');
            $hasOrg = in_array('org_id', $cols, true);
            $super  = db_row("SELECT id FROM admins WHERE role = 'super' LIMIT 1");
            if ($super !== null) {
                echo "SKIP  최고관리자 (이미 있음)\n";
            } elseif (!$hasOrg) {
                echo "SKIP  최고관리자 (admins.org_id 아직 없음)\n";
            } else {
                $env   = (string) (getenv('ADMIN_INIT_PASSWORD') ?: '');
                $isEnv = $env !== '';
                $pw    = $isEnv ? $env : 'Admin1234!';
                db_insert(
                    "INSERT INTO admins (login_id, password_hash, name, role, org_id, is_active)
                     VALUES ('admin', ?, '최고관리자', 'super', ?, 1)",
                    [password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]), $hqId]
                );
                echo "OK    최고관리자 계정 생성(admin)\n";
                echo $isEnv
                    ? "      비밀번호: ADMIN_INIT_PASSWORD 환경변수 값\n"
                    : "      ⚠️ 비밀번호가 기본값(Admin1234!)입니다 — 로그인 후 즉시 변경하세요.\n";
            }
        }

        // ── 3) 시스템 코드 ────────────────────────────────────────────
        if (!db_table_exists('system_codes')) {
            echo "SKIP  system_codes 없음\n";

            return;
        }
        $codes = [
            ['bank', '004', '국민은행', 10],    ['bank', '088', '신한은행', 20],
            ['bank', '020', '우리은행', 30],    ['bank', '090', '카카오뱅크', 40],
            ['bank', '081', '하나은행', 50],    ['bank', '011', '농협', 60],
            ['bank', '003', 'IBK기업은행', 70], ['bank', '092', '토스뱅크', 80],
            ['bank', '023', 'SC제일은행', 90],  ['bank', '032', '부산은행', 100],
            ['bank', '039', '경남은행', 110],   ['bank', '045', '새마을금고', 120],
            ['bank', '071', '우체국', 130],

            ['vehicle', 'motor', '오토바이', 10], ['vehicle', 'bike', '자전거', 20],
            ['vehicle', 'kick', '전동킥보드', 30], ['vehicle', 'car', '자동차', 40],
            ['vehicle', 'walk', '도보', 50],

            ['rider_status', 'active', '활동 중', 10],
            ['rider_status', 'suspended', '일시 정지', 20],
            ['rider_status', 'leave_request', '탈퇴 요청', 30],
            ['rider_status', 'offboarded', '계약 종료', 40],

            ['settlement_status', 'uploaded', '업로드됨', 10],
            ['settlement_status', 'parsing', '파싱 중', 20],
            ['settlement_status', 'parsed', '파싱 완료', 30],
            ['settlement_status', 'applied', '반영 완료', 40],
            ['settlement_status', 'error', '오류', 50],

            ['withdrawal_status', 'pending', '대기', 10],
            ['withdrawal_status', 'downloaded', '다운로드 완료', 20],
            ['withdrawal_status', 'completed', '처리 완료', 30],
            ['withdrawal_status', 'rejected', '반려', 40],

            ['platform', 'baemin', '배달의민족', 10],
            ['platform', 'coupang', '쿠팡이츠', 20],
            ['platform', 'other', '기타', 30],

            ['deduction_kind', 'withholding', '원천세', 10],
            ['deduction_kind', 'employment_ins', '고용·산재', 20],
            ['deduction_kind', 'agency_fee', '정산 수수료', 30],
            ['deduction_kind', 'hourly_ins', '시간제 보험', 40],
            ['deduction_kind', 'ins_refund', '보험료 환급', 50],
            ['deduction_kind', 'rental', '대여금 차감', 60],
            ['deduction_kind', 'advance', '선지급 정산', 70],
            ['deduction_kind', 'manual', '수동 조정', 80],
        ];
        $added = 0;
        foreach ($codes as [$cat, $code, $label, $sort]) {
            if (db_row('SELECT id FROM system_codes WHERE category = ? AND code = ? LIMIT 1', [$cat, $code]) !== null) {
                continue;
            }
            db_insert(
                'INSERT INTO system_codes (category, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, 1)',
                [$cat, $code, $label, $sort]
            );
            $added++;
        }
        echo $added > 0 ? "OK    시스템 코드 {$added}건 추가\n" : "SKIP  시스템 코드 (모두 있음)\n";
    }

    /**
     * 세무신고용 필드 (2026-09-05 갑).
     *
     * 세무대리가 대리점별 「세무신고용」 엑셀을 뽑을 때 라이더마다 두 가지를 더 본다:
     *   · 세금신고 유무   — 체크박스. 신고 대상인지 여부만 판단하면 된다.
     *   · 금액조정필요    — 자유 텍스트. 나중에 확인할 메모를 남긴다.
     *
     * `withholding_tax_enabled`(정산 시 3.3% 공제 여부)와는 **다른 값**이다 —
     * 이쪽은 돈 계산, 저쪽은 신고 대상 판단이라 각각 켤 수 있어야 한다.
     *
     * 기본값은 1(신고 대상) — 기존 라이더가 전부 「미신고」로 보이면 안 되기 때문이다.
     * 예외만 체크를 풀어 관리한다.
     *
     * 세무비용 단가는 「총 콜수 × 단가」로 청구액을 내는 데 쓴다(샘플 파일 기준 15원/콜).
     */
    private static function migrateTaxReportFields(): void
    {
        echo "== 세무신고용 필드 ==\n";

        if (db_table_exists('riders')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM riders'), 'Field');
            if (in_array('tax_report_enabled', $cols, true)) {
                echo "SKIP  riders.tax_report_enabled (이미 있음)\n";
            } else {
                db_execute(
                    "ALTER TABLE riders
                        ADD COLUMN tax_report_enabled TINYINT(1) NOT NULL DEFAULT 1
                            COMMENT '세금신고 대상 여부(세무대리 판단용) — 원천세 공제 여부와 별개'
                        AFTER withholding_tax_enabled"
                );
                echo "OK    riders.tax_report_enabled 추가\n";
            }
            if (in_array('tax_adjust_note', $cols, true)) {
                echo "SKIP  riders.tax_adjust_note (이미 있음)\n";
            } else {
                db_execute(
                    "ALTER TABLE riders
                        ADD COLUMN tax_adjust_note VARCHAR(255) NULL
                            COMMENT '금액조정필요 — 세무신고 시 참고할 자유 메모'
                        AFTER tax_report_enabled"
                );
                echo "OK    riders.tax_adjust_note 추가\n";
            }
        }

        if (db_table_exists('deduction_global_config')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
            if (in_array('tax_fee_per_call', $cols, true)) {
                echo "SKIP  deduction_global_config.tax_fee_per_call (이미 있음)\n";
            } else {
                db_execute(
                    "ALTER TABLE deduction_global_config
                        ADD COLUMN tax_fee_per_call INT NOT NULL DEFAULT 15
                            COMMENT '세무비용 단가(원/콜) — 세무신고용 파일의 최종 세무 비용 산출'"
                );
                echo "OK    deduction_global_config.tax_fee_per_call 추가(기본 15원/콜)\n";
            }
        }
    }

    /**
     * 정산수수료 배분에 **세무대리 몫** 추가 (2026-09-05 갑).
     *
     * 갑: "정산수수료 배분에서 본사 총판 세무대리 이렇게 3개로 하고 세무대리꺼는
     *      세무대리 지갑으로 보내면 어때?"
     *
     * 본사·총판과 같은 구조(기준 미만/이상 배달 건당 정액). 기본 0 이라 설정을 넣기 전까지는
     * 지금과 동작이 완전히 같다.
     */
    private static function migrateTaxFeeShare(): void
    {
        echo "== 정산수수료 세무대리 몫 ==\n";

        if (!db_table_exists('withdrawal_config')) {
            echo "SKIP  withdrawal_config 없음\n";

            return;
        }
        $cols = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
        $adds = [];
        if (!in_array('tax_fee_short', $cols, true)) {
            $adds[] = "ADD COLUMN tax_fee_short INT NOT NULL DEFAULT 0 COMMENT '세무대리 몫 — 기준 미만 배달 건당(원)'";
        }
        if (!in_array('tax_fee_long', $cols, true)) {
            $adds[] = "ADD COLUMN tax_fee_long INT NOT NULL DEFAULT 0 COMMENT '세무대리 몫 — 기준 이상 배달 건당(원)'";
        }
        if ($adds === []) {
            echo "SKIP  tax_fee_short/long (이미 있음)\n";

            return;
        }
        db_execute('ALTER TABLE withdrawal_config ' . implode(', ', $adds));
        echo "OK    withdrawal_config 세무대리 몫 컬럼 " . count($adds) . "개 추가(기본 0원)\n";
    }

    /**
     * 개발사 조직 — 정산수수료 배분 몫을 받고 자기 지갑에서 인출한다 (2026-09-05 갑).
     *
     * 갑: "개발사도 하나 추가해줘 세무대리처럼 대행수수료 나눠서 가져가고 지갑에서 출금할수 있도록"
     *     "개발사는 메뉴 권한을 본사 최고관리자랑 동일하게 해줘"
     *
     * 세무대리와 같은 자리 — 조직 트리(본사>총판>대리점) 밖의 단일 독립 조직이다.
     * 메뉴·데이터 권한은 본사와 동일하되(Org::scopeAgencyIds 가 null 을 준다), 지갑만은
     * 자기 것을 본다.
     */
    private static function migrateDeveloperOrg(): void
    {
        echo "== 개발사 조직 ==\n";

        if (!db_table_exists('organizations')) {
            echo "SKIP  organizations 없음\n";

            return;
        }

        // 1) organizations.level 에 'developer' 추가
        $col = db_row("SHOW COLUMNS FROM organizations LIKE 'level'");
        if ($col !== null && !str_contains((string) $col['Type'], 'developer')) {
            db_execute("ALTER TABLE organizations MODIFY COLUMN level ENUM('admin','distributor','agency','tax_agent','developer') NOT NULL");
            echo "OK    organizations.level 에 developer 추가\n";
        }

        // 2) 개발사 조직(단일)
        $dev = db_row("SELECT id FROM organizations WHERE level = 'developer' ORDER BY id ASC LIMIT 1");
        if ($dev === null) {
            $code  = db_row("SELECT id FROM organizations WHERE code = 'DEV' LIMIT 1") === null ? 'DEV' : 'DEV-' . date('ymdHis');
            $devId = db_insert(
                "INSERT INTO organizations (parent_id, level, code, name, is_active, created_at)
                 VALUES (NULL, 'developer', ?, '개발사', 1, NOW())",
                [$code]
            );
            echo "OK    개발사 조직 생성(id={$devId}, code={$code})\n";
        } else {
            $devId = (int) $dev['id'];
            echo "SKIP  개발사 조직 (이미 있음, id={$devId})\n";
        }

        // 3) 대표계정 — 메뉴 권한을 본사 최고관리자와 동일하게 하려고 role='super' 로 만든다.
        if (db_table_exists('admins')) {
            $cols = array_column(db_rows('SHOW COLUMNS FROM admins'), 'Field');
            if (!in_array('org_id', $cols, true)) {
                echo "SKIP  개발사 계정 (admins.org_id 아직 없음)\n";
            } elseif (db_row('SELECT id FROM admins WHERE org_id = ? LIMIT 1', [$devId]) !== null) {
                echo "SKIP  개발사 계정 (이미 있음)\n";
            } elseif (db_row("SELECT id FROM admins WHERE login_id = 'dev' LIMIT 1") !== null) {
                echo "SKIP  개발사 계정 (login_id 'dev' 가 이미 쓰임)\n";
            } else {
                $pw = (string) (getenv('DEV_INIT_PASSWORD') ?: 'Admin1234!');
                db_insert(
                    "INSERT INTO admins (login_id, password_hash, name, role, org_id, is_active)
                     VALUES ('dev', ?, '개발사', 'super', ?, 1)",
                    [password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]), $devId]
                );
                echo "OK    개발사 계정 생성(dev / role=super)\n";
                echo getenv('DEV_INIT_PASSWORD')
                    ? "      비밀번호: DEV_INIT_PASSWORD 환경변수 값\n"
                    : "      ⚠️ 비밀번호가 기본값(Admin1234!)입니다 — 로그인 후 즉시 변경하세요.\n";
            }
        }

        // 4) 지갑
        if (db_table_exists('agency_wallets')) {
            db_execute('INSERT IGNORE INTO agency_wallets (agency_id, balance, withholding_reserve) VALUES (?, 0, 0)', [$devId]);
            echo "OK    개발사 지갑 확인\n";
        }

        // 5) 정산수수료 배분 — 개발사 몫(기본 0원이라 설정 전까지 동작 변화 없음)
        if (db_table_exists('withdrawal_config')) {
            $wc   = array_column(db_rows('SHOW COLUMNS FROM withdrawal_config'), 'Field');
            $adds = [];
            if (!in_array('dev_fee_short', $wc, true)) {
                $adds[] = "ADD COLUMN dev_fee_short INT NOT NULL DEFAULT 0 COMMENT '개발사 몫 — 기준 미만 배달 건당(원)'";
            }
            if (!in_array('dev_fee_long', $wc, true)) {
                $adds[] = "ADD COLUMN dev_fee_long INT NOT NULL DEFAULT 0 COMMENT '개발사 몫 — 기준 이상 배달 건당(원)'";
            }
            if ($adds === []) {
                echo "SKIP  dev_fee_short/long (이미 있음)\n";
            } else {
                db_execute('ALTER TABLE withdrawal_config ' . implode(', ', $adds));
                echo "OK    withdrawal_config 개발사 몫 컬럼 " . count($adds) . "개 추가(기본 0원)\n";
            }
        }
    }

    /**
     * 펌뱅킹 이체 은행코드 (2026-09-06 갑 제공).
     *
     * 갑: "이체 은행코드야 ... 이체은행은 일반은행 코드와 다르게 이 코드로 보내야해"
     *     "펌뱅킹할때 쓰는 은행코드라고 보면되"
     *
     * 두 곳에 넣는다:
     *   1) `transfer_bank` — 펌뱅킹에 실제로 보내는 `C###` 코드. **이쪽이 원본**이다.
     *   2) `bank` — 라이더·대리점이 계좌를 등록할 때 고르는 3자리 코드.
     *      기존에 13개뿐이라 증권사·저축은행 계좌는 아예 등록할 수 없었다.
     *
     * ⚠️ 목록에 **같은 이름이 두 번 나오는 코드**가 있다(하나은행 081·005, 미래에셋 230·238).
     *    `transfer_bank` 에는 받은 그대로 전부 넣지만, `bank` 드롭다운에 같은 이름이 두 개
     *    보이면 고를 수가 없으므로 005·230 은 `bank` 에서 제외한다
     *    (005 는 옛 외환은행 → 하나은행 081 로 통합, 230 은 238 과 같은 미래에셋).
     *    그 계좌를 쓰는 사람도 081·238 로 고르면 펌뱅킹은 정상 처리된다.
     *
     * 기존 코드는 건드리지 않는다 — 이미 있는 건 SKIP.
     */
    private static function migrateTransferBankCodes(): void
    {
        echo "== 펌뱅킹 이체 은행코드 ==\n";

        if (!db_table_exists('system_codes')) {
            echo "SKIP  system_codes 없음\n";

            return;
        }

        /** @var list<array{0:string,1:string}> [3자리 코드, 은행명] — 갑이 준 순서 그대로 */
        $banks = [
            ['002', '산업은행'],   ['003', '기업은행'],   ['004', '국민은행'],
            ['081', '하나은행'],   ['005', '하나은행'],   ['007', '수협은행'],
            ['011', '농협은행'],   ['012', '단위농협'],   ['020', '우리은행'],
            ['023', 'SC제일은행'], ['027', '씨티은행'],   ['031', '대구은행'],
            ['032', '부산은행'],   ['034', '광주은행'],   ['035', '제주은행'],
            ['037', '전북은행'],   ['039', '경남은행'],   ['045', '새마을금고'],
            ['048', '신협'],       ['050', '상호저축은행'], ['054', 'HSBC'],
            ['055', '도이치'],     ['057', 'JP모간'],     ['060', 'BOA'],
            ['061', '비엔피파리바은행'], ['062', '중국공상은행'], ['063', '중국은행'],
            ['064', '산림조합'],   ['067', '중국건설은행'], ['071', '우체국'],
            ['088', '신한은행'],   ['089', 'K뱅크'],      ['090', '카카오뱅크'],
            ['092', '토스뱅크'],   ['209', '유안타증권'], ['218', 'KB증권'],
            ['224', 'BNK투자증권'], ['225', 'IBK투자증권'], ['227', '다올투자증권'],
            ['230', '미래에셋'],   ['238', '미래에셋'],   ['240', '삼성증권'],
            ['243', '한국투자증권'], ['247', 'NH투자증권'], ['261', '교보증권'],
            ['262', '하이투자증권'], ['263', 'HMC증권(현대차증권)'], ['264', '키움증권'],
            ['265', '이베스트증권'], ['266', 'SK증권'],    ['267', '대신증권'],
            ['268', '아이엠투자증권'], ['269', '한화증권'], ['270', '하나금융투자'],
            ['271', '토스증권'],   ['278', '신한금융투자'], ['279', 'DB금융투자증권'],
            ['280', '유진투자증권'], ['287', '메리츠증권'], ['288', '카카오페이증권'],
            ['290', '부국증권'],   ['291', '신영증권'],   ['292', '케이프투자증권(LIG)'],
            ['294', '한국포스증권'],
        ];

        // `bank` 드롭다운에서 뺄 중복 이름 코드(위 주석 참고)
        $skipInBank = ['005', '230'];

        $addT = 0;
        $addB = 0;
        $sort = 0;
        foreach ($banks as [$code, $label]) {
            $sort += 10;

            // 1) 펌뱅킹 이체 코드 — C + 3자리
            $tCode = 'C' . $code;
            if (db_row('SELECT id FROM system_codes WHERE category = ? AND code = ? LIMIT 1', ['transfer_bank', $tCode]) === null) {
                db_insert(
                    'INSERT INTO system_codes (category, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, 1)',
                    ['transfer_bank', $tCode, $label, $sort]
                );
                $addT++;
            }

            // 2) 계좌 등록용 3자리 코드
            if (in_array($code, $skipInBank, true)) {
                continue;
            }
            if (db_row('SELECT id FROM system_codes WHERE category = ? AND code = ? LIMIT 1', ['bank', $code]) === null) {
                db_insert(
                    'INSERT INTO system_codes (category, code, label, sort_order, is_active) VALUES (?, ?, ?, ?, 1)',
                    ['bank', $code, $label, $sort]
                );
                $addB++;
            }
        }

        echo $addT > 0 ? "OK    transfer_bank {$addT}건 추가\n" : "SKIP  transfer_bank (모두 있음)\n";
        echo $addB > 0 ? "OK    bank {$addB}건 추가(증권사·저축은행 계좌 등록 가능)\n" : "SKIP  bank (모두 있음)\n";
    }

    /**
     * 플랫폼 수수료에 **전역 기본값** 자리를 만든다 (2026-09-06 갑).
     *
     * 갑: "플랫폼 수수료 전역설정하는 화면이 없네.
     *      수수료 설정(관리) 화면에서 전역 기본값이 이 내용이 안나와"
     *
     * `org_fee_config` 는 `org_id` 가 **PRIMARY KEY** 라 NULL 을 넣을 수 없었다.
     * 그래서 전역 행 자체가 만들어질 수 없었고, 대리점 행이 없으면 코드에 박힌 1.00%
     * 로 떨어졌다 — 화면에서 바꿀 방법이 없는 값이었다.
     *
     * 이미 같은 문제를 겪은 `withdrawal_config` 와 **같은 모양**으로 맞춘다:
     *   대리키 id 를 PK 로, org_id 는 NULL 허용 + UNIQUE(전역 행은 org_id IS NULL).
     */
    private static function migrateOrgFeeGlobalRow(): void
    {
        echo "== 플랫폼 수수료 전역 기본값 ==\n";

        if (!db_table_exists('org_fee_config')) {
            echo "SKIP  org_fee_config 없음\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM org_fee_config'), 'Field');
        if (!in_array('id', $cols, true)) {
            // PK 교체 — AUTO_INCREMENT 컬럼은 키가 있어야 하므로 한 문장에서 함께 처리한다.
            db_execute(
                'ALTER TABLE org_fee_config
                    DROP PRIMARY KEY,
                    ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                    ADD PRIMARY KEY (id),
                    MODIFY COLUMN org_id INT UNSIGNED NULL COMMENT \'대상 조직. NULL = 전역 기본값\',
                    ADD UNIQUE KEY uq_ofc_org (org_id)'
            );
            echo "OK    org_fee_config PK 교체(id) · org_id NULL 허용\n";
        } else {
            echo "SKIP  org_fee_config 구조 (이미 전환됨)\n";
        }

        // 전역 행 — 없으면 만든다. 값은 기존 코드 기본값(1%)과 같게 둬서 동작이 바뀌지 않게.
        if (db_row('SELECT id FROM org_fee_config WHERE org_id IS NULL LIMIT 1') === null) {
            db_insert(
                'INSERT INTO org_fee_config (org_id, pg_service_fee_pct, hq_pct, distributor_pct, agency_pct)
                 VALUES (NULL, 1.00, 1.00, 1.00, 1.00)'
            );
            echo "OK    전역 기본값 행 생성(본사·총판·대리점 각 1.00%)\n";
        } else {
            echo "SKIP  전역 기본값 행 (이미 있음)\n";
        }
    }

    /**
     * 대리점 선차감 수수료 — 건당 정액, **대리점 몫**(2026-09-06 갑).
     *
     * 갑: "대리점에서 선차감 수수료를 100원 책정하면, 라이더가 한 건에 1,100원이 발생할 때
     *      100원을 미리 대리점 몫으로 잡아놓는다. 관리자에서는 1,100원으로 나오는데
     *      라이더한테는 1,000원으로 나온다."
     *
     * 기존 `agency_fee`(선정산수수료·대행)와 **다른 것**이다:
     *   - agency_fee : 일정산 라이더만 · 본사 귀속 · 라이더 명세서에 보인다
     *   - 선차감      : 전 라이더 · **대리점 귀속** · 라이더에게는 안 보이고 단가에서 빠진 것처럼 보인다
     *
     * 기본값 0 — 켜기 전까지 아무 대리점도 영향을 받지 않는다.
     */
    private static function migrateAgencyPredeductFee(): void
    {
        echo "== 대리점 선차감 수수료 ==\n";

        if (!db_table_exists('deduction_global_config')) {
            echo "SKIP  deduction_global_config 없음\n";

            return;
        }

        $cols = array_column(db_rows('SHOW COLUMNS FROM deduction_global_config'), 'Field');
        if (in_array('agency_prededuct_fee', $cols, true)) {
            echo "SKIP  agency_prededuct_fee (이미 있음)\n";

            return;
        }

        db_execute(
            "ALTER TABLE deduction_global_config
                ADD COLUMN agency_prededuct_fee INT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT '대리점 선차감 수수료(배달 건당 정액, 대리점 귀속). 0 = 사용 안 함'
                AFTER agency_fee_min_long"
        );
        echo "OK    agency_prededuct_fee 추가(기본 0 = 사용 안 함)\n";
    }
}

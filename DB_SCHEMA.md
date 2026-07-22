# 도깨비 배달대행 — DB 명세서

> **목적:** 실제 DB에 어떤 테이블·컬럼·관계가 있는지 한눈에 파악하기 위한 기준 문서.
> **원본:** `SHOW CREATE TABLE`(정보스키마) 기준 — 코드(`sql/*.sql`, `MigrateRunner.php`)가 아니라 **실제 서버 DB 상태**를 그대로 기술한다.
> **갱신 규칙(필수):** 테이블 추가·삭제, 컬럼 추가·변경·삭제, 인덱스/FK 변경, enum 값 추가 등 **스키마가 바뀌는 모든 작업에서 이 문서를 함께 갱신**한다. (`.cursor/rules/db-schema-sync.mdc`)
> **최종 확인:** 2026-07-23, DB `my_web_db`, 테이블 27개 (Phase A~F 관리자 재설계 + 정산 엑셀 row-level 저장 확장 반영 완료 시점)

---

## 0. 문서 재생성 방법

이 문서가 실제 DB와 어긋난 것 같으면, 아래로 authoritative 스냅샷을 다시 뽑아 대조한다.

```bash
php migrate.php   # 최신 스키마로 맞춘 뒤
php -r '
require "inc/env.php"; require "inc/config.php"; require "inc/db.php";
foreach (db_rows("SHOW TABLES") as $r) {
    $t = array_values($r)[0];
    $row = db_row("SHOW CREATE TABLE `$t`");
    echo array_values($row)[1] . "\n\n";
}
'
```

---

## 1. 전체 구조 개요

```
organizations (본사>총판>대리점, 자기참조 트리)
  └─ admins.org_id            소속 계정
  └─ riders.agency_id          소속 라이더
  └─ *.org_id 설정 오버라이드   deduction_global_config / withdrawal_config / settlement_excel_config / org_fee_config

riders ─┬─ rider_platforms     플랫폼(배민/쿠팡) 연동
        ├─ rider_wallets       임시 잔액(적립일수)
        └─ deduction_entries   수동 차감(선지급 등)

settlement_uploads → settlement_daily_riders → settlement_rider_cycles → settlement_fee_items
                                                        │
                                                        └→ rider_wallets.balance 적립

agency_wallets(대리점 잔액) ←─ pg_payments(PG충전, agency_cards로 결제)
       │
       └─→ withdrawal_requests(kind: rider_manual/auto_daily/agency_payout) ─→ agency_wallet_ledger(원장)
                     이체는 agency_bank_accounts(핀테크번호) 통해 실행(현재 mock)

content_notices / content_banners   본사 작성 → org_id 기준 broadcast
audit_logs                          전 도메인 공통 감사로그(before/after JSON)
system_codes                        bank/vehicle/rider_status/... 코드마스터
```

---

## 2. 조직·계정

### `organizations` — 조직 계층(본사>총판>대리점)
자기참조 트리. `level` enum이 계층을 결정하고 `parent_id`로 연결.

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | PK | |
| `parent_id` | FK→`organizations.id`, `ON DELETE CASCADE` | 상위 조직(본사 루트는 NULL) |
| `level` | enum(`admin`,`distributor`,`agency`) | 본사/총판/대리점 |
| `code` | varchar(40) UNIQUE | 조직 식별 코드 |
| `name`, `contact_name`, `contact_phone`, `memo` | | |
| `is_active` | tinyint(1) | 비활성화 시 소속 계정도 함께 잠금(`Organization::setActive`) |

- 코드: `inc/Organization.php`, `inc/Org.php`(스코핑 엔진)
- 규칙: **조직 생성은 본사만**(2026-07 재설계, `admin_can_manage_orgs()`)

### `admins` — 관리자 계정
| 컬럼 | 설명 |
|---|---|
| `org_id` | FK 없음(인덱스만) → `organizations.id`. 계정≠조직, 한 조직에 여러 계정 가능 |
| `role` | enum(`super`,`admin`,`operation`,`settlement`) — 기능 축(조직 축은 `org_id`) |

- **대표계정** = 조직 내 최소 `id`(최초 계정). `OrgAccount::primaryId()`
- `super`는 조직 계정에 부여 금지(스코프 우회 위험) — 앱 레벨 관례, DB 제약 아님

---

## 3. 라이더

### `riders`
| 컬럼 | 설명 |
|---|---|
| `agency_id` | FK 없음(인덱스만) → `organizations.id`(level=agency) |
| `status` | enum(`active`,`suspended`,`leave_request`,`offboarded`) |
| `kyc_status` | enum(`none`,`pending`,`verified`,`rejected`) |
| `withdrawal_hold` | 출금 보류 플래그 |
| `is_daily_settlement` | 1=선정산(일일지급), 0=주정산 — `fee_calc_timing`은 이 값에서 파생(별도 컬럼 없음) |
| `withholding_tax_enabled` | 🆕(2026-07-22) 원천세 공제 대상 여부, 대리점이 라이더별 설정. 세율(3.3%)은 `deduction_global_config`에서 고정 |
| `rider_code`, `login_id` | UNIQUE(겸업 시 대리점마다 별도 계정 — 이관 절차 없이 신규 등록) |

### `rider_platforms` — 라이더 1명이 여러 플랫폼(배민/쿠팡) 연동 가능
`platform` enum(`baemin`,`coupang`,`other`), `external_id`(라이선스ID, 대리점 간 중복 허용)

### `rider_wallets` — 임시 잔액(정산 반영 시 누적, 출금 시 정리)
PK=`rider_id`. `accrued_days`는 정산수수료 구간 판단용(§5 참고, `Withdrawal::applyForRider`가 여전히 이 값을 씀 — age-bucket 모델 전환은 미착수, §7 #18 이연).

---

## 4. 정산 파이프라인

```
settlement_uploads (1건 업로드)
  ├→ settlement_daily_riders   (종합탭, 라이더·일자 요약, N행)
  │    → settlement_rider_cycles (정산 반영 1건, upload당 라이더 1일 1행)
  │        → settlement_fee_items (반영 시 차감 항목 N개)
  ├→ settlement_order_details  (오더별 상세내역, 주문 단위 원본, N행)
  ├→ settlement_hourly_insurance (시간제보험, 라이더·일자별, N행)
  └→ settlement_weekly_deductions (차감내역, 라이더 매칭, N행)
       → registered_entry_id → deduction_entries (등록 시)
```

> 2026-07-23 실 쿠팡 정산서(`오엑스플러스_서울_강서남부_20260628.xlsx`)로 시트 구조를 직접 검증. 실제 엑셀은 7개 탭: **종합·오더별 상세 내역서·지원금·추가지원금·차감내역·협력사 자체 미션·시간제보험**. 이 중 종합/오더별상세/차감내역/시간제보험만 파싱·저장 중(지원금·추가지원금·협력사자체미션은 미사용, 필요 시 추후 추가).

### `settlement_uploads`
| 컬럼 | 설명 |
|---|---|
| `kind` | enum(`daily`,`weekly`) |
| `platform` | enum(`baemin`,`coupang`,`other`) — 현재 실사용은 쿠팡이츠 daily만 |
| `agency_id` | 업로드 소유 대리점(FK 없음, 인덱스만) |
| `status` | enum(`uploaded`,`parsing`,`parsed`,`applied`,`error`) |

### `settlement_daily_riders` — 엑셀 "종합" 탭 원본 1행 = 라이더 1일 요약
`fee_pickup/delivery/area/dist_*/pickup_*/dest_*/weather*/promo1~4` 등 쿠팡 정산서 세부 항목 컬럼.
`hourly_insurance`: 시간제보험 — **계산이 아니라 "시간제보험" 탭 값을 파싱해 채움**(✅ 2026-07-23 실 파일 검증 완료, `XlsxParser::parseHourlyInsuranceSheet()`).
UNIQUE(`upload_id`,`license_id`) — 중복 업로드 방지.

### `settlement_order_details` — 🆕(2026-07-23) 엑셀 "오더별 상세 내역서" 탭 원본, 주문 1건=1행
`rider_id`(FK→`riders`, `ON DELETE SET NULL`, 파일 내 성함 매칭·DB 폴백) · `order_no`(축약형 주문번호) · `pickup_area`/`delivery_area` · `assigned_at`/`accepted_at`/`delivered_at`(datetime, 엑셀 시리얼 변환 — **실제 배달 시각**, §7 #18 age-bucket 계산의 미래 데이터 소스) · `duration_minutes` · `distance_m` · `delivery_type`(멀티배달 등) · 수수료 세부(`fee_pickup`/`fee_delivery`/`fee_area`/`fee_dist_surge`/`fee_pickup_surge`/`fee_dest_surge`/`fee_weather`/`fee_promo1~4`) · `net_amount`(오더 단위 정산금액).
검증: 한 업로드 내 전체 `net_amount` 합계가 `settlement_daily_riders` 총 정산금액과 일치해야 함(실 파일로 확인 완료: 328건 = 1,390,241원).

### `settlement_hourly_insurance` — 🆕(2026-07-23) 엑셀 "시간제보험" 탭 원본, 라이더·일자별 1행
`occurred_date`(발생일자, 파일 값) · `amount`(양수로 정규화 — 종합탭 AH컬럼은 음수 표기이나 이 테이블·`settlement_daily_riders.hourly_insurance`는 양수 공제액 컨벤션).

### `settlement_rider_cycles` — 정산 반영 확정 1건
`gross_amount`(플랫폼 총액) → `total_fee_amount`(차감 합) → `net_amount`(지갑 반영액, `rider_wallets.balance`에 적립).
UNIQUE(`rider_id`,`settlement_date`,`platform`) — 같은 날 중복 반영 방지.

### `settlement_fee_items` — 반영 시 차감 항목 상세(`SettlementLedger::buildFeeItems` 산출)
`fee_code` 값: `agency_fee`(선정산수수료, `is_daily_settlement=1`만 반영시점 부과) · `withholding`(원천세, 대상자만) · `employment_ins`(고용 0.8%) · `accident_ins`(산재 0.88%) · `hourly_ins`(시간제보험) · `advance`(선지급) 등.
⚠️ `agency_fee`(대행수수료, 대리점 몫)와 §6의 `org_fee_config`(영업대행수수료, PG 결제 시 3자 분배)는 **이름이 비슷하지만 완전히 다른 개념**.

### `settlement_weekly_deductions` — 엑셀 "차감내역" 탭 원본
🐛→✅ **2026-07-23 버그 수정**: 실 파일로 헤더 대조 결과 기존 파서가 D열부터 한 칸씩 밀려 읽어 **실제 차감액(금액열)이 아니라 배달비를 저장하던 버그**를 발견·수정. 라이더 매칭(`rider_id`, 이전엔 항상 NULL)도 이번에 추가.
`registered_entry_id`(🆕): 이 차감행을 `deduction_entries`로 "등록"하면 채워짐(`admin/api/deduction_register.php`) — 중복 등록 방지 + 등록 취소 시 NULL로 복원. 등록된 `deduction_entries` 행은 정산 반영 시 `applied_date` 기준으로 자동 차감된다(§5.3 참고).

### `settlement_excel_config` — 정산 엑셀 열기 암호(대리점별 오버라이드)
UNIQUE(`org_id`,`platform`), `org_id IS NULL`=전역 기본. 복호화 순서: 업로드 직접입력→대리점→전역→env→baemin 하드코딩.

---

## 5. 차감·수수료 설정 (대리점별 오버라이드 패턴)

공통 패턴: `org_id IS NULL` = 전역 기본값, 특정 `org_id` 행 = 그 대리점 전용 오버라이드. 조회는 "대리점 행 → 전역 기본" 순 폴백(`get(?int $orgId)`).

### `deduction_global_config`
| 컬럼 | 설명 |
|---|---|
| `withholding_tax_pct` | 원천세율, **3.30 고정**(대리점별 조정 불가 — 대상 여부만 라이더별 설정) |
| `employment_ins_pct` | 고용보험 0.80% (2026-07-22 분리, 이전엔 9.12 합산값이었던 버그 교정) |
| `industrial_accident_ins_pct` | 산재보험 0.88% (🆕 2026-07-22 분리) |
| `agency_fee_pct` | 구 비율(미사용) |
| `agency_fee_day_threshold`/`_short`/`_long` | 선정산수수료(대행수수료) 구간 — 대리점이 자유 설정 가능 |

### `withdrawal_config` — 정산수수료(구 이체수수료) 정책
`fee_day_threshold`(기준일수, 기본 7) · `fee_per_tx_short`(80원) · `fee_per_tx_long`(40원) — **대리점별 설정 가능**(2026-07-16 재정정, 방향 불변: 최근=비쌈/오래됨=쌈). `reserve_amount`(출금 시 남기는 보증금).
⚠️ 실제 계산은 아직 `accrued_days` 단일값 모델(`Withdrawal::applyForRider`) — 주문별 age-bucket 합산 모델(§7 #18)로의 전환은 **라이더 출금 플로우 단계로 이연**.

### `org_fee_config` — 🆕(2026-07-22) 영업대행수수료 분배 요율
PK=`org_id`(모든 조직 각자 1행, 본사·총판·대리점). `pg_service_fee_pct` 기본 1.00%(임시, 갑 확정 대기).
대리점 PG 결제 총 요율 = 대리점.pct + 상위총판.pct + 본사.pct (`PgFeeConfig::breakdownForAgency`).

### `deduction_entries` — 라이더별 수동 차감(선지급/대여금 등)
`kind` varchar(자유값): `advance`(선지급, 🆕 2026-07-22 입력화면 완성) · `withholding`/`employment_ins`/`accident_ins`/`agency_fee`(수동 보정용) · `hourly_ins`/`ins_refund`/`rental`/`manual`.
정산 반영 시 해당 `applied_date`의 항목이 자동으로 `settlement_fee_items`에 합산됨.

---

## 6. 출금·지갑

### `withdrawal_requests` — 출금/지급/자체인출 통합 테이블
| 컬럼 | 설명 |
|---|---|
| `rider_id` | **nullable**(2026-07-22 변경) — `agency_payout`은 라이더 없음. FK `fk_wr_rider`는 유지(NULL 허용 FK) |
| `agency_id` | 🆕 대리점 자체 인출 소유 대리점 |
| `kind` | enum(`rider_manual`,`auto_daily`,`agency_payout`) — 라이더 신청 / 일일정산 원클릭 / 대리점 자체인출 |
| `status` | enum(`pending`,`downloaded`,`completed`,`rejected`,`failed`) — 🆕 `failed` 추가(오픈뱅킹 이체 실패) |
| `fail_reason` | 🆕 이체 실패 사유 |
| `withhold_*` | 신한 이체파일 시절 잔재 컬럼(원천세/환급/기타/최소보증/반올림) — `rider_manual` 경로에서만 사용 |

**kind별 흐름:**
- `rider_manual`: 라이더 전액출금 신청 → 대리점 수기 승인 → `Withdrawal::markCompleted`
- `auto_daily`: 선정산 라이더, 관리자 "일일정산 지급 리스트"에서 원클릭(`DailyPayout::payRider`) — 즉시 completed/failed
- `agency_payout`: 대리점 자체 인출, **승인 절차 없음**, 신청 즉시 이체 실행(`AgencyPayout::create`)

### `agency_wallets` — 🆕(2026-07-22) 대리점 잔액
PK=`agency_id`. `balance`(PG 충전 잔액) · `withholding_reserve`(원천세 예수금 누적, 고용·산재는 예수금 아님이라 제외).
**대리점 인출가능액 = balance − 라이더채무(rider_wallets 합계) − withholding_reserve** (`AgencyWallet::withdrawable`)

### `agency_wallet_ledger` — 🆕 대리점 잔액 변동 원장(감사용)
`direction`(credit/debit) · `reason`(`pg_fund`/`rider_payout`/`agency_payout`/`manual_adjust`) · `balance_after`(스냅샷) · `ref_id`(연관 레코드).

---

## 7. PG 카드결제 · 오픈뱅킹 (Phase F, 🧩 뼈대 — 실 연동 전 mock)

### `agency_cards` — 🆕 대리점 등록 카드(다건, 우선순위)
`priority`(낮을수록 먼저 시도) · `billing_key`(실 PG 전엔 모의 발급) · `mock_limit`(0=무제한, 개발용 — 한도초과 시뮬레이션).
결제 시 `priority` 순 시도 → 실패(한도초과 등) 시 다음 카드 자동 재시도(`PgPayment::chargeForRider`).

### `pg_payments` — PG 결제 이력(라이더별 건건히)
`net_amount`(지갑 충전분) + `service_fee`(영업대행수수료, `org_fee_config` 기준) = `total_charged`(카드 청구 총액).
`status`(`success`/`failed`), `attempts`(시도한 카드 수), `card_id`(실제 승인 카드).

### `agency_bank_accounts` — 🆕 대리점 오픈뱅킹 출금 계좌
PK=`agency_id`. `fintech_use_num`(핀테크이용번호, 실 연동 전 모의 발급 가능).
`Disbursement::transfer()`가 이 계좌 기준으로 이체(현재 `MockOpenBankingGateway`).

> **Mock→Real 교체 지점:** `inc/PgGateway.php`(`PgGatewayFactory::make()`), `inc/OpenBankingGateway.php`(`OpenBankingGatewayFactory::make()`) — 실 스펙 도착 시 이 두 팩토리만 교체하면 스키마·상위 로직은 그대로 재사용.

---

## 8. 콘텐츠 (공지·배너)

### `content_notices` / `content_banners`
`org_id`(작성 조직, broadcast 기준) — **2026-07 재설계로 본사만 작성**(`admin_can_write('content')`에 HQ 레벨 체크 추가), 총판·대리점은 조회만.
`content_banners.slot`: `home_top`/`home_middle`/`rider_app`(라이더 홈 캐러셀).
⚠️ 본사만 작성이면 `org_id`가 항상 본사 id로 고정돼 사실상 무의미 — 컬럼 유지 여부는 미정(§8-B 참고).

---

## 9. 시스템 공통

### `system_codes` — 코드마스터
`category`: `bank`/`vehicle`/`rider_status`/`settlement_status`/`withdrawal_status`/`platform`/`deduction_kind`. UNIQUE(`category`,`code`), 생성 후 불변(비활성화만 가능).
⚠️ `withdrawal_status`에 `failed`를 추가할 때 PHP `Withdrawal::STATUS_LABELS`(enum과 별개)도 함께 갱신해야 함 — 상태값 이중정의 패턴 주의.

### `audit_logs` — 전 도메인 공통 감사로그
`actor_type`(admin/rider/system), `action`(정규화된 코드: CREATE/UPDATE/DELETE/LOGIN/MANUAL_ADJUST 등), `target_table`/`target_id`, `before_value`/`after_value`(JSON, `CHECK json_valid()`).
기본은 `after_value`만 채움(간단 로그). **예외: 본사 수동조정(`ManualAdjust`)만 `before_value`도 채워 변경 전/후를 모두 남김.**

---

## 10. 알려진 스키마 특이사항 (읽을 때 주의)

1. **`withdrawal_requests.rider_id`가 nullable인데 FK(`fk_wr_rider`)는 유지됨** — MySQL/MariaDB에서 nullable FK 컬럼은 NULL 값에 한해 참조 무결성 검사를 건너뛰므로 정상 동작(스키마 충돌이었던 §7 #1은 해소됨).
2. **`deduction_global_config.agency_fee_pct`**: "구 비율(미사용)" 코멘트 — 실제 계산은 `agency_fee_day_threshold`/`_short`/`_long` 3개 컬럼이 담당, 이 컬럼은 죽은 컬럼.
3. **`org_fee_config`와 `deduction_global_config`/`withdrawal_config`의 `org_id` 의미가 다름** — 전자는 "모든 조직"(본사 포함) 각자 1행, 후자 둘은 "대리점 오버라이드"(`NULL`=전역, 본사 행 없음). 코드 작성 시 혼동 주의.
4. **`agency_cards.mock_limit`·`billing_key`의 `MOCK-` 접두** 값은 실 PG 연동 전 임시 — Real 드라이버 전환 시 반드시 걷어낼 것.

---

## 11. 도메인 클래스 매핑 (테이블 ↔ PHP)

| 테이블 | 주 도메인 클래스 |
|---|---|
| `organizations` | `Organization`, `Org`(스코핑) |
| `admins` | `AdminAccount`(전체), `OrgAccount`(조직 서브계정) |
| `riders`, `rider_platforms` | (라이더 CRUD — `admin/api/riders.php`, `rider_action.php`) |
| `rider_wallets` | `RiderWallet` |
| `settlement_*` | `SettlementLedger`, `AgencyFeeConfig`, `XlsxParser`(파싱), `admin/api/settlement_upload.php`(저장) |
| `settlement_order_details` | `XlsxParser::parseOrderDetailSheet` → `settlement_upload.php` |
| `settlement_hourly_insurance` | `XlsxParser::parseHourlyInsuranceSheet` → `settlement_upload.php` |
| `settlement_weekly_deductions` | `XlsxParser::parseDeductionSheet` → `settlement_upload.php`(저장), `admin/api/deduction_register.php`(등록) |
| `deduction_entries` | 선지급: `admin/api/advance_entry.php`, 업로드 차감 등록: `admin/api/deduction_register.php` |
| `deduction_global_config` | `SettlementLedger::globalDeductionConfig` |
| `withdrawal_config` | `WithdrawalConfig` |
| `withdrawal_requests` | `Withdrawal`(rider_manual), `DailyPayout`(auto_daily), `AgencyPayout`(agency_payout) |
| `agency_wallets`, `agency_wallet_ledger` | `AgencyWallet` |
| `org_fee_config` | `PgFeeConfig` |
| `agency_cards`, `pg_payments` | `AgencyCard`, `PgPayment`, `PgGateway`(인터페이스) |
| `agency_bank_accounts` | `BankAccount`, `Disbursement`, `OpenBankingGateway`(인터페이스) |
| `content_notices`, `content_banners` | `Notice`, `Banner` |
| `system_codes` | `SystemCode` |
| `audit_logs` | `AuditLog` |
| (수동조정, 테이블 아님) | `ManualAdjust` — `rider_wallets`/`agency_wallets` 직접 조정 |

---

## 12. 변경 이력

| 날짜 | 내용 |
|---|---|
| 2026-07-22 | 이 문서 최초 작성 — Phase A~F(관리자 재설계) 완료 시점의 DB 전체(25개 테이블) 스냅샷 |
| 2026-07-23 | 정산 엑셀 row-level 저장 확장(실 파일 검증). 신규 `settlement_order_details`(오더별 상세내역, 328행/업로드), `settlement_hourly_insurance`(시간제보험) 추가 → 27개 테이블. `settlement_weekly_deductions`에 `registered_entry_id` 컬럼 추가 + 파서 컬럼매핑 버그 수정(배달비→금액 오탐 교정) + 라이더 매칭 추가. `settlement_daily_riders.hourly_insurance` 실값 채움 확인. |

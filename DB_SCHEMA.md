# 도깨비 배달대행 — DB 명세서

> **목적:** 실제 DB에 어떤 테이블·컬럼·관계가 있는지 한눈에 파악하기 위한 기준 문서.
> **원본:** `SHOW CREATE TABLE`(정보스키마) 기준 — 코드(`sql/*.sql`, `MigrateRunner.php`)가 아니라 **실제 서버 DB 상태**를 그대로 기술한다.
> **갱신 규칙(필수):** 테이블 추가·삭제, 컬럼 추가·변경·삭제, 인덱스/FK 변경, enum 값 추가 등 **스키마가 바뀌는 모든 작업에서 이 문서를 함께 갱신**한다. (`.cursor/rules/db-schema-sync.mdc`)
> **최종 확인:** 2026-08-05, DB `my_web_db`, 테이블 34개 (프로모션 지급 + 역할별 권한관리(`role_permissions`) 반영 완료 시점)

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

## 🔒 암호화 저장 컬럼

`inc/Crypto.php`(AES-256-GCM)로 **저장 시 암호화**하는 컬럼입니다. 저장 형식은 `enc:v1:` + base64(iv‖tag‖ciphertext).

| 테이블 | 컬럼 | 이유 |
|---|---|---|
| `pg_config` | `pay_key`·`sign_key`·`api_key`·`enc_key`·`enc_iv`·`login_pw`·`access_token` | **그 자체로 카드 결제를 실행할 수 있는 자격증명** |
| `agency_cards` | `billing_key` | `pay_key`와 합치면 결제 가능 |
| `riders` | `bank_account` | 개인 금융정보 |
| `withdrawal_requests` | `bank_account` | 〃 (신청 시점 스냅샷) |
| `agency_bank_accounts` | `account_no`·`fintech_use_num` | 계좌·**이체 실행 키** |
| `settlement_excel_config` | `open_password` | 정산 원본 열람 암호 |

**키 관리** — `.env` 의 `APP_ENC_KEY`(base64 32바이트). `php tools/gen_enc_key.php` 로 생성합니다.

- 키는 **DB 밖**에 둡니다. 같은 DB에 넣으면 덤프 한 번에 둘 다 나가 암호화한 의미가 없습니다.
- **웹서버가 여러 대면 전부 같은 값**이어야 합니다. 다르면 서로가 저장한 값을 못 읽습니다.
- **키를 잃으면 복구 방법이 없습니다.** 비밀번호 관리자나 AWS Secrets Manager에 따로 보관하세요.

**주의사항**

- 암호문은 평문보다 깁니다(원문 40자 → 약 99자). 컬럼 폭을 먼저 늘리지 않으면 **잘려 들어가 복구 불가**입니다.
- 같은 값도 매번 다른 암호문이 되므로 **`LIKE` 검색·`GROUP BY`·유니크 제약에 쓸 수 없습니다.**
- 빈 문자열은 암호화하지 않습니다 — `!== ''` 로 값 존재를 확인하는 기존 코드가 그대로 동작합니다.
- 접두사가 없는 값은 평문으로 보고 그대로 돌려줍니다 → 이관 전 데이터도 읽히고, 마이그레이션을 여러 번 돌려도 안전합니다.
- **비밀번호(`admins`·`riders`의 `password_hash`)는 bcrypt cost 12** 로 별개입니다. 대조만 하면 되므로 암호화하지 않습니다.
- **주민등록번호는 시스템에 저장하지 않습니다.**

## 1. 전체 구조 개요

```
organizations (본사>총판>대리점, 자기참조 트리)
  └─ admins.org_id            소속 계정
  └─ riders.agency_id          소속 라이더
  └─ *.org_id 설정 오버라이드   deduction_global_config / withdrawal_config / settlement_excel_config / org_fee_config

riders ─┬─ rider_platforms     플랫폼(배민/쿠팡) 연동
        ├─ rider_wallets       임시 잔액(적립일수)
        ├─ deduction_entries   수동 차감(선지급 등)
        └─ rider_debts ─ rider_debt_entries   부채 원장(대여금/리스/선지급) → deduction_entries 생성

settlement_uploads → settlement_daily_riders → settlement_rider_cycles → settlement_fee_items
                                                        │
                                                        └→ rider_wallets.balance 적립

agency_wallets(대리점 잔액) ←─ pg_payments(PG충전, agency_cards로 결제)
       │
       └─→ withdrawal_requests(kind: rider_manual/auto_daily/agency_payout) ─→ agency_wallet_ledger(원장)
                     이체는 agency_bank_accounts의 **본사 행**(출금 원천 계좌)에서 실행(현재 mock)
                     대리점 행은 정산금을 받을 수령 계좌일 뿐 — 지갑은 단일 계좌를 나눈 내부 장부

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
- 코드 자동생성: `Organization::suggestCode()`(레벨별 `DIST-`/`AG-` 접두 + 4자리 순번). `create()`에 코드 미입력 시 서버가 자동 채움.
- **생성 시 설정 시딩**(2026-07-23): `create()`가 신규 조직에 `org_fee_config`(1%) 행을, 대리점이면 추가로 `agency_wallets`(0)·`withdrawal_config`(7/80/40) 행을 함께 생성 → 이 config 테이블들의 org행은 조직 생성 시점에 존재하게 됨(기존엔 migrate 백필 or 없으면 전역 폴백).

### `admins` — 관리자 계정
| 컬럼 | 설명 |
|---|---|
| `org_id` | FK 없음(인덱스만) → `organizations.id`. 계정≠조직, 한 조직에 여러 계정 가능 |
| `role` | enum(`super`,`admin`,`operation`,`settlement`,`manager`) — 기능 축(조직 축은 `org_id`) |

- **대표계정** = 조직 내 최소 `id`(최초 계정). `OrgAccount::primaryId()`
- `super`는 조직 계정에 부여 금지(스코프 우회 위험) — 앱 레벨 관례, DB 제약 아님
- `manager`(2026-08-05 추가) = 소속 조직 범위 내 시스템관리 제외 전 화면 조회·쓰기(대리점/총판 담당자가 그 조직 업무를 혼자 처리하는 경우용). `OrgAccount::ASSIGNABLE_ROLES`/`Organization::ACCOUNT_ROLES`에 포함돼 있어 조직 계정에도 부여 가능(super와 달리 스코프 우회 없음 — `org_id` 스코핑은 그대로 적용됨). `inc/RolePermission.php`의 `role_permissions` 테이블 대상이 아니라 `admin_can_access_route()`/`admin_can_write()`에서 항상 true로 처리.

---

## 3. 라이더

### `riders`
| 컬럼 | 설명 |
|---|---|
| `agency_id` | FK 없음(인덱스만) → `organizations.id`(level=agency). **라이더의 실제 소속 단위**(상세·목록·수정 화면에 "소속 대리점"으로 표시, 2026-07-24) |
| `team_code` | ⚠️ **레거시**(단일 대리점 시절 지역/조 구분, 예 `gangseo_a`). 멀티테넌시 이후 `agency_id`로 대체돼 현재 전 라이더 `etc`·UI에서 제거됨(2026-07-24). 컬럼은 보존(정산 등 잔여 참조), 신규 등록 시 기본값 `etc` |
| `status` | enum(`active`,`suspended`,`leave_request`,`offboarded`) |
| `kyc_status` | enum(`none`,`pending`,`verified`,`rejected`) |
| `withdrawal_hold` | 출금 보류 플래그 |
| `is_daily_settlement` | 1=선정산(일일지급), 0=주정산 — `fee_calc_timing`은 이 값에서 파생(별도 컬럼 없음) |
| `withholding_tax_enabled` | 🆕(2026-07-22) 원천세 공제 대상 여부, 대리점이 라이더별 설정. 세율(3.3%)은 `deduction_global_config`에서 고정 |
| `bank_account` | 🔒 **암호화 저장**(`Crypto`, `enc:v1:…`). varchar(255) — 암호문이 평문보다 길다. 읽는 곳에서 `Crypto::decrypt()` 필요. **같은 계좌도 매번 다른 암호문이라 `LIKE` 검색·`GROUP BY` 불가.** |
| `rider_code`, `login_id` | UNIQUE(겸업 시 대리점마다 별도 계정 — 이관 절차 없이 신규 등록) |

### `rider_platforms` — 라이더 1명이 여러 플랫폼(배민/쿠팡) 연동 가능
`platform` enum(`baemin`,`coupang`,`other`), `external_id`(라이선스ID, 대리점 간 중복 허용)

### `rider_wallets` — 임시 잔액(정산 반영 시 누적, 출금 시 정리)
PK=`rider_id`. `accrued_days`는 **사이클이 없을 때만 쓰는 폴백**용(§5 참고). 정산수수료 본 계산은 age-bucket 모델(`WithdrawalConfig::feeForCycles`, 정산일로부터 경과일 × 주문 건수)로 전환 완료 — §7 #18.

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

### `settlement_daily_riders` — 라이더 1일 요약(쿠팡 "종합" 탭 / 배민 집계)
`fee_pickup/delivery/area/dist_*/pickup_*/dest_*/weather*/promo1~4` 등 쿠팡 정산서 세부 항목 컬럼.
`hourly_insurance`: 시간제보험 — **계산이 아니라 "시간제보험" 탭 값을 파싱해 채움**(✅ 2026-07-23 실 파일 검증 완료, `XlsxParser::parseHourlyInsuranceSheet()`).
**UNIQUE(`upload_id`,`license_id`,`settlement_date`)** — 2026-07-23 확장(기존 `(upload_id,license_id)`). 배민은 파일 하나가 여러 운행일을 포함해 라이더가 날짜별로 여러 행을 가질 수 있어 날짜를 유니크에 포함. 쿠팡은 upload당 단일 날짜라 영향 없음.
- 쿠팡: `license_id`=라이선스ID, 종합탭 값 그대로. **정산 기준액은 부가세 제외 공급가액**(`SettlementAmounts::exVat` = fee 구성합). 엑셀 `gross_amount`(총 정산 금액, 부가세 포함)는 원본 보관만. `payout_amount`(보수액)도 계산에 쓰지 않는다. 배민: `license_id`=라이더ID(J), 주문을 라이더·운행일별로 집계 — `gross_amount`=`payout_amount`=배달처리비(부가세 없음).

### `settlement_order_details` — 🆕(2026-07-23) 엑셀 "오더별 상세 내역서" 탭 원본, 주문 1건=1행
`rider_id`(FK→`riders`, `ON DELETE SET NULL`, 파일 내 성함 매칭·DB 폴백) · `order_no`(축약형 주문번호) · `pickup_area`/`delivery_area` · `assigned_at`/`accepted_at`/`delivered_at`(datetime, 엑셀 시리얼 변환 — **실제 배달 시각**, §7 #18 age-bucket 계산의 미래 데이터 소스) · `duration_minutes` · `distance_m` · `delivery_type`(멀티배달 등) · 수수료 세부(`fee_pickup`/`fee_delivery`/`fee_area`/`fee_dist_surge`/`fee_pickup_surge`/`fee_dest_surge`/`fee_weather`/`fee_promo1~4`) · `net_amount`(오더 단위 정산금액).
검증: 한 업로드 내 전체 `net_amount` 합계가 `settlement_daily_riders` 총 정산금액과 일치해야 함(실 파일로 확인 완료: 328건 = 1,390,241원).

### `settlement_hourly_insurance` — 🆕(2026-07-23) 엑셀 "시간제보험" 탭 원본, 라이더·일자별 1행
`occurred_date`(발생일자, 파일 값) · `amount`(양수로 정규화 — 종합탭 AH컬럼은 음수 표기이나 이 테이블·`settlement_daily_riders.hourly_insurance`는 양수 공제액 컨벤션).

### `settlement_support_amounts` — 🆕(2026-07-30) 엑셀 "지원금"·"추가지원금" 탭 원본
`kind` enum(`support`=지원금, `add_support`=추가지원금). 실제 운영 파서(`parser.py`) 확인 결과 **정산금액과 별개로 존재하며 최종 지급액에 가산되는 항목** — 우리 시스템이 이 탭을 파싱하지 않아 라이더 지급액이 누락돼 있었음(2026-07-30 발견). `settlement_daily_riders.support_amount`(합계)를 거쳐 `settlement_rider_cycles.support_amount`로 이어져 `gross_amount`에 가산된 뒤 `net_amount` 계산에 반영된다.
⚠️ `XlsxParser::parseSupportSheet()` 헤더 판정 시 키워드 `'지원금'`을 쓰면 시트 제목("지원금 **상세 내역서**")에도 포함돼 있어 제목 행을 헤더로 오판한다(실 파일로 발견·수정) — `'주문일자'`만 사용할 것.

### `settlement_rider_cycles` — 정산 반영 확정 1건
`gross_amount`(부가세 제외 정산액 — 2026-08-09부터. 엑셀 총액이 아님) · `support_amount`(지원금+추가지원금) → `total_fee_amount`(차감 합, 부가세 없음) → `net_amount`(라이더 지갑 적립). PG 조달액 = `gross_amount` + `support_amount`. `platform_payout`은 엑셀 보수액 스냅샷.
UNIQUE(`rider_id`,`settlement_date`,`platform`) — 같은 날 중복 반영 방지.

### `settlement_fee_items` — 반영 시 차감 항목 상세(`SettlementLedger::composeFeesForDailyRow`)
`fee_code` 값: `hourly_ins`(시간제보험) · `excel_deduction`(차감내역 탭) · `agency_fee`(선정산수수료) · `withholding`(원천세) · `employment_ins`(고용 0.8%) · `accident_ins`(산재 0.88%) · `advance`(선지급) 등. **부가세(`vat`)는 2026-08-09부터 생성하지 않음**(과거 사이클에만 남을 수 있음).
⚠️ `agency_fee`(대행수수료, 대리점 몫)와 §6의 `org_fee_config`(영업대행수수료, PG 결제 시 3자 분배)는 **이름이 비슷하지만 완전히 다른 개념**.

### `settlement_weekly_deductions` — 엑셀 "차감내역" 탭 원본
🐛→✅ **2026-07-23 버그 수정**: 실 파일로 헤더 대조 결과 기존 파서가 D열부터 한 칸씩 밀려 읽어 **실제 차감액(금액열)이 아니라 배달비를 저장하던 버그**를 발견·수정. 라이더 매칭(`rider_id`, 이전엔 항상 NULL)도 이번에 추가.
정산 반영 시 **해당 `upload_id`의 차감행을 라이더별로 바로 공제**한다(`SettlementAmounts::excelDeductions`, fee_code=`excel_deduction`). `registered_entry_id`(🆕): 이 차감행을 `deduction_entries`로 "등록"하면 채워짐(`admin/api/deduction_register.php`) — 수동 원장용. 등록분은 엑셀 차감과 이중으로 빠지지 않게 `buildFeeItems`에서 제외.

### `settlement_weekly_riders` — 🆕(2026-08-22) 배민 **주간정산서(을지)** 라이더별 결과
일일정산서에는 **배달료와 주문 상세만** 있다. 프로모션·시간제보험료·고용/산재보험·원천세·최종 지급액은 **주간정산서에만** 있어서(실파일 기준 프로모션만 237만원), 이걸 안 읽으면 라이더에게 줄 정확한 금액이 안 나온다. 그래서 일일과 **별도 테이블**로 둔다 — 일자별이 아니라 **주 단위 1행**이고 담는 항목도 다르다.
UNIQUE(`upload_id`,`user_id_raw`) — 같은 업로드를 다시 올려도 라이더별로 덮어쓴다(멱등). 매칭키는 배민 **User ID**(일일 경로와 동일).
컬럼: `order_count`(처리건수) · `delivery_fee`(배달료 A) · `extra_pay`(추가지급 B=프로모션) · `total_fee`(C=A+B) · `hourly_ins` · `expense` · `reward` · `emp_ins_rider`/`acc_ins_rider`(라이더부담 보험) · `settle_amount` · `income_tax`/`resident_tax`/`withholding` · `payout`(최종 지급액).
**반영 범위 — 프로모션 + 시간제보험만**(2026-08-22 갑 확정: *"주간정산서는 프로모션 금액과 시간제 보험만 반영해, (고용 산재 원천)은 기존처럼 일일정산서 업로드 할때 우리 내부 기준에 맞춰서 계산하니까"*). 고용·산재·원천세는 파일에 값이 있어도 **반영하지 않고** 우리 요율(`deduction_global_config`)로 일일 반영 때 계산한다. 다만 파일 값은 대조용으로 **저장은 해둔다**(플랫폼 값과 우리 계산의 차이를 볼 수 있게).
⚠️ **아직 저장만 하고 지갑 적립은 하지 않는다.** 프로모션·시간제보험을 라이더 지갑에 어느 시점에 넣을지는 일일 반영과 순서가 얽혀 별도 결정이 필요하다.

**플랫폼별 주정산서 구조가 다르다** — 파서를 나눠 쓴다:
| | 배민 | 쿠팡 |
|---|---|---|
| 판별 시트 | `갑지`/`을지`/`고용보험소급정산` | `일자별 정산내역`/`보험료(소급)`/`시간제보험(차감)` |
| 라이더별 원천 | `을지` 표(16개 항목) | 「시간제보험(차감)」만 |
| 반영 항목 | 프로모션 + 시간제보험 | **시간제보험만** |
| 매칭키(`user_id_raw`) | User ID | 성함(User ID가 없음) |

⚠️ **쿠팡은 프로모션을 반영하지 않는다**(2026-08-22 갑 확정: *"쿠팡은 프로모션 반영 안할꺼야"*). 쿠팡 주정산서에는 프로모션으로 보이는 값이 **두 개**나 있는데 서로 다르다 — 종합 시트의 「프로모션」 총액 52,000원(라이더별 내역 없음)과 「협력사 자체 미션」 시트의 1,653,000원(라이더 18명별). **둘 다 읽지 않는다.** 나중에 "프로모션이 빠졌다"고 채워 넣지 말 것 — 누락이 아니라 확정된 정책이다.
⚠️ **쿠팡 주정산서의 라이더별 표는 일일과 컬럼 구조가 같다.** 일일로 오인해 올리면 **한 주치가 하루치로 저장된다**(실측 1,730건·프로모션도 오독). 그래서 `detectKind()`로 반드시 걸러내고, 주간에서는 그 표를 **의도적으로 읽지 않는다**.
「시간제보험」 시트 헤더도 다르다 — 일일 `발생일자/성함/금액`, 주간 `일자/이름/금액`. `parseHourlyInsuranceSheet()`가 둘 다 받는다.

**⚠️ 배민의 건수/금액 집계 규칙(2026-08-22 실정산서 대조로 확인)** — 둘의 기준이 다르다:
- **배달료** = 배달취소 건도 포함해 `배달처리비` 전액 합산
- **처리건수** = **픽업완료했고 배달처리비가 0원이 아닌 건**만
  → 가게까지 갔다 픽업 못 한 취소건(헛걸음 보상 700원)과 라이더 귀책 0원 건은 **돈은 받지만 건수엔 안 들어간다**.
  이 규칙을 안 지키면 집계가 6건(실측) 많아지고 건당 정산수수료·프로모션 건수 구간이 그만큼 어긋난다.
  구현: `XlsxParser::parseBaeminOrders()`의 `counted` 플래그 → `settlement_baemin_normalize()`.

### `settlement_excel_config` — 정산 엑셀 열기 암호(대리점별 오버라이드)
UNIQUE(`org_id`,`platform`,`kind`), `org_id IS NULL`=전역 기본. 복호화 순서: 업로드 직접입력→대리점→전역→env→baemin 하드코딩.
🆕 **`kind`(daily|weekly)** — 배민은 **일일과 주간의 열기 암호가 다르다**(주간=사업자등록번호, 일일=별도). 종류별로 따로 저장하고, 업로드 시 요청한 종류를 먼저 시도한 뒤 나머지 종류도 시도한다(한쪽만 등록해둔 경우에도 열리도록).
🐛→✅ **2026-08-22 중복행 버그 수정** — 구 시드가 `INSERT IGNORE`로 전역행(org_id NULL)을 심었는데, **MySQL 유니크키는 NULL을 서로 다른 값으로 취급**해서 `php migrate.php`를 돌릴 때마다 3행씩 쌓였다(실제 141행까지 늘어나 있었음). 조회는 `LIMIT 1`이라 정렬 보장 없이 아무 행이나 집게 되고, 빈 암호 행을 집으면 복호화가 실패한다. 시드를 제거하고 기존 중복행을 정리했다(암호가 있는 행 우선 유지).
관리 화면(`system/settlement-excel`)은 2026-08-05부터 `system/*` 일괄 super 전용 규칙의 예외 — `RolePermission`의 `settlement` 영역 권한을 따르며, 대리점 계정은 자기 암호만, 본사·총판은 스코프 내 대리점 전체를 리스트로 본다(`SettlementExcelConfig::listAgencyRows()`, `Org::agencyScopeClause()` 사용).

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
| `agency_fee_day_threshold`/`_short`/`_long` | 선정산수수료(대행수수료) 구간 — 대리점이 설정하되 아래 하한 이상만 |
| `agency_fee_min_short`/`_min_long` | 🆕(2026-08-15) **본사가 정한 구간별 최저 건당 금액**. ⚠️ **전역 행(`org_id IS NULL`)의 값만 의미가 있다** — 대리점 행에도 컬럼은 생기지만 읽지 않는다(대리점이 자기 하한을 정하면 하한이 아니므로). `0`이면 하한 없음 |

**최저금액 동작** — `AgencyFeeConfig::minimums()`가 전역 행에서 읽고, `save()`가 하한 미만이면 `InvalidArgumentException`으로 **거부**한다(조용히 올려주지 않음 — 대리점이 자기가 뭘 설정했는지 착각하면 안 되므로). 하한은 **전역 기본값에도 걸린다**(기본값이 하한보다 낮으면 전용 설정이 없는 대리점이 하한을 우회하기 때문). 하한을 올려도 **이미 낮게 설정된 대리점 행은 그대로 둔다**(남의 요율을 말없이 바꾸지 않음) — `agenciesBelowMinimum()`이 그 목록을 돌려주고 본사 화면에 경고로 뜬다. 저장 API는 `action:'save_min'`, **본사 전용**.

### `withdrawal_config` — 정산수수료(구 이체수수료) 정책 + 3분할 배분 설정

🆕 **(2026-08-31 갑 지시) 정산수수료 구간별 배분** — 본사·총판 몫 **모두 배달 건당 정액(원)**이며, 「기준 미만/기준 이상」 두 구간에 각각 다르게 매긴다: `hq_fee_short`/`hq_fee_long`(본사 몫) · `dist_fee_short`/`dist_fee_long`(총판 몫). **대리점 몫 = 대행수수료 − 본사 − 총판**(나머지 전부). **본사 몫(건당)의 하한**은 별도 컬럼이 아니라 **「대행수수료 설정」의 최저 금액**(`deduction_global_config.agency_fee_min_short`/`agency_fee_min_long`, `AgencyFeeConfig::minimums()`)을 구간별로 그대로 쓴다 — 저장 시 `hq_fee_short < min_short || hq_fee_long < min_long`이면 거부(InvalidArgumentException→422). 0이면 하한 없음. 대리점별 설정이지만 **편집은 본사만**(대리점은 조회).
  - 구 컬럼 `hq_fee_per_order`(단일 본사 몫) · `fee_share_distributor_pct`(총판 %)는 **폐기·미사용**(컬럼은 남겨 둠). 마이그레이션 시 `hq_fee_per_order` 값을 `hq_fee_short`/`hq_fee_long` 두 구간에 복사해 기존 동작을 이어받는다. (구 검증 "본사 몫 < 건당 수수료"는 2026-08-12 "대리점 0 OK"로 이미 제거됨.)
  - ⚠️ 한때 `min_agency_fee` 컬럼을 여기 두려다 폐기 — 대행수수료 최저 금액이 이미 「대행수수료 설정」에 있어(2026-08-15) 중복이라, 마이그레이션이 그 컬럼을 **DROP** 한다.
계산·이동은 `WithdrawalConfig::feeShare()` + `inc/WithdrawalFeeShare.php`. **대리점 몫은 이동하지 않는다**(정산수수료는 라이더 지갑에서 빠져 이미 대리점 지갑에 남아 있는 돈) — 본사·총판 몫만 대리점 지갑에서 빼서 각 조직 지갑으로 옮기고 `agency_wallet_ledger`에 `wd_fee_up`(대리점 출금)·`wd_fee_in`(상위 수입)으로 기록한다.
`fee_day_threshold`(기준일수, 기본 7) · `fee_per_tx_short`(80원) · `fee_per_tx_long`(40원) — **대리점별 설정 가능**(2026-07-16 재정정, 방향 불변: 최근=비쌈/오래됨=쌈). `reserve_amount`(출금 시 남기는 보증금).
✅ 실제 계산은 주문별 age-bucket 합산 모델(`WithdrawalConfig::feeForCycles` + `WithdrawalCycles`)로 전환 완료(§7 #18). `accrued_days` 단일값 모델은 사이클이 하나도 없는 경우의 폴백으로만 남아 있다.
🆕 **(2026-09-01) `transfer_fee`** INT 기본 330 — **이체 수수료**. 펌뱅킹 이체(일일이체·출금신청·출금대행)가 일어날 때마다 라이더에게 부과하는 정액으로, **실지급액에서 빠져 본사로 귀속**된다(정산수수료를 뗀 뒤에도 실지급이 남을 때만 부과). 본사만 편집(대리점은 조회). 출금 건에는 `withdrawal_requests.withhold_transfer_fee`로 실제 부과액을 기록하고, 이체가 확정되는 지점(`Withdrawal::finalizeSuccess`/`markCompleted`·`DailyPayout`)에서 `WithdrawalFeeShare::chargeTransferFee()`가 대리점 지갑에서 본사 지갑으로 옮기며 `agency_wallet_ledger`에 `transfer_fee_up`/`transfer_fee_in`으로 남긴다.
🆕 **(2026-08-23) `auto_transfer_on_request`** TINYINT(1) 기본 0 — 라이더가 앱에서 출금을 신청하는 **즉시** 펌뱅킹으로 내보낼지. 대리점별 on/off 이고 **대리점이 직접 설정**(「출금 정책 설정」). 켜면 `Withdrawal::autoTransferOnRequest()`가 신청 직후 `executeTransfers()`를 부른다 — **라이더 본인 신청 경로에서만** 호출한다(「출금 대행」·일일정산 자동출금은 자기 흐름에서 이미 이체를 부르므로 중복 호출 금지). 이체 실패 시 신청은 `failed`로 남아 관리자가 재시도할 수 있다.

### `org_fee_config` — 🆕(2026-07-22) 영업대행수수료 분배 요율
PK=`org_id`(모든 조직 각자 1행, 본사·총판·대리점). `pg_service_fee_pct` 기본 1.00%(임시, 갑 확정 대기).
대리점 PG 결제 총 요율 = 대리점.pct + 상위총판.pct + 본사.pct (`PgFeeConfig::breakdownForAgency`).

### `deduction_entries` — 라이더별 수동 차감(선지급/대여금 등)
`kind` varchar(자유값): `advance`(선지급, 🆕 2026-07-22 입력화면 완성) · `loan`/`lease`(🆕 2026-07-24 부채원장이 생성) · `withholding`/`employment_ins`/`accident_ins`/`agency_fee`(수동 보정용) · `hourly_ins`/`ins_refund`/`rental`/`manual`.
정산 반영 시 해당 `applied_date`의 항목이 자동으로 `settlement_fee_items`에 합산됨.

### `rider_debts` — 🆕(2026-07-24) 라이더 부채 원장(대여금/리스/선지급)
PDF 정산명세서의 대여금·리스·선지급 차감 명세 대응. `kind` enum(`loan`=대여금, `lease`=리스/렌탈, `advance`=선지급). `principal_amount`(원금) → `balance_amount`(남은 잔액, 주 단위 이월) · `daily_amount`(일납) · `creditor`(채권자) · `status`(active/paused/closed) · `opened_on`/`closed_on`/`due_updated_on`(미납갱신일) · `planned_end_on`(2026-07-30 컬럼 추가, 2026-08-08 등록/수정 화면·API에 실제로 연결 — 그 전엔 컬럼만 있고 입력할 곳이 없어 리스 자동계산이 항상 스킵되는 상태였음. 계약 종료 예정일 — 리스 전용. `opened_on`과 함께 계약기간을 이뤄 자동 일수계산의 기준이 됨). 대여금·선지급은 **상각형**(잔액이 줄어 0이면 자동 완납), 리스는 **반복 부과**(잔액 불변). 관리: `admin/api/debt_action.php`, `inc/RiderDebt.php`, 라이더 상세 "부채" 카드.

🆕 **(2026-08-08) 리스 전용 컬럼** — `lease_provider` enum(`hq`/`distributor`/`agency`, 리스 제공 주체) · `vin`(차대번호, 오토바이 리스) · `fee_hq`/`fee_distributor`/`fee_agency`(수수료 배분, **일 단위 정액 원**). 제공 주체와 그 하위 조직만 배분에 참여하며(본사 제공=3자, 총판 제공=2자, 대리점 제공=단독), 상위 조직 몫은 서버에서 0으로 강제. **배분 합계 > `daily_amount`면 저장 거부.** 차감 시점의 실제 배분액은 `rider_debt_entries.fee_hq/fee_distributor/fee_agency`에 스냅샷으로 남는다(설정 변경과 무관하게 과거 정산 근거 보존).

### `rider_debt_entries` — 🆕(2026-07-24) 부채 차감 이력
차감 1회 = 1행. `applied_date`(차감 귀속일) · `days`(차감일수) · `amount`(차감액=일납×일수 또는 수동) · `balance_after`(차감후잔액) · `deduction_entry_id`(생성한 `deduction_entries` 연결). **핵심 연동**: 차감 실행 시 `deduction_entries` 행을 만들어 기존 `SettlementLedger::buildFeeItems` 흐름이 그대로 차감(중복 로직 없음). 이력 취소 시 연결된 `deduction_entries`도 삭제되고 상각형 잔액이 복구됨.
UNIQUE(`debt_id`,`applied_date`) — 🆕(2026-07-30) **재실행 멱등성**: 같은 부채에 같은 귀속일로 두 번 차감되지 않음(정산 업로드를 재반영해도 리스 이중 차감 방지, `RiderDebt::applyLeaseForPeriod`가 이 제약을 이용해 조용히 skip).

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

### `agency_wallets` — 🆕(2026-07-22) 조직 지갑 잔액
PK=`agency_id`(=`organizations.id`, 이름과 달리 **본사·총판·대리점 모두 사용**). `balance`(PG 충전·수수료 수입 잔액) · `withholding_reserve`(원천세 예수금 누적, 고용·산재는 예수금 아님이라 제외).
**대리점 인출가능액 = balance − 라이더 정산금(rider_wallets 합계) − withholding_reserve** (`AgencyWallet::withdrawable`). 본사·총판은 라이더 정산금·예수금이 보통 0이라 잔액≈인출가능액.

### `agency_wallet_ledger` — 🆕 조직 지갑 변동 원장(감사용)
`direction`(credit/debit) · `reason` · `amount`(양수) · `balance_after`(스냅샷) · `ref_id`(연관 레코드) · `note` · `created_by`.
`reason` 코드: `pg_fund`(PG 정산 조달) · `pg_fee_in`(플랫폼 수수료 수입 — ⚠️ **2026-08-12부터 신규 생성 안 함**, 과거 행만 남음) · `rider_payout`(라이더 지급) · `agency_payout`(자체 인출) · `manual_adjust`(수동 조정) · `lease_fee_up`/`lease_fee_up_rev`(리스 수수료 상위 이체·취소) · `lease_fee_in`/`lease_fee_in_rev`(리스 수수료 수입·취소) · 🆕 `wd_fee_up`/`wd_fee_in`(정산수수료 상위 이체·수입).
조회 화면: `withdrawal/wallet-ledger` (`AgencyWallet::listLedgerScoped`).

---

## 7. PG 카드결제 · 오픈뱅킹 (Phase F, 🧩 뼈대 — 실 연동 전 mock)

### `agency_cards` — 🆕 대리점 등록 카드(다건, 우선순위)
`priority`(낮을수록 먼저 시도) · `billing_key`(실 PG 전엔 모의 발급) · `mock_limit`(0=무제한, 개발용 — 한도초과 시뮬레이션).
결제 시 `priority` 순 시도 → 실패(한도초과 등) 시 다음 카드 자동 재시도(`PgPayment::chargeForRider`).

### `pg_payments` — PG 결제 이력(라이더별 건건히)
`net_amount`(지갑 충전분) + `service_fee`(플랫폼 수수료, `org_fee_config` 기준) = `total_charged`(카드 청구 총액).
`status`(`success`/`failed`), `attempts`(시도한 카드 수), `card_id`(실제 승인 카드).
`hq_amount`/`distributor_amount`/`agency_amount`(🆕 2026-08-05) — `service_fee`의 본사/총판/대리점 분배 **금액 스냅샷**, `hq_pct`/`distributor_pct`/`agency_pct`는 그 시점 요율 스냅샷. `PgPayment::record()`가 결제 순간의 `PgFeeConfig::breakdownForAgency()` 결과를 그대로 저장 — 이후 `org_fee_config` 요율이 바뀌어도 이 값은 불변(재계산 안 함). 「플랫폼 수수료 내역」(`settlement/platform-fee`) 화면과 `OrgDashboard`의 "내 몫" 집계 둘 다 이 스냅샷 컬럼을 그대로 합산한다(실시간 재계산 금지).

### `agency_bank_accounts` — 조직 계좌 (🔧 2026-08-15 의미 재정의)
PK=`agency_id`. **행의 역할이 조직 레벨에 따라 다르다**(갑 확정 "돈이 나가는 계좌는 하나"):

| 레벨 | 역할 | `fintech_use_num` |
|---|---|---|
| `admin`(본사) | **출금 원천 계좌** — 라이더 이체·대리점 자체 인출이 전부 여기서 나감 | **필요**(이체 실행 키) |
| `agency`(대리점) | **정산금 수령 계좌** — 대리점이 자체 인출로 받을 곳 | 불필요(저장 안 함) |

- `BankAccount::payerFintechNum()` — 출금 원천(본사) 핀테크번호. `Disbursement::transfer()`가 이걸 쓴다(현재 `MockOpenBankingGateway`).
- `BankAccount::fintechNum($orgId)` — 특정 행을 그대로 읽는 저수준 함수. 대리점 id를 넘기면 수령 계좌 행이 나오므로 이체에는 쓰지 말 것.
- `BankAccount::save()`는 기존 `fintech_use_num`을 **보존**한다(예금주만 고쳐도 번호가 바뀌면 이체가 끊기므로). 모의 번호 발급도 본사 행에서만 한다.
- 화면(`withdrawal/payment-setup`): 본사를 대상으로 고르면 **출금 원천 계좌만** 노출(카드·PG충전 숨김, API도 `account_save` 외 거부). 대리점은 기존대로 카드+수령계좌+충전.
- 총판(`distributor`)은 결제수단 대상이 아니다(선택 불가).

> **Mock→Real 교체 지점:** `inc/PgGateway.php`(`PgGatewayFactory::make()`), `inc/OpenBankingGateway.php`(`OpenBankingGatewayFactory::make()`) — 실 스펙 도착 시 이 두 팩토리만 교체하면 스키마·상위 로직은 그대로 재사용.
>
> 🆕 **(2026-08-14 · 08-15 정밀 재검토) PG사 확정 — 위루트(weroutefincorp.com), 빌링 API 기반.** 분석 전문은 [REF_PG_WEROUTE.md](REF_PG_WEROUTE.md). **분석만 완료, 코드 미착수.**
>
> 🔴 **가장 중요 — 자금 모델 충돌:** 위루트 **대사 API**(`/docs/reconciliation`, 별도 문서)의 정산 응답을 보면 승인액 1,000원 → 실입금 `settle_amount` 960~967원이고 `settle_dt`/`deposit_dt`/`deposit_status`가 따로 있다. 즉 **PG가 약 3.3% 수수료를 떼고 며칠 뒤 입금**한다. 그런데 `PgPayment`는 결제 성공 즉시 `AgencyWallet::credit(net)`으로 전액을 지갑에 올리고 라이더 이체가 그 잔액을 근거로 나간다 → **금액 부족 + 실제 은행잔고 도착 전 이체 실패** 위험. 코드가 아니라 **정책 결정 사항**(누가 PG 수수료를 부담하고 지갑은 언제 올릴지).
>
> **그 밖의 갭:** 인증 체계가 2종(거래=`Authorization: {pay_key}` 원문 / 대사=`External-Api: Bearer {API_KEY}` + 로그인 `access_token` 30h) → **자격증명 6개** 저장소 필요. `pg_payments.ord_num` 컬럼 신설 필수(위루트는 결제 *전에* 주문번호를 요구하는데 우리는 결제 후에 id가 생김). `agency_cards`에 `bill_code`/`issuer_code` 없음. 카드 등록 폼이 카드번호·유효기간·비번·생년월일을 안 받음(자체 `MOCK-BK-` 생성 중). 웹훅 수신 엔드포인트 자체가 없음(`sign_key` 발급처 문서에 없음).
>
> ✅ **확인된 호환성:** `system_codes(category='bank')` 13종이 위루트 은행코드표와 **전부 일치**. 단, 발급사코드(`issuer_code`: 01비씨/02국민…)와 은행코드(`acct_bank_code`: 003기업/004국민…)는 **다른 체계**라 혼동 주의.

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

### `role_permissions` — 역할별 화면 조회·쓰기 권한 (2026-08-05)
PK `(role, area)`. `role`: `admin`/`operation`/`settlement`(super는 항상 전권이라 행 없음). `area`: `dashboard`/`settlement`/`deduction`/`promotion`/`withdrawal`/`content`/`riders`. `can_view`/`can_write`(TINYINT). 「권한 관리」(`system/permissions`, super 전용) 화면에서 편집, `inc/RolePermission.php`가 캐시 조회. **`system/*`(시스템관리)는 이 테이블과 무관하게 코드로 super 전용 고정**(라우트별 area 매핑 대상 아님).

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
| `organizations` | `Organization`(CRUD·`detail()`·`suggestCode()`·생성시 config 시딩), `Org`(스코핑) |
| `admins` | `AdminAccount`(전체), `OrgAccount`(조직 서브계정) |
| `riders`, `rider_platforms` | (라이더 CRUD — `admin/api/riders.php`, `rider_action.php`) |
| `rider_wallets` | `RiderWallet` |
| `settlement_*` | `SettlementLedger`, `SettlementAmounts`(총액·부가세·차감내역 산식), `AgencyFeeConfig`, `XlsxParser`(파싱), `admin/api/settlement_upload.php`(저장) |
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
| 2026-08-09 (3) | **지갑 원장 문서화.** `agency_wallets`/`agency_wallet_ledger`가 본사·총판에도 쓰임을 명시. ledger `reason` 코드 목록 보강. 컬럼 추가 없음. |
| 2026-08-09 (2) | **부가세 제외.** 반영 기준·사이클 `gross_amount`·PG 조달액을 부가세 제외 공급가액으로 통일. `fee_code=vat` 신규 생성 중단. 컬럼 추가 없음. |
| 2026-08-09 | **정산 기준액 의미 정정(스키마 컬럼 추가 없음).** `settlement_daily_riders.gross_amount`=엑셀 총 정산금액(원본). 반영 base는 부가세 제외액. `payout_amount`/`platform_payout`는 보수액 스냅샷. `excel_deduction` 사용. |
| 2026-08-05 (7) | **`pg_payments` 수수료 분배 스냅샷 추가.** `hq_amount`/`distributor_amount`/`agency_amount`/`hq_pct`/`distributor_pct`/`agency_pct` 6컬럼 추가(테이블 수 변화 없음). §7 참고. |
| 2026-08-05 (4) | **총괄 관리자 역할(`manager`) 추가.** `admins.role` ENUM에 `manager` 추가(테이블 수 변화 없음). §2 참고. |
| 2026-08-05 (3) | **역할별 권한 관리 신규.** `role_permissions`(role×area→can_view/can_write) 추가 → **34개 테이블**. 기존 `inc/auth.php` 하드코딩 라우트·역할 매핑을 DB화, 「권한 관리」(`system/permissions`, super 전용) 화면에서 편집. `system/*`(시스템관리)는 이 테이블과 무관하게 코드로 super 전용 고정. §9 참고. |
| 2026-07-22 | 이 문서 최초 작성 — Phase A~F(관리자 재설계) 완료 시점의 DB 전체(25개 테이블) 스냅샷 |
| 2026-07-23 | 정산 엑셀 row-level 저장 확장(실 파일 검증). 신규 `settlement_order_details`(오더별 상세내역, 328행/업로드), `settlement_hourly_insurance`(시간제보험) 추가 → 27개 테이블. `settlement_weekly_deductions`에 `registered_entry_id` 컬럼 추가 + 파서 컬럼매핑 버그 수정(배달비→금액 오탐 교정) + 라이더 매칭 추가. `settlement_daily_riders.hourly_insurance` 실값 채움 확인. |
| 2026-07-23 (2) | 배달의민족 정산서 지원. `settlement_daily_riders` UNIQUE를 `(upload_id,license_id)`→`(upload_id,license_id,settlement_date)`로 확장(배민 다중 운행일 대응, 인덱스명 `uq_sdr_upload_license_date`). 스키마 신규 테이블 없음 — 배민 주문을 기존 `settlement_daily_riders`/`settlement_order_details`에 집계·저장. |
| 2026-07-24 (2) | **정산수수료 age-bucket용 사이클 출금 추적.** `settlement_rider_cycles`에 `withdrawn_amount`(INT, 0=미출금 / net_amount=완전출금, 부분출금까지 표현) + `idx_src_rider_withdrawn` 인덱스 추가. 신규 `withdrawal_request_cycles`(출금↔사이클 연결: `request_id`·`cycle_id`·`amount`·`order_count`, UNIQUE(request,cycle), 양쪽 FK CASCADE) → **30개 테이블**. 출금 신청 시점에 사이클을 점유하고 반려 시 해제한다(`inc/WithdrawalCycles.php`). 보증금 경계 정책(통째/부분)이 미확정이지만 **두 정책 모두 이 스키마로 수용**되므로 재마이그레이션 불필요. |
| 2026-07-30 | **지원금 파싱 + 리스 자동계산(정산명세서 실 파서 `parser.py` 분석 결과 반영).** ① 신규 `settlement_support_amounts`(지원금·추가지원금 원본) → **31개 테이블**, `settlement_daily_riders.support_amount`·`settlement_rider_cycles.support_amount` 컬럼 추가. `XlsxParser::parseSupportSheet()`/`parseAddSupportSheet()` 신규 — 시트 제목("지원금 상세 내역서")에 헤더 키워드가 겹쳐 오판하던 버그를 실 파일로 발견·수정. `SettlementLedger`가 net_amount 계산 시 지원금을 gross에 가산하도록 수정. 실 파일+가짜데이터 e2e 검증(5,000+2,000원 정확히 가산 확인). ② `rider_debts.planned_end_on`(리스 계약 종료 예정일) + `rider_debt_entries` UNIQUE(debt_id,applied_date) 추가 — `RiderDebt::applyLeaseForPeriod()`가 계약기간∩정산기간 겹치는 일수를 자동 계산해 차감(수동 일수 입력 불필요), 재실행해도 이중 차감 안 됨(11/11 + SettlementLedger 배선 검증). |
| 2026-07-24 | **라이더 부채 원장 신규.** `rider_debts`(대여금/리스/선지급 헤더: 원금·잔액·일납·채권자·상태) + `rider_debt_entries`(차감 이력) 추가 → **29개 테이블**. 라이더 정산명세서(PDF)의 대여금/리스/선지급 차감 명세 대응. 차감 실행 시 `deduction_entries`(kind=`loan`/`lease`/`advance`)를 생성해 기존 정산 반영 흐름이 그대로 차감. `sql/rider_debts.sql`, `inc/RiderDebt.php`, `admin/api/debt_action.php`, 라이더 상세 "부채" 카드. |
| 2026-08-05 | **팀지역 분리 · 쿠팡ID 다중 · 초기비번 플래그 · 플랫폼 수수료 3분할 (갑 지시 재편, 신규 테이블 없음 — 31개 유지).** ① `settlement_uploads`에 `team_name`/`region_name` 컬럼 추가(기존 `stored_path` JSON에서 백필) + `idx_su_agency_date_team`. 한 대리점이 **같은 날 여러 팀지역** 정산서를 올릴 수 있게 됨. ② `settlement_rider_cycles.team_region` 추가하고 **UNIQUE를 `uq_src_rider_date_pf`(rider,date,platform) → `uq_src_rider_date_pf_team`(rider,date,platform,team_region)로 교체**. 한 라이더가 같은 날 두 팀지역에서 일하면 사이클 2건이 정상 생성됨(기존엔 두 번째가 조용히 skip). ⚠️ 팀/지역 문자열은 **반드시 NFC 정규화 후 저장**할 것 — 실데이터에서 같은 "팀도깨비 서울_강서남부"가 NFD(조합형)/NFC(완성형) 두 형태로 섞여 있어 UNIQUE가 무력화될 뻔했다(`normalize_hangul_nfc()` in `inc/helpers.php`, 마이그레이션에서 기존 데이터 일괄 정규화). ③ `rider_platforms`에 `UNIQUE(rider_id, platform, external_id)` 추가 — 한 라이더가 **팀지역별로 여러 쿠팡ID 보유 가능**(플랫폼당 1개 제약 폐지, 완전 중복만 차단). ④ `riders.must_change_password` 추가(1=초기비번 0000 상태, 최초 로그인 시 변경 강제). ⑤ `org_fee_config`에 `hq_pct`/`distributor_pct`/`agency_pct` 추가 — 플랫폼 수수료(구 영업대행수수료)를 **조직당 "내 몫" 1개 → 대리점 행 하나에 본사/총판/대리점 3몫**으로 재편(기존 값 이관). 구 `pg_service_fee_pct`는 이관 후 미사용(호환 위해 컬럼 유지). |
| 2026-08-05 (2) | **프로모션 지급 신규 → 33개 테이블.** `promotion_batches`(업로드 1회분 = 대리점 + 지급일자 단위. status: draft/paid/partial/failed, 대상·성공 인원과 금액·수수료 집계) + `promotion_entries`(엑셀 1행 = 1건. `rider_id`(미매칭 시 NULL) · `rider_code_raw`/`rider_name_raw`(엑셀 원본) · `promo1_amount`/`promo2_amount`/`total_amount` · `fee_amount`(이 건에 붙은 플랫폼 수수료) · status(pending/paid/failed/skipped) · `pg_payment_id`(성공한 카드결제 FK) · `fail_reason`). 지급은 **라이더별 카드결제(프로모션액 + 플랫폼 수수료) 성공 건만** `rider_wallets`에 적립하며, 결제 기록은 기존 `pg_payments`를 그대로 쓴다(`upload_id`는 NULL — 정산 업로드와 무관한 지급이라). 라이더 식별키는 `riders.rider_code`. |

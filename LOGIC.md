# 도깨비 배달대행 — 비즈니스 로직 정리

> **목적:** 페이지별로 기능을 붙여 나가면서 **규칙·상태·권한·DB 연동 여부**가 어긋나지 않도록 기준 문서를 둡니다.  
> **유지:** 화면/API/도메인 클래스를 수정할 때 **해당 섹션을 함께 갱신**하세요.

---

## 1. 문서 사용법

| 작업 전 | 작업 후 |
|--------|--------|
| 이 문서에서 해당 메뉴의 **상태(연동/목업)**, **권한**, **상태값 enum** 확인 | 변경한 규칙·API·테이블·감사 action 코드를 반영 |
| **§4 교차 규칙**(역할, 코드 마스터, 감사)과 충돌 없는지 확인 | **§6 주의·미결**에 새 리스크가 있으면 추가 |
| 목업 화면을 DB 연동할 때 §3 표의 상태를 `DB`로 바꿈 | migrate/seed 필요 여부 기록 |

**에이전트·개발 공통:** 화면/API/DB/권한을 수정하는 **모든 작업**은 같은 변경 안에서 **본 문서(`LOGIC.md`)도 함께 갱신**한다. (프로젝트 규칙: `.cursor/rules/logic-md-sync.mdc`)

**상태 표기**

- **DB** — 실 DB + PHP 도메인 클래스/API 사용
- **부분** — 일부만 DB, UI/집계는 목업
- **목업** — localStorage·하드코딩·샘플 데이터만

---

## 2. 아키텍처 요약

```
[관리자] admin/index.php → inc/routes.php → admin/views/*.php
                      ↘ admin/api/*.php → inc/*.php (도메인) → MariaDB

[라이더] rider/index.php → inc/rider_routes.php → rider/views/*.php
```

| 계층 | 역할 |
|------|------|
| `admin/views/*`, `rider/views/*` | HTML·JS. 가능하면 **초기 목록은 서버 렌더**, 변경은 API |
| `admin/api/*` | JSON API. 로그인·쓰기 권한 검사 후 도메인 클래스 호출 |
| `inc/*.php` | 비즈니스 규칙·SQL·상태 전이 (화면별 로직의 **단일 출처** 지향) |
| `system_codes` | 은행·상태 등 **표시명/선택지** 마스터 (코드 값은 앱 전역에서 동일해야 함) |
| `audit_logs` | 관리자 행위 기록 (실패 시 기능은 계속, 로그만 생략) |

**저장소 구조 (2026-05 정리):** Metronic **데모 HTML·`src/` 빌드 소스는 제거**됨. 런타임은 아래만 사용.

| 경로 | 용도 |
|------|------|
| `admin/`, `rider/`, `inc/`, `sql/` | PHP 앱 |
| `index.html` | 공개 랜딩 |
| `assets/plugins/global/`, `assets/css/style.bundle.css`, `assets/js/scripts.bundle.js` | Metronic 번들 |
| `assets/js/custom/landing.js`, `assets/plugins/custom/typedjs/` | 랜딩 전용 |
| `assets/css/rider-mobile.css`, `rider-settlement-calendar.css`, `js/admin-datepickers.js` | admin·rider |
| `assets/media/logos/`, `auth/`, `favicon/`, `banners/`, `svg/illustrations/landing.svg`, `svg/misc/octagon.svg` | UI·랜딩 이미지 |

**DB 연결:** `inc/env.php` (루트 `.env`) → `inc/db.php` — Apache `SetEnv`가 있으면 `.env`보다 우선. `db_table_exists()`는 `information_schema` 사용 (`SHOW TABLES LIKE ?`는 사용 금지).

---

## 3. 관리자 메뉴별 구현 상태

| route | 화면 | 상태 | 도메인/API | 비고 |
|-------|------|------|------------|------|
| `dashboard` | 대시보드 | **DB** | `AdminDashboard` | `settlement_daily_riders`, `withdrawal_requests` 등 테이블 없으면 일부 0 |
| `settlement/upload` | 엑셀 업로드 | **DB** | `settlement_upload.php`, `XlsxParser`, `XlsxDecrypt` | 암호 자동 해제 · **중복 업로드 거부** (§5.4) |
| `settlement/upload-detail` | 업로드 상세 | **DB** | SQL 직접 | 정산 반영·수수료·지갑 |
| `settlement/history` | 업로드 이력 | **DB** | `settlement_history.php` | 기간·플랫폼·파일명 검색, 페이지네이션 |
| `settlement/fees` | 정산 수수료 내역 | **DB** | `SettlementLedger::listAdmin` | 정산 반영 후 생성 |
| `settlement/fee-detail` | 정산 수수료 상세 | **DB** | `SettlementLedger::find` | 항목별 차감 |
| `deduction/agency-fee` | 선공제(대행 수수료) | **DB** | `AgencyFeeConfig` | 적립일수·건당 정액 · 전역 비율(`deduction_global_config`) |
| `withdrawal/list` | 출금 목록 | **DB** | `Withdrawal`, `withdrawals.php` | 라이더 신청 → `pending` |
| `withdrawal/settings` | 출금 정책 | **DB** | `WithdrawalConfig`, `withdrawal_config.php` | super · 보증금·건당 수수료 |
| `withdrawal/download` | 출금 다운로드 | **DB** | `withdrawal_download_file.php`, `ShinhanTransferFile` | |
| `withdrawal/complete` | 처리 완료 | **DB** | `Withdrawal` | |
| `content/notices` | 공지 | **DB** | `Notice`, `notices.php` | `php migrate.php` |
| `content/banners` | 배너 | **DB** | `Banner`, `banners.php`, `banner_upload.php` | |
| `riders/list`, `riders/detail` | 라이더 | **DB** | `riders.php`, `rider_action.php` | 은행 목록: `system_codes` |
| `system/admins` | 관리자·권한 | **DB** | `AdminAccount`, `admins.php` | super 전용 |
| `system/codes` | 코드/마스터 | **DB** | `SystemCode`, `codes.php` | super 전용 |
| `system/settlement-excel` | 정산 엑셀 열기 암호 | **DB** | `SettlementExcelConfig`, `settlement_excel_config.php` | super, settlement |
| `system/audit` | 감사 로그 | **DB** | `AuditLog` | `php migrate.php` |

---

## 4. 교차 규칙 (모든 페이지 공통)

### 4.1 관리자 인증

- 로그인: `admin/index.php?route=login` → `admins` 테이블, `password_verify`, `is_active = 1`
- 세션: `admin_auth`, `admin_id`, `admin_login_id`, `admin_name`, `admin_role`
- 브루트포스: 10분 내 5회 실패 시 잠금
- 성공 시 `last_login_at` 갱신 + `AuditLog::record('auth.login', ...)`

**시드 계정 (개발):** `admin` / `Admin1234!` (role: `super`)

### 4.2 역할(RBAC)

| role (DB enum) | 라벨 | 메뉴 접근 | 데이터 쓰기 |
|----------------|------|-----------|-------------|
| `super` | 최고 관리자 | 전체 | 전체 |
| `operation` | 운영 | 대시보드, 정산(조회), 출금, 라이더, 콘텐츠 | `content`, `riders`, `withdrawal` |
| `settlement` | 정산 | 대시보드, 정산, 선공제(대행), 출금(조회) | `settlement`, `deduction` |
| `admin` | 조회 전용 | 위 조회 가능 메뉴 + **감사 로그** | **없음** (모든 POST API 403) |

- **페이지:** `admin/index.php` → `admin_can_access_route($route)` (`inc/auth.php`)
- **API:** POST 시 `admin_deny_write_json('영역')` — 영역: `content` \| `riders` \| `withdrawal` \| `settlement` \| `deduction` \| `system`
- **규칙 정의:** `admin_route_access_rules()`, `admin_can_write()` — **변경 시 이 문서 §4.2도 수정**

### 4.3 시스템 코드 (`system_codes`)

| category | 용도 | 참조 예 |
|----------|------|---------|
| `bank` | 라이더 계좌 은행 | `riders.bank_code`, JOIN 라벨 |
| `vehicle` | 차량 유형 | `riders.vehicle_type` |
| `rider_status` | 라이더 상태 | `riders.status` |
| `settlement_status` | 업로드 배치 상태 | `settlement_uploads.status` |
| `withdrawal_status` | 출금 상태 | `withdrawal_requests.status` |
| `platform` | 배달 플랫폼 | 정산 업로드 `platform` enum |
| `deduction_kind` | 차감 종류 | (차감 기능 연동 예정) |

**규칙**

- 코드 값(`code`)은 카테고리 내 유일. **생성 후 code 변경 불가.**
- `is_active = 0` → 선택 목록에서 제외 (예: `riders_list` 은행 필터)
- **삭제:** 참조 건수 > 0 이면 불가 → 사용 중지로 처리
- seed: `seed.php` / `seed.sql`

### 4.4 감사 로그 (`audit_logs`)

- 스키마: `actor_type`, `actor_id`, `action`, `target_table`, `target_id`, `before_value`, `after_value`, `ip`, `user_agent`
- `AuditLog::record('도메인.동작', $target, $detail)` — action은 DB에 `LOGIN`, `UPDATE`, `CREATE` 등으로 정규화
- 테이블 없으면 **조용히 skip** (기능은 동작)
- `target_table` 매핑: `content.notice.*` → `content_notices`, `admin.*` → `admins`, `codes.*` → `system_codes`, `rider.*` → `riders`, … (`AuditLog::resolveTarget`)

**주요 action (내부 코드 → 화면 action)**

| 내부 코드 | 의미 |
|-----------|------|
| `auth.login` / `auth.login.fail` / `auth.logout` | 로그인·실패·로그아웃 |
| `content.notice.save` / `.delete` | 공지 |
| `content.banner.save` / `.delete` | 배너 |
| `admin.create` / `.update` / `.activate` / `.deactivate` | 관리자 |
| `codes.create` / `.update` / `.delete` | 코드 마스터 |
| `rider.status` / `.memo` / … | 라이더 |
| `settlement.upload` | 정산 업로드 | |
| `withdrawal.config.save` | 출금 정책 저장 | |
| `withdrawal.*` | 출금 처리 | |

---

## 5. 도메인별 로직

### 5.1 공지 (`Notice` / `content_notices`)

| 항목 | 규칙 |
|------|------|
| 상태 | `draft` \| `published` \| `hidden` |
| 카테고리 | `일반`, `안내`, `긴급` (코드 고정, system_codes 아님) |
| public_id | `nt-YYYYMMDD-순번` 형식 |
| 라이더 노출 | `published` + `published_at <= NOW()`; 상단 고정 `pinned` 우선 |
| API | `admin/api/notices.php` — GET 목록, POST `save` \| `delete` |
| 권한 | 조회: operation+, 쓰기: operation+ |

### 5.2 배너 (`Banner` / `content_banners`)

| 항목 | 규칙 |
|------|------|
| 슬롯 | `home_top`, `home_middle`, `rider_app` |
| **라이더 홈 캐러셀** | **`rider_app` 슬롯만** (`Banner::RIDER_HOME_CAROUSEL_SLOT`) |
| 이미지 | 업로드 → `admin/api/banner_upload.php` → `/uploads/`; 라이더 앱은 `rider/p/asset.php` 프록시 |
| 활성 | `is_active`, 기간 `starts_at` ~ `ends_at` |
| API | `admin/api/banners.php` |

### 5.3 라이더 (`riders` 테이블)

| 항목 | 규칙 |
|------|------|
| 상태 | `active`, `suspended`, `leave_request`, `offboarded` — **system_codes.rider_status와 일치해야 함** |
| API | `riders.php` (목록·등록), `rider_action.php` (상세·상태·메모·출금보류·일일정산 플래그) |
| 은행 표시 | `bank_code` → `system_codes` JOIN |
| 권한 | operation+ 읽기/쓰기 |

**라이더 앱:** `RiderAuth` — 별도 세션; 비밀번호 변경 `profile/password`.

### 5.4 정산 업로드

```
엑셀 업로드 → settlement_uploads (status: uploaded → parsing → parsed → applied | error)
           → settlement_daily_riders (일별·라이더·건수·금액)
```

| 항목 | 규칙 |
|------|------|
| platform | `baemin` \| `coupang` \| `other` — **현재 파서·업로드 검증은 쿠팡이츠 일간 정산서만** (배민 미지원) |
| 파서 | `inc/XlsxParser.php` (쿠팡이츠 일간 형식) |
| 파일 열기 암호 | `SettlementExcelConfig` (DB `settlement_excel_config` · `.env`) · **설정 UI:** `system/settlement-excel` · 복호화: `scripts/decrypt_xlsx.py` |
| **중복 업로드** | 동일 **귀속일+플랫폼** 이미 있으면 거부. 동일 **파일명** 또는 **SHA256(`file_hash`)** 도 거부. 덮어쓰기 없음 — 재업로드 시 기존 건 삭제 후 |
| **플랫폼 자동 감지** | 파일 선택 시 `settlement_upload_preview.php` → `SettlementPlatformDetect`. **파싱 성공 = 쿠팡이츠** 로 판단 (현재 파서 기준). 파일명·헤더·내용 키워드 보조 |
| **플랫폼 불일치 차단** | 선택 platform ≠ 감지 결과(신뢰도 medium 이상)면 업로드 거부. `confirm_platform_mismatch=1` 로 강제 업로드 가능. `other`는 검증 생략 |
| 이력 화면 | `settlement/history` — 필터·목록·상세 링크 |
| migrate | `php migrate.php` (`sql/base_schema.sql` → 확장 SQL) |
| 권한 | 조회: settlement, operation, super / 업로드 POST: settlement, super |

#### 정산 반영·수수료 내역

```
업로드 상세 「정산 반영 · 수수료·지갑」
  → SettlementLedger::applyUpload()
  → settlement_rider_cycles (라이더·일·플랫폼별 1건)
  → settlement_fee_items (대행·원천·고용보험·추가 차감)
  → RiderWallet::credit(net, accrued+1)
  → settlement_uploads.status = applied
```

| 화면 | route | 설명 |
|------|-------|------|
| 관리자 목록 | `settlement/fees` | 기간·라이더 검색 |
| 관리자 상세 | `settlement/fee-detail?cycle=` | 항목별 차감 |
| 라이더 목록 | `settlement/fees` | 본인 완료 내역 |
| 라이더 상세 | `settlement/fee-detail?cycle=` | 본인만 조회 |

| API | `admin/api/settlement_apply.php` (POST upload_id) |
| migrate | `php migrate.php` |
| 감사 | `settlement.apply` → `settlement_rider_cycles` |

수수료 산출: **대행** `AgencyFeeConfig`(적립일수·건당 정액) + `deduction_global_config` 비율(원천·고용보험) + 동일 `applied_date` 의 `deduction_entries`.

### 5.5 정산·출금 일원화

> 정산 데이터 → 지갑 반영은 **`SettlementLedger::applyUpload()` → `RiderWallet::credit()`** 로 처리.

#### 지갑 (`rider_wallets`)

| 컬럼 | 의미 |
|------|------|
| `balance` | 누적 잔액 (정산 반영 전 임시·수동 credit 가능) |
| `accrued_days` | 쌓인 정산 **일수** (출금 수수료 구간 판단) |

스키마: `php migrate.php`

#### 출금 정책 (`withdrawal_config` — 단일 row)

| 설정 | 기본값 | 설명 |
|------|--------|------|
| `reserve_amount` | 50,000 | **보증금** — 출금 후 지갑에 남김 |
| `fee_day_threshold` | 7 | 적립 일수 기준 |
| `fee_per_tx_short` | 80 | 기준 **미만** 건당 수수료(원) |
| `fee_per_tx_long` | 40 | 기준 **이상** 건당 수수료(원) |

관리: **출금 > 출금 정책** (`withdrawal/settings`, super)

#### 라이더 전액 출금 (`Withdrawal::applyForRider`)

```
실지급(amount) = balance − reserve_amount − fee_per_tx
fee_per_tx = accrued_days < fee_day_threshold ? fee_per_tx_short : fee_per_tx_long
```

**규칙**

- **부분 출금 불가** — 항상 현재 `balance` 기준 전액 신청
- `pending` / `downloaded` 건이 있으면 재신청 불가
- `withdrawal_hold`, 비활성, 계좌 미등록 시 불가
- `withdrawal_requests` 매핑: `gross_amount`=잔액, `withhold_min_retain`=보증금, `withhold_other`=건당 수수료, `accrued_days`=적립 일수
- **완료(`completed`)** 시: `RiderWallet::finalizeAfterComplete` → `balance = reserve_amount`, `accrued_days = 0`
- **반려** 시: 지갑 변경 없음

라이더 UI: `withdrawal/apply` POST → `rider/index.php`  
관리자: 기존 목록·다운로드·완료 흐름 유지

### 5.6 출금 (`Withdrawal` / `withdrawal_requests`)

| status | 의미 | system_codes |
|--------|------|--------------|
| `pending` | 대기 | ✓ |
| `downloaded` | 이체 파일 다운로드됨 | ✓ |
| `completed` | 처리 완료 | ✓ |
| `rejected` | 반려 | ✓ |

| kind | 의미 |
|------|------|
| `rider_manual` | **라이더 앱 전액 출금** (표준) |
| `auto_daily` | ~~자동 일일정산~~ (레거시·신규 생성 안 함) |

- public_id: `wd-{id}` / `wd-auto-{id}`
- API: `withdrawals.php`, `withdrawal_download_file.php` (신한 이체 파일)
- **쓰기:** operation (super 포함) — settlement 역할은 목록·다운로드 **조회만** (route는 허용, POST는 403)

### 5.7 관리자 계정 (`AdminAccount` / `admins`)

| role | DB enum |
|------|---------|
| super, admin, operation, settlement | `admins.role` |

- **super만** 계정 CRUD (`admin/api/admins.php`)
- 본인 비활성화 불가; 활성 super 최소 1명 유지
- 비밀번호 bcrypt cost 12, 8자 이상

### 5.8 대시보드 (`AdminDashboard`)

- 이번 주 활성 라이더, 주간 payout/건수, 출금 대기, 게시 공지 수, 월 차감, 플랫폼별 payout, 최근 업로드
- 테이블 없으면 try/catch로 0 — **목업 아님, DB 의존**

---

## 6. 라이더 앱 (요약)

| route | 기능 | 상태 |
|-------|------|------|
| `home` | 공지 요약, 정산 카드, **`rider_app` 배너** | DB |
| `notices/*` | 공지 목록·상세 | DB |
| `settlement/*` | 정산 달력·목록·수수료 내역 | DB (`SettlementLedger` 반영분) |
| `withdrawal/*` | 출금 신청·내역 | DB |
| `profile/*` | 정보·계좌·비밀번호 | DB |

PWA: `rider/service-worker.js`, `manifest.php`.

---

## 7. 마이그레이션·시드

| 스크립트 | 대상 |
|----------|------|
| `php migrate.php` | `inc/MigrateRunner.php` — **`sql/base_schema.sql`** (admins, riders, settlement_uploads, …) → `content_tables`, `settlement_*`, `withdrawal_wallet`, `agency_fee_config`, `audit_tables` + ALTER (멱등) |
| `php seed.php` / `seed.sql` | admins, system_codes, deduction_global_config 초기값 |

운영 배포 후 `migrate.php`·`seed.php` **웹 접근 차단** 권장 (GitHub Actions rsync에서 제외).

---

## 8. 주의·미결 (로직 리스크)

1. **상태값 이중 정의** — `Withdrawal::STATUS_LABELS`, `Notice` 상태, `system_codes`가 따로 있음. **코드 값 변경 시 세 곳을 함께 확인.**
2. **operation의 정산 메뉴** — route 접근은 허용, 업로드 POST는 settlement만.
3. **settlement 역할의 출금** — 목록·다운로드 화면은 열리나 상태 변경 POST는 operation만.
4. **지갑 잔액** — 정산 반영은 업로드 상세 「정산 반영 · 수수료·지갑」 또는 `SettlementLedger::applyUpload()`. 테스트용 수동 반영: `RiderWallet::credit()` 또는 DB 직접.
5. **적립 일수(`accrued_days`)** — 정산 반영 시 `RiderWallet::credit($net, true)` 로 +1.
6. **감사 로그** — `before_value` 미사용(대부분 after JSON만).
7. **코드 마스터 vs PHP enum** — DB enum과 `system_codes` 불일치 시 JOIN/라벨 깨짐.
8. **sidebar** — 권한 없는 메뉴 링크는 403 (숨김 optional).
9. **미구현(메뉴 없음)** — 프로모션 배치, 종합 통계·엑셀 내보내기, 파싱 오류 전용 화면, 수동 차감 등록·자동 차감·할부 UI는 **목업 제거됨**. 필요 시 §9 체크리스트로 새로 추가.

---

## 9. 새 기능 추가 체크리스트

- [ ] `inc/routes.php` (+ 라이더면 `rider_routes.php`) 등록
- [ ] `inc/auth.php` — `admin_route_access_rules` / `admin_can_write` 반영
- [ ] 도메인 클래스(`inc/*.php`)에 SQL·검증·상태 전이
- [ ] `admin/api/*.php` — 로그인 + 쓰기 권한 + `AuditLog::record`
- [ ] `system_codes` 필요 여부
- [ ] migrate/SQL + seed
- [ ] **이 문서 §3 표·§5 해당 절·§8** 갱신

---

## 10. 변경 이력

| 날짜 | 내용 |
|------|------|
| 2026-05-24 | 목업 화면 제거: 프로모션·통계·파싱 오류·차감(수동/자동/할부) UI — `deduction/agency-fee`만 유지 |
| 2026-05-24 | 저장소 정리: Metronic 데모 HTML·`src/`·미사용 assets 제거, `rider_shell_end.php` 복구 |
| 2026-05-24 | 출금 일원화: 자동 일일정산 메뉴 제거, 라이더 전액 출금·건당 수수료·보증금·withdrawal_config |
| 2026-05-24 | 초안: RBAC, 감사, 코드 마스터, 콘텐츠·라이더·정산·출금·시스템 연동 상태 정리 |

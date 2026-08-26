# 펌뱅킹 연동 참고 — 바움P&S

출처: `바움P&S_펌뱅킹_API_연동_매뉴얼_v1.1.8` (2026-07)
구현: `inc/BaumFirmGateway.php` · `inc/FirmConfig.php` · `inc/BaumCrypto.php` · `inc/FirmApiLog.php`
화면: 시스템 관리 → **펌뱅킹 연동** (`system/firm-integration`)

---

## 1. 서버

| 구분 | URL |
|---|---|
| 개발 | `https://dev-firm-api.baumpns.com` |
| 운영 | `https://firm-api.baumpns.com` |

## 2. 인증

`POST /auth/access_token` — **이 요청만 암호화하지 않는다.**

```
authorization: Basic base64("{Client_ID}:{Secret_Key}")
```

응답의 `access_token` 을 이후 모든 요청에 `authorization: Bearer {token}` 으로 넣는다.

> ⚠️ **확인 대기** — 응답 `expires_in` 의 단위. 문서 설명은 "초" 인데 예시값이 `86400000` 이다.
> 초로 보면 1,000일이라 토큰을 영원히 갱신하지 않게 된다. 24시간을 밀리초로 적은 것으로 보고
> **10만 이상이면 밀리초로 간주**하도록 구현했다(`FirmConfig::storeAccessToken()`).

## 3. 암호화 — 우리 시스템의 두 암호화와 헷갈리지 말 것

| 클래스 | 용도 | 알고리즘 | 키 출처 |
|---|---|---|---|
| `Crypto` | **우리 DB 저장** | AES-256-**GCM** | `.env` 의 `APP_ENC_KEY` |
| `BaumCrypto` | **바움과 송수신** | AES-256-**CBC** / PKCS5 / Base64 | 바움 발급 KEY·IV |

바움 KEY/IV 자체도 우리 DB 에 넣을 때 `Crypto` 로 한 번 더 감싼다. 이중 구조가 맞다.

**적용 범위** — `/auth/access_token` 을 제외한 **모든 요청·응답 Body 전체**. 바움이 우리에게
보내는 「계좌이체 처리결과 통보」도 암호화돼 있고, **우리가 돌려주는 응답 Body 도 암호화해야**
정상 처리된다.

Java `AES/CBC/PKCS5Padding` 과 PHP `aes-256-cbc` 는 블록 16바이트에서 동일하다.
`openssl` CLI 로 교차 검증 완료(같은 키·IV·평문 → 같은 암호문).

## 4. 엔드포인트

| 기능 | Method | Path |
|---|---|---|
| 토큰 발급 | POST | `/auth/access_token` |
| 예금주 조회 | POST | `/api/firm/account-holder` ⚠️ |
| 잔액(전체 포켓) | GET | `/api/firm/account-pocket` |
| 잔액(특정 포켓) | GET | `/api/firm/pocket/{포켓코드}` |
| 계좌이체 접수 | POST | `/api/firm/transfer-submission` |
| 이체 정보 조회 | GET | `/api/firm/transfer-info/{transactionId}` |
| 계좌이체 취소 | POST | `/api/firm/transfer-cancel` |
| 통보 URL 관리 | POST/GET/PUT/DELETE | `/api/firm/webhook` |

> ⚠️ **확인 대기** — 예금주 조회 경로가 문서 안에서 어긋난다.
> API 목록 표에는 `/api/firm/depositor-name`, 상세 페이지와 curl 예시에는 `/api/firm/account-holder`.
> **상세 페이지 기준(`account-holder`)으로 구현**했다.

## 5. 이체는 비동기다 — 이 연동의 핵심

```
RECEPTION → PROGRESS → NEED_CHECK → SUCCESS / FAILED / CANCELLED
```

`transfer-submission` 은 **접수(RECEPTION)만 즉시 응답**한다. 실제 성공/실패는 나중에
「계좌이체 처리결과 통보」(웹훅)로 온다.

**우리 코드의 기존 전제와 어긋나던 지점이다.** `Withdrawal::executeTransfers()` 가
`transfer()` 성공을 곧 "이체 완료"로 보고 지갑을 깎고 있었다 — 그대로 켰다면
접수만 된 건이 출금 완료로 찍히고 라이더 지갑이 먼저 줄었을 것이다.

**→ 2026-08-26 해결.** `withdrawal_requests.status` 에 `transferring` 을 넣고 지갑 차감을
통보 수신 뒤로 옮겼다. 구현과 검증 결과는 **§11** 참고.

- 취소는 **RECEPTION 상태만** 가능하다. 진행 중이면 못 막는다.
- 웹훅은 **1분 간격 최대 10회** 재시도 후 포기한다(Read Timeout 60초).
  → 유실 대비로 미확정 건을 `transfer-info` 로 조회하는 보정 경로가 필요하다.

## 6. 계좌이체 접수 요청

**Body 는 배열이다** — 개별 객체가 아니라 배열 하위 객체. **최대 100건**을 한 번에 보낸다.

| 필드 | 필수 | 설명 |
|---|---|---|
| `transactionId` | ✅ | 우리가 만드는 고유 ID. 중복 접수 방지 · 조회 · 취소에 쓰인다 |
| `bankCode` | ✅ | `C` + 3자리 (아래 참고) |
| `accountNumber` | ✅ | 숫자만 |
| `amount` | ✅ | 출금 금액 |
| `accountHolder` | | **입력하면 예금주명을 검증**한다 — 넣는 게 안전하다 |
| `receiverMemo` | | 받는 분 통장 표시 (미입력 시 사용자 이름) |
| `memo` | | 출금 메모 (미입력 시 수취 예금주명) |
| `pocketCode` | | 출금 포켓 (미입력 시 기본 포켓) |
| `reservationTime` | | `yyyyMMddHHmm` 예약 (미입력 시 즉시) |
| `metadata` | | 부가 정보, 최대 4096 bytes |

**Query String (선택)** — 수취 은행 AML 대응:
`delayedPeriodMinute` · `delayedReservationCount` (예: 1분당 최대 3건)

## 7. 은행 코드

바움 코드 = `C` + **우리가 쓰는 표준 3자리**. 예: `004` → `C004`(국민), `088` → `C088`(신한).

**우리 `system_codes` 의 은행 13개가 전부 바움 목록에 있음을 대조 확인했다.**
변환은 `BaumFirmGateway::bankCode()` 한 곳에서만 한다.

## 8. 오류 코드

| 구분 | 코드 |
|---|---|
| 공통 | `VALIDATION_FAILED` · `NOT_CLASSIFIED` · `EXCEPTION` · `RESOURCE_NOT_EXISTS` |
| 접수 | `DUPLICATE_SUBMISSION` · `BEING_PROCESSED` · `ALREADY_PROCESSED` · `POCKET_NOT_EXISTS` · `INSUFFICIENT_SUBMISSION_AMOUNT` · `TRANSFER_RESTRICTED_TIME` |
| 취소 | `TRANSACTION_NOT_EXISTS` · `BEING_PROCESSED` · `ALREADY_PROCESSED` |

개발환경 실패 테스트용 계좌번호: `9999999999999` (13자)

## 9. 바움에 문의·수령해야 할 것

- [ ] **Client ID / Secret Key**
- [ ] **암호화 KEY (Base64 32바이트) / IV (Base64 16바이트)**
- [ ] **포켓코드** (기본·추가)
- [ ] **처리결과 통보 발신 IP** — ⚠️ **매뉴얼에 없다.** 없으면 통보를 위조당할 수 있다.
      암호화 키를 아는 쪽만 유효한 본문을 만들 수 있어 사실상 인증 역할을 하지만,
      IP 제한이 있으면 훨씬 안전하다. 서명 검증 수단도 문서에 보이지 않는다.
- [ ] `expires_in` 단위 (§2)
- [ ] 예금주 조회 경로 (§4)

## 10. 진행 상태

- [x] 암복호화 (`BaumCrypto`) — openssl CLI 교차 검증 완료
- [x] 설정 저장 (`FirmConfig`, 비밀값 암호화) · 마이그레이션 · 관리자 화면
- [x] 게이트웨이 (`BaumFirmGateway`) — 토큰 · 예금주 조회 · 잔액 · 접수 · 조회 · 취소
- [x] API 호출 로그 (`FirmApiLog`, 계좌번호 뒤 4자리만)
- [x] 팩토리 분기 — 자격증명이 없으면 자동으로 모의
- [x] **처리결과 통보 수신** (`/firm/noti.php` · `FirmWebhook`) — 응답도 암호화해 반환. 실 HTTP 로 왕복 확인
- [x] **「접수중」 상태** — `withdrawal_requests.status` 에 `transferring` 추가, 지갑 차감을 통보 수신 뒤로 이동
- [x] **이체 장부** (`FirmTransfer`) — 접수와 결과 확정을 잇는다. `transaction_id` UNIQUE 로 멱등
- [x] 미확정 건 보정 조회 (`FirmReconciler`) — 설정 화면 「보정 조회」 버튼
- [x] **예금주 조회를 계좌 등록 화면에 연결** — 라이더 상세·라이더 등록·조직 계좌·일정산 등록 모달 5곳
- [x] **배치 접수(100건)** 를 출금 대행에 적용 — 건별 왕복을 100분의 1로

## 11. 비동기 처리 구현 (2026-08-26 완료)

```
출금 확정 → transfer-submission (접수)
              ↓  withdrawal_requests.status = 'transferring'   ← 지갑은 아직 그대로
              ↓  firm_transfers 에 접수 기록
        ┌─────┴─────┐
   웹훅 수신      보정 조회        ← 둘 중 먼저 오는 쪽이 확정
        └─────┬─────┘
              ↓  FirmTransfer::updateStatus()  ← 확정된 건은 다시 안 바꾼다(멱등)
              ↓  true 일 때만
   SUCCESS → Withdrawal::finalizeSuccess()  → completed + 지갑 차감 + 수수료 배분
   FAILED  → Withdrawal::markTransferFailed() → failed (지갑 손 안 댐)
```

**멱등이 핵심이다.** 웹훅은 최대 10회 재전송되고 보정 조회까지 겹칠 수 있다.
`updateStatus()` 가 `finalized_at IS NULL` 조건으로 한 번만 true 를 돌려주고,
지갑 차감은 그 true 에만 따라붙는다. `finalizeSuccess()` 자체도 `status IN (…)` 으로
한 번 더 막는다(이중 안전장치).

**일부러 뺀 것** — 반려(`markRejected`)는 `transferring` 을 대상에서 제외한다.
이미 이체가 진행 중인데 반려하면 돈은 나가는데 사이클 점유만 풀린다.

**검증 완료** (실 HTTP + 웹훅 재현):

| 시나리오 | 결과 |
|---|---|
| 허용 안 된 IP | 403 거절 |
| 평문 본문 | 400 거절 |
| 다른 키로 암호화(위조) | 400 거절 |
| 금액 불일치 | 확정 안 함, 기록만 |
| 정상 SUCCESS | 출금 완료 + 지갑 10,500원 차감 |
| 같은 통보 재전송 | 지갑 변화 없음 (멱등) |
| 장부에 없는 거래 | 200 + 기록 (재전송 멈춤) |
| 입금 통보(`+`) | 기록만 |
| GET 요청 | 405 |
| 설정 미완료 | 503 (평문 — 암호화할 키가 없으므로) |
| 정상 응답 | 200 + **암호화된** `{"success":true}` |

## 12. 계좌 확인·배치 접수 (2026-08-26 완료)

### 예금주 조회 연결

계좌번호는 **한 자리만 틀려도 모르는 사람에게 돈이 간다.** 계좌이체는 받은 사람이
동의하지 않으면 되찾기가 매우 어렵다. 등록 시점에 한 번 확인하는 것이 가장 싸다.

| 화면 | 버튼 |
|---|---|
| 라이더 상세 → 정보 수정 | `ed_verify` |
| 라이더 목록 → 신규 등록 | `reg_verify` |
| 결제 설정(카드·계좌) | `ps_verify` |
| 정산 업로드 → 일정산 등록 모달 (2곳) | `qrVerify` |

- 공용 로직: `inc/AccountVerifier.php` · `admin/api/account_verify.php` · `assets/js/account-verify.js`
- 조회된 예금주로 입력칸을 **채워 준다** — 옮겨 적다 틀리는 걸 막는다
- 라이더는 확인 결과를 `riders.bank_verified_at` / `bank_verified_name` 에 남겨
  상세 화면에 「확인됨」 배지를 띄운다. **계좌가 바뀌면 지운다**(옛 확인이 새 계좌를 보증하지 않는다)
- **실 연동이 꺼져 있으면 "확인 불가"** 를 돌려주고 저장은 막지 않는다 — 연동 전에도 등록은 돼야 한다
- 저장 직전 `AccountVerify.confirmUnverified()` 로 한 번 되묻는다(계좌를 **바꿨을 때만**)
- 남용 방지: 세션당 분당 30회

### 배치 접수

`Withdrawal::executeTransfers()` 를 두 단계로 나눴다.

1. **사전 검증** — 대리점 잔액 등 이체 전에 막을 수 있는 것을 모두 거른다
   (이체부터 하면 돈은 나갔는데 지갑이 음수가 되어 되돌릴 수 없다)
2. **접수** — 바움이면 `submitBatched()`(100건씩), 모의면 `submitOneByOne()`

⚠️ **요청이 터졌다고 실패로 단정하지 않는다.** 타임아웃은 바움에 도달했는지 알 수 없고,
실패로 찍고 재시도하면 **같은 돈이 두 번 나갈 수 있다.** 그래서 「접수중」으로 남기고
보정 조회가 실제 상태를 확인하게 한다. 도달하지 않았다면 미확정으로 남아 사람 눈에 띈다 —
이중 이체보다 낫다. 응답에 해당 건이 아예 없을 때도 같게 처리한다.

**호출부 3곳도 함께 고쳤다** — `completed` 만 보고 있어서 실 연동에서는 **접수 성공을
"이체 실패"로 표시**했을 것이다: `admin/api/withdrawals.php` ·
`admin/api/withdrawal_proxy.php` · `inc/DailyAutoWithdrawal.php`.

# 설치·운영 매뉴얼

## 1. 운영 구조

이 프로젝트는 PHP·Apache·MySQL 기반이며, 운영 배포는 GitHub Actions에서 서버로 `rsync`하는 방식입니다.

- 애플리케이션 파일: GitHub Actions 자동 배포
- 환경설정 `.env`: 서버에 별도 유지
- 업로드·로그: 서버에 별도 유지
- PHP 의존성 `vendor`: 서버에서 설치
- DB 마이그레이션: 서버에서 수동 실행
- PG·오픈뱅킹: 현재 Mock 구현

## 2. 설치 요구사항

- Apache와 `mod_rewrite`
- PHP와 `pdo_mysql`, `zip` 확장
- MySQL 또는 MariaDB
- Composer
- Python 3
- `msoffcrypto-tool`
- SSH와 rsync

정산 엑셀 처리는 PhpSpreadsheet와 Python 복호화 도구에 의존합니다.

## 3. 최초 설치

### 3.1 DB 준비

1. MySQL/MariaDB에 애플리케이션 DB를 생성합니다.
2. 필요한 권한을 가진 전용 DB 계정을 생성합니다.
3. 외부 DB라면 방화벽과 보안 그룹에서 애플리케이션 서버의 접근을 허용합니다.

### 3.2 환경설정

프로젝트 루트에서 `.env.example`을 복사해 `.env`를 만들고 다음 값을 환경에 맞게 입력합니다.

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `DB_CHARSET`
- 필요 시 정산 엑셀 암호와 Python 경로

`.env`는 Git에 커밋하지 않습니다. 운영 값을 문서나 이슈에 복사하지 않습니다.

### 3.3 의존성

```bash
composer install --no-dev --optimize-autoloader
python3 -m pip install msoffcrypto-tool
```

PHP zip 확장이 활성화되어 있는지도 확인합니다.

### 3.4 디렉터리

웹서버 계정이 다음 경로에 쓸 수 있어야 합니다.

- `uploads/`
- `uploads/banners/`
- `storage/`

디렉터리 전체에 과도한 공개 권한을 주지 말고 웹서버 사용자에게 필요한 최소 쓰기 권한만 부여합니다.

### 3.5 DB 초기화

```bash
php migrate.php
```

1. `migrate.php`로 스키마·본사 조직·최고관리자·시스템 코드·차감 기본값을 생성·갱신합니다.
2. 최고관리자 `admin`으로 로그인합니다. 비밀번호는 `ADMIN_INIT_PASSWORD` 환경변수 값이며, 없으면 `Admin1234!`입니다 — **즉시 변경합니다.**
3. 총판·대리점을 시스템 관리 → 조직 관리에서 직접 만듭니다(샘플 조직은 생성되지 않습니다).
4. 정산 엑셀 암호를 시스템 관리에서 설정합니다.

마이그레이션은 멱등 실행을 목표로 하지만 전체 작업이 하나의 트랜잭션으로 묶이지 않습니다. 실패하면 오류 지점과 실제 DB 상태를 확인한 후 다시 실행합니다.

## 4. Apache 설정

### 필수 설정

- 프로젝트 루트를 `DocumentRoot`로 지정
- `<Directory>`에 `AllowOverride All`
- `Require all granted`
- `mod_rewrite` 활성화
- `DirectoryIndex index.html index.php`

여러 로컬 사이트를 사용할 때 첫 번째 VirtualHost가 기본 사이트입니다. `localhost`를 이 프로젝트로 연결하려면 baedal VirtualHost를 첫 번째로 두거나 `ServerAlias localhost`를 설정합니다.

설정 변경 후 관리자 권한으로 Apache 서비스를 재시작합니다.

## 5. 배포

### 5.1 GitHub Actions

배포 워크플로는 `main` 또는 `master` push와 수동 실행으로 시작됩니다. 필요한 GitHub Secrets는 다음과 같습니다.

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PATH`
- `DEPLOY_SSH_KEY`
- 선택: `DEPLOY_PORT`
- 선택: `DEPLOY_POST_CMD`

배포는 `rsync -az --delete`를 사용합니다. Git에서 삭제된 일반 파일은 서버에서도 삭제됩니다.

### 5.2 자동 배포에서 제외되는 항목

- `.env`와 환경별 설정
- `uploads/`
- `storage/`
- `vendor/`
- `node_modules/`
- `inc/*.flag`
- `migrate.php`

제외된 파일은 서버에서 별도로 관리해야 합니다. 특히 `migrate.php`가 변경된 경우 운영 서버에 최신 파일을 안전하게 전달하는 절차가 필요합니다.

### 5.3 배포 후 점검

1. GitHub Actions 성공 여부 확인
2. 관리자·라이더 사이트 HTTP 응답 확인
3. DB 스키마 변경이 있으면 서버에서 `php migrate.php`
4. `composer.lock` 변경이 있으면 서버에서 `composer install`
5. 관리자 로그인과 대시보드 확인
6. 정산 업로드 미리보기 확인
7. 라이더 로그인과 정산 조회 확인
8. PHP·Apache 오류 로그 확인

## 6. DB 운영

### 주요 초기화 파일

- `migrate.php`: 전체 마이그레이션 실행
- `inc/MigrateRunner.php`: 조건부 ALTER와 백필
- `sql/*.sql`: 테이블별 초기 스키마
- `scripts/reset_operational_data.php`: 최고 관리자와 본사·전역 설정만 남기고 운영 데이터 삭제

### 운영 데이터 초기화 (최고 관리자만 유지)

테스트 데이터를 지우고 최고 관리자만 남길 때 사용합니다. **되돌릴 수 없으므로 실행 전 DB 백업을 확인합니다.**

남기는 항목:

- `admins` 중 `role=super` 계정
- 해당 계정의 본사 조직(`organizations`)
- `system_codes`, `role_permissions`
- 전역 설정(`deduction_global_config`, `withdrawal_config`, `settlement_excel_config`의 `org_id IS NULL` 또는 본사 행)
- 본사 `org_fee_config`

삭제하는 항목:

- 총판·대리점 조직과 그 관리자 계정
- 라이더·지갑·정산 업로드·반영·출금·미수금·프로모션·콘텐츠·감사 로그
- 대리점 지갑·카드·계좌

```bash
php scripts/reset_operational_data.php           # 미리보기
php scripts/reset_operational_data.php --execute # 실제 삭제
```

실행 후 최고 관리자로 다시 로그인해 총판·대리점·라이더를 새로 구성합니다.

### 스키마 확인

`DB_SCHEMA.md`는 실제 DB의 `SHOW CREATE TABLE` 결과를 기준으로 관리합니다. 구조 변경 후 마이그레이션을 실행하고 문서와 비교합니다.

### 중요한 데이터 관계

- 조직: `organizations`
- 관리자: `admins`
- 라이더: `riders`, `rider_platforms`
- 정산 원본: `settlement_uploads`와 하위 상세 테이블
- 정산 반영: `settlement_rider_cycles`, `settlement_fee_items`
- 라이더 지갑: `rider_wallets`
- 출금: `withdrawal_requests`, `withdrawal_request_cycles`
- 대리점 지갑·PG: `agency_wallets`, `agency_wallet_ledger`, `pg_payments`
- 감사: `audit_logs`

운영 데이터 삭제 시 외래 관계와 애플리케이션 보정 로직을 먼저 확인합니다. 정산 업로드만 삭제해도 이미 반영된 지갑·수수료·출금 기록이 자동으로 모두 되돌아가는 것은 아닙니다.

## 7. 백업

저장소에는 자동 DB 백업 작업이 포함되어 있지 않습니다. 운영 환경에서 별도 구성해야 합니다.

### 권장 백업

- RDS를 사용하면 자동 백업과 수동 스냅샷 활성화
- 자체 MySQL이면 정기 `mysqldump`
- `uploads/` 별도 파일 백업
- `.env`와 웹서버 설정의 암호화된 보관
- 배포 전 DB 스냅샷

### 백업 검증

1. 백업 생성 시각과 크기 확인
2. 별도 복구 환경에서 정기 복원 테스트
3. 주요 테이블 행 수와 최신 정산일 확인
4. 백업 파일 접근 권한과 보존 기간 확인

백업은 생성 사실보다 실제 복원 가능 여부가 중요합니다.

## 8. 복구

### 전체 DB 유실

1. 새 DB와 계정을 준비합니다.
2. `.env`의 접속 정보를 변경합니다.
3. 백업이 있으면 우선 복원합니다.
4. 백업이 없으면 `php migrate.php` 를 실행하고 `admins`에 최고관리자를 직접 넣습니다.
5. 조직, 라이더, 계좌, 수수료와 엑셀 암호를 복구합니다.
6. 필요한 정산 원본을 순서대로 재업로드·반영합니다.
7. 지갑·출금·미수금 합계를 검증한 후 서비스를 개방합니다.

백업 없이 정산을 재업로드하면 과거 수동 조정과 출금 상태를 완전히 재현하지 못할 수 있습니다.

### 배포 파일 복구

Git의 정상 커밋으로 되돌린 뒤 일반 배포 절차를 다시 실행합니다. `.env`, `uploads`, `storage`는 rsync 제외 대상이므로 별도로 확인합니다.

## 9. 정기 운영 점검

### 매일

- 관리자·라이더 로그인
- DB 연결과 Apache/PHP 오류 로그
- 미처리 출금과 실패 지급
- 정산 업로드 실패·미매칭
- 대리점 지갑 부족

### 배포 시

- Actions 결과
- 스키마·Composer 변경 여부
- 라우트 403/404
- 관리자·라이더 핵심 화면
- 감사 로그 기록

### 매주

- DB 백업·스냅샷 성공
- 디스크 사용량
- `uploads`와 로그 증가량
- 실패 PG·오픈뱅킹 Mock 기록
- 장기 미처리 출금

### 매월

- 복구 테스트
- 관리자 계정과 권한 검토
- 퇴사자·계약 종료 계정 정리
- 수수료·보험·미수금 설정 검증
- 문서와 실제 화면 차이 점검

## 10. 장애 대응

### 사이트 403

- VirtualHost 기본 사이트 확인
- `DocumentRoot`와 `<Directory>` 경로 확인
- `Require all granted`, `AllowOverride All` 확인
- Apache 재시작 권한 확인
- `httpd -S`, `httpd -t`로 설정 검증

### 사이트 500

- Apache/PHP 오류 로그 확인
- `.env`와 DB 연결 확인
- 누락 테이블이면 `php migrate.php`
- `vendor/autoload.php` 누락이면 Composer 설치
- uploads/storage 권한 확인

### DB 연결 오류

- `.env` 경로와 DB 변수
- DB 서버 상태와 포트
- 보안 그룹·방화벽
- DB 계정 권한
- 운영 환경에서 상세 오류가 숨겨지는 것이 정상인지 확인

### 정산 엑셀 실패

- 파일 확장자와 손상 여부
- PHP zip 확장
- Composer 의존성
- Python과 `msoffcrypto-tool`
- 엑셀 암호 우선순위
- Apache 계정이 Python 실행 파일에 접근 가능한지 확인

### GitHub Actions SSH 시간 초과

- 서버 22번 포트와 보안 그룹 확인
- GitHub Actions에서 서버로 접근 가능한지 확인
- 호스트·포트 Secret 확인
- 서버의 SSH 서비스 상태 확인

### 공개키 인증 실패

- 배포 사용자 확인
- 개인키 전체가 Secret에 들어갔는지 확인
- 줄바꿈이 보존되었는지 확인
- 서버 `authorized_keys`와 권한 확인

## 11. 보안 주의

- `.env`, 개인키, DB 덤프를 Git에 추가하지 않습니다.
- 마이그레이션 도구의 웹 접근을 차단합니다.
- 운영에서 코드 내 DB 기본값에 의존하지 않습니다.
- 계정은 개인별로 발급하고 공유 계정 사용을 줄입니다.
- 수동 조정, 출금 완료, 권한 변경 후 감사 로그를 확인합니다.
- Mock 금융 연동을 실제 이체·결제로 오인하지 않습니다.
- 로그와 화면 캡처에 계좌번호·전화번호·비밀번호를 노출하지 않습니다.

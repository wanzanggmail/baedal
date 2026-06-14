# 도깨비 배달 — 관리자·라이더 백오피스

Metronic 8.3.3 + PHP + MariaDB/MySQL.  
**기능·권한·DB 규칙의 기준 문서는 [LOGIC.md](LOGIC.md)** 입니다. 화면/API를 바꿀 때 해당 섹션도 함께 갱신하세요.

---

## 문서 맵

| 문서 | 내용 |
|------|------|
| **[LOGIC.md](LOGIC.md)** | 메뉴별 구현 상태, RBAC, 정산·출금·지갑 규칙, API·테이블, migrate/seed |
| **[DEPLOY.md](DEPLOY.md)** | GitHub Actions → rsync 배포, `.env`, 서버 최초 설정, DB 복구 |
| **`.env.example`** | DB·정산 엑셀 암호 환경 변수 예시 (실제 값은 `.env`, Git 제외) |

아래 Phase 체크리스트는 **초기 기획용**이며, 실제 진행 상황은 **LOGIC.md §3** 표가 더 정확합니다.

---

## 로컬·서버 공통 — DB 준비

```bash
# 프로젝트 루트
cp .env.example .env          # 필요 시 DB 접속 정보 입력 (없으면 inc/db.php 기본값)
php migrate.php               # sql/base_schema.sql → 확장 테이블 (멱등)
php seed.php                  # 관리자·system_codes·차감 기본값 (최초 1회)
```

- 개발 로그인: `admin` / `Admin1234!` (seed 후 비밀번호 변경 권장)
- DB 연결: `inc/env.php`가 루트 `.env` 로드 → `inc/db.php`
- 정산 xlsx: `php-zip` + `msoffcrypto-tool` (DEPLOY.md 참고)

---

## GitHub → 실서버 배포

`main` / `master` push 시 GitHub Actions가 rsync로 동기화.  
SSH 시크릿·경로·`.env` 유지: **[DEPLOY.md](DEPLOY.md)**

배포 시 **제외**: `.env`, `uploads/`, `migrate.php`, `seed.php` (서버에만 둠)

---

## 디렉터리 요약

| 경로 | 역할 |
|------|------|
| `admin/` | 관리자 UI·API |
| `rider/` | 라이더 PWA |
| `inc/` | bootstrap, auth, 도메인 클래스, `MigrateRunner.php` |
| `sql/` | `base_schema.sql` + 기능별 DDL |
| `scripts/` | rsync, `decrypt_xlsx.py` |
| `migrate.php` / `seed.php` | CLI 스키마·초기 데이터 |
| `index.html` | 공개 랜딩 (Metronic 데모 HTML 폴더는 **제거됨**) |
| `assets/` | Metronic **빌드 결과물** + `custom/landing.js`, `typedjs` |

> Metronic `authentication/`, `apps/`, `src/` 등 데모·소스 트리는 용량만 차지해 삭제했습니다. 상세: **LOGIC.md §2 저장소 구조**.

---

## Metronic

- 버전 **8.3.3** — `assets/css/style.bundle.css`, `assets/plugins/global/plugins.bundle.js`
- 레이아웃: `inc/header.php`, `sidebar.php`, `shell_*.php`

---

## Phase 로드맵 (초기 기획 — 상세는 LOGIC.md)

**환경·로그인·레이아웃 → 라이더·설정 → 엑셀·집계 → 차감 → 프로모션 → 출금·공지·배너 → 통계**

| Phase | 주요 항목 | LOGIC 참고 |
|-------|-----------|------------|
| 1 | 로그인, 레이아웃, migrate | §4, §7 |
| 2 | 라이더, 선공제 설정 | §5.3, §5.6 |
| 3 | 정산 업로드·이력·반영·선공제 | §5.4, §5.6 |
| 4~6 | 출금, 공지, 배너, 라이더 | §5.5~§5.7 |
| (예정) | 프로모션 배치, 통계·내보내기, 수동 차감 UI | §8 #10 — 구현 시 §9 |

# GitHub → 실서버 자동 배포

`main` 또는 `master` 브랜치에 **push** 하면 GitHub Actions가 서버에 **rsync**로 파일을 맞춥니다.

---

## AWS Lightsail (ec2-user + PEM) — 지금 환경

이미 Lightsail에 파일이 올라가 있다면, **아래 3단계만** 하면 됩니다.

### 1) 서버에 올라간 폴더 경로 확인

로컬 PowerShell (PEM 경로·IP는 본인 값으로 바꿈):

```powershell
ssh -i "C:\경로\LightsailDefaultKey.pem" ec2-user@서버공인IP "pwd; ls -la"
# 프로젝트가 있는 폴더로 이동 후
ssh -i "C:\경로\LightsailDefaultKey.pem" ec2-user@서버공인IP "cd /home/ec2-user/프로젝트폴더 && pwd && ls admin rider inc 2>/dev/null"
```

`admin`, `rider`, `inc` 가 보이는 디렉터리 전체 경로가 **`DEPLOY_PATH`** 입니다.  
Apache DocumentRoot가 **`/var/www/html`** 이면 `DEPLOY_PATH`도 **`/var/www/html`** 로 맞춥니다.  
(예: `/var/www/html`, `/home/ec2-user/baedal` — **Apache가 실제로 읽는 경로**와 동일해야 함)

> 사용자 이름은 Lightsail **Amazon Linux** 기준 **`ec2-user`** 입니다. (`ec-user` 가 아님)

### 2) GitHub Secrets 등록

저장소 → **Settings** → **Secrets and variables** → **Actions**

| Secret | Lightsail 예시 |
|--------|----------------|
| `DEPLOY_HOST` | Lightsail **고정 IP** (콘솔에 표시) |
| `DEPLOY_USER` | `ec2-user` |
| `DEPLOY_PATH` | Apache DocumentRoot (예: `/var/www/html`) |
| `DEPLOY_SSH_KEY` | **PEM 파일 내용 전체** (메모장으로 열어 `-----BEGIN…` 부터 `-----END…` 까지 복사) |
| `DEPLOY_PORT` | `22` (보통 생략 가능) |
| `DEPLOY_POST_CMD` | (선택) `chmod -R u+rwX uploads 2>/dev/null; true` |

**PEM을 Secret에 넣는 방법**

1. Lightsail에서 받은 `.pem` 파일을 연다.
2. 첫 줄 `-----BEGIN RSA PRIVATE KEY-----` 또는 `-----BEGIN OPENSSH PRIVATE KEY-----` 부터 마지막 줄까지 **전부** 복사.
3. GitHub `DEPLOY_SSH_KEY` 값에 붙여 넣기.

⚠️ **PEM 파일은 Git에 커밋하지 마세요.** (`.gitignore`에 `*.pem` 포함)

### 3) push 후 확인

```bash
git push origin main
```

GitHub → **Actions** → `Deploy to server` 가 초록색이면, 서버 폴더가 저장소와 동기화된 것입니다.

**로컬에서 배포 테스트 (선택)**

```powershell
# Git Bash 또는 WSL
bash scripts/deploy-rsync.sh -e "ssh -i /c/경로/key.pem" ec2-user@IP:/home/ec2-user/baedal
```

`deploy-rsync.sh`는 `-e` 옵션을 넘기려면 스크립트 수정이 필요 — 아래 PowerShell rsync 대안:

```powershell
# WSL 설치된 경우
wsl rsync -avz -e "ssh -i /mnt/c/경로/key.pem" --exclude '.env' --exclude 'uploads/' `
  /mnt/d/web/baedal/ ec2-user@IP:/home/ec2-user/baedal/
```

---

## 서버에 이미 있는 것 (유지됨)

배포할 때 **덮어쓰지 않음**:

| 항목 | 설명 |
|------|------|
| `.env` | DB 비밀번호 등 — 서버에만 둠 |
| `uploads/` | 배너·업로드 파일 |
| `storage/` | 로그 등 |

Apache/가상호스트·DB(RDS) 설정은 그대로 두고, **코드만** Git과 맞춥니다.

---

## 최초 1회만 (아직 안 했다면)

```bash
# 서버 SSH 접속 후, DEPLOY_PATH 안에서
cp .env.example .env    # 이미 .env 있으면 생략
mkdir -p uploads/banners
chmod -R u+rwX uploads
php migrate_content.php   # 공지·배너 테이블
```

---

## 배포 시 동작 요약

- **복사**: admin, rider, assets, inc, … (Git 추적 파일)
- **삭제 동기화**: Git에서 지운 파일은 서버에서도 삭제 (`uploads` 제외)
- **제외**: `.git`, `.env`, `uploads/`, 마이그레이션·시드 스크립트

---

## 문제 해결

| 증상 | 확인 |
|------|------|
| `Permission denied (publickey)` | `DEPLOY_USER`=`ec2-user`, PEM 전체가 Secret에 들어갔는지 |
| `No such file or directory` | `DEPLOY_PATH`가 admin/rider가 있는 **정확한** 경로인지 |
| 배포 후 500 에러 | 서버 `.env` 존재·DB 접속, `uploads` 권한 |
| Actions만 실패, SSH는 됨 | PEM 줄바꿈 깨짐 → Secret 다시 붙여넣기 |

Lightsail 방화벽: **SSH 22** 포트가 열려 있어야 합니다 (기본 허용).

---

## GitHub Actions 없이 수동 배포

```bash
bash scripts/deploy-rsync.sh ec2-user@고정IP:/home/ec2-user/프로젝트경로
```

(로컬에 SSH 키가 `~/.ssh/config`에 등록돼 있어야 함)

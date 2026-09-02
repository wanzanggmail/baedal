# GitHub → 실서버 자동 배포

두 환경으로 분리되어 있습니다 — **테스트 서버**와 **라이브 서버**를 절대 같은 브랜치/시크릿으로 섞지 않습니다. 배포 "방식"도 서로 다릅니다.

| | 브랜치 | 배포 방식 | 서버 |
|---|---|---|---|
| 테스트 | `main`/`master` | GitHub Actions가 **SSH로 push**(`deploy-staging.yml`), push하면 자동 | 기존 Lightsail |
| 라이브 | `production` | **관리자 패널에서 pull**(`system/deploy` 메뉴), 버튼을 눌러야 실행 | AWS EC2(oxpay.kr) |

## 왜 라이브만 방식이 다른가

AWS EC2의 SSH(22번 포트)는 **본사 고정 IP로만 제한**되어 있습니다(보안). GitHub Actions 러너는 IP가 계속 바뀌는 광범위한 대역이라, 거기서 SSH로 접속하려면 이 제한을 풀어야 해서 보안 원칙이 깨집니다.

그래서 라이브는 방향을 뒤집었습니다 — **서버가 GitHub에서 코드를 당겨오는(pull) 방식**입니다. SSH를 열 필요가 전혀 없고(아웃바운드로 github.com에 나가는 것뿐), **관리자가 패널에서 버튼을 눌러야만 실행**되므로 그 자체가 승인 절차입니다.

## 라이브 서버 최초 설정 (한 번만)

**① Deploy Key 발급·등록** (서버가 private 저장소를 읽기 전용으로 pull할 수 있게)

서버(EC2)에서:
```bash
sudo -u apache ssh-keygen -t ed25519 -C "oxpay-production-deploy" -f /var/www/.ssh/deploy_key -N ""
sudo cat /var/www/.ssh/deploy_key.pub
```
출력된 공개키를 GitHub 저장소 → **Settings → Deploy keys → Add deploy key**에 등록(**"Allow write access"는 체크하지 않음** — 읽기 전용).

apache 계정이 이 키로 git 명령을 쓰게 SSH 설정을 추가:
```bash
sudo -u apache tee /var/www/.ssh/config > /dev/null <<'EOF'
Host github.com
    HostName github.com
    User git
    IdentityFile /var/www/.ssh/deploy_key
    IdentitiesOnly yes
EOF
sudo chmod 600 /var/www/.ssh/config /var/www/.ssh/deploy_key
sudo -u apache ssh -T git@github.com   # "Hi wanzanggmail/baedal! You've successfully authenticated..." 뜨면 성공(exit code 1 정상)
```

**② `/var/www/html`을 git clone으로 전환** (기존 rsync로 올라간 파일은 백업 후 교체)

```bash
sudo systemctl stop httpd
sudo mkdir -p /var/www/html.bak
sudo cp -a /var/www/html/.env /var/www/html.bak/ 2>/dev/null
sudo cp -a /var/www/html/uploads /var/www/html.bak/ 2>/dev/null

sudo mv /var/www/html /var/www/html.old
sudo -u apache git clone --branch production git@github.com:wanzanggmail/baedal.git /var/www/html

sudo cp -a /var/www/html.bak/.env /var/www/html/ 2>/dev/null
sudo cp -a /var/www/html.bak/uploads/. /var/www/html/uploads/ 2>/dev/null
sudo chown -R ec2-user:apache /var/www/html/uploads
cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction

sudo systemctl start httpd
```

**③ PHP `exec()`가 막혀있지 않은지 확인**
```bash
php -i | grep disable_functions
```
`exec`가 목록에 있으면 `php.ini`(`/etc/opt/remi/php8.5/...` 또는 `/etc/php.d/`)에서 빼야 관리자 패널의 배포 버튼이 동작합니다.

## 평소 작업 흐름

```bash
# 1) 평소 개발 — main 에 push → 테스트 서버에 즉시 자동 반영
git push origin main

# 2) 테스트 서버에서 확인 끝나면 → production 브랜치로 승격
git checkout production
git merge main
git push origin production

# 3) 관리자 패널 로그인 → 시스템 관리 → 배포
#    "최신 상태 확인" → 대기 중인 커밋 확인 → "배포 실행" 클릭
#    (여기서 fetch → reset --hard origin/production → composer install 이 실행된다)
```

⚠️ **`main`과 `production`의 DB는 완전히 분리**되어 있습니다(각 서버의 `.env`가 서로 다른 DB를 가리킴) — 테스트 서버에서 만든 데이터가 라이브에 영향을 주지 않고, 그 반대도 마찬가지입니다.

⚠️ **`uploads/`, `.env`, `storage/`는 git 관리 대상이 아닙니다** — `.gitignore`에 포함되어 배포(`reset --hard`)해도 지워지지 않지만, 최초 전환(②) 때는 수동으로 옮겨줘야 합니다.

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

저장소 → **Settings → Environments → `staging`** (위에서 만든 환경) → **Environment secrets**

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
# uploads/ 는 제외하되 .htaccess(PHP 실행 차단)는 보내야 한다.
wsl rsync -avz -e "ssh -i /mnt/c/경로/key.pem" --exclude '.env' --exclude 'uploads/*' --include 'uploads/.htaccess' `
  /mnt/d/web/baedal/ ec2-user@IP:/home/ec2-user/baedal/
```

---

## 서버에 이미 있는 것 (유지됨)

배포할 때 **덮어쓰지 않음**:

| 항목 | 설명 |
|------|------|
| `.env` | DB 비밀번호 + **`APP_ENC_KEY`(암호화 키)** — 서버에만 둠. **키를 잃으면 결제키·계좌번호 복구 불가** → 별도 보관 필수 |
| `uploads/` | 배너·업로드 파일 |
| `storage/` | 로그 등 |

Apache/가상호스트·DB(RDS) 설정은 그대로 두고, **코드만** Git과 맞춥니다.

---

## 최초 1회만 (아직 안 했다면)

```bash
# 서버 SSH 접속 후, DEPLOY_PATH 안에서
cp .env.example .env    # 이미 .env 있으면 생략
# ⚠️ .env 에 DB 접속정보와 함께 APP_ENC_KEY 를 반드시 채운다.
#    PG 결제키·계좌번호는 이 키로 암호화 저장되므로, 없으면 저장이 거부된다.
#    이미 운영 중인 DB가 있다면 **기존 서버와 똑같은 키**를 넣어야 한다(다르면 못 읽는다).
#    새로 만들 때만:  php tools/gen_enc_key.php
mkdir -p uploads/banners
chmod -R u+rwX uploads
php migrate.php   # 스키마 (멱등, base_schema.sql 포함)
php seed.php      # 초기 관리자·코드 (최초 1회)

# 정산 xlsx 업로드·암호 해제 (필수)
sudo dnf install -y php-zip || sudo yum install -y php-zip
sudo systemctl restart httpd
php -m | grep zip   # "zip" 한 줄 출력되면 OK
sudo /usr/bin/python3 -m pip install msoffcrypto-tool
# ⚠️ `sudo -u apache ... --user` 로 하면 실패한다 — apache 시스템 계정은 홈 디렉터리
#    (/usr/share/httpd) 쓰기 권한이 없어 `~/.local` 생성이 막힌다(Amazon Linux 2023 확인됨).
#    --user 없이 전역 설치하면 apache 계정에서도 그대로 import 된다.
#    확인: sudo -u apache /usr/bin/python3 -c "import msoffcrypto; print('ok')"
```

---

## DB 데이터가 비었을 때 (복구)

1. MySQL/RDS에서 **새 DB·계정** 생성 후 서버 `.env` 갱신 (유출된 비밀번호는 즉시 폐기)
2. `DEPLOY_PATH`에서 `php migrate.php` → `php seed.php`
3. 관리자 로그인 (`admin` / `Admin1234!`) → **시스템 관리 → 정산 엑셀 암호** 재등록
4. 라이더·정산 엑셀 **재업로드** (백업 없으면 수동 재입력)
5. `seed.php` 실행 후 **파일 삭제**

**미매칭 정산 행 → 더미 라이더 (로컬·1회성)**

```bash
# 대상 확인만
php scripts/seed_riders_from_settlement.php --upload-id=3 --dry-run

# DB에 더미 라이더 생성 + settlement_daily_riders 연결
php scripts/seed_riders_from_settlement.php --upload-id=3

# SQL 파일로 뽑기 (다른 DB에 수동 실행)
php scripts/seed_riders_from_settlement.php --upload-id=3 --sql-only > seed_riders.sql
```

`upload-id`는 `settlement_uploads.id`. 기본 비밀번호 `Rider1234!` (`--password=` 로 변경).

---

## 배포 시 동작 요약

- **복사**: admin, rider, assets, inc, … (Git 추적 파일)
- **삭제 동기화**: Git에서 지운 파일은 서버에서도 삭제 (`uploads` 제외)
- **제외**: `.git`, `.env`, `uploads/`, `vendor/`, 마이그레이션·시드 스크립트
- **composer install**: `vendor/`는 rsync로 옮기지 않고, rsync 직후 서버에 SSH로 접속해 `composer.lock` 기준으로 직접 설치한다(서버에 `composer` 명령이 없으면 파이프라인이 자동으로 설치기를 내려받아 씀). `composer.json`에 패키지를 추가/변경했으면 **반드시 `composer.lock`도 커밋**해야 서버에 똑같이 반영된다 — `composer.lock`이 안 바뀌면 서버는 예전 의존성 그대로다.

---

## 문제 해결

| 증상 | 확인 |
|------|------|
| `Permission denied (publickey)` | `DEPLOY_USER`=`ec2-user`, PEM 전체가 Secret에 들어갔는지 |
| `No such file or directory` | `DEPLOY_PATH`가 admin/rider가 있는 **정확한** 경로인지 |
| 배포 후 500 에러 | 서버 `.env` 존재·DB 접속, `uploads` 권한 |
| `Class "Parsedown" not found` 등 `Class ... not found` | `vendor/`가 서버에 없거나 옛날 상태. Actions 로그의 **"Install composer dependencies on server"** 단계가 성공했는지 확인. 그 단계가 없던 옛 커밋으로 배포된 서버라면 한 번 수동으로 `cd DEPLOY_PATH && composer install --no-dev`(또는 위 자동설치 스크립트) 실행 |
| Actions만 실패, SSH는 됨 | PEM 줄바꿈 깨짐 → Secret 다시 붙여넣기 |

Lightsail 방화벽: **SSH 22** 포트가 열려 있어야 합니다 (기본 허용).

---

## GitHub Actions 없이 수동 배포

```bash
bash scripts/deploy-rsync.sh ec2-user@고정IP:/home/ec2-user/프로젝트경로
```

(로컬에 SSH 키가 `~/.ssh/config`에 등록돼 있어야 함)

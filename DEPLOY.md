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
sudo mkdir -p /var/www/.ssh
sudo chown apache:apache /var/www/.ssh
sudo chmod 700 /var/www/.ssh

sudo -u apache ssh-keygen -t ed25519 -C "oxpay-production-deploy" -f /var/www/.ssh/deploy_key -N ""

# github.com 호스트키 미리 등록(첫 접속 확인 프롬프트 방지)
ssh-keyscan github.com | sudo tee /var/www/.ssh/known_hosts > /dev/null
sudo chown apache:apache /var/www/.ssh/known_hosts
sudo chmod 600 /var/www/.ssh/known_hosts /var/www/.ssh/deploy_key

sudo cat /var/www/.ssh/deploy_key.pub
```
출력된 공개키를 GitHub 저장소 → **Settings → Deploy keys → Add deploy key**에 등록(**"Allow write access"는 체크하지 않음** — 읽기 전용).

⚠️ apache 계정의 홈 디렉터리는 `/var/www`가 아니라 **`/usr/share/httpd`** 라서 `~/.ssh/config`에 의존하면 안 된다. 대신 **저장소별 `core.sshCommand`** 로 키를 명시한다(아래 ② 참고).

연결 테스트:
```bash
sudo -u apache ssh -i /var/www/.ssh/deploy_key -o IdentitiesOnly=yes \
  -o UserKnownHostsFile=/var/www/.ssh/known_hosts -T git@github.com
# "Hi wanzanggmail/baedal! You've successfully authenticated..." 뜨면 성공(exit code 1 은 정상)
```

**② `/var/www/html`을 git clone으로 전환** (기존 rsync로 올라간 파일은 백업 후 교체)

```bash
SSHCMD='ssh -i /var/www/.ssh/deploy_key -o IdentitiesOnly=yes -o UserKnownHostsFile=/var/www/.ssh/known_hosts'

sudo systemctl stop httpd

# 기존 파일 백업(.env·uploads 는 git 관리 대상이 아니라 반드시 따로 챙긴다)
sudo mkdir -p /var/www/html.bak
sudo cp -a /var/www/html/.env /var/www/html.bak/ 2>/dev/null
sudo cp -a /var/www/html/uploads /var/www/html.bak/ 2>/dev/null

sudo mv /var/www/html /var/www/html.old

# /var/www 는 root 소유라 apache 가 html 을 못 만든다 → 빈 디렉터리를 먼저 만들어 넘겨준다
sudo mkdir -p /var/www/html
sudo chown apache:apache /var/www/html

sudo -u apache env GIT_SSH_COMMAND="$SSHCMD" \
  git clone --branch production git@github.com:wanzanggmail/baedal.git /var/www/html

# 이후 배포(git fetch)에서도 같은 키를 쓰도록 저장소에 고정
sudo -u apache git -C /var/www/html config core.sshCommand "$SSHCMD"

# 백업 복원
sudo cp -a /var/www/html.bak/.env /var/www/html/ 2>/dev/null
sudo mkdir -p /var/www/html/uploads
sudo cp -a /var/www/html.bak/uploads/. /var/www/html/uploads/ 2>/dev/null
sudo chown -R apache:apache /var/www/html/uploads
sudo chmod -R u+rwX /var/www/html/uploads

cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction

sudo systemctl start httpd
```

> 저장소를 **apache 소유**로 clone 하는 이유: 배포 버튼이 PHP(apache 권한)에서 `git fetch`·`reset --hard`를 실행하기 때문이다. 소유자가 다르면 git이 `detected dubious ownership` 으로 거부한다.
> 확인이 끝나면 `/var/www/html.old`, `/var/www/html.bak` 은 삭제해도 된다.

**③ PHP `exec()`가 막혀있지 않은지 확인**
```bash
php -i | grep disable_functions
```
`exec`가 목록에 있으면 `php.ini`(`/etc/php.d/`)에서 빼야 관리자 패널의 배포 버튼이 동작합니다.

## AWS EC2에 서버를 새로 세울 때 — 실제로 걸렸던 함정들

2026-09-02 oxpay.kr 구축에서 겪은 것들. Amazon Linux 2023 기준.

| 증상 | 원인 · 해결 |
|---|---|
| `remi-release-9.rpm` 설치 실패<br>(`nothing provides redhat-release >= 9.8`) | **AL2023은 RHEL9 호환이 아니다.** remi 저장소를 쓰지 말 것 — AL2023 기본 저장소에 `php8.5`가 이미 있다: `sudo dnf install php8.5 php8.5-cli php8.5-modphp php8.5-mysqlnd php8.5-mbstring php8.5-xml php8.5-gd php8.5-zip php8.5-intl` |
| `dnf module list php` → `No matching Modules` | AL2023은 **모듈 스트림 방식을 폐지**했다. 버전별 개별 패키지명(`php8.5-*`)을 쓴다 |
| `php8.5-opcache` 패키지 없음 | 8.5는 OPcache가 **core에 내장**. 별도 설치 불필요(`php -m \| grep -i opcache`로 확인) |
| pip `--user` 설치 실패<br>(`Permission denied: '/usr/share/httpd/.local'`) | apache 계정 홈(`/usr/share/httpd`)은 쓰기 불가. **`--user` 없이 전역 설치**: `sudo /usr/bin/python3 -m pip install msoffcrypto-tool` |
| certbot `ModuleNotFoundError: cryptography` | dnf 버전 certbot의 의존성이 깨져 있다. **독립 venv로 설치**: `sudo python3 -m venv /opt/certbot && sudo /opt/certbot/bin/pip install certbot certbot-apache` + `/usr/local/bin/certbot` 심볼릭 링크. 빌드 도구 필요: `sudo dnf install -y gcc make pkgconfig augeas-devel libxml2-devel python3-devel` |
| certbot이 `www.` vhost를 못 찾고 충돌 | vhost에 `ServerName`/`ServerAlias`가 없어서. 인증서는 이미 두 도메인 다 포함돼 발급되므로, **기존 SSL vhost에 `ServerAlias www.도메인` 한 줄만 추가**하면 된다 |
| HTTPS로 접속하면 `/admin/login` → **404** | certbot이 만든 SSL vhost에 `<Directory>` 블록이 안 딸려와 `.htaccess`가 무시된다(AL2023 기본 `AllowOverride None`). **가상호스트 밖 전역 설정**으로 두면 HTTP/HTTPS 양쪽 적용 + certbot 갱신에도 안전:<br>`/etc/httpd/conf.d/zz-oxpay-dir.conf` 에 `<Directory /var/www/html> AllowOverride All / Require all granted </Directory>` |
| RDS 접속 시 `ERROR 3159 ... require_secure_transport=ON` | RDS가 TLS를 강제한다. CA 번들 받아서 `.env`에 `DB_SSL_CA` 지정:<br>`sudo curl -o /etc/pki/rds/global-bundle.pem https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem` |
| `mysql --ssl-mode=...` → `unknown variable` | 설치된 게 **MariaDB 클라이언트**라 MySQL8 문법이 없다. `--ssl-ca=경로` 또는 `--ssl-verify-server-cert` 사용 |
| `ERROR 1045 Access denied` | RDS 마스터 계정은 **원격 접속이 기본 허용**이다(호스트 차단이면 1130이 뜬다). 1045는 순수 비밀번호 불일치 — 콘솔에서 재설정 |
| composer `vendor does not exist and could not be created` | 저장소가 apache 소유인데 ec2-user로 실행해서. **apache로 실행 + COMPOSER_HOME 지정**:<br>`sudo -u apache env COMPOSER_HOME=/tmp/composer composer install --no-dev` |
| `git clone` → `Permission denied` | `/var/www`가 root 소유. **빈 디렉터리를 먼저 만들어 apache에 넘긴 뒤** clone |
| 배포 화면이 전부 `fatal: detected dubious ownership in repository at '/var/www/html'` | 저장소가 apache 가 아닌 계정(root·ec2-user)으로 clone 됨. **읽기 명령은 코드에서 `git -c safe.directory=` 로 우회하지만(2026-09-05), 실제 배포(fetch·reset)는 쓰기 권한이 필요**하다:<br>`sudo chown -R apache:apache /var/www/html`<br>확인: `stat -c '%U:%G' /var/www/html` → `apache:apache` |

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

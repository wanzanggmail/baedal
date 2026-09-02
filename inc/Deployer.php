<?php

declare(strict_types=1);

/**
 * 관리자 패널에서 실행하는 코드 배포(2026-09-02) — production 서버 전용.
 *
 * GitHub Actions가 SSH로 서버에 접속하는 push 방식은 SSH를 본사 고정 IP로만 제한한
 * 보안그룹 때문에 production 에서는 쓸 수 없다(GitHub 러너 IP가 광범위·유동적이라 허용하려면
 * SSH를 열어야 함). 대신 **서버가 GitHub 에서 코드를 당겨오는(pull)** 방식으로 뒤집는다 —
 * 관리자가 패널에서 버튼을 눌러야만 실행되므로 그 자체로 승인 절차를 겸한다.
 *
 * 전제: `ROOT_PATH`가 Deploy Key(읽기전용)로 `git clone`된 저장소여야 한다. rsync로 배포되는
 * 서버(.git 없음 — `.git/`은 rsync exclude 대상)에서는 `ready()`가 false 라 이 기능이 자동으로
 * 숨겨진다(같은 코드가 staging에도 배포되지만 자연히 비활성).
 */
final class Deployer
{
    private const BRANCH = 'production';

    public static function ready(): bool
    {
        return is_dir(ROOT_PATH . '/.git');
    }

    /** 현재 배포된 커밋 정보. @return array{hash:string,short:string,subject:string,author:string,date:string} */
    public static function currentCommit(): array
    {
        return [
            'hash'    => self::run('git log -1 --format=%H'),
            'short'   => self::run('git log -1 --format=%h'),
            'subject' => self::run('git log -1 --format=%s'),
            'author'  => self::run('git log -1 --format=%an'),
            'date'    => self::run('git log -1 --format=%ci'),
        ];
    }

    /**
     * origin/production 최신 상태를 가져와 아직 반영 안 된 커밋 목록만 보여준다(실제 배포는 안 함).
     *
     * @return array{ok:bool, ahead:int, commits:list<array{hash:string,subject:string,author:string}>, output:string}
     */
    public static function check(): array
    {
        [$ok, $out] = self::exec('git fetch origin ' . escapeshellarg(self::BRANCH));
        if (!$ok) {
            return ['ok' => false, 'ahead' => 0, 'commits' => [], 'output' => $out];
        }

        $log = self::run('git log HEAD..origin/' . escapeshellarg(self::BRANCH) . ' --format=%h^^^%s^^^%an');
        $commits = [];
        foreach (array_filter(explode("\n", $log), static fn (string $l): bool => trim($l) !== '') as $line) {
            [$h, $s, $a] = array_pad(explode('^^^', $line, 3), 3, '');
            $commits[] = ['hash' => $h, 'subject' => $s, 'author' => $a];
        }

        return ['ok' => true, 'ahead' => count($commits), 'commits' => $commits, 'output' => $out];
    }

    /**
     * 실제 배포 — fetch → reset --hard origin/production → composer install. 고정 명령만 실행한다
     * (사용자 입력이 셸 명령에 섞이지 않음 — 관리자가 브랜치나 커밋을 고를 수 없다).
     *
     * @return array{ok:bool, output:string}
     */
    public static function deploy(): array
    {
        $out = [];

        [$ok, $o] = self::exec('git fetch origin ' . escapeshellarg(self::BRANCH));
        $out[] = $o;
        if (!$ok) {
            return ['ok' => false, 'output' => implode("\n\n", $out)];
        }

        [$ok, $o] = self::exec('git reset --hard origin/' . escapeshellarg(self::BRANCH));
        $out[] = $o;
        if (!$ok) {
            return ['ok' => false, 'output' => implode("\n\n", $out)];
        }

        [$ok, $o] = self::exec(self::composerCmd() . ' install --no-dev --optimize-autoloader --no-interaction');
        $out[] = $o;

        return ['ok' => $ok, 'output' => implode("\n\n", $out)];
    }

    /**
     * composer 실행 명령. 웹서버(apache) 권한으로 도는 걸 전제로 두 가지를 보정한다.
     *  - `COMPOSER_HOME`: apache 홈(/usr/share/httpd)은 쓰기 불가라 캐시 디렉터리를 못 만들어 실패한다.
     *  - 절대 경로: PHP exec 의 PATH 가 로그인 셸과 달라 `composer` 를 못 찾는 경우가 있다.
     */
    private static function composerCmd(): string
    {
        $bin = 'composer';
        foreach (['/usr/local/bin/composer', '/usr/bin/composer'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $bin = $candidate;
                break;
            }
        }

        $home = sys_get_temp_dir() . '/composer-deploy';

        return 'COMPOSER_HOME=' . escapeshellarg($home) . ' ' . escapeshellarg($bin);
    }

    /** 고정 디렉터리(ROOT_PATH)에서 주어진 명령만 실행. @return array{0:bool,1:string} */
    private static function exec(string $cmd): array
    {
        $full = 'cd ' . escapeshellarg(ROOT_PATH) . ' 2>&1 && ' . $cmd . ' 2>&1';
        exec($full, $lines, $code);

        return [$code === 0, implode("\n", $lines)];
    }

    private static function run(string $cmd): string
    {
        [, $out] = self::exec($cmd);

        return trim($out);
    }
}

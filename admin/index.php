<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$route = admin_requested_route();

if ($route === 'login') {
    if (admin_is_logged_in()) {
        header('Location: ' . admin_url('dashboard'), true, 302);
        exit;
    }

    $loginError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF 검증
        $csrfOk = isset($_POST['_token'], $_SESSION['login_csrf_token'])
                  && hash_equals((string) $_SESSION['login_csrf_token'], (string) $_POST['_token']);
        if (!$csrfOk) {
            $loginError = '잘못된 요청입니다. 다시 시도해 주세요.';
        } else {
            // 브루트포스 방지: 10분 안에 5회 실패 시 잠금
            $now   = time();
            $fails = (int) ($_SESSION['login_fail_count'] ?? 0);
            $lastFail = (int) ($_SESSION['login_fail_at'] ?? 0);
            if ($fails >= 5 && ($now - $lastFail) < 600) {
                $wait = 600 - ($now - $lastFail);
                $loginError = "로그인 시도 횟수를 초과했습니다. {$wait}초 후 다시 시도해 주세요.";
            } else {
                if (($now - $lastFail) >= 600) {
                    // 10분 경과 시 카운터 리셋
                    $_SESSION['login_fail_count'] = 0;
                }

                $loginId  = trim((string) ($_POST['user_id'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');

                if ($loginId === '' || $password === '') {
                    $loginError = '아이디와 비밀번호를 입력하세요.';
                } else {
                    $admin = db_row(
                        'SELECT id, login_id, password_hash, name, role, is_active
                         FROM admins WHERE login_id = ? LIMIT 1',
                        [$loginId]
                    );

                    if ($admin && (int) $admin['is_active'] === 1
                        && password_verify($password, (string) $admin['password_hash'])
                    ) {
                        // 로그인 성공 — 세션 재생성으로 세션 고정 공격 방지
                        session_regenerate_id(true);
                        $_SESSION['admin_auth']     = true;
                        $_SESSION['admin_id']       = (int) $admin['id'];
                        $_SESSION['admin_login_id'] = $admin['login_id'];
                        $_SESSION['admin_name']     = $admin['name'];
                        $_SESSION['admin_role']     = $admin['role'];
                        unset($_SESSION['login_fail_count'], $_SESSION['login_fail_at'], $_SESSION['login_csrf_token']);

                        // last_login_at 갱신 (실패해도 무시)
                        try {
                            db_execute(
                                'UPDATE admins SET last_login_at = NOW() WHERE id = ?',
                                [(int) $admin['id']]
                            );
                        } catch (Throwable) {}

                        require_once INC_PATH . '/AuditLog.php';
                        AuditLog::record(
                            'auth.login',
                            '세션',
                            '관리자 로그인 성공',
                            (int) $admin['id'],
                            (string) $admin['login_id']
                        );

                        header('Location: ' . admin_url('dashboard'), true, 302);
                        exit;
                    }

                    // 로그인 실패
                    $_SESSION['login_fail_count'] = ($fails + 1);
                    $_SESSION['login_fail_at']    = $now;
                    require_once INC_PATH . '/AuditLog.php';
                    AuditLog::record('auth.login.fail', $loginId, '로그인 실패', null, $loginId);
                    $loginError = '아이디 또는 비밀번호가 올바르지 않습니다.';
                }
            }
        }
    }

    // CSRF 토큰 발급
    if (empty($_SESSION['login_csrf_token'])) {
        $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
    }
    $loginCsrfToken = $_SESSION['login_csrf_token'];

    require __DIR__ . '/login_view.php';
    exit;
}

admin_require_login();

if ($route === '') {
    $route = 'dashboard';
}

if (!admin_can_access_route($route)) {
    http_response_code(403);
    $pageTitle = '접근 권한 없음';
    require_once INC_PATH . '/header.php';
    require_once INC_PATH . '/shell_main_open.php';
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">이 메뉴에 접근할 권한이 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    require_once INC_PATH . '/shell_close.php';
    exit;
}

$routes = require INC_PATH . '/routes.php';

if (!isset($routes[$route])) {
    http_response_code(404);
    $pageTitle = '페이지 없음';
    require_once INC_PATH . '/header.php';
    require_once INC_PATH . '/shell_main_open.php';
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning">요청한 경로가 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    require_once INC_PATH . '/shell_close.php';
    exit;
}

$pageTitle = $routes[$route]['title'] . ' — 도깨비 배달 관리자';
$view = $routes[$route]['view'];
$viewPath = __DIR__ . '/views/' . $view . '.php';

require_once INC_PATH . '/header.php';
require_once INC_PATH . '/shell_main_open.php';

if (is_file($viewPath)) {
    require $viewPath;
} else {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-light border">뷰 파일이 없습니다: <code>' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '</code></div>';
    require_once INC_PATH . '/app_content_close.php';
}

require_once INC_PATH . '/shell_close.php';

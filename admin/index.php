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
        $userId = trim((string) ($_POST['user_id'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($userId !== '' && $password !== '') {
            $_SESSION['admin_auth'] = true;
            $_SESSION['admin_user_id'] = $userId;
            header('Location: ' . admin_url('dashboard'), true, 302);
            exit;
        }
        $loginError = '아이디와 비밀번호를 입력하세요.';
    }
    require __DIR__ . '/login_view.php';
    exit;
}

admin_require_login();

if ($route === '') {
    $route = 'dashboard';
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

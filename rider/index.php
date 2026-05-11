<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/rider_auth.php';

rider_auth_bootstrap();

$routes = require INC_PATH . '/rider_routes.php';

$riderRoute = rider_requested_route();

if ($riderRoute === 'logout') {
    rider_logout();
    header('Location: ' . rider_url('login') . '?out=1', true, 302);
    exit;
}

if ($riderRoute === '') {
    $riderRoute = rider_is_logged_in() ? 'home' : 'login';
}

if (!rider_is_logged_in() && $riderRoute !== 'login') {
    $q = $riderRoute === 'home' ? '' : ('?next=' . rawurlencode($riderRoute));
    header('Location: ' . rider_url('login') . $q, true, 302);
    exit;
}

if (rider_is_logged_in() && $riderRoute === 'login') {
    header('Location: ' . rider_url('home'), true, 302);
    exit;
}

if ($riderRoute === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $nextRaw = trim((string) ($_POST['next'] ?? $_GET['next'] ?? 'home'));

    if ($loginId === '' || strlen($password) < 4) {
        $_SESSION['rider_flash_error'] = '아이디와 비밀번호(4자 이상)를 입력하세요.';
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    rider_set_session_user($loginId);
    if ($remember) {
        rider_set_remember_cookie($loginId);
    } else {
        rider_clear_remember_cookie();
    }

    $_SESSION['rider_show_notice_popup'] = true;

    $next = rider_safe_next_route($nextRaw, $routes);
    header('Location: ' . rider_url($next), true, 302);
    exit;
}

if (!isset($routes[$riderRoute])) {
    http_response_code(404);
    $riderPageTitle = '페이지 없음';
    $riderMinimalShell = false;
    $riderUser = rider_current_user();
    require_once INC_PATH . '/rider_shell_start.php';
    echo '<div class="alert alert-warning m-5 mb-0">요청한 화면이 없습니다.</div>';
    require_once INC_PATH . '/rider_shell_end.php';
    exit;
}

$riderPageTitle = $routes[$riderRoute]['title'];
$view = $routes[$riderRoute]['view'];
$viewPath = __DIR__ . '/views/' . $view . '.php';

$riderMinimalShell = ($riderRoute === 'login');
$riderUser = rider_current_user();

require_once INC_PATH . '/rider_shell_start.php';

if (is_file($viewPath)) {
    require $viewPath;
} else {
    echo '<div class="alert alert-light border m-5">뷰가 없습니다: <code>' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '</code></div>';
}

require_once INC_PATH . '/rider_shell_end.php';

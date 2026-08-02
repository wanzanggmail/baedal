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
    require_once INC_PATH . '/RiderAuth.php';

    $loginId  = trim((string) ($_POST['login_id'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);
    $nextRaw  = trim((string) ($_POST['next'] ?? $_GET['next'] ?? 'home'));

    $throttle = RiderAuth::checkLoginThrottle();
    if ($throttle !== null) {
        $_SESSION['rider_flash_error'] = $throttle;
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    if ($loginId === '' || $password === '') {
        $_SESSION['rider_flash_error'] = '아이디와 비밀번호를 입력하세요.';
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    try {
        $rider = RiderAuth::authenticate($loginId, $password);
    } catch (RuntimeException $e) {
        $_SESSION['rider_flash_error'] = $e->getMessage();
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    if ($rider === null) {
        RiderAuth::recordLoginFailure();
        $_SESSION['rider_flash_error'] = '아이디 또는 비밀번호가 올바르지 않습니다.';
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    session_regenerate_id(true);
    RiderAuth::clearLoginFailures();
    rider_set_session_user($rider);
    RiderAuth::touchLastLogin((int) $rider['id']);

    if ($remember) {
        rider_set_remember_cookie((int) $rider['id']);
    } else {
        rider_clear_remember_cookie();
    }

    try {
        require_once INC_PATH . '/Notice.php';
        $popupQueue = Notice::loginPopupQueue(rider_current_agency_id());
        if ($popupQueue !== []) {
            $_SESSION['rider_notice_popup_queue'] = $popupQueue;
        }
    } catch (Throwable) {
    }

    $next = rider_safe_next_route($nextRaw, $routes);
    header('Location: ' . rider_url($next), true, 302);
    exit;
}

if ($riderRoute === 'profile/password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once INC_PATH . '/RiderAuth.php';

    $csrf = (string) ($_POST['_token'] ?? '');
    $expect = (string) ($_SESSION['rider_pw_csrf'] ?? '');
    if ($expect === '' || !hash_equals($expect, $csrf)) {
        $_SESSION['rider_flash_error'] = '잘못된 요청입니다. 다시 시도해 주세요.';
        header('Location: ' . rider_url('profile/password'), true, 302);
        exit;
    }

    $ru = rider_current_user();
    if (!$ru) {
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    try {
        RiderAuth::changePassword(
            (int) $ru['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['new_password_confirm'] ?? '')
        );
        unset($_SESSION['rider_pw_csrf']);
        rider_clear_remember_cookie();
        session_regenerate_id(true);
        $_SESSION['rider_flash_ok'] = '비밀번호가 변경되었습니다.';
    } catch (InvalidArgumentException $e) {
        $_SESSION['rider_flash_error'] = $e->getMessage();
    } catch (Throwable $e) {
        $_SESSION['rider_flash_error'] = '비밀번호 변경에 실패했습니다.';
    }

    header('Location: ' . rider_url('profile/password'), true, 302);
    exit;
}

if ($riderRoute === 'withdrawal/apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once INC_PATH . '/Withdrawal.php';

    $csrf = (string) ($_POST['_token'] ?? '');
    $expect = (string) ($_SESSION['rider_wd_csrf'] ?? '');
    if ($expect === '' || !hash_equals($expect, $csrf)) {
        $_SESSION['rider_flash_error'] = '잘못된 요청입니다. 다시 시도해 주세요.';
        header('Location: ' . rider_url('withdrawal/apply'), true, 302);
        exit;
    }
    unset($_SESSION['rider_wd_csrf']);

    $ru = rider_current_user();
    if (!$ru) {
        header('Location: ' . rider_url('login'), true, 302);
        exit;
    }

    try {
        $toDate = trim((string) ($_POST['to_date'] ?? ''));
        $row = Withdrawal::applyForRider((int) $ru['id'], $toDate !== '' ? $toDate : null);
        $_SESSION['rider_flash_ok'] = '출금 신청이 접수되었습니다. (실지급 ₩' . number_format((int) $row['amount']) . ')';
    } catch (InvalidArgumentException $e) {
        $_SESSION['rider_flash_error'] = $e->getMessage();
    } catch (Throwable) {
        $_SESSION['rider_flash_error'] = '출금 신청에 실패했습니다.';
    }

    header('Location: ' . rider_url('withdrawal/apply'), true, 302);
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

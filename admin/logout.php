<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';

$admin = admin_user();
if ($admin) {
    AuditLog::record(
        'auth.logout',
        '세션',
        '관리자 로그아웃',
        (int) $admin['id'],
        (string) $admin['login_id']
    );
}

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . admin_login_url(), true, 302);
exit;

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . admin_login_url(), true, 302);
exit;

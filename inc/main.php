<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (admin_is_logged_in()) {
    header('Location: ' . admin_url('dashboard'), true, 302);
} else {
    header('Location: ' . admin_login_url(), true, 302);
}
exit;

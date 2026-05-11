<?php

declare(strict_types=1);

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_auth']);
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        header('Location: ' . admin_login_url(), true, 302);
        exit;
    }
}

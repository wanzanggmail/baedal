<?php

declare(strict_types=1);

/**
 * 로그인 전용 진입점 — mod_rewrite 없이도 /admin/login.php 로 동작합니다.
 * 내부적으로 route=login 과 동일하게 처리합니다.
 */
$_GET['route'] = 'login';
require __DIR__ . '/index.php';

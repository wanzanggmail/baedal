<?php

declare(strict_types=1);

require_once INC_PATH . '/rider_auth.php';

if (!isset($riderPageTitle)) {
    $riderPageTitle = '도깨비';
}
if (!isset($riderRoute)) {
    $riderRoute = '';
}
$riderMinimalShell = !empty($riderMinimalShell);
$riderUser = $riderUser ?? rider_current_user();

$riderHeaderBarTitle = '도깨비 - ' . $riderPageTitle;
$fullTitle = $riderPageTitle . ' — 도깨비 배달';
$manifestHref = htmlspecialchars(rtrim(RIDER_BASE, '/') . '/manifest.php', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
	<title><?= htmlspecialchars($fullTitle, ENT_QUOTES, 'UTF-8') ?></title>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
	<meta name="description" content="도깨비 배달 앱" />
	<meta name="theme-color" content="#009ef7" />
	<meta name="application-name" content="도깨비" />
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-title" content="도깨비" />
	<meta name="apple-mobile-web-app-status-bar-style" content="default" />
	<link rel="manifest" href="<?= $manifestHref ?>" />
	<link rel="shortcut icon" href="<?= htmlspecialchars(web_favicon_shortcut_href(), ENT_QUOTES, 'UTF-8') ?>" />
	<?php if (($appleTouch = web_favicon_apple_touch_href()) !== null) : ?>
	<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($appleTouch, ENT_QUOTES, 'UTF-8') ?>" />
	<?php endif; ?>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700" />
	<link href="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
	<link href="<?= htmlspecialchars(web_asset('css/style.bundle.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
	<link href="<?= htmlspecialchars(web_asset('css/rider-mobile.css?v=' . time()), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
	<script>
		var defaultThemeMode = "light";
		var themeMode;
		if (document.documentElement) {
			if (document.documentElement.hasAttribute("data-bs-theme-mode")) {
				themeMode = document.documentElement.getAttribute("data-bs-theme-mode");
			} else {
				themeMode = localStorage.getItem("data-bs-theme") !== null ? localStorage.getItem("data-bs-theme") : defaultThemeMode;
			}
			if (themeMode === "system") {
				themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
			}
			document.documentElement.setAttribute("data-bs-theme", themeMode);
		}
	</script>
</head>
<body class="app-default bg-body rider-mobile-root">
	<div class="d-flex flex-column flex-root min-vh-100">
		<?php if (!$riderMinimalShell) : ?>
		<div
			id="kt_rider_menu_drawer"
			class="bg-body border-end"
			data-kt-drawer="true"
			data-kt-drawer-name="rider-menu"
			data-kt-drawer-activate="true"
			data-kt-drawer-overlay="true"
			data-kt-drawer-width="{default:'280px', 'md': '300px'}"
			data-kt-drawer-direction="start"
			data-kt-drawer-toggle="#kt_rider_menu_toggle"
		>
			<div class="d-flex flex-column h-100">
				<div class="d-flex align-items-center px-6 py-5 border-bottom border-gray-200">
					<img alt="Logo" src="<?= htmlspecialchars(web_asset('media/logos/default-small.svg'), ENT_QUOTES, 'UTF-8') ?>" class="h-30px me-3" />
					<div>
						<div class="fw-bold text-gray-900">도깨비 배달</div>
						<div class="fs-8 text-muted"><?= $riderUser ? htmlspecialchars($riderUser['name'], ENT_QUOTES, 'UTF-8') : '도깨비' ?></div>
					</div>
				</div>
				<div class="flex-grow-1 overflow-auto">
					<?php require INC_PATH . '/rider_drawer_menu.php'; ?>
				</div>
				<div class="px-6 py-4 border-top border-gray-200 fs-7 text-muted">
					배달용품 바로가기는 홈 화면에서 외부 링크로 열립니다.
				</div>
			</div>
		</div>
		<?php endif; ?>

		<header class="border-bottom border-gray-200 bg-body position-sticky top-0 z-index-3" style="z-index: 100;">
			<div class="d-flex align-items-center justify-content-between px-4 py-3 gap-2">
				<?php if ($riderMinimalShell) : ?>
				<div class="w-35px"></div>
				<?php else : ?>
				<button type="button" class="btn btn-icon btn-color-gray-600 btn-active-color-primary" id="kt_rider_menu_toggle" aria-label="메뉴">
					<i class="ki-duotone ki-abstract-14 fs-1"><span class="path1"></span><span class="path2"></span></i>
				</button>
				<?php endif; ?>
				<h1 class="fs-5 fw-bold text-gray-900 text-truncate flex-grow-1 text-center mb-0 px-2"><?= htmlspecialchars($riderHeaderBarTitle, ENT_QUOTES, 'UTF-8') ?></h1>
				<?php if ($riderMinimalShell) : ?>
				<div class="w-35px"></div>
				<?php else : ?>
				<a href="<?= htmlspecialchars(rider_url('logout'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-icon btn-color-gray-600 btn-active-color-primary" title="로그아웃" id="kt_rider_header_logout">
					<i class="ki-duotone ki-entrance-left fs-1"><span class="path1"></span><span class="path2"></span></i>
				</a>
				<?php endif; ?>
			</div>
		</header>

		<main class="flex-grow-1 overflow-auto px-4 py-4 <?= $riderMinimalShell ? 'pb-10' : 'pb-20' ?>" id="kt_rider_main">

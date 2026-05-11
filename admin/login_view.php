<?php

declare(strict_types=1);

/** @var string|null $loginError */
$loginError = $loginError ?? null;
$pageTitle = '로그인 — 도깨비 배달 관리자';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
	<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link rel="shortcut icon" href="<?= htmlspecialchars(web_asset('media/logos/favicon.ico'), ENT_QUOTES, 'UTF-8') ?>" />
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700" />
	<link href="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
	<link href="<?= htmlspecialchars(web_asset('css/style.bundle.css'), ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet" type="text/css" />
	<script>if (window.top != window.self) { window.top.location.replace(window.self.location.href); }</script>
</head>
<body id="kt_body" class="app-blank bgi-size-cover bgi-attachment-fixed bgi-position-center bgi-no-repeat">
	<script>var defaultThemeMode = "light"; var themeMode; if ( document.documentElement ) { if ( document.documentElement.hasAttribute("data-bs-theme-mode")) { themeMode = document.documentElement.getAttribute("data-bs-theme-mode"); } else { if ( localStorage.getItem("data-bs-theme") !== null ) { themeMode = localStorage.getItem("data-bs-theme"); } else { themeMode = defaultThemeMode; } } if (themeMode === "system") { themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light"; } document.documentElement.setAttribute("data-bs-theme", themeMode); }</script>
	<div class="d-flex flex-column flex-root" id="kt_app_root">
		<style>body { background-image: url('<?= htmlspecialchars(web_asset('media/auth/bg4.jpg'), ENT_QUOTES, 'UTF-8') ?>'); } [data-bs-theme="dark"] body { background-image: url('<?= htmlspecialchars(web_asset('media/auth/bg4-dark.jpg'), ENT_QUOTES, 'UTF-8') ?>'); }</style>
		<div class="d-flex flex-column flex-column-fluid flex-lg-row">
			<div class="d-flex flex-center w-lg-50 pt-15 pt-lg-0 px-10">
				<div class="d-flex flex-center flex-lg-start flex-column">
					<a href="<?= htmlspecialchars(admin_login_url(), ENT_QUOTES, 'UTF-8') ?>" class="mb-7">
						<img alt="Logo" src="<?= htmlspecialchars(web_asset('media/logos/default-dark.svg'), ENT_QUOTES, 'UTF-8') ?>" />
					</a>
					<h2 class="text-white fw-normal m-0">도깨비 배달 관리자</h2>
				</div>
			</div>
			<div class="d-flex flex-column-fluid flex-lg-row-auto justify-content-center justify-content-lg-end p-12 p-lg-20">
				<div class="bg-body d-flex flex-column align-items-stretch flex-center rounded-4 w-md-600px p-20">
					<div class="d-flex flex-center flex-column flex-column-fluid px-lg-10 pb-15 pb-lg-20">
						<form class="form w-100" method="post" action="<?= htmlspecialchars(admin_login_url(), ENT_QUOTES, 'UTF-8') ?>" accept-charset="UTF-8">
							<div class="text-center mb-11">
								<h1 class="text-gray-900 fw-bolder mb-3">로그인</h1>
								<div class="text-gray-500 fw-semibold fs-6">관리자 계정으로 로그인하세요</div>
							</div>
							<?php if ($loginError !== null && $loginError !== ''): ?>
								<div class="alert alert-danger d-flex align-items-center mb-8">
									<span><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></span>
								</div>
							<?php endif; ?>
							<div class="fv-row mb-8">
								<input type="text" placeholder="아이디" name="user_id" autocomplete="username" class="form-control bg-transparent" value="<?= isset($_POST['user_id']) ? htmlspecialchars((string) $_POST['user_id'], ENT_QUOTES, 'UTF-8') : '' ?>" />
							</div>
							<div class="fv-row mb-3">
								<input type="password" placeholder="비밀번호" name="password" autocomplete="current-password" class="form-control bg-transparent" />
							</div>
							<div class="d-grid mb-10 mt-8">
								<button type="submit" class="btn btn-primary">
									<span class="indicator-label">로그인</span>
								</button>
							</div>
						</form>
					</div>
					<div class="d-flex flex-stack px-lg-10">
						<div class="me-0"></div>
						<div class="d-flex fw-semibold text-primary fs-base gap-5"></div>
					</div>
				</div>
			</div>
		</div>
	</div>
	<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
	<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>

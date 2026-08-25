<?php

declare(strict_types=1);

$flash = $_SESSION['rider_flash_error'] ?? '';
unset($_SESSION['rider_flash_error']);
$next = isset($_GET['next']) ? trim((string) $_GET['next'], '/') : '';
if ($next === 'login' || $next === 'logout') {
    $next = '';
}
?>
<div class="card card-flush shadow-sm mt-2">
	<div class="card-body p-6 p-sm-8">
		<div class="text-center mb-8">
			<img alt="Logo" src="<?= htmlspecialchars(web_asset('media/logos/default-small.svg'), ENT_QUOTES, 'UTF-8') ?>" class="h-40px mb-4" />
			<h2 class="fs-3 fw-bold text-gray-900">라이더 로그인</h2>
			<p class="text-gray-600 fs-7 mb-0">등록된 라이더 계정으로 로그인합니다.</p>
		</div>
		<?php if ($flash !== '') : ?>
		<div class="alert alert-danger fs-7 py-3 mb-5"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<form method="post" action="<?= htmlspecialchars(rider_url('login'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="on">
			<input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>" />
			<div class="mb-4">
				<label class="form-label" for="rider_login_id">로그인 ID / 휴대전화</label>
				<input type="text" class="form-control form-control-solid" id="rider_login_id" name="login_id" required autocomplete="username" placeholder="예: mb1234567890 또는 01012345678" />
			</div>
			<div class="mb-4">
				<label class="form-label" for="rider_password">비밀번호</label>
				<input type="password" class="form-control form-control-solid" id="rider_password" name="password" required autocomplete="current-password" />
			</div>
			<div class="form-check form-check-custom form-check-solid mb-6">
				<input class="form-check-input" type="checkbox" name="remember" value="1" id="rider_remember" checked />
				<label class="form-check-label text-gray-800" for="rider_remember">로그인 상태 유지 (30일)</label>
			</div>
			<button type="submit" class="btn btn-primary w-100 py-3">로그인</button>
		</form>
	</div>
</div>

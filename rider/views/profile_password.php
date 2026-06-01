<?php

declare(strict_types=1);

if (empty($_SESSION['rider_pw_csrf'])) {
    $_SESSION['rider_pw_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['rider_pw_csrf'];

$flashErr = $_SESSION['rider_flash_error'] ?? '';
$flashOk  = $_SESSION['rider_flash_ok'] ?? '';
unset($_SESSION['rider_flash_error'], $_SESSION['rider_flash_ok']);
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">비밀번호 변경</h2>
	</div>
	<div class="card-body pt-0">
		<?php if ($flashOk !== '') : ?>
		<div class="alert alert-success fs-7 py-3 mb-5"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<?php if ($flashErr !== '') : ?>
		<div class="alert alert-danger fs-7 py-3 mb-5"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<p class="text-gray-600 fs-7 mb-5">현재 비밀번호 확인 후 새 비밀번호로 변경합니다. (4자 이상)</p>
		<form method="post" action="<?= htmlspecialchars(rider_url('profile/password'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
			<input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
			<div class="mb-4">
				<label class="form-label required" for="rider_current_password">현재 비밀번호</label>
				<input type="password" class="form-control form-control-solid" id="rider_current_password" name="current_password" required autocomplete="current-password" />
			</div>
			<div class="mb-4">
				<label class="form-label required" for="rider_new_password">새 비밀번호</label>
				<input type="password" class="form-control form-control-solid" id="rider_new_password" name="new_password" required minlength="4" autocomplete="new-password" />
			</div>
			<div class="mb-6">
				<label class="form-label required" for="rider_new_password_confirm">새 비밀번호 확인</label>
				<input type="password" class="form-control form-control-solid" id="rider_new_password_confirm" name="new_password_confirm" required minlength="4" autocomplete="new-password" />
			</div>
			<button type="submit" class="btn btn-primary w-100 mb-3">비밀번호 저장</button>
			<a href="<?= htmlspecialchars(rider_url('profile'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light w-100">내 정보로</a>
		</form>
	</div>
</div>

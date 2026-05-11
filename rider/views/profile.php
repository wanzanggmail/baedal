<?php

declare(strict_types=1);

$ru = rider_current_user();
$name = $ru['name'] ?? '라이더';
$lid = $ru['login_id'] ?? '';
$initial = $name !== '' ? (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1)) : '?';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-body text-center pt-8">
		<div class="symbol symbol-80px symbol-circle bg-light-primary mx-auto mb-4">
			<span class="symbol-label fs-2x fw-bold text-primary"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="fw-bold fs-3 text-gray-900"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
		<div class="text-muted fs-7 font-monospace"><?= htmlspecialchars($lid, ENT_QUOTES, 'UTF-8') ?></div>
	</div>
</div>
<div class="card card-flush shadow-sm">
	<div class="card-body fs-6">
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">로그인 ID</span>
			<span class="fw-semibold font-monospace fs-7"><?= htmlspecialchars($lid, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">이메일</span>
			<span class="fw-semibold fs-7">rider@example.com</span>
		</div>
		<div class="d-flex justify-content-between py-3">
			<span class="text-gray-600">차량</span>
			<span class="fw-semibold">오토바이</span>
		</div>
	</div>
</div>
<div class="d-grid gap-3 mt-5">
	<a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light-primary">계좌 정보</a>
	<a href="<?= htmlspecialchars(rider_url('profile/password'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">비밀번호 변경</a>
</div>

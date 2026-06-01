<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderAuth.php';

$ru = rider_current_user();
$profile = null;
$profileError = null;

if ($ru) {
    try {
        $profile = RiderAuth::findById((int) $ru['id']);
    } catch (Throwable $e) {
        $profileError = $e->getMessage();
    }
}

$name = $profile['name'] ?? $ru['name'] ?? '라이더';
$lid  = $profile['login_id'] ?? $ru['login_id'] ?? '';
$code = $profile['rider_code'] ?? $ru['rider_code'] ?? '';
$phone = $profile['phone'] ?? $ru['phone'] ?? '';
$email = $profile['email'] ?? $ru['email'] ?? '';
$vehicle = (string) ($profile['vehicle_type'] ?? 'motor');

$vehicleLabels = [
    'motor' => '오토바이',
    'bike'  => '자전거',
    'car'   => '자동차',
    'walk'  => '도보',
    'kick'  => '전동킥보드',
];

$initial = $name !== '' ? (function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1)) : '?';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-body text-center pt-8">
		<div class="symbol symbol-80px symbol-circle bg-light-primary mx-auto mb-4">
			<span class="symbol-label fs-2x fw-bold text-primary"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="fw-bold fs-3 text-gray-900"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
		<div class="text-muted fs-7 font-monospace"><?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?></div>
	</div>
</div>
<?php if ($profileError !== null) : ?>
<div class="alert alert-warning"><?= htmlspecialchars($profileError, ENT_QUOTES, 'UTF-8') ?></div>
<?php elseif ($profile === null) : ?>
<div class="alert alert-warning">프로필을 불러올 수 없습니다.</div>
<?php else : ?>
<div class="card card-flush shadow-sm">
	<div class="card-body fs-6">
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">로그인 ID</span>
			<span class="fw-semibold font-monospace fs-7"><?= htmlspecialchars($lid, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">휴대전화</span>
			<span class="fw-semibold fs-7"><?= htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">이메일</span>
			<span class="fw-semibold fs-7 text-end"><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span class="text-gray-600">차량</span>
			<span class="fw-semibold"><?= htmlspecialchars($vehicleLabels[$vehicle] ?? $vehicle, ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<div class="d-flex justify-content-between py-3">
			<span class="text-gray-600">정산 방식</span>
			<span class="fw-semibold"><?= !empty($profile['is_daily_settlement']) ? '일일정산' : '주간정산' ?></span>
		</div>
	</div>
</div>
<?php endif; ?>
<div class="d-grid gap-3 mt-5">
	<a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light-primary">계좌 정보</a>
	<a href="<?= htmlspecialchars(rider_url('profile/password'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">비밀번호 변경</a>
</div>

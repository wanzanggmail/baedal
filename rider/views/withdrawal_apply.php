<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderSettlement.php';
require_once INC_PATH . '/RiderAuth.php';

$withdrawSummary = [
    'withdrawable'    => 0,
    'withdrawal_hold' => false,
    'error'           => null,
];
$bankLabel = '';
$bankAccount = '';
$accountHolder = '';

$ru = rider_current_user();
if ($ru) {
    $withdrawSummary = RiderSettlement::homeSummary((int) $ru['id']);
    try {
        $profile = RiderAuth::findById((int) $ru['id']);
        if ($profile) {
            $bankLabel = (string) ($profile['bank_label'] ?? '');
            $bankAccount = (string) ($profile['bank_account'] ?? '');
            $accountHolder = (string) ($profile['account_holder'] ?? $profile['name'] ?? '');
        }
    } catch (Throwable) {
    }
}

$bankDisplay = '등록된 계좌 없음';
if ($bankLabel !== '' && $bankAccount !== '') {
    $masked = strlen($bankAccount) > 4
        ? str_repeat('*', max(0, strlen($bankAccount) - 4)) . substr($bankAccount, -4)
        : $bankAccount;
    $bankDisplay = trim($bankLabel . ' ' . $masked . ($accountHolder !== '' ? ' · ' . $accountHolder : ''));
}
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청</h2>
		<span class="text-gray-500 fs-7">금액·계좌 확인 후 제출</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($withdrawSummary['error'] !== null) : ?>
		<div class="alert alert-warning fs-8 mb-4"><?= htmlspecialchars($withdrawSummary['error'], ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<div class="mb-4">
			<label class="form-label">출금 가능 잔액</label>
			<div class="fs-2 fw-bold text-gray-900">₩ <?= htmlspecialchars(number_format($withdrawSummary['withdrawable']), ENT_QUOTES, 'UTF-8') ?></div>
			<?php if ($withdrawSummary['withdrawal_hold']) : ?>
			<div class="form-text text-danger">출금 보류 상태입니다.</div>
			<?php endif; ?>
		</div>
		<div class="mb-4">
			<label class="form-label">신청 금액</label>
			<input type="text" class="form-control form-control-solid" placeholder="₩" disabled />
		</div>
		<div class="mb-4">
			<label class="form-label">입금 계좌</label>
			<div class="form-control form-control-solid bg-light"><?= htmlspecialchars($bankDisplay, ENT_QUOTES, 'UTF-8') ?></div>
			<div class="form-text"><a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>">계좌 변경</a></div>
		</div>
		<button type="button" class="btn btn-primary w-100" disabled>제출 (준비 중)</button>
	</div>
</div>

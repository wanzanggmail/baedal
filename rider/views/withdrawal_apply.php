<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/Withdrawal.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$preview   = $riderId > 0 ? RiderWallet::previewWithdrawal($riderId) : [];
$canApply  = $riderId > 0 && (bool) ($preview['can_apply'] ?? false) && !Withdrawal::hasOpenRiderRequest($riderId);

$bankLabel = '—';
$bankAcct  = '—';
$bankHolder = $riderUser['name'] ?? '';
if ($riderId > 0) {
    $r = db_row(
        'SELECT r.bank_code, r.bank_account, r.account_holder, r.name, sc.label AS bank_label
         FROM riders r
         LEFT JOIN system_codes sc ON sc.category = \'bank\' AND sc.code = r.bank_code
         WHERE r.id = ? LIMIT 1',
        [$riderId]
    );
    if ($r) {
        $bankLabel  = (string) ($r['bank_label'] ?: $r['bank_code'] ?: '—');
        $acct       = (string) ($r['bank_account'] ?? '');
        $bankAcct   = $acct !== '' && strlen($acct) > 4
            ? substr($acct, 0, 3) . str_repeat('*', max(0, strlen($acct) - 5)) . substr($acct, -2)
            : '미등록';
        $bankHolder = (string) ($r['account_holder'] ?: $r['name']);
    }
}

$flashOk   = (string) ($_SESSION['rider_flash_ok'] ?? '');
$flashErr  = (string) ($_SESSION['rider_flash_error'] ?? '');
unset($_SESSION['rider_flash_ok'], $_SESSION['rider_flash_error']);

if (empty($_SESSION['rider_wd_csrf'])) {
    $_SESSION['rider_wd_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['rider_wd_csrf'];
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청</h2>
		<span class="text-gray-500 fs-7">모인 금액 전액 출금 (보증금·수수료 자동 차감)</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($flashOk !== '') : ?>
		<div class="alert alert-success fs-7 mb-4"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<?php if ($flashErr !== '') : ?>
		<div class="alert alert-danger fs-7 mb-4"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>

		<div class="mb-5 p-4 bg-light rounded">
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7">지갑 잔액</span>
				<span class="fw-bold">₩ <?= number_format((int) ($preview['balance'] ?? 0)) ?></span>
			</div>
			<?php if (empty($preview['fee_cycle_based'])) : ?>
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7">적립 일수</span>
				<span><?= (int) ($preview['accrued_days'] ?? 0) ?>일</span>
			</div>
			<?php endif; ?>
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7">보증금 (출금 후 유지)</span>
				<span class="text-danger">− ₩ <?= number_format((int) ($preview['reserve_amount'] ?? 0)) ?></span>
			</div>
			<?php if (!empty($preview['fee_cycle_based'])) : ?>
			<?php // §7 #18 — 정산수수료는 주문 건별로 매겨진다. 최근 주문일수록 비싸므로 구간을 나눠 보여준다. ?>
			<?php if ((int) ($preview['fee_short_orders'] ?? 0) > 0) : ?>
			<div class="d-flex justify-content-between mb-1">
				<span class="text-gray-600 fs-8">└ 최근 <?= (int) ($preview['fee_day_threshold'] ?? 7) ?>일 이내 <?= number_format((int) $preview['fee_short_orders']) ?>건 × <?= number_format((int) $preview['fee_rate_short']) ?>원</span>
				<span class="text-danger fs-8">− ₩ <?= number_format((int) $preview['fee_short_amount']) ?></span>
			</div>
			<?php endif; ?>
			<?php if ((int) ($preview['fee_long_orders'] ?? 0) > 0) : ?>
			<div class="d-flex justify-content-between mb-1">
				<span class="text-gray-600 fs-8">└ <?= (int) ($preview['fee_day_threshold'] ?? 7) ?>일 지난 <?= number_format((int) $preview['fee_long_orders']) ?>건 × <?= number_format((int) $preview['fee_rate_long']) ?>원</span>
				<span class="text-danger fs-8">− ₩ <?= number_format((int) $preview['fee_long_amount']) ?></span>
			</div>
			<?php endif; ?>
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7 fw-semibold">정산수수료 합계</span>
				<span class="text-danger fw-semibold">− ₩ <?= number_format((int) ($preview['fee_per_tx'] ?? 0)) ?></span>
			</div>
			<?php else : ?>
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7">출금 수수료 (건당)</span>
				<span class="text-danger">− ₩ <?= number_format((int) ($preview['fee_per_tx'] ?? 0)) ?></span>
			</div>
			<?php endif; ?>
			<div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center">
				<span class="fw-bold text-gray-800">실지급 예정액</span>
				<span class="fs-3 fw-bold text-primary">₩ <?= number_format((int) ($preview['payout_amount'] ?? 0)) ?></span>
			</div>
		</div>

		<div class="mb-5">
			<label class="form-label fs-7">입금 계좌</label>
			<div class="form-control form-control-solid bg-light">
				<?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?>
				<?= htmlspecialchars($bankAcct, ENT_QUOTES, 'UTF-8') ?>
				· <?= htmlspecialchars($bankHolder, ENT_QUOTES, 'UTF-8') ?>
			</div>
			<div class="form-text"><a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>">계좌 변경</a></div>
		</div>

		<form method="post" action="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>">
			<input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
			<button type="submit" class="btn btn-primary w-100"<?= $canApply ? '' : ' disabled' ?>>
				전액 출금 신청
			</button>
		</form>
		<?php if (!$canApply && $flashOk === '') : ?>
		<p class="text-muted fs-8 mt-3 mb-0">
			<?php if (Withdrawal::hasOpenRiderRequest($riderId)) : ?>
			처리 중인 출금 신청이 있습니다. <a href="<?= htmlspecialchars(rider_url('withdrawal/history'), ENT_QUOTES, 'UTF-8') ?>">내역 확인</a>
			<?php else : ?>
			출금 가능 금액이 없거나 계좌·상태를 확인해 주세요.
			<?php endif; ?>
		</p>
		<?php endif; ?>
	</div>
</div>

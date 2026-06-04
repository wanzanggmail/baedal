<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$cycleId   = (int) ($_GET['cycle'] ?? 0);
$listUrl   = rider_url('settlement/fees');

if ($cycleId < 1 || $riderId < 1 || !SettlementLedger::tableExists()) {
    echo '<div class="alert alert-warning">내역을 불러올 수 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '">목록</a></div>';
    return;
}

$cycle = SettlementLedger::find($cycleId, $riderId);
if ($cycle === null) {
    echo '<div class="alert alert-warning">내역이 없습니다.</div>';
    return;
}

$fees = $cycle['fees'] ?? [];
?>
<div class="mb-3">
	<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="fs-7 fw-semibold">← 수수료 내역</a>
</div>

<div class="card card-flush shadow-sm mb-4">
	<div class="card-body">
		<div class="fs-7 text-gray-500 mb-1">
			<?= htmlspecialchars($cycle['settlement_date'], ENT_QUOTES, 'UTF-8') ?>
			· <?= htmlspecialchars($cycle['platform_label'], ENT_QUOTES, 'UTF-8') ?>
		</div>
		<div class="fs-2 fw-bold text-gray-900">₩ <?= number_format((int) $cycle['net_amount']) ?></div>
		<span class="badge badge-light-success">정산 완료</span>
	</div>
</div>

<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">항목별 내역</h2>
	</div>
	<div class="card-body pt-0 fs-7">
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span>플랫폼 지급액</span>
			<span class="fw-semibold">₩ <?= number_format((int) $cycle['platform_payout']) ?></span>
		</div>
		<?php foreach ($fees as $fee) : ?>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span><?= htmlspecialchars($fee['label'], ENT_QUOTES, 'UTF-8') ?></span>
			<span class="fw-semibold text-danger">− ₩ <?= number_format((int) $fee['amount']) ?></span>
		</div>
		<?php endforeach; ?>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span>수수료 합계</span>
			<span class="fw-semibold text-danger">− ₩ <?= number_format((int) $cycle['total_fee_amount']) ?></span>
		</div>
		<div class="d-flex justify-content-between py-3">
			<span class="fw-bold">지갑 적립</span>
			<span class="fw-bold">₩ <?= number_format((int) $cycle['net_amount']) ?></span>
		</div>
	</div>
</div>

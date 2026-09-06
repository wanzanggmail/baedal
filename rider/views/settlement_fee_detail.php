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

// 🔒 대리점 선차감(2026-09-06 갑) — 라이더에게는 보이지 않는 대리점 몫이다.
// 공제 줄에서 빼고 정산금액·수수료 합계를 그만큼 낮춰, 라이더 눈에는 처음부터
// 그만큼 낮은 단가였던 것처럼 보이게 한다. 지갑 적립(net)은 그대로라
// 「정산금액 − 수수료 합계 = 지갑 적립」 이 그대로 맞아떨어진다.
$prededucted = 0;
foreach ($fees as $f) {
    if ((string) ($f['fee_code'] ?? '') === 'agency_prededuct') {
        $prededucted += (int) ($f['amount'] ?? 0);
    }
}
if ($prededucted > 0) {
    $fees = array_values(array_filter(
        $fees,
        static fn (array $f): bool => (string) ($f['fee_code'] ?? '') !== 'agency_prededuct'
    ));
    $cycle['gross_amount']     = (int) $cycle['gross_amount'] - $prededucted;
    $cycle['total_fee_amount'] = (int) $cycle['total_fee_amount'] - $prededucted;
}
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
			<span>정산금액</span>
			<span class="fw-semibold">₩ <?= number_format((int) $cycle['gross_amount']) ?></span>
		</div>
		<?php if ((int) ($cycle['support_amount'] ?? 0) > 0) : ?>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span>지원금</span>
			<span class="fw-semibold text-success">+ ₩ <?= number_format((int) $cycle['support_amount']) ?></span>
		</div>
		<?php endif; ?>
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

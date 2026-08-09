<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/Promotion.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$orgId     = rider_current_agency_id();
$needsMigrate = !SettlementLedger::tableExists();

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$rows      = [];
$sum       = ['count' => 0, 'orders' => 0, 'gross' => 0, 'support' => 0, 'payout' => 0, 'fee' => 0, 'net' => 0];
$breakdown = [];
$rates     = ['withholding_tax_pct' => 3.3, 'employment_ins_pct' => 0.80, 'industrial_accident_ins_pct' => 0.88];
$promoRows = [];

if ($riderId > 0 && !$needsMigrate) {
    try {
        $listFilters = ['from' => $filterFrom, 'to' => $filterTo];
        $sum       = SettlementLedger::sumForRider($riderId, $listFilters);
        $breakdown = SettlementLedger::feeBreakdownForRider($riderId, $listFilters);
        $rows      = SettlementLedger::listForRider($riderId, $listFilters + ['limit' => 100]);
        $rates     = SettlementLedger::deductionRates($orgId > 0 ? $orgId : null);
    } catch (Throwable) {
        $rows = [];
    }
    try {
        $promoRows = Promotion::listForRiderPeriod($riderId, $listFilters, 100);
    } catch (Throwable) {
        $promoRows = [];
    }
}

// 정산(공제 전 총액) — "정산 - 공제 = 실수령금액"이 항상 성립하도록 net+fee로 역산한다.
// (gross_amount+support_amount는 옛 정산식으로 만들어진 과거 사이클에서 fee/net과
//  어긋날 수 있어— 여러 차례 계산식이 개정됐고 기존 사이클은 소급 재계산하지 않음 —
//  화면 표시용 "정산" 합계는 항상 실수령과 맞아떨어지는 net+fee를 쓴다.)
$grossTotal = $sum['net'] + $sum['fee'];

$detailBase = rider_url('settlement/fee-detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';

$filterUrl = rider_url('settlement/fees');
$filterUrl .= str_contains($filterUrl, '?') ? '&' : '?';

$today = date('Y-m-d');
$rangePresets = [
    '오늘'   => [$today, $today],
    '최근 7일' => [date('Y-m-d', strtotime('-6 days')), $today],
    '이번 달'  => [date('Y-m-01'), date('Y-m-t')],
    '지난 달'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), $today],
];

$fmtWon = static fn (int $n): string => number_format($n) . '원';
$fmtPct = static fn (float $p): string => rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';

$weekdayKr = ['일', '월', '화', '수', '목', '금', '토'];
$fmtDateKr = static function (string $ymd) use ($weekdayKr): string {
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return date('n월 j일', $ts) . ' (' . $weekdayKr[(int) date('w', $ts)] . ')';
};

$promoStatusBadge = [
    'paid'    => ['지급완료', 'success'],
    'pending' => ['대기', 'warning'],
    'failed'  => ['실패', 'danger'],
];

$promoTotal = 0;
foreach ($promoRows as $p) {
    if ((string) $p['status'] === 'paid') {
        $promoTotal += (int) $p['total_amount'];
    }
}
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산 조회</h2>
	</div>
	<div class="card-body pt-0">
		<?php if ($needsMigrate) : ?>
		<p class="text-muted fs-7 py-6 mb-0">정산 수수료 내역을 준비 중입니다.</p>
		<?php else : ?>

		<form method="get" action="<?= htmlspecialchars(rider_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="row g-2 align-items-end mb-3">
			<?php if (defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL) : ?>
			<input type="hidden" name="route" value="settlement/fees" />
			<?php endif; ?>
			<div class="col-6">
				<label class="form-label fs-8 mb-1">시작일</label>
				<input type="date" class="form-control form-control-sm form-control-solid" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
			</div>
			<div class="col-6">
				<label class="form-label fs-8 mb-1">종료일</label>
				<input type="date" class="form-control form-control-sm form-control-solid" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
			</div>
			<div class="col-12">
				<button type="submit" class="btn btn-sm btn-light-primary w-100">조회</button>
			</div>
		</form>

		<div class="d-flex flex-wrap gap-2">
			<?php foreach ($rangePresets as $label => [$pf, $pt]) :
			    $active = ($filterFrom === $pf && $filterTo === $pt);
			    ?>
			<a href="<?= htmlspecialchars($filterUrl . 'from=' . $pf . '&to=' . $pt, ENT_QUOTES, 'UTF-8') ?>"
				class="btn btn-sm py-1 px-3 fs-8 <?= $active ? 'btn-primary' : 'btn-light' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
			<?php endforeach; ?>
		</div>

		<?php endif; ?>
	</div>
</div>

<?php if (!$needsMigrate) : ?>

<!--begin::실수령금액 히어로-->
<div class="card card-flush shadow-sm mb-4">
	<div class="card-body">
		<div class="fs-8 text-gray-500 mb-2"><?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?></div>
		<div class="d-flex justify-content-between align-items-end mb-4">
			<span class="fw-bold fs-6 text-gray-700">실수령금액</span>
			<span class="fs-2qx fw-bold text-success"><?= $fmtWon($sum['net']) ?></span>
		</div>
		<div class="separator mb-3"></div>
		<div class="d-flex justify-content-between align-items-center mb-4">
			<span class="fs-7 text-gray-600">정산 <span class="fw-bold text-primary"><?= $fmtWon($grossTotal) ?></span></span>
			<span class="fs-7 text-gray-600">공제 <span class="fw-bold text-danger">−<?= $fmtWon($sum['fee']) ?></span></span>
		</div>

		<?php if ($breakdown !== []) : ?>
		<div class="separator mb-3"></div>
		<?php foreach ($breakdown as $b) :
		    $pct = null;
		    if ($b['fee_code'] === 'withholding') { $pct = $rates['withholding_tax_pct']; }
		    elseif ($b['fee_code'] === 'employment_ins') { $pct = $rates['employment_ins_pct']; }
		    elseif ($b['fee_code'] === 'accident_ins') { $pct = $rates['industrial_accident_ins_pct']; }
		    ?>
		<div class="d-flex justify-content-between align-items-center py-2">
			<span class="fs-7 text-gray-700">
				<?= htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($pct !== null) : ?> <span class="text-muted">(<?= $fmtPct($pct) ?>)</span><?php endif; ?>
				<?php if ($b['is_debt']) : ?><span class="badge badge-light-warning fs-9 ms-1">차감(원금상환)</span><?php endif; ?>
			</span>
			<span class="fw-semibold fs-7 <?= $b['is_debt'] ? 'text-warning' : 'text-danger' ?>">−<?= $fmtWon($b['amount']) ?></span>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
<!--end::실수령금액 히어로-->

<!--begin::일별 상세-->
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-6">일별 상세</h3>
		<span class="card-toolbar text-muted fs-8"><?= number_format($sum['count']) ?>건</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">해당 기간에 정산 완료 내역이 없습니다.</p>
		<?php else : ?>
		<div class="rider-settle-list border border-gray-200 rounded-3 overflow-hidden bg-body">
			<?php foreach ($rows as $row) : ?>
			<a href="<?= htmlspecialchars($detailBase . 'cycle=' . (int) $row['id'], ENT_QUOTES, 'UTF-8') ?>"
				class="d-flex align-items-center gap-2 px-3 py-3 fs-7 text-gray-900 text-decoration-none border-bottom border-gray-200 bg-hover-light">
				<span class="flex-shrink-0 fw-semibold text-gray-800" style="width: 5.5rem;"><?= htmlspecialchars($fmtDateKr((string) $row['settlement_date']), ENT_QUOTES, 'UTF-8') ?></span>
				<span class="badge badge-light-primary fs-9 flex-shrink-0"><?= htmlspecialchars($row['platform_label'], ENT_QUOTES, 'UTF-8') ?></span>
				<span class="flex-shrink-0 text-gray-500 text-nowrap"><?= number_format((int) $row['order_count']) ?>건</span>
				<span class="flex-grow-1 text-end fw-bold text-success tabular-nums"><?= $fmtWon((int) $row['net_amount']) ?></span>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
<!--end::일별 상세-->

<!--begin::프로모션 이력(접기/펼치기)-->
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5 cursor-pointer" id="promoHistoryToggle" role="button">
		<h3 class="card-title fw-bold fs-6 mb-0">프로모션 이력 <span class="text-muted fw-normal fs-8">(<?= count($promoRows) ?>건)</span></h3>
		<span class="card-toolbar">
			<i class="ki-duotone ki-down fs-3 text-gray-500" id="promoHistoryIcon"><span class="path1"></span></i>
		</span>
	</div>
	<div class="card-body pt-0 d-none" id="promoHistoryBody">
		<?php if ($promoRows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">해당 기간에 프로모션 내역이 없습니다.</p>
		<?php else : ?>
		<?php if ($promoTotal > 0) : ?>
		<div class="d-flex justify-content-between py-2 mb-2 border-bottom border-gray-200">
			<span class="fw-bold text-gray-800">지급 완료 합계</span>
			<span class="fw-bold text-primary"><?= $fmtWon($promoTotal) ?></span>
		</div>
		<?php endif; ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($promoRows as $p) :
			    [$stLabel, $stBadge] = $promoStatusBadge[(string) $p['status']] ?? [(string) $p['status'], 'secondary'];
			    ?>
			<div class="border border-gray-200 rounded p-4">
				<div class="d-flex justify-content-between align-items-center mb-2">
					<span class="fw-bold"><?= htmlspecialchars((string) $p['pay_date'], ENT_QUOTES, 'UTF-8') ?></span>
					<div class="d-flex align-items-center gap-2">
						<span class="fw-bold <?= (string) $p['status'] === 'paid' ? 'text-primary' : 'text-gray-500' ?>">
							<?= (string) $p['status'] === 'paid' ? '+' : '' ?><?= $fmtWon((int) $p['total_amount']) ?>
						</span>
						<span class="badge badge-light-<?= htmlspecialchars($stBadge, ENT_QUOTES, 'UTF-8') ?> fs-9"><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span>
					</div>
				</div>
				<div class="fs-8 text-gray-600">
					<?php if ((int) $p['promo1_amount'] > 0) : ?>
					프로모션1 <?= $fmtWon((int) $p['promo1_amount']) ?>
					<?php endif; ?>
					<?php if ((int) $p['promo2_amount'] > 0) : ?>
					<?= (int) $p['promo1_amount'] > 0 ? ' · ' : '' ?>프로모션2 <?= $fmtWon((int) $p['promo2_amount']) ?>
					<?php endif; ?>
				</div>
				<?php if ((string) $p['status'] === 'failed' && (string) ($p['fail_reason'] ?? '') !== '') : ?>
				<div class="fs-8 text-danger mt-1"><?= htmlspecialchars((string) $p['fail_reason'], ENT_QUOTES, 'UTF-8') ?></div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
<!--end::프로모션 이력-->

<script>
(function () {
	'use strict';
	var toggle = document.getElementById('promoHistoryToggle');
	var body   = document.getElementById('promoHistoryBody');
	var icon   = document.getElementById('promoHistoryIcon');
	if (!toggle || !body) { return; }
	toggle.addEventListener('click', function () {
		var hidden = body.classList.toggle('d-none');
		if (icon) { icon.style.transform = hidden ? 'rotate(0deg)' : 'rotate(180deg)'; }
	});
})();
</script>

<?php endif; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
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

if ($riderId > 0 && !$needsMigrate) {
    try {
        $listFilters = ['from' => $filterFrom, 'to' => $filterTo];
        $sum       = SettlementLedger::sumForRider($riderId, $listFilters);
        $breakdown = SettlementLedger::feeBreakdownForRider($riderId, $listFilters);
        $rows      = SettlementLedger::listForRider($riderId, $listFilters + ['limit' => 100]);
    } catch (Throwable) {
        $rows = [];
    }
}

$feeTotal  = 0;
$debtTotal = 0;
foreach ($breakdown as $b) {
    if ($b['is_debt']) {
        $debtTotal += $b['amount'];
    } else {
        $feeTotal += $b['amount'];
    }
}

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
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산 수수료 내역</h2>
		<span class="text-gray-500 fs-7">정산 완료 후 차감된 수수료·실지급</span>
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

		<div class="d-flex flex-wrap gap-2 mb-5">
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

<div class="card card-flush shadow-sm mb-4">
	<div class="card-body">
		<div class="fs-8 text-gray-500 mb-2"><?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?> 합계 · <?= number_format($sum['count']) ?>건</div>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">정산금액 합계</span>
			<span class="fw-bold fs-6"><?= $fmtWon($sum['gross']) ?></span>
		</div>
		<?php if ($sum['support'] > 0) : ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">지원금</span>
			<span class="fw-semibold text-success fs-7">+<?= $fmtWon($sum['support']) ?></span>
		</div>
		<?php endif; ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">수수료 합계</span>
			<span class="fw-semibold text-danger fs-7">−<?= $fmtWon($sum['fee']) ?></span>
		</div>
		<div class="d-flex justify-content-between py-2">
			<span class="fw-bold text-gray-800">지갑 반영 합계</span>
			<span class="fs-3 fw-bold text-primary"><?= $fmtWon($sum['net']) ?></span>
		</div>
	</div>
</div>

<?php if ($breakdown !== []) : ?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-6">상세 내역 합계 (항목별)</h3>
	</div>
	<div class="card-body pt-0 fs-7">
		<?php foreach ($breakdown as $b) : ?>
		<div class="d-flex justify-content-between align-items-center py-2 border-bottom border-gray-200">
			<span>
				<?= htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') ?>
				<?php if ($b['is_debt']) : ?>
				<span class="badge badge-light-warning fs-9 ms-1">차감(원금상환)</span>
				<?php endif; ?>
				<span class="text-muted fs-8 d-block"><?= number_format($b['count']) ?>건</span>
			</span>
			<span class="fw-semibold <?= $b['is_debt'] ? 'text-warning' : 'text-danger' ?>"><?= $fmtWon($b['amount']) ?></span>
		</div>
		<?php endforeach; ?>
		<div class="d-flex justify-content-between py-2 mt-1">
			<span class="fw-bold">수수료 합계 <span class="text-muted fs-8 fw-normal">(미수금 제외)</span></span>
			<span class="fw-bold text-danger"><?= $fmtWon($feeTotal) ?></span>
		</div>
		<?php if ($debtTotal > 0) : ?>
		<div class="d-flex justify-content-between py-2">
			<span class="fw-bold">미수금 차감</span>
			<span class="fw-bold text-warning"><?= $fmtWon($debtTotal) ?></span>
		</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-6">일별 내역</h3>
	</div>
	<div class="card-body pt-0">
		<?php if ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">해당 기간에 정산 완료 내역이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($rows as $row) : ?>
			<a href="<?= htmlspecialchars($detailBase . 'cycle=' . (int) $row['id'], ENT_QUOTES, 'UTF-8') ?>"
				class="border border-gray-200 rounded p-4 text-decoration-none text-gray-800">
				<div class="d-flex justify-content-between mb-2">
					<span class="fw-bold"><?= htmlspecialchars($row['settlement_date'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="badge badge-light-success fs-8">완료</span>
				</div>
				<div class="fs-7 text-gray-600 mb-1"><?= htmlspecialchars($row['platform_label'], ENT_QUOTES, 'UTF-8') ?></div>
				<div class="d-flex justify-content-between fs-7">
					<span class="text-danger">수수료 −<?= number_format((int) $row['total_fee_amount']) ?>원</span>
					<span class="fw-bold text-primary">+<?= number_format((int) $row['net_amount']) ?>원</span>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

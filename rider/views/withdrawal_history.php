<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

// 출금 신청은 매일 일어나는 이벤트가 아니라서(정산과 달리) 기본 기간을 "이번 달"처럼 좁게
// 잡으면 지난달에 넣어둔 진행 중(pending/failed) 신청이 화면에서 사라져 라이더가 헷갈릴 수
// 있다. 그래서 기본값은 최근 90일로 넉넉히 잡는다.
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-89 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}
$listFilters = ['from' => $filterFrom, 'to' => $filterTo];

$rows = $riderId > 0 ? Withdrawal::listForRider($riderId, 100, $listFilters) : [];
$sum  = $riderId > 0 ? Withdrawal::sumForRider($riderId, $listFilters) : [
    'count' => 0, 'gross' => 0, 'reserve' => 0, 'fee' => 0, 'amount' => 0,
    'completed_count' => 0, 'completed_amount' => 0,
    'pending_count' => 0, 'pending_amount' => 0,
];

$detailBase = rider_url('withdrawal/detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';

$filterUrl = rider_url('withdrawal/history');
$filterUrl .= str_contains($filterUrl, '?') ? '&' : '?';

$today = date('Y-m-d');
$rangePresets = [
    '이번 달'   => [date('Y-m-01'), date('Y-m-t')],
    '지난 달'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), $today],
    '전체'     => ['2020-01-01', $today],
];

$fmtWon = static fn (int $n): string => number_format($n) . '원';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청 내역</h2>
	</div>
	<div class="card-body pt-0">
		<form method="get" action="<?= htmlspecialchars(rider_url('withdrawal/history'), ENT_QUOTES, 'UTF-8') ?>" class="row g-2 align-items-end mb-3">
			<?php if (defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL) : ?>
			<input type="hidden" name="route" value="withdrawal/history" />
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
	</div>
</div>

<div class="card card-flush shadow-sm mb-4">
	<div class="card-body">
		<div class="fs-8 text-gray-500 mb-3"><?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?> 합계 · <?= number_format($sum['count']) ?>건</div>

		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">완료 지급액 <span class="text-muted fs-8">(<?= number_format($sum['completed_count']) ?>건)</span></span>
			<span class="fw-bold fs-6 text-primary"><?= $fmtWon($sum['completed_amount']) ?></span>
		</div>
		<?php if ($sum['pending_count'] > 0) : ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">진행 중 <span class="text-muted fs-8">(<?= number_format($sum['pending_count']) ?>건)</span></span>
			<span class="fw-semibold fs-7 text-warning"><?= $fmtWon($sum['pending_amount']) ?></span>
		</div>
		<?php endif; ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">정산수수료 합계</span>
			<span class="fw-semibold fs-7 text-danger">−<?= $fmtWon($sum['fee']) ?></span>
		</div>
		<div class="d-flex justify-content-between py-2">
			<span class="fw-bold text-gray-800">신청 총액</span>
			<span class="fs-3 fw-bold text-gray-900"><?= $fmtWon($sum['amount']) ?></span>
		</div>
	</div>
</div>

<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-6">신청 목록</h3>
	</div>
	<div class="card-body pt-0">
		<?php if ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">해당 기간에 출금 신청 내역이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($rows as $row) : ?>
			<a href="<?= htmlspecialchars($detailBase . 'id=' . (int) $row['db_id'], ENT_QUOTES, 'UTF-8') ?>" class="border border-gray-200 rounded p-4 text-decoration-none text-gray-800 d-block">
				<div class="d-flex justify-content-between mb-2">
					<span class="fw-bold">₩ <?= number_format((int) $row['amount']) ?></span>
					<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>">
						<?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?>
					</span>
				</div>
				<div class="fs-8 text-gray-600">
					신청 <?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?>
					<?php if ((int) ($row['accrued_days'] ?? 0) > 0) : ?>
					· 적립 <?= (int) $row['accrued_days'] ?>일
					<?php endif; ?>
				</div>
				<?php if ($row['completed_at'] !== '') : ?>
				<div class="fs-8 text-gray-600">완료 <?= htmlspecialchars($row['completed_at'], ENT_QUOTES, 'UTF-8') ?></div>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

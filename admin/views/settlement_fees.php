<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
$filterQ    = trim((string) ($_GET['q'] ?? ''));

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

// 페이징은 새로고침 없이 클라이언트에서 처리(assets/js/table-paginate.js) — 서버는
// 필터(기간·검색어)에 맞는 결과를 한 번에 내려주되, 브라우저 렌더 부담 방지를 위해
// 안전 상한만 둔다. 상한을 넘으면 기간을 좁히도록 안내(그런 경우는 드묾 — 기본 30일).
// SettlementLedger::listAdmin() 자체 상한(500)과 맞춘다.
const SETTLEMENT_FEES_ROW_CAP = 500;

$listError  = null;
$rows       = [];
$totalCount = 0;
$sum        = ['count' => 0, 'orders' => 0, 'gross' => 0, 'support' => 0, 'payout' => 0, 'fee' => 0, 'net' => 0];
$breakdown  = [];
$needsMigrate = !SettlementLedger::tableExists();

if (!$needsMigrate) {
    try {
        $listFilters = [
            'from' => $filterFrom,
            'to'   => $filterTo,
            'q'    => $filterQ,
        ];
        // 합계는 표시 상한과 무관하게 필터 전체를 대상으로 집계한다.
        $sum        = SettlementLedger::sumAdmin($listFilters);
        $breakdown  = SettlementLedger::feeBreakdownAdmin($listFilters);
        $totalCount = $sum['count'];
        $rows = SettlementLedger::listAdmin($listFilters + ['limit' => SETTLEMENT_FEES_ROW_CAP]);
    } catch (Throwable $e) {
        $listError = $e->getMessage();
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

$listUrl = admin_url('settlement/fees');
$detailBase = admin_url('settlement/fee-detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';

/** 기간 빠른 선택 버튼용 URL */
function settlement_fees_range_url(string $base, string $from, string $to, string $q): string
{
    $sep = str_contains($base, '?') ? '&' : '?';
    $query = array_filter(['from' => $from, 'to' => $to, 'q' => $q !== '' ? $q : null], static fn ($v) => $v !== null && $v !== '');

    return $base . $sep . http_build_query($query);
}

$today = date('Y-m-d');
$rangePresets = [
    '오늘'    => [$today, $today],
    '최근 7일'  => [date('Y-m-d', strtotime('-6 days')), $today],
    '이번 달'   => [date('Y-m-01'), date('Y-m-t')],
    '지난 달'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), $today],
];

$fmtWon = static fn (int $n): string => number_format($n) . '원';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산 수수료 내역</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">엑셀 업로드</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">업로드 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex p-5 mb-8">
		<div class="fs-7 text-gray-800">
			<strong>정산 반영</strong> 후 라이더별 수수료·실지급 내역입니다. 업로드 상세에서 「정산 반영 · 수수료·지갑」 실행 시 생성됩니다.
		</div>
	</div>
	<?php endif; ?>

	<?php if (!$needsMigrate && $listError === null) : ?>
	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/fees" />
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">정산일 from</label>
					<input type="date" class="form-control form-control-solid" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">to</label>
					<input type="date" class="form-control form-control-solid" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">라이더 검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="이름, 코드" />
				</div>
				<div class="col-md-2"><button type="submit" class="btn btn-light-primary w-100">조회</button></div>
			</form>
			<div class="d-flex flex-wrap align-items-center gap-2 mt-4 pt-4 border-top">
				<span class="text-muted fs-8 me-1">기간 바로가기</span>
				<?php foreach ($rangePresets as $label => [$pf, $pt]) :
				    $active = ($filterFrom === $pf && $filterTo === $pt);
				    ?>
				<a href="<?= htmlspecialchars(settlement_fees_range_url($listUrl, $pf, $pt, $filterQ), ENT_QUOTES, 'UTF-8') ?>"
					class="btn btn-sm py-1 px-3 fs-8 <?= $active ? 'btn-primary' : 'btn-light' ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!--begin::기간 합계-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">정산금액 합계</div>
					<div class="fw-bold fs-3 text-gray-900"><?= $fmtWon($sum['gross']) ?></div>
					<div class="text-muted fs-8 mt-1">
						<?= number_format($sum['count']) ?>건 · 오더 <?= number_format($sum['orders']) ?>건
						<?php if ($sum['support'] > 0) : ?>
						<span class="text-success d-block">+지원금 <?= $fmtWon($sum['support']) ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">수수료·차감 합계</div>
					<div class="fw-bold fs-3 text-danger">−<?= $fmtWon($sum['fee']) ?></div>
					<div class="text-muted fs-8 mt-1">아래 항목별 내역 참고</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">지갑 반영 합계</div>
					<div class="fw-bold fs-3 text-primary"><?= $fmtWon($sum['net']) ?></div>
					<div class="text-muted fs-8 mt-1">라이더 실적립액</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">조회 기간</div>
					<div class="fw-bold fs-5 text-gray-900"><?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?></div>
					<div class="fw-bold fs-5 text-gray-900">~ <?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?></div>
					<?php if ($filterQ !== '') : ?>
					<div class="text-muted fs-8 mt-1">검색: <?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<!--end::기간 합계-->

	<?php if ($breakdown !== []) : ?>
	<!--begin::항목별 합계-->
	<div class="card card-flush mb-8">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title">
				<h3 class="fw-bold m-0">상세 내역 합계 (항목별)</h3>
				<span class="text-gray-500 fs-8 fw-semibold d-block mt-1">이 기간에 발생한 수수료·차감을 종류별로 집계</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-3 mb-0">
					<thead>
						<tr class="fw-bold text-muted fs-7 bg-light">
							<th>항목</th>
							<th class="text-end min-w-90px">건수</th>
							<th class="text-end min-w-120px">합계</th>
							<th class="text-end min-w-90px">비중</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($breakdown as $b) :
						    $base = $b['is_debt'] ? $debtTotal : $feeTotal;
						    $pct  = $base > 0 ? round($b['amount'] / $base * 100, 1) : 0.0;
						    ?>
						<tr>
							<td>
								<span class="fw-semibold text-gray-800"><?= htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php if ($b['is_debt']) : ?>
								<span class="badge badge-light-warning fs-9 ms-2">차감(원금상환)</span>
								<?php endif; ?>
							</td>
							<td class="text-end text-gray-700"><?= number_format($b['count']) ?>건</td>
							<td class="text-end fw-bold <?= $b['is_debt'] ? 'text-warning' : 'text-danger' ?>"><?= $fmtWon($b['amount']) ?></td>
							<td class="text-end text-muted fs-8"><?= number_format($pct, 1) ?>%</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr class="fw-bold bg-light">
							<td>수수료 합계 <span class="text-muted fs-8 fw-normal">(미수금 제외)</span></td>
							<td></td>
							<td class="text-end text-danger"><?= $fmtWon($feeTotal) ?></td>
							<td></td>
						</tr>
						<?php if ($debtTotal > 0) : ?>
						<tr class="fw-bold">
							<td>미수금 차감</td>
							<td></td>
							<td class="text-end text-warning"><?= $fmtWon($debtTotal) ?></td>
							<td></td>
						</tr>
						<tr class="fw-bold bg-light">
							<td>총 차감 합계</td>
							<td></td>
							<td class="text-end text-gray-900"><?= $fmtWon($feeTotal + $debtTotal) ?></td>
							<td></td>
						</tr>
						<?php endif; ?>
					</tfoot>
				</table>
			</div>
		</div>
	</div>
	<!--end::항목별 합계-->
	<?php endif; ?>

	<div class="card card-flush">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title"><h3 class="fw-bold m-0">내역</h3></div>
			<div class="card-toolbar"><span class="text-muted fs-7">총 <?= number_format($totalCount) ?>건</span></div>
		</div>
		<div class="card-body pt-0">
			<?php if ($totalCount > SETTLEMENT_FEES_ROW_CAP) : ?>
			<div class="alert bg-light-warning fs-8 p-3 mb-4">
				조회 기간 내 <?= number_format($totalCount) ?>건 중 최근 <?= number_format(SETTLEMENT_FEES_ROW_CAP) ?>건만 표시합니다.
				<strong>위 합계는 표시 여부와 관계없이 기간 전체 기준</strong>이며, 목록 전체를 보려면 기간을 좁혀 조회하세요.
			</div>
			<?php endif; ?>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-4" id="settlementFeesTable">
					<thead>
						<tr class="fw-bold text-muted fs-7">
							<th>정산일</th>
							<th>라이더</th>
							<th>플랫폼</th>
							<th class="text-end">정산금액</th>
							<th class="text-end">수수료 합</th>
							<th class="text-end">지갑 반영</th>
							<th class="text-end">상세</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr data-tp-skip><td colspan="7" class="text-center text-muted py-10">내역이 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($rows as $row) : ?>
						<tr>
							<td><?= htmlspecialchars($row['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
															</td>
							<td>
								<?= htmlspecialchars($row['platform_label'], ENT_QUOTES, 'UTF-8') ?>
								<?php if (($row['team_region'] ?? '') !== '') : ?>
								<span class="text-muted fs-8 d-block"><?= htmlspecialchars($row['team_region'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php endif; ?>
							</td>
							<td class="text-end">
								<?= number_format((int) $row['gross_amount']) ?>원
								<?php if ((int) ($row['support_amount'] ?? 0) > 0) : ?>
								<span class="text-success fs-8 d-block">+지원금 <?= number_format((int) $row['support_amount']) ?>원</span>
								<?php endif; ?>
							</td>
							<td class="text-end text-danger">−<?= number_format((int) $row['total_fee_amount']) ?>원</td>
							<td class="text-end fw-bold"><?= number_format((int) $row['net_amount']) ?>원</td>
							<td class="text-end">
								<a href="<?= htmlspecialchars($detailBase . 'cycle=' . (int) $row['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">내역</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<?php if ($rows !== []) : ?>
					<?php // 이 합계는 페이징과 무관하게 조회 기간 전체 기준(sumAdmin). ?>
					<tfoot>
						<tr class="fw-bold bg-light">
							<td colspan="3">기간 합계 <span class="text-muted fs-8 fw-normal">(<?= number_format($sum['count']) ?>건 전체)</span></td>
							<td class="text-end"><?= $fmtWon($sum['gross']) ?></td>
							<td class="text-end text-danger">−<?= $fmtWon($sum['fee']) ?></td>
							<td class="text-end text-primary"><?= $fmtWon($sum['net']) ?></td>
							<td></td>
						</tr>
					</tfoot>
					<?php endif; ?>
				</table>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		var settlementFeesTable = document.getElementById('settlementFeesTable');
		if (settlementFeesTable) {
			initTablePaginate(settlementFeesTable, { pageSize: 30 });
		}
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

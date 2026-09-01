<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/RiderDebt.php';
require_once INC_PATH . '/Org.php';

// 본사(super) 또는 대리점(자기 라이더). 총판은 라우트 허용목록에서 제외됨.
$level = admin_org_level();
if ($level !== Org::LEVEL_ADMIN && $level !== Org::LEVEL_AGENCY) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">정산명세서는 본사·대리점만 조회할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$won = static fn ($n): string => number_format((int) $n) . '원';
$platLabel = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
$riderId    = (int) ($_GET['rider'] ?? 0);
$withOrders = (int) ($_GET['orders'] ?? 0) === 1;
$baseUrl    = admin_url('settlement/statement');
$qs = static fn (array $ov): string => $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?')
    . http_build_query(array_merge(['from' => $from, 'to' => $to], $ov));

$scopeAllowed = true;
if ($riderId > 0) {
    // 스코프: 대리점은 자기 라이더만
    $r = db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
    $scopeAllowed = $r !== null && Org::canAccessAgency((int) ($r['agency_id'] ?? 0));
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6 st-noprint">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산명세서</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">명세서 발급</li>
			</ul>
		</div>
		<form class="d-flex align-items-end gap-2" method="get">
			<input type="hidden" name="route" value="settlement/statement" />
			<?php if ($riderId > 0) : ?><input type="hidden" name="rider" value="<?= $riderId ?>" /><?php endif; ?>
			<div><label class="form-label fs-8 mb-1">시작</label><input type="date" name="from" value="<?= $esc($from) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<div><label class="form-label fs-8 mb-1">종료</label><input type="date" name="to" value="<?= $esc($to) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<button class="btn btn-sm btn-primary" type="submit">조회</button>
		</form>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<style>
	@media print {
		.app-sidebar, #kt_app_header, #kt_app_toolbar, .app-footer, .st-noprint { display: none !important; }
		.app-wrapper, .app-main, .app-container, #kt_app_content, #kt_app_content_container { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
		.card { border: none !important; box-shadow: none !important; }
		.st-statement { font-size: 11px; }
		body { background: #fff !important; }
	}
	.st-statement table th, .st-statement table td { padding: .35rem .5rem; }
</style>

<?php if ($riderId > 0 && $scopeAllowed) :
	// ── 명세서 모드 ─────────────────────────────────────────────
	$rider = db_row(
		'SELECT r.*, o.name AS agency_name, o.code AS agency_code
		   FROM riders r LEFT JOIN organizations o ON o.id = r.agency_id
		  WHERE r.id = ? LIMIT 1',
		[$riderId]
	);
	$f = ['from' => $from, 'to' => $to];
	$sum   = SettlementLedger::sumForRider($riderId, $f);
	$daily = db_rows(
		"SELECT settlement_date, platform, SUM(order_count) AS orders, SUM(gross_amount+support_amount) AS gross,
		        SUM(total_fee_amount) AS fee, SUM(net_amount) AS net
		   FROM settlement_rider_cycles WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?
		  GROUP BY settlement_date ORDER BY settlement_date ASC",
		[$riderId, $from, $to]
	);
	$fees  = SettlementLedger::feeBreakdownForRider($riderId, $f);
	$debts = RiderDebt::forRider($riderId, false);
	$grossTot = (int) $sum['gross'] + (int) $sum['support'];
	?>
	<div class="d-flex justify-content-end gap-2 mb-4 st-noprint">
		<a href="<?= $esc($qs(['orders' => $withOrders ? 0 : 1, 'rider' => $riderId])) ?>" class="btn btn-sm btn-light-primary"><?= $withOrders ? '건별 상세 숨기기' : '건별 상세 포함' ?></a>
		<a href="<?= $esc($qs([])) ?>" class="btn btn-sm btn-light">목록으로</a>
		<button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="ki-duotone ki-printer fs-5"><span class="path1"></span><span class="path2"></span></i> 인쇄</button>
	</div>

	<div class="card card-flush st-statement">
		<div class="card-body">
			<!--헤더-->
			<div class="d-flex flex-stack border-bottom border-gray-300 pb-4 mb-4">
				<div>
					<h2 class="fw-bold mb-1">정산명세서</h2>
					<div class="fs-7 text-gray-700"><?= $esc((string) ($rider['name'] ?? '')) ?> <span class="text-muted">(<?= $esc((string) ($rider['rider_code'] ?? '')) ?>)</span>
						· <?= (int) ($rider['is_daily_settlement'] ?? 0) === 1 ? '선정산' : '주정산' ?>
						· <?= $esc((string) ($rider['agency_name'] ?? '')) ?></div>
				</div>
				<div class="text-end fs-8 text-gray-600">
					<div>정산기간 <strong><?= $esc($from) ?> ~ <?= $esc($to) ?></strong></div>
					<div>발행일 <?= date('Y-m-d H:i') ?></div>
				</div>
			</div>

			<!--요약-->
			<div class="row g-3 mb-5">
				<?php
				$cards = [
					['총 주문', number_format($sum['orders']) . '건'],
					['정산금액', $won($grossTot)],
					['총 공제', $won($sum['fee'])],
					['실지급액', $won($sum['net'])],
				];
				foreach ($cards as $c) : ?>
				<div class="col-3"><div class="border border-gray-300 rounded p-3 text-center">
					<div class="fs-8 text-gray-500"><?= $esc($c[0]) ?></div>
					<div class="fs-5 fw-bold text-gray-900"><?= $esc($c[1]) ?></div>
				</div></div>
				<?php endforeach; ?>
			</div>

			<!--일자별-->
			<h4 class="fs-6 fw-bold mb-2">일자별 정산</h4>
			<table class="table table-bordered align-middle fs-8 mb-5">
				<thead class="bg-light fw-bold"><tr><th>정산일</th><th>플랫폼</th><th class="text-end">건수</th><th class="text-end">정산금액</th><th class="text-end">공제</th><th class="text-end">실지급</th></tr></thead>
				<tbody>
					<?php if ($daily === []) : ?><tr><td colspan="6" class="text-center text-muted py-4">해당 기간 정산 내역이 없습니다.</td></tr>
					<?php else : foreach ($daily as $d) : ?>
					<tr>
						<td><?= $esc((string) $d['settlement_date']) ?></td>
						<td><?= $esc($platLabel[(string) $d['platform']] ?? (string) $d['platform']) ?></td>
						<td class="text-end"><?= number_format((int) $d['orders']) ?></td>
						<td class="text-end"><?= $won($d['gross']) ?></td>
						<td class="text-end text-danger"><?= $won($d['fee']) ?></td>
						<td class="text-end fw-bold"><?= $won($d['net']) ?></td>
					</tr>
					<?php endforeach; endif; ?>
				</tbody>
				<?php if ($daily !== []) : ?>
				<tfoot class="fw-bold bg-light"><tr><td colspan="2">합계</td><td class="text-end"><?= number_format($sum['orders']) ?></td><td class="text-end"><?= $won($grossTot) ?></td><td class="text-end text-danger"><?= $won($sum['fee']) ?></td><td class="text-end"><?= $won($sum['net']) ?></td></tr></tfoot>
				<?php endif; ?>
			</table>

			<div class="row g-5 mb-4">
				<!--공제 내역-->
				<div class="col-md-6">
					<h4 class="fs-6 fw-bold mb-2">공제 내역</h4>
					<table class="table table-bordered align-middle fs-8">
						<thead class="bg-light fw-bold"><tr><th>항목</th><th class="text-end">건수</th><th class="text-end">금액</th></tr></thead>
						<tbody>
							<?php $feeOnly = array_filter($fees, static fn ($x) => !$x['is_debt']); if ($feeOnly === []) : ?><tr><td colspan="3" class="text-center text-muted py-3">공제 없음</td></tr>
							<?php else : foreach ($feeOnly as $x) : ?>
							<tr><td><?= $esc($x['label']) ?></td><td class="text-end"><?= number_format($x['count']) ?></td><td class="text-end text-danger"><?= $won($x['amount']) ?></td></tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
				<!--대여금·리스-->
				<div class="col-md-6">
					<h4 class="fs-6 fw-bold mb-2">대여금·리스·선지급</h4>
					<table class="table table-bordered align-middle fs-8">
						<thead class="bg-light fw-bold"><tr><th>종류</th><th>내용</th><th class="text-end">잔액</th></tr></thead>
						<tbody>
							<?php if (($debts ?? []) === []) : ?><tr><td colspan="3" class="text-center text-muted py-3">없음</td></tr>
							<?php else : foreach ($debts as $dt) : ?>
							<tr>
								<td><?= $esc((string) ($dt['kind_label'] ?? $dt['kind'] ?? '')) ?></td>
								<td class="text-muted"><?= $esc((string) ($dt['title'] ?? $dt['note'] ?? '')) ?></td>
								<td class="text-end fw-bold"><?= $won($dt['balance_amount'] ?? 0) ?></td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php if ($withOrders) :
				$orders = db_rows(
					"SELECT settlement_date, assigned_at, order_no, store_name, delivery_area, distance_m, net_amount
					   FROM settlement_order_details WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?
					  ORDER BY settlement_date ASC, assigned_at ASC LIMIT 2000",
					[$riderId, $from, $to]
				); ?>
			<h4 class="fs-6 fw-bold mb-2">건별 배달 내역 <span class="text-muted fs-8 fw-normal"><?= number_format(count($orders)) ?>건</span></h4>
			<table class="table table-bordered align-middle fs-9">
				<thead class="bg-light fw-bold"><tr><th>일자</th><th>배정시각</th><th>주문번호</th><th>매장</th><th>도착지</th><th class="text-end">거리(m)</th><th class="text-end">배달비</th></tr></thead>
				<tbody>
					<?php if ($orders === []) : ?><tr><td colspan="7" class="text-center text-muted py-3">건별 내역이 없습니다.</td></tr>
					<?php else : foreach ($orders as $od) : ?>
					<tr>
						<td><?= $esc((string) $od['settlement_date']) ?></td>
						<td><?= $esc(substr((string) ($od['assigned_at'] ?? ''), 11, 8)) ?></td>
						<td><?= $esc((string) ($od['order_no'] ?? '')) ?></td>
						<td><?= $esc((string) ($od['store_name'] ?? '')) ?></td>
						<td><?= $esc((string) ($od['delivery_area'] ?? '')) ?></td>
						<td class="text-end"><?= number_format((int) ($od['distance_m'] ?? 0)) ?></td>
						<td class="text-end"><?= $won($od['net_amount'] ?? 0) ?></td>
					</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<div class="text-center text-muted fs-9 mt-4 pt-3 border-top border-gray-300">본 명세서는 도깨비 배달 정산 시스템에서 발행되었습니다.</div>
		</div>
	</div>

<?php elseif ($riderId > 0 && !$scopeAllowed) : ?>
	<div class="alert alert-danger p-5">이 라이더의 명세서를 조회할 권한이 없습니다. <a href="<?= $esc($qs([])) ?>" class="ms-2">목록으로</a></div>

<?php else :
	// ── 목록 모드 ──────────────────────────────────────────────
	[$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
	$where  = 'c.settlement_date BETWEEN ? AND ?';
	$params = [$from, $to];
	if ($scopeSql !== '') { $where .= ' AND ' . $scopeSql; $params = array_merge($params, $scopeParams); }
	$riders = db_rows(
		"SELECT r.id, r.name, r.rider_code, r.is_daily_settlement, o.name AS agency_name,
		        COUNT(DISTINCT c.settlement_date) AS days, SUM(c.order_count) AS orders,
		        SUM(c.gross_amount + c.support_amount) AS gross, SUM(c.total_fee_amount) AS fee, SUM(c.net_amount) AS net
		   FROM settlement_rider_cycles c
		   JOIN riders r ON r.id = c.rider_id
		   LEFT JOIN organizations o ON o.id = r.agency_id
		  WHERE {$where}
		  GROUP BY r.id, r.name, r.rider_code, r.is_daily_settlement, o.name
		  ORDER BY net DESC",
		$params
	);
	?>
	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">라이더 선택 <span class="text-muted fs-8 fw-normal">· <?= $esc($from) ?> ~ <?= $esc($to) ?> 정산 있는 라이더 <?= number_format(count($riders)) ?>명</span></h3></div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-2">
					<thead><tr class="fw-bold text-muted"><th>라이더</th><th>소속</th><th>유형</th><th class="text-end">정산일수</th><th class="text-end">주문</th><th class="text-end">정산금액</th><th class="text-end">실지급</th><th class="text-end">명세서</th></tr></thead>
					<tbody>
						<?php if ($riders === []) : ?><tr><td colspan="8" class="text-center text-muted py-6">해당 기간 정산 내역이 있는 라이더가 없습니다.</td></tr>
						<?php else : foreach ($riders as $r) : ?>
						<tr>
							<td class="fw-semibold"><?= $esc((string) $r['name']) ?> <span class="text-muted fs-8"><?= $esc((string) $r['rider_code']) ?></span></td>
							<td class="text-muted"><?= $esc((string) ($r['agency_name'] ?? '')) ?></td>
							<td><span class="badge badge-light-<?= (int) $r['is_daily_settlement'] === 1 ? 'warning' : 'primary' ?>"><?= (int) $r['is_daily_settlement'] === 1 ? '선정산' : '주정산' ?></span></td>
							<td class="text-end"><?= number_format((int) $r['days']) ?>일</td>
							<td class="text-end"><?= number_format((int) $r['orders']) ?></td>
							<td class="text-end"><?= $won($r['gross']) ?></td>
							<td class="text-end fw-bold"><?= $won($r['net']) ?></td>
							<td class="text-end"><a href="<?= $esc($qs(['rider' => (int) $r['id']])) ?>" class="btn btn-sm btn-light-primary py-1 px-3">발급</a></td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

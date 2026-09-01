<?php

declare(strict_types=1);

require_once INC_PATH . '/Statistics.php';

// 본사(super) 전용 — 라우트에서도 막지만 화면에서도 한 번 더.
if (!admin_has_role('super')) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">통계는 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

// 기간 — 기본: 올해 1/1 ~ 오늘.
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-01-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }

$ov       = Statistics::overview($from, $to);
$trend    = Statistics::monthlyTrend(12);
$hqIncome = Statistics::hqIncomeByType($from, $to);
$platform = Statistics::platformMix($from, $to);
$topSet   = Statistics::topAgenciesBySettlement($from, $to, 10);
$topRider = Statistics::topAgenciesByRiders(10);
$comp     = Statistics::riderComposition();
$wd       = Statistics::withdrawals($from, $to);
$bal      = Statistics::balances();

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$won = static fn ($n): string => number_format((int) $n) . '원';
$baseUrl = admin_url('system/statistics');

// 축약(억/만) — 카드 표기용
$wonShort = static function ($n): string {
    $n = (int) $n; $sign = $n < 0 ? '-' : ''; $a = abs($n);
    if ($a >= 100000000) { $v = $a / 100000000; return $sign . (abs($v - round($v)) < 0.05 ? (string) round($v) : number_format($v, 1)) . '억'; }
    if ($a >= 10000) { return $sign . number_format(round($a / 10000)) . '만'; }
    return $sign . number_format($a);
};

$data = [
    'trend'    => $trend,
    'hqIncome' => ['labels' => array_column($hqIncome, 'label'), 'values' => array_map('intval', array_column($hqIncome, 'amount'))],
    'platform' => ['labels' => array_column($platform, 'label'), 'values' => array_map('intval', array_column($platform, 'amount'))],
    'comp'     => $comp,
    'topSet'   => ['labels' => array_column($topSet, 'name'), 'values' => array_map('intval', array_column($topSet, 'net'))],
    'topRider' => ['labels' => array_column($topRider, 'name'), 'values' => array_map('intval', array_column($topRider, 'cnt'))],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">통계</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">전사 통계</li>
			</ul>
		</div>
		<form class="d-flex align-items-end gap-2" method="get">
			<input type="hidden" name="route" value="system/statistics" />
			<div><label class="form-label fs-8 mb-1">시작</label><input type="date" name="from" value="<?= $esc($from) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<div><label class="form-label fs-8 mb-1">종료</label><input type="date" name="to" value="<?= $esc($to) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<button class="btn btn-sm btn-primary" type="submit">조회</button>
		</form>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="text-muted fs-8 mb-4">기간 집계(정산·수수료수입·출금·플랫폼·대리점순위)는 <strong><?= $esc($from) ?> ~ <?= $esc($to) ?></strong> 기준, 라이더·조직·지갑·예수금은 현재 상태입니다.</div>

	<!--begin::KPI-->
	<div class="row g-4 g-xl-6 mb-6">
		<?php
		$kpis = [
			['활성 라이더', number_format($ov['riders_active']) . '명', '전체 ' . number_format($ov['riders_total']) . '명 · 선정산 ' . number_format($ov['riders_daily']), 'primary', 'ki-people'],
			['총판 / 대리점', number_format($ov['distributors']) . ' / ' . number_format($ov['agencies']), '활성 조직', 'info', 'ki-abstract-26'],
			['기간 정산액', $wonShort($ov['settle_net']) . '원', number_format($ov['settle_orders']) . '건', 'success', 'ki-chart-simple'],
			['본사 수수료 수입', $wonShort($ov['hq_income']) . '원', '기간 · 정산/대행/플랫폼/이체/리스', 'warning', 'ki-dollar'],
			['대리점 지갑 잔액', $wonShort($bal['agency_balance']) . '원', '현재', 'dark', 'ki-wallet'],
			['라이더 지갑 잔액', $wonShort($bal['rider_balance']) . '원', '현재(미출금)', 'primary', 'ki-wallet'],
			['원천세 예수금', $wonShort($bal['withholding_reserve']) . '원', '대리점 보관', 'info', 'ki-shield'],
			['고용·산재 예수금', $wonShort($bal['insurance_reserve']) . '원', '세무대리 수집 대상', 'danger', 'ki-shield-tick'],
		];
		foreach ($kpis as $k) : ?>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100"><div class="card-body d-flex align-items-center py-5">
				<span class="symbol symbol-45px me-4"><span class="symbol-label bg-light-<?= $k[3] ?>"><i class="ki-duotone <?= $k[4] ?> fs-2x text-<?= $k[3] ?>"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span></span>
				<div>
					<div class="fs-6 fw-bold text-gray-900"><?= $esc($k[1]) ?></div>
					<div class="fs-8 fw-semibold text-gray-500"><?= $esc($k[0]) ?></div>
					<div class="fs-9 text-muted"><?= $esc($k[2]) ?></div>
				</div>
			</div></div>
		</div>
		<?php endforeach; ?>
	</div>
	<!--end::KPI-->

	<div class="row g-4 g-xl-6 mb-6">
		<div class="col-xl-8">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">월별 추이 <span class="text-muted fs-8 fw-normal">최근 12개월 · 정산액/출금액</span></h3></div>
				<div class="card-body pt-2"><div id="st_trend" style="height:320px"></div></div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">본사 수수료 수입 구성</h3></div>
				<div class="card-body pt-2 d-flex flex-column">
					<div id="st_income" style="min-height:230px"></div>
					<?php if ($hqIncome === []) : ?><div class="text-center text-muted fs-8 mt-3">기간 내 본사 수입이 없습니다.</div><?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-4 g-xl-6 mb-6">
		<div class="col-xl-4">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">플랫폼별 정산 분포</h3></div>
				<div class="card-body pt-2"><div id="st_platform" style="min-height:230px"></div><?php if ($platform === []) : ?><div class="text-center text-muted fs-8">데이터 없음</div><?php endif; ?></div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">라이더 정산 유형</h3></div>
				<div class="card-body pt-2"><div id="st_comp" style="min-height:230px"></div></div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">출금 유형 <span class="text-muted fs-8 fw-normal">기간</span></h3></div>
				<div class="card-body pt-2">
					<table class="table table-row-bordered align-middle fs-7 gy-2 mb-2">
						<thead><tr class="fw-bold text-muted"><th>유형</th><th class="text-end">금액</th><th class="text-end">건수</th></tr></thead>
						<tbody>
							<?php if ($wd['by_kind'] === []) : ?><tr><td colspan="3" class="text-center text-muted py-4">출금 없음</td></tr>
							<?php else : foreach ($wd['by_kind'] as $r) : ?>
							<tr><td class="fw-semibold"><?= $esc($r['label']) ?></td><td class="text-end fw-bold"><?= $won($r['amount']) ?></td><td class="text-end text-muted"><?= number_format($r['count']) ?>건</td></tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
					<div class="d-flex flex-wrap gap-2">
						<?php foreach ($wd['by_status'] as $s) :
							$cls = ['completed' => 'success', 'pending' => 'warning', 'failed' => 'danger', 'rejected' => 'secondary'][$s['status']] ?? 'light'; ?>
						<span class="badge badge-light-<?= $cls ?> fs-8"><?= $esc($s['label']) ?> <?= number_format($s['count']) ?></span>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-4 g-xl-6 mb-6">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점 정산액 TOP 10 <span class="text-muted fs-8 fw-normal">기간</span></h3></div>
				<div class="card-body pt-2"><div id="st_topset" style="min-height:340px"></div><?php if ($topSet === []) : ?><div class="text-center text-muted fs-8">데이터 없음</div><?php endif; ?></div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점 활성 라이더 TOP 10</h3></div>
				<div class="card-body pt-2"><div id="st_toprider" style="min-height:340px"></div><?php if ($topRider === []) : ?><div class="text-center text-muted fs-8">데이터 없음</div><?php endif; ?></div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>;
		var charts = [];
		function cssVar(n, f) { try { if (typeof KTUtil !== 'undefined' && KTUtil.getCssVariableValue) { var v = KTUtil.getCssVariableValue(n); if (v) return v; } } catch (e) {} return f; }
		function won(n) { n = Number(n) || 0; var s = n < 0 ? '-' : '', a = Math.abs(n);
			if (a >= 1e8) { var e = a / 1e8; return s + '₩' + (Math.abs(e - Math.round(e)) < 0.05 ? Math.round(e) : e.toFixed(1)) + '억'; }
			if (a >= 1e4) { return s + '₩' + Math.round(a / 1e4).toLocaleString('ko-KR') + '만'; }
			return s + '₩' + a.toLocaleString('ko-KR'); }

		function mk(id, opts) {
			var el = document.getElementById(id);
			if (!el) return;
			var c = new ApexCharts(el, opts);
			c.render();
			charts.push(c);
		}

		function render() {
			charts.forEach(function (c) { try { c.destroy(); } catch (e) {} });
			charts = [];
			var label = cssVar('--bs-gray-500', '#99A1B7');
			var primary = cssVar('--bs-primary', '#009EF7'), success = cssVar('--bs-success', '#50CD89'),
				warning = cssVar('--bs-warning', '#FFC700'), info = cssVar('--bs-info', '#7239EA'),
				danger = cssVar('--bs-danger', '#F1416C'), gray = cssVar('--bs-gray-400', '#B5B5C3');
			var palette = [primary, success, warning, info, danger, gray];

			// 월별 추이 — 정산 area + 출금 column
			mk('st_trend', {
				series: [{ name: '정산액', type: 'area', data: DATA.trend.net }, { name: '출금액', type: 'column', data: DATA.trend.withdraw }],
				chart: { fontFamily: 'inherit', height: 320, type: 'line', toolbar: { show: false } },
				colors: [primary, warning], stroke: { curve: 'smooth', width: [3, 0] },
				fill: { type: ['gradient', 'solid'], gradient: { opacityFrom: 0.35, opacityTo: 0 } },
				plotOptions: { bar: { columnWidth: '38%', borderRadius: 3 } },
				dataLabels: { enabled: false }, legend: { show: true, labels: { colors: label } },
				xaxis: { categories: DATA.trend.labels, labels: { style: { colors: label, fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
				yaxis: { labels: { style: { colors: label }, formatter: function (v) { return won(v); } } },
				tooltip: { y: { formatter: function (v) { return won(v); } } }
			});

			pie('st_income', DATA.hqIncome, palette);
			pie('st_platform', DATA.platform, [success, primary, gray]);
			pie('st_comp', { labels: ['선정산', '주정산'], values: [DATA.comp.daily, DATA.comp.weekly] }, [warning, primary]);
			hbar('st_topset', DATA.topSet, primary, true);
			hbar('st_toprider', DATA.topRider, info, false);

			function pie(id, d, colors) {
				if (!d.labels.length) return;
				mk(id, {
					series: d.values, labels: d.labels, colors: colors,
					chart: { type: 'donut', height: 240, fontFamily: 'inherit' },
					legend: { position: 'bottom', labels: { colors: label } }, dataLabels: { enabled: true, formatter: function (v) { return v.toFixed(0) + '%'; } },
					tooltip: { y: { formatter: function (v) { return won(v); } } },
					plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: '합계', formatter: function (w) { return won(w.globals.seriesTotals.reduce(function (a, b) { return a + b; }, 0)); } } } } } }
				});
			}
			function hbar(id, d, color, money) {
				if (!d.labels.length) return;
				mk(id, {
					series: [{ name: money ? '정산액' : '라이더 수', data: d.values }],
					chart: { type: 'bar', height: 340, fontFamily: 'inherit', toolbar: { show: false } },
					colors: [color], plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
					dataLabels: { enabled: true, formatter: function (v) { return money ? won(v) : (v + '명'); }, style: { fontSize: '10px', colors: [label] }, offsetX: 30 },
					xaxis: { categories: d.labels, labels: { style: { colors: label }, formatter: function (v) { return money ? won(v) : v; } } },
					yaxis: { labels: { style: { colors: label, fontSize: '11px' } } },
					tooltip: { y: { formatter: function (v) { return money ? won(v) : (v + '명'); } } }
				});
			}
		}

		function boot() { if (typeof ApexCharts === 'undefined') return; render();
			try { if (typeof KTThemeMode !== 'undefined' && KTThemeMode.on) KTThemeMode.on('kt.thememode.change', render); } catch (e) {} }
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

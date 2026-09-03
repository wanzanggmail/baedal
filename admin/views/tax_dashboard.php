<?php

declare(strict_types=1);

require_once INC_PATH . '/TaxAgent.php';

$needsMigrate = !TaxAgent::ready();
$won   = static fn ($n): string => number_format((int) $n) . '원';
$esc   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$apiUrl = ADMIN_BASE . '/api/tax_collect.php';
$exportUrl = ADMIN_BASE . '/api/tax_export.php';

$months = $needsMigrate ? [] : TaxAgent::months();
// 기본 선택월 — 미수집 남은 최신월, 없으면 최신월.
$period = '';
foreach ($months as $m) {
    if ((int) $m['uncollected'] > 0) { $period = (string) $m['period']; break; }
}
if ($period === '' && $months !== []) { $period = (string) $months[0]['period']; }
if ($period === '') { $period = date('Y-m'); }

$agencies    = $needsMigrate ? [] : TaxAgent::agencySummary($period);
$collectible = $needsMigrate ? 0 : TaxAgent::collectibleForPeriod($period);
$walletBal   = $needsMigrate ? 0 : TaxAgent::walletBalance();
$history     = $needsMigrate ? [] : TaxAgent::history(50);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">원천세 예수금</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">세무대리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">예수금 수집</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="tax_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="tax_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="alert bg-light-primary d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			각 대리점이 라이더 정산에서 걷은 원천세 예수금을 <strong>정산 귀속월 단위</strong>로 세무대리 지갑으로 가져와 그 달로 신고·납입합니다.
			「수집」하면 대리점 지갑에서 그 달 미수집분만 빠져 세무대리 지갑으로 들어옵니다.
		</div>
	</div>

	<!--begin::KPI + 월 선택-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-md-4">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-2">정산 귀속월</div>
				<select class="form-select form-select-solid" id="tax_period">
					<?php foreach ($months as $m) : ?>
					<option value="<?= $esc((string) $m['period']) ?>"<?= $m['period'] === $period ? ' selected' : '' ?>>
						<?= $esc((string) $m['period']) ?> · 미수집 <?= $won($m['uncollected']) ?><?= (int) $m['uncollected'] === 0 ? ' (완료)' : '' ?>
					</option>
					<?php endforeach; ?>
					<?php if ($months === []) : ?><option value="<?= $esc($period) ?>"><?= $esc($period) ?></option><?php endif; ?>
				</select>
			</div></div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-primary border-dashed"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">선택월 미수집(합계)</div>
				<div class="fw-bold fs-2 text-primary" id="tax_collectible"><?= $won($collectible) ?></div>
				<button type="button" class="btn btn-sm btn-primary mt-2" id="tax_collect_all">이 달 전체 수집</button>
			</div></div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">세무대리 지갑 잔액</div>
				<div class="fw-bold fs-2 text-gray-900" id="tax_wallet"><?= $won($walletBal) ?></div>
			</div></div>
		</div>
	</div>
	<!--end::KPI-->

	<div class="card card-flush mb-8">
		<div class="card-header pt-5 flex-wrap gap-2"><h3 class="card-title fw-bold">대리점별 원천세 (<span id="tax_period_label"><?= $esc($period) ?></span>월분)</h3><div class="card-toolbar"><a href="#" id="tax_export_btn" class="btn btn-sm btn-light-success" data-base="<?= $esc($exportUrl) ?>"><i class="ki-duotone ki-file-down fs-5"><span class="path1"></span><span class="path2"></span></i> 엑셀 다운로드</a></div></div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th>대리점</th>
							<th class="text-end">걷힌 합계</th>
							<th class="text-end">이미 수집</th>
							<th class="text-end">미수집</th>
							<th class="text-center">수집</th>
						</tr>
					</thead>
					<tbody id="tax_tbody"><?php require __DIR__ . '/_tax_rows.php'; ?></tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">수집 이력</h3></div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-2">
					<thead><tr class="fw-bold text-muted"><th>귀속월</th><th>대리점</th><th class="text-end">금액</th><th>수집일시</th></tr></thead>
					<tbody id="tax_history">
						<?php if ($history === []) : ?>
						<tr><td colspan="4" class="text-center text-muted py-6">수집 이력이 없습니다.</td></tr>
						<?php else : foreach ($history as $h) : ?>
						<tr>
							<td><?= $esc((string) $h['period']) ?></td>
							<td><?= $esc((string) ($h['agency_name'] ?? '—')) ?></td>
							<td class="text-end fw-bold"><?= $won($h['amount']) ?></td>
							<td class="text-muted"><?= $esc((string) $h['collected_at']) ?></td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('tax_toast');
		var toastMsg = document.getElementById('tax_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }
		function period() { return document.getElementById('tax_period').value || ''; }

		function renderRows(agencies) {
			var tb = document.getElementById('tax_tbody');
			if (!agencies || agencies.length === 0) {
				tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-6">이 달에 걷힌 원천세가 없습니다.</td></tr>';
				return;
			}
			tb.innerHTML = agencies.map(function (a) {
				var btn = a.uncollected > 0
					? '<button type="button" class="btn btn-sm btn-light-primary tax-collect-one" data-agency="' + a.agency_id + '" data-name="' + String(a.agency_name).replace(/"/g, '&quot;') + '">수집</button>'
					: '<span class="badge badge-light-success fs-8">완료</span>';
				return '<tr><td class="fw-semibold">' + a.agency_name + ' <span class="text-muted fs-8">' + a.code + '</span></td>' +
					'<td class="text-end fw-semibold">' + won(a.accrued) + '</td>' +
					'<td class="text-end text-muted">' + won(a.collected) + '</td>' +
					'<td class="text-end fw-bold' + (a.uncollected > 0 ? ' text-primary' : '') + '">' + won(a.uncollected) + '</td>' +
					'<td class="text-center">' + btn + '</td></tr>';
			}).join('');
		}
		function renderMonths(months, sel) {
			var s = document.getElementById('tax_period');
			s.innerHTML = (months || []).map(function (m) {
				return '<option value="' + m.period + '"' + (m.period === sel ? ' selected' : '') + '>' + m.period + ' · 미수집 ' + won(m.uncollected) + (m.uncollected === 0 ? ' (완료)' : '') + '</option>';
			}).join('') || ('<option value="' + sel + '">' + sel + '</option>');
		}
		function apply(d) {
			document.getElementById('tax_collectible').textContent = won(d.collectible);
			document.getElementById('tax_wallet').textContent = won(d.wallet_balance);
			document.getElementById('tax_period_label').textContent = d.period;
			renderRows(d.agencies);
			if (d.months) { renderMonths(d.months, d.period); }
			if (d.history) {
				document.getElementById('tax_history').innerHTML = d.history.length
					? d.history.map(function (h) { return '<tr><td>' + h.period + '</td><td>' + (h.agency_name || '—') + '</td><td class="text-end fw-bold">' + won(h.amount) + '</td><td class="text-muted">' + h.collected_at + '</td></tr>'; }).join('')
					: '<tr><td colspan="4" class="text-center text-muted py-6">수집 이력이 없습니다.</td></tr>';
			}
		}
		function load(p) {
			fetch(API + '?period=' + encodeURIComponent(p || period()), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) { if (d.ok) apply(d); })
				.catch(function () {});
		}
		function collect(agencyId, label) {
			if (!confirm(period() + '월분 ' + label + ' 예수금을 세무대리 지갑으로 수집할까요?')) { return; }
			var payload = { action: 'collect', period: period() };
			if (agencyId) { payload.agency_id = agencyId; }
			fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (!res.ok) throw new Error(res.message || '수집 실패'); showToast(res.message, true); apply(res); })
				.catch(function (e) { showToast(e.message || '수집 실패', false); });
		}

		document.getElementById('tax_period').addEventListener('change', function () { load(); });
		document.getElementById('tax_export_btn').addEventListener('click', function (ev) {
			ev.preventDefault();
			window.location = this.getAttribute('data-base') + '?period=' + encodeURIComponent(period());
		});
		document.getElementById('tax_collect_all').addEventListener('click', function () { collect(null, '전체 대리점'); });
		document.getElementById('tax_tbody').addEventListener('click', function (ev) {
			var b = ev.target.closest('.tax-collect-one');
			if (b) { collect(Number(b.getAttribute('data-agency')), b.getAttribute('data-name') || '대리점'); }
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

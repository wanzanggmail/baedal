<?php

declare(strict_types=1);

require_once INC_PATH . '/TaxAgent.php';

$needsMigrate = !TaxAgent::ready();
$won   = static fn ($n): string => number_format((int) $n) . '원';
$esc   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$apiUrl = ADMIN_BASE . '/api/tax_collect.php';

$agencies    = $needsMigrate ? [] : TaxAgent::agencySummary();
$collectible = $needsMigrate ? 0 : TaxAgent::collectibleTotal();
$walletBal   = $needsMigrate ? 0 : TaxAgent::walletBalance();
$history     = $needsMigrate ? [] : TaxAgent::history(50);
$thisMonth   = date('Y-m');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">고용·산재 예수금</h1>
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
			각 대리점이 라이더 정산에서 걷어 <strong>지갑에 보관 중인 고용·산재 예수금</strong>을 세무대리 지갑으로 가져와 신고·납입합니다.
			「수집」하면 대리점 지갑에서 빠져 세무대리 지갑으로 들어옵니다.
		</div>
	</div>

	<!--begin::KPI-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-primary border-dashed"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">수집 대상 예수금(합계)</div>
				<div class="fw-bold fs-2 text-primary" id="tax_collectible"><?= $won($collectible) ?></div>
			</div></div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">세무대리 지갑 잔액</div>
				<div class="fw-bold fs-2 text-gray-900" id="tax_wallet"><?= $won($walletBal) ?></div>
			</div></div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-2">전체 수집</div>
				<div class="d-flex gap-2">
					<input type="month" class="form-control form-control-solid form-control-sm" id="tax_period" value="<?= $esc($thisMonth) ?>" style="max-width:150px" />
					<button type="button" class="btn btn-sm btn-primary" id="tax_collect_all">전체 수집</button>
				</div>
				<div class="form-text fs-9">예수금이 있는 모든 대리점에서 한 번에 가져옵니다.</div>
			</div></div>
		</div>
	</div>
	<!--end::KPI-->

	<div class="card card-flush mb-8">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점별 고용·산재 예수금</h3></div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th>대리점</th>
							<th class="text-end">누계 고용</th>
							<th class="text-end">누계 산재</th>
							<th class="text-end">수집 대상 예수금</th>
							<th class="text-center">수집</th>
						</tr>
					</thead>
					<tbody id="tax_tbody">
						<?php if ($agencies === []) : ?>
						<tr><td colspan="5" class="text-center text-muted py-6">대리점이 없습니다.</td></tr>
						<?php else : foreach ($agencies as $a) : ?>
						<tr data-agency="<?= (int) $a['agency_id'] ?>">
							<td class="fw-semibold"><?= $esc($a['agency_name']) ?> <span class="text-muted fs-8"><?= $esc($a['code']) ?></span></td>
							<td class="text-end text-gray-600"><?= $won($a['accrued_employment']) ?></td>
							<td class="text-end text-gray-600"><?= $won($a['accrued_accident']) ?></td>
							<td class="text-end fw-bold reserve-cell"><?= $won($a['reserve']) ?></td>
							<td class="text-center">
								<?php if ((int) $a['reserve'] > 0) : ?>
								<button type="button" class="btn btn-sm btn-light-primary tax-collect-one" data-agency="<?= (int) $a['agency_id'] ?>" data-name="<?= $esc($a['agency_name']) ?>">수집</button>
								<?php else : ?>
								<span class="text-muted fs-8">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
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

		function rerender(d) {
			document.getElementById('tax_collectible').textContent = won(d.collectible);
			document.getElementById('tax_wallet').textContent = won(d.wallet_balance);
			// 대리점 표 갱신
			var tb = document.getElementById('tax_tbody');
			if (d.agencies) {
				tb.innerHTML = d.agencies.map(function (a) {
					var btn = a.reserve > 0
						? '<button type="button" class="btn btn-sm btn-light-primary tax-collect-one" data-agency="' + a.agency_id + '" data-name="' + a.agency_name.replace(/"/g, '&quot;') + '">수집</button>'
						: '<span class="text-muted fs-8">—</span>';
					return '<tr data-agency="' + a.agency_id + '"><td class="fw-semibold">' + a.agency_name + ' <span class="text-muted fs-8">' + a.code + '</span></td>' +
						'<td class="text-end text-gray-600">' + won(a.accrued_employment) + '</td>' +
						'<td class="text-end text-gray-600">' + won(a.accrued_accident) + '</td>' +
						'<td class="text-end fw-bold">' + won(a.reserve) + '</td>' +
						'<td class="text-center">' + btn + '</td></tr>';
				}).join('') || '<tr><td colspan="5" class="text-center text-muted py-6">대리점이 없습니다.</td></tr>';
			}
			if (d.history) {
				var hb = document.getElementById('tax_history');
				hb.innerHTML = d.history.map(function (h) {
					return '<tr><td>' + h.period + '</td><td>' + (h.agency_name || '—') + '</td><td class="text-end fw-bold">' + won(h.amount) + '</td><td class="text-muted">' + h.collected_at + '</td></tr>';
				}).join('') || '<tr><td colspan="4" class="text-center text-muted py-6">수집 이력이 없습니다.</td></tr>';
			}
		}

		function collect(agencyId, label) {
			if (!confirm(label + ' 예수금을 세무대리 지갑으로 수집할까요?')) { return; }
			var payload = { action: 'collect', period: period() };
			if (agencyId) { payload.agency_id = agencyId; }
			fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '수집 실패');
					showToast(res.message, true);
					rerender(res);
				})
				.catch(function (e) { showToast(e.message || '수집 실패', false); });
		}

		document.getElementById('tax_collect_all').addEventListener('click', function () { collect(null, '전체 대리점'); });
		document.getElementById('tax_tbody').addEventListener('click', function (ev) {
			var b = ev.target.closest('.tax-collect-one');
			if (b) { collect(Number(b.getAttribute('data-agency')), b.getAttribute('data-name') || '대리점'); }
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

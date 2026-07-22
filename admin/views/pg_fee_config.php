<?php

declare(strict_types=1);

require_once INC_PATH . '/PgFeeConfig.php';

$apiUrl = ADMIN_BASE . '/api/pg_fee_config.php';
$needsMigrate = !PgFeeConfig::tableExists();
$rows = $needsMigrate ? [] : PgFeeConfig::listAll();

$levelLabel = ['admin' => '본사', 'distributor' => '총판', 'agency' => '대리점'];
$hqPct = 0.0;
foreach ($rows as $r) {
    if ((string) $r['level'] === 'admin') { $hqPct = (float) $r['pct']; break; }
}
// 총판별 요율 맵(대리점 총계 계산용)
$distPct = [];
foreach ($rows as $r) {
    if ((string) $r['level'] === 'distributor') { $distPct[(int) $r['id']] = (float) $r['pct']; }
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">영업대행수수료 분배</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">영업대행수수료</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="pf_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="pf_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="alert bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-percentage fs-2hx text-primary me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			대리점 PG 결제 시 붙는 <strong>영업대행수수료</strong>를 본사·총판·대리점이 나눠 갖습니다.
			어떤 대리점 결제의 <strong>총 요율 = 대리점 몫 + 상위 총판 몫 + 본사 몫</strong>입니다.
			<span class="badge badge-light-warning ms-1">기본 각 1% (임시값 — 확정 대기)</span>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">조직별 요율</h3></div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead><tr class="fw-bold text-muted">
						<th>구분</th><th>조직</th><th>상위</th><th style="width:160px">내 몫(%)</th><th class="text-end">대리점 총 요율</th><th style="width:80px"></th>
					</tr></thead>
					<tbody>
						<?php foreach ($rows as $r) :
							$lvl = (string) $r['level'];
							$total = null;
							if ($lvl === 'agency') {
								$total = (float) $r['pct'] + ($distPct[(int) $r['parent_id']] ?? 0.0) + $hqPct;
							}
						?>
						<tr data-org="<?= (int) $r['id'] ?>">
							<td><span class="badge badge-light-<?= $lvl === 'admin' ? 'dark' : ($lvl === 'distributor' ? 'info' : 'success') ?>"><?= $levelLabel[$lvl] ?? $lvl ?></span></td>
							<td class="fw-bold"><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-muted"><?= htmlspecialchars((string) ($r['parent_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
							<td><input type="number" class="form-control form-control-sm form-control-solid pf-pct" step="0.01" min="0" max="100" value="<?= number_format((float) $r['pct'], 2) ?>" /></td>
							<td class="text-end fw-bold"><?= $total !== null ? number_format($total, 2) . '%' : '—' ?></td>
							<td><button type="button" class="btn btn-sm btn-light-primary pf-save">저장</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('pf_toast'), toastMsg = document.getElementById('pf_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); }
		document.querySelectorAll('.pf-save').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var tr = btn.closest('tr');
				var orgId = parseInt(tr.getAttribute('data-org'), 10);
				var pct = parseFloat(tr.querySelector('.pf-pct').value) || 0;
				btn.disabled = true;
				fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ action: 'save', org_id: orgId, pct: pct }) })
					.then(function (r) { return r.json(); })
					.then(function (res) { if (!res.ok) throw new Error(res.message); showToast(res.message, true); setTimeout(function () { location.reload(); }, 700); })
					.catch(function (e) { showToast(e.message, false); btn.disabled = false; });
			});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

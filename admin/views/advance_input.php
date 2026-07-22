<?php

declare(strict_types=1);

$apiUrl   = ADMIN_BASE . '/api/advance_entry.php';
$riderApi = ADMIN_BASE . '/api/riders.php';
$canWrite = admin_can_write('deduction');
$needsMigrate = !db_table_exists('deduction_entries');

$recent = [];
if (!$needsMigrate) {
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    $where = ["de.kind = 'advance'"];
    if ($scopeSql !== '') { $where[] = $scopeSql; }
    $recent = db_rows(
        "SELECT de.id, de.applied_date, de.amount, de.note, r.name AS rider_name, r.rider_code
           FROM deduction_entries de INNER JOIN riders r ON r.id = de.rider_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY de.applied_date DESC, de.id DESC LIMIT 100",
        $scopeParams
    );
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">선지급(대여금) 입력</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">선지급 입력</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="av_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="av_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<?php if ($canWrite) : ?>
		<div class="col-xl-5">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">선지급 입력</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="alert bg-light-warning text-gray-800 fs-8 p-4 mb-5">선지급(대여금)은 해당 <strong>정산일</strong>의 라이더 정산에서 <strong>차감</strong>됩니다.</div>
					<div class="mb-3">
						<label class="form-label required">라이더 검색</label>
						<div class="input-group">
							<input type="text" class="form-control form-control-solid" id="av_rider_q" placeholder="이름/코드 검색" />
							<button class="btn btn-light-primary" type="button" id="av_rider_search">검색</button>
						</div>
						<select class="form-select form-select-solid mt-2 d-none" id="av_rider_sel" size="4"></select>
						<input type="hidden" id="av_rider_id" />
						<div class="form-text" id="av_rider_picked"></div>
					</div>
					<div class="mb-3">
						<label class="form-label required">선지급 금액 (원)</label>
						<input type="number" class="form-control form-control-solid" id="av_amount" min="1" step="1000" />
					</div>
					<div class="mb-3">
						<label class="form-label required">정산일(차감일)</label>
						<input type="date" class="form-control form-control-solid" id="av_date" value="<?= date('Y-m-d') ?>" />
					</div>
					<div class="mb-4">
						<label class="form-label">메모</label>
						<input type="text" class="form-control form-control-solid" id="av_note" maxlength="255" placeholder="예: 장비 대여금" />
					</div>
					<button type="button" class="btn btn-primary" id="av_submit">선지급 등록</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="col-xl-<?= $canWrite ? '7' : '12' ?>">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">최근 선지급 내역</h3></div>
				<div class="card-body pt-2">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle fs-7 gy-3">
							<thead><tr class="fw-bold text-muted">
								<th>정산일</th><th>라이더</th><th class="text-end">금액</th><th>메모</th><?php if ($canWrite) : ?><th></th><?php endif; ?>
							</tr></thead>
							<tbody id="av_tbody">
								<?php if ($recent === []) : ?>
								<tr><td colspan="5" class="text-center text-muted py-6">선지급 내역이 없습니다.</td></tr>
								<?php else : foreach ($recent as $r) : ?>
								<tr data-id="<?= (int) $r['id'] ?>">
									<td class="text-muted"><?= htmlspecialchars((string) $r['applied_date'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= htmlspecialchars((string) $r['rider_name'], ENT_QUOTES, 'UTF-8') ?> <span class="text-muted">(<?= htmlspecialchars((string) $r['rider_code'], ENT_QUOTES, 'UTF-8') ?>)</span></td>
									<td class="text-end fw-bold"><?= number_format((int) $r['amount']) ?>원</td>
									<td class="text-muted"><?= htmlspecialchars((string) $r['note'], ENT_QUOTES, 'UTF-8') ?></td>
									<?php if ($canWrite) : ?><td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-light-danger av-del">×</button></td><?php endif; ?>
								</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var RIDER_API = <?= json_encode($riderApi, JSON_UNESCAPED_UNICODE) ?>;
		var canWrite = <?= $canWrite ? 'true' : 'false' ?>;
		var toast = document.getElementById('av_toast'), toastMsg = document.getElementById('av_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); }
		function post(p) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(p) }).then(function (r) { return r.json(); }); }

		if (canWrite) {
			document.getElementById('av_rider_search').addEventListener('click', function () {
				var q = document.getElementById('av_rider_q').value.trim();
				fetch(RIDER_API + '?q=' + encodeURIComponent(q) + '&limit=20', { credentials: 'same-origin' })
					.then(function (r) { return r.json(); }).then(function (res) {
						var sel = document.getElementById('av_rider_sel');
						sel.innerHTML = '';
						(res.items || []).forEach(function (it) {
							var o = document.createElement('option');
							o.value = it.id; o.textContent = it.name + ' (' + it.rider_code + ')';
							sel.appendChild(o);
						});
						sel.classList.toggle('d-none', (res.items || []).length === 0);
					});
			});
			document.getElementById('av_rider_sel').addEventListener('change', function () {
				document.getElementById('av_rider_id').value = this.value;
				document.getElementById('av_rider_picked').textContent = '선택: ' + this.options[this.selectedIndex].textContent;
			});
			document.getElementById('av_submit').addEventListener('click', function () {
				var rid = parseInt(document.getElementById('av_rider_id').value, 10) || 0;
				if (!rid) { showToast('라이더를 선택하세요.', false); return; }
				post({ action: 'create', rider_id: rid, amount: parseInt(document.getElementById('av_amount').value, 10) || 0, applied_date: document.getElementById('av_date').value, note: document.getElementById('av_note').value })
					.then(function (res) { if (!res.ok) throw new Error(res.message); showToast(res.message, true); setTimeout(function () { location.reload(); }, 800); })
					.catch(function (e) { showToast(e.message, false); });
			});
			document.querySelectorAll('.av-del').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (!confirm('이 선지급 내역을 삭제할까요?')) return;
					var id = parseInt(btn.closest('tr').getAttribute('data-id'), 10);
					post({ action: 'delete', id: id }).then(function (res) { if (!res.ok) throw new Error(res.message); showToast(res.message, true); btn.closest('tr').remove(); }).catch(function (e) { showToast(e.message, false); });
				});
			});
		}
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

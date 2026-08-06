<?php

declare(strict_types=1);

require_once INC_PATH . '/RolePermission.php';

$apiUrl       = ADMIN_BASE . '/api/role_permissions.php';
$needsMigrate = !RolePermission::tableReady();
$grid         = RolePermission::all();
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">권한 관리</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">권한 관리</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>최고 관리자</strong>와 <strong>총괄 관리자</strong>는 항상 소속 범위의 모든 화면에 접근·저장할 수 있어 이 표에 없습니다(총괄 관리자는 시스템 관리는 접근 불가 — 자기 대리점·총판의 업무 전체를 혼자 처리하는 담당자용 역할).<br />
			<strong>시스템 관리(system/*)</strong> 화면은 이 표와 무관하게 항상 최고 관리자 전용입니다.<br />
			조회 권한이 없으면 해당 메뉴가 아예 보이지 않습니다. 쓰기 권한만 켜도 조회 권한이 자동으로 켜집니다.
		</div>
	</div>

	<div id="perm_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="perm_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="card card-flush">
		<div class="card-body pt-6">
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle" id="perm_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th>화면(area)</th>
							<?php foreach (RolePermission::ROLES as $role) : ?>
							<th class="text-center"><?= htmlspecialchars(admin_role_label($role), ENT_QUOTES, 'UTF-8') ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach (RolePermission::AREAS as $area => $label) : ?>
						<tr>
							<td class="fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
							<?php foreach (RolePermission::ROLES as $role) :
								$cell = $grid[$role][$area] ?? ['view' => false, 'write' => false]; ?>
							<td class="text-center">
								<div class="d-flex justify-content-center gap-4">
									<label class="form-check form-check-custom form-check-sm" title="조회">
										<input type="checkbox" class="form-check-input perm-view" data-role="<?= $role ?>" data-area="<?= $area ?>" <?= $cell['view'] ? 'checked' : '' ?> />
										<span class="fs-8 text-muted ms-1">조회</span>
									</label>
									<label class="form-check form-check-custom form-check-sm" title="쓰기">
										<input type="checkbox" class="form-check-input perm-write" data-role="<?= $role ?>" data-area="<?= $area ?>" <?= $cell['write'] ? 'checked' : '' ?> />
										<span class="fs-8 text-muted ms-1">쓰기</span>
									</label>
								</div>
							</td>
							<?php endforeach; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<button type="button" class="btn btn-primary" id="perm_save_btn">저장</button>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('perm_toast');
		var toastMsg = document.getElementById('perm_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}

		// 쓰기 체크 시 조회 자동 체크
		document.querySelectorAll('.perm-write').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (cb.checked) {
					var view = document.querySelector('.perm-view[data-role="' + cb.dataset.role + '"][data-area="' + cb.dataset.area + '"]');
					if (view) view.checked = true;
				}
			});
		});
		// 조회 해제 시 쓰기도 해제
		document.querySelectorAll('.perm-view').forEach(function (cb) {
			cb.addEventListener('change', function () {
				if (!cb.checked) {
					var write = document.querySelector('.perm-write[data-role="' + cb.dataset.role + '"][data-area="' + cb.dataset.area + '"]');
					if (write) write.checked = false;
				}
			});
		});

		document.getElementById('perm_save_btn').addEventListener('click', function (ev) {
			var btn = ev.currentTarget;
			var rowsMap = {};
			document.querySelectorAll('.perm-view').forEach(function (cb) {
				var key = cb.dataset.role + '|' + cb.dataset.area;
				rowsMap[key] = rowsMap[key] || { role: cb.dataset.role, area: cb.dataset.area, view: false, write: false };
				rowsMap[key].view = cb.checked;
			});
			document.querySelectorAll('.perm-write').forEach(function (cb) {
				var key = cb.dataset.role + '|' + cb.dataset.area;
				rowsMap[key] = rowsMap[key] || { role: cb.dataset.role, area: cb.dataset.area, view: false, write: false };
				rowsMap[key].write = cb.checked;
			});
			btn.disabled = true;
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ action: 'save', rows: Object.values(rowsMap) }),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					showToast(res.message || '저장되었습니다.', true);
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); })
				.finally(function () { btn.disabled = false; });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

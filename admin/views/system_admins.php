<?php

declare(strict_types=1);

require_once INC_PATH . '/AdminAccount.php';

$roleLabels = AdminAccount::roleLabels();
$listError  = null;
$admins     = [];
$apiUrl     = ADMIN_BASE . '/api/admins.php';
$canManage  = admin_has_role('super');
$selfId     = (int) ($_SESSION['admin_id'] ?? 0);

try {
    if (!$canManage) {
        $listError = '최고 관리자만 관리자 계정을 변경할 수 있습니다.';
    } else {
        $admins = AdminAccount::listAll();
    }
} catch (Throwable $e) {
    $listError = $e->getMessage();
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">관리자 계정·권한</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">시스템</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">관리자</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">코드/마스터</a>
			<a href="<?= htmlspecialchars(admin_url('system/audit'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">감사 로그</a>
			<?php if ($canManage) : ?>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_admin_modal" id="btn_admin_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				관리자 추가
			</button>
			<?php endif; ?>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			관리자 계정은 <strong>DB(admins)</strong>에 저장됩니다. 역할에 따라 메뉴 접근·수정 권한이 적용되며, 변경 내역은 <strong>감사 로그</strong>에 기록됩니다.
		</div>
	</div>
	<?php endif; ?>

	<div id="admin_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="admin_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
	</div>

	<div class="row g-6 mb-8">
		<div class="col-xl-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">역할 요약</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">로그인 시 역할별 메뉴·API 권한 적용</span>
				</div>
				<div class="card-body pt-0 fs-7">
					<ul class="list-unstyled mb-3">
						<li class="mb-3"><span class="badge badge-light-danger me-2">최고</span> 전 메뉴·관리자 계정·시스템 설정까지 항상 전권</li>
						<li class="mb-3"><span class="badge badge-light-warning me-2">총괄</span> 시스템 관리를 제외한 소속 조직(총판/대리점)의 모든 화면을 조회·수정 — 담당자 1명이 그 조직 업무 전체를 처리하는 경우용</li>
						<li class="mb-3"><span class="badge badge-light-primary me-2">운영</span> · <span class="badge badge-light-success me-2">정산</span> · <span class="badge badge-light-dark me-2">조회</span> 화면별 조회·쓰기 권한은 고정이 아니라 <a href="<?= htmlspecialchars(admin_url('system/permissions'), ENT_QUOTES, 'UTF-8') ?>">「권한 관리」</a> 화면에서 역할별로 직접 설정합니다(기본값: 운영=라이더·콘텐츠·출금 쓰기, 정산=정산·차감·프로모션 쓰기, 조회=전 화면 읽기전용).</li>
					</ul>
					<p class="text-gray-500 fs-8 mb-0">시스템 관리(이 화면 포함)는 역할 설정과 무관하게 항상 최고 관리자만 접근할 수 있습니다.</p>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-3 flex-wrap">
			<div class="card-title">
				<h3 class="fw-bold m-0">관리자 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">로그인 ID · 소속 · 역할 · 마지막 접속</span>
			</div>
			<div class="card-toolbar">
				<div class="d-flex align-items-center position-relative">
					<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
					<input type="search" id="admin_search" class="form-control form-control-solid ps-12 w-250px" placeholder="로그인ID·이름·소속 검색" />
				</div>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="adminListTable">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">로그인 ID</th>
							<th class="min-w-100px">이름</th>
							<th class="min-w-140px">소속</th>
							<th class="min-w-180px">이메일</th>
							<th class="min-w-110px">역할</th>
							<th class="min-w-80px">상태</th>
							<th class="min-w-130px">마지막 로그인</th>
							<th class="min-w-100px">등록일</th>
							<?php if ($canManage) : ?>
							<th class="min-w-200px text-end">관리</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody id="admin_tbody">
						<?php if ($listError === null && $admins === []) : ?>
						<tr data-tp-skip><td colspan="<?= $canManage ? 9 : 8 ?>" class="text-center text-muted py-10">등록된 관리자가 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($admins as $row) :
						    $role = (string) $row['role'];
						    $badgeClass = match ($role) {
						        'super' => 'danger',
						        'manager' => 'warning',
						        'operation' => 'primary',
						        'settlement' => 'success',
						        default => 'dark',
						    };
						    $isSelf = (int) $row['id'] === $selfId;
						    ?>
						<tr class="<?= !($row['active'] ?? false) ? 'opacity-75' : '' ?>" data-id="<?= (int) $row['id'] ?>">
							<td class="font-monospace fw-bold"><?= htmlspecialchars((string) $row['login_id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?><?= $isSelf ? ' <span class="badge badge-light-info fs-8">나</span>' : '' ?></td>
							<td class="fs-7">
								<?php $orgName = (string) ($row['org_name'] ?? ''); $orgLevel = (string) ($row['org_level'] ?? ''); ?>
								<?php if ($orgName === '') : ?>
									<span class="text-muted">—</span>
								<?php else : ?>
									<span class="fw-semibold text-gray-800"><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></span>
									<?php $lvLabel = ['admin' => '본사', 'distributor' => '총판', 'agency' => '대리점'][$orgLevel] ?? ''; ?>
									<?php if ($lvLabel !== '') : ?>
									<span class="badge badge-light-<?= $orgLevel === 'admin' ? 'dark' : ($orgLevel === 'distributor' ? 'primary' : 'success') ?> fs-9 ms-1"><?= $lvLabel ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="fs-7"><?= ($row['email'] ?? '') !== '' ? htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td><span class="badge badge-light-<?= htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $row['role_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td><?= ($row['active'] ?? false) ? '<span class="badge badge-light-success">활성</span>' : '<span class="badge badge-light-dark">중지</span>' ?></td>
							<td class="fs-7"><?= ($row['last_login_at'] ?? '') !== '' ? htmlspecialchars((string) $row['last_login_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td class="fs-7"><?= htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
							<?php if ($canManage) : ?>
							<td class="text-end">
								<div class="d-flex flex-wrap justify-content-end gap-1">
									<button type="button" class="btn btn-sm btn-light-primary btn-admin-edit"
										data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
									<?php if (!$isSelf) : ?>
									<button type="button" class="btn btn-sm btn-light-<?= ($row['active'] ?? false) ? 'warning' : 'success' ?> btn-admin-toggle"
										data-id="<?= (int) $row['id'] ?>"
										data-active="<?= ($row['active'] ?? false) ? '1' : '0' ?>"><?= ($row['active'] ?? false) ? '비활성' : '활성' ?></button>
									<?php endif; ?>
								</div>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<?php if ($canManage) : ?>
	<div class="modal fade" id="kt_admin_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="admin_modal_title">관리자 추가</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-10 px-lg-17">
					<form id="admin_form">
						<input type="hidden" id="admin_edit_id" value="" />
						<div class="mb-5">
							<label class="form-label required" for="admin_login_id">로그인 ID</label>
							<input type="text" class="form-control form-control-solid" id="admin_login_id" required autocomplete="username" maxlength="60" />
						</div>
						<div class="mb-5">
							<label class="form-label required" for="admin_name">이름</label>
							<input type="text" class="form-control form-control-solid" id="admin_name" required maxlength="50" />
						</div>
						<div class="mb-5">
							<label class="form-label" for="admin_email">이메일</label>
							<input type="email" class="form-control form-control-solid" id="admin_email" maxlength="120" />
						</div>
						<div class="mb-5">
							<label class="form-label required" for="admin_role">역할</label>
							<select class="form-select form-select-solid" id="admin_role" required>
								<?php foreach ($roleLabels as $value => $label) : ?>
								<option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="mb-5" id="admin_password_wrap">
							<label class="form-label" for="admin_password" id="admin_password_label">비밀번호</label>
							<input type="password" class="form-control form-control-solid" id="admin_password" autocomplete="new-password" minlength="8" />
							<div class="form-text" id="admin_password_hint">8자 이상. 수정 시 변경할 때만 입력하세요.</div>
						</div>
					</form>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="admin_save_btn"><span class="indicator-label">저장</span></button>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var toast = document.getElementById('admin_toast');
		var toastMsg = document.getElementById('admin_toast_msg');

		function showToast(msg, ok) {
			if (!toast || !toastMsg) return;
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}

		function resetForm() {
			document.getElementById('admin_modal_title').textContent = '관리자 추가';
			document.getElementById('admin_edit_id').value = '';
			document.getElementById('admin_form').reset();
			document.getElementById('admin_login_id').removeAttribute('readonly');
			document.getElementById('admin_password').required = true;
			document.getElementById('admin_password_label').classList.add('required');
			document.getElementById('admin_password_hint').textContent = '8자 이상';
		}

		document.getElementById('btn_admin_create').addEventListener('click', resetForm);

		document.getElementById('admin_tbody').addEventListener('click', function (ev) {
			var edit = ev.target.closest('.btn-admin-edit');
			var toggle = ev.target.closest('.btn-admin-toggle');
			if (edit) {
				var row = JSON.parse(edit.getAttribute('data-json') || '{}');
				document.getElementById('admin_modal_title').textContent = '관리자 수정';
				document.getElementById('admin_edit_id').value = row.id || '';
				document.getElementById('admin_login_id').value = row.login_id || '';
				document.getElementById('admin_login_id').setAttribute('readonly', 'readonly');
				document.getElementById('admin_name').value = row.name || '';
				document.getElementById('admin_email').value = row.email || '';
				document.getElementById('admin_role').value = row.role || 'admin';
				document.getElementById('admin_password').value = '';
				document.getElementById('admin_password').required = false;
				document.getElementById('admin_password_label').classList.remove('required');
				document.getElementById('admin_password_hint').textContent = '변경할 때만 입력 (8자 이상)';
				new bootstrap.Modal(document.getElementById('kt_admin_modal')).show();
				return;
			}
			if (toggle) {
				var id = toggle.getAttribute('data-id');
				var active = toggle.getAttribute('data-active') !== '1';
				var label = active ? '활성화' : '비활성화';
				if (!confirm('이 관리자를 ' + label + '할까요?')) return;
				fetch(API, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ action: 'toggle_active', id: Number(id), active: active }),
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '실패');
						location.reload();
					})
					.catch(function (e) { showToast(e.message || '상태 변경 실패', false); });
			}
		});

		document.getElementById('admin_save_btn').addEventListener('click', function () {
			var editId = document.getElementById('admin_edit_id').value;
			var loginId = document.getElementById('admin_login_id').value.trim();
			var name = document.getElementById('admin_name').value.trim();
			var password = document.getElementById('admin_password').value;
			if (!loginId || !name) {
				showToast('로그인 ID와 이름을 입력하세요.', false);
				return;
			}
			if (!editId && password.length < 8) {
				showToast('비밀번호는 8자 이상이어야 합니다.', false);
				return;
			}
			var payload = {
				action: 'save',
				id: editId ? Number(editId) : 0,
				login_id: loginId,
				name: name,
				email: document.getElementById('admin_email').value.trim(),
				role: document.getElementById('admin_role').value,
			};
			if (password !== '') payload.password = password;

			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					var modalEl = document.getElementById('kt_admin_modal');
					var inst = bootstrap.Modal.getInstance(modalEl);
					if (inst) inst.hide();
					location.reload();
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		});
	})();
	</script>
	<?php endif; ?>

<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
	// 목록이 길어지면 스크롤만 길어져 찾기 어렵다. 서버가 내려준 결과를 그대로 두고
	// 화면에서만 페이지로 나눈다(DataTables 없이 같은 UX — assets/js/table-paginate.js).
	var tp_adminListTable = document.getElementById('adminListTable');
	if (tp_adminListTable) { initTablePaginate(tp_adminListTable, { pageSize: 20, unit: '명', searchInput: '#admin_search' }); }
</script>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

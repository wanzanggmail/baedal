<?php

declare(strict_types=1);

require_once INC_PATH . '/Organization.php';

$canManage   = admin_can_manage_orgs();
$myLevel     = admin_org_level();
$isAdmin     = $myLevel === Org::LEVEL_ADMIN;
$roleLabels  = Organization::accountRoleLabels();
$distOptions = $canManage ? Organization::distributorOptions() : [];
$apiUrl      = ADMIN_BASE . '/api/orgs.php';

$listError = null;
$orgs      = [];
try {
    if (!$canManage) {
        $listError = '조직을 관리할 권한이 없습니다. (본사·총판 전용)';
    } else {
        $orgs = Organization::listManageable();
    }
} catch (Throwable $e) {
    $listError = $e->getMessage();
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">조직 관리(총판·대리점)</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">조직</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<?php if ($canManage) : ?>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_org_modal" id="btn_org_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				<?= $isAdmin ? '총판/대리점 추가' : '대리점 추가' ?>
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
			<strong>본사 &gt; 총판 &gt; 대리점</strong> 계층입니다. 조직을 만들면 로그인 계정이 함께 발급되며, 각 계정은 <strong>자기 조직 범위의 데이터·메뉴</strong>만 보입니다. 라이더는 대리점에 소속됩니다.
		</div>
	</div>
	<?php endif; ?>

	<div id="org_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="org_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">조직 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">유형 · 코드 · 상위 · 대표 계정 · 라이더 수</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-90px">유형</th>
							<th class="min-w-120px">코드</th>
							<th class="min-w-140px">이름</th>
							<th class="min-w-120px">상위</th>
							<th class="min-w-130px">대표 계정</th>
							<th class="min-w-80px text-center">라이더</th>
							<th class="min-w-70px">상태</th>
							<?php if ($canManage) : ?>
							<th class="min-w-160px text-end">관리</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody id="org_tbody">
						<?php if ($listError === null && $orgs === []) : ?>
						<tr><td colspan="<?= $canManage ? 8 : 7 ?>" class="text-center text-muted py-10">등록된 조직이 없습니다. 위의 추가 버튼으로 만드세요.</td></tr>
						<?php endif; ?>
						<?php foreach ($orgs as $row) :
						    $badge = $row['level'] === Org::LEVEL_DISTRIBUTOR ? 'primary' : 'success';
						    ?>
						<tr class="<?= !$row['active'] ? 'opacity-75' : '' ?>" data-id="<?= (int) $row['id'] ?>">
							<td><span class="badge badge-light-<?= $badge ?>"><?= htmlspecialchars((string) $row['level_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="font-monospace fw-bold"><?= htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="fs-7"><?= ($row['parent_name'] ?? '') !== '' ? htmlspecialchars((string) $row['parent_name'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td class="fs-7 font-monospace"><?= ($row['primary_login'] ?? '') !== '' ? htmlspecialchars((string) $row['primary_login'], ENT_QUOTES, 'UTF-8') : '—' ?><?= $row['account_count'] > 1 ? ' <span class="badge badge-light-info fs-8">+' . ((int) $row['account_count'] - 1) . '</span>' : '' ?></td>
							<td class="text-center"><?= $row['level'] === Org::LEVEL_AGENCY ? (int) $row['rider_count'] : '—' ?></td>
							<td><?= $row['active'] ? '<span class="badge badge-light-success">활성</span>' : '<span class="badge badge-light-dark">중지</span>' ?></td>
							<?php if ($canManage) : ?>
							<td class="text-end">
								<div class="d-flex flex-wrap justify-content-end gap-1">
									<button type="button" class="btn btn-sm btn-light-primary btn-org-edit"
										data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
									<button type="button" class="btn btn-sm btn-light-<?= $row['active'] ? 'warning' : 'success' ?> btn-org-toggle"
										data-id="<?= (int) $row['id'] ?>" data-active="<?= $row['active'] ? '1' : '0' ?>"><?= $row['active'] ? '중지' : '활성' ?></button>
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
	<div class="modal fade" id="kt_org_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="org_modal_title">조직 추가</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-10 px-lg-17">
					<form id="org_form">
						<input type="hidden" id="org_edit_id" value="" />

						<div id="org_create_only">
							<?php if ($isAdmin) : ?>
							<div class="mb-5">
								<label class="form-label required">조직 유형</label>
								<select class="form-select form-select-solid" id="org_level">
									<option value="distributor">총판</option>
									<option value="agency">대리점</option>
								</select>
							</div>
							<div class="mb-5 d-none" id="org_parent_wrap">
								<label class="form-label required">상위 총판</label>
								<select class="form-select form-select-solid" id="org_parent_id">
									<option value="">선택하세요</option>
									<?php foreach ($distOptions as $d) : ?>
									<option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name'] . ' (' . $d['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<?php else : ?>
							<input type="hidden" id="org_level" value="agency" />
							<div class="alert bg-light-info fs-7 py-3 mb-5">총판 <strong><?= htmlspecialchars((string) (admin_org()['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong> 하위 <strong>대리점</strong>이 생성됩니다.</div>
							<?php endif; ?>

							<div class="mb-5">
								<label class="form-label required" for="org_code">조직 코드</label>
								<input type="text" class="form-control form-control-solid" id="org_code" maxlength="40" placeholder="예: AG-GANGNAM" />
								<div class="form-text">영문 대문자·숫자·_·- (생성 후 변경 불가)</div>
							</div>
						</div>

						<div class="mb-5">
							<label class="form-label required" for="org_name">조직 이름</label>
							<input type="text" class="form-control form-control-solid" id="org_name" maxlength="120" />
						</div>
						<div class="row">
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_contact_name">담당자명</label>
								<input type="text" class="form-control form-control-solid" id="org_contact_name" maxlength="80" />
							</div>
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_contact_phone">연락처</label>
								<input type="text" class="form-control form-control-solid" id="org_contact_phone" maxlength="30" />
							</div>
						</div>

						<div id="org_account_section">
							<div class="separator separator-dashed my-6"></div>
							<h4 class="fw-bold fs-6 mb-4">로그인 계정 발급</h4>
							<div class="row">
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_login_id">로그인 ID</label>
									<input type="text" class="form-control form-control-solid" id="org_login_id" maxlength="60" autocomplete="off" />
								</div>
								<div class="col-md-6 mb-5">
									<label class="form-label" for="org_account_name">계정 이름</label>
									<input type="text" class="form-control form-control-solid" id="org_account_name" maxlength="50" placeholder="비우면 조직명+담당자" />
								</div>
							</div>
							<div class="row">
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_password">비밀번호</label>
									<input type="password" class="form-control form-control-solid" id="org_password" autocomplete="new-password" minlength="8" />
									<div class="form-text">8자 이상</div>
								</div>
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_role">계정 역할</label>
									<select class="form-select form-select-solid" id="org_role">
										<?php foreach ($roleLabels as $value => $label) : ?>
										<option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= $value === 'operation' ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>
					</form>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="org_save_btn"><span class="indicator-label">저장</span></button>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
		var toast = document.getElementById('org_toast');
		var toastMsg = document.getElementById('org_toast_msg');

		function showToast(msg, ok) {
			if (!toast || !toastMsg) return;
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function $(id) { return document.getElementById(id); }

		function syncParent() {
			if (!IS_ADMIN) return;
			var lvl = $('org_level').value;
			$('org_parent_wrap').classList.toggle('d-none', lvl !== 'agency');
		}
		if (IS_ADMIN && $('org_level')) {
			$('org_level').addEventListener('change', syncParent);
		}

		function resetForm() {
			$('org_modal_title').textContent = '조직 추가';
			$('org_edit_id').value = '';
			$('org_form').reset();
			$('org_create_only').classList.remove('d-none');
			$('org_account_section').classList.remove('d-none');
			$('org_code').removeAttribute('readonly');
			syncParent();
		}
		$('btn_org_create').addEventListener('click', resetForm);

		$('org_tbody').addEventListener('click', function (ev) {
			var edit = ev.target.closest('.btn-org-edit');
			var toggle = ev.target.closest('.btn-org-toggle');
			if (edit) {
				var row = JSON.parse(edit.getAttribute('data-json') || '{}');
				$('org_modal_title').textContent = '조직 수정 — ' + (row.name || '');
				$('org_edit_id').value = row.id || '';
				// 수정 시 유형·코드·계정은 변경 불가 → 숨김
				$('org_create_only').classList.add('d-none');
				$('org_account_section').classList.add('d-none');
				$('org_name').value = row.name || '';
				$('org_contact_name').value = row.contact_name || '';
				$('org_contact_phone').value = row.contact_phone || '';
				new bootstrap.Modal($('kt_org_modal')).show();
				return;
			}
			if (toggle) {
				var id = toggle.getAttribute('data-id');
				var active = toggle.getAttribute('data-active') !== '1';
				var label = active ? '활성화' : '중지';
				if (!confirm('이 조직을 ' + label + '할까요? 소속 계정 로그인도 함께 ' + label + '됩니다.')) return;
				post({ action: 'toggle_active', id: Number(id), active: active });
			}
		});

		$('org_save_btn').addEventListener('click', function () {
			var editId = $('org_edit_id').value;
			var payload = {
				action: 'save',
				id: editId ? Number(editId) : 0,
				name: $('org_name').value.trim(),
				contact_name: $('org_contact_name').value.trim(),
				contact_phone: $('org_contact_phone').value.trim()
			};
			if (!payload.name) { showToast('조직 이름을 입력하세요.', false); return; }

			if (!editId) {
				payload.level = $('org_level').value;
				payload.code = $('org_code').value.trim().toUpperCase();
				if (IS_ADMIN && payload.level === 'agency') {
					payload.parent_id = Number($('org_parent_id').value || 0);
					if (!payload.parent_id) { showToast('상위 총판을 선택하세요.', false); return; }
				}
				payload.login_id = $('org_login_id').value.trim();
				payload.account_name = $('org_account_name').value.trim();
				payload.password = $('org_password').value;
				payload.role = $('org_role').value;
				if (!payload.code) { showToast('조직 코드를 입력하세요.', false); return; }
				if (!payload.login_id) { showToast('로그인 ID를 입력하세요.', false); return; }
				if (payload.password.length < 8) { showToast('비밀번호는 8자 이상이어야 합니다.', false); return; }
			}
			post(payload);
		});

		function post(payload) {
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '처리 실패');
					var inst = bootstrap.Modal.getInstance($('kt_org_modal'));
					if (inst) inst.hide();
					location.reload();
				})
				.catch(function (e) { showToast(e.message || '처리 실패', false); });
		}
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

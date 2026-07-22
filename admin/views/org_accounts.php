<?php

declare(strict_types=1);

require_once INC_PATH . '/OrgAccount.php';

$apiUrl = ADMIN_BASE . '/api/org_accounts.php';
$orgId  = admin_org_id();
$org    = admin_org();
$rows   = OrgAccount::listForOrg($orgId);
$roleLabels = OrgAccount::roleLabels();
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">대표·서브계정 관리</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900"><?= htmlspecialchars((string) ($org['name'] ?? '내 조직'), ENT_QUOTES, 'UTF-8') ?> 계정</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<button type="button" class="btn btn-sm btn-primary" id="oa_create_btn">＋ 서브계정 추가</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div id="oa_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="oa_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold"><?= htmlspecialchars((string) ($org['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?> 소속 계정</h3></div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead><tr class="fw-bold text-muted">
						<th>로그인 ID</th><th>이름</th><th>역할</th><th class="text-center">상태</th><th>최근 로그인</th><th class="text-end">관리</th>
					</tr></thead>
					<tbody id="oa_tbody">
						<?php foreach ($rows as $r) : ?>
						<tr data-json='<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'>
							<td class="fw-bold"><?= htmlspecialchars($r['login_id'], ENT_QUOTES, 'UTF-8') ?>
								<?php if ($r['is_primary']) : ?><span class="badge badge-light-primary ms-1">대표</span><?php endif; ?>
							</td>
							<td><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($r['role_label'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-center"><span class="badge badge-light-<?= $r['active'] ? 'success' : 'secondary' ?>"><?= $r['active'] ? '활성' : '비활성' ?></span></td>
							<td class="text-muted"><?= htmlspecialchars($r['last_login_at'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-icon btn-light oa-edit">✎</button>
								<?php if (!$r['is_primary']) : ?>
								<button type="button" class="btn btn-sm btn-light-<?= $r['active'] ? 'warning' : 'success' ?> oa-toggle" data-id="<?= (int) $r['id'] ?>" data-active="<?= $r['active'] ? '1' : '0' ?>"><?= $r['active'] ? '비활성' : '활성' ?></button>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- 모달 -->
	<div class="modal fade" id="oa_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header"><h3 class="modal-title" id="oa_modal_title">서브계정 추가</h3><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
				<div class="modal-body fs-7">
					<input type="hidden" id="oa_id" />
					<div class="mb-3">
						<label class="form-label required">로그인 ID</label>
						<input type="text" class="form-control form-control-solid" id="oa_login" />
					</div>
					<div class="mb-3">
						<label class="form-label required">이름</label>
						<input type="text" class="form-control form-control-solid" id="oa_name" />
					</div>
					<div class="mb-3">
						<label class="form-label">이메일</label>
						<input type="email" class="form-control form-control-solid" id="oa_email" />
					</div>
					<div class="mb-3">
						<label class="form-label required">역할</label>
						<select class="form-select form-select-solid" id="oa_role">
							<?php foreach ($roleLabels as $rk => $rl) : ?>
							<option value="<?= htmlspecialchars($rk, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($rl, ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mb-2">
						<label class="form-label" id="oa_pw_label">비밀번호 (8자 이상)</label>
						<input type="password" class="form-control form-control-solid" id="oa_password" autocomplete="new-password" />
						<div class="form-text" id="oa_pw_hint"></div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="oa_save">저장</button>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('oa_toast'), toastMsg = document.getElementById('oa_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); }
		function modal() { return bootstrap.Modal.getOrCreateInstance(document.getElementById('oa_modal')); }
		function post(p) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(p) }).then(function (r) { return r.json(); }); }

		function openCreate() {
			document.getElementById('oa_modal_title').textContent = '서브계정 추가';
			document.getElementById('oa_id').value = '';
			document.getElementById('oa_login').value = '';
			document.getElementById('oa_login').disabled = false;
			document.getElementById('oa_name').value = '';
			document.getElementById('oa_email').value = '';
			document.getElementById('oa_role').value = 'operation';
			document.getElementById('oa_password').value = '';
			document.getElementById('oa_pw_hint').textContent = '';
			modal().show();
		}
		function openEdit(row) {
			document.getElementById('oa_modal_title').textContent = '계정 수정';
			document.getElementById('oa_id').value = row.id;
			document.getElementById('oa_login').value = row.login_id;
			document.getElementById('oa_login').disabled = true;
			document.getElementById('oa_name').value = row.name;
			document.getElementById('oa_email').value = row.email || '';
			document.getElementById('oa_role').value = row.role;
			document.getElementById('oa_role').disabled = !!row.is_primary;
			document.getElementById('oa_password').value = '';
			document.getElementById('oa_pw_hint').textContent = '비워두면 기존 비밀번호 유지';
			modal().show();
		}

		document.getElementById('oa_create_btn').addEventListener('click', openCreate);
		document.getElementById('oa_tbody').addEventListener('click', function (ev) {
			var tr = ev.target.closest('tr');
			if (ev.target.closest('.oa-edit')) { try { openEdit(JSON.parse(tr.getAttribute('data-json'))); } catch (e) { showToast('데이터 오류', false); } }
			var tg = ev.target.closest('.oa-toggle');
			if (tg) {
				post({ action: 'set_active', id: parseInt(tg.getAttribute('data-id'), 10), active: tg.getAttribute('data-active') !== '1' })
					.then(function (res) { if (!res.ok) throw new Error(res.message); showToast(res.message, true); setTimeout(function () { location.reload(); }, 500); })
					.catch(function (e) { showToast(e.message, false); });
			}
		});
		document.getElementById('oa_save').addEventListener('click', function () {
			var id = document.getElementById('oa_id').value.trim();
			var payload = {
				action: id ? 'update' : 'create',
				id: id || undefined,
				login_id: document.getElementById('oa_login').value.trim(),
				name: document.getElementById('oa_name').value.trim(),
				email: document.getElementById('oa_email').value.trim(),
				role: document.getElementById('oa_role').value,
				password: document.getElementById('oa_password').value,
			};
			post(payload).then(function (res) { if (!res.ok) throw new Error(res.message); showToast(res.message, true); modal().hide(); setTimeout(function () { location.reload(); }, 500); })
				.catch(function (e) { showToast(e.message, false); });
		});
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

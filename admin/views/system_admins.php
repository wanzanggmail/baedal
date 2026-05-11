<?php

declare(strict_types=1);

$adminSeed = [
    [
        'id' => 'adm-seed-1',
        'login_id' => 'super@baedal',
        'name' => '김운영',
        'email' => 'super@baedal.local',
        'role' => 'super',
        'active' => true,
        'last_login_at' => '2026-05-10 09:12',
        'created_at' => '2025-01-02',
        'seed' => true,
        'note' => '최초 시스템 관리자',
    ],
    [
        'id' => 'adm-seed-2',
        'login_id' => 'settlement01',
        'name' => '이정산',
        'email' => 'settlement@baedal.local',
        'role' => 'settlement',
        'active' => true,
        'last_login_at' => '2026-05-09 18:40',
        'created_at' => '2025-03-10',
        'seed' => true,
        'note' => '',
    ],
    [
        'id' => 'adm-seed-3',
        'login_id' => 'ops_hw',
        'name' => '박현장',
        'email' => 'ops@baedal.local',
        'role' => 'ops',
        'active' => true,
        'last_login_at' => '2026-05-08 11:05',
        'created_at' => '2025-06-01',
        'seed' => true,
        'note' => '라이더·콘텐츠 담당',
    ],
    [
        'id' => 'adm-seed-4',
        'login_id' => 'viewer_cs',
        'name' => '최조회',
        'email' => 'viewer@baedal.local',
        'role' => 'read_only',
        'active' => true,
        'last_login_at' => '2026-05-07 08:55',
        'created_at' => '2026-01-15',
        'seed' => true,
        'note' => 'CS 파견 조회 전용',
    ],
];
$adminSeedJson = json_encode($adminSeed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$roleLabels = [
    'super' => '최고 관리자',
    'ops' => '운영',
    'settlement' => '정산',
    'read_only' => '조회 전용',
];
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
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_admin_modal" id="btn_admin_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				관리자 추가
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 계정 추가·비활성화·역할 변경은 브라우저 <code class="fs-8">localStorage</code>에만 저장되며 실제 인증·SSO와 연동되지 않습니다. 시드 계정은 삭제할 수 없고 비활성만 가능합니다.
		</div>
	</div>

	<div class="row g-6 mb-8">
		<div class="col-xl-4">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">역할 요약</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">실서비스에서는 RBAC·정책 엔진과 매핑</span>
				</div>
				<div class="card-body pt-0 fs-7">
					<ul class="list-unstyled mb-0">
						<li class="mb-3"><span class="badge badge-light-danger me-2">최고</span> 전 메뉴·관리자 초대·시스템 설정</li>
						<li class="mb-3"><span class="badge badge-light-primary me-2">운영</span> 라이더·콘텐츠·출금 조회, 일부 조치</li>
						<li class="mb-3"><span class="badge badge-light-success me-2">정산</span> 정산 업로드·차감·프로모션 배치</li>
						<li class="mb-0"><span class="badge badge-light-dark me-2">조회</span> 읽기 전용, 다운로드 제한(가정)</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="col-xl-8">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">권한 매트릭스 (샘플)</h3>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-2 fs-7 mb-0">
							<thead>
								<tr class="fw-bold text-muted">
									<th>기능 영역</th>
									<th class="text-center">최고</th>
									<th class="text-center">운영</th>
									<th class="text-center">정산</th>
									<th class="text-center">조회</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>대시보드·통계 조회</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-success">●</td>
								</tr>
								<tr>
									<td>라이더·콘텐츠 편집</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-muted">—</td>
									<td class="text-center text-muted">—</td>
								</tr>
								<tr>
									<td>정산 엑셀·차감·프로모션</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-muted">△</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-muted">—</td>
								</tr>
								<tr>
									<td>관리자·코드·감사</td>
									<td class="text-center text-success">●</td>
									<td class="text-center text-muted">△</td>
									<td class="text-center text-muted">—</td>
									<td class="text-center text-muted">△</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p class="text-gray-500 fs-8 mb-0 mt-3">● 전체 · △ 일부(메뉴별 세분화 가정) · — 없음</p>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">관리자 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">로그인 ID · 역할 · 마지막 접속</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">로그인 ID</th>
							<th class="min-w-100px">이름</th>
							<th class="min-w-180px">이메일</th>
							<th class="min-w-110px">역할</th>
							<th class="min-w-80px">상태</th>
							<th class="min-w-130px">마지막 로그인</th>
							<th class="min-w-100px">유형</th>
							<th class="min-w-200px text-end">관리</th>
						</tr>
					</thead>
					<tbody id="admin_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

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
							<input type="text" class="form-control form-control-solid" id="admin_login_id" required autocomplete="username" />
							<div class="form-text">목업: 이메일 형식 또는 사번 형태 모두 가능</div>
						</div>
						<div class="mb-5">
							<label class="form-label required" for="admin_name">이름</label>
							<input type="text" class="form-control form-control-solid" id="admin_name" required />
						</div>
						<div class="mb-5">
							<label class="form-label" for="admin_email">이메일</label>
							<input type="email" class="form-control form-control-solid" id="admin_email" />
						</div>
						<div class="mb-5">
							<label class="form-label required" for="admin_role">역할</label>
							<select class="form-select form-select-solid" id="admin_role" required>
								<option value="super"><?= htmlspecialchars($roleLabels['super'], ENT_QUOTES, 'UTF-8') ?></option>
								<option value="ops"><?= htmlspecialchars($roleLabels['ops'], ENT_QUOTES, 'UTF-8') ?></option>
								<option value="settlement"><?= htmlspecialchars($roleLabels['settlement'], ENT_QUOTES, 'UTF-8') ?></option>
								<option value="read_only"><?= htmlspecialchars($roleLabels['read_only'], ENT_QUOTES, 'UTF-8') ?></option>
							</select>
						</div>
						<div class="mb-5">
							<label class="form-label" for="admin_note">메모</label>
							<textarea class="form-control form-control-solid" id="admin_note" rows="2"></textarea>
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
		var SEED = <?= $adminSeedJson ?>;
		var ROLE_LABEL = <?= json_encode($roleLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var STORAGE_KEY = 'baedal_system_admins_v1';
		var AUDIT_KEY = 'baedal_audit_log';
		var SESSION_ACTOR = <?= json_encode((string) ($_SESSION['admin_user_id'] ?? '관리자'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

		function loadState() {
			try {
				var raw = localStorage.getItem(STORAGE_KEY);
				if (!raw) return { seedPatch: {}, extras: [] };
				var s = JSON.parse(raw);
				return {
					seedPatch: s.seedPatch && typeof s.seedPatch === 'object' ? s.seedPatch : {},
					extras: Array.isArray(s.extras) ? s.extras : [],
				};
			} catch (e) {
				return { seedPatch: {}, extras: [] };
			}
		}
		function saveState(st) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(st));
		}
		function auditPush(entry) {
			try {
				var list = JSON.parse(localStorage.getItem(AUDIT_KEY) || '[]');
				if (!Array.isArray(list)) list = [];
				list.unshift(entry);
				localStorage.setItem(AUDIT_KEY, JSON.stringify(list.slice(0, 400)));
			} catch (e) {}
		}
		function nowStr() {
			var d = new Date();
			function z(n) { return n < 10 ? '0' + n : '' + n; }
			return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());
		}
		function actor() {
			return SESSION_ACTOR || '관리자';
		}
		function mergedList() {
			var st = loadState();
			var rows = SEED.map(function (s) {
				var p = st.seedPatch[s.id] || {};
				return Object.assign({}, s, p);
			});
			return rows.concat(st.extras.slice());
		}
		function esc(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : String(s);
			return d.innerHTML;
		}
		function roleBadge(role) {
			var cls =
				role === 'super'
					? 'danger'
					: role === 'ops'
						? 'primary'
						: role === 'settlement'
							? 'success'
							: 'dark';
			return '<span class="badge badge-light-' + cls + '">' + esc(ROLE_LABEL[role] || role) + '</span>';
		}
		function render() {
			var tb = document.getElementById('admin_tbody');
			if (!tb) return;
			var rows = mergedList();
			tb.innerHTML = rows
				.map(function (r) {
					var active = !!r.active;
					var typeLabel = r.seed ? '<span class="badge badge-light">시드</span>' : '<span class="badge badge-light-info">추가</span>';
					var btnToggle =
						'<button type="button" class="btn btn-sm ' +
						(active ? 'btn-light-warning' : 'btn-light-success') +
						' btn-admin-toggle" data-id="' +
						esc(r.id) +
						'">' +
						(active ? '비활성' : '활성') +
						'</button>';
					var btnEdit =
						'<button type="button" class="btn btn-sm btn-light-primary btn-admin-edit" data-id="' + esc(r.id) + '">역할·메모</button>';
					var btnDel = r.seed
						? ''
						: '<button type="button" class="btn btn-sm btn-light-danger btn-admin-del" data-id="' + esc(r.id) + '">삭제</button>';
					return (
						'<tr class="' +
						(!active ? 'opacity-75' : '') +
						'"><td class="font-monospace fw-bold">' +
						esc(r.login_id) +
						'</td><td>' +
						esc(r.name) +
						'</td><td class="fs-7">' +
						esc(r.email || '—') +
						'</td><td>' +
						roleBadge(r.role) +
						'</td><td>' +
						(active ? '<span class="badge badge-light-success">활성</span>' : '<span class="badge badge-light-dark">중지</span>') +
						'</td><td class="fs-7">' +
						esc(r.last_login_at || '—') +
						'</td><td>' +
						typeLabel +
						'</td><td class="text-end"><div class="d-flex flex-wrap justify-content-end gap-1">' +
						btnEdit +
						btnToggle +
						btnDel +
						'</div></td></tr>'
					);
				})
				.join('');
		}
		function findRow(id) {
			var rows = mergedList();
			for (var i = 0; i < rows.length; i++) {
				if (rows[i].id === id) return rows[i];
			}
			return null;
		}
		document.getElementById('btn_admin_create').addEventListener('click', function () {
			document.getElementById('admin_modal_title').textContent = '관리자 추가';
			document.getElementById('admin_edit_id').value = '';
			document.getElementById('admin_form').reset();
			document.getElementById('admin_login_id').removeAttribute('readonly');
		});
		document.getElementById('admin_tbody').addEventListener('click', function (ev) {
			var t = ev.target;
			if (!t || !t.closest) return;
			var edit = t.closest('.btn-admin-edit');
			var toggle = t.closest('.btn-admin-toggle');
			var del = t.closest('.btn-admin-del');
			if (edit) {
				var row = findRow(edit.getAttribute('data-id'));
				if (!row) return;
				document.getElementById('admin_modal_title').textContent = row.seed ? '역할·메모 (시드)' : '관리자 수정';
				document.getElementById('admin_edit_id').value = row.id;
				document.getElementById('admin_login_id').value = row.login_id;
				document.getElementById('admin_login_id').setAttribute('readonly', 'readonly');
				document.getElementById('admin_name').value = row.name;
				document.getElementById('admin_email').value = row.email || '';
				document.getElementById('admin_role').value = row.role;
				document.getElementById('admin_note').value = row.note || '';
				new bootstrap.Modal(document.getElementById('kt_admin_modal')).show();
				return;
			}
			if (toggle) {
				var id = toggle.getAttribute('data-id');
				var st = loadState();
				var row = findRow(id);
				if (!row) return;
				var next = !row.active;
				if (row.seed) {
					st.seedPatch[id] = st.seedPatch[id] || {};
					st.seedPatch[id].active = next;
				} else {
					st.extras = st.extras.map(function (x) {
						if (x.id !== id) return x;
						return Object.assign({}, x, { active: next });
					});
				}
				saveState(st);
				auditPush({
					at: nowStr(),
					actor: actor(),
					action: next ? 'admin.activate' : 'admin.deactivate',
					target: row.login_id,
					detail: '관리자 상태 변경 (목업)',
					ip: '—',
				});
				render();
				return;
			}
			if (del) {
				var did = del.getAttribute('data-id');
				if (!confirm('이 관리자를 목록에서 제거할까요? (localStorage만)')) return;
				var st2 = loadState();
				st2.extras = st2.extras.filter(function (x) {
					return x.id !== did;
				});
				saveState(st2);
				auditPush({
					at: nowStr(),
					actor: actor(),
					action: 'admin.delete',
					target: did,
					detail: '추가 계정 삭제 (목업)',
					ip: '—',
				});
				render();
			}
		});
		document.getElementById('admin_save_btn').addEventListener('click', function () {
			var editId = document.getElementById('admin_edit_id').value;
			var loginId = document.getElementById('admin_login_id').value.trim();
			var name = document.getElementById('admin_name').value.trim();
			if (!loginId || !name) return;
			var st = loadState();
			if (editId) {
				var base = findRow(editId);
				if (!base) return;
				if (base.seed) {
					st.seedPatch[editId] = st.seedPatch[editId] || {};
					st.seedPatch[editId].role = document.getElementById('admin_role').value;
					st.seedPatch[editId].note = document.getElementById('admin_note').value.trim();
				} else {
					st.extras = st.extras.map(function (x) {
						if (x.id !== editId) return x;
						return Object.assign({}, x, {
							name: name,
							email: document.getElementById('admin_email').value.trim(),
							role: document.getElementById('admin_role').value,
							note: document.getElementById('admin_note').value.trim(),
						});
					});
				}
				saveState(st);
				auditPush({
					at: nowStr(),
					actor: actor(),
					action: 'admin.update',
					target: loginId,
					detail: '역할·메모 수정 (목업)',
					ip: '—',
				});
			} else {
				var dup = mergedList().some(function (x) {
					return x.login_id === loginId;
				});
				if (dup) {
					alert('동일한 로그인 ID가 이미 있습니다.');
					return;
				}
				var id = 'adm-custom-' + Date.now();
				st.extras.push({
					id: id,
					login_id: loginId,
					name: name,
					email: document.getElementById('admin_email').value.trim(),
					role: document.getElementById('admin_role').value,
					active: true,
					last_login_at: '—',
					created_at: nowStr().slice(0, 10),
					seed: false,
					note: document.getElementById('admin_note').value.trim(),
				});
				saveState(st);
				auditPush({
					at: nowStr(),
					actor: actor(),
					action: 'admin.create',
					target: loginId,
					detail: '관리자 추가 (목업)',
					ip: '—',
				});
			}
			var modalEl = document.getElementById('kt_admin_modal');
			var inst = bootstrap.Modal.getInstance(modalEl);
			if (inst) inst.hide();
			render();
		});
		render();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

$codeSeed = [
    'bank' => [
        ['code' => '088', 'label' => '신한은행', 'sort' => 10, 'active' => true],
        ['code' => '004', 'label' => '국민은행', 'sort' => 20, 'active' => true],
        ['code' => '020', 'label' => '우리은행', 'sort' => 30, 'active' => true],
        ['code' => '090', 'label' => '카카오뱅크', 'sort' => 40, 'active' => true],
    ],
    'vehicle' => [
        ['code' => 'bike', 'label' => '자전거', 'sort' => 10, 'active' => true],
        ['code' => 'motor', 'label' => '오토바이', 'sort' => 20, 'active' => true],
        ['code' => 'car', 'label' => '자동차', 'sort' => 30, 'active' => true],
        ['code' => 'walk', 'label' => '도보', 'sort' => 40, 'active' => true],
    ],
    'rider_status' => [
        ['code' => 'active', 'label' => '활성', 'sort' => 10, 'active' => true],
        ['code' => 'suspended', 'label' => '정지', 'sort' => 20, 'active' => true],
        ['code' => 'leave_request', 'label' => '탈퇴 요청', 'sort' => 30, 'active' => true],
        ['code' => 'offboarded', 'label' => '탈퇴 완료', 'sort' => 40, 'active' => true],
    ],
    'settlement_status' => [
        ['code' => 'uploaded', 'label' => '업로드됨', 'sort' => 10, 'active' => true],
        ['code' => 'parsed', 'label' => '파싱 완료', 'sort' => 20, 'active' => true],
        ['code' => 'applied', 'label' => '반영 완료', 'sort' => 30, 'active' => true],
        ['code' => 'error', 'label' => '오류', 'sort' => 40, 'active' => true],
    ],
    'withdrawal_status' => [
        ['code' => 'pending', 'label' => '대기', 'sort' => 10, 'active' => true],
        ['code' => 'processing', 'label' => '처리 중', 'sort' => 20, 'active' => true],
        ['code' => 'paid', 'label' => '지급 완료', 'sort' => 30, 'active' => true],
        ['code' => 'rejected', 'label' => '반려', 'sort' => 40, 'active' => true],
    ],
];
$codeSeedJson = json_encode($codeSeed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$catLabels = [
    'bank' => '은행 코드',
    'vehicle' => '차량 유형',
    'rider_status' => '라이더 상태',
    'settlement_status' => '정산 처리 상태',
    'withdrawal_status' => '출금 신청 상태',
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">코드 / 마스터</h1>
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
				<li class="breadcrumb-item text-gray-900">코드</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">관리자</a>
			<a href="<?= htmlspecialchars(admin_url('system/audit'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">감사 로그</a>
			<button type="button" class="btn btn-sm btn-light-danger fw-bold" id="btn_codes_reset">목업 초기화</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-abstract-26 fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 코드 값은 <code class="fs-8">localStorage</code>에 저장됩니다. 실제 앱·DB와 동기화되지 않으며, 배너·라이더 화면의 라벨과 자동 연결되지 않습니다.
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 flex-wrap gap-3">
			<div class="card-title flex-grow-1">
				<h3 class="fw-bold m-0">마스터 코드</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">탭별로 코드·표시명·정렬·사용 여부를 관리합니다</span>
			</div>
			<button type="button" class="btn btn-sm btn-primary fw-bold" id="btn_code_add" data-cat="">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				현재 탭에 행 추가
			</button>
		</div>
		<div class="card-body pt-0">
			<ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x mb-5 fs-6" id="codes_tab_nav" role="tablist">
				<?php $i = 0; ?>
				<?php foreach ($catLabels as $key => $label) : ?>
				<li class="nav-item" role="presentation">
					<a class="nav-link text-active-primary fw-bold <?= $i === 0 ? 'active' : '' ?>" data-bs-toggle="tab" href="#codes_tab_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-cat="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" role="tab"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
				</li>
				<?php $i++; ?>
				<?php endforeach; ?>
			</ul>
			<div class="tab-content" id="codes_tab_content">
				<?php $j = 0; ?>
				<?php foreach ($catLabels as $key => $label) : ?>
				<div class="tab-pane fade<?= $j === 0 ? ' show active' : '' ?>" id="codes_tab_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" role="tabpanel">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-3" data-master-cat="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-100px">코드</th>
									<th class="min-w-200px">표시명</th>
									<th class="min-w-80px">정렬</th>
									<th class="min-w-90px">사용</th>
									<th class="min-w-160px text-end">관리</th>
								</tr>
							</thead>
							<tbody class="codes_tbody" data-cat="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"></tbody>
						</table>
					</div>
				</div>
				<?php $j++; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<div class="modal fade" id="kt_code_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="code_modal_title">코드 행</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-12">
					<input type="hidden" id="code_modal_cat" value="" />
					<input type="hidden" id="code_modal_index" value="" />
					<div class="mb-5">
						<label class="form-label required" for="code_value">코드</label>
						<input type="text" class="form-control form-control-solid" id="code_value" required />
					</div>
					<div class="mb-5">
						<label class="form-label required" for="code_label">표시명</label>
						<input type="text" class="form-control form-control-solid" id="code_label" required />
					</div>
					<div class="mb-5">
						<label class="form-label" for="code_sort">정렬 (숫자 작을수록 위)</label>
						<input type="number" class="form-control form-control-solid" id="code_sort" value="100" />
					</div>
					<div class="form-check form-switch form-check-custom form-check-solid">
						<input class="form-check-input" type="checkbox" id="code_active" checked />
						<label class="form-check-label" for="code_active">사용</label>
					</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="code_save_btn">저장</button>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var SEED = <?= $codeSeedJson ?>;
		var CAT_LABEL = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var STORAGE_KEY = 'baedal_master_codes_v1';
		var AUDIT_KEY = 'baedal_audit_log';
		var SESSION_ACTOR = <?= json_encode((string) ($_SESSION['admin_user_id'] ?? '관리자'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

		function deepClone(o) {
			return JSON.parse(JSON.stringify(o));
		}
		function loadData() {
			try {
				var raw = localStorage.getItem(STORAGE_KEY);
				if (!raw) return deepClone(SEED);
				var d = JSON.parse(raw);
				var ok = true;
				Object.keys(SEED).forEach(function (k) {
					if (!Array.isArray(d[k])) ok = false;
				});
				return ok ? d : deepClone(SEED);
			} catch (e) {
				return deepClone(SEED);
			}
		}
		function saveData(d) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(d));
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
			function z(n) {
				return n < 10 ? '0' + n : '' + n;
			}
			return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + ' ' + z(d.getHours()) + ':' + z(d.getMinutes());
		}
		function esc(s) {
			var el = document.createElement('div');
			el.textContent = s == null ? '' : String(s);
			return el.innerHTML;
		}
		var currentCat = Object.keys(SEED)[0];
		function sortedRows(cat, data) {
			var rows = (data[cat] || []).slice();
			rows.sort(function (a, b) {
				return (a.sort || 0) - (b.sort || 0);
			});
			return rows;
		}
		function renderCat(cat) {
			var data = loadData();
			var tb = document.querySelector('.codes_tbody[data-cat="' + cat + '"]');
			if (!tb) return;
			var rows = sortedRows(cat, data);
			tb.innerHTML = rows
				.map(function (r, idx) {
					var origIdx = data[cat].indexOf(r);
					var act = r.active !== false;
					return (
						'<tr class="' +
						(!act ? 'opacity-50' : '') +
						'"><td class="font-monospace fw-bold">' +
						esc(r.code) +
						'</td><td>' +
						esc(r.label) +
						'</td><td>' +
						esc(r.sort) +
						'</td><td>' +
						(act ? '<span class="badge badge-light-success">사용</span>' : '<span class="badge badge-light-dark">중지</span>') +
						'</td><td class="text-end"><button type="button" class="btn btn-sm btn-light-primary btn-code-edit" data-cat="' +
						esc(cat) +
						'" data-index="' +
						origIdx +
						'">수정</button> <button type="button" class="btn btn-sm btn-light-danger btn-code-del" data-cat="' +
						esc(cat) +
						'" data-index="' +
						origIdx +
						'">삭제</button></td></tr>'
					);
				})
				.join('');
		}
		function renderAll() {
			Object.keys(SEED).forEach(renderCat);
		}
		function activeTabCat() {
			var a = document.querySelector('#codes_tab_nav .nav-link.active');
			return a ? a.getAttribute('data-cat') : currentCat;
		}
		document.querySelectorAll('#codes_tab_nav .nav-link').forEach(function (el) {
			el.addEventListener('shown.bs.tab', function () {
				currentCat = el.getAttribute('data-cat') || currentCat;
			});
		});
		document.getElementById('btn_code_add').addEventListener('click', function () {
			var cat = activeTabCat();
			document.getElementById('code_modal_title').textContent = '행 추가 — ' + (CAT_LABEL[cat] || cat);
			document.getElementById('code_modal_cat').value = cat;
			document.getElementById('code_modal_index').value = '';
			document.getElementById('code_value').value = '';
			document.getElementById('code_label').value = '';
			document.getElementById('code_sort').value = '100';
			document.getElementById('code_active').checked = true;
			document.getElementById('code_value').removeAttribute('readonly');
			new bootstrap.Modal(document.getElementById('kt_code_modal')).show();
		});
		document.querySelector('#codes_tab_content').addEventListener('click', function (ev) {
			var ed = ev.target.closest('.btn-code-edit');
			var del = ev.target.closest('.btn-code-del');
			if (ed) {
				var cat = ed.getAttribute('data-cat');
				var ix = parseInt(ed.getAttribute('data-index'), 10);
				var data = loadData();
				var r = data[cat][ix];
				if (!r) return;
				document.getElementById('code_modal_title').textContent = '행 수정 — ' + (CAT_LABEL[cat] || cat);
				document.getElementById('code_modal_cat').value = cat;
				document.getElementById('code_modal_index').value = String(ix);
				document.getElementById('code_value').value = r.code;
				document.getElementById('code_value').setAttribute('readonly', 'readonly');
				document.getElementById('code_label').value = r.label;
				document.getElementById('code_sort').value = String(r.sort != null ? r.sort : 100);
				document.getElementById('code_active').checked = r.active !== false;
				new bootstrap.Modal(document.getElementById('kt_code_modal')).show();
				return;
			}
			if (del) {
				var cat2 = del.getAttribute('data-cat');
				var ix2 = parseInt(del.getAttribute('data-index'), 10);
				if (!confirm('이 코드 행을 삭제할까요?')) return;
				var data2 = loadData();
				data2[cat2].splice(ix2, 1);
				saveData(data2);
				auditPush({
					at: nowStr(),
					actor: SESSION_ACTOR,
					action: 'codes.delete',
					target: cat2,
					detail: '코드 행 삭제 (목업)',
					ip: '—',
				});
				renderAll();
			}
		});
		document.getElementById('code_save_btn').addEventListener('click', function () {
			var cat = document.getElementById('code_modal_cat').value;
			var ixRaw = document.getElementById('code_modal_index').value;
			var code = document.getElementById('code_value').value.trim();
			var label = document.getElementById('code_label').value.trim();
			if (!code || !label) return;
			var sort = parseInt(document.getElementById('code_sort').value, 10);
			if (isNaN(sort)) sort = 100;
			var active = document.getElementById('code_active').checked;
			var data = loadData();
			if (ixRaw !== '') {
				var ix = parseInt(ixRaw, 10);
				data[cat][ix] = { code: data[cat][ix].code, label: label, sort: sort, active: active };
			} else {
				var dup = data[cat].some(function (x) {
					return String(x.code) === code;
				});
				if (dup) {
					alert('같은 탭에 동일 코드가 있습니다.');
					return;
				}
				data[cat].push({ code: code, label: label, sort: sort, active: active });
			}
			saveData(data);
			auditPush({
				at: nowStr(),
				actor: SESSION_ACTOR,
				action: ixRaw !== '' ? 'codes.update' : 'codes.create',
				target: cat + ':' + code,
				detail: (CAT_LABEL[cat] || cat) + ' (목업)',
				ip: '—',
			});
			var modalEl = document.getElementById('kt_code_modal');
			var inst = bootstrap.Modal.getInstance(modalEl);
			if (inst) inst.hide();
			renderAll();
		});
		document.getElementById('btn_codes_reset').addEventListener('click', function () {
			if (!confirm('코드 마스터를 시드 값으로 되돌릴까요?')) return;
			localStorage.removeItem(STORAGE_KEY);
			auditPush({
				at: nowStr(),
				actor: SESSION_ACTOR,
				action: 'codes.reset',
				target: 'all',
				detail: '마스터 목업 초기화',
				ip: '—',
			});
			renderAll();
		});
		renderAll();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

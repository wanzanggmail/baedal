<?php

declare(strict_types=1);

require_once INC_PATH . '/SystemCode.php';

$catLabels  = SystemCode::categoryLabels();
$listError  = null;
$grouped    = [];
$apiUrl     = ADMIN_BASE . '/api/codes.php';
$canManage  = admin_has_role('super');

try {
    if (!$canManage) {
        $listError = '최고 관리자만 코드 마스터를 변경할 수 있습니다.';
    } else {
        $grouped = SystemCode::listGrouped();
    }
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$firstCat = SystemCode::CATEGORIES[0] ?? 'bank';
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
			<?php if ($canManage) : ?>
			<button type="button" class="btn btn-sm btn-primary fw-bold" id="btn_code_add">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				현재 탭에 행 추가
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
		<i class="ki-duotone ki-abstract-26 fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			코드 값은 <strong>DB(system_codes)</strong>에 저장되며, 라이더·출금·정산 화면의 선택 목록·라벨과 연동됩니다. 사용 중인 코드는 삭제할 수 없고 <strong>사용 중지</strong>로 처리하세요.
		</div>
	</div>
	<?php endif; ?>

	<div id="code_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="code_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 flex-wrap gap-3">
			<div class="card-title flex-grow-1">
				<h3 class="fw-bold m-0">마스터 코드</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">탭별로 코드·표시명·정렬·사용 여부를 관리합니다</span>
			</div>
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
				<?php foreach ($catLabels as $key => $label) :
				    $rows = $grouped[$key] ?? [];
				    ?>
				<div class="tab-pane fade<?= $j === 0 ? ' show active' : '' ?>" id="codes_tab_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" role="tabpanel">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-3">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-100px">코드</th>
									<th class="min-w-200px">표시명</th>
									<th class="min-w-80px">정렬</th>
									<th class="min-w-90px">사용</th>
									<?php if ($canManage) : ?>
									<th class="min-w-160px text-end">관리</th>
									<?php endif; ?>
								</tr>
							</thead>
							<tbody class="codes_tbody" data-cat="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
								<?php if ($listError === null && $rows === []) : ?>
								<tr><td colspan="<?= $canManage ? 5 : 4 ?>" class="text-center text-muted py-8">등록된 코드가 없습니다.</td></tr>
								<?php endif; ?>
								<?php foreach ($rows as $row) : ?>
								<tr class="<?= !($row['active'] ?? false) ? 'opacity-50' : '' ?>" data-id="<?= (int) $row['id'] ?>">
									<td class="font-monospace fw-bold"><?= htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= (int) $row['sort_order'] ?></td>
									<td><?= ($row['active'] ?? false) ? '<span class="badge badge-light-success">사용</span>' : '<span class="badge badge-light-dark">중지</span>' ?></td>
									<?php if ($canManage) : ?>
									<td class="text-end">
										<button type="button" class="btn btn-sm btn-light-primary btn-code-edit"
											data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
										<button type="button" class="btn btn-sm btn-light-danger btn-code-del"
											data-id="<?= (int) $row['id'] ?>"
											data-label="<?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?>">삭제</button>
									</td>
									<?php endif; ?>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
				<?php $j++; ?>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<?php if ($canManage) : ?>
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
					<input type="hidden" id="code_modal_id" value="" />
					<input type="hidden" id="code_modal_cat" value="" />
					<div class="mb-5">
						<label class="form-label required" for="code_value">코드</label>
						<input type="text" class="form-control form-control-solid" id="code_value" required maxlength="40" />
					</div>
					<div class="mb-5">
						<label class="form-label required" for="code_label">표시명</label>
						<input type="text" class="form-control form-control-solid" id="code_label" required maxlength="80" />
					</div>
					<div class="mb-5">
						<label class="form-label" for="code_sort">정렬 (숫자 작을수록 위)</label>
						<input type="number" class="form-control form-control-solid" id="code_sort" value="100" min="0" max="32767" />
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
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var CAT_LABEL = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var toast = document.getElementById('code_toast');
		var toastMsg = document.getElementById('code_toast_msg');

		function showToast(msg, ok) {
			if (!toast || !toastMsg) return;
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}

		function activeTabCat() {
			var a = document.querySelector('#codes_tab_nav .nav-link.active');
			return a ? a.getAttribute('data-cat') : <?= json_encode($firstCat, JSON_UNESCAPED_UNICODE) ?>;
		}

		document.getElementById('btn_code_add').addEventListener('click', function () {
			var cat = activeTabCat();
			document.getElementById('code_modal_title').textContent = '행 추가 — ' + (CAT_LABEL[cat] || cat);
			document.getElementById('code_modal_id').value = '';
			document.getElementById('code_modal_cat').value = cat;
			document.getElementById('code_value').value = '';
			document.getElementById('code_label').value = '';
			document.getElementById('code_sort').value = '100';
			document.getElementById('code_active').checked = true;
			document.getElementById('code_value').removeAttribute('readonly');
			new bootstrap.Modal(document.getElementById('kt_code_modal')).show();
		});

		document.getElementById('codes_tab_content').addEventListener('click', function (ev) {
			var ed = ev.target.closest('.btn-code-edit');
			var del = ev.target.closest('.btn-code-del');
			if (ed) {
				var row = JSON.parse(ed.getAttribute('data-json') || '{}');
				var cat = row.category || activeTabCat();
				document.getElementById('code_modal_title').textContent = '행 수정 — ' + (CAT_LABEL[cat] || cat);
				document.getElementById('code_modal_id').value = row.id || '';
				document.getElementById('code_modal_cat').value = cat;
				document.getElementById('code_value').value = row.code || '';
				document.getElementById('code_value').setAttribute('readonly', 'readonly');
				document.getElementById('code_label').value = row.label || '';
				document.getElementById('code_sort').value = String(row.sort_order != null ? row.sort_order : 100);
				document.getElementById('code_active').checked = !!row.active;
				new bootstrap.Modal(document.getElementById('kt_code_modal')).show();
				return;
			}
			if (del) {
				var id = del.getAttribute('data-id');
				var label = del.getAttribute('data-label') || '';
				if (!confirm('「' + label + '」 코드를 삭제할까요?\n사용 중이면 삭제되지 않습니다.')) return;
				fetch(API, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ action: 'delete', id: Number(id) }),
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '삭제 실패');
						showToast(res.message || '삭제되었습니다.', true);
						refreshTable(activeTabCat(), null);
					})
					.catch(function (e) { showToast(e.message || '삭제 실패', false); });
			}
		});

		/**
		 * 저장·삭제 뒤 **그 탭의 표만** 다시 그린다.
		 *
		 * 예전엔 location.reload() 로 페이지를 통째로 새로 고쳤다. 그러면 첫 탭
		 * (은행 코드)으로 돌아가고 스크롤도 맨 위로 튀어서, 방금 고친 줄을 다시
		 * 찾아가야 했다 — 코드가 60건 넘는 탭에서는 확인 자체가 일이었다.
		 * GET ?category= 가 이미 있어서 서버는 그대로 두고 화면만 바꾼다.
		 */
		function esc(v) {
			return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function rowHtml(row) {
			var active = !!row.active;
			return '<tr class="' + (active ? '' : 'opacity-50') + ' code-row-flash" data-id="' + Number(row.id) + '">'
				+ '<td class="font-monospace fw-bold">' + esc(row.code) + '</td>'
				+ '<td>' + esc(row.label) + '</td>'
				+ '<td>' + Number(row.sort_order || 0) + '</td>'
				+ '<td>' + (active
					? '<span class="badge badge-light-success">사용</span>'
					: '<span class="badge badge-light-dark">중지</span>') + '</td>'
				+ '<td class="text-end">'
				+ '<button type="button" class="btn btn-sm btn-light-primary btn-code-edit" data-json="'
					+ esc(JSON.stringify(row)) + '">수정</button> '
				+ '<button type="button" class="btn btn-sm btn-light-danger btn-code-del" data-id="'
					+ Number(row.id) + '" data-label="' + esc(row.label) + '">삭제</button>'
				+ '</td></tr>';
		}

		/** @param {string} cat  @param {number|null} focusId 방금 저장한 행 — 잠깐 강조한다 */
		function refreshTable(cat, focusId) {
			var tbody = document.querySelector('.codes_tbody[data-cat="' + cat + '"]');
			if (!tbody) { location.reload(); return; }   // 탭을 못 찾으면 예전처럼 통째로

			return fetch(API + '?category=' + encodeURIComponent(cat), { headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '목록을 불러오지 못했습니다.');
					var rows = res.rows || [];
					tbody.innerHTML = rows.length
						? rows.map(rowHtml).join('')
						: '<tr><td colspan="5" class="text-center text-muted py-8">등록된 코드가 없습니다.</td></tr>';

					if (focusId) {
						var tr = tbody.querySelector('tr[data-id="' + Number(focusId) + '"]');
						if (tr) {
							tr.classList.add('bg-light-success');
							tr.scrollIntoView({ block: 'center', behavior: 'smooth' });
							setTimeout(function () { tr.classList.remove('bg-light-success'); }, 1600);
						}
					}
				})
				.catch(function (e) {
					showToast(e.message || '목록 새로고침 실패 — 화면을 새로 고쳐 주세요.', false);
				});
		}

		document.getElementById('code_save_btn').addEventListener('click', function () {
			var id = document.getElementById('code_modal_id').value;
			var cat = document.getElementById('code_modal_cat').value;
			var code = document.getElementById('code_value').value.trim();
			var label = document.getElementById('code_label').value.trim();
			if (!code || !label) {
				showToast('코드와 표시명을 입력하세요.', false);
				return;
			}
			var sort = parseInt(document.getElementById('code_sort').value, 10);
			if (isNaN(sort)) sort = 100;

			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					action: 'save',
					id: id ? Number(id) : 0,
					category: cat,
					code: code,
					label: label,
					sort_order: sort,
					is_active: document.getElementById('code_active').checked,
				}),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					var modalEl = document.getElementById('kt_code_modal');
					var inst = bootstrap.Modal.getInstance(modalEl);
					if (inst) inst.hide();
					showToast(res.message || '저장되었습니다.', true);
					/* 저장한 코드가 속한 탭을 갱신한다 — 모달에서 카테고리를 바꿨을 수도 있다. */
					refreshTable(cat, res.row && res.row.id ? res.row.id : null);
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

$mockNoticesSeed = [
    [
        'id' => 'nt-20260510-001',
        'title' => '5월 정산 일정 안내',
        'body' => "안녕하세요.\n5월 정산 지급일은 5월 15일(목) 예정입니다.\n자세한 내역은 앱에서 확인해 주세요.",
        'category' => '안내',
        'pinned' => true,
        'status' => 'published',
        'published_at' => '2026-05-10 10:00',
        'updated_at' => '2026-05-10 10:00',
    ],
    [
        'id' => 'nt-20260509-002',
        'title' => '앱 점검 안내 (5/12 02:00~04:00)',
        'body' => "시스템 점검으로 일시적으로 서비스 이용이 제한될 수 있습니다.",
        'category' => '긴급',
        'pinned' => true,
        'status' => 'published',
        'published_at' => '2026-05-09 18:30',
        'updated_at' => '2026-05-09 18:30',
    ],
    [
        'id' => 'nt-20260508-003',
        'title' => '프로모션 지급 기준 변경 (예정)',
        'body' => "내부 검토 중인 초안입니다. 게시 전입니다.",
        'category' => '일반',
        'pinned' => false,
        'status' => 'draft',
        'published_at' => '',
        'updated_at' => '2026-05-08 14:00',
    ],
];
$noticesSeedJson = json_encode($mockNoticesSeed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">공지 관리</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">콘텐츠</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">공지</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('content/banners'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">광고 배너</a>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_notice_modal" id="btn_notice_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				공지 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-notepad fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 등록·수정·삭제는 브라우저 <code class="fs-8">localStorage</code>에만 반영되며 서버로 전송되지 않습니다.
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">공지 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">상단 고정 · 카테고리 · 게시 상태</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="notice_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-120px">ID</th>
							<th class="min-w-200px">제목</th>
							<th class="min-w-80px">카테고리</th>
							<th class="min-w-70px">고정</th>
							<th class="min-w-90px">상태</th>
							<th class="min-w-130px">게시일시</th>
							<th class="min-w-130px">수정일시</th>
							<th class="min-w-140px text-end">관리</th>
						</tr>
					</thead>
					<tbody id="notice_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="kt_notice_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="notice_modal_title">공지 등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-10 px-lg-10">
					<form id="notice_form">
						<input type="hidden" id="notice_id" name="notice_id" value="" />
						<div class="mb-6">
							<label class="form-label required">제목</label>
							<input type="text" class="form-control form-control-solid" id="notice_title" required maxlength="200" />
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">카테고리</label>
								<select class="form-select form-select-solid" id="notice_category">
									<option value="일반">일반</option>
									<option value="안내">안내</option>
									<option value="긴급">긴급</option>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label">게시 상태</label>
								<select class="form-select form-select-solid" id="notice_status">
									<option value="draft">초안</option>
									<option value="published">게시</option>
									<option value="hidden">숨김</option>
								</select>
							</div>
						</div>
						<div class="mb-6">
							<label class="form-label form-check form-check-custom form-check-solid">
								<input class="form-check-input" type="checkbox" id="notice_pinned" />
								<span class="form-check-label fw-semibold text-gray-700">상단 고정</span>
							</label>
						</div>
						<div class="mb-6">
							<label class="form-label">게시일시 (비어 있으면 저장 시각)</label>
							<input type="text" class="form-control form-control-solid" id="notice_published_at" placeholder="YYYY-MM-DD HH:mm" autocomplete="off" />
						</div>
						<div class="mb-8">
							<label class="form-label required">본문</label>
							<textarea class="form-control form-control-solid" id="notice_body" rows="8" required placeholder="공지 내용"></textarea>
						</div>
						<div class="d-flex justify-content-end gap-3">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
							<button type="submit" class="btn btn-primary">저장 (목업)</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var STORAGE_KEY = 'baedal_content_notices';
		var SEED = <?= $noticesSeedJson ?>;

		function pad(n) { return String(n).padStart(2, '0'); }
		function nowStr() {
			var d = new Date();
			return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
		}

		function loadList() {
			try {
				var raw = localStorage.getItem(STORAGE_KEY);
				if (raw) {
					var a = JSON.parse(raw);
					if (Array.isArray(a) && a.length) return a;
				}
			} catch (e) {}
			return SEED.slice();
		}

		function saveList(arr) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
		}

		function statusLabel(st) {
			if (st === 'published') return { t: '게시', c: 'success' };
			if (st === 'hidden') return { t: '숨김', c: 'dark' };
			return { t: '초안', c: 'warning' };
		}

		function excerpt(body) {
			var s = (body || '').replace(/\s+/g, ' ').trim();
			return s.length > 48 ? s.slice(0, 48) + '…' : s;
		}

		function render() {
			var list = loadList().slice().sort(function (a, b) {
				if (!!b.pinned !== !!a.pinned) return b.pinned ? 1 : -1;
				return (b.updated_at || '').localeCompare(a.updated_at || '');
			});
			var tb = document.getElementById('notice_tbody');
			tb.innerHTML = '';
			list.forEach(function (row) {
				var st = statusLabel(row.status);
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td class="fw-semibold text-gray-800">' + escapeHtml(row.id) + '</td>' +
					'<td><span class="text-gray-900 fw-bold">' + escapeHtml(row.title) + '</span>' +
					'<span class="text-muted fs-7 d-block">' + escapeHtml(excerpt(row.body)) + '</span></td>' +
					'<td><span class="badge badge-light-primary">' + escapeHtml(row.category) + '</span></td>' +
					'<td>' + (row.pinned ? '<span class="badge badge-light-success">고정</span>' : '<span class="text-muted">—</span>') + '</td>' +
					'<td><span class="badge badge-light-' + st.c + '">' + st.t + '</span></td>' +
					'<td class="text-gray-700">' + escapeHtml(row.published_at || '—') + '</td>' +
					'<td class="text-gray-700">' + escapeHtml(row.updated_at || '—') + '</td>' +
					'<td class="text-end">' +
					'<button type="button" class="btn btn-sm btn-light-primary me-1 btn-notice-edit" data-id="' + escapeAttr(row.id) + '">수정</button>' +
					'<button type="button" class="btn btn-sm btn-light-danger btn-notice-del" data-id="' + escapeAttr(row.id) + '">삭제</button>' +
					'</td>';
				tb.appendChild(tr);
			});
		}

		function escapeHtml(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : String(s);
			return d.innerHTML;
		}
		function escapeAttr(s) {
			return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
		}

		function openCreate() {
			document.getElementById('notice_modal_title').textContent = '공지 등록';
			document.getElementById('notice_id').value = '';
			document.getElementById('notice_title').value = '';
			document.getElementById('notice_category').value = '일반';
			document.getElementById('notice_status').value = 'draft';
			document.getElementById('notice_pinned').checked = false;
			document.getElementById('notice_published_at').value = '';
			document.getElementById('notice_body').value = '';
		}

		function openEdit(id) {
			var list = loadList();
			var row = list.find(function (r) { return r.id === id; });
			if (!row) return;
			document.getElementById('notice_modal_title').textContent = '공지 수정';
			document.getElementById('notice_id').value = row.id;
			document.getElementById('notice_title').value = row.title;
			document.getElementById('notice_category').value = row.category;
			document.getElementById('notice_status').value = row.status;
			document.getElementById('notice_pinned').checked = !!row.pinned;
			document.getElementById('notice_published_at').value = row.published_at || '';
			document.getElementById('notice_body').value = row.body || '';
			var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_notice_modal'));
			m.show();
		}

		document.getElementById('btn_notice_create').addEventListener('click', function () {
			openCreate();
		});

		document.getElementById('notice_tbody').addEventListener('click', function (ev) {
			var ed = ev.target.closest('.btn-notice-edit');
			var del = ev.target.closest('.btn-notice-del');
			if (ed) openEdit(ed.getAttribute('data-id'));
			if (del) {
				var id = del.getAttribute('data-id');
				if (!window.confirm('이 공지를 삭제할까요? (목업)')) return;
				var list = loadList().filter(function (r) { return r.id !== id; });
				saveList(list);
				render();
			}
		});

		document.getElementById('notice_form').addEventListener('submit', function (ev) {
			ev.preventDefault();
			var id = document.getElementById('notice_id').value.trim();
			var title = document.getElementById('notice_title').value.trim();
			var body = document.getElementById('notice_body').value;
			var category = document.getElementById('notice_category').value;
			var status = document.getElementById('notice_status').value;
			var pinned = document.getElementById('notice_pinned').checked;
			var pubAt = document.getElementById('notice_published_at').value.trim();
			var ts = nowStr();
			var list = loadList();
			if (id) {
				var idx = list.findIndex(function (r) { return r.id === id; });
				if (idx < 0) return;
				list[idx] = {
					id: id,
					title: title,
					body: body,
					category: category,
					pinned: pinned,
					status: status,
					published_at: status === 'published' ? (pubAt || ts) : pubAt,
					updated_at: ts,
				};
			} else {
				id = 'nt-' + Date.now();
				list.unshift({
					id: id,
					title: title,
					body: body,
					category: category,
					pinned: pinned,
					status: status,
					published_at: status === 'published' ? (pubAt || ts) : pubAt,
					updated_at: ts,
				});
			}
			saveList(list);
			render();
			var modalEl = document.getElementById('kt_notice_modal');
			var mi = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
			mi.hide();
		});

		document.getElementById('kt_notice_modal').addEventListener('show.bs.modal', function (ev) {
			if (ev.target !== this) return;
			if (!document.getElementById('notice_id').value) openCreate();
		});

		render();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

$mockBannersSeed = [
    [
        'id' => 'ad-20260501-001',
        'title' => '제휴 보험 가입 프로모션',
        'subtitle' => '라이더 전용 요금 · 한정 기간',
        'link_url' => 'https://example.com/ads/insurance-may',
        'image_url' => '/assets/media/banners/ad-insurance-202605.svg',
        'slot' => 'home_top',
        'sort_order' => 10,
        'status' => 'active',
        'start_at' => '2026-05-01',
        'end_at' => '2026-05-31',
        'updated_at' => '2026-05-01 09:00',
    ],
    [
        'id' => 'ad-20260420-002',
        'title' => '배달 장비 할인 스폰서',
        'subtitle' => '클릭 시 외부 쇼핑몰',
        'link_url' => 'https://example.com/ads/gear-shop',
        'image_url' => '/assets/media/banners/ad-gear-202604.svg',
        'slot' => 'home_middle',
        'sort_order' => 20,
        'status' => 'active',
        'start_at' => '2026-04-20',
        'end_at' => '',
        'updated_at' => '2026-04-20 11:30',
    ],
    [
        'id' => 'ad-20260310-003',
        'title' => '[종료] 봄 시즌 브랜드 광고',
        'subtitle' => '캠페인 종료 · 비활성 보관',
        'link_url' => '',
        'image_url' => '/assets/media/banners/ad-brand-spring.svg',
        'slot' => 'home_top',
        'sort_order' => 99,
        'status' => 'inactive',
        'start_at' => '2026-03-01',
        'end_at' => '2026-03-31',
        'updated_at' => '2026-04-01 00:00',
    ],
];
$bannersSeedJson = json_encode($mockBannersSeed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$slotLabels = [
    'home_top' => '앱 홈 상단 광고',
    'home_middle' => '앱 홈 중단 광고',
    'rider_app' => '라이더 홈 배너',
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">광고 배너</h1>
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
				<li class="breadcrumb-item text-gray-900">광고 배너</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">공지 관리</a>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_banner_modal" id="btn_banner_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				광고 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-picture fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. <strong>광고 소재(이미지)</strong>와 <strong>랜딩 URL</strong>을 작성해 노출 위치·기간에 맞게 송출하는 흐름입니다. 이미지 경로는 모달에서만 입력합니다.
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">광고 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">노출 위치 · 송출 순서(작을수록 앞) · 집행 기간</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="banner_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-120px">광고 ID</th>
							<th class="min-w-200px">광고명 / 문구</th>
							<th class="min-w-140px">노출 위치</th>
							<th class="min-w-70px text-center">순서</th>
							<th class="min-w-90px">송출</th>
							<th class="min-w-120px">집행 기간</th>
							<th class="min-w-120px">수정일시</th>
							<th class="min-w-140px text-end">관리</th>
						</tr>
					</thead>
					<tbody id="banner_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="modal fade" id="kt_banner_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="banner_modal_title">광고 등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-10 px-lg-10">
					<form id="banner_form">
						<input type="hidden" id="banner_id" value="" />
						<div class="mb-6">
							<label class="form-label required">광고 제목 (관리용)</label>
							<input type="text" class="form-control form-control-solid" id="banner_title" required maxlength="120" placeholder="예: 5월 제휴 보험 캠페인" />
						</div>
						<div class="mb-6">
							<label class="form-label">광고 문구 (부제·캡션)</label>
							<input type="text" class="form-control form-control-solid" id="banner_subtitle" maxlength="200" placeholder="앱에 노출될 짧은 문구(선택)" />
						</div>
						<div class="mb-6">
							<label class="form-label">랜딩 URL (클릭 시 이동)</label>
							<input type="url" class="form-control form-control-solid" id="banner_link_url" placeholder="https://… (제휴·이벤트 페이지)" />
							<div class="form-text">없으면 이미지만 노출되는 광고로 둘 수 있습니다.</div>
						</div>
						<div class="mb-6">
							<label class="form-label required">광고 이미지 URL</label>
							<input type="text" class="form-control form-control-solid" id="banner_image_url" required placeholder="/assets/media/banners/… 또는 CDN 경로" />
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">노출 위치</label>
								<select class="form-select form-select-solid" id="banner_slot">
									<?php foreach ($slotLabels as $val => $label) : ?>
									<option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label">송출 순서</label>
								<input type="number" class="form-control form-control-solid" id="banner_sort_order" value="100" min="0" max="9999" />
								<div class="form-text">같은 위치에서 숫자가 작을수록 먼저 노출</div>
							</div>
						</div>
						<div class="mb-6">
							<label class="form-label">송출 상태</label>
							<select class="form-select form-select-solid" id="banner_status">
								<option value="active">집행 중</option>
								<option value="inactive">중지</option>
							</select>
						</div>
						<div class="row g-6 mb-8">
							<div class="col-md-6">
								<label class="form-label">집행 시작일</label>
								<input type="text" class="form-control form-control-solid" id="banner_start_at" placeholder="YYYY-MM-DD" autocomplete="off" />
							</div>
							<div class="col-md-6">
								<label class="form-label">집행 종료일 (선택)</label>
								<input type="text" class="form-control form-control-solid" id="banner_end_at" placeholder="YYYY-MM-DD" autocomplete="off" />
							</div>
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
		var STORAGE_KEY = 'baedal_content_banners';
		var SEED = <?= $bannersSeedJson ?>;
		var SLOT_LABELS = <?= json_encode($slotLabels, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

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

		function escapeHtml(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : String(s);
			return d.innerHTML;
		}
		function escapeAttr(s) {
			return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
		}

		function slotLabel(key) {
			return SLOT_LABELS[key] || key;
		}

		function periodRow(row) {
			var a = row.start_at || '—';
			var b = row.end_at || '—';
			return escapeHtml(a + ' ~ ' + b);
		}

		function render() {
			var list = loadList().slice().sort(function (a, b) {
				var s = (a.slot || '').localeCompare(b.slot || '');
				if (s !== 0) return s;
				return (parseInt(a.sort_order, 10) || 0) - (parseInt(b.sort_order, 10) || 0);
			});
			var tb = document.getElementById('banner_tbody');
			tb.innerHTML = '';
			list.forEach(function (row) {
				var st = row.status === 'active'
					? { t: '집행 중', c: 'success' }
					: { t: '중지', c: 'dark' };
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td class="fw-semibold text-gray-800">' + escapeHtml(row.id) + '</td>' +
					'<td><span class="text-gray-900 fw-bold">' + escapeHtml(row.title) + '</span>' +
					(row.subtitle ? '<span class="text-muted fs-7 d-block">' + escapeHtml(row.subtitle) + '</span>' : '') +
					(row.link_url ? '<a href="' + escapeAttr(row.link_url) + '" class="fs-7 d-block text-primary text-hover-primary" target="_blank" rel="noopener">랜딩</a>' : '') +
					'</td>' +
					'<td><span class="badge badge-light-primary">' + escapeHtml(slotLabel(row.slot)) + '</span></td>' +
					'<td class="text-center fw-bold">' + escapeHtml(String(row.sort_order)) + '</td>' +
					'<td><span class="badge badge-light-' + st.c + '">' + st.t + '</span></td>' +
					'<td class="fs-7 text-gray-700">' + periodRow(row) + '</td>' +
					'<td class="text-gray-700">' + escapeHtml(row.updated_at || '—') + '</td>' +
					'<td class="text-end">' +
					'<button type="button" class="btn btn-sm btn-light-primary me-1 btn-banner-edit" data-id="' + escapeAttr(row.id) + '">수정</button>' +
					'<button type="button" class="btn btn-sm btn-light-danger btn-banner-del" data-id="' + escapeAttr(row.id) + '">삭제</button>' +
					'</td>';
				tb.appendChild(tr);
			});
		}

		function openCreate() {
			document.getElementById('banner_modal_title').textContent = '광고 등록';
			document.getElementById('banner_id').value = '';
			document.getElementById('banner_title').value = '';
			document.getElementById('banner_subtitle').value = '';
			document.getElementById('banner_link_url').value = '';
			document.getElementById('banner_image_url').value = '/assets/media/banners/';
			document.getElementById('banner_slot').value = 'home_top';
			document.getElementById('banner_sort_order').value = '100';
			document.getElementById('banner_status').value = 'active';
			document.getElementById('banner_start_at').value = '';
			document.getElementById('banner_end_at').value = '';
		}

		function openEdit(id) {
			var list = loadList();
			var row = list.find(function (r) { return r.id === id; });
			if (!row) return;
			document.getElementById('banner_modal_title').textContent = '광고 수정';
			document.getElementById('banner_id').value = row.id;
			document.getElementById('banner_title').value = row.title;
			document.getElementById('banner_subtitle').value = row.subtitle || '';
			document.getElementById('banner_link_url').value = row.link_url || '';
			document.getElementById('banner_image_url').value = row.image_url;
			document.getElementById('banner_slot').value = row.slot;
			document.getElementById('banner_sort_order').value = String(row.sort_order);
			document.getElementById('banner_status').value = row.status;
			document.getElementById('banner_start_at').value = row.start_at || '';
			document.getElementById('banner_end_at').value = row.end_at || '';
			var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_banner_modal'));
			m.show();
		}

		document.getElementById('btn_banner_create').addEventListener('click', function () {
			openCreate();
		});

		document.getElementById('banner_tbody').addEventListener('click', function (ev) {
			var ed = ev.target.closest('.btn-banner-edit');
			var del = ev.target.closest('.btn-banner-del');
			if (ed) openEdit(ed.getAttribute('data-id'));
			if (del) {
				var id = del.getAttribute('data-id');
				if (!window.confirm('이 광고 배너를 삭제할까요? (목업)')) return;
				saveList(loadList().filter(function (r) { return r.id !== id; }));
				render();
			}
		});

		document.getElementById('banner_form').addEventListener('submit', function (ev) {
			ev.preventDefault();
			var id = document.getElementById('banner_id').value.trim();
			var row = {
				id: id || ('ad-' + Date.now()),
				title: document.getElementById('banner_title').value.trim(),
				subtitle: document.getElementById('banner_subtitle').value.trim(),
				link_url: document.getElementById('banner_link_url').value.trim(),
				image_url: document.getElementById('banner_image_url').value.trim(),
				slot: document.getElementById('banner_slot').value,
				sort_order: parseInt(document.getElementById('banner_sort_order').value, 10) || 0,
				status: document.getElementById('banner_status').value,
				start_at: document.getElementById('banner_start_at').value.trim(),
				end_at: document.getElementById('banner_end_at').value.trim(),
				updated_at: nowStr(),
			};
			var list = loadList();
			if (id) {
				var idx = list.findIndex(function (r) { return r.id === id; });
				if (idx < 0) return;
				row.id = id;
				list[idx] = row;
			} else {
				list.unshift(row);
			}
			saveList(list);
			render();
			var modalEl = document.getElementById('kt_banner_modal');
			var mi = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
			mi.hide();
		});

		render();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/Banner.php';

$listError = null;
$banners   = [];
$apiUrl       = ADMIN_BASE . '/api/banners.php';
$uploadApiUrl = ADMIN_BASE . '/api/banner_upload.php';
$slotLabels = Banner::SLOT_LABELS;

try {
    $banners = Banner::listAdmin();
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$needsMigrate = $listError !== null
    && (str_contains($listError, 'content_banners') || str_contains($listError, "doesn't exist"));
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
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">콘텐츠</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">광고 배너</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">공지 관리</a>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_banner_modal" id="btn_banner_create"<?= $needsMigrate ? ' disabled' : '' ?>>
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				광고 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">
		<strong>DB 테이블이 없습니다.</strong> 서버에서 <code>php migrate.php</code> 를 실행한 뒤 새로고침하세요.
	</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-picture fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>집행 중</strong>이고 기간 내인 광고가 라이더 앱 홈 롤링 배너에 노출됩니다. 이미지는 <strong>파일 업로드</strong> 또는 URL로 등록할 수 있습니다.
		</div>
	</div>
	<?php endif; ?>

	<div id="banner_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="banner_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
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
							<th class="min-w-50px"></th>
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
					<tbody id="banner_tbody">
						<?php if ($listError === null && $banners === []) : ?>
						<tr><td colspan="9" class="text-center text-muted py-10">등록된 광고가 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($banners as $row) :
						    $period = ($row['start_at'] !== '' ? $row['start_at'] : '—') . ' ~ ' . ($row['end_at'] !== '' ? $row['end_at'] : '—');
						    ?>
						<tr>
							<td class="w-50px">
								<?php if ($row['image_src'] !== '') : ?>
								<img src="<?= htmlspecialchars($row['image_src'], ENT_QUOTES, 'UTF-8') ?>" alt="" class="rounded h-40px w-50px object-fit-cover bg-light" loading="lazy" />
								<?php endif; ?>
							</td>
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($row['public_id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-900 fw-bold"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php if ($row['subtitle'] !== '') : ?>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['subtitle'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php endif; ?>
								<?php if ($row['link_url'] !== '') : ?>
								<a href="<?= htmlspecialchars($row['link_url'], ENT_QUOTES, 'UTF-8') ?>" class="fs-7 d-block text-primary text-hover-primary" target="_blank" rel="noopener">랜딩</a>
								<?php endif; ?>
							</td>
							<td><span class="badge badge-light-primary"><?= htmlspecialchars($row['slot_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-center fw-bold"><?= (int) $row['sort_order'] ?></td>
							<td><span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="fs-7 text-gray-700"><?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light-primary me-1 btn-banner-edit"
									data-id="<?= (int) $row['id'] ?>"
									data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
								<button type="button" class="btn btn-sm btn-light-danger btn-banner-del" data-id="<?= (int) $row['id'] ?>">삭제</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
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
							<input type="text" class="form-control form-control-solid" id="banner_title" required maxlength="120" />
						</div>
						<div class="mb-6">
							<label class="form-label">광고 문구 (부제·캡션)</label>
							<input type="text" class="form-control form-control-solid" id="banner_subtitle" maxlength="200" />
						</div>
						<div class="mb-6">
							<label class="form-label">랜딩 URL (클릭 시 이동)</label>
							<input type="url" class="form-control form-control-solid" id="banner_link_url" placeholder="https://…" />
						</div>
						<div class="mb-6">
							<label class="form-label required">광고 이미지</label>
							<div class="border border-dashed border-gray-300 rounded p-4 mb-3 bg-light">
								<div id="banner_image_preview_wrap" class="d-none mb-3 text-center">
									<img id="banner_image_preview" src="" alt="미리보기" class="rounded mw-100" style="max-height: 140px;" />
								</div>
								<div class="d-flex flex-wrap gap-2 align-items-center">
									<input type="file" class="form-control form-control-solid form-control-sm w-auto flex-grow-1" id="banner_image_file" accept="image/jpeg,image/png,image/webp,image/gif" />
									<button type="button" class="btn btn-sm btn-light-primary fw-bold" id="banner_image_upload_btn">업로드</button>
								</div>
								<div class="form-text mt-2">JPG·PNG·WebP·GIF, 최대 5MB</div>
							</div>
							<label class="form-label fs-7 text-muted">이미지 경로 (업로드 시 자동 입력 · CDN/기존 자산 URL도 가능)</label>
							<input type="text" class="form-control form-control-solid" id="banner_image_url" required placeholder="/uploads/banners/… 또는 /assets/media/banners/…" />
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
								<input type="date" class="form-control form-control-solid" id="banner_start_at" />
							</div>
							<div class="col-md-6">
								<label class="form-label">집행 종료일 (선택)</label>
								<input type="date" class="form-control form-control-solid" id="banner_end_at" />
							</div>
						</div>
						<div class="d-flex justify-content-end gap-3">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
							<button type="submit" class="btn btn-primary" id="banner_submit_btn">저장</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_THROW_ON_ERROR) ?>;
		var UPLOAD_API = <?= json_encode($uploadApiUrl, JSON_THROW_ON_ERROR) ?>;
		var ASSETS_BASE = <?= json_encode(web_assets_base(), JSON_THROW_ON_ERROR) ?>;
		var UPLOADS_BASE = <?= json_encode(web_uploads_base(), JSON_THROW_ON_ERROR) ?>;
		var disabled = <?= $needsMigrate ? 'true' : 'false' ?>;

		function previewSrcFromPath(path) {
			var v = (path || '').trim();
			if (!v) return '';
			if (/^https?:\/\//i.test(v)) return v;
			if (v.indexOf('/uploads/') === 0) return UPLOADS_BASE + v.slice('/uploads'.length);
			if (v.indexOf('/assets/') === 0) return ASSETS_BASE + v.slice('/assets'.length);
			return '';
		}

		function toast(msg, ok) {
			var el = document.getElementById('banner_toast');
			var msgEl = document.getElementById('banner_toast_msg');
			if (!el || !msgEl) return;
			el.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			msgEl.textContent = msg;
			el.classList.remove('d-none');
		}

		function apiPost(payload) {
			return fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(payload),
			}).then(function (res) {
				return res.json().then(function (j) {
					if (!res.ok || !j.ok) throw new Error(j.message || '요청 실패');
					return j;
				});
			});
		}

		function setImagePreview(src) {
			var wrap = document.getElementById('banner_image_preview_wrap');
			var img = document.getElementById('banner_image_preview');
			if (!wrap || !img) return;
			if (src) {
				img.src = src;
				wrap.classList.remove('d-none');
			} else {
				img.removeAttribute('src');
				wrap.classList.add('d-none');
			}
		}

		function openCreate() {
			document.getElementById('banner_modal_title').textContent = '광고 등록';
			document.getElementById('banner_id').value = '';
			document.getElementById('banner_title').value = '';
			document.getElementById('banner_subtitle').value = '';
			document.getElementById('banner_link_url').value = '';
			document.getElementById('banner_image_url').value = '';
			document.getElementById('banner_image_file').value = '';
			setImagePreview('');
			document.getElementById('banner_slot').value = 'rider_app';
			document.getElementById('banner_sort_order').value = '100';
			document.getElementById('banner_status').value = 'active';
			document.getElementById('banner_start_at').value = '';
			document.getElementById('banner_end_at').value = '';
		}

		function openEdit(row) {
			document.getElementById('banner_modal_title').textContent = '광고 수정';
			document.getElementById('banner_id').value = String(row.id);
			document.getElementById('banner_title').value = row.title || '';
			document.getElementById('banner_subtitle').value = row.subtitle || '';
			document.getElementById('banner_link_url').value = row.link_url || '';
			document.getElementById('banner_image_url').value = row.image_url || '';
			document.getElementById('banner_image_file').value = '';
			setImagePreview(row.image_src || '');
			document.getElementById('banner_slot').value = row.slot || 'rider_app';
			document.getElementById('banner_sort_order').value = String(row.sort_order);
			document.getElementById('banner_status').value = row.status || 'inactive';
			document.getElementById('banner_start_at').value = row.start_at || '';
			document.getElementById('banner_end_at').value = row.end_at || '';
			bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_banner_modal')).show();
		}

		function uploadBannerImage() {
			var input = document.getElementById('banner_image_file');
			var btn = document.getElementById('banner_image_upload_btn');
			if (!input || !input.files || !input.files[0]) {
				toast('이미지 파일을 선택하세요.', false);
				return Promise.reject();
			}
			var fd = new FormData();
			fd.append('image', input.files[0]);
			btn.disabled = true;
			return fetch(UPLOAD_API, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (res) {
					return res.json().then(function (j) {
						if (!res.ok || !j.ok) throw new Error(j.message || '업로드 실패');
						return j;
					});
				})
				.then(function (j) {
					document.getElementById('banner_image_url').value = j.image_url || '';
					setImagePreview(j.image_src || '');
					toast('이미지가 업로드되었습니다.', true);
				})
				.catch(function (e) { toast(e.message || String(e), false); })
				.finally(function () { btn.disabled = false; });
		}

		if (!disabled) {
			document.getElementById('btn_banner_create').addEventListener('click', openCreate);
			document.getElementById('banner_image_upload_btn').addEventListener('click', function () {
				uploadBannerImage();
			});
			document.getElementById('banner_image_url').addEventListener('input', function () {
				setImagePreview(previewSrcFromPath(this.value));
			});
			document.getElementById('banner_tbody').addEventListener('click', function (ev) {
				var ed = ev.target.closest('.btn-banner-edit');
				var del = ev.target.closest('.btn-banner-del');
				if (ed) {
					try { openEdit(JSON.parse(ed.getAttribute('data-json') || '{}')); }
					catch (e) { toast('데이터 오류', false); }
				}
				if (del) {
					if (!window.confirm('이 광고를 삭제할까요?')) return;
					apiPost({ action: 'delete', id: del.getAttribute('data-id') })
						.then(function () { toast('삭제되었습니다.', true); setTimeout(function () { location.reload(); }, 400); })
						.catch(function (e) { toast(e.message, false); });
				}
			});
			document.getElementById('banner_form').addEventListener('submit', function (ev) {
				ev.preventDefault();
				if (!document.getElementById('banner_image_url').value.trim()) {
					toast('이미지를 업로드하거나 경로를 입력하세요.', false);
					return;
				}
				var btn = document.getElementById('banner_submit_btn');
				btn.disabled = true;
				apiPost({
					action: 'save',
					id: document.getElementById('banner_id').value.trim() || undefined,
					title: document.getElementById('banner_title').value.trim(),
					subtitle: document.getElementById('banner_subtitle').value.trim(),
					link_url: document.getElementById('banner_link_url').value.trim(),
					image_url: document.getElementById('banner_image_url').value.trim(),
					slot: document.getElementById('banner_slot').value,
					sort_order: parseInt(document.getElementById('banner_sort_order').value, 10) || 0,
					status: document.getElementById('banner_status').value,
					start_at: document.getElementById('banner_start_at').value,
					end_at: document.getElementById('banner_end_at').value,
				})
					.then(function () {
						toast('저장되었습니다.', true);
						(bootstrap.Modal.getInstance(document.getElementById('kt_banner_modal')) || bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_banner_modal'))).hide();
						setTimeout(function () { location.reload(); }, 400);
					})
					.catch(function (e) { toast(e.message, false); })
					.finally(function () { btn.disabled = false; });
			});
			document.getElementById('kt_banner_modal').addEventListener('show.bs.modal', function (ev) {
				if (ev.target === this && !document.getElementById('banner_id').value) openCreate();
			});
		}
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

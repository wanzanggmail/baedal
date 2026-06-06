<?php

declare(strict_types=1);

require_once INC_PATH . '/Notice.php';

$listError = null;
$notices   = [];
$apiUrl    = ADMIN_BASE . '/api/notices.php';

try {
    $notices = Notice::listAdmin();
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$needsMigrate = $listError !== null
    && (str_contains($listError, 'content_notices') || str_contains($listError, "doesn't exist"));
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
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">콘텐츠</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">공지</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('content/banners'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">광고 배너</a>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_notice_modal" id="btn_notice_create"<?= $needsMigrate ? ' disabled' : '' ?>>
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				공지 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">
		<strong>DB 테이블이 없습니다.</strong> 서버에서 <code>php migrate.php</code> 를 한 번 실행한 뒤 이 페이지를 새로고침하세요.
	</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-notepad fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			게시된 공지는 <strong>라이더 앱</strong> 공지 목록·로그인 팝업(상단 고정 우선)에 표시됩니다. 정산·출금 로직과는 분리되어 있습니다.
		</div>
	</div>
	<?php endif; ?>

	<div id="notice_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="notice_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
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
					<tbody id="notice_tbody">
						<?php if ($listError === null && $notices === []) : ?>
						<tr><td colspan="8" class="text-center text-muted py-10">등록된 공지가 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($notices as $row) :
						    $excerpt = mb_strlen($row['body']) > 48
						        ? mb_substr(preg_replace('/\s+/u', ' ', $row['body']), 0, 48) . '…'
						        : $row['body'];
						    ?>
						<tr data-id="<?= (int) $row['id'] ?>">
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($row['public_id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-900 fw-bold"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><span class="badge badge-light-primary"><?= htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td><?= $row['pinned'] ? '<span class="badge badge-light-success">고정</span>' : '<span class="text-muted">—</span>' ?></td>
							<td><span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-gray-700"><?= $row['published_at'] !== '' ? htmlspecialchars($row['published_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light-primary me-1 btn-notice-edit"
									data-id="<?= (int) $row['id'] ?>"
									data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
								<button type="button" class="btn btn-sm btn-light-danger btn-notice-del" data-id="<?= (int) $row['id'] ?>">삭제</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
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
									<?php foreach (Notice::categories() as $cat) : ?>
									<option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
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
								<span class="form-check-label fw-semibold text-gray-700">상단 고정 (로그인 팝업 우선)</span>
							</label>
						</div>
						<div class="mb-6">
							<label class="form-label">게시일시 (비어 있으면 게시 시 현재 시각)</label>
							<input type="text" class="form-control form-control-solid" id="notice_published_at" placeholder="YYYY-MM-DD HH:mm" autocomplete="off" />
						</div>
						<div class="mb-8">
							<label class="form-label required">본문</label>
							<textarea class="form-control form-control-solid" id="notice_body" rows="8" required placeholder="공지 내용"></textarea>
						</div>
						<div class="d-flex justify-content-end gap-3">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
							<button type="submit" class="btn btn-primary" id="notice_submit_btn">저장</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_THROW_ON_ERROR) ?>;
		var disabled = <?= $needsMigrate ? 'true' : 'false' ?>;

		function toast(msg, ok) {
			var el = document.getElementById('notice_toast');
			var msgEl = document.getElementById('notice_toast_msg');
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

		function openEdit(row) {
			document.getElementById('notice_modal_title').textContent = '공지 수정';
			document.getElementById('notice_id').value = String(row.id);
			document.getElementById('notice_title').value = row.title || '';
			document.getElementById('notice_category').value = row.category || '일반';
			document.getElementById('notice_status').value = row.status || 'draft';
			document.getElementById('notice_pinned').checked = !!row.pinned;
			document.getElementById('notice_published_at').value = row.published_at || '';
			document.getElementById('notice_body').value = row.body || '';
			var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_notice_modal'));
			m.show();
		}

		if (!disabled) {
			document.getElementById('btn_notice_create').addEventListener('click', openCreate);

			document.getElementById('notice_tbody').addEventListener('click', function (ev) {
				var ed = ev.target.closest('.btn-notice-edit');
				var del = ev.target.closest('.btn-notice-del');
				if (ed) {
					try {
						openEdit(JSON.parse(ed.getAttribute('data-json') || '{}'));
					} catch (e) { toast('데이터 오류', false); }
				}
				if (del) {
					var id = del.getAttribute('data-id');
					if (!window.confirm('이 공지를 삭제할까요?')) return;
					apiPost({ action: 'delete', id: id })
						.then(function () {
							toast('삭제되었습니다.', true);
							setTimeout(function () { location.reload(); }, 400);
						})
						.catch(function (e) { toast(e.message, false); });
				}
			});

			document.getElementById('notice_form').addEventListener('submit', function (ev) {
				ev.preventDefault();
				var btn = document.getElementById('notice_submit_btn');
				btn.disabled = true;
				apiPost({
					action: 'save',
					id: document.getElementById('notice_id').value.trim() || undefined,
					title: document.getElementById('notice_title').value.trim(),
					body: document.getElementById('notice_body').value,
					category: document.getElementById('notice_category').value,
					status: document.getElementById('notice_status').value,
					pinned: document.getElementById('notice_pinned').checked,
					published_at: document.getElementById('notice_published_at').value.trim(),
				})
					.then(function () {
						toast('저장되었습니다.', true);
						var modalEl = document.getElementById('kt_notice_modal');
						(bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl)).hide();
						setTimeout(function () { location.reload(); }, 400);
					})
					.catch(function (e) { toast(e.message, false); })
					.finally(function () { btn.disabled = false; });
			});

			document.getElementById('kt_notice_modal').addEventListener('show.bs.modal', function (ev) {
				if (ev.target !== this) return;
				if (!document.getElementById('notice_id').value) openCreate();
			});
		}
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

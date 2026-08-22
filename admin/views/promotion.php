<?php

declare(strict_types=1);

/**
 * 프로모션 지급 — 템플릿 다운로드 → 금액 채워 업로드 → 미리보기 → 확정 지급.
 * 확정 시 라이더별 카드결제(프로모션액 + 플랫폼 수수료) 후 성공 건만 지갑 적립.
 */

require_once INC_PATH . '/Promotion.php';
require_once INC_PATH . '/Organization.php';

$needsMigrate = !Promotion::tableReady();
$isAgency     = admin_org_level() === Org::LEVEL_AGENCY;
$agencyOptions = $isAgency ? [] : Organization::agencyOptions();

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-90 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$batches = $needsMigrate ? [] : Promotion::listBatches(['from' => $filterFrom, 'to' => $filterTo]);

$statusLabels = [
    'draft'   => ['미지급', 'badge-light-secondary'],
    'paid'    => ['지급 완료', 'badge-light-success'],
    'partial' => ['일부 실패', 'badge-light-warning'],
    'failed'  => ['전건 실패', 'badge-light-danger'],
];

$listUrl     = admin_url('promotion');
$detailBase  = admin_url('promotion/detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';
$templateApi = rtrim(ADMIN_BASE, '/') . '/api/promotion_template.php';
$uploadApi   = rtrim(ADMIN_BASE, '/') . '/api/promotion_upload.php';
$fmtWon      = static fn (int $n): string => number_format($n) . '원';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 지급</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">프로모션</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-gift fs-2hx text-primary me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			① <strong>템플릿 다운로드</strong> → ② 엑셀에 <strong>프로모션1·프로모션2</strong> 금액 입력 → ③ 업로드 → ④ 미리보기 확인 후 <strong>지급 확정</strong>
			<span class="d-block mt-1 text-gray-700">
				지급 확정 시 <strong>라이더 1명씩 카드로 결제</strong>(프로모션 금액 + 플랫폼 수수료)한 뒤, 결제가 성공한 건만 라이더 지갑에 적립됩니다.
				일부가 실패해도 나머지는 정상 지급되며, 실패 건은 상세 화면에서 재시도할 수 있습니다.
			</span>
		</div>
	</div>

	<div id="promoResult" class="d-none mb-8"></div>

	<div class="row g-5 g-xl-10 mb-8">
		<!--begin::업로드 폼-->
		<div class="col-xl-5">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title fw-bold">프로모션 업로드</h3>
				</div>
				<div class="card-body pt-5">
					<form id="promoForm" enctype="multipart/form-data" accept-charset="UTF-8">
						<?php if (!$isAgency) : ?>
						<div class="mb-6">
							<label class="form-label required">대리점</label>
							<select class="form-select form-select-solid" name="agency_id" id="promoAgency">
								<option value="">대리점 선택</option>
								<?php foreach ($agencyOptions as $ag) : ?>
								<option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name'] . ' (' . $ag['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
								<?php endforeach; ?>
							</select>
							<div class="form-text">프로모션을 지급할 대리점입니다. 템플릿도 이 대리점 라이더로 만들어집니다.</div>
						</div>
						<?php endif; ?>

						<div class="mb-6">
							<button type="button" class="btn btn-light-primary w-100" id="promoTplBtn">
								<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
								① 템플릿 다운로드 (라이더 목록 포함)
							</button>
							<div class="form-text">컬럼: <?= htmlspecialchars(implode(' / ', Promotion::TEMPLATE_HEADERS), ENT_QUOTES, 'UTF-8') ?></div>
						</div>

						<div class="mb-6">
							<label class="form-label required">지급 일자</label>
							<input type="date" class="form-control form-control-solid" name="pay_date" id="promoDate" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" />
							<div class="form-text">이 날짜로 지급 처리됩니다.</div>
						</div>

						<div class="mb-6">
							<label class="form-label required">작성한 엑셀 파일</label>
							<input type="file" class="form-control form-control-solid" name="file" id="promoFile" accept=".xlsx" />
						</div>

						<div class="mb-6">
							<label class="form-label">메모 <span class="text-muted fs-8">(선택)</span></label>
							<input type="text" class="form-control form-control-solid" name="memo" id="promoMemo" maxlength="255" placeholder="예: 8월 1주차 프로모션" />
						</div>

						<button type="submit" class="btn btn-primary w-100" id="promoPreviewBtn">
							<span class="indicator-label">
								<i class="ki-duotone ki-eye fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								미리보기 · 대상 확인
							</span>
							<span class="indicator-progress">처리 중... <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
						</button>
					</form>
				</div>
			</div>
		</div>
		<!--end::업로드 폼-->

		<div class="col-xl-7">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title fw-bold">최근 지급 이력</h3>
					<div class="card-toolbar">
						<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-flex gap-2 align-items-center">
							<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
							<input type="hidden" name="route" value="promotion" />
							<?php endif; ?>
							<input type="date" class="form-control form-control-sm form-control-solid w-auto" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
							<input type="date" class="form-control form-control-sm form-control-solid w-auto" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
							<button type="submit" class="btn btn-sm btn-light-primary">조회</button>
						</form>
					</div>
				</div>
				<div class="card-body pt-3">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gy-3 mb-0">
							<thead>
								<tr class="fw-bold text-muted fs-7 bg-light">
									<th>지급일자</th>
									<?php if (!$isAgency) : ?><th>대리점</th><?php endif; ?>
									<th class="text-end">대상</th>
									<th class="text-end">지급액</th>
									<th class="text-end">수수료</th>
									<th>상태</th>
									<th class="text-end"></th>
								</tr>
							</thead>
							<tbody>
								<?php if ($batches === []) : ?>
								<tr><td colspan="<?= $isAgency ? 6 : 7 ?>" class="text-center text-muted py-10">지급 이력이 없습니다.</td></tr>
								<?php endif; ?>
								<?php foreach ($batches as $b) :
								    [$stLabel, $stBadge] = $statusLabels[$b['status']] ?? [(string) $b['status'], 'badge-light'];
								    ?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars((string) $b['pay_date'], ENT_QUOTES, 'UTF-8') ?></td>
									<?php if (!$isAgency) : ?>
									<td class="text-gray-700 fs-7"><?= htmlspecialchars((string) ($b['agency_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
									<?php endif; ?>
									<td class="text-end"><?= number_format((int) $b['paid_riders']) ?> / <?= number_format((int) $b['total_riders']) ?>명</td>
									<td class="text-end fw-bold text-primary"><?= $fmtWon((int) $b['paid_amount']) ?></td>
									<td class="text-end text-muted fs-8"><?= $fmtWon((int) $b['fee_amount']) ?></td>
									<td><span class="badge <?= htmlspecialchars($stBadge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
									<td class="text-end">
										<a href="<?= htmlspecialchars($detailBase . 'id=' . (int) $b['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">상세</a>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!--begin::미리보기 모달-->
	<div class="modal fade" id="kt_promo_preview_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">프로모션 지급 미리보기</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body">
					<div id="promoSummary" class="mb-4"></div>
					<div id="promoDupWarn" class="alert bg-light-warning fs-8 p-3 mb-4 d-none"></div>
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gy-2 fs-7">
							<thead><tr class="fw-bold text-muted bg-light">
								<th>이름</th><th class="text-end">프로모션1</th><th class="text-end">프로모션2</th><th class="text-end">합계</th><th>상태</th>
							</tr></thead>
							<tbody id="promoTbody"></tbody>
						</table>
					</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="promoConfirmBtn">
						<span class="indicator-label">지급 확정 (카드결제 실행)</span>
						<span class="indicator-progress">지급 중... <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
					</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::미리보기 모달-->

	<script>
	(function () {
		'use strict';
		var TPL_API = <?= json_encode($templateApi, JSON_UNESCAPED_UNICODE) ?>;
		var UP_API  = <?= json_encode($uploadApi, JSON_UNESCAPED_UNICODE) ?>;
		var IS_AGENCY = <?= $isAgency ? 'true' : 'false' ?>;

		var form = document.getElementById('promoForm');
		var result = document.getElementById('promoResult');
		var modalEl = document.getElementById('kt_promo_preview_modal');
		var confirmBtn = document.getElementById('promoConfirmBtn');
		var previewBtn = document.getElementById('promoPreviewBtn');

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function won(n) { return (Number(n) || 0).toLocaleString('ko-KR') + '원'; }
		function show(type, msg) {
			result.className = 'alert alert-' + type + ' mb-8';
			result.innerHTML = msg;
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}
		function agencyId() {
			if (IS_AGENCY) return null;
			var el = document.getElementById('promoAgency');
			return el ? el.value : '';
		}

		// 템플릿 다운로드
		document.getElementById('promoTplBtn').addEventListener('click', function () {
			var ag = agencyId();
			if (!IS_AGENCY && !ag) { show('danger', '먼저 대리점을 선택하세요.'); return; }
			window.location.href = TPL_API + (IS_AGENCY ? '' : ('?agency_id=' + encodeURIComponent(ag)));
		});

		function buildForm(mode) {
			var fd = new FormData(form);
			fd.append('mode', mode);
			return fd;
		}

		// 1단계: 미리보기
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!document.getElementById('promoFile').files[0]) { show('danger', '엑셀 파일을 선택하세요.'); return; }
			if (!IS_AGENCY && !agencyId()) { show('danger', '대리점을 선택하세요.'); return; }

			previewBtn.setAttribute('data-kt-indicator', 'on');
			previewBtn.disabled = true;
			result.className = 'd-none';

			fetch(UP_API, { method: 'POST', body: buildForm('preview'), credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d.ok) throw new Error(d.error || d.message || '오류');
					renderPreview(d);
					bootstrap.Modal.getOrCreateInstance(modalEl).show();
				})
				.catch(function (err) { show('danger', esc(err.message)); })
				.finally(function () {
					previewBtn.removeAttribute('data-kt-indicator');
					previewBtn.disabled = false;
				});
		});

		function renderPreview(d) {
			var s = d.summary;
			document.getElementById('promoSummary').innerHTML =
				'<div class="d-flex flex-wrap gap-2 fs-6">'
				+ '<span class="badge badge-light-primary fs-7 py-2">지급일 ' + esc(d.pay_date) + '</span>'
				+ '<span class="badge badge-light fs-7 py-2">총 ' + s.total_rows + '행</span>'
				+ '<span class="badge badge-light-success fs-7 py-2">지급 대상 ' + s.matched + '명</span>'
				+ (s.unmatched ? '<span class="badge badge-light-danger fs-7 py-2">미매칭 ' + s.unmatched + '명</span>' : '')
				+ (s.skipped ? '<span class="badge badge-light-secondary fs-7 py-2">제외(0원) ' + s.skipped + '명</span>' : '')
				+ '<span class="badge badge-light-info fs-7 py-2">지급 예정액 ' + won(s.total_amount) + '</span>'
				+ '</div>';

			var dup = document.getElementById('promoDupWarn');
			if (s.dup_riders && s.dup_riders.length) {
				dup.classList.remove('d-none');
				dup.innerHTML = '⚠ 같은 지급일자에 <strong>이미 프로모션을 받은 라이더</strong>가 있습니다 ('
					+ s.dup_riders.length + '명: ' + s.dup_riders.slice(0, 5).map(esc).join(', ')
					+ (s.dup_riders.length > 5 ? ' 외' : '') + '). 확정하면 <strong>추가로 더 지급</strong>됩니다.';
			} else {
				dup.classList.add('d-none');
			}

			var badge = { pending: '<span class="badge badge-light-success fs-8">지급 대상</span>',
			              unmatched: '<span class="badge badge-light-danger fs-8">미매칭</span>',
			              skipped: '<span class="badge badge-light-secondary fs-8">제외</span>' };
			document.getElementById('promoTbody').innerHTML = d.rows.map(function (r) {
				return '<tr class="' + (r.status === 'pending' ? '' : 'text-muted') + '">'
					+ '<td>' + esc(r.rider_name) + '</td>'
					+ '<td class="text-end">' + won(r.promo1) + '</td>'
					+ '<td class="text-end">' + won(r.promo2) + '</td>'
					+ '<td class="text-end fw-bold">' + won(r.total) + '</td>'
					+ '<td>' + (badge[r.status] || esc(r.status))
					+ (r.note ? '<span class="text-muted fs-9 d-block">' + esc(r.note) + '</span>' : '') + '</td></tr>';
			}).join('');

			confirmBtn.disabled = s.matched === 0;
		}

		// 2단계: 확정 지급
		confirmBtn.addEventListener('click', function () {
			if (!confirm('지급을 확정할까요? 라이더별로 카드결제가 실행되며 되돌릴 수 없습니다.')) return;
			confirmBtn.setAttribute('data-kt-indicator', 'on');
			confirmBtn.disabled = true;

			fetch(UP_API, { method: 'POST', body: buildForm('confirm'), credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d.ok) throw new Error(d.error || d.message || '오류');
					bootstrap.Modal.getInstance(modalEl).hide();
					var html = '<strong>' + esc(d.message) + '</strong>';
					if (d.fee_amount) html += '<div class="fs-7 mt-1">플랫폼 수수료 ' + won(d.fee_amount) + ' 포함 결제</div>';
					if (d.errors && d.errors.length) {
						html += '<ul class="mb-0 mt-2 fs-8">' + d.errors.slice(0, 10).map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
					}
					show(d.failed > 0 ? 'warning' : 'success', html);
					setTimeout(function () { location.reload(); }, 2500);
				})
				.catch(function (err) {
					bootstrap.Modal.getInstance(modalEl).hide();
					show('danger', esc(err.message));
				})
				.finally(function () {
					confirmBtn.removeAttribute('data-kt-indicator');
					confirmBtn.disabled = false;
				});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

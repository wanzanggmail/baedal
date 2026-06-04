<?php

declare(strict_types=1);

// 최근 업로드 이력 (일간)
$recentUploads = [];
try {
    $recentUploads = db_rows(
        'SELECT u.id, u.settlement_date, u.original_filename, u.platform,
                u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at,
                a.name AS uploaded_by_name, u.stored_path
           FROM settlement_uploads u
           LEFT JOIN admins a ON a.id = u.operator_id
          WHERE u.kind = ?
          ORDER BY u.created_at DESC
          LIMIT 10',
        ['daily']
    );
} catch (Throwable) {
}

$statusLabels = [
    'uploaded' => ['label' => '업로드됨', 'badge' => 'badge-light-primary'],
    'parsing'  => ['label' => '파싱 중', 'badge' => 'badge-light-warning'],
    'parsed'   => ['label' => '파싱완료', 'badge' => 'badge-light-success'],
    'applied'  => ['label' => '반영완료', 'badge' => 'badge-light-info'],
    'error'    => ['label' => '오류',     'badge' => 'badge-light-danger'],
];

$platformLabels = [
    'baemin'  => '배달의민족',
    'coupang' => '쿠팡이츠',
    'other'   => '기타',
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산 엑셀 업로드</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">정산</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">업로드</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 gap-lg-3">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-success fw-bold">
				<i class="ki-duotone ki-wallet fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
				출금 신청 목록
			</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">
				<i class="ki-duotone ki-time fs-3"><span class="path1"></span><span class="path2"></span></i>
				업로드 이력
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<ul class="nav nav-tabs nav-line-tabs nav-line-tabs-2x border-transparent fs-5 fw-bold mb-8">
		<li class="nav-item">
			<a class="nav-link text-active-primary pb-4 active" data-bs-toggle="tab" href="#kt_tab_daily">일간 정산서</a>
		</li>
		<li class="nav-item">
			<a class="nav-link text-active-primary pb-4" data-bs-toggle="tab" href="#kt_tab_weekly">주간 정산서 (차감)</a>
		</li>
	</ul>

	<div class="tab-content" id="settlementUploadTabs">
		<!--begin::업로드 탭-->
		<div class="tab-pane fade show active" id="kt_tab_daily" role="tabpanel">
			<div class="row g-5 g-xl-10">
				<!--begin::업로드 폼-->
				<div class="col-xl-5">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title align-items-start flex-column">
								<span class="card-label fw-bold text-gray-900">일간 정산서 업로드</span>
								<span class="text-gray-500 mt-1 fw-semibold fs-6">배달의민족 일별 정산서(.xlsx) → DB 저장</span>
							</h3>
						</div>
						<div class="card-body pt-5">
							<div class="alert alert-primary d-flex align-items-center p-5 mb-8">
								<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">플랫폼에서 받은 일간 정산서(.xlsx)를 그대로 올려 주세요.</span>
									<span class="text-gray-700 fs-7 mt-1">파일명에 날짜가 포함된 경우(예: 팀도깨비_서울_강서남부_<strong>20260225</strong>.xlsx) 귀속일이 자동 추출됩니다.</span>
								</div>
							</div>

							<!--begin::결과 영역-->
							<div id="uploadResult" class="d-none mb-8"></div>
							<!--end::결과 영역-->

							<form id="dailyUploadForm" class="form" enctype="multipart/form-data">
								<div class="mb-6">
									<label class="form-label required">플랫폼</label>
									<select class="form-select form-select-solid" name="platform">
										<option value="baemin" selected>배달의민족</option>
										<option value="coupang">쿠팡이츠</option>
										<option value="other">기타</option>
									</select>
								</div>
								<div class="mb-6">
									<label class="form-label">정산 귀속일 <span class="text-muted fs-7">(파일명 날짜로 자동 입력)</span></label>
									<input type="date" class="form-control form-control-solid" name="settlement_date" id="settlementDateInput" />
									<div class="form-text">파일명에 날짜가 없는 경우에만 수동 입력하세요.</div>
								</div>
								<div class="mb-8">
									<label class="form-label required">파일</label>
									<input type="file" class="form-control form-control-solid" name="file" id="xlsxFileInput" accept=".xlsx" />
									<div class="form-text">xlsx 파일만 가능합니다. (최대 20MB)</div>
								</div>
								<div class="d-flex justify-content-end">
									<button type="submit" class="btn btn-primary" id="uploadBtn">
										<span class="indicator-label">
											<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
											업로드 및 파싱
										</span>
										<span class="indicator-progress">
											처리 중... <span class="spinner-border spinner-border-sm align-middle ms-2"></span>
										</span>
									</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<!--end::업로드 폼-->

				<!--begin::파싱 컬럼 안내-->
				<div class="col-xl-7">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title">파싱 대상 항목 (「일별」시트)</h3>
						</div>
						<div class="card-body pt-0">
							<div class="table-responsive">
								<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
									<thead>
										<tr class="fw-bold text-muted">
											<th class="min-w-50px">컬럼</th>
											<th class="min-w-180px">항목명</th>
											<th>비고</th>
										</tr>
									</thead>
									<tbody>
										<?php
										$cols = [
											['B','라이선스 ID','배달의민족 라이더 외부 ID'],
											['C','이름','라이더명+코드'],
											['E','총 정산예정 금액','(원)'],
											['F','총 정산 오더수','건'],
											['G','픽업 비용 합',''],
											['H','배달 비용 합',''],
											['I','지역 단가 합',''],
											['J','배달거리 할증 건수',''],
											['K','배달거리 할증 금액',''],
											['L','픽업지 할증 건수',''],
											['M','픽업지 할증 금액',''],
											['N','도착지 할증 건수',''],
											['O','도착지 할증 금액',''],
											['P','기상 할증 건수',''],
											['Q','기상 할증 금액',''],
											['…','기타 프로모션1~4',''],
											['끝','라이더별 실지급액','최종 지급금'],
										];
										foreach ($cols as $c): ?>
										<tr>
											<td class="fw-bold text-gray-700"><?= htmlspecialchars($c[0], ENT_QUOTES, 'UTF-8') ?></td>
											<td><?= htmlspecialchars($c[1], ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-gray-600 fs-7"><?= htmlspecialchars($c[2], ENT_QUOTES, 'UTF-8') ?></td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-5 mt-5">
								<i class="ki-duotone ki-information fs-2tx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">「차감내역」시트도 함께 파싱됩니다.</span>
									<span class="text-gray-700 fs-7">오배달 등 차감 항목이 있으면 차감 내역 테이블에 함께 저장됩니다.</span>
								</div>
							</div>
						</div>
					</div>
				</div>
				<!--end::파싱 컬럼 안내-->
			</div>

			<!--begin::업로드 이력-->
			<?php if (!empty($recentUploads)) : ?>
			<div class="card card-flush mt-8">
				<div class="card-header pt-7">
					<h3 class="card-title">최근 업로드 이력</h3>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
							<thead>
								<tr class="fw-bold text-muted">
									<th>귀속일</th>
									<th>팀·지역</th>
									<th>파일명</th>
									<th>건수</th>
									<th>상태</th>
									<th>업로드자</th>
									<th>일시</th>
									<th class="min-w-70px"></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($recentUploads as $up) :
								$detailUrl = admin_url('settlement/upload-detail');
								$detailUrl .= (str_contains($detailUrl, '?') ? '&' : '?') . 'id=' . (int) $up['id'];
								$st = $statusLabels[$up['status']] ?? ['label' => $up['status'], 'badge' => 'badge-light'];
								$meta = json_decode((string) ($up['stored_path'] ?? ''), true);
								$teamLabel = is_array($meta)
									? trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? ''))
									: '';
								$platLabel = $platformLabels[$up['platform']] ?? $up['platform'];
							?>
								<tr>
									<td class="fw-bold"><?= htmlspecialchars((string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= htmlspecialchars($teamLabel !== '' ? $teamLabel : '-', ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-gray-600 fs-7"><?= htmlspecialchars((string) $up['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= number_format((int) $up['total_rows']) ?>명
										<span class="text-muted fs-8">(매칭 <?= number_format((int) $up['ok_rows']) ?>)</span>
									</td>
									<td><span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-muted fs-8 ms-1"><?= htmlspecialchars($platLabel, ENT_QUOTES, 'UTF-8') ?></span>
									</td>
									<td><?= htmlspecialchars($up['uploaded_by_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-gray-600 fs-7"><?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
									<td>
										<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">상세</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<?php endif; ?>
			<!--end::업로드 이력-->
		</div>
		<!--end::업로드 탭-->

		<!--begin::주간 탭-->
		<div class="tab-pane fade" id="kt_tab_weekly" role="tabpanel">
			<div class="card card-flush">
				<div class="card-body pt-8">
					<div class="notice d-flex bg-light-primary rounded border-primary border border-dashed p-8">
						<i class="ki-duotone ki-calendar-2 fs-2tx text-primary me-6"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
						<div class="d-flex flex-column">
							<h4 class="fw-bold text-gray-900">주간 정산서 업로드는 준비 중입니다.</h4>
							<span class="text-gray-700 fs-6">일간 정산서 업로드가 안정화된 후 차감내역·보험료 일괄 반영 기능을 추가할 예정입니다.</span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!--end::주간 탭-->
	</div>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

<script>
(function () {
	'use strict';

	// 파일 선택 시 날짜 자동 추출
	const fileInput = document.getElementById('xlsxFileInput');
	const dateInput = document.getElementById('settlementDateInput');
	if (fileInput && dateInput) {
		fileInput.addEventListener('change', function () {
			const name = this.files[0]?.name || '';
			const m = name.match(/(\d{4})(\d{2})(\d{2})/);
			if (m) {
				dateInput.value = `${m[1]}-${m[2]}-${m[3]}`;
			}
		});
	}

	// 업로드 폼 제출
	const form    = document.getElementById('dailyUploadForm');
	const btn     = document.getElementById('uploadBtn');
	const result  = document.getElementById('uploadResult');

	if (!form) return;

	form.addEventListener('submit', async function (e) {
		e.preventDefault();

		const file = fileInput?.files[0];
		if (!file) {
			showResult('danger', '파일을 선택해 주세요.');
			return;
		}

		btn.setAttribute('data-kt-indicator', 'on');
		btn.disabled = true;
		result.className = 'd-none';

		const fd = new FormData(form);

		try {
			const apiUrl = '<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_upload.php', ENT_QUOTES, 'UTF-8') ?>';
			const resp = await fetch(apiUrl, {
				method: 'POST',
				body: fd,
			});
			const data = await resp.json();

			if (data.ok) {
				let html = `<div class="alert alert-success d-flex flex-column p-5">
					<div class="d-flex align-items-center mb-3">
						<i class="ki-duotone ki-check-circle fs-2hx text-success me-3"><span class="path1"></span><span class="path2"></span></i>
						<span class="fw-bold fs-5">${escHtml(data.message)}</span>
					</div>
					<ul class="mb-0 ps-6 text-gray-700 fs-7">
						<li>귀속일: <strong>${escHtml(data.date)}</strong></li>
						<li>팀·지역: <strong>${escHtml((data.team || '') + ' ' + (data.region || ''))}</strong></li>
						<li>저장된 라이더: <strong>${data.rows}명</strong></li>
						<li>차감내역: <strong>${data.deductions}건</strong></li>`;
				if (data.unmatched && data.unmatched.length > 0) {
					html += `<li class="text-warning">라이더 미매칭(${data.unmatched.length}명): ${data.unmatched.slice(0, 5).map(escHtml).join(', ')}${data.unmatched.length > 5 ? ' …' : ''}</li>`;
				}
				html += `</ul></div>`;
				showResult('', html, true);

				// 페이지 새로고침으로 이력 갱신
				setTimeout(() => location.reload(), 2500);
			} else {
				showResult('danger', '오류: ' + (data.error || '알 수 없는 오류'));
			}
		} catch (err) {
			showResult('danger', '통신 오류: ' + err.message);
		} finally {
			btn.removeAttribute('data-kt-indicator');
			btn.disabled = false;
		}
	});

	function showResult(type, msg, raw = false) {
		result.className = 'mb-8';
		if (raw) {
			result.innerHTML = msg;
		} else {
			result.innerHTML = `<div class="alert alert-${type} d-flex align-items-center p-5">
				<i class="ki-duotone ki-${type === 'danger' ? 'cross-circle' : 'check-circle'} fs-2hx text-${type} me-3"><span class="path1"></span><span class="path2"></span></i>
				<span>${escHtml(msg)}</span></div>`;
		}
	}

	function escHtml(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
})();
</script>


<?php

declare(strict_types=1);

// 멀티테넌시: 업로드 소유 대리점 스코프 / 본사·총판은 대리점 선택
require_once INC_PATH . '/Organization.php';
$isAgencyUploader = admin_org_level() === Org::LEVEL_AGENCY;
$uploadAgencyOptions = $isAgencyUploader ? [] : Organization::agencyOptions();
[$uplScopeSql, $uplScopeParams] = Org::agencyScopeClause('u.agency_id');

// 최근 업로드 이력 (일간)
$recentUploads = [];
try {
    $recentWhere = 'u.kind = ?';
    $recentParams = ['daily'];
    if ($uplScopeSql !== '') {
        $recentWhere .= ' AND ' . $uplScopeSql;
        $recentParams = array_merge($recentParams, $uplScopeParams);
    }
    $recentUploads = db_rows(
        'SELECT u.id, u.settlement_date, u.original_filename, u.platform,
                u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at,
                a.name AS uploaded_by_name, u.stored_path
           FROM settlement_uploads u
           LEFT JOIN admins a ON a.id = u.operator_id
          WHERE ' . $recentWhere . '
          ORDER BY u.created_at DESC
          LIMIT 10',
        $recentParams
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
								<span class="text-gray-500 mt-1 fw-semibold fs-6">쿠팡이츠 일별 정산서(.xlsx) → DB 저장</span>
							</h3>
						</div>
						<div class="card-body pt-5">
							<div class="alert alert-primary d-flex align-items-center p-5 mb-5">
								<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">플랫폼에서 받은 일간 정산서(.xlsx)를 그대로 올려 주세요.</span>
									<span class="text-gray-700 fs-7 mt-1">파일명에 날짜가 포함된 경우(예: 팀도깨비_서울_강서남부_<strong>20260225</strong>.xlsx) 귀속일이 자동 추출됩니다.</span>
								</div>
							</div>

							<!--begin::결과 영역-->
							<div id="uploadResult" class="d-none mb-8"></div>
							<!--end::결과 영역-->

							<form id="dailyUploadForm" class="form" enctype="multipart/form-data" accept-charset="UTF-8">
								<?php if (!$isAgencyUploader): ?>
								<div class="mb-6">
									<label class="form-label required">업로드 대리점</label>
									<select class="form-select form-select-solid" name="agency_id" id="uploadAgencySelect">
										<option value="">대리점 선택</option>
										<?php foreach ($uploadAgencyOptions as $ag): ?>
										<option value="<?= (int) $ag['id'] ?>"><?= htmlspecialchars($ag['name'] . ' (' . $ag['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
										<?php endforeach; ?>
									</select>
									<div class="form-text">이 정산 파일이 귀속될 대리점입니다. 라이더 매칭도 해당 대리점 내에서만 이뤄집니다.</div>
								</div>
								<?php endif; ?>
								<div class="mb-6">
									<label class="form-label required">파일</label>
									<input type="file" class="form-control form-control-solid" name="file" id="xlsxFileInput" accept=".xlsx" />
									<div class="form-text">xlsx 파일만 가능합니다. (최대 20MB) · 선택 시 플랫폼을 자동 감지합니다.</div>
								</div>
								<div id="platformDetectBanner" class="d-none mb-6"></div>
								<div class="mb-6">
									<label class="form-label required">플랫폼</label>
									<select class="form-select form-select-solid" name="platform" id="platformSelect">
										<option value="coupang" selected>쿠팡이츠</option>
										<option value="baemin">배달의민족</option>
										<option value="other">기타</option>
									</select>
									<div class="form-text">파일 분석 결과에 따라 자동 선택됩니다. 잘못 감지된 경우에만 수동으로 변경하세요.</div>
								</div>
								<div id="platformMismatchConfirmWrap" class="d-none mb-6">
									<label class="form-check form-check-custom form-check-solid">
										<input class="form-check-input" type="checkbox" name="confirm_platform_mismatch" id="confirmPlatformMismatch" value="1" />
										<span class="form-check-label text-warning fw-semibold">플랫폼이 맞습니다 (감지 결과와 다를 때 강제 업로드)</span>
									</label>
								</div>
								<div class="mb-6">
									<label class="form-label">정산 귀속일 <span class="text-muted fs-7">(파일명 날짜로 자동 입력)</span></label>
									<input type="date" class="form-control form-control-solid" name="settlement_date" id="settlementDateInput" />
									<div class="form-text">파일명에 날짜가 없는 경우에만 수동 입력하세요.</div>
								</div>
								<div class="mb-6">
									<label class="form-label">파일 열기 암호 <span class="text-muted fs-7">(선택 — 이번 업로드만 사용)</span></label>
									<input type="password" class="form-control form-control-solid" name="excel_password" id="excelPasswordInput" autocomplete="off" placeholder="암호가 걸린 파일이면 직접 입력" />
									<div class="form-text">비우면 <strong>대리점에 저장된 암호 → 전역 기본 → 환경변수</strong> 순으로 자동 시도합니다.</div>
								</div>
								<div class="d-flex justify-content-end">
									<button type="submit" class="btn btn-primary" id="uploadBtn">
										<span class="indicator-label">
											<i class="ki-duotone ki-eye fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
											미리보기 · 매칭 확인
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
											['B','라이선스 ID','쿠팡이츠 라이더 ID'],
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
			<div class="card card-flush mt-8">
				<div class="card-header pt-7 d-flex flex-stack">
					<h3 class="card-title">최근 업로드 이력</h3>
					<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">전체 보기</a>
				</div>
				<div class="card-body pt-0">
					<?php if ($recentUploads === []) : ?>
					<div class="text-center text-muted py-10">아직 업로드 이력이 없습니다.</div>
					<?php else : ?>
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
					<?php endif; ?>
				</div>
			</div>
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

	<!--begin::미리보기 모달-->
	<div class="modal fade" id="kt_settlement_preview_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-900px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold">정산 데이터 미리보기</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-8">
					<div id="previewSummary" class="mb-4"></div>
					<div id="previewDupWarn" class="alert alert-warning d-none fs-7 py-3"></div>
					<div class="table-responsive" style="max-height:52vh;overflow:auto">
						<table class="table table-row-bordered align-middle gs-0 gy-2 fs-7 mb-0">
							<thead class="position-sticky top-0 bg-white">
								<tr class="fw-bold text-muted">
									<th class="min-w-110px">라이선스 ID</th>
									<th class="min-w-90px">이름(원본)</th>
									<th class="text-end min-w-60px">건수</th>
									<th class="text-end min-w-90px">지급액</th>
									<th class="min-w-160px">매칭</th>
								</tr>
							</thead>
							<tbody id="previewTbody"></tbody>
						</table>
					</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="confirmUploadBtn">
						<span class="indicator-label">확정 업로드</span>
						<span class="indicator-progress">저장 중… <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
					</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::미리보기 모달-->

	<!--begin::미매칭 라이더 빠른 등록 모달-->
	<div class="modal fade" id="kt_quick_rider_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold fs-4">라이더 빠른 등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-6 px-lg-8">
					<div id="quickRiderAlert" class="d-none mb-4"></div>
					<input type="hidden" id="qrLicense" />
					<div class="mb-4">
						<label class="form-label required">이름</label>
						<input type="text" class="form-control form-control-solid" id="qrName" maxlength="50" />
					</div>
					<div class="mb-4">
						<label class="form-label">휴대전화</label>
						<input type="text" class="form-control form-control-solid" id="qrPhone" maxlength="20" placeholder="01012345678" />
					</div>
					<div class="row">
						<div class="col-md-6 mb-4">
							<label class="form-label required">로그인 ID</label>
							<input type="text" class="form-control form-control-solid" id="qrLoginId" maxlength="60" autocomplete="off" />
						</div>
						<div class="col-md-6 mb-4">
							<label class="form-label required">비밀번호</label>
							<input type="text" class="form-control form-control-solid" id="qrPassword" maxlength="60" autocomplete="off" />
						</div>
					</div>
					<div class="form-text">최소 정보로 등록하고 license <code id="qrLicenseLabel">-</code> 를 연동합니다. 상세 정보는 라이더 상세에서 보완하세요.</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="qrSubmitBtn">등록 및 매칭</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::미매칭 라이더 빠른 등록 모달-->
<?php require_once INC_PATH . '/app_content_close.php'; ?>

<script>
(function () {
	'use strict';

	const platformLabels = {
		baemin: '배달의민족',
		coupang: '쿠팡이츠',
		other: '기타',
	};

	const fileInput   = document.getElementById('xlsxFileInput');
	const dateInput   = document.getElementById('settlementDateInput');
	const platformSel = document.getElementById('platformSelect');
	const detectBanner = document.getElementById('platformDetectBanner');
	const mismatchWrap = document.getElementById('platformMismatchConfirmWrap');
	const mismatchChk  = document.getElementById('confirmPlatformMismatch');
	const form    = document.getElementById('dailyUploadForm');
	const btn     = document.getElementById('uploadBtn');
	const result  = document.getElementById('uploadResult');

	const previewApiUrl = '<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_upload_preview.php', ENT_QUOTES, 'UTF-8') ?>';
	const uploadApiUrl  = '<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_upload.php', ENT_QUOTES, 'UTF-8') ?>';
	const registerApiUrl = '<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_register_rider.php', ENT_QUOTES, 'UTF-8') ?>';

	let lastDetected = null;
	let lastConfidence = 'none';
	let previewToken = 0;

	function resetPlatformDetect() {
		lastDetected = null;
		lastConfidence = 'none';
		if (detectBanner) {
			detectBanner.className = 'd-none mb-6';
			detectBanner.innerHTML = '';
		}
		updateMismatchUi();
	}

	function updateMismatchUi() {
		if (!platformSel || !mismatchWrap || !mismatchChk) return;

		const selected = platformSel.value;
		const showMismatch = lastDetected !== null
			&& lastDetected !== 'other'
			&& selected !== lastDetected
			&& lastConfidence !== 'none';

		mismatchWrap.classList.toggle('d-none', !showMismatch);
		if (!showMismatch) {
			mismatchChk.checked = false;
		}
	}

	function renderDetectBanner(data) {
		if (!detectBanner) return;

		if (!data.ok) {
			detectBanner.className = 'alert alert-danger d-flex align-items-start p-5 mb-6';
			detectBanner.innerHTML = `<i class="ki-duotone ki-cross-circle fs-2hx text-danger me-4"><span class="path1"></span><span class="path2"></span></i>
				<div><span class="fw-bold">파일 분석 실패</span><div class="text-gray-700 fs-7 mt-1">${escHtml(data.error || '알 수 없는 오류')}</div></div>`;
			return;
		}

		const detected = data.detected_platform;
		const label = data.detected_label || (detected ? platformLabels[detected] : '');
		const conf = data.confidence || 'none';
		const rows = data.parse_row_count || 0;
		const mismatch = !!data.mismatch;

		if (!detected) {
			detectBanner.className = 'alert alert-warning d-flex align-items-start p-5 mb-6';
			detectBanner.innerHTML = `<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
				<div><span class="fw-bold">플랫폼을 자동으로 판별하지 못했습니다</span>
				<div class="text-gray-700 fs-7 mt-1">플랫폼을 직접 선택한 뒤 업로드해 주세요.${rows > 0 ? ` (파싱 가능 ${rows}명)` : ''}</div></div>`;
			return;
		}

		const alertClass = mismatch ? 'alert-warning' : (conf === 'high' ? 'alert-success' : 'alert-primary');
		const iconClass  = mismatch ? 'text-warning' : (conf === 'high' ? 'text-success' : 'text-primary');
		const title = mismatch
			? `감지: ${escHtml(label)} — 선택한 플랫폼과 다릅니다`
			: `이 파일은 ${escHtml(label)} 정산서로 감지되었습니다`;

		let reasonsHtml = '';
		if (Array.isArray(data.reasons) && data.reasons.length > 0) {
			reasonsHtml = `<ul class="mb-0 mt-2 ps-5 fs-7 text-gray-700">${data.reasons.slice(0, 4).map(r => `<li>${escHtml(r)}</li>`).join('')}</ul>`;
		}

		detectBanner.className = `alert ${alertClass} d-flex align-items-start p-5 mb-6`;
		detectBanner.innerHTML = `<i class="ki-duotone ki-shield-tick fs-2hx ${iconClass} me-4"><span class="path1"></span><span class="path2"></span></i>
			<div><span class="fw-bold">${title}</span>
			<div class="text-gray-700 fs-7 mt-1">${rows > 0 ? `파싱 가능 라이더 ${rows}명 · ` : ''}신뢰도 ${escHtml(conf)}</div>
			${reasonsHtml}</div>`;
	}

	async function runPreview() {
		const file = fileInput?.files[0];
		resetPlatformDetect();
		if (!file) return;

		const token = ++previewToken;
		if (detectBanner) {
			detectBanner.className = 'alert alert-light d-flex align-items-center p-5 mb-6';
			detectBanner.innerHTML = '<span class="spinner-border spinner-border-sm me-3"></span><span class="text-gray-700">파일 분석 중…</span>';
		}

		const fd = new FormData();
		fd.append('file', file);
		if (platformSel) {
			fd.append('platform', platformSel.value);
		}

		try {
			const resp = await fetch(previewApiUrl, { method: 'POST', body: fd });
			const data = await resp.json();
			if (token !== previewToken) return;

			if (data.ok && data.settlement_date && dateInput && !dateInput.value) {
				dateInput.value = data.settlement_date;
			}

			if (data.ok && data.detected_platform && platformSel) {
				lastDetected = data.detected_platform;
				lastConfidence = data.confidence || 'none';
				if (lastConfidence !== 'none') {
					platformSel.value = data.detected_platform;
				}
			}

			renderDetectBanner(data);
			updateMismatchUi();
		} catch (err) {
			if (token !== previewToken) return;
			renderDetectBanner({ ok: false, error: err.message });
		}
	}

	if (fileInput && dateInput) {
		fileInput.addEventListener('change', function () {
			const name = this.files[0]?.name || '';
			const m = name.match(/(\d{4})(\d{2})(\d{2})/);
			if (m) {
				dateInput.value = `${m[1]}-${m[2]}-${m[3]}`;
			}
			runPreview();
		});
	}

	if (platformSel) {
		platformSel.addEventListener('change', function () {
			updateMismatchUi();
			const file = fileInput?.files[0];
			if (file) {
				runPreview();
			}
		});
	}

	if (mismatchChk) {
		mismatchChk.addEventListener('change', updateMismatchUi);
	}

	if (!form) return;

	// ── 미리보기 상태 ──────────────────────────────────────────
	let previewState = { agencyId: 0, platform: '', date: '', total: 0, matched: 0, unmatched: 0 };
	let activeRegRow = null;

	const previewModalEl = document.getElementById('kt_settlement_preview_modal');
	const quickModalEl   = document.getElementById('kt_quick_rider_modal');
	const previewTbody   = document.getElementById('previewTbody');
	const previewSummary = document.getElementById('previewSummary');
	const previewDupWarn = document.getElementById('previewDupWarn');
	const confirmBtn     = document.getElementById('confirmUploadBtn');

	function won(n){ return Number(n || 0).toLocaleString('ko-KR'); }

	function handleMismatch(data) {
		lastDetected = data.detected_platform || null;
		lastConfidence = data.confidence || 'high';
		updateMismatchUi();
		if (mismatchWrap) {
			mismatchWrap.classList.remove('d-none');
			mismatchWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
		let msg = data.error || '플랫폼이 일치하지 않습니다.';
		if (data.detected_label) msg += ` (감지: ${data.detected_label})`;
		msg += ' 아래 「플랫폼 확인 후 강제 업로드」를 체크하거나 플랫폼을 변경해 주세요.';
		showResult('danger', msg);
	}

	// 1단계: 제출 → 미리보기(파싱+매칭, 저장 안 함)
	form.addEventListener('submit', async function (e) {
		e.preventDefault();
		const file = fileInput?.files[0];
		if (!file) { showResult('danger', '파일을 선택해 주세요.'); return; }

		btn.setAttribute('data-kt-indicator', 'on');
		btn.disabled = true;
		result.className = 'd-none';

		const fd = new FormData(form);
		fd.append('mode', 'preview');

		try {
			const resp = await fetch(uploadApiUrl, { method: 'POST', body: fd });
			const data = await resp.json();
			if (data.ok && data.preview) {
				openPreview(data);
			} else if (data.code === 'platform_mismatch') {
				handleMismatch(data);
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

	function openPreview(data) {
		previewState = {
			agencyId: data.agency_id || 0,
			platform: data.platform || '',
			date: data.settlement_date || '',
			total: data.summary.total,
			matched: data.summary.matched,
			unmatched: data.summary.unmatched
		};
		previewSummary.innerHTML =
			`<div class="d-flex flex-wrap gap-2 fs-6">
				<span class="badge badge-light-primary fs-7 py-2">귀속일 ${escHtml(previewState.date)}</span>
				<span class="badge badge-light-info fs-7 py-2">${escHtml(platformLabels[previewState.platform] || previewState.platform)}</span>
				<span class="badge badge-light fs-7 py-2">총 ${previewState.total}명</span>
				<span class="badge badge-light-success fs-7 py-2">매칭 <span id="sumMatched">${previewState.matched}</span>명</span>
				<span class="badge badge-light-danger fs-7 py-2">미매칭 <span id="sumUnmatched">${previewState.unmatched}</span>명</span>
				${data.summary.deductions ? `<span class="badge badge-light-warning fs-7 py-2">차감 ${data.summary.deductions}건</span>` : ''}
			</div>`;
		if (data.duplicate_warning) {
			previewDupWarn.classList.remove('d-none');
			previewDupWarn.textContent = '⚠ ' + data.duplicate_warning;
		} else {
			previewDupWarn.classList.add('d-none');
		}
		previewTbody.innerHTML = data.rows.map((r, i) => rowHtml(r, i)).join('');
		updateConfirmBtn();
		bootstrap.Modal.getOrCreateInstance(previewModalEl).show();
	}

	function rowHtml(r, i) {
		const matchCell = r.matched
			? `<span class="badge badge-light-success">${escHtml(r.rider_name)} <span class="text-muted">${escHtml(r.rider_code)}</span></span>`
			: `<button type="button" class="btn btn-sm btn-light-danger py-1 btn-reg" data-i="${i}" data-license="${escHtml(r.license_id)}" data-name="${escHtml(r.name_raw)}">미매칭 · 라이더 등록</button>`;
		return `<tr data-i="${i}">
			<td class="font-monospace">${escHtml(r.license_id || '-')}</td>
			<td>${escHtml(r.name_raw)}</td>
			<td class="text-end">${won(r.order_count)}</td>
			<td class="text-end fw-bold">${won(r.payout_amount)}</td>
			<td class="match-cell">${matchCell}</td>
		</tr>`;
	}

	function updateConfirmBtn() {
		const label = confirmBtn.querySelector('.indicator-label');
		if (label) label.textContent = `확정 업로드 (${previewState.total}명${previewState.unmatched > 0 ? ` · 미매칭 ${previewState.unmatched}명 포함` : ''})`;
	}

	// 미매칭 행 → 빠른 등록 모달
	previewTbody.addEventListener('click', function (ev) {
		const b = ev.target.closest('.btn-reg');
		if (!b) return;
		activeRegRow = Number(b.getAttribute('data-i'));
		const license = b.getAttribute('data-license') || '';
		const name = b.getAttribute('data-name') || '';
		document.getElementById('qrLicense').value = license;
		document.getElementById('qrLicenseLabel').textContent = license || '(없음)';
		document.getElementById('qrName').value = name;
		document.getElementById('qrPhone').value = '';
		document.getElementById('qrLoginId').value = suggestLogin(license);
		document.getElementById('qrPassword').value = randomPw();
		const al = document.getElementById('quickRiderAlert'); al.className = 'd-none mb-4'; al.textContent = '';
		bootstrap.Modal.getOrCreateInstance(quickModalEl).show();
	});

	function suggestLogin(license) {
		let base = (license || '').replace(/[^a-zA-Z0-9_.@-]/g, '');
		if (base.length < 3) base = 'r' + Math.random().toString(36).slice(2, 8);
		return base.slice(0, 30);
	}
	function randomPw() { return Math.random().toString(36).slice(2, 8); }

	document.getElementById('qrSubmitBtn').addEventListener('click', async function () {
		const al = document.getElementById('quickRiderAlert');
		const payload = {
			agency_id: previewState.agencyId,
			platform: previewState.platform,
			license_id: document.getElementById('qrLicense').value,
			name: document.getElementById('qrName').value.trim(),
			phone: document.getElementById('qrPhone').value.trim(),
			login_id: document.getElementById('qrLoginId').value.trim(),
			password: document.getElementById('qrPassword').value
		};
		if (!payload.name) { al.className = 'alert alert-danger mb-4'; al.textContent = '이름을 입력하세요.'; return; }
		if (payload.login_id.length < 3) { al.className = 'alert alert-danger mb-4'; al.textContent = '로그인 ID는 3자 이상이어야 합니다.'; return; }
		if (payload.password.length < 4) { al.className = 'alert alert-danger mb-4'; al.textContent = '비밀번호는 4자 이상이어야 합니다.'; return; }
		this.disabled = true;
		try {
			const resp = await fetch(registerApiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
			const data = await resp.json();
			if (!data.ok) throw new Error(data.message || '등록 실패');
			markRowMatched(activeRegRow, data.rider);
			bootstrap.Modal.getInstance(quickModalEl).hide();
		} catch (e) {
			al.className = 'alert alert-danger mb-4'; al.textContent = e.message || '등록 실패';
		} finally {
			this.disabled = false;
		}
	});

	function markRowMatched(i, rider) {
		const tr = previewTbody.querySelector(`tr[data-i="${i}"]`);
		if (tr) {
			const cell = tr.querySelector('.match-cell');
			if (cell) cell.innerHTML = `<span class="badge badge-light-success">${escHtml(rider.name)} <span class="text-muted">${escHtml(rider.rider_code)}</span> <span class="badge badge-success ms-1">신규</span></span>`;
		}
		previewState.matched++;
		previewState.unmatched = Math.max(0, previewState.unmatched - 1);
		const m = document.getElementById('sumMatched'); if (m) m.textContent = previewState.matched;
		const u = document.getElementById('sumUnmatched'); if (u) u.textContent = previewState.unmatched;
		updateConfirmBtn();
	}

	// 2단계: 확정 업로드 (파일 재전송 → 저장. 새로 등록된 라이더가 매칭됨)
	confirmBtn.addEventListener('click', async function () {
		const file = fileInput?.files[0];
		if (!file) return;
		confirmBtn.setAttribute('data-kt-indicator', 'on');
		confirmBtn.disabled = true;
		const fd = new FormData(form); // mode 없음 → 커밋
		try {
			const resp = await fetch(uploadApiUrl, { method: 'POST', body: fd });
			const data = await resp.json();
			if (data.ok) {
				bootstrap.Modal.getInstance(previewModalEl).hide();
				let html = `<div class="alert alert-success d-flex flex-column p-5">
					<div class="d-flex align-items-center mb-3"><i class="ki-duotone ki-check-circle fs-2hx text-success me-3"><span class="path1"></span><span class="path2"></span></i><span class="fw-bold fs-5">${escHtml(data.message)}</span></div>
					<ul class="mb-0 ps-6 text-gray-700 fs-7">
						<li>귀속일: <strong>${escHtml(data.date)}</strong></li>
						<li>저장된 라이더: <strong>${data.rows}명</strong> (매칭 ${data.matched}명)</li>
						<li>차감내역: <strong>${data.deductions}건</strong></li>`;
				if (data.unmatched && data.unmatched.length > 0) html += `<li class="text-warning">여전히 미매칭(${data.unmatched.length}명): ${data.unmatched.slice(0, 5).map(escHtml).join(', ')}${data.unmatched.length > 5 ? ' …' : ''}</li>`;
				html += `</ul></div>`;
				showResult('', html, true);
				setTimeout(() => location.reload(), 2200);
			} else if (data.code === 'platform_mismatch') {
				bootstrap.Modal.getInstance(previewModalEl).hide();
				handleMismatch(data);
			} else {
				previewDupWarn.classList.remove('d-none');
				previewDupWarn.textContent = '⚠ ' + (data.error || '저장 실패');
			}
		} catch (err) {
			previewDupWarn.classList.remove('d-none');
			previewDupWarn.textContent = '⚠ 통신 오류: ' + err.message;
		} finally {
			confirmBtn.removeAttribute('data-kt-indicator');
			confirmBtn.disabled = false;
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


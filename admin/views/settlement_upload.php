<?php

declare(strict_types=1);

// 멀티테넌시: 업로드 소유 대리점 스코프 / 본사·총판은 대리점 선택
require_once INC_PATH . '/Organization.php';
$isAgencyUploader = admin_org_level() === Org::LEVEL_AGENCY;
$uploadAgencyOptions = $isAgencyUploader ? [] : Organization::agencyOptions();
[$uplScopeSql, $uplScopeParams] = Org::agencyScopeClause('u.agency_id');
// 라이더 빠른 등록 모달의 은행 선택용(일정산 라이더는 대리점이 출금 대행하므로 계좌가 필요).
$qrBanks = db_table_exists('system_codes') ? db_rows("SELECT code, label FROM system_codes WHERE category = 'bank' AND is_active = 1 ORDER BY label ASC") : [];

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
			<a class="nav-link text-active-primary pb-4" data-bs-toggle="tab" href="#kt_tab_weekly">주간 정산서</a>
		</li>
	</ul>

	<div class="tab-content" id="settlementUploadTabs">
		<!--begin::업로드 탭-->
		<div class="tab-pane fade show active" id="kt_tab_daily" role="tabpanel">
			<div class="row g-5 g-xl-10">
				<!--begin::업로드 폼-->
				<div class="col-xl-12">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title align-items-start flex-column">
								<span class="card-label fw-bold text-gray-900">일간 정산서 업로드</span>
								<span class="text-gray-500 mt-1 fw-semibold fs-6">배달의민족·쿠팡이츠 일별 정산서(.xlsx) → DB 저장</span>
							</h3>
						</div>
						<div class="card-body pt-5">
							<div class="alert alert-primary d-flex align-items-center p-5 mb-5">
								<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">플랫폼에서 받은 일간 정산서(.xlsx)를 그대로 올려 주세요.</span>
									<span class="text-gray-700 fs-7 mt-1">파일명에 날짜가 포함된 경우(예: 팀도깨비_서울_강서남부_<strong>20260225</strong>.xlsx) 귀속일이 자동 추출됩니다.</span>
									<span class="text-gray-700 fs-7 mt-1">「차감내역」시트도 함께 파싱됩니다 — 오배달 등 차감 항목이 있으면 자동으로 함께 저장됩니다.</span>
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
								<?php // 파일을 고르면 자동으로 채워진다. 표시 전용이라 서버로는 보내지 않는다
								      // (서버가 파일 내용으로 다시 판별한다 — 화면 값을 믿고 처리하면 위험하다). ?>
								<div class="mb-6">
									<label class="form-label">정산서 종류 <span class="text-muted fs-7">(파일 선택 시 자동 판별)</span></label>
									<select class="form-select form-select-solid" id="kindSelect" disabled>
										<option value="">파일을 선택하면 표시됩니다</option>
										<option value="daily">일간 정산서</option>
										<option value="weekly">주간 정산서</option>
									</select>
									<div class="form-text" id="kindHint">일간·주간을 파일 내용으로 자동 구분합니다. 직접 고르지 않아도 됩니다.</div>
								</div>
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
								<div class="row g-4 mb-6">
									<div class="col-md-6">
										<label class="form-label">팀명 <span class="text-muted fs-7">(파일명에서 자동)</span></label>
										<input type="text" class="form-control form-control-solid" name="team_name" id="teamNameInput" maxlength="60" placeholder="예: 팀도깨비" />
									</div>
									<div class="col-md-6">
										<label class="form-label">지역명 <span class="text-muted fs-7">(파일명에서 자동)</span></label>
										<input type="text" class="form-control form-control-solid" name="region_name" id="regionNameInput" maxlength="60" placeholder="예: 서울_강서남부" />
									</div>
									<div class="col-12">
										<div class="form-text">
											같은 대리점이라도 <strong>팀지역이 다르면 같은 날짜에 여러 건</strong>을 올릴 수 있습니다.
											파일명이 <code>팀_지역_날짜.xlsx</code> 형식이면 자동으로 채워지며, 다르면 직접 입력하세요.
										</div>
									</div>
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
					<?php
					// 8개 컬럼짜리 표는 좁은 화면에서 가로 스크롤이 생겨 알아보기 어렵다.
					// md 이상은 기존 표 그대로, 그 아래는 핵심 정보(귀속일·상태·건수)만 카드로 쌓아 보여준다.
					$rows = [];
					foreach ($recentUploads as $up) {
						$detailUrl = admin_url('settlement/upload-detail');
						$detailUrl .= (str_contains($detailUrl, '?') ? '&' : '?') . 'id=' . (int) $up['id'];
						$meta = json_decode((string) ($up['stored_path'] ?? ''), true);
						$rows[] = [
							'up'        => $up,
							'detailUrl' => $detailUrl,
							'st'        => $statusLabels[$up['status']] ?? ['label' => $up['status'], 'badge' => 'badge-light'],
							'team'      => is_array($meta) ? trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? '')) : '',
							'plat'      => $platformLabels[$up['platform']] ?? $up['platform'],
						];
					}
					?>
					<!--begin::표(md 이상)-->
					<div class="table-responsive d-none d-md-block">
						<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
							<thead>
								<tr class="fw-bold text-muted">
									<?php // 파일명만 늘어나게 두고 나머지는 고정 폭 + 줄바꿈 금지 — 한 줄씩 차지하면 표가 세로로 늘어난다. ?>
									<th class="text-nowrap w-100px">귀속일</th>
									<th class="w-125px">팀·지역</th>
									<th>파일명</th>
									<th class="text-nowrap w-100px">건수</th>
									<th class="text-nowrap w-125px">상태</th>
									<th class="text-nowrap w-100px">업로드자</th>
									<th class="text-nowrap w-125px">일시</th>
									<th class="min-w-70px"></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ($rows as $r) : $up = $r['up']; $st = $r['st']; ?>
								<tr>
									<td class="fw-bold text-nowrap"><?= htmlspecialchars((string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
									<?php // 팀·지역은 파일명에서 뽑히다 보니 아주 길어지는 값이 섞인다 — 잘라 보여주고 전체는 툴팁으로. ?>
									<td class="fs-8 text-gray-700" title="<?= htmlspecialchars($r['team'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r['team'] !== '' ? mb_strimwidth($r['team'], 0, 22, '…') : '-', ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-gray-600 fs-8 text-break"><?= htmlspecialchars((string) $up['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-nowrap"><?= number_format((int) $up['total_rows']) ?>명
										<span class="text-muted fs-8">(<?= number_format((int) $up['ok_rows']) ?>)</span>
									</td>
									<td class="text-nowrap"><span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-muted fs-8 ms-1"><?= htmlspecialchars($r['plat'], ENT_QUOTES, 'UTF-8') ?></span>
									</td>
									<td class="text-nowrap"><?= htmlspecialchars($up['uploaded_by_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-gray-600 fs-7 text-nowrap"><?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
									<td>
										<a href="<?= htmlspecialchars($r['detailUrl'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">상세</a>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<!--end::표(md 이상)-->

					<!--begin::카드 목록(모바일)-->
					<div class="d-md-none">
						<?php foreach ($rows as $r) : $up = $r['up']; $st = $r['st']; ?>
						<a href="<?= htmlspecialchars($r['detailUrl'], ENT_QUOTES, 'UTF-8') ?>" class="d-block text-gray-900 border border-gray-300 rounded p-4 mb-3">
							<div class="d-flex justify-content-between align-items-center mb-1">
								<span class="fw-bold fs-6"><?= htmlspecialchars((string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="text-gray-700 fs-7 mb-1"><?= htmlspecialchars($r['team'] !== '' ? $r['team'] : '-', ENT_QUOTES, 'UTF-8') ?></div>
							<div class="text-muted fs-8">
								<?= number_format((int) $up['total_rows']) ?>명(매칭 <?= number_format((int) $up['ok_rows']) ?>)
								· <?= htmlspecialchars($r['plat'], ENT_QUOTES, 'UTF-8') ?>
								· <?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
							</div>
						</a>
						<?php endforeach; ?>
					</div>
					<!--end::카드 목록(모바일)-->
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
							<h4 class="fw-bold text-gray-900">주간 정산서도 「일간 정산서」 탭에서 그대로 올리시면 됩니다.</h4>
							<span class="text-gray-700 fs-6">
								파일 내용을 보고 <strong>일간·주간을 자동으로 구분</strong>하므로 따로 고르실 필요가 없습니다.
								열기 암호가 일간과 다르면 「시스템 관리 → 정산 엑셀 암호」에 <strong>주간용 암호</strong>를 따로 등록해 두세요.
							</span>
							<span class="text-gray-700 fs-7 mt-3">
								주간에서 반영하는 항목은 <strong>프로모션과 시간제보험</strong>뿐입니다(배민 기준).
								고용·산재·원천세는 일간 반영 때 우리 기준으로 계산합니다.
								<span class="d-block mt-1">쿠팡 주정산서는 시간제보험을 <strong>일간에서 이미 공제</strong>하므로 반영하지 않고 <strong>대조용</strong>으로만 저장합니다.</span>
							</span>
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
					<!--begin::표(md 이상)-->
					<div class="table-responsive d-none d-md-block" style="max-height:52vh;overflow:auto">
						<table class="table table-row-bordered align-middle gs-0 gy-2 fs-7 mb-0">
							<thead class="position-sticky top-0 bg-white">
								<tr class="fw-bold text-muted">
									<th class="min-w-110px">라이선스 ID</th>
									<th class="min-w-90px">이름(원본)</th>
									<th class="text-end min-w-60px">건수</th>
									<th class="text-end min-w-90px">정산금액</th>
									<th class="min-w-160px">매칭</th>
								</tr>
							</thead>
							<tbody id="previewTbody"></tbody>
						</table>
					</div>
					<!--end::표(md 이상)-->
					<!--begin::카드 목록(모바일) — 5컬럼 표는 좁은 화면에서 가로 스크롤이 심해 항목당 카드로 대신 보여준다-->
					<div id="previewCards" class="d-md-none" style="max-height:52vh;overflow:auto"></div>
					<!--end::카드 목록(모바일)-->
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

	<!--begin::주간 정산서 미리보기 모달-->
	<?php // 주간은 라이더 매칭 표가 없고 반영 대상도 달라서 일간 모달을 재사용하지 않는다. ?>
	<div class="modal fade" id="kt_weekly_preview_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-600px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold fs-4">주간 정산서 확인</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-8 fs-7" id="wkPreviewBody"></div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="wkConfirmBtn">
						<span class="indicator-label">저장</span>
						<span class="indicator-progress">저장 중… <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
					</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::주간 정산서 미리보기 모달-->

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

					<!-- 모드 토글: 신규 등록 / 기존 라이더 연결 -->
					<ul class="nav nav-tabs nav-line-tabs fs-7 mb-5" id="qrModeTabs">
						<li class="nav-item"><a class="nav-link active" data-mode="create" href="#">신규 라이더 등록</a></li>
						<li class="nav-item"><a class="nav-link" data-mode="link" href="#">기존 라이더에 연결</a></li>
					</ul>

					<!-- 신규 등록 -->
					<div id="qrCreatePane">
						<div class="mb-4">
							<label class="form-label required">이름</label>
							<input type="text" class="form-control form-control-solid" id="qrName" maxlength="50" />
						</div>
						<div class="mb-4">
							<label class="form-label required">휴대전화</label>
							<input type="text" class="form-control form-control-solid" id="qrPhone" maxlength="20" placeholder="01012345678" />
							<div class="form-text fs-9">로그인 ID로 그대로 쓰입니다(겹치면 뒤에 글자가 자동으로 붙습니다).</div>
						</div>
						<div class="mb-4">
							<label class="form-label">초기 비밀번호</label>
							<input type="text" class="form-control form-control-solid" id="qrPassword" value="0000" readonly />
							<div class="form-text fs-9">최초 로그인 시 라이더가 직접 변경합니다.</div>
						</div>
						<div class="row mb-2">
							<div class="col-6">
								<label class="form-check form-check-custom form-check-solid">
									<input class="form-check-input" type="checkbox" id="qrDaily" />
									<span class="form-check-label">일정산 라이더</span>
								</label>
							</div>
							<div class="col-6">
								<label class="form-check form-check-custom form-check-solid">
									<input class="form-check-input" type="checkbox" id="qrWithholding" />
									<span class="form-check-label">원천세 대상</span>
								</label>
							</div>
						</div>
						<?php // 일정산 라이더는 대리점이 출금을 대행하므로 계좌가 있어야 한다. ?>
						<div id="qrBankWrap" class="row d-none mt-2">
							<div class="col-md-6 mb-4">
								<label class="form-label required">은행</label>
								<select class="form-select form-select-solid" id="qrBank">
									<option value="">선택…</option>
									<?php foreach ($qrBanks as $b) : ?>
									<option value="<?= htmlspecialchars((string) $b['code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $b['label'], ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6 mb-4">
								<label class="form-label required">계좌번호</label>
								<div class="d-flex gap-2">
									<input type="text" class="form-control form-control-solid font-monospace" id="qrAccount" maxlength="40" placeholder="숫자·하이픈" />
									<button type="button" class="btn btn-light-primary text-nowrap px-3" id="qrVerify">확인</button>
								</div>
								<?php // 일정산 라이더는 대리점이 매일 출금 대행한다 — 계좌가 틀리면 매일 잘못 나간다. ?>
								<div class="fs-8 mt-2" id="qrVerifyMsg"></div>
							</div>
						</div>
						<div class="form-text">최소 정보로 등록하고 정산서 ID <code id="qrLicenseLabel">-</code> 를 연동합니다.</div>
					</div>

					<!-- 기존 라이더 연결 -->
					<div id="qrLinkPane" class="d-none">
						<div class="alert bg-light-info fs-8 p-3 mb-4">쿠팡만/배민만 등록돼 있던 라이더에 <strong id="qrLinkPlatformLabel">이 플랫폼</strong> ID(<code id="qrLinkLicenseLabel">-</code>)를 연결합니다.</div>
						<div class="input-group mb-3">
							<input type="text" class="form-control form-control-solid" id="qrSearchInput" placeholder="이름·코드로 검색" />
							<button type="button" class="btn btn-light-primary" id="qrSearchBtn">검색</button>
						</div>
						<div id="qrSearchResults" class="d-flex flex-column gap-2" style="max-height:240px;overflow-y:auto"></div>
					</div>
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
	// 업로드 확정 후 곧바로 이동할 상세 화면(?id= 는 아래에서 붙인다)
	const detailBaseUrl = <?php
		$upDetailUrl = admin_url('settlement/upload-detail');
		$upDetailUrl .= (str_contains($upDetailUrl, '?') ? '&' : '?') . 'id=';
		echo json_encode($upDetailUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
	?>;

	let lastDetected = null;
	let lastConfidence = 'none';
	let previewToken = 0;

	const kindSel  = document.getElementById('kindSelect');
	const kindHint = document.getElementById('kindHint');

	/** 파일 분석 결과의 일간/주간을 셀렉트박스에 반영(표시 전용 — 서버가 다시 판별한다). */
	function renderKind(kind, label, confidence) {
		if (!kindSel) return;
		if (!kind) {
			kindSel.value = '';
			kindSel.disabled = true;
			kindHint.className = 'form-text';
			kindHint.textContent = '일간·주간을 파일 내용으로 자동 구분합니다. 직접 고르지 않아도 됩니다.';
			return;
		}
		kindSel.value = kind;
		kindSel.disabled = true;   // 자동 판별 결과라 사람이 바꾸지 못하게 둔다
		if (kind === 'weekly') {
			kindHint.className = 'form-text text-primary fw-semibold';
			kindHint.textContent = '주간 정산서로 감지되었습니다 — 프로모션·시간제보험만 반영합니다.'
				+ (confidence === 'low' ? ' (판별 근거가 약합니다. 파일을 확인해 주세요)' : '');
		} else {
			kindHint.className = 'form-text';
			kindHint.textContent = '일간 정산서로 감지되었습니다 — 라이더 매칭 후 정산 반영까지 진행합니다.';
		}
	}

	function resetPlatformDetect() {
		lastDetected = null;
		lastConfidence = 'none';
		if (detectBanner) {
			detectBanner.className = 'd-none mb-6';
			detectBanner.innerHTML = '';
		}
		renderKind(null);
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

		renderKind(data.ok ? data.detected_kind : null, data.detected_kind_label, data.kind_confidence);

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
			// 팀/지역 자동 채움 — 서버(settlement_upload.php)와 동일 규칙: 팀_지역..._날짜
			const teamEl = document.getElementById('teamNameInput');
			const regionEl = document.getElementById('regionNameInput');
			if (teamEl && regionEl) {
				const base = name.replace(/\.[^.]+$/, '');
				const parts = base.split('_');
				if (parts.length >= 3) {
					teamEl.value = parts[0];
					regionEl.value = parts.slice(1, -1).join('_');
				} else {
					teamEl.value = '';
					regionEl.value = '';
				}
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
	let weeklyPending = null;   // 주간 미리보기 중인 응답(확정 버튼이 사용)
	let activeRegRow = null;

	const previewModalEl = document.getElementById('kt_settlement_preview_modal');
	const quickModalEl   = document.getElementById('kt_quick_rider_modal');
	const previewTbody   = document.getElementById('previewTbody');
	const previewCards   = document.getElementById('previewCards');
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
			if (data.ok && data.preview && data.kind === 'weekly') {
				// 주간 정산서는 응답 형식이 일간과 달라(라이더 매칭 표가 없다) 전용 미리보기를 띄운다.
				openWeeklyPreview(data);
			} else if (data.ok && data.preview) {
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

	/**
	 * 주간 정산서 미리보기 — 일간과 응답 형식이 다르다(라이더 매칭 표 없음, 주 단위 1행).
	 * 반영 대상(프로모션·시간제보험)이 플랫폼마다 달라 그것도 같이 보여준다.
	 */
	function openWeeklyPreview(data) {
		var s = data.summary || {};
		var ref = s.reflectable || {};
		weeklyPending = data;   // 확정 버튼이 쓸 정보

		function line(label, value, on) {
			return '<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">'
				+ '<span class="text-gray-600">' + escHtml(label) + '</span>'
				+ '<span class="' + (on ? 'fw-bold text-primary' : 'text-muted') + '">' + escHtml(value)
				+ (on ? '' : ' <span class="badge badge-light fs-9 ms-1">대조용</span>') + '</span></div>';
		}

		var h = '';
		h += '<div class="d-flex flex-wrap gap-2 mb-4">'
		   + '<span class="badge badge-light-info fs-7 py-2">주간 정산서</span>'
		   + '<span class="badge badge-light-primary fs-7 py-2">' + escHtml(data.week_start) + ' ~ ' + escHtml(data.week_end) + '</span>'
		   + '<span class="badge badge-light fs-7 py-2">라이더 ' + won(s.riders) + '명</span>'
		   + '<span class="badge badge-light-success fs-7 py-2">매칭 ' + won(s.matched) + '명</span>'
		   + '</div>';
		h += line('프로모션', won(s.extra_pay) + '원', !!ref.promo);
		h += line('시간제보험', won(s.hourly_ins) + '원', !!ref.hourly_ins);

		if (!ref.promo && !ref.hourly_ins) {
			h += '<div class="alert bg-light-warning fs-8 p-4 mt-4 mb-0">'
			   + '이 정산서에는 <strong>반영할 항목이 없습니다.</strong> 쿠팡은 시간제보험을 '
			   + '<strong>일간 정산서에서 이미 공제</strong>하고 있어 여기서 또 반영하면 이중 공제가 됩니다. '
			   + '프로모션도 반영 대상이 아닙니다. 저장은 되지만 <strong>대조·확인용</strong>입니다.</div>';
		} else {
			h += '<div class="alert bg-light-primary fs-8 p-4 mt-4 mb-0">'
			   + '고용·산재·원천세는 여기서 반영하지 않습니다 — 일간 정산서 반영 때 우리 기준으로 계산합니다.</div>';
		}

		document.getElementById('wkPreviewBody').innerHTML = h;
		bootstrap.Modal.getOrCreateInstance(document.getElementById('kt_weekly_preview_modal')).show();
	}

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
		previewCards.innerHTML = data.rows.map((r, i) => cardHtml(r, i)).join('');
		updateConfirmBtn();
		bootstrap.Modal.getOrCreateInstance(previewModalEl).show();
	}

	function matchCellHtml(r, i) {
		return r.matched
			? `<span class="badge badge-light-success">${escHtml(r.rider_name)}</span>`
			: `<button type="button" class="btn btn-sm btn-light-danger py-1 btn-reg" data-i="${i}" data-license="${escHtml(r.match_key || r.license_id)}" data-name="${escHtml(r.name_raw)}">미매칭 · 연결/등록</button>`;
	}

	function rowHtml(r, i) {
		return `<tr data-i="${i}">
			<td class="font-monospace">${escHtml((r.match_key || r.license_id) || '-')}</td>
			<td>${escHtml(r.name_raw)}</td>
			<td class="text-end">${won(r.order_count)}</td>
			<td class="text-end fw-bold">${won(r.gross_amount)}</td>
			<td class="match-cell">${matchCellHtml(r, i)}</td>
		</tr>`;
	}

	function cardHtml(r, i) {
		return `<div class="border border-gray-300 rounded p-3 mb-2" data-i="${i}">
			<div class="d-flex justify-content-between align-items-start mb-1">
				<div>
					<div class="fw-bold">${escHtml(r.name_raw)}</div>
					<div class="text-muted fs-8 font-monospace">${escHtml((r.match_key || r.license_id) || '-')}</div>
				</div>
				<div class="text-end">
					<div class="fw-bold">${won(r.gross_amount)}원</div>
					<div class="text-muted fs-8">${won(r.order_count)}건</div>
				</div>
			</div>
			<div class="match-cell mt-2">${matchCellHtml(r, i)}</div>
		</div>`;
	}

	function updateConfirmBtn() {
		const label = confirmBtn.querySelector('.indicator-label');
		if (label) label.textContent = `확정 업로드 (${previewState.total}명${previewState.unmatched > 0 ? ` · 미매칭 ${previewState.unmatched}명 포함` : ''})`;
	}

	// 미매칭 행 → 빠른 등록 모달 (표·카드 둘 다에서 뜬다 — 모달 전체에 한 번만 건다)
	previewModalEl.addEventListener('click', function (ev) {
		const b = ev.target.closest('.btn-reg');
		if (!b) return;
		activeRegRow = Number(b.getAttribute('data-i'));
		const license = b.getAttribute('data-license') || '';
		const name = b.getAttribute('data-name') || '';
		document.getElementById('qrLicense').value = license;
		document.getElementById('qrLicenseLabel').textContent = license || '(없음)';
		document.getElementById('qrName').value = name;
		document.getElementById('qrPhone').value = '';
		document.getElementById('qrPassword').value = '0000';
		document.getElementById('qrDaily').checked = false;
		document.getElementById('qrWithholding').checked = false;
		document.getElementById('qrBank').value = '';
		document.getElementById('qrAccount').value = '';
		var qrVMsg = document.getElementById('qrVerifyMsg');
		if (qrVMsg) { qrVMsg.innerHTML = ''; qrVMsg.dataset.state = ''; }
		document.getElementById('qrBankWrap').classList.add('d-none');
		const al = document.getElementById('quickRiderAlert'); al.className = 'd-none mb-4'; al.textContent = '';
		const pfLabels = { coupang: '쿠팡이츠', baemin: '배달의민족', other: '기타' };
		document.getElementById('qrLinkPlatformLabel').textContent = pfLabels[previewState.platform] || previewState.platform;
		document.getElementById('qrLinkLicenseLabel').textContent = license || '(없음)';
		document.getElementById('qrSearchInput').value = name || '';
		document.getElementById('qrSearchResults').innerHTML = '';
		setQrMode('create');
		bootstrap.Modal.getOrCreateInstance(quickModalEl).show();
	});

	// 모드 토글 (신규 등록 / 기존 연결)
	let qrMode = 'create';
	function setQrMode(mode) {
		qrMode = mode;
		document.getElementById('qrCreatePane').classList.toggle('d-none', mode !== 'create');
		document.getElementById('qrLinkPane').classList.toggle('d-none', mode !== 'link');
		document.getElementById('qrSubmitBtn').classList.toggle('d-none', mode !== 'create');
		document.querySelectorAll('#qrModeTabs .nav-link').forEach(function (a) {
			a.classList.toggle('active', a.getAttribute('data-mode') === mode);
		});
	}
	document.querySelectorAll('#qrModeTabs .nav-link').forEach(function (a) {
		a.addEventListener('click', function (ev) { ev.preventDefault(); setQrMode(a.getAttribute('data-mode')); });
	});

	// 일정산 라이더는 대리점이 출금을 대행하므로 계좌가 필요하다 — 체크할 때만 계좌 입력을 보여준다.
	document.getElementById('qrDaily').addEventListener('change', function () {
		document.getElementById('qrBankWrap').classList.toggle('d-none', !this.checked);
	});

	// 기존 라이더 검색 → 연결
	async function qrSearch() {
		const q = document.getElementById('qrSearchInput').value.trim();
		const box = document.getElementById('qrSearchResults');
		box.innerHTML = '<div class="text-muted fs-8">검색 중…</div>';
		try {
			const resp = await fetch(registerApiUrl + '?q=' + encodeURIComponent(q) + '&platform=' + encodeURIComponent(previewState.platform), { credentials: 'same-origin' });
			const data = await resp.json();
			if (!data.ok) throw new Error(data.message || '검색 실패');
			if (!data.riders.length) { box.innerHTML = '<div class="text-muted fs-8">결과가 없습니다.</div>'; return; }
			box.innerHTML = data.riders.map(function (r) {
				const has = r.platform_ext ? `<span class="badge badge-light-warning fs-8 ms-1">기존:${escHtml(r.platform_ext)}</span>` : '';
				return `<div class="d-flex align-items-center justify-content-between border border-gray-300 rounded p-2">
					<div><span class="fw-bold">${escHtml(r.name)}</span> <span class="text-muted fs-8">${escHtml(r.phone_masked || '')}</span>${has}</div>
					<button type="button" class="btn btn-sm btn-light-primary qr-link-btn" data-id="${r.id}">연결</button>
				</div>`;
			}).join('');
		} catch (e) { box.innerHTML = `<div class="text-danger fs-8">${escHtml(e.message)}</div>`; }
	}
	document.getElementById('qrSearchBtn').addEventListener('click', qrSearch);
	document.getElementById('qrSearchInput').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); qrSearch(); } });
	document.getElementById('qrSearchResults').addEventListener('click', async function (ev) {
		const b = ev.target.closest('.qr-link-btn'); if (!b) return;
		const al = document.getElementById('quickRiderAlert');
		b.disabled = true;
		try {
			const resp = await fetch(registerApiUrl, {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
				body: JSON.stringify({ action: 'link', rider_id: Number(b.getAttribute('data-id')), agency_id: previewState.agencyId, platform: previewState.platform, license_id: document.getElementById('qrLicense').value })
			});
			const data = await resp.json();
			if (!data.ok) throw new Error(data.message || '연결 실패');
			markRowMatched(activeRegRow, data.rider, '연결');
			bootstrap.Modal.getInstance(quickModalEl).hide();
		} catch (e) { al.className = 'alert alert-danger mb-4'; al.textContent = e.message || '연결 실패'; b.disabled = false; }
	});

	function randomPw() { return Math.random().toString(36).slice(2, 8); }

	document.getElementById('qrSubmitBtn').addEventListener('click', async function () {
		const al = document.getElementById('quickRiderAlert');
		const isDaily = document.getElementById('qrDaily').checked;
		const payload = {
			agency_id: previewState.agencyId,
			platform: previewState.platform,
			license_id: document.getElementById('qrLicense').value,
			name: document.getElementById('qrName').value.trim(),
			phone: document.getElementById('qrPhone').value.trim(),
			password: document.getElementById('qrPassword').value,
			is_daily_settlement: isDaily,
			withholding_tax_enabled: document.getElementById('qrWithholding').checked,
			bank_code: isDaily ? document.getElementById('qrBank').value : '',
			bank_account: isDaily ? document.getElementById('qrAccount').value.trim() : '',
		};
		if (!payload.name) { al.className = 'alert alert-danger mb-4'; al.textContent = '이름을 입력하세요.'; return; }
		if (!/^01[016789]\d{7,8}$/.test(payload.phone.replace(/\D/g, ''))) { al.className = 'alert alert-danger mb-4'; al.textContent = '휴대전화 번호 형식이 올바르지 않습니다(예: 01012345678).'; return; }
		if (isDaily && (!payload.bank_code || !payload.bank_account)) { al.className = 'alert alert-danger mb-4'; al.textContent = '일정산 라이더는 출금 대행을 위해 은행·계좌번호가 필요합니다.'; return; }

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

	function markRowMatched(i, rider, badgeLabel) {
		// 같은 i가 표(행)·카드(모바일) 양쪽에 다 있을 수 있으니 둘 다 갱신한다.
		document.querySelectorAll(`[data-i="${i}"]`).forEach(function (el) {
			const cell = el.querySelector('.match-cell');
			if (cell) cell.innerHTML = `<span class="badge badge-light-success">${escHtml(rider.name)} <span class="badge badge-success ms-1">${escHtml(badgeLabel || '신규')}</span></span>`;
		});
		previewState.matched++;
		previewState.unmatched = Math.max(0, previewState.unmatched - 1);
		const m = document.getElementById('sumMatched'); if (m) m.textContent = previewState.matched;
		const u = document.getElementById('sumUnmatched'); if (u) u.textContent = previewState.unmatched;
		updateConfirmBtn();
	}

	// 2단계: 확정 업로드 (파일 재전송 → 저장. 새로 등록된 라이더가 매칭됨)
	// 주간 정산서 저장 — 같은 폼을 mode 없이 다시 보낸다(서버가 다시 주간으로 판별해 저장).
	document.getElementById('wkConfirmBtn').addEventListener('click', async function () {
		const file = fileInput?.files[0];
		if (!file) return;
		const btn = this;
		btn.setAttribute('data-kt-indicator', 'on');
		btn.disabled = true;
		try {
			const resp = await fetch(uploadApiUrl, { method: 'POST', body: new FormData(form) });
			const data = await resp.json();
			bootstrap.Modal.getInstance(document.getElementById('kt_weekly_preview_modal')).hide();
			if (data.ok) {
				showResult('success', data.message + ' — 「업로드 이력 → 주간 정산서」 탭에서 확인할 수 있습니다.');
				form.reset();
			} else {
				showResult('danger', data.error || '저장 실패');
			}
		} catch (err) {
			showResult('danger', '통신 오류: ' + err.message);
		} finally {
			btn.removeAttribute('data-kt-indicator');
			btn.disabled = false;
			weeklyPending = null;
		}
	});

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
				html += `</ul>`;
				if (data.warnings && data.warnings.length > 0) {
					html += `<div class="alert alert-warning mt-3 mb-0 p-3 fs-8">${data.warnings.map(escHtml).join('<br>')}</div>`;
				}
				html += `<div class="text-gray-600 fs-8 mt-3">잠시 후 업로드 상세 화면으로 이동합니다…</div>`;
				html += `</div>`;
				showResult('', html, true);
				// 업로드 직후 바로 상세로 — 매칭 확인·정산 반영을 이어서 하기 위함.
				// upload_id가 없으면(구 응답 등) 기존처럼 목록만 갱신한다.
				if (data.upload_id) {
					setTimeout(() => { window.location.href = detailBaseUrl + encodeURIComponent(data.upload_id); }, 1200);
				} else {
					setTimeout(() => location.reload(), 2200);
				}
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

	// ── 계좌 확인 ── 일정산 라이더는 매일 출금 대행이 나간다.
	document.addEventListener('DOMContentLoaded', function () {
		if (!window.AccountVerify) { return; }
		AccountVerify.attach({ bank: 'qrBank', account: 'qrAccount', holder: 'qrName',
			button: 'qrVerify', result: 'qrVerifyMsg' });
	});
})();
</script>


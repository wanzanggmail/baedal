<?php

declare(strict_types=1);

$dailyColumns = [
    '성함',
    '축약형 주문번호',
    '스토어명',
    '픽업지역',
    '배달지역',
    '배정시간',
    '수락시간',
    '배달시간',
    '배달소요시간(시:분)',
    '피크타임',
    '배달거리(m)',
    '배달타입',
    '픽업 비용',
    '배달 비용',
    '지역 단가',
    '배달거리 할증',
    '픽업지 할증',
    '도착지 할증',
    '기상 할증',
    '기타 프로모션1',
    '기타 프로모션2',
    '기타 프로모션3',
    '기타 프로모션4',
    '정산금액',
];

$weeklyColumns = [
    '주문일자',
    '축약형ID',
    '성함',
    '구분',
    '스토어명',
    '배정시간',
    '메뉴가',
    '배달비',
    '금액',
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
		<div class="tab-pane fade show active" id="kt_tab_daily" role="tabpanel">
			<div class="row g-5 g-xl-10">
				<div class="col-xl-5">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title align-items-start flex-column">
								<span class="card-label fw-bold text-gray-900">일간 정산서 업로드</span>
								<span class="text-gray-500 mt-1 fw-semibold fs-6">오더 상세 내역 → 건별 기초 데이터 적재</span>
							</h3>
						</div>
						<div class="card-body pt-5">
							<div class="alert alert-primary d-flex align-items-center p-5 mb-8">
								<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">플랫폼에서 받은 일간 정산서(.xlsx)를 그대로 올려 주세요.</span>
									<span class="text-gray-700 fs-7 mt-1">상단에 팀·정산일, 중간에 합계 행이 있고 데이터는 9행부터 헤더·10행부터 본문인 형식을 가정합니다. (첫 열은 비어 있을 수 있음)</span>
								</div>
							</div>
							<form class="form" action="#" method="post" enctype="multipart/form-data">
								<div class="mb-8">
									<label class="form-label required">플랫폼</label>
									<select class="form-select form-select-solid" name="platform_daily">
										<option value="baemin" selected>배달의민족</option>
										<option value="coupang">쿠팡이츠</option>
										<option value="other">기타</option>
									</select>
									<div class="form-text">목업 · 업로드·파싱은 연동 후 동작합니다.</div>
								</div>
								<div class="mb-8">
									<label class="form-label required">정산 귀속일</label>
									<input type="text" class="form-control form-control-solid" name="settlement_date_daily" value="2026-03-03" data-kt-flatpickr autocomplete="off" />
									<div class="form-text">엑셀 내 정산일과 일치하는지 확인용. 백엔드 연동 시 검증에 사용합니다.</div>
								</div>
								<div class="mb-10">
									<label class="form-label required">파일</label>
									<input type="file" class="form-control form-control-solid" name="file_daily" accept=".xlsx,.xls" />
								</div>
								<div class="d-flex justify-content-end">
									<button type="button" class="btn btn-primary" disabled>
										<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
										업로드 및 파싱 (준비 중)
									</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<div class="col-xl-7">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title">예상 데이터 열 (일간)</h3>
						</div>
						<div class="card-body pt-0">
							<div class="table-responsive">
								<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
									<thead>
										<tr class="fw-bold text-muted">
											<th class="min-w-80px">#</th>
											<th class="min-w-200px">열 이름</th>
											<th class="min-w-120px">비고</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($dailyColumns as $i => $label) :
										    $note = $label === '정산금액' ? '건당 최종 정산' : '';
										    ?>
										<tr>
											<td><?= (int) ($i + 1) ?></td>
											<td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-gray-600"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="tab-pane fade" id="kt_tab_weekly" role="tabpanel">
			<div class="row g-5 g-xl-10">
				<div class="col-xl-5">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title align-items-start flex-column">
								<span class="card-label fw-bold text-gray-900">주간 정산서 업로드</span>
								<span class="text-gray-500 mt-1 fw-semibold fs-6">차감내역 → 라이더별 차감 등록</span>
							</h3>
						</div>
						<div class="card-body pt-5">
							<div class="alert alert-warning d-flex align-items-center p-5 mb-8">
								<i class="ki-duotone ki-shield-tick fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span></i>
								<div class="d-flex flex-column">
									<span class="fw-bold">차감 관련 세부 사항은 담당 RM·플랫폼 안내를 따릅니다.</span>
									<span class="text-gray-700 fs-7 mt-1">주간 파일의 「금액」은 차감 반영 금액으로 파싱해 등록하는 흐름을 예정하고 있습니다.</span>
								</div>
							</div>
							<form class="form" action="#" method="post" enctype="multipart/form-data">
								<div class="mb-8">
									<label class="form-label required">플랫폼</label>
									<select class="form-select form-select-solid" name="platform_weekly">
										<option value="baemin" selected>배달의민족</option>
										<option value="coupang">쿠팡이츠</option>
										<option value="other">기타</option>
									</select>
								</div>
								<div class="mb-8">
									<label class="form-label required">정산 주차 (시작일)</label>
									<input type="text" class="form-control form-control-solid" name="week_start_weekly" value="2026-02-17" data-kt-flatpickr data-kt-flatpickr-week="true" autocomplete="off" />
									<div class="form-text">해당 주간 정산서 구간과 맞는 시작일을 선택합니다.</div>
								</div>
								<div class="mb-10">
									<label class="form-label required">파일</label>
									<input type="file" class="form-control form-control-solid" name="file_weekly" accept=".xlsx,.xls" />
								</div>
								<div class="d-flex justify-content-end">
									<button type="button" class="btn btn-primary" disabled>
										<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
										업로드 및 차감 반영 (준비 중)
									</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<div class="col-xl-7">
					<div class="card card-flush h-xl-100">
						<div class="card-header pt-7">
							<h3 class="card-title">예상 데이터 열 (주간 · 차감내역)</h3>
						</div>
						<div class="card-body pt-0">
							<div class="table-responsive">
								<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
									<thead>
										<tr class="fw-bold text-muted">
											<th class="min-w-80px">#</th>
											<th class="min-w-200px">열 이름</th>
											<th class="min-w-120px">비고</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($weeklyColumns as $i => $label) :
										    if ($label === '구분') {
										        $note = '오배달 유형 등';
										    } elseif ($label === '금액') {
										        $note = '차감 금액';
										    } elseif ($label === '축약형ID') {
										        $note = '주문 식별';
										    } else {
										        $note = '';
										    }
										    ?>
										<tr>
											<td><?= (int) ($i + 1) ?></td>
											<td><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-gray-600"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<p class="text-gray-600 fs-7 mt-6 mb-0">샘플 행 예: 다른주소로 오배달, 메뉴가·배달비·합계 금액이 함께 기재됩니다.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php require_once INC_PATH . '/app_content_close.php'; ?>


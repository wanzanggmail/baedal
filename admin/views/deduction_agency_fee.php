<?php

declare(strict_types=1);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">선공제(대행 수수료) 설정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">차감·수수료</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">선공제(대행)</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('deduction/entries'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">차감 내역</a>
			<a href="<?= htmlspecialchars(admin_url('deduction/auto'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">자동 차감 설정</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-wallet fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>정산 수수료</strong>(자동 계산)와 별도로, <strong>대행·중개 선공제</strong> 비율·방식을 정하는 화면입니다. 목업이며 저장은 되지 않습니다.
			선지급 정산·대여금 차감은 <a href="<?= htmlspecialchars(admin_url('deduction/auto'), ENT_QUOTES, 'UTF-8') ?>" class="fw-bold">자동 차감 설정</a>에서 <strong>라이더별</strong>로 다룹니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">선공제 규칙</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">정산 지급 전 차감되는 대행 수수료 (예시 필드)</span>
			</div>
		</div>
		<div class="card-body pt-5">
			<div class="row g-6">
				<div class="col-md-4">
					<label class="form-label">적용 대상</label>
					<select class="form-select form-select-solid" name="agency_target">
						<option value="all" selected>전체 라이더</option>
						<option value="team">팀 단위</option>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label">공제 방식</label>
					<select class="form-select form-select-solid" name="agency_mode">
						<option value="pct" selected>정산액 대비 %</option>
						<option value="fixed">건당 정액</option>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label">비율 / 금액 (예시)</label>
					<div class="input-group input-group-solid">
						<input type="text" class="form-control" name="agency_rate" value="1.5" />
						<span class="input-group-text">%</span>
					</div>
				</div>
				<div class="col-md-6">
					<label class="form-label">적용 시작일</label>
					<input type="text" class="form-control form-control-solid" name="agency_start" value="2026-05-01" data-kt-flatpickr autocomplete="off" />
				</div>
				<div class="col-md-6">
					<label class="form-label">메모</label>
					<input type="text" class="form-control form-control-solid" name="agency_memo" value="도깨비 대행 계약 기준" />
				</div>
			</div>
			<div class="mt-6 d-flex gap-3">
				<button type="button" class="btn btn-primary" disabled>저장 (준비 중)</button>
				<button type="button" class="btn btn-light" disabled>임시 적용 해제</button>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">적용 이력 (샘플)</h3>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3 fs-7">
					<thead>
						<tr class="fw-bold text-muted">
							<th>시작일</th>
							<th>방식</th>
							<th>값</th>
							<th>비고</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>2026-05-01</td>
							<td>정산액 %</td>
							<td>1.5%</td>
							<td class="text-gray-600">목업</td>
						</tr>
						<tr>
							<td>2026-04-01</td>
							<td>정산액 %</td>
							<td>1.5%</td>
							<td class="text-gray-600">목업</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

$mockUploads = [
    [
        'id' => 'up-20260510-001',
        'uploaded_at' => '2026-05-10 09:12:08',
        'kind' => '일간',
        'platform' => '배민',
        'file' => 'baemin_settlement_20260510.xlsx',
        'rows' => 842,
        'ok' => 838,
        'skipped' => 2,
        'errors' => 2,
        'operator' => 'admin01',
        'status' => '완료',
        'status_class' => 'success',
    ],
    [
        'id' => 'up-20260510-002',
        'uploaded_at' => '2026-05-10 08:55:41',
        'kind' => '일간',
        'platform' => '쿠팡',
        'file' => 'coupang_daily_20260510.xls',
        'rows' => 631,
        'ok' => 631,
        'skipped' => 0,
        'errors' => 0,
        'operator' => 'admin01',
        'status' => '완료',
        'status_class' => 'success',
    ],
    [
        'id' => 'up-20260509-014',
        'uploaded_at' => '2026-05-09 10:22:15',
        'kind' => '일간',
        'platform' => '배민',
        'file' => 'baemin_settlement_20260509.xlsx',
        'rows' => 801,
        'ok' => 797,
        'skipped' => 0,
        'errors' => 4,
        'operator' => 'admin01',
        'status' => '경고',
        'status_class' => 'warning',
    ],
    [
        'id' => 'up-20260508-w01',
        'uploaded_at' => '2026-05-08 16:40:00',
        'kind' => '주간',
        'platform' => '배민',
        'file' => 'weekly_deduction_20260505.xlsx',
        'rows' => 14,
        'ok' => 14,
        'skipped' => 0,
        'errors' => 0,
        'operator' => 'admin02',
        'status' => '완료',
        'status_class' => 'success',
    ],
    [
        'id' => 'up-20260507-003',
        'uploaded_at' => '2026-05-07 11:03:22',
        'kind' => '일간',
        'platform' => '배민',
        'file' => 'baemin_settlement_20260507.xlsx',
        'rows' => 0,
        'ok' => 0,
        'skipped' => 0,
        'errors' => 1,
        'operator' => 'admin01',
        'status' => '실패',
        'status_class' => 'danger',
    ],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">업로드 이력</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">업로드 이력</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 gap-lg-3 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
				새 업로드
			</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/parse-errors'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-danger fw-bold">
				<i class="ki-duotone ki-information-2 fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
				파싱 오류 상세
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="d-flex flex-column pe-0 pe-sm-10">
			<h5 class="mb-1">샘플 데이터입니다</h5>
			<span class="fs-7 text-gray-700">실제 서비스에서는 DB에서 업로드 배치 목록을 조회합니다. 오류가 있는 행은 「파싱 오류 상세」에서 배치별로 확인할 수 있습니다.</span>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body pt-6 pb-4">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold text-gray-700">기간</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="hist_from" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="hist_to" data-kt-daterange-to value="2026-05-10" />
					</div>
					<div class="form-text">목업 · daterangepicker</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold text-gray-700">유형</label>
					<select class="form-select form-select-solid" name="hist_kind">
						<option value="" selected>전체</option>
						<option value="daily">일간</option>
						<option value="weekly">주간</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold text-gray-700">플랫폼</label>
					<select class="form-select form-select-solid" name="hist_platform">
						<option value="" selected>전체</option>
						<option value="baemin">배민</option>
						<option value="coupang">쿠팡</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold text-gray-700">상태</label>
					<select class="form-select form-select-solid" name="hist_status">
						<option value="" selected>전체</option>
						<option value="ok">완료</option>
						<option value="warn">경고</option>
						<option value="fail">실패</option>
					</select>
				</div>
				<div class="col-md-3 text-md-end">
					<button type="button" class="btn btn-light-primary me-2" disabled>조회</button>
					<button type="button" class="btn btn-light" disabled>초기화</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">업로드 배치 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">목업 · 총 <?= count($mockUploads) ?>건 표시</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-160px">업로드 일시</th>
							<th class="min-w-80px">유형</th>
							<th class="min-w-90px">플랫폼</th>
							<th class="min-w-200px">파일명</th>
							<th class="min-w-80px text-end">총 행</th>
							<th class="min-w-80px text-end">반영</th>
							<th class="min-w-70px text-end">스킵</th>
							<th class="min-w-70px text-end">오류</th>
							<th class="min-w-100px">처리자</th>
							<th class="min-w-90px">상태</th>
							<th class="min-w-120px text-end">작업</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockUploads as $row) :
						    $parseUrl = admin_url('settlement/parse-errors') . '?batch=' . rawurlencode($row['id']);
						    ?>
						<tr>
							<td><span class="text-gray-800 fw-semibold"><?= htmlspecialchars($row['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td><span class="badge badge-light"><?= htmlspecialchars($row['kind'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td>
								<?php if ($row['platform'] === '배민') : ?>
									<span class="badge badge-light-primary"><?= htmlspecialchars($row['platform'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php else : ?>
									<span class="badge badge-light-success"><?= htmlspecialchars($row['platform'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php endif; ?>
							</td>
							<td class="text-gray-700"><?= htmlspecialchars($row['file'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end text-gray-800"><?= (int) $row['rows'] ?></td>
							<td class="text-end text-gray-800"><?= (int) $row['ok'] ?></td>
							<td class="text-end text-muted"><?= (int) $row['skipped'] ?></td>
							<td class="text-end">
								<?php if ($row['errors'] > 0) : ?>
									<span class="text-danger fw-bold"><?= (int) $row['errors'] ?></span>
								<?php else : ?>
									<span class="text-gray-600">0</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-700"><?= htmlspecialchars($row['operator'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-end">
								<?php if ($row['errors'] > 0 || $row['status_class'] === 'warning') : ?>
									<a href="<?= htmlspecialchars($parseUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-danger">오류 보기</a>
								<?php else : ?>
									<span class="text-muted fs-7">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

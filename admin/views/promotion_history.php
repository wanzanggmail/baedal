<?php

declare(strict_types=1);

$mockRuns = [
    [
        'id' => 'pr-20260510-001',
        'run_at' => '2026-05-10 18:02:11',
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-07',
        'tiers' => 3,
        'riders' => 118,
        'total_pay' => 4285000,
        'operator' => 'admin01',
        'status' => '완료',
        'status_class' => 'success',
        'note' => '건수 구간 프로모션',
    ],
    [
        'id' => 'pr-20260503-002',
        'run_at' => '2026-05-03 17:45:00',
        'period_start' => '2026-04-24',
        'period_end' => '2026-04-30',
        'tiers' => 2,
        'riders' => 105,
        'total_pay' => 3150000,
        'operator' => 'admin01',
        'status' => '완료',
        'status_class' => 'success',
        'note' => '건수 구간 프로모션',
    ],
    [
        'id' => 'pr-20260426-001',
        'run_at' => '2026-04-26 09:10:22',
        'period_start' => '2026-04-17',
        'period_end' => '2026-04-23',
        'tiers' => 4,
        'riders' => 0,
        'total_pay' => 0,
        'operator' => 'admin02',
        'status' => '중단',
        'status_class' => 'warning',
        'note' => '데이터 검증 실패(목업)',
    ],
    [
        'id' => 'pr-20260419-003',
        'run_at' => '2026-04-19 16:30:05',
        'period_start' => '2026-04-10',
        'period_end' => '2026-04-16',
        'tiers' => 2,
        'riders' => 98,
        'total_pay' => 2940000,
        'operator' => 'admin01',
        'status' => '완료',
        'status_class' => 'success',
        'note' => '건수 구간 프로모션',
    ],
];

function format_won(int $n): string
{
    return '₩ ' . number_format($n);
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 실행 이력</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">프로모션</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">실행 이력</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('promotion/batch'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-rocket fs-3"><span class="path1"></span><span class="path2"></span></i>
				배치 실행
			</a>
			<a href="<?= htmlspecialchars(admin_url('promotion/rules'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">프로모션 규칙</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-time fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="d-flex flex-column pe-0 pe-sm-10">
			<h5 class="mb-1">샘플 실행 이력입니다</h5>
			<span class="fs-7 text-gray-700">실제 서비스에서는 배치 실행 시 생성된 작업 ID·집계 결과를 DB에서 조회합니다. 상세 로그·되돌리기는 연동 후 제공할 수 있습니다.</span>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body pt-6 pb-4">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold text-gray-700">실행일 기준</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="promo_hist_run_from" data-kt-daterange-from value="2026-04-01" />
						<input type="hidden" name="promo_hist_run_to" data-kt-daterange-to value="2026-05-10" />
					</div>
					<div class="form-text">목업 · 실행일 기준</div>
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold text-gray-700">적용 기간 (겹침)</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="promo_hist_apply_from" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="promo_hist_apply_to" data-kt-daterange-to value="2026-05-10" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold text-gray-700">상태</label>
					<select class="form-select form-select-solid" name="promo_hist_status">
						<option value="" selected>전체</option>
						<option value="ok">완료</option>
						<option value="stop">중단</option>
						<option value="fail">실패</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold text-gray-700">실행자</label>
					<select class="form-select form-select-solid" name="promo_hist_operator">
						<option value="" selected>전체</option>
						<option value="admin01">admin01</option>
						<option value="admin02">admin02</option>
					</select>
				</div>
				<div class="col-md-2 text-md-end">
					<button type="button" class="btn btn-light-primary me-2" disabled>조회</button>
					<button type="button" class="btn btn-light" disabled>초기화</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">실행 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">목업 · 총 <?= count($mockRuns) ?>건 표시</span>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light" disabled>엑셀 내보내기 (준비 중)</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">실행 ID</th>
							<th class="min-w-150px">실행 일시</th>
							<th class="min-w-200px">적용 기간</th>
							<th class="min-w-90px text-center">조건(구간)</th>
							<th class="min-w-100px text-end">대상 라이더</th>
							<th class="min-w-130px text-end">총 지급액</th>
							<th class="min-w-100px">실행자</th>
							<th class="min-w-90px">상태</th>
							<th class="min-w-160px">비고</th>
							<th class="min-w-100px text-end">작업</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockRuns as $row) : ?>
						<tr>
							<td><span class="text-gray-900 fw-semibold font-monospace fs-7"><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-gray-800"><?= htmlspecialchars($row['run_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-800"><?= htmlspecialchars($row['period_start'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-gray-500 mx-1">~</span>
								<span class="text-gray-800"><?= htmlspecialchars($row['period_end'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-center">
								<span class="badge badge-light"><?= (int) $row['tiers'] ?>구간</span>
							</td>
							<td class="text-end text-gray-800"><?= $row['riders'] > 0 ? number_format((int) $row['riders']) . '명' : '—' ?></td>
							<td class="text-end fw-semibold text-gray-800"><?= $row['total_pay'] > 0 ? htmlspecialchars(format_won((int) $row['total_pay']), ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['operator'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-gray-600 fs-7"><?= htmlspecialchars($row['note'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light" disabled title="연동 예정">상세</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

$mockDeductionBreakdown = [
    ['item' => '원천세', 'source' => '자동 계산', 'amount' => 18200000, 'pct' => 28.4],
    ['item' => '고용·산재', 'source' => '자동 계산', 'amount' => 9650000, 'pct' => 15.1],
    ['item' => '정산 수수료', 'source' => '자동 계산', 'amount' => 11200000, 'pct' => 17.5],
    ['item' => '선공제(대행)', 'source' => '선공제 설정', 'amount' => 4200000, 'pct' => 6.6],
    ['item' => '시간제 보험', 'source' => '주간 정산서', 'amount' => 3100000, 'pct' => 4.8],
    ['item' => '보험료 환급', 'source' => '주간 정산서', 'amount' => -890000, 'pct' => -1.4],
    ['item' => '대여금 차감', 'source' => '라이더별 자동', 'amount' => 7800000, 'pct' => 12.2],
    ['item' => '선지급 정산', 'source' => '라이더별 자동', 'amount' => 5100000, 'pct' => 8.0],
    ['item' => '수동·기타', 'source' => '수동', 'amount' => 5800000, 'pct' => 9.0],
];

$mockWeeklyUploads = [
    ['week' => '2026-05-05 ~ 11', 'daily_batches' => 7, 'weekly_batches' => 1, 'orders' => 5840],
    ['week' => '2026-04-28 ~ 05-04', 'daily_batches' => 7, 'weekly_batches' => 1, 'orders' => 5621],
    ['week' => '2026-04-21 ~ 27', 'daily_batches' => 7, 'weekly_batches' => 1, 'orders' => 5488],
];

$mockPromoRuns = [
    ['id' => 'pr-20260510-001', 'period' => '2026-05-01 ~ 07', 'riders' => 118, 'pay' => 4285000],
    ['id' => 'pr-20260503-002', 'period' => '2026-04-24 ~ 30', 'riders' => 105, 'pay' => 3150000],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">종합 통계</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">통계</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">종합</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('stats/export'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
				기초데이터 내보내기
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-chart-simple fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업 데이터</strong>입니다. 정산 업로드·차감(자동/주간정산서/수동)·프로모션 실행 결과가 쌓이면 동일한 구조로 집계합니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold">집계 기간</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="stats_from" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="stats_to" data-kt-daterange-to value="2026-05-10" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">플랫폼</label>
					<select class="form-select form-select-solid" name="stats_platform">
						<option value="" selected>전체</option>
						<option value="baemin">배달의민족</option>
						<option value="coupang">쿠팡이츠</option>
						<option value="other">기타</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">팀</label>
					<select class="form-select form-select-solid" name="stats_team">
						<option value="" selected>전체</option>
						<option value="gangseo">강서남부</option>
						<option value="other">기타 팀</option>
					</select>
				</div>
				<div class="col-md-5 text-md-end">
					<button type="button" class="btn btn-light-primary me-2" disabled>조회</button>
					<button type="button" class="btn btn-light" disabled>초기화</button>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-sm-6 col-xl-3">
			<div class="card h-100">
				<div class="card-body d-flex flex-column">
					<span class="text-gray-600 fs-7 fw-semibold">총 정산 지급(건당 합)</span>
					<span class="fs-2x fw-bold text-gray-900 my-3">₩ 412.8M</span>
					<span class="badge badge-light-success fs-8">일간 정산서 기준 · 목업</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card h-100">
				<div class="card-body d-flex flex-column">
					<span class="text-gray-600 fs-7 fw-semibold">총 차감·공제</span>
					<span class="fs-2x fw-bold text-danger my-3">₩ 64.0M</span>
					<span class="badge badge-light-danger fs-8">아래 항목별 합산 · 목업</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card h-100">
				<div class="card-body d-flex flex-column">
					<span class="text-gray-600 fs-7 fw-semibold">프로모션 지급</span>
					<span class="fs-2x fw-bold text-primary my-3">₩ 7.4M</span>
					<span class="badge badge-light-primary fs-8">배치 실행 합계 · 목업</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card h-100 border border-primary border-dashed">
				<div class="card-body d-flex flex-column">
					<span class="text-gray-600 fs-7 fw-semibold">순 지급 예상(목업)</span>
					<span class="fs-2x fw-bold text-gray-900 my-3">₩ 356.2M</span>
					<span class="text-gray-600 fs-8">정산 − 차감 + 프로모션 등 단순 예시</span>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-8">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">주차별 추이 (차트 영역)</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">연동 후 Chart.js 등으로 정산·차감·순지급 추이 표시</span>
				</div>
				<div class="card-body d-flex align-items-center justify-content-center bg-light rounded-bottom" style="min-height: 260px;">
					<div class="text-center text-gray-500 fs-7">
						<i class="ki-duotone ki-chart-line-up fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
						<div>그래프 플레이스홀더</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">요약 지표</h3>
				</div>
				<div class="card-body pt-3">
					<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
						<span class="text-gray-700">활성 라이더</span>
						<span class="fw-bold text-gray-900">128명</span>
					</div>
					<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
						<span class="text-gray-700">일간 업로드 배치</span>
						<span class="fw-bold text-gray-900">52건</span>
					</div>
					<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
						<span class="text-gray-700">주간 업로드 배치</span>
						<span class="fw-bold text-gray-900">8건</span>
					</div>
					<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
						<span class="text-gray-700">파싱 오류 행(누적)</span>
						<span class="fw-bold text-warning">14행</span>
					</div>
					<div class="d-flex justify-content-between py-3">
						<span class="text-gray-700">프로모션 실행</span>
						<span class="fw-bold text-gray-900">6회</span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">차감·공제 항목별 집계</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">자동 계산 · 주간 정산서 · 라이더별 자동 · 수동 (목업)</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th>항목</th>
							<th>출처</th>
							<th class="text-end">금액</th>
							<th class="text-end">비중(%)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockDeductionBreakdown as $row) : ?>
						<tr>
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($row['item'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light"><?= htmlspecialchars($row['source'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-end fw-semibold <?= $row['amount'] < 0 ? 'text-success' : 'text-gray-900' ?>">₩ <?= number_format((int) $row['amount']) ?></td>
							<td class="text-end text-gray-600"><?= htmlspecialchars((string) $row['pct'], ENT_QUOTES, 'UTF-8') ?>%</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="row g-5 g-xl-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">정산 업로드·오더 규모</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">주차별 일간/주간 배치 수 (목업)</span>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-dashed table-row-gray-300 gs-0 gy-3 fs-7">
							<thead>
								<tr class="fw-bold text-muted">
									<th>주차</th>
									<th class="text-end">일간 배치</th>
									<th class="text-end">주간 배치</th>
									<th class="text-end">오더 건수(합)</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($mockWeeklyUploads as $w) : ?>
								<tr>
									<td><?= htmlspecialchars($w['week'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-end"><?= (int) $w['daily_batches'] ?></td>
									<td class="text-end"><?= (int) $w['weekly_batches'] ?></td>
									<td class="text-end fw-semibold"><?= number_format((int) $w['orders']) ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">프로모션 실행 요약</h3>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-dashed table-row-gray-300 gs-0 gy-3 fs-7">
							<thead>
								<tr class="fw-bold text-muted">
									<th>실행 ID</th>
									<th>적용 기간</th>
									<th class="text-end">대상</th>
									<th class="text-end">지급액</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($mockPromoRuns as $p) : ?>
								<tr>
									<td class="font-monospace"><?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><?= htmlspecialchars($p['period'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-end"><?= (int) $p['riders'] ?>명</td>
									<td class="text-end fw-semibold">₩ <?= number_format((int) $p['pay']) ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<a href="<?= htmlspecialchars(admin_url('promotion/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light mt-4">실행 이력 화면</a>
				</div>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

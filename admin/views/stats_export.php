<?php

declare(strict_types=1);

$exportJobs = [
    [
        'id' => 'daily_orders',
        'title' => '일간 정산 기초데이터 (오더 상세)',
        'desc' => '일간 정산서 업로드로 적재한 건별 데이터 — 성함, 주문번호, 스토어, 정산금액 등 엑셀과 동일 컬럼 구조.',
        'format' => 'XLSX',
        'columns' => '성함, 축약형 주문번호, 스토어명, 픽업/배달지역, 배정·수락·배달시간, 피크타입, 거리, 각종 비용, 정산금액 …',
    ],
    [
        'id' => 'weekly_deduction',
        'title' => '주간 정산·차감 원본',
        'desc' => '주간 정산서에서 읽은 차감내역 — 시간제 보험, 보험료 환급, 기타 플랫폼 차감 행.',
        'format' => 'XLSX',
        'columns' => '주문일자, 축약형ID, 성함, 구분, 스토어명, 배정시간, 메뉴가, 배달비, 금액 …',
    ],
    [
        'id' => 'rider_settlement_rollup',
        'title' => '라이더별 정산·차감 합산표',
        'desc' => '기간 내 라이더 단위로 정산 합계, 항목별 차감 합계, 프로모션, 순액 등을 한 시트로.',
        'format' => 'XLSX',
        'columns' => '라이더ID, 이름, 정산총액, 원천세, 고용·산재, 수수료, 대여·선지급, 주간정산항목, 프로모션, 순지급액 …',
    ],
    [
        'id' => 'deduction_lines',
        'title' => '차감 내역 상세 (행 단위)',
        'desc' => '차감 내역 등록·자동·주간 반영분을 모두 포함한 감사/대사용 리스트.',
        'format' => 'XLSX',
        'columns' => '일시, 라이더, 정산주차, 항목, 출처(자동/주간/수동), 금액, 메모 …',
    ],
    [
        'id' => 'promotion_payout',
        'title' => '프로모션 지급 내역',
        'desc' => '배치 실행별·라이더별 지급 금액과 적용 기간.',
        'format' => 'XLSX',
        'columns' => '실행ID, 라이더, 적용기간, 구간조건, 지급액 …',
    ],
    [
        'id' => 'master_riders',
        'title' => '라이더 마스터 스냅샷',
        'desc' => '엑셀 내보내기 시점의 라이더 식별·팀·상태 (연동 후).',
        'format' => 'XLSX',
        'columns' => '라이더ID, 이름, 팀, 상태, 연락처(마스킹) …',
    ],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">기초데이터 엑셀 내보내기</h1>
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
				<li class="breadcrumb-item text-gray-900">내보내기</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('stats/summary'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">종합 통계</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-info d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-file-down fs-2x text-info me-4"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800 mb-0">
			<strong>다운로드는 아직 동작하지 않습니다.</strong> 아래는 정산·차감·프로모션 도메인을 기준으로 한 <strong>내보내기 종류·컬럼 안내 목업</strong>입니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-4">
					<label class="form-label fw-semibold">데이터 기준일 (공통)</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="export_global_from" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="export_global_to" data-kt-daterange-to value="2026-05-10" />
					</div>
					<div class="form-text">각 항목별로 별도 기간을 둘 수 있음 (연동 후)</div>
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">인코딩</label>
					<select class="form-select form-select-solid" name="export_encoding">
						<option value="utf8" selected>UTF-8 (Excel 호환)</option>
						<option value="utf8bom">UTF-8 BOM</option>
					</select>
				</div>
				<div class="col-md-5 text-md-end">
					<button type="button" class="btn btn-light-primary" disabled>선택 항목 일괄 생성 (준비 중)</button>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-6">
		<?php foreach ($exportJobs as $job) : ?>
		<div class="col-12">
			<div class="card card-flush border border-gray-200">
				<div class="card-body py-6">
					<div class="d-flex flex-column flex-lg-row align-items-lg-start justify-content-between gap-5">
						<div class="flex-grow-1">
							<div class="d-flex align-items-center gap-2 mb-2">
								<h3 class="fs-5 fw-bold text-gray-900 mb-0"><?= htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') ?></h3>
								<span class="badge badge-light-dark"><?= htmlspecialchars($job['format'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<p class="text-gray-700 fs-7 mb-4"><?= htmlspecialchars($job['desc'], ENT_QUOTES, 'UTF-8') ?></p>
							<div class="bg-light rounded p-4">
								<span class="text-gray-600 fs-8 fw-bold text-uppercase d-block mb-1">포함 컬럼 (요약)</span>
								<p class="fs-7 text-gray-800 mb-0"><?= htmlspecialchars($job['columns'], ENT_QUOTES, 'UTF-8') ?></p>
							</div>
						</div>
						<div class="d-flex flex-column gap-3 min-w-200px">
							<div>
								<label class="form-label fs-8 mb-1">이 항목 기간</label>
								<div data-kt-daterange="true">
									<input type="text" class="form-control form-control-solid form-control-sm" data-kt-daterange-display readonly placeholder="기간" />
									<input type="hidden" name="export_<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>_from" data-kt-daterange-from value="2026-05-01" />
									<input type="hidden" name="export_<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>_to" data-kt-daterange-to value="2026-05-10" />
								</div>
							</div>
							<div class="form-check form-check-custom form-check-solid">
								<input class="form-check-input" type="checkbox" id="chk-<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>" />
								<label class="form-check-label fs-7" for="chk-<?= htmlspecialchars($job['id'], ENT_QUOTES, 'UTF-8') ?>">일괄 대상에 포함</label>
							</div>
							<button type="button" class="btn btn-sm btn-primary" disabled>
								<i class="ki-duotone ki-file-down fs-4"><span class="path1"></span><span class="path2"></span></i>
								엑셀 받기
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="card card-flush mt-8 bg-light">
		<div class="card-body py-6">
			<h4 class="fs-6 fw-bold text-gray-900 mb-3">내보내기 정책 (안내)</h4>
			<ul class="mb-0 ps-4 fs-7 text-gray-700">
				<li class="mb-2">대용량 일간 오더 데이터는 <strong>기간 분할</strong> 또는 <strong>배치 ID 단위</strong> 다운로드를 지원할 수 있습니다.</li>
				<li class="mb-2">차감·프로모션 내역은 <strong>집계 시점</strong> 스냅샷이므로, 회계 대사 시 실행 일시를 파일명에 포함하는 방식을 권장합니다.</li>
				<li>개인정보 컬럼은 마스킹·권한별 제외 옵션을 둘 수 있습니다.</li>
			</ul>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

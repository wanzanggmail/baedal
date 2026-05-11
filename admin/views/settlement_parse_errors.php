<?php

declare(strict_types=1);

$batchId = isset($_GET['batch']) ? trim((string) $_GET['batch']) : '';

$mockBatches = [
    'up-20260509-014' => [
        'label' => 'baemin_settlement_20260509.xlsx',
        'uploaded_at' => '2026-05-09 10:22:15',
        'kind' => '일간',
        'platform' => '배민',
        'total_rows' => 801,
        'error_rows' => 4,
        'warn_rows' => 1,
    ],
    'up-20260510-001' => [
        'label' => 'baemin_settlement_20260510.xlsx',
        'uploaded_at' => '2026-05-10 09:12:08',
        'kind' => '일간',
        'platform' => '배민',
        'total_rows' => 842,
        'error_rows' => 2,
        'warn_rows' => 0,
    ],
    'up-20260507-003' => [
        'label' => 'baemin_settlement_20260507.xlsx',
        'uploaded_at' => '2026-05-07 11:03:22',
        'kind' => '일간',
        'platform' => '배민',
        'total_rows' => 0,
        'error_rows' => 1,
        'warn_rows' => 0,
    ],
];

$mockErrors = [
    [
        'sheet_row' => 112,
        'excel_row' => 121,
        'type' => '형식 오류',
        'field' => '정산금액',
        'raw' => '(공란)',
        'message' => '숫자가 필요한 열에 값이 비어 있습니다.',
        'severity' => '오류',
        'severity_class' => 'danger',
    ],
    [
        'sheet_row' => 205,
        'excel_row' => 214,
        'type' => '형식 오류',
        'field' => '배정시간',
        'raw' => '3/4 오전',
        'message' => '날짜·시간 형식을 파싱할 수 없습니다.',
        'severity' => '오류',
        'severity_class' => 'danger',
    ],
    [
        'sheet_row' => 441,
        'excel_row' => 450,
        'type' => '참조 오류',
        'field' => '축약형 주문번호',
        'raw' => 'AB@#',
        'message' => '주문번호 패턴이 예상과 다릅니다. (영숫자 6자 등)',
        'severity' => '경고',
        'severity_class' => 'warning',
    ],
    [
        'sheet_row' => 620,
        'excel_row' => 629,
        'type' => '범위 오류',
        'field' => '정산금액',
        'raw' => '-500',
        'message' => '음수 금액은 허용되지 않습니다. 원본 엑셀을 확인하세요.',
        'severity' => '오류',
        'severity_class' => 'danger',
    ],
];

$batchMeta = $mockBatches[$batchId] ?? null;
if ($batchMeta === null) {
    $batchMeta = [
        'label' => '샘플 배치 (목업)',
        'uploaded_at' => '2026-05-09 10:22:15',
        'kind' => '일간',
        'platform' => '배민',
        'total_rows' => 801,
        'error_rows' => 4,
        'warn_rows' => 1,
    ];
    if ($batchId === '') {
        $batchId = 'sample';
    }
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">파싱 오류 상세</h1>
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
				<li class="breadcrumb-item text-gray-900">파싱 오류</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">
				<i class="ki-duotone ki-arrow-left fs-3"><span class="path1"></span><span class="path2"></span></i>
				업로드 이력
			</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">새 업로드</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="card card-flush mb-8">
		<div class="card-body py-6">
			<div class="d-flex flex-wrap align-items-center justify-content-between gap-4">
				<div>
					<span class="text-gray-600 fs-7 fw-semibold text-uppercase">배치 ID</span>
					<div class="fs-4 fw-bold text-gray-900 font-monospace"><?= htmlspecialchars($batchId, ENT_QUOTES, 'UTF-8') ?></div>
				</div>
				<div>
					<span class="text-gray-600 fs-7 fw-semibold text-uppercase">파일</span>
					<div class="fw-bold text-gray-800"><?= htmlspecialchars($batchMeta['label'], ENT_QUOTES, 'UTF-8') ?></div>
				</div>
				<div>
					<span class="text-gray-600 fs-7 fw-semibold text-uppercase">업로드</span>
					<div class="text-gray-800"><?= htmlspecialchars($batchMeta['uploaded_at'], ENT_QUOTES, 'UTF-8') ?></div>
				</div>
				<div>
					<span class="text-gray-600 fs-7 fw-semibold text-uppercase">유형 · 플랫폼</span>
					<div>
						<span class="badge badge-light me-1"><?= htmlspecialchars($batchMeta['kind'], ENT_QUOTES, 'UTF-8') ?></span>
						<span class="badge badge-light-primary"><?= htmlspecialchars($batchMeta['platform'], ENT_QUOTES, 'UTF-8') ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-md-4">
			<div class="card card-flush h-100">
				<div class="card-body d-flex align-items-center">
					<i class="ki-duotone ki-row-horizontal fs-3x text-primary me-4"><span class="path1"></span><span class="path2"></span></i>
					<div>
						<div class="fs-2hx fw-bold text-gray-800"><?= (int) $batchMeta['total_rows'] ?></div>
						<div class="text-gray-600 fs-7 fw-semibold">파싱 대상 행 (목업)</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-danger border-dashed">
				<div class="card-body d-flex align-items-center">
					<i class="ki-duotone ki-cross-circle fs-3x text-danger me-4"><span class="path1"></span><span class="path2"></span></i>
					<div>
						<div class="fs-2hx fw-bold text-danger"><?= (int) $batchMeta['error_rows'] ?></div>
						<div class="text-gray-600 fs-7 fw-semibold">오류 (반영 제외)</div>
					</div>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-warning border-dashed">
				<div class="card-body d-flex align-items-center">
					<i class="ki-duotone ki-information-2 fs-3x text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
					<div>
						<div class="fs-2hx fw-bold text-warning"><?= (int) $batchMeta['warn_rows'] ?></div>
						<div class="text-gray-600 fs-7 fw-semibold">경고 (반영 가능)</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="alert bg-light d-flex align-items-center mb-8">
		<i class="ki-duotone ki-document fs-2 text-gray-600 me-3"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-700">
			<strong>행 번호 안내 (목업):</strong> 「시트 행」은 파서가 읽은 데이터 행 번호, 「엑셀 행」은 파일 상의 행 번호(헤더·빈 행 포함 시 차이가 날 수 있음)를 가정한 예시입니다.
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">오류·경고 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">샘플 <?= count($mockErrors) ?>건 · 실제 연동 시 DB/로그에서 조회</span>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light" disabled>CSV 내보내기 (준비 중)</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-90px text-end">시트 행</th>
							<th class="min-w-90px text-end">엑셀 행</th>
							<th class="min-w-110px">유형</th>
							<th class="min-w-120px">필드</th>
							<th class="min-w-160px">원본 값</th>
							<th class="min-w-200px">메시지</th>
							<th class="min-w-90px">심각도</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockErrors as $err) : ?>
						<tr>
							<td class="text-end font-monospace text-gray-800"><?= (int) $err['sheet_row'] ?></td>
							<td class="text-end font-monospace text-gray-600"><?= (int) $err['excel_row'] ?></td>
							<td class="text-gray-800"><?= htmlspecialchars($err['type'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light-primary"><?= htmlspecialchars($err['field'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-gray-700"><code class="text-gray-800"><?= htmlspecialchars($err['raw'], ENT_QUOTES, 'UTF-8') ?></code></td>
							<td class="text-gray-700"><?= htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light-<?= htmlspecialchars($err['severity_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($err['severity'], ENT_QUOTES, 'UTF-8') ?></span></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

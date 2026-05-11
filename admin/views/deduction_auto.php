<?php

declare(strict_types=1);

/** 라이더별 대여금·선지급 자동 차감 (목업) */
$mockRiderAuto = [
    [
        'rider' => '권성진4418',
        'rental_on' => true,
        'rental_mode' => '주당 고정',
        'rental_weekly' => 50000,
        'rental_note' => '잔액 65만',
        'advance_on' => true,
        'advance_mode' => '주당 고정',
        'advance_weekly' => 20000,
        'advance_note' => '선지급 잔액 12만',
        'priority' => '대여 → 선지급',
    ],
    [
        'rider' => '민세훈3274',
        'rental_on' => true,
        'rental_mode' => '주당 고정',
        'rental_weekly' => 40000,
        'rental_note' => '할부 8주 남음',
        'advance_on' => false,
        'advance_mode' => '—',
        'advance_weekly' => 0,
        'advance_note' => '미사용',
        'priority' => '대여만',
    ],
    [
        'rider' => '노동현0647',
        'rental_on' => false,
        'rental_mode' => '—',
        'rental_weekly' => 0,
        'rental_note' => '미설정',
        'advance_on' => true,
        'advance_mode' => '정산액 비율',
        'advance_weekly' => 0,
        'advance_note' => '5% (예시)',
        'priority' => '선지급만',
    ],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">자동 차감 설정</h1>
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
				<li class="breadcrumb-item text-gray-900">자동 차감 설정</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('deduction/entries'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">차감 내역</a>
			<a href="<?= htmlspecialchars(admin_url('deduction/installment'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">할부 관리</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-info d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2x text-info me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800 mb-0">
			<strong>자동 계산</strong>(원천세·고용·산재·정산 수수료)은 공통 규칙·세율로 관리하고,
			<strong>대여금 차감·선지급 정산</strong>은 <strong>라이더(개인)별</strong>로 금액·방식·사용 여부를 둡니다.
			<strong>시간제 보험·보험료 환급</strong>은 주간 정산서 업로드로 반영됩니다.
		</div>
	</div>

	<!-- A. 자동 계산 (설명 + 비율 목업) -->
	<div class="card card-flush mb-8">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">자동 계산 항목</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">원천세 · 고용·산재 · 정산 수수료 — 정산 금액·마스터 기준 산출 (저장 기능 없음)</span>
			</div>
		</div>
		<div class="card-body pt-5">
			<div class="row g-6">
				<div class="col-md-4">
					<label class="form-label fw-semibold">원천세</label>
					<div class="input-group input-group-solid mb-2">
						<input type="text" class="form-control" name="auto_withholding_pct" value="3.3" />
						<span class="input-group-text">% (예시)</span>
					</div>
					<div class="form-text">과세 대상 금액 × 세율 (실제 공식은 연동 시)</div>
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">고용·산재</label>
					<div class="input-group input-group-solid mb-2">
						<input type="text" class="form-control" name="auto_employment_pct" value="9.12" />
						<span class="input-group-text">% (예시)</span>
					</div>
					<div class="form-text">보수월액·요율 연동 예정</div>
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">정산 수수료</label>
					<div class="input-group input-group-solid mb-2">
						<input type="text" class="form-control" name="auto_fee_pct" value="2.0" />
						<span class="input-group-text">% (예시)</span>
					</div>
					<div class="form-text">플랫폼/대행 정책에 따름 · 선공제와 별도일 수 있음</div>
				</div>
			</div>
			<div class="mt-6">
				<button type="button" class="btn btn-light-primary" disabled>자동 계산 규칙 저장 (준비 중)</button>
			</div>
		</div>
	</div>

	<!-- B. 주간 정산서 연동 (읽기 안내) -->
	<div class="card card-flush mb-8 border border-dashed border-gray-300">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">주간 정산서에서 반영되는 항목</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">이 화면에서 금액을 직접 쓰지 않고, 업로드 파싱 결과가 차감 내역으로 쌓입니다.</span>
			</div>
		</div>
		<div class="card-body pt-3">
			<div class="d-flex flex-column gap-3">
				<div class="d-flex align-items-center p-4 rounded bg-light">
					<i class="ki-duotone ki-shield-tick fs-2x text-primary me-4"><span class="path1"></span><span class="path2"></span></i>
					<div>
						<div class="fw-bold text-gray-900">시간제 보험</div>
						<div class="text-gray-600 fs-7">주간 정산서 열·차감내역 구조에 맞춰 등록</div>
					</div>
				</div>
				<div class="d-flex align-items-center p-4 rounded bg-light">
					<i class="ki-duotone ki-arrow-circle-left fs-2x text-success me-4"><span class="path1"></span><span class="path2"></span></i>
					<div>
						<div class="fw-bold text-gray-900">보험료 환급</div>
						<div class="text-gray-600 fs-7">환급은 가산(+)로 반영되는 경우 UI에서 구분 표시 (연동 후)</div>
					</div>
				</div>
				<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light align-self-start">정산 업로드 화면으로</a>
			</div>
		</div>
	</div>

	<!-- C. 라이더별 대여금 · 선지급 -->
	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5 gap-3 flex-wrap">
			<div class="card-title flex-grow-1">
				<h3 class="fw-bold m-0">라이더별 자동 차감 — 대여금 · 선지급 정산</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">라이더마다 사용 여부·상환 방식·금액을 다르게 둡니다. (목업 테이블 · 저장 없음)</span>
			</div>
			<div class="d-flex flex-wrap gap-2">
				<input type="search" class="form-control form-control-solid w-auto min-w-200px" name="auto_rider_q" placeholder="라이더 이름·ID 검색" />
				<button type="button" class="btn btn-sm btn-primary" disabled title="연동 후">라이더 설정 추가</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4 fs-7">
					<thead>
						<tr class="fw-bold text-muted text-nowrap">
							<th class="min-w-120px">라이더</th>
							<th class="min-w-90px text-center">대여금</th>
							<th class="min-w-110px">대여 방식</th>
							<th class="min-w-100px text-end">대여 주당</th>
							<th class="min-w-120px">대여 비고</th>
							<th class="min-w-90px text-center">선지급</th>
							<th class="min-w-110px">선지급 방식</th>
							<th class="min-w-100px text-end">선지급 주당</th>
							<th class="min-w-120px">선지급 비고</th>
							<th class="min-w-130px">차감 순서(예시)</th>
							<th class="min-w-100px text-end">작업</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockRiderAuto as $row) : ?>
						<tr>
							<td class="fw-semibold text-gray-900"><?= htmlspecialchars($row['rider'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-center">
								<?php if ($row['rental_on']) : ?>
									<span class="badge badge-light-success">사용</span>
								<?php else : ?>
									<span class="badge badge-light">미사용</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-800"><?= htmlspecialchars($row['rental_mode'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end text-gray-800"><?= $row['rental_weekly'] > 0 ? '₩ ' . number_format((int) $row['rental_weekly']) : '—' ?></td>
							<td class="text-gray-600"><?= htmlspecialchars($row['rental_note'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-center">
								<?php if ($row['advance_on']) : ?>
									<span class="badge badge-light-success">사용</span>
								<?php else : ?>
									<span class="badge badge-light">미사용</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-800"><?= htmlspecialchars($row['advance_mode'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end text-gray-800"><?= $row['advance_weekly'] > 0 ? '₩ ' . number_format((int) $row['advance_weekly']) : '—' ?></td>
							<td class="text-gray-600"><?= htmlspecialchars($row['advance_note'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['priority'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end text-nowrap">
								<button type="button" class="btn btn-sm btn-light btn-active-light-primary" disabled>수정</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="text-gray-600 fs-7 mt-6 mb-0">실제 서비스에서는 행 단위로 저장 시 해당 라이더만 갱신하고, 정산 배치 시 이 설정을 읽어 차감합니다.</p>
		</div>
	</div>

	<div class="card card-flush mb-8 bg-light">
		<div class="card-body py-6">
			<h4 class="fs-6 fw-bold text-gray-900 mb-3">설정 시 고려사항 (안내)</h4>
			<ul class="mb-0 ps-4 fs-7 text-gray-700">
				<li class="mb-2"><strong>대여금</strong>: 할부·대여 계약과 잔액은 「할부 관리」와 맞추는 것을 권장합니다.</li>
				<li class="mb-2"><strong>선지급 정산</strong>: 선지급 원금·이미 상환된 금액은 라이더별 잔액으로 추적합니다.</li>
				<li><strong>차감 순서</strong>: 동일 주차에 여러 자동 차감이 있을 때 대여·선지급 중 어느 것을 먼저 할지 라이더별 또는 팀 정책으로 정할 수 있습니다.</li>
			</ul>
		</div>
	</div>

	<div class="d-flex justify-content-end gap-3">
		<button type="button" class="btn btn-light" disabled>변경 취소</button>
		<button type="button" class="btn btn-primary" disabled>전체 저장 (준비 중)</button>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

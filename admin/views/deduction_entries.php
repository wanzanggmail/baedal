<?php

declare(strict_types=1);

/** 공제 항목 마스터 (화면 설명용) */
$deductionCategories = [
    [
        'code' => 'withholding',
        'name' => '원천세',
        'source' => '자동 계산',
        'source_class' => 'primary',
        'note' => '소득세 등 자동 산출 (연동 후)',
    ],
    [
        'code' => 'employment',
        'name' => '고용·산재',
        'source' => '자동 계산',
        'source_class' => 'primary',
        'note' => '4대보험 부담률·기준에 따름',
    ],
    [
        'code' => 'hourly_ins',
        'name' => '시간제 보험',
        'source' => '주간 정산서',
        'source_class' => 'info',
        'note' => '주간 정산서 업로드 시 반영',
    ],
    [
        'code' => 'ins_refund',
        'name' => '보험료 환급',
        'source' => '주간 정산서',
        'source_class' => 'info',
        'note' => '주간 정산서 업로드 시 반영',
    ],
    [
        'code' => 'rental',
        'name' => '대여금 차감',
        'source' => '자동 차감 설정',
        'source_class' => 'success',
        'note' => '라이더별 설정 · 할부·잔액과 연계',
    ],
    [
        'code' => 'advance',
        'name' => '선지급 정산',
        'source' => '자동 차감 설정',
        'source_class' => 'success',
        'note' => '라이더별 설정 · 선지급 잔액 상환',
    ],
    [
        'code' => 'fee',
        'name' => '정산 수수료',
        'source' => '자동 계산',
        'source_class' => 'primary',
        'note' => '플랫폼/대행 수수료율 적용',
    ],
];

$mockLines = [
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '시간제 보험', 'source' => '주간 정산서', 'amount' => -12500, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '보험료 환급', 'source' => '주간 정산서', 'amount' => 3200, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '원천세', 'source' => '자동 계산', 'amount' => -8420, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '고용·산재', 'source' => '자동 계산', 'amount' => -11800, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '정산 수수료', 'source' => '자동 계산', 'amount' => -15000, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '대여금 차감', 'source' => '자동 차감 설정', 'amount' => -50000, 'manual' => false],
    ['rider' => '권성진4418', 'week' => '2026-05-05 ~ 11', 'item' => '선지급 정산', 'source' => '자동 차감 설정', 'amount' => -20000, 'manual' => false],
    ['rider' => '민세훈3274', 'week' => '2026-05-05 ~ 11', 'item' => '기타 수동 조정', 'source' => '수동', 'amount' => -5000, 'manual' => true],
];

function fmt_money(int $n): string
{
    $sign = $n < 0 ? '' : '+';

    return $sign . '₩ ' . number_format(abs($n));
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">차감 내역 등록</h1>
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
				<li class="breadcrumb-item text-gray-900">차감 내역</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('deduction/auto'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">자동 차감 설정</a>
			<a href="<?= htmlspecialchars(admin_url('deduction/agency-fee'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">선공제(대행)</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">정산 업로드</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">수동 vs 자동</h3>
				</div>
				<div class="card-body fs-7 text-gray-700">
					<ul class="mb-0 ps-4">
						<li class="mb-2"><strong>자동 계산</strong> · 원천세, 고용·산재, 정산 수수료 — 규칙·정산 금액 기준으로 시스템 산출 (연동 후).</li>
						<li class="mb-2"><strong>주간 정산서</strong> · 시간제 보험, 보험료 환급 — 주간 정산서 업로드 시 건별·라이더별 반영.</li>
						<li class="mb-2"><strong>자동 차감 설정</strong> · 대여금 차감, 선지급 정산 — <strong>라이더(개인)별</strong>로 금액·방식·사용 여부 관리.</li>
						<li><strong>수동</strong> · 그 외 현장 조정·예외 건은 이 화면에서 등록·보정 (연동 후).</li>
					</ul>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header">
					<h3 class="card-title fw-bold m-0">공제 항목 구성</h3>
				</div>
				<div class="card-body pt-3">
					<div class="table-responsive">
						<table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-3 fs-7">
							<thead>
								<tr class="fw-bold text-muted">
									<th>항목</th>
									<th>출처</th>
									<th>비고</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($deductionCategories as $c) : ?>
								<tr>
									<td class="text-gray-800 fw-semibold"><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><span class="badge badge-light-<?= htmlspecialchars($c['source_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['source'], ENT_QUOTES, 'UTF-8') ?></span></td>
									<td class="text-gray-600"><?= htmlspecialchars($c['note'], ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">수동 등록·보정 (목업)</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">자동·주간정산서 반영분과 별도로 입력하는 행만 저장 (기능 미연동)</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="row g-4">
				<div class="col-md-3">
					<label class="form-label">라이더</label>
					<select class="form-select form-select-solid" name="manual_rider">
						<option value="">선택</option>
						<option value="1">권성진4418</option>
						<option value="2">민세훈3274</option>
						<option value="3">노동현0647</option>
					</select>
				</div>
				<div class="col-md-3">
					<label class="form-label">정산 주차</label>
					<input type="text" class="form-control form-control-solid" name="manual_week" value="2026-05-11" data-kt-flatpickr data-kt-flatpickr-week="true" autocomplete="off" />
					<div class="form-text">해당 주의 월요일 (ISO 주차 표시)</div>
				</div>
				<div class="col-md-3">
					<label class="form-label">항목</label>
					<select class="form-select form-select-solid" name="manual_item">
						<option value="other">기타 수동 조정</option>
						<option value="adjust">현장 조정</option>
						<option value="refund">환급 조정</option>
					</select>
				</div>
				<div class="col-md-3">
					<label class="form-label">금액 (차감은 음수)</label>
					<input type="text" class="form-control form-control-solid" name="manual_amount" placeholder="-5000" />
				</div>
				<div class="col-12">
					<label class="form-label">메모</label>
					<textarea class="form-control form-control-solid" name="manual_memo" rows="2" placeholder="사유 입력"></textarea>
				</div>
				<div class="col-12">
					<button type="button" class="btn btn-primary" disabled>수동 반영 (준비 중)</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold">라이더 검색</label>
					<input type="text" class="form-control form-control-solid" name="list_rider_q" placeholder="이름·ID" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">주차</label>
					<input type="text" class="form-control form-control-solid" name="list_week" value="2026-05-11" data-kt-flatpickr data-kt-flatpickr-week="true" autocomplete="off" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">출처</label>
					<select class="form-select form-select-solid" name="list_source">
						<option value="" selected>전체</option>
						<option value="auto">자동 계산</option>
						<option value="weekly">주간 정산서</option>
						<option value="autodeduct">자동 차감 설정</option>
						<option value="manual">수동</option>
					</select>
				</div>
				<div class="col-md-3 text-md-end">
					<button type="button" class="btn btn-light-primary" disabled>조회</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">차감 내역 샘플</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">한 라이더·주차 기준 합산 예시 (목업 데이터)</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-120px">라이더</th>
							<th class="min-w-140px">정산 주차</th>
							<th class="min-w-120px">항목</th>
							<th class="min-w-120px">출처</th>
							<th class="min-w-120px text-end">금액</th>
							<th class="min-w-90px">수동</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockLines as $line) : ?>
						<tr>
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($line['rider'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($line['week'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($line['item'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light"><?= htmlspecialchars($line['source'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-end fw-semibold <?= $line['amount'] < 0 ? 'text-danger' : 'text-success' ?>"><?= htmlspecialchars(fmt_money($line['amount']), ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= $line['manual'] ? '<span class="badge badge-light-warning">수동</span>' : '<span class="text-muted">—</span>' ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

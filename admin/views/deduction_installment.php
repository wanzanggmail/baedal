<?php

declare(strict_types=1);

$mockPlans = [
    ['rider' => '권성진4418', 'principal' => 1200000, 'weekly' => 50000, 'remaining' => 650000, 'weeks_left' => 13],
    ['rider' => '민세훈3274', 'principal' => 800000, 'weekly' => 40000, 'remaining' => 320000, 'weeks_left' => 8],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">할부 관리</h1>
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
				<li class="breadcrumb-item text-gray-900">할부 관리</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('deduction/auto'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">자동 차감 설정</a>
			<a href="<?= htmlspecialchars(admin_url('deduction/entries'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">차감 내역</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-info d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2x text-info me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800 mb-0">
			대여금·장비 할부는 <strong>자동 차감 설정</strong>에서 <strong>라이더별</strong>로 둔 「대여금 차감」과 연계되어 주차마다 상환됩니다. 이 화면에서는 계약·잔액을 조회·등록하는 흐름을 가정한 <strong>목업</strong>입니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">할부·대여 상환 (샘플)</h3>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light-primary" disabled>신규 등록</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th>라이더</th>
							<th class="text-end">원금</th>
							<th class="text-end">주당 상환</th>
							<th class="text-end">잔액</th>
							<th class="text-end">잔여 주</th>
							<th class="text-end">작업</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockPlans as $p) : ?>
						<tr>
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($p['rider'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">₩ <?= number_format($p['principal']) ?></td>
							<td class="text-end">₩ <?= number_format($p['weekly']) ?></td>
							<td class="text-end text-gray-800">₩ <?= number_format($p['remaining']) ?></td>
							<td class="text-end"><?= (int) $p['weeks_left'] ?>주</td>
							<td class="text-end"><button type="button" class="btn btn-sm btn-light" disabled>상세</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

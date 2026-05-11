<?php

declare(strict_types=1);

$mockCompleted = [
    ['id' => 'wd-20260508-003', 'rider_id' => 'R-440012', 'rider_name' => '최유진', 'bank' => '하나은행', 'amount' => 2100000, 'requested_at' => '2026-05-08 09:30:00', 'completed_at' => '2026-05-09 11:00:00', 'operator' => 'admin01', 'bank_ref' => 'KB-TRF-998877'],
    ['id' => 'wd-20260507-002', 'rider_id' => 'R-991877', 'rider_name' => '정우성', 'bank' => 'IBK기업은행', 'amount' => 675000, 'requested_at' => '2026-05-07 15:20:00', 'completed_at' => '2026-05-08 10:15:00', 'operator' => 'admin01', 'bank_ref' => 'SH-OUT-556644'],
    ['id' => 'wd-20260507-001', 'rider_id' => 'R-112233', 'rider_name' => '김라이더', 'bank' => '토스뱅크', 'amount' => 890000, 'requested_at' => '2026-05-07 10:00:00', 'completed_at' => '2026-05-08 10:12:00', 'operator' => 'admin02', 'bank_ref' => 'NH-BULK-112233'],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 처리 완료</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">처리 완료</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">신청 목록</a>
			<a href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">은행 파일</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-success d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-check-circle fs-2hx text-success me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 「입금 완료」 액션은 <strong>출금 신청 목록</strong> 화면에서 다운로드 완료 건마다 처리합니다. 이 페이지는 그중 일부를 샘플로 보여 줄 뿐이며, 목록에서 방금 완료한 건은 아래 표에 자동으로 붙지는 않습니다(연동 전).
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">입금 완료 내역 (샘플)</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">은행 참조번호·처리일시는 감사용</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-120px">신청 ID</th>
							<th class="min-w-100px">라이더</th>
							<th class="min-w-100px">은행</th>
							<th class="min-w-100px text-end">금액</th>
							<th class="min-w-140px">신청일시</th>
							<th class="min-w-140px">완료일시</th>
							<th class="min-w-100px">처리자</th>
							<th class="min-w-140px">은행 참조(목업)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockCompleted as $row) : ?>
						<tr>
							<td class="fw-semibold text-gray-900"><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-900 fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['rider_id'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><?= htmlspecialchars($row['bank'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold"><?= number_format((int) $row['amount']) ?>원</td>
							<td class="text-gray-700"><?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['completed_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($row['operator'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light-success"><?= htmlspecialchars($row['bank_ref'], ENT_QUOTES, 'UTF-8') ?></span></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-60 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$completed = [];
$listError = null;
try {
    $completed = Withdrawal::list([
        'status' => 'completed',
        'from'   => $filterFrom,
        'to'     => $filterTo,
        'limit'  => 200,
    ]);
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$listUrl = admin_url('withdrawal/complete');
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
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
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

	<?php if ($listError !== null) : ?>
	<div class="alert alert-danger p-5 mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php endif; ?>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="withdrawal/complete" />
				<?php endif; ?>
				<div class="col-md-4">
					<label class="form-label fw-semibold">완료일 기간</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly />
						<input type="hidden" name="from" data-kt-daterange-from value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
						<input type="hidden" name="to" data-kt-daterange-to value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
				</div>
				<div class="col-md-2">
					<button type="submit" class="btn btn-light-primary w-100">조회</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">입금 완료 내역</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1"><?= number_format(count($completed)) ?>건</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th>신청 ID</th>
							<th>라이더</th>
							<th>유형</th>
							<th>은행</th>
							<th class="text-end">금액</th>
							<th>신청일시</th>
							<th>완료일시</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($completed === []) : ?>
						<tr><td colspan="7" class="text-center text-muted py-10">내역이 없습니다.</td></tr>
						<?php else : ?>
						<?php foreach ($completed as $row) : ?>
						<tr>
							<td class="fw-semibold text-gray-900"><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-900 fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['rider_id'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><span class="badge badge-light fs-8"><?= htmlspecialchars($row['kind_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td><?= htmlspecialchars($row['bank'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold"><?= number_format((int) $row['amount']) ?>원</td>
							<td class="text-gray-700"><?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['completed_at'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

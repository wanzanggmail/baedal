<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$cycleId = (int) ($_GET['cycle'] ?? 0);
$listUrl = admin_url('settlement/fees');

if ($cycleId < 1 || !SettlementLedger::tableExists()) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning m-0">내역을 찾을 수 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '">목록</a></div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$cycle = SettlementLedger::find($cycleId);
if ($cycle === null) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning m-0">내역이 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$fees = $cycle['fees'] ?? [];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산 수수료 상세</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">수수료 내역</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900"><?= htmlspecialchars($cycle['settlement_date'], ENT_QUOTES, 'UTF-8') ?></li>
			</ul>
		</div>
		<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">목록</a>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="card card-flush shadow-sm mb-5">
		<div class="card-body">
			<div class="fs-7 text-gray-500 mb-1">
				<?= htmlspecialchars($cycle['settlement_date'], ENT_QUOTES, 'UTF-8') ?>
				· <?= htmlspecialchars($cycle['platform_label'], ENT_QUOTES, 'UTF-8') ?>
				· <?= htmlspecialchars($cycle['rider_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($cycle['rider_code'], ENT_QUOTES, 'UTF-8') ?>)
			</div>
			<div class="fs-2 fw-bold text-gray-900">지갑 반영 <?= number_format((int) $cycle['net_amount']) ?>원</div>
			<span class="badge badge-light-success">정산 완료</span>
			<?php if ($cycle['completed_at'] !== '') : ?>
			<span class="text-muted fs-8 ms-2"><?= htmlspecialchars($cycle['completed_at'], ENT_QUOTES, 'UTF-8') ?></span>
			<?php endif; ?>
		</div>
	</div>

	<div class="card card-flush shadow-sm">
		<div class="card-header border-0 pt-5"><h3 class="card-title fw-bold fs-5">수수료·차감 내역</h3></div>
		<div class="card-body pt-0 fs-7">
			<div class="d-flex justify-content-between py-3 border-bottom">
				<span>정산금액</span>
				<span class="fw-semibold"><?= number_format((int) $cycle['gross_amount']) ?>원</span>
			</div>
			<?php if ((int) ($cycle['support_amount'] ?? 0) > 0) : ?>
			<div class="d-flex justify-content-between py-3 border-bottom">
				<span>지원금(+추가지원금)</span>
				<span class="fw-semibold text-success">+ <?= number_format((int) $cycle['support_amount']) ?>원</span>
			</div>
			<?php endif; ?>
			<?php foreach ($fees as $fee) : ?>
			<div class="d-flex justify-content-between py-3 border-bottom">
				<span><?= htmlspecialchars($fee['label'], ENT_QUOTES, 'UTF-8') ?></span>
				<span class="fw-semibold text-danger">− <?= number_format((int) $fee['amount']) ?>원</span>
			</div>
			<?php endforeach; ?>
			<?php if ($fees === []) : ?>
			<div class="py-4 text-muted">차감 항목 없음</div>
			<?php endif; ?>
			<div class="d-flex justify-content-between py-3 border-bottom">
				<span>수수료 합계</span>
				<span class="fw-semibold text-danger">− <?= number_format((int) $cycle['total_fee_amount']) ?>원</span>
			</div>
			<div class="d-flex justify-content-between py-3">
				<span class="fw-bold">지갑 적립액</span>
				<span class="fw-bold text-primary"><?= number_format((int) $cycle['net_amount']) ?>원</span>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

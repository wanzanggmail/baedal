<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
$filterQ    = trim((string) ($_GET['q'] ?? ''));

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$listError = null;
$rows      = [];
$needsMigrate = !SettlementLedger::tableExists();

if (!$needsMigrate) {
    try {
        $rows = SettlementLedger::listAdmin([
            'from'  => $filterFrom,
            'to'    => $filterTo,
            'q'     => $filterQ,
            'limit' => 300,
        ]);
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

$listUrl = admin_url('settlement/fees');
$detailBase = admin_url('settlement/fee-detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산 수수료 내역</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">엑셀 업로드</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">업로드 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex p-5 mb-8">
		<div class="fs-7 text-gray-800">
			<strong>정산 반영</strong> 후 라이더별 수수료·실지급 내역입니다. 업로드 상세에서 「정산 반영 · 수수료·지갑」 실행 시 생성됩니다.
		</div>
	</div>
	<?php endif; ?>

	<?php if (!$needsMigrate && $listError === null) : ?>
	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/fees" />
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">정산일 from</label>
					<input type="date" class="form-control form-control-solid" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">to</label>
					<input type="date" class="form-control form-control-solid" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">라이더 검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="이름, 코드" />
				</div>
				<div class="col-md-2"><button type="submit" class="btn btn-light-primary w-100">조회</button></div>
			</form>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-4">
					<thead>
						<tr class="fw-bold text-muted fs-7">
							<th>정산일</th>
							<th>라이더</th>
							<th>플랫폼</th>
							<th class="text-end">플랫폼 지급</th>
							<th class="text-end">수수료 합</th>
							<th class="text-end">지갑 반영</th>
							<th class="text-end">상세</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr><td colspan="7" class="text-center text-muted py-10">내역이 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($rows as $row) : ?>
						<tr>
							<td><?= htmlspecialchars($row['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['rider_code'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><?= htmlspecialchars($row['platform_label'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end"><?= number_format((int) $row['platform_payout']) ?>원</td>
							<td class="text-end text-danger">−<?= number_format((int) $row['total_fee_amount']) ?>원</td>
							<td class="text-end fw-bold"><?= number_format((int) $row['net_amount']) ?>원</td>
							<td class="text-end">
								<a href="<?= htmlspecialchars($detailBase . 'cycle=' . (int) $row['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">내역</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

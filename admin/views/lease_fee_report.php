<?php

declare(strict_types=1);

/**
 * 리스 수수료 배분 리포트 (§5.5)
 *
 * 라이더에게서 걷은 리스료가 본사·총판·대리점에 각각 얼마씩 배분됐는지 집계한다.
 * 금액은 차감 시점에 `rider_debt_entries`에 박아둔 **스냅샷**이라, 이후 계약 설정이
 * 바뀌어도 과거 실적은 그대로 유지된다.
 */

require_once INC_PATH . '/RiderDebt.php';
require_once INC_PATH . '/Org.php';

// 본사 전용(2026-08-12 갑 확정). 라우트 가드가 이미 막지만, 직접 include 되는 경로가 생겨도
// 총판·대리점에 배분 내역이 노출되지 않도록 화면에서도 한 번 더 차단한다.
if (admin_org_level() !== Org::LEVEL_ADMIN) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">리스 수수료 배분은 본사만 조회할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$filterFrom   = trim((string) ($_GET['from'] ?? ''));
$filterTo     = trim((string) ($_GET['to'] ?? ''));
$filterAgency = (int) ($_GET['agency'] ?? 0);

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-01');
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$needsMigrate = !RiderDebt::tableReady();
$filters = ['from' => $filterFrom, 'to' => $filterTo, 'agency_id' => $filterAgency];

$sum  = $needsMigrate ? ['total' => 0, 'hq' => 0, 'distributor' => 0, 'agency' => 0, 'count' => 0, 'days' => 0] : RiderDebt::feeSummary($filters);
$rows = $needsMigrate ? [] : RiderDebt::feeRows($filters);

// 대리점 선택 목록 — 스코프 안에서만
$agencyOptions = [];
if (!$needsMigrate) {
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('id');
    $agencyOptions = db_rows(
        "SELECT id, name FROM organizations
          WHERE level = 'agency'" . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . '
          ORDER BY name ASC',
        $scopeParams
    );
}

$won = static fn ($n): string => number_format((int) $n) . '원';
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$currentUrl = admin_url('deduction/lease-fees');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">리스 수수료 배분</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">선공제(대행)</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">리스 수수료 배분</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<a href="<?= $esc(admin_url('deduction/debts')) ?>" class="btn btn-sm btn-light fw-bold">미수금 원장</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning p-5">미수금 원장 테이블이 아직 없습니다. 서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert bg-light-primary d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			라이더에게서 걷은 <strong>리스료</strong>가 각 조직에 얼마씩 배분됐는지 보여줍니다. 리스를 <strong>누가 제공했느냐</strong>에 따라
			본사·총판·대리점이 나눠 갖고, 금액은 계약마다 설정한 <strong>일 단위 정액 × 차감일수</strong>입니다.
			<span class="text-gray-600">배분 시점에 기록된 값이라 이후 계약 설정을 바꿔도 과거 실적은 변하지 않습니다.</span>
		</div>
	</div>

	<!--begin::KPI-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100 border border-primary border-dashed"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">걷은 리스료 합계</div>
				<div class="fw-bold fs-2 text-gray-900"><?= $won($sum['total']) ?></div>
				<div class="text-gray-600 fs-8"><?= number_format($sum['count']) ?>건 · <?= number_format($sum['days']) ?>일</div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">본사 몫</div>
				<div class="fw-bold fs-2 text-dark"><?= $won($sum['hq']) ?></div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">총판 몫</div>
				<div class="fw-bold fs-2 text-info"><?= $won($sum['distributor']) ?></div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">대리점 몫</div>
				<div class="fw-bold fs-2 text-success"><?= $won($sum['agency']) ?></div>
			</div></div>
		</div>
	</div>
	<!--end::KPI-->

	<?php
	$distributed = (int) $sum['hq'] + (int) $sum['distributor'] + (int) $sum['agency'];
	$outside     = (int) $sum['total'] - $distributed;
	?>
	<?php if ($sum['total'] > 0) : ?>
	<div class="card card-flush mb-8"><div class="card-body py-5 fs-7">
		<div class="d-flex flex-wrap gap-4 align-items-center">
			<span class="text-gray-600">배분 합계 <strong class="text-gray-900"><?= $won($distributed) ?></strong></span>
			<span class="text-gray-400">/</span>
			<span class="text-gray-600">걷은 금액 <strong class="text-gray-900"><?= $won($sum['total']) ?></strong></span>
			<?php if ($outside > 0) : ?>
			<span class="badge badge-light-warning">미배분 <?= $won($outside) ?> — 리스사 등 외부 지급분</span>
			<?php else : ?>
			<span class="badge badge-light-success">전액 배분</span>
			<?php endif; ?>
		</div>
	</div></div>
	<?php endif; ?>

	<!--begin::Filter-->
	<form method="get" action="<?= $esc($currentUrl) ?>">
		<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
		<input type="hidden" name="route" value="deduction/lease-fees" />
		<?php endif; ?>
		<div class="card card-flush mb-8"><div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold">차감 귀속일 시작</label>
					<input type="date" class="form-control form-control-solid" name="from" value="<?= $esc($filterFrom) ?>" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">종료</label>
					<input type="date" class="form-control form-control-solid" name="to" value="<?= $esc($filterTo) ?>" />
				</div>
				<?php if ($agencyOptions !== []) : ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">대리점</label>
					<select class="form-select form-select-solid" name="agency" data-control="select2" data-placeholder="전체">
						<option value="">전체</option>
						<?php foreach ($agencyOptions as $ao) : ?>
						<option value="<?= (int) $ao['id'] ?>" <?= $filterAgency === (int) $ao['id'] ? 'selected' : '' ?>><?= $esc((string) $ao['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-md-3 d-flex gap-2 justify-content-md-end">
					<button type="submit" class="btn btn-primary">조회</button>
					<a href="<?= $esc($currentUrl) ?>" class="btn btn-light">초기화</a>
				</div>
			</div>
		</div></div>
	</form>
	<!--end::Filter-->

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold m-0">배분 상세 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format(count($rows)) ?>건</span></h3>
		</div>
		<div class="card-body pt-2">
			<?php if ($rows === []) : ?>
			<div class="text-center text-gray-500 py-10">선택한 기간에 배분된 리스 수수료가 없습니다.</div>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gy-3 fs-7">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-100px">귀속일</th>
							<th class="min-w-130px">라이더</th>
							<th class="min-w-110px">대리점</th>
							<th class="min-w-150px">계약 · 차대번호</th>
							<th class="text-center min-w-60px">일수</th>
							<th class="text-end min-w-90px">걷은 금액</th>
							<th class="text-end min-w-90px">본사</th>
							<th class="text-end min-w-90px">총판</th>
							<th class="text-end min-w-90px">대리점</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) : ?>
						<tr>
							<td class="text-gray-700"><?= $esc((string) $r['applied_date']) ?></td>
							<td>
								<span class="fw-bold text-gray-900"><?= $esc((string) $r['rider_name']) ?></span>
								<div class="text-muted fs-8"><?= $esc(rider_phone_hint((string) ($r['rider_phone'] ?? ''))) ?></div>
							</td>
							<td class="text-gray-700"><?= $esc((string) ($r['agency_name'] ?: '—')) ?></td>
							<td>
								<span class="badge badge-light-dark fs-9"><?= $esc(RiderDebt::providerLabel((string) $r['lease_provider'])) ?> 제공</span>
								<div class="text-gray-800"><?= $esc((string) ($r['title'] ?: '—')) ?></div>
								<?php if ((string) ($r['vin'] ?? '') !== '') : ?>
								<div class="text-muted fs-8 font-monospace">VIN <?= $esc((string) $r['vin']) ?></div>
								<?php endif; ?>
							</td>
							<td class="text-center text-gray-700"><?= (int) $r['days'] ?></td>
							<td class="text-end fw-bold text-gray-900"><?= $won($r['amount']) ?></td>
							<td class="text-end <?= (int) $r['fee_hq'] > 0 ? 'text-dark fw-semibold' : 'text-muted' ?>"><?= (int) $r['fee_hq'] > 0 ? $won($r['fee_hq']) : '—' ?></td>
							<td class="text-end <?= (int) $r['fee_distributor'] > 0 ? 'text-info fw-semibold' : 'text-muted' ?>"><?= (int) $r['fee_distributor'] > 0 ? $won($r['fee_distributor']) : '—' ?></td>
							<td class="text-end <?= (int) $r['fee_agency'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>"><?= (int) $r['fee_agency'] > 0 ? $won($r['fee_agency']) : '—' ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr class="fw-bold text-gray-900 border-top">
							<td colspan="5">합계</td>
							<td class="text-end"><?= $won($sum['total']) ?></td>
							<td class="text-end text-dark"><?= $won($sum['hq']) ?></td>
							<td class="text-end text-info"><?= $won($sum['distributor']) ?></td>
							<td class="text-end text-success"><?= $won($sum['agency']) ?></td>
						</tr>
					</tfoot>
				</table>
			</div>
			<?php endif; ?>
			<div class="text-muted fs-8 mt-3">
				본사·총판 몫은 배분 시점에 <strong>대리점 지갑에서 각 조직 지갑으로 실제 이체</strong>됩니다(대리점 몫은 이미 대리점에 남아 있어 이동하지 않습니다).
				차감을 취소하면 이 이동도 함께 되돌아갑니다.
			</div>
		</div>
	</div>

	<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

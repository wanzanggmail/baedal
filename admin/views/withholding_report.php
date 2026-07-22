<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyWallet.php';

$needsMigrate = !db_table_exists('settlement_fee_items') || !db_table_exists('rider_wallets');

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
$validTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';

$rows = [];
$reserveTotal = 0;
if (!$needsMigrate) {
    $where  = ['r.withholding_tax_enabled = 1'];
    $params = [];

    // 정산 사이클 날짜 범위 (원천세 항목 join)
    $cycleCond  = "c.rider_id = r.id";
    if ($validFrom !== '') { $cycleCond .= ' AND c.settlement_date >= ?'; }
    if ($validTo   !== '') { $cycleCond .= ' AND c.settlement_date <= ?'; }

    // 멀티테넌시 스코프
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    if ($scopeSql !== '') { $where[] = $scopeSql; }

    // 파라미터 순서: cycle join(from,to) → where scope
    $joinParams = [];
    if ($validFrom !== '') { $joinParams[] = $validFrom; }
    if ($validTo   !== '') { $joinParams[] = $validTo; }
    $params = array_merge($joinParams, $scopeParams);

    $whereStr = implode(' AND ', $where);
    $rows = db_rows(
        "SELECT r.id, r.rider_code, r.name, r.agency_id, o.name AS agency_name,
                COUNT(fi.id) AS tax_count,
                COALESCE(SUM(fi.amount), 0) AS tax_total
           FROM riders r
           LEFT JOIN organizations o ON o.id = r.agency_id
           LEFT JOIN settlement_rider_cycles c ON {$cycleCond}
           LEFT JOIN settlement_fee_items fi ON fi.cycle_id = c.id AND fi.fee_code = 'withholding'
          WHERE {$whereStr}
          GROUP BY r.id, r.rider_code, r.name, r.agency_id, o.name
          ORDER BY tax_total DESC, r.name ASC",
        $params
    );

    if ($isAgency) {
        $reserveTotal = (int) AgencyWallet::get(admin_org_id())['withholding_reserve'];
    }
}
$grandTotal = array_sum(array_map(static fn ($r) => (int) $r['tax_total'], $rows));
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">원천세 대상자 명세</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">원천세 명세</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert bg-light-info d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-document fs-2hx text-info me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">원천세(3.3% 고정) <strong>공제 대상 라이더</strong>의 공제 누계입니다. 대리점이 국세청에 <strong>신고·납부</strong>할 때 사용하세요. 대상 지정은 라이더 상세화면에서 설정합니다.</div>
	</div>

	<form method="get" class="d-flex flex-wrap gap-3 align-items-end mb-6">
		<input type="hidden" name="route" value="settlement/withholding" />
		<div>
			<label class="form-label fs-8 text-muted">시작일(정산일)</label>
			<input type="date" name="from" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8') ?>" />
		</div>
		<div>
			<label class="form-label fs-8 text-muted">종료일</label>
			<input type="date" name="to" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8') ?>" />
		</div>
		<button type="submit" class="btn btn-sm btn-primary">조회</button>
	</form>

	<div class="row g-6 mb-6">
		<div class="col-sm-4">
			<div class="card card-flush bg-light-primary"><div class="card-body py-4">
				<div class="fs-7 text-muted">대상 라이더</div>
				<div class="fs-2 fw-bold text-gray-900"><?= count($rows) ?>명</div>
			</div></div>
		</div>
		<div class="col-sm-4">
			<div class="card card-flush bg-light-warning"><div class="card-body py-4">
				<div class="fs-7 text-muted">기간 원천세 합계</div>
				<div class="fs-2 fw-bold text-gray-900"><?= number_format($grandTotal) ?>원</div>
			</div></div>
		</div>
		<?php if ($isAgency) : ?>
		<div class="col-sm-4">
			<div class="card card-flush bg-light-success"><div class="card-body py-4">
				<div class="fs-7 text-muted">누적 예수금(미납부)</div>
				<div class="fs-2 fw-bold text-gray-900"><?= number_format($reserveTotal) ?>원</div>
			</div></div>
		</div>
		<?php endif; ?>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">대상자별 원천세 공제 내역</h3></div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<?php if (!$isAgency) : ?><th>대리점</th><?php endif; ?>
							<th>라이더</th>
							<th class="text-center">공제 건수</th>
							<th class="text-end">원천세 합계</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr><td colspan="4" class="text-center text-muted py-6">원천세 대상 라이더가 없습니다.</td></tr>
						<?php else : foreach ($rows as $r) : ?>
						<tr>
							<?php if (!$isAgency) : ?><td><?= htmlspecialchars((string) $r['agency_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
							<td><span class="fw-bold"><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></span> <span class="text-muted">(<?= htmlspecialchars((string) $r['rider_code'], ENT_QUOTES, 'UTF-8') ?>)</span></td>
							<td class="text-center"><?= (int) $r['tax_count'] ?>건</td>
							<td class="text-end fw-bold"><?= number_format((int) $r['tax_total']) ?>원</td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

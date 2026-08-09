<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyWallet.php';

$needsMigrate = !db_table_exists('settlement_fee_items') || !db_table_exists('rider_wallets');

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));

// 기본 기간 = 이번 달 1일 ~ 말일. 원천세는 월 단위로 신고·납부하므로 월이 기본 단위다.
$validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
$validTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : date('Y-m-t');

$rows = [];
$detailByRider = [];
$reserveTotal = 0;
if (!$needsMigrate) {
    $where  = ['r.withholding_tax_enabled = 1'];

    // 정산 사이클 날짜 범위 (원천세 항목 join)
    $cycleCond = 'c.rider_id = r.id AND c.settlement_date >= ? AND c.settlement_date <= ?';

    // 멀티테넌시 스코프
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
    if ($scopeSql !== '') { $where[] = $scopeSql; }

    // 파라미터 순서: cycle join(from,to) → where scope
    $params = array_merge([$validFrom, $validTo], $scopeParams);

    $whereStr = implode(' AND ', $where);
    $rows = db_rows(
        "SELECT r.id, r.rider_code, r.name, r.agency_id, o.name AS agency_name,
                COUNT(fi.id) AS tax_count,
                COALESCE(SUM(fi.amount), 0) AS tax_total,
                COALESCE(SUM(c.platform_payout + c.support_amount), 0) AS base_total
           FROM riders r
           LEFT JOIN organizations o ON o.id = r.agency_id
           LEFT JOIN settlement_rider_cycles c ON {$cycleCond}
           LEFT JOIN settlement_fee_items fi ON fi.cycle_id = c.id AND fi.fee_code = 'withholding'
          WHERE {$whereStr}
          GROUP BY r.id, r.rider_code, r.name, r.agency_id, o.name
          ORDER BY tax_total DESC, r.name ASC",
        $params
    );

    // 라이더별 상세(정산일 단위) — 신고 시 근거 자료로 쓰인다.
    // ⚠️ 위 집계는 LEFT JOIN이라 0원 대상자도 나오지만, 상세는 실제 공제분만 담는다.
    $detailWhere  = ['r.withholding_tax_enabled = 1'];
    if ($scopeSql !== '') { $detailWhere[] = $scopeSql; }
    $detailRows = db_rows(
        'SELECT c.rider_id, c.settlement_date, c.platform, c.team_region,
                c.platform_payout, c.support_amount, c.net_amount,
                fi.amount AS tax_amount
           FROM settlement_rider_cycles c
           INNER JOIN settlement_fee_items fi ON fi.cycle_id = c.id AND fi.fee_code = \'withholding\'
           INNER JOIN riders r ON r.id = c.rider_id
          WHERE ' . implode(' AND ', $detailWhere) . '
            AND c.settlement_date >= ? AND c.settlement_date <= ?
          ORDER BY c.rider_id ASC, c.settlement_date ASC, c.id ASC',
        array_merge($scopeParams, [$validFrom, $validTo])
    );
    foreach ($detailRows as $d) {
        $detailByRider[(int) $d['rider_id']][] = $d;
    }

    if ($isAgency) {
        $reserveTotal = (int) AgencyWallet::get(admin_org_id())['withholding_reserve'];
    }
}
$grandTotal = array_sum(array_map(static fn ($r) => (int) $r['tax_total'], $rows));
$baseGrandTotal = array_sum(array_map(static fn ($r) => (int) $r['base_total'], $rows));
$payingCount = count(array_filter($rows, static fn ($r): bool => (int) $r['tax_total'] > 0));

$platformShort = ['baemin' => '배민', 'coupang' => '쿠팡', 'other' => '기타'];

// 기간 빠른 선택
$whUrl = admin_url('settlement/withholding');
$whRangeUrl = static function (string $f, string $t) use ($whUrl): string {
    $sep = str_contains($whUrl, '?') ? '&' : '?';
    return $whUrl . $sep . http_build_query(['from' => $f, 'to' => $t]);
};
$quickRanges = [
    '이번 달'   => [date('Y-m-01'), date('Y-m-t')],
    '지난 달'   => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '올해'      => [date('Y-01-01'), date('Y-12-31')],
];
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

	<form method="get" class="d-flex flex-wrap gap-3 align-items-end mb-3">
		<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
		<input type="hidden" name="route" value="settlement/withholding" />
		<?php endif; ?>
		<div>
			<label class="form-label fs-8 text-muted">시작일(정산일)</label>
			<input type="date" name="from" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8') ?>" />
		</div>
		<div>
			<label class="form-label fs-8 text-muted">종료일</label>
			<input type="date" name="to" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8') ?>" />
		</div>
		<button type="submit" class="btn btn-sm btn-primary">조회</button>
		<div class="d-flex flex-wrap gap-1">
			<?php foreach ($quickRanges as $label => [$qf, $qt]) :
			    $active = $validFrom === $qf && $validTo === $qt; ?>
			<a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>" href="<?= htmlspecialchars($whRangeUrl($qf, $qt), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
			<?php endforeach; ?>
		</div>
	</form>
	<div class="text-muted fs-8 mb-6">
		조회 기간 <strong><?= htmlspecialchars($validFrom, ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($validTo, ENT_QUOTES, 'UTF-8') ?></strong>
		(기본값은 이번 달 1일~말일입니다)
	</div>

	<div class="row g-6 mb-6">
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush bg-light-primary"><div class="card-body py-4">
				<div class="fs-7 text-muted">대상 라이더</div>
				<div class="fs-2 fw-bold text-gray-900"><?= $payingCount ?>명<span class="fs-7 text-muted fw-normal"> / <?= count($rows) ?>명</span></div>
				<div class="fs-8 text-muted mt-1">기간 내 공제발생 / 전체 대상</div>
			</div></div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush bg-light-info"><div class="card-body py-4">
				<div class="fs-7 text-muted">과세표준 합계</div>
				<div class="fs-2 fw-bold text-gray-900"><?= number_format($baseGrandTotal) ?>원</div>
				<div class="fs-8 text-muted mt-1">실지급 + 지원금</div>
			</div></div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush bg-light-warning"><div class="card-body py-4">
				<div class="fs-7 text-muted">기간 원천세 합계</div>
				<div class="fs-2 fw-bold text-gray-900"><?= number_format($grandTotal) ?>원</div>
				<div class="fs-8 text-muted mt-1">3.3% 고정</div>
			</div></div>
		</div>
		<?php if ($isAgency) : ?>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush bg-light-success"><div class="card-body py-4">
				<div class="fs-7 text-muted">누적 예수금(미납부)</div>
				<div class="fs-2 fw-bold text-gray-900"><?= number_format($reserveTotal) ?>원</div>
				<div class="fs-8 text-muted mt-1">기간과 무관한 전체 누계</div>
			</div></div>
		</div>
		<?php endif; ?>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">대상자별 원천세 공제 내역</h3></div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<?php $colCount = $isAgency ? 5 : 6; ?>
					<thead>
						<tr class="fw-bold text-muted">
							<?php if (!$isAgency) : ?><th>대리점</th><?php endif; ?>
							<th>라이더</th>
							<th class="text-center">공제 건수</th>
							<th class="text-end">과세표준</th>
							<th class="text-end">원천세 합계</th>
							<th class="text-end">상세</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr><td colspan="<?= $colCount ?>" class="text-center text-muted py-6">원천세 대상 라이더가 없습니다.</td></tr>
						<?php else : foreach ($rows as $r) :
						    $rid     = (int) $r['id'];
						    $details = $detailByRider[$rid] ?? [];
						    ?>
						<tr>
							<?php if (!$isAgency) : ?><td><?= htmlspecialchars((string) $r['agency_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
							<td><span class="fw-bold"><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></span> <span class="text-muted">(<?= htmlspecialchars((string) $r['rider_code'], ENT_QUOTES, 'UTF-8') ?>)</span></td>
							<td class="text-center"><?= (int) $r['tax_count'] ?>건</td>
							<td class="text-end text-gray-700"><?= number_format((int) $r['base_total']) ?>원</td>
							<td class="text-end fw-bold"><?= number_format((int) $r['tax_total']) ?>원</td>
							<td class="text-end">
								<?php if ($details !== []) : ?>
								<button type="button" class="btn btn-sm btn-light-primary py-1 px-3 fs-8 wh-toggle" data-rider="<?= $rid ?>">내역 <?= count($details) ?>건</button>
								<?php else : ?>
								<span class="text-muted fs-8">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ($details !== []) : ?>
						<tr class="wh-detail d-none" data-rider="<?= $rid ?>">
							<td colspan="<?= $colCount ?>" class="bg-light-primary py-3">
								<div class="fw-bold fs-8 text-gray-700 mb-2">
									<?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?> · 정산일별 원천세 내역
								</div>
								<table class="table table-row-bordered align-middle fs-8 gy-2 mb-0 bg-body">
									<thead>
										<tr class="fw-bold text-muted">
											<th>정산일</th>
											<th>플랫폼</th>
											<th>팀지역</th>
											<th class="text-end">실지급</th>
											<th class="text-end">지원금</th>
											<th class="text-end">과세표준</th>
											<th class="text-end">원천세(3.3%)</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($details as $d) :
										    $dBase = (int) $d['platform_payout'] + (int) $d['support_amount']; ?>
										<tr>
											<td class="text-gray-800"><?= htmlspecialchars(substr((string) $d['settlement_date'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-muted"><?= htmlspecialchars($platformShort[(string) $d['platform']] ?? (string) $d['platform'], ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-muted"><?= htmlspecialchars((string) ($d['team_region'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-end text-muted"><?= number_format((int) $d['platform_payout']) ?>원</td>
											<td class="text-end text-muted"><?= (int) $d['support_amount'] > 0 ? '+' . number_format((int) $d['support_amount']) . '원' : '—' ?></td>
											<td class="text-end"><?= number_format($dBase) ?>원</td>
											<td class="text-end fw-bold text-gray-900"><?= number_format((int) $d['tax_amount']) ?>원</td>
										</tr>
										<?php endforeach; ?>
									</tbody>
									<tfoot>
										<tr class="fw-bold bg-light">
											<td colspan="5" class="text-end">합계</td>
											<td class="text-end"><?= number_format((int) $r['base_total']) ?>원</td>
											<td class="text-end"><?= number_format((int) $r['tax_total']) ?>원</td>
										</tr>
									</tfoot>
								</table>
							</td>
						</tr>
						<?php endif; ?>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		'use strict';
		document.querySelectorAll('.wh-toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var rid = btn.getAttribute('data-rider');
				var row = document.querySelector('.wh-detail[data-rider="' + rid + '"]');
				if (!row) return;
				var hidden = row.classList.toggle('d-none');
				btn.classList.toggle('btn-light-primary', hidden);
				btn.classList.toggle('btn-primary', !hidden);
			});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

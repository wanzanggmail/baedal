<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderDebt.php';
require_once INC_PATH . '/Org.php';

$debtApi  = ADMIN_BASE . '/api/debt_action.php';
$riderApi = ADMIN_BASE . '/api/riders.php';
$canWrite = admin_can_write('deduction');
$needsMigrate = !RiderDebt::tableReady();

$filterKind   = trim((string) ($_GET['kind']   ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterQ      = trim((string) ($_GET['q']      ?? ''));
$filterDist   = (int) ($_GET['distributor'] ?? 0);
$filterAgency = (int) ($_GET['agency'] ?? 0);
// 대리점 계정은 어차피 자기 대리점 하나뿐이라 총판·대리점 필터가 의미가 없다.
// 게다가 총판 옵션이 0개라 "총판을 먼저 선택하세요"에 걸려 대리점 칸이 **영구히 잠긴다**
// (실제로 비어 보이던 원인). 아예 감추고 라이더 검색만 남긴다.
$isAgencyLevel = admin_org_level() === Org::LEVEL_AGENCY;

// 라이더 선택 필터(select2) — 선택된 라이더는 새로고침 후에도 이름이 보이도록 미리 읽어둔다.
$filterRiderId   = (int) ($_GET['rider_id'] ?? 0);
$filterRiderName = '';
if ($filterRiderId > 0) {
    $r = db_row('SELECT name, phone FROM riders WHERE id = ?', [$filterRiderId]);
    if ($r !== null) {
        $hint = rider_phone_hint((string) $r['phone']);
        $filterRiderName = (string) $r['name'] . ($hint !== '' ? ' (' . $hint . ')' : '');
    }
}

$rows = [];
$kpi  = ['active' => 0, 'balance' => 0, 'lease_daily' => 0, 'closed' => 0, 'lease_no_end' => 0, 'lease_overdue' => 0];
// 회수 집계 — 마이그레이션 전이면 빈 값으로 렌더된다.
$recSettle = [];
$recLedger = [];
$recFrom   = date('Y-m-01');
$recTo     = date('Y-m-d');

// 총판·대리점 선택 목록 — 현재 계정 스코프 안에서만(총판 계정=자기, 대리점 계정=자기뿐이라 사실상 선택 불필요)
[$orgScopeSql, $orgScopeParams] = Org::orgScopeClause('id');
$distributorOptions = $needsMigrate ? [] : db_rows(
    "SELECT id, name FROM organizations WHERE level = 'distributor'" . ($orgScopeSql !== '' ? " AND {$orgScopeSql}" : '') . ' ORDER BY name ASC',
    $orgScopeParams
);
$agencyOptions = $needsMigrate ? [] : db_rows(
    "SELECT id, name, parent_id FROM organizations WHERE level = 'agency'" . ($orgScopeSql !== '' ? " AND {$orgScopeSql}" : '') . ' ORDER BY name ASC',
    $orgScopeParams
);

if (!$needsMigrate) {
    [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');

    $where  = ['1=1'];
    $params = [];
    if ($scopeSql !== '') {
        $where[] = $scopeSql;
        $params  = array_merge($params, $scopeParams);
    }
    if (isset(RiderDebt::KINDS[$filterKind])) { $where[] = 'd.kind = ?';   $params[] = $filterKind; }
    if (in_array($filterStatus, ['active', 'paused', 'closed'], true)) { $where[] = 'd.status = ?'; $params[] = $filterStatus; }
    if ($filterAgency > 0) {
        $where[] = 'r.agency_id = ?';
        $params[] = $filterAgency;
    } elseif ($filterDist > 0) {
        $subAgencyIds = Org::subtreeAgencyIds($filterDist);
        $where[] = $subAgencyIds !== [] ? 'r.agency_id IN (' . implode(',', array_fill(0, count($subAgencyIds), '?')) . ')' : '1=0';
        $params = array_merge($params, $subAgencyIds);
    }
    // 라이더는 select2로 정확히 지목하고, 자유검색(q)은 항목명·채권자만 본다.
    // (라이더명을 q에도 남겨두면 "라이더 선택 + 검색어"가 서로 어긋날 때 결과가 헷갈린다)
    if ($filterRiderId > 0) {
        $where[]  = 'd.rider_id = ?';
        $params[] = $filterRiderId;
    }
    if ($filterQ !== '') {
        $like    = '%' . $filterQ . '%';
        $where[] = '(d.title LIKE ? OR d.creditor LIKE ?)';
        $params  = array_merge($params, [$like, $like]);
    }
    $whereStr = implode(' AND ', $where);

    $rows = db_rows(
        "SELECT d.*, r.id AS rider_id, r.name AS rider_name, r.phone AS rider_phone, o.name AS agency_name,
                (SELECT COUNT(*) FROM rider_debt_entries e WHERE e.debt_id = d.id) AS entry_count
           FROM rider_debts d
           INNER JOIN riders r ON r.id = d.rider_id
           LEFT JOIN organizations o ON o.id = r.agency_id
          WHERE {$whereStr}
          ORDER BY (d.status = 'active') DESC, d.id DESC
          LIMIT 300",
        $params
    );

    // KPI — 필터와 무관하게 스코프 전체 기준
    $kWhere  = $scopeSql !== '' ? ' AND ' . $scopeSql : '';
    $k = db_row(
        "SELECT
            SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) AS active_cnt,
            SUM(CASE WHEN d.status = 'active' THEN d.balance_amount ELSE 0 END) AS balance_sum,
            SUM(CASE WHEN d.status = 'active' AND d.kind = 'lease' THEN d.daily_amount ELSE 0 END) AS lease_daily,
            SUM(CASE WHEN d.status = 'closed' THEN 1 ELSE 0 END) AS closed_cnt,
            SUM(CASE WHEN d.status = 'active' AND d.kind = 'lease' AND (d.opened_on IS NULL OR d.planned_end_on IS NULL) THEN 1 ELSE 0 END) AS lease_no_end,
            -- 차감 밀림: 리스뿐 아니라 대여금·선지급금도 달력일로 부과되므로 함께 센다(2026-09-04).
            --   · 종료예정일이 없는 건(대여금·선지급금)은 오늘까지가 커버 대상
            --   · 상각형은 잔액이 남아 있는 건만 해당
            SUM(CASE WHEN d.status = 'active' AND d.opened_on IS NOT NULL AND d.daily_amount > 0
                     AND d.opened_on <= CURDATE()
                     AND (d.kind = 'lease' OR d.balance_amount > 0)
                     AND (d.kind <> 'lease' OR d.planned_end_on IS NOT NULL)
                     AND DATEDIFF(LEAST(CURDATE(), COALESCE(d.planned_end_on, CURDATE())), COALESCE(d.due_updated_on, DATE_SUB(d.opened_on, INTERVAL 1 DAY))) >= " . RiderDebt::GAP_WARNING_DAYS . "
                THEN 1 ELSE 0 END) AS lease_overdue
           FROM rider_debts d INNER JOIN riders r ON r.id = d.rider_id
          WHERE 1=1 {$kWhere}",
        $scopeParams
    ) ?: [];
    $kpi = [
        'active'        => (int) ($k['active_cnt']  ?? 0),
        'balance'       => (int) ($k['balance_sum'] ?? 0),
        'lease_daily'   => (int) ($k['lease_daily'] ?? 0),
        'closed'        => (int) ($k['closed_cnt']  ?? 0),
        'lease_no_end'  => (int) ($k['lease_no_end'] ?? 0),
        'lease_overdue' => (int) ($k['lease_overdue'] ?? 0),
    ];

    // ── 회수 집계(2026-09-26 갑) ────────────────────────────────────────────────
    // 「얼마나 걷혔나」는 기존 KPI(잔액·건수)로는 안 보였다. 기간을 잡아 종류별로 집계한다.
    //
    // ⚠️ **두 기준을 나란히 본다** — 같아야 정상이고, 다르면 그 자체가 신호다.
    //   · 정산 기준(`settlement_fee_items`) = 라이더에게서 **실제로 뗀 돈**. 귀속은 정산일.
    //   · 원장 기준(`rider_debt_entries`)   = 미수금 원장이 «걷었다»고 기록한 돈. 귀속은 차감일.
    // 수동 조정으로 지갑에서 직접 회수했거나(정산 밖) 차감이 어느 사이클에도 안 먹힌 경우
    // 둘이 벌어진다 — 자세한 건별 확인은 `tools/audit_double_deduction.php` ② 가 한다.
    $isDate  = static fn (string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
    $recFrom = trim((string) ($_GET['rfrom'] ?? ''));
    $recTo   = trim((string) ($_GET['rto'] ?? ''));
    $recFrom = $isDate($recFrom) ? $recFrom : date('Y-m-01');   // 기본: 이번 달
    $recTo   = $isDate($recTo) ? $recTo : date('Y-m-d');
    $debtCodes = array_keys(RiderDebt::KINDS);
    $debtCodes[] = 'rental';   // 구 데이터에 남아 있는 코드
    $ph = implode(',', array_fill(0, count($debtCodes), '?'));

    $recSettle = [];
    foreach (db_rows(
        "SELECT fi.fee_code k, COALESCE(SUM(fi.amount),0) s, COUNT(*) c
           FROM settlement_fee_items fi
           INNER JOIN settlement_rider_cycles cy ON cy.id = fi.cycle_id
           INNER JOIN riders r ON r.id = cy.rider_id
          WHERE fi.fee_code IN ({$ph}) AND cy.settlement_date BETWEEN ? AND ?"
          . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . '
          GROUP BY fi.fee_code',
        array_merge($debtCodes, [$recFrom, $recTo], $scopeParams)
    ) as $r2) {
        $recSettle[(string) $r2['k']] = ['sum' => (int) $r2['s'], 'cnt' => (int) $r2['c']];
    }

    $recLedger = [];
    foreach (db_rows(
        "SELECT d.kind k, COALESCE(SUM(e.amount),0) s, COUNT(*) c
           FROM rider_debt_entries e
           INNER JOIN rider_debts d ON d.id = e.debt_id
           INNER JOIN riders r ON r.id = d.rider_id
          WHERE e.applied_date BETWEEN ? AND ?"
          . ($scopeSql !== '' ? " AND {$scopeSql}" : '') . '
          GROUP BY d.kind',
        array_merge([$recFrom, $recTo], $scopeParams)
    ) as $r2) {
        $recLedger[(string) $r2['k']] = ['sum' => (int) $r2['s'], 'cnt' => (int) $r2['c']];
    }
}

$kindBadge   = ['loan' => 'primary', 'lease' => 'warning', 'advance' => 'info'];
$statusLabel = ['active' => '진행 중', 'paused' => '일시중지', 'closed' => '완납/종료'];
$won = static fn ($n): string => number_format((int) $n) . '원';

$riderDetailBase = admin_url('riders/detail');
$riderDetailBase .= str_contains($riderDetailBase, '?') ? '&id=' : '?id=';
$currentUrl = admin_url('deduction/debts');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
				<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">미수금 원장</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">미수금(대여금·리스·선지급)</li>
			</ul>
		</div>
		<?php if ($canWrite && !$needsMigrate): ?>
		<div class="d-flex gap-2">
			<button type="button" class="btn btn-sm btn-primary fw-bold" id="btn_debt_new">
				<i class="ki-duotone ki-plus fs-4"></i>미수금 등록
			</button>
		</div>
		<?php endif; ?>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate): ?>
	<div class="alert alert-warning p-5">미수금 원장 테이블이 아직 없습니다. 서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else: ?>

	<div id="debt_toast" class="alert alert-dismissible d-none mb-6"><span id="debt_toast_msg"></span></div>

	<?php if ($kpi['lease_no_end'] > 0) : ?>
	<div class="alert alert-warning d-flex align-items-center p-5 mb-6">
		<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div>
			<strong><?= number_format($kpi['lease_no_end']) ?>건</strong>의 진행 중 리스/렌탈에 개시일 또는 계약 종료 예정일이 없습니다.
			계약기간이 없으면 정산 반영 시 <strong>자동 차감이 되지 않으며</strong>, 「차감」 버튼으로 직접 차감해야 합니다.
			아래 목록의 「계약기간(리스)」 열에서 확인 후 수정 버튼으로 채워 주세요.
		</div>
	</div>
	<?php endif; ?>
	<?php if ($kpi['lease_overdue'] > 0) : ?>
	<div class="alert alert-danger d-flex align-items-center p-5 mb-6">
		<i class="ki-duotone ki-time fs-2hx text-danger me-4"><span class="path1"></span><span class="path2"></span></i>
		<div>
			<strong><?= number_format($kpi['lease_overdue']) ?>건</strong>의 리스/렌탈이 최근 차감일 기준 <?= RiderDebt::GAP_WARNING_DAYS ?>일 이상 반영되지 않고 있습니다.
			이 시스템은 <strong>정산 엑셀을 업로드·반영할 때만</strong> 차감되므로, 해당 대리점의 정산 업로드가 밀렸는지 확인해 주세요.
			아래 목록에서 <span class="badge badge-light-danger fs-9">N일 지연</span> 배지가 붙은 건입니다.
		</div>
	</div>
	<?php endif; ?>

	<!--begin::KPI-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">진행 중 미수금</div>
				<div class="fw-bold fs-2 text-gray-900"><?= number_format($kpi['active']) ?>건</div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">총 미상환 잔액 <span class="text-muted fs-8">(대여금·선지급)</span></div>
				<div class="fw-bold fs-2 text-danger"><?= $won($kpi['balance']) ?></div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">리스 일납 합계 <span class="text-muted fs-8">(1일)</span></div>
				<div class="fw-bold fs-2 text-warning"><?= $won($kpi['lease_daily']) ?></div>
			</div></div>
		</div>
		<div class="col-xl-3 col-md-6">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">완납/종료</div>
				<div class="fw-bold fs-2 text-gray-600"><?= number_format($kpi['closed']) ?>건</div>
			</div></div>
		</div>
	</div>
	<!--end::KPI-->

	<!--begin::회수 집계-->
	<?php // 「얼마나 걷혔나」 — 잔액·건수만으로는 안 보이던 값. 기간을 잡아 종류별로 센다(2026-09-26 갑). ?>
	<div class="card card-flush mb-8">
		<div class="card-header pt-5 flex-wrap gap-3">
			<h3 class="card-title fw-bold m-0">회수 집계
				<span class="text-muted fs-8 ms-1">기간 안에 실제로 걷힌 미수금</span>
			</h3>
			<form method="get" action="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>" class="card-toolbar d-flex align-items-end gap-2">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL): ?>
				<input type="hidden" name="route" value="deduction/debts" />
				<?php endif; ?>
				<?php // 목록 필터는 그대로 유지한 채 기간만 바꾼다. ?>
				<?php foreach (['kind' => $filterKind, 'status' => $filterStatus, 'q' => $filterQ,
				                'distributor' => $filterDist, 'agency' => $filterAgency, 'rider_id' => $filterRiderId] as $k => $v) : ?>
					<?php if ((string) $v !== '' && (string) $v !== '0') : ?>
					<input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') ?>" />
					<?php endif; ?>
				<?php endforeach; ?>
				<input type="date" name="rfrom" value="<?= htmlspecialchars($recFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" style="width:150px" />
				<span class="text-muted">~</span>
				<input type="date" name="rto" value="<?= htmlspecialchars($recTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" style="width:150px" />
				<button type="submit" class="btn btn-sm btn-primary">집계</button>
			</form>
		</div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-2 mb-0">
					<thead>
						<tr class="fw-bold text-muted">
							<th>종류</th>
							<th class="text-end">정산에서 뗀 금액</th>
							<th class="text-end">건수</th>
							<th class="text-end">원장 기록</th>
							<th class="text-end">차이</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$recTotalS = 0;
						$recTotalL = 0;
						$recKinds  = RiderDebt::KINDS + ['rental' => '렌탈(구)'];
						foreach ($recKinds as $code => $label) :
						    $sv = (int) ($recSettle[$code]['sum'] ?? 0);
						    $sc = (int) ($recSettle[$code]['cnt'] ?? 0);
						    $lv = (int) ($recLedger[$code]['sum'] ?? 0);
						    if ($sv === 0 && $lv === 0) { continue; }
						    $recTotalS += $sv;
						    $recTotalL += $lv;
						    $diff = $sv - $lv;
						?>
						<tr>
							<td class="fw-semibold"><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold text-danger"><?= $won($sv) ?></td>
							<td class="text-end text-muted"><?= number_format($sc) ?>건</td>
							<td class="text-end text-gray-700"><?= $won($lv) ?></td>
							<td class="text-end <?= $diff === 0 ? 'text-muted' : 'text-warning fw-semibold' ?>">
								<?= $diff === 0 ? '—' : ($diff > 0 ? '+' : '') . $won($diff) ?>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php if ($recTotalS === 0 && $recTotalL === 0) : ?>
						<tr><td colspan="5" class="text-center text-muted py-6">이 기간에 걷힌 미수금이 없습니다.</td></tr>
						<?php endif; ?>
					</tbody>
					<?php if ($recTotalS !== 0 || $recTotalL !== 0) : ?>
					<tfoot>
						<tr class="fw-bold bg-light">
							<td>합계</td>
							<td class="text-end text-danger"><?= $won($recTotalS) ?></td>
							<td></td>
							<td class="text-end"><?= $won($recTotalL) ?></td>
							<td class="text-end <?= $recTotalS === $recTotalL ? 'text-muted' : 'text-warning' ?>">
								<?= $recTotalS === $recTotalL ? '—' : ($recTotalS - $recTotalL > 0 ? '+' : '') . $won($recTotalS - $recTotalL) ?>
							</td>
						</tr>
					</tfoot>
					<?php endif; ?>
				</table>
			</div>
			<div class="text-muted fs-8 mt-3">
				<strong>정산에서 뗀 금액</strong> = 라이더 정산에서 실제로 빠진 돈(귀속: 정산일) ·
				<strong>원장 기록</strong> = 미수금 원장이 「걷었다」고 남긴 돈(귀속: 차감일).
				<span class="text-gray-700">둘은 같아야 정상이고, <strong>차이가 나면</strong> 수동 조정으로 정산 밖에서 회수했거나
				차감이 어느 정산에도 안 걷힌 건이 있다는 뜻입니다</span> — 건별 확인은 <code>tools/audit_double_deduction.php</code>.
			</div>
		</div>
	</div>
	<!--end::회수 집계-->

	<!--begin::Filter-->
	<form method="get" action="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">
		<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL): ?>
		<input type="hidden" name="route" value="deduction/debts" />
		<?php endif; ?>
		<div class="card card-flush mb-8"><div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<?php if (!$isAgencyLevel && $distributorOptions !== []): ?>
				<div class="col-md-2">
					<label class="form-label fw-semibold">총판</label>
					<select class="form-select form-select-solid" name="distributor" id="debt_filter_distributor" data-control="select2" data-placeholder="전체">
						<option value=""></option>
						<?php foreach ($distributorOptions as $do): ?>
						<option value="<?= (int) $do['id'] ?>" <?= $filterDist === (int) $do['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $do['name'], ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<?php if (!$isAgencyLevel && $agencyOptions !== []): ?>
				<div class="col-md-2">
					<label class="form-label fw-semibold">대리점</label>
					<?php // data-control="select2" 없음(의도적) — 총판 선택에 따라 대리점 목록을 걸러야 해서
					      // 커스텀 matcher로 직접 초기화한다(아래 스크립트). 자동 스캐너가 먼저 잡으면
					      // 두 번 초기화돼 충돌하므로 자동 초기화 대상에서 제외.
					      // 총판을 먼저 골라야 대리점을 고를 수 있다 — 처음부터 전체를 다 보여주면
					      // "총판을 고르면 대리점이 나와야지"(사용자 확인) 의도와 어긋남. ?>
					<select class="form-select form-select-solid" name="agency" id="debt_filter_agency" <?= $filterDist > 0 ? '' : 'disabled' ?>>
						<option value=""></option>
						<?php foreach ($agencyOptions as $ao): ?>
						<option value="<?= (int) $ao['id'] ?>" data-parent="<?= (int) ($ao['parent_id'] ?? 0) ?>" <?= $filterAgency === (int) $ao['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $ao['name'], ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">라이더</label>
					<?php // 이름을 정확히 몰라도 찾을 수 있게 select2 검색(riders.php ajax, 대리점 스코프 내). ?>
					<select class="form-select form-select-solid" name="rider_id" id="debt_filter_rider" style="width:100%">
						<?php if ($filterRiderId > 0 && $filterRiderName !== '') : ?>
						<option value="<?= (int) $filterRiderId ?>" selected><?= htmlspecialchars($filterRiderName, ENT_QUOTES, 'UTF-8') ?></option>
						<?php endif; ?>
					</select>
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="항목명·채권자" />
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">종류</label>
					<select class="form-select form-select-solid" name="kind">
						<option value="">전체</option>
						<?php foreach (RiderDebt::KINDS as $k => $lbl): ?>
						<option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= $filterKind === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">상태</label>
					<select class="form-select form-select-solid" name="status">
						<option value="">전체</option>
						<?php foreach ($statusLabel as $k => $lbl): ?>
						<option value="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-12 d-flex gap-2 justify-content-md-end">
					<button type="submit" class="btn btn-primary">필터 적용</button>
					<a href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">초기화</a>
				</div>
			</div>
		</div></div>
	</form>
	<!--end::Filter-->

	<?php if ($distributorOptions !== [] && $agencyOptions !== []) : ?>
	<script>
	(function () {
		// 총판 → 대리점 연동 필터. 조회 권한만 있어도 동작해야 하므로(위쪽 canWrite 게이트 밖) 별도 블록으로 둔다.
		// jQuery/select2는 이 화면 맨 아래(shell_close.php)에서 로드되므로 DOMContentLoaded 이후로 미룬다.
		function init() {
			var distEl   = document.getElementById('debt_filter_distributor');
			var agencyEl = document.getElementById('debt_filter_agency');
			if (!distEl || !agencyEl || typeof jQuery === 'undefined') { return; }

			var $agency = jQuery(agencyEl);

			/**
			 * 총판을 골라야 대리점을 고를 수 있다 — 총판 미선택 시 비활성화("총판을 먼저
			 * 선택하세요")하고, 선택하면 그 소속 대리점만 남도록 select2를 커스텀 matcher로
			 * 재초기화한다.
			 */
			function applyMatcher() {
				var distVal = distEl.value;
				agencyEl.disabled = (distVal === '');
				if ($agency.hasClass('select2-hidden-accessible')) { $agency.select2('destroy'); }
				$agency.select2({
					placeholder: distVal === '' ? '총판을 먼저 선택하세요' : '전체',
					allowClear: true,
					matcher: function (params, data) {
						if (!params.term && distVal === '') { return data; }
						var opt = data.element;
						if (distVal !== '' && opt && opt.getAttribute('data-parent') !== distVal) { return null; }
						if (!params.term) { return data; }
						return (data.text || '').toLowerCase().indexOf(params.term.toLowerCase()) > -1 ? data : null;
					},
				});
			}

			// ⚠️ select2가 옵션 선택 시 발생시키는 'change'는 jQuery 이벤트라 네이티브
			// addEventListener로는 못 받는다(실측 확인) — jQuery.on()으로 바인딩해야 한다.
			jQuery(distEl).on('change', function () {
				// 대리점 선택이 새 총판 소속이 아니면 초기화(다른 총판 소속 대리점이 조회되는 걸 방지)
				var selectedOpt = agencyEl.options[agencyEl.selectedIndex];
				if (distEl.value !== '' && selectedOpt && selectedOpt.getAttribute('data-parent') !== distEl.value) {
					jQuery(agencyEl).val('').trigger('change');
				} else if (distEl.value === '') {
					jQuery(agencyEl).val('').trigger('change');
				}
				applyMatcher();
			});

			applyMatcher();
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
	</script>
	<?php endif; ?>

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold m-0">미수금 목록 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format(count($rows)) ?>건</span></h3>
		</div>
		<div class="card-body pt-2">
			<?php if (empty($rows)): ?>
			<div class="text-center text-gray-500 py-10">
				<?= ($filterQ !== '' || $filterKind !== '' || $filterStatus !== '' || $filterRiderId > 0) ? '조건에 맞는 미수금이 없습니다.' : '등록된 미수금(대여금·리스·선지급)이 없습니다. 우측 상단 「미수금 등록」으로 추가하세요.' ?>
			</div>
			<?php else: ?>
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gy-3">
					<thead>
						<tr class="fw-bold text-muted fs-7">
							<th class="min-w-140px">라이더</th>
							<th class="min-w-100px">종류 · 항목</th>
							<th class="text-end min-w-90px">원금</th>
							<th class="text-end min-w-90px">남은 잔액</th>
							<th class="text-end min-w-80px">일납</th>
							<th class="text-end min-w-110px">다음 차감 예상</th>
							<th class="min-w-140px">계약기간 · 미납갱신</th>
							<th class="min-w-80px">상태</th>
							<th class="text-end min-w-140px">관리</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $d):
							$dk = (string) $d['kind'];
							$isAmort = in_array($dk, ['loan', 'advance', 'lease'], true);
						?>
						<tr>
							<td>
								<a href="<?= htmlspecialchars($riderDetailBase . (int) $d['rider_id'], ENT_QUOTES, 'UTF-8') ?>" class="fw-bold text-gray-900 text-hover-primary"><?= htmlspecialchars((string) $d['rider_name'], ENT_QUOTES, 'UTF-8') ?></a>
								<div class="text-muted fs-8"><?= htmlspecialchars(rider_phone_hint((string) ($d['rider_phone'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
							</td>
							<td>
								<span class="badge badge-light-<?= $kindBadge[$dk] ?? 'secondary' ?> mb-1"><?= htmlspecialchars(RiderDebt::kindLabel($dk), ENT_QUOTES, 'UTF-8') ?></span>
								<?php if (!empty($d['is_migrated'])) : ?>
								<span class="badge badge-light-info fs-9 mb-1" title="기존 계약을 잔액 기준으로 옮겨 담은 건입니다.">이관</span>
								<?php endif; ?>
								<?php if ($dk === 'lease' && (string) ($d['lease_provider'] ?? '') !== '') : ?>
								<span class="badge badge-light-dark fs-9 mb-1"><?= htmlspecialchars(RiderDebt::providerLabel((string) $d['lease_provider']), ENT_QUOTES, 'UTF-8') ?> 제공</span>
								<?php endif; ?>
								<div class="text-gray-800 fs-7"><?= htmlspecialchars((string) ($d['title'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></div>
								<?php if ($dk === 'lease') : ?>
								<?php if ((string) ($d['vin'] ?? '') !== '') : ?>
								<div class="text-muted fs-8 font-monospace">VIN <?= htmlspecialchars((string) $d['vin'], ENT_QUOTES, 'UTF-8') ?></div>
								<?php endif; ?>
								<?php
								$feeParts = [];
								foreach (['fee_hq' => '본사', 'fee_distributor' => '총판', 'fee_agency' => '대리점'] as $fk => $fl) {
								    if ((int) ($d[$fk] ?? 0) > 0) { $feeParts[] = $fl . ' ' . number_format((int) $d[$fk]); }
								}
								?>
								<?php if ($feeParts !== []) : ?>
								<div class="text-gray-600 fs-8">배분(일) <?= htmlspecialchars(implode(' · ', $feeParts), ENT_QUOTES, 'UTF-8') ?>원</div>
								<?php endif; ?>
								<?php endif; ?>
							</td>
							<td class="text-end text-gray-700"><?= $isAmort ? $won($d['principal_amount']) : '—' ?></td>
							<td class="text-end fw-bold <?= $isAmort && (int) $d['balance_amount'] > 0 ? 'text-danger' : 'text-gray-500' ?>"><?= $isAmort ? $won($d['balance_amount']) : '—' ?></td>
							<td class="text-end text-gray-700"><?= (int) $d['daily_amount'] > 0 ? $won($d['daily_amount']) : '—' ?></td>
							<?php
							// 다음 정산에서 실제로 걸릴 금액 — 선지급금은 잔액 전액, 나머지는 밀린 일수 × 일납.
							// 차감이 아예 안 되는 설정(일납 0·개시일 없음·리스 종료일 없음)은 여기서 눈에 띄게 한다.
							$dGap  = RiderDebt::accrualGap($d);
							$dDead = (string) ($d['opened_on'] ?? '') === ''
							      || ($dk !== 'advance' && (int) $d['daily_amount'] <= 0)
							      || ($dk === 'lease' && (string) ($d['planned_end_on'] ?? '') === '');
							$dFull = $dk === 'advance' && (int) $d['daily_amount'] <= 0;
							$dNext = 0;
							if ((string) $d['status'] === 'active' && !$dDead) {
							    $dNext = $dFull
							        ? (int) $d['balance_amount']
							        : min((int) $d['balance_amount'], (int) $d['daily_amount'] * (int) ($dGap['gap_days'] ?? 0));
							}
							?>
							<td class="text-end fs-7">
								<?php if ((string) $d['status'] !== 'active') : ?>
								<span class="text-gray-500">—</span>
								<?php elseif ($dDead) : ?>
								<span class="badge badge-light-danger fs-9" title="설정이 비어 있어 자동·수동 차감이 모두 되지 않습니다.">차감 안 됨</span>
								<?php elseif ($dNext > 0) : ?>
								<span class="text-gray-800 fw-semibold"><?= $won($dNext) ?></span>
								<div class="text-muted fs-9"><?= $dFull ? '전액 회수' : (int) ($dGap['gap_days'] ?? 0) . '일치' ?></div>
								<?php else : ?>
								<span class="text-gray-500">—</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-600 fs-8">
								<?php $gap = RiderDebt::accrualGap($d); ?>
								<?php if ($dk === "lease" && (string) ($d["opened_on"] ?? "") !== "" && (string) ($d["planned_end_on"] ?? "") !== ""): ?>
								<?= htmlspecialchars((string) $d["opened_on"], ENT_QUOTES, "UTF-8") ?> ~ <?= htmlspecialchars((string) $d["planned_end_on"], ENT_QUOTES, "UTF-8") ?>
								<?php if ($gap !== null && $gap["overdue"]): ?>
								<br><span class="badge badge-light-danger fs-9"><?= (int) $gap["gap_days"] ?>일 지연</span>
								<?php elseif ($gap !== null && $gap["gap_days"] > 0): ?>
								<br><span class="badge badge-light-secondary fs-9"><?= (int) $gap["gap_days"] ?>일 경과</span>
								<?php endif; ?>
								<?php elseif ($dk === "lease"): ?>
								<span class="badge badge-light-warning fs-9">종료일 미설정 · 자동차감 안됨</span>
								<?php else: ?>
								—
								<?php endif; ?>
							</td>
							<td><span class="badge badge-light-<?= $d['status'] === 'active' ? 'success' : ($d['status'] === 'closed' ? 'dark' : 'warning') ?> fs-8"><?= htmlspecialchars($statusLabel[$d['status']] ?? $d['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-end text-nowrap">
								<?php if ($canWrite && $d['status'] === 'active'): ?>
								<button type="button" class="btn btn-sm btn-light-danger py-1 px-3 debt-repay-btn"
									data-id="<?= (int) $d['id'] ?>" data-kind="<?= htmlspecialchars($dk, ENT_QUOTES, 'UTF-8') ?>"
									data-title="<?= htmlspecialchars((string) ($d['title'] ?: RiderDebt::kindLabel($dk)), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $d['rider_name'], ENT_QUOTES, 'UTF-8') ?>"
									data-daily="<?= (int) $d['daily_amount'] ?>" data-balance="<?= (int) $d['balance_amount'] ?>">차감</button>
								<?php endif; ?>
								<?php if ($canWrite): ?>
								<button type="button" class="btn btn-sm btn-icon btn-light-warning debt-edit-btn"
									data-id="<?= (int) $d['id'] ?>" data-title="<?= htmlspecialchars((string) $d['title'], ENT_QUOTES, 'UTF-8') ?>"
									data-daily="<?= (int) $d['daily_amount'] ?>" data-creditor="<?= htmlspecialchars((string) $d['creditor'], ENT_QUOTES, 'UTF-8') ?>"
									data-balance="<?= (int) $d['balance_amount'] ?>" data-status="<?= htmlspecialchars((string) $d['status'], ENT_QUOTES, 'UTF-8') ?>"
									data-amort="<?= $isAmort ? 1 : 0 ?>" data-kind="<?= htmlspecialchars($dk, ENT_QUOTES, 'UTF-8') ?>"
									data-planned-end="<?= htmlspecialchars((string) ($d['planned_end_on'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
									data-provider="<?= htmlspecialchars((string) ($d['lease_provider'] ?? 'hq'), ENT_QUOTES, 'UTF-8') ?>"
									data-vin="<?= htmlspecialchars((string) ($d['vin'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
									data-fee-hq="<?= (int) ($d['fee_hq'] ?? 0) ?>"
									data-fee-dist="<?= (int) ($d['fee_distributor'] ?? 0) ?>"
									data-fee-ag="<?= (int) ($d['fee_agency'] ?? 0) ?>" title="수정">
									<i class="ki-duotone ki-pencil fs-5"><span class="path1"></span><span class="path2"></span></i>
								</button>
								<?php endif; ?>
								<a href="<?= htmlspecialchars($riderDetailBase . (int) $d['rider_id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light py-1 px-3" title="이력은 라이더 상세에서">이력 <?= (int) $d['entry_count'] ?></a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<div class="text-muted fs-8 mt-3">
				모든 차감은 <strong>정산 엑셀을 업로드·반영할 때</strong> 처리됩니다 — 매일 자동으로 도는 별도 배치는 없습니다.
				<strong>대여금·리스</strong>는 마지막 차감일 다음날부터 정산일까지의 <strong>달력일 × 일납</strong>만큼 자동 차감됩니다(라이더가 쉰 날도 셉니다. 같은 정산을 재반영해도 중복 차감되지 않습니다).
				<strong>선지급금</strong>은 다음 정산에서 <strong>잔액 전액</strong>을 회수하며, 그날 실지급이 모자라면 걷을 수 있는 만큼만 걷고 나머지는 다음 정산으로 넘어갑니다.
				회수 순서는 리스 → 대여금 → 선지급금입니다.
				리스/렌탈의 <strong>계약 종료 예정일은 총 금액 ÷ 일납</strong>으로 자동 계산되며, 일납이나 잔액을 고치면 다시 잡힙니다.
				차감 이력·취소는 라이더 상세의 「미수금」 카드에서 확인합니다.
			</div>
		</div>
	</div>

	<?php if ($canWrite): ?>
	<!--begin::New Modal-->
	<div class="modal fade" id="kt_debt_new_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px"><div class="modal-content">
			<div class="modal-header"><h2 class="fw-bold">미수금 등록</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
			<div class="modal-body py-lg-8 px-lg-10">
				<div id="debt_new_alert" class="d-none mb-4"></div>
				<div class="mb-4">
					<label class="form-label fs-7 fw-semibold required">라이더</label>
					<?php // 검색 버튼 → 목록 클릭 2단계였던 걸 select2 한 단계로 바꿨다(타이핑하면 바로 후보). ?>
					<select class="form-select form-select-solid" id="dn_rider_sel" style="width:100%"></select>
					<input type="hidden" id="dn_rider_id" />
					<div class="form-text fs-8" id="dn_rider_picked"></div>
				</div>
				<div class="row g-4">
					<div class="col-md-4">
						<label class="form-label fs-7 fw-semibold required">종류</label>
						<select class="form-select form-select-sm form-select-solid" id="dn_kind">
							<option value="loan">대여금</option>
							<option value="lease">리스/렌탈</option>
							<option value="advance">선지급금</option>
						</select>
					</div>
					<div class="col-md-8">
						<label class="form-label fs-7 fw-semibold">항목명</label>
						<input type="text" class="form-control form-control-sm form-control-solid" id="dn_title" maxlength="120" placeholder="예: 차량대여금, 오토바이리스" />
					</div>
					<div class="col-md-6" id="dn_principal_wrap">
						<label class="form-label fs-7 fw-semibold required" id="dn_principal_label">원금</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dn_principal" min="0" step="1000" placeholder="예: 1250000" />
						<div class="form-text fs-8" id="dn_principal_help">남은 잔액의 시작값.</div>
					</div>
					<div class="col-md-6" id="dn_daily_wrap">
						<label class="form-label fs-7 fw-semibold required">일납금액</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dn_daily" min="0" step="100" placeholder="예: 24000" />
						<div class="form-text fs-8">정산 반영 때 일납 × 경과일수로 자동 차감됩니다.</div>
					</div>
					<div class="col-md-6" id="dn_opened_wrap">
						<label class="form-label fs-7 fw-semibold required">개시일/출고일</label>
						<input type="date" class="form-control form-control-sm form-control-solid" id="dn_opened" />
					</div>
					<div class="col-md-6 d-none" id="dn_planned_end_wrap">
						<label class="form-label fs-7 fw-semibold">계약 종료 예정일 <span class="text-muted fs-8">(자동 계산)</span></label>
						<input type="date" class="form-control form-control-sm form-control-solid bg-light" id="dn_planned_end" readonly />
						<div class="form-text fs-8" id="dn_end_help">총 금액 ÷ 일납으로 며칠이 걸리는지 계산해 채웁니다.</div>
					</div>
					<div class="col-12 d-none" id="dn_lease_wrap">
						<div class="separator separator-dashed my-2"></div>
						<div class="row g-4">
							<div class="col-md-5">
								<label class="form-label fs-7 fw-semibold required">리스 제공 주체</label>
								<select class="form-select form-select-sm form-select-solid" id="dn_lease_provider">
									<?php foreach (RiderDebt::LEASE_PROVIDERS as $pv => $pl) : ?>
									<option value="<?= htmlspecialchars($pv, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($pl, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
								<div class="form-text fs-8">제공 주체와 그 하위 조직만 수수료를 나눠 갖습니다.</div>
							</div>
							<div class="col-md-7">
								<label class="form-label fs-7 fw-semibold">차대번호(VIN)</label>
								<input type="text" class="form-control form-control-sm form-control-solid font-monospace" id="dn_vin" maxlength="30" placeholder="예: KMYJZ123456789012" />
							</div>
							<div class="col-12">
								<label class="form-label fs-7 fw-semibold">수수료 배분 <span class="text-muted fs-8">(일 단위 금액 · 합계는 일납을 넘을 수 없음)</span></label>
								<div class="row g-3">
									<div class="col-md-4 d-none" id="dn_fee_hq_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">본사</span>
											<input type="number" class="form-control dn-fee" id="dn_fee_hq" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4 d-none" id="dn_fee_dist_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">총판</span>
											<input type="number" class="form-control dn-fee" id="dn_fee_distributor" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4" id="dn_fee_ag_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">대리점</span>
											<input type="number" class="form-control dn-fee" id="dn_fee_agency" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
								</div>
								<div class="form-text fs-8" id="dn_fee_hint"></div>
							</div>
						</div>
					</div>
					<div class="col-12">
						<label class="form-label fs-7 fw-semibold">메모</label>
						<input type="text" class="form-control form-control-sm form-control-solid" id="dn_note" maxlength="255" />
					</div>
					<?php // 기존 계약 이관 — 잔액 기준. 켜지 않으면 개시일부터 전부 미차감으로 보고 소급 부과된다. ?>
					<div class="col-12" id="dn_migrate_block">
						<div class="separator separator-dashed my-2"></div>
						<label class="form-check form-switch form-check-custom form-check-solid align-items-start">
							<input class="form-check-input me-3 mt-1" type="checkbox" id="dn_migrate" />
							<span class="d-flex flex-column">
								<span class="fw-semibold text-gray-800">기존 계약 이관</span>
								<span class="text-muted fs-8">다른 곳에서 관리하던 계약을 옮겨올 때 켜세요. <strong>남은 잔액</strong>부터 시작하고, 기준일 다음날부터 차감합니다.</span>
							</span>
						</label>
						<div class="row g-4 mt-1 d-none" id="dn_migrate_fields">
							<div class="col-md-6">
								<label class="form-label fs-7 fw-semibold required">남은 잔액</label>
								<input type="number" class="form-control form-control-sm form-control-solid" id="dn_migrate_balance" min="0" step="1000" placeholder="예: 3240000" />
							</div>
							<div class="col-md-6">
								<label class="form-label fs-7 fw-semibold required">이 날짜까지 정산 완료</label>
								<input type="date" class="form-control form-control-sm form-control-solid" id="dn_migrate_as_of" />
								<div class="form-text fs-8">이 날 다음날부터 차감이 시작됩니다.</div>
							</div>
						</div>
					</div>
					<div class="col-12">
						<div class="bg-light rounded p-3 fs-8" id="dn_preview">라이더와 금액을 입력하면 다음 정산에서 얼마가 차감될지 보여 드립니다.</div>
					</div>
				</div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-primary" id="dn_save">등록</button></div>
		</div></div>
	</div>
	<!--end::New Modal-->

	<!--begin::Repay Modal-->
	<div class="modal fade" id="kt_debt_repay_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px"><div class="modal-content">
			<div class="modal-header"><h2 class="fw-bold">차감 실행</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
			<div class="modal-body py-lg-8 px-lg-10">
				<div id="debt_repay_alert" class="d-none mb-4"></div>
				<input type="hidden" id="dr_debt_id" />
				<div class="mb-4 p-3 bg-light-primary rounded fs-7">
					<span id="dr_title" class="fw-bold"></span>
					<span class="text-muted ms-2">남은 잔액 <span id="dr_balance" class="fw-bold"></span></span>
				</div>
				<div class="row g-4">
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold required">차감 귀속일</label>
						<input type="date" class="form-control form-control-sm form-control-solid" id="dr_date" value="<?= date('Y-m-d') ?>" />
						<div class="form-text fs-8">이 날짜의 정산 반영 시 차감됩니다.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">차감일수</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dr_days" min="0" value="7" />
					</div>
					<div class="col-12">
						<label class="form-label fs-7 fw-semibold">차감액 <span class="text-muted fs-8">(비우면 일납×일수)</span></label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dr_amount" min="0" step="100" placeholder="자동 계산" />
						<div class="form-text fs-8" id="dr_hint"></div>
					</div>
				</div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-danger" id="dr_save">차감</button></div>
		</div></div>
	</div>
	<!--end::Repay Modal-->

	<!--begin::Edit Modal-->
	<div class="modal fade" id="kt_debt_edit_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px"><div class="modal-content">
			<div class="modal-header"><h2 class="fw-bold">미수금 수정</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
			<div class="modal-body py-lg-8 px-lg-10">
				<div id="debt_edit_alert" class="d-none mb-4"></div>
				<input type="hidden" id="de_debt_id" />
				<div class="row g-4">
					<div class="col-12">
						<label class="form-label fs-7 fw-semibold">항목명</label>
						<input type="text" class="form-control form-control-sm form-control-solid" id="de_title" maxlength="120" />
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">일납금액</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="de_daily" min="0" step="100" />
					</div>
					<div class="col-md-6" id="de_balance_wrap">
						<label class="form-label fs-7 fw-semibold">남은 잔액 보정</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="de_balance" min="0" step="1000" />
					</div>
					<div class="col-md-6" id="de_planned_end_wrap">
						<label class="form-label fs-7 fw-semibold">계약 종료 예정일 <span class="text-muted fs-8">(자동 계산)</span></label>
						<input type="date" class="form-control form-control-sm form-control-solid bg-light" id="de_planned_end" readonly />
						<div class="form-text fs-8">남은 잔액 ÷ 일납으로 저장할 때 다시 계산됩니다.</div>
					</div>
					<div class="col-12 d-none" id="de_lease_wrap">
						<div class="separator separator-dashed my-1"></div>
						<div class="row g-4">
							<div class="col-md-5">
								<label class="form-label fs-7 fw-semibold">리스 제공 주체</label>
								<select class="form-select form-select-sm form-select-solid" id="de_lease_provider">
									<?php foreach (RiderDebt::LEASE_PROVIDERS as $pv => $pl) : ?>
									<option value="<?= htmlspecialchars($pv, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($pl, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-7">
								<label class="form-label fs-7 fw-semibold">차대번호(VIN)</label>
								<input type="text" class="form-control form-control-sm form-control-solid font-monospace" id="de_vin" maxlength="30" />
							</div>
							<div class="col-12">
								<label class="form-label fs-7 fw-semibold">수수료 배분 <span class="text-muted fs-8">(일 단위 금액)</span></label>
								<div class="row g-3">
									<div class="col-md-4 d-none" id="de_fee_hq_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">본사</span>
											<input type="number" class="form-control de-fee" id="de_fee_hq" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4 d-none" id="de_fee_dist_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">총판</span>
											<input type="number" class="form-control de-fee" id="de_fee_distributor" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4" id="de_fee_ag_wrap">
										<div class="input-group input-group-sm debt-fee-split">
											<span class="input-group-text">대리점</span>
											<input type="number" class="form-control de-fee" id="de_fee_agency" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
								</div>
								<div class="form-text fs-8" id="de_fee_hint"></div>
							</div>
						</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">상태</label>
						<select class="form-select form-select-sm form-select-solid" id="de_status">
							<option value="active">진행 중</option>
							<option value="paused">일시중지</option>
							<option value="closed">완납/종료</option>
						</select>
					</div>
				</div>
			</div>
			<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-primary" id="de_save">저장</button></div>
		</div></div>
	</div>
	<!--end::Edit Modal-->
	<?php endif; ?>

	<script>
	(function () {
		var DEBT_API  = <?= json_encode($debtApi, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
		var RIDER_API = <?= json_encode($riderApi, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
		var canWrite  = <?= $canWrite ? 'true' : 'false' ?>;
		if (!canWrite) { return; }

		var won = function (n) { return (Number(n) || 0).toLocaleString() + '원'; };
		function post(p) {
			return fetch(DEBT_API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(p) })
				.then(function (r) { return r.json(); });
		}
		function setAlert(id, msg, type) {
			var el = document.getElementById(id);
			el.className = 'alert alert-' + (type || 'danger') + ' mb-4';
			el.textContent = msg;
		}
		function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }

		// ── 등록 ──
		var kindEl = document.getElementById('dn_kind');

		/**
		 * 제공 주체별로 배분 입력칸을 보였다/숨겼다 한다.
		 * 숨긴 칸은 값을 0으로 리셋 — 안 그러면 화면엔 안 보이는데 값이 전송돼 혼란스럽다.
		 * (서버도 같은 규칙으로 한 번 더 걸러내므로 화면을 우회해도 안전하다)
		 */
		function syncFeeFields(prefix) {
			var provider = document.getElementById(prefix + '_lease_provider').value;
			var showHq   = (provider === 'hq');
			var showDist = (provider === 'hq' || provider === 'distributor');
			document.getElementById(prefix + '_fee_hq_wrap').classList.toggle('d-none', !showHq);
			document.getElementById(prefix + '_fee_dist_wrap').classList.toggle('d-none', !showDist);
			if (!showHq)   { document.getElementById(prefix + '_fee_hq').value = 0; }
			if (!showDist) { document.getElementById(prefix + '_fee_distributor').value = 0; }
			syncFeeHint(prefix);
		}

		/** 배분 합계 vs 일납 안내 — 서버가 거부하기 전에 화면에서 먼저 알려준다. */
		function syncFeeHint(prefix) {
			var daily = Number(document.getElementById(prefix === 'dn' ? 'dn_daily' : 'de_daily').value) || 0;
			var sum = ['_fee_hq', '_fee_distributor', '_fee_agency'].reduce(function (a, s) {
				return a + (Number(document.getElementById(prefix + s).value) || 0);
			}, 0);
			var el = document.getElementById(prefix + '_fee_hint');
			if (sum > daily) {
				el.className = 'form-text fs-8 text-danger';
				el.textContent = '배분 합계 ' + sum.toLocaleString() + '원이 일납 ' + daily.toLocaleString() + '원보다 큽니다. 저장할 수 없습니다.';
			} else {
				el.className = 'form-text fs-8 text-muted';
				el.textContent = '배분 합계 ' + sum.toLocaleString() + '원 / 일납 ' + daily.toLocaleString() + '원'
					+ (daily > 0 ? ' · 남는 ' + (daily - sum).toLocaleString() + '원은 리스사 등 외부 지급분' : '');
			}
		}

		// 종류마다 필요한 칸이 다르다 — 선지급금은 금액·메모만, 리스는 계약기간·배분까지.
		function syncPrincipal() {
			var kind = kindEl.value, isLease = kind === 'lease', isAdv = kind === 'advance';
			// 리스도 총 금액을 직접 받는다 — 종료예정일을 «총액 ÷ 일납» 으로 계산하기 때문(2026-09-18 갑).
			document.getElementById('dn_principal_wrap').style.display = '';
			document.getElementById('dn_principal_label').textContent = isAdv ? '선지급 금액' : (isLease ? '총 금액' : '원금');
			document.getElementById('dn_principal_help').textContent = isAdv
				? '다음 정산에서 전액 회수합니다. 모자라면 남은 금액은 그다음 정산으로 넘어갑니다.'
				: (isLease ? '리스/렌탈 계약 총액. 종료예정일은 이 금액 ÷ 일납으로 계산됩니다.' : '남은 잔액의 시작값.');
			// 선지급금은 일납·개시일·항목명 없이 금액과 메모만 받는다(2026-09-18 갑).
			document.getElementById('dn_daily_wrap').classList.toggle('d-none', isAdv);
			document.getElementById('dn_opened_wrap').classList.toggle('d-none', isAdv);
			document.getElementById('dn_title').closest('.col-md-8').classList.toggle('d-none', isAdv);
			document.getElementById('dn_planned_end_wrap').classList.toggle('d-none', !isLease);
			document.getElementById('dn_lease_wrap').classList.toggle('d-none', !isLease);
			// 선지급금은 「금액 = 남은 잔액」이라 이관 입력이 따로 필요 없다.
			document.getElementById('dn_migrate_block').classList.toggle('d-none', isAdv);
			if (isAdv) { document.getElementById('dn_migrate').checked = false; }
			if (isLease) { syncFeeFields('dn'); }
			syncMigrate();
		}
		function syncMigrate() {
			var on = document.getElementById('dn_migrate').checked && kindEl.value !== 'advance';
			document.getElementById('dn_migrate_fields').classList.toggle('d-none', !on);
			// 이관이면 «남은 잔액»이 유일한 기준이다 — 총 금액/원금 칸을 같이 두면 어느 쪽이
			// 맞는지 알 수 없다(서버도 잔액만 쓴다). 켜는 순간 감추고 값을 비운다.
			document.getElementById('dn_principal_wrap').style.display = on ? 'none' : '';
			if (on) { document.getElementById('dn_principal').value = ''; }
			syncPreview();
		}
		/** 리스 종료예정일 = 부과 시작일 + (총액 ÷ 일납) − 1일. 서버(RiderDebt::leaseEndDate)와 같은 식. */
		function syncLeaseEnd() {
			if (kindEl.value !== 'lease') { return; }
			var num   = function (id) { return Number(document.getElementById(id).value) || 0; };
			var daily = num('dn_daily');
			var mig   = document.getElementById('dn_migrate').checked;
			var amount = mig ? num('dn_migrate_balance') : num('dn_principal');
			var from   = mig ? document.getElementById('dn_migrate_as_of').value : document.getElementById('dn_opened').value;
			var endEl  = document.getElementById('dn_planned_end');
			var help   = document.getElementById('dn_end_help');
			if (!from || daily <= 0 || amount <= 0) {
				endEl.value = '';
				help.textContent = mig
					? '남은 잔액 ÷ 일납으로 며칠이 더 걸리는지 계산해 채웁니다.'
					: '총 금액 ÷ 일납으로 며칠이 걸리는지 계산해 채웁니다.';
				return;
			}
			var days  = Math.ceil(amount / daily);
			var p     = from.split('-');
			var start = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]) + (mig ? 86400000 : 0));  // 이관은 기준일 다음날부터
			var end   = new Date(start.getTime() + (days - 1) * 86400000);
			endEl.value = end.toISOString().slice(0, 10);
			var last = amount - daily * (days - 1);
			help.textContent = days + '일 동안 차감합니다.'
				+ (last !== daily ? ' 마지막 날은 남은 ' + won(last) + '만 걷습니다.' : '');
		}

		/** 저장 전에 «다음 정산에서 얼마가 빠지는지»를 보여준다 — 이관 체크를 깜빡하면 여기 큰 금액이 뜬다. */
		function syncPreview() {
			syncLeaseEnd();
			var el = document.getElementById('dn_preview');
			if (!el) { return; }
			var kind  = kindEl.value;
			var num   = function (id) { return Number(document.getElementById(id).value) || 0; };
			var daily = num('dn_daily');
			var mig   = document.getElementById('dn_migrate').checked && kind !== 'advance';
			var from  = mig ? document.getElementById('dn_migrate_as_of').value : document.getElementById('dn_opened').value;
			var bal   = mig ? num('dn_migrate_balance') : num('dn_principal');

			if (kind === 'advance') {
				var amt = num('dn_principal');
				el.className = 'bg-light rounded p-3 fs-8';
				el.innerHTML = amt > 0
					? '다음 정산에서 <strong>' + won(amt) + ' 전액</strong>을 회수합니다. 그날 실지급이 모자라면 남은 금액은 다음 정산으로 넘어갑니다.'
					: '선지급 금액을 입력하세요.';
				return;
			}
			if (!from || daily <= 0) {
				el.className = 'bg-light rounded p-3 fs-8';
				el.textContent = mig ? '남은 잔액과 기준일을 입력하세요.' : '개시일과 일납금액을 입력하세요.';
				return;
			}
			// 기준일(또는 개시일 전날) 다음날 ~ 오늘까지의 달력일.
			// ⚠️ new Date('2026-09-17') 은 UTC 자정이라 로컬(KST) 자정과 9시간 어긋난다 —
			//    날짜만 꺼내 UTC 기준으로 계산해야 하루가 밀리지 않는다.
			var toUtc = function (s) { var p = String(s).split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); };
			var now   = new Date();
			var today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
			var start = toUtc(from) - (mig ? 0 : 86400000);   // 이관은 기준일 다음날부터, 신규는 개시일부터
			var days  = Math.floor((today - start) / 86400000);
			if (days < 0) { days = 0; }
			var amount = daily * days;
			if (bal > 0 && amount > bal) { amount = bal; }
			var heavy = days > 14;
			el.className = 'rounded p-3 fs-8 ' + (heavy ? 'bg-light-danger' : 'bg-light');
			el.innerHTML = (heavy ? '⚠️ ' : '')
				+ '다음 정산에서 <strong>' + days + '일치 ' + won(amount) + '</strong>이 한꺼번에 차감됩니다.'
				+ (heavy && !mig ? '<br>이미 받던 계약을 옮기는 중이라면 <strong>「기존 계약 이관」</strong>을 켜고 남은 잔액과 정산 완료일을 입력하세요.' : '')
				+ '<br><span class="text-muted">라이더 실지급이 모자라면 걷을 수 있는 만큼만 걷고 나머지는 다음 정산으로 넘어갑니다.</span>';
		}
		kindEl.addEventListener('change', syncPrincipal);
		document.getElementById('dn_migrate').addEventListener('change', syncMigrate);
		['dn_daily', 'dn_principal', 'dn_opened', 'dn_migrate_balance', 'dn_migrate_as_of'].forEach(function (i) {
			document.getElementById(i).addEventListener('input', syncPreview);
		});
		document.getElementById('dn_lease_provider').addEventListener('change', function () { syncFeeFields('dn'); });
		document.getElementById('de_lease_provider').addEventListener('change', function () { syncFeeFields('de'); });
		['dn_daily', 'dn_fee_hq', 'dn_fee_distributor', 'dn_fee_agency'].forEach(function (i) {
			document.getElementById(i).addEventListener('input', function () { syncFeeHint('dn'); });
		});
		['de_daily', 'de_fee_hq', 'de_fee_distributor', 'de_fee_agency'].forEach(function (i) {
			document.getElementById(i).addEventListener('input', function () { syncFeeHint('de'); });
		});

		document.getElementById('btn_debt_new').addEventListener('click', function () {
			['dn_title', 'dn_principal', 'dn_daily', 'dn_opened', 'dn_planned_end', 'dn_note', 'dn_rider_id', 'dn_vin',
			 'dn_migrate_balance', 'dn_migrate_as_of'].forEach(function (i) { document.getElementById(i).value = ''; });
			document.getElementById('dn_migrate').checked = false;
			['dn_fee_hq', 'dn_fee_distributor', 'dn_fee_agency'].forEach(function (i) { document.getElementById(i).value = 0; });
			document.getElementById('dn_lease_provider').value = 'hq';
			document.getElementById('dn_rider_picked').textContent = '';
			if (typeof jQuery !== 'undefined') { jQuery('#dn_rider_sel').val(null).trigger('change'); }
			kindEl.value = 'loan'; syncPrincipal();
			document.getElementById('debt_new_alert').className = 'd-none';
			modal('kt_debt_new_modal').show();
		});

		/**
		 * 라이더 선택 select2 — 원장 필터와 등록 모달 둘 다.
		 * 예전엔 「검색 버튼 → 목록에서 클릭」 2단계였는데, 목록이 안 뜨면 왜 안 되는지 알기 어려웠다.
		 * 이제 타이핑하는 대로 후보가 뜬다(riders.php ajax, 이름으로만 검색 · 대리점 스코프 내).
		 */
		function initRiderSelect2() {
			if (typeof jQuery === 'undefined' || !jQuery.fn.select2) { return; }

			var ajaxOpts = {
				url: RIDER_API,
				dataType: 'json',
				delay: 250,
				data: function (params) { return { q: params.term || '', q_field: 'name', limit: 30 }; },
				processResults: function (data) {
					return {
						results: (data.items || []).map(function (r) {
							return { id: r.id, text: r.name + (r.phone_masked ? ' (' + r.phone_masked + ')' : '') };
						}),
					};
				},
			};

			var $filter = jQuery('#debt_filter_rider');
			if ($filter.length) {
				$filter.select2({ placeholder: '전체 (이름으로 검색)', allowClear: true, ajax: ajaxOpts });
			}

			var $modalSel = jQuery('#dn_rider_sel');
			if ($modalSel.length) {
				$modalSel.select2({
					placeholder: '이름으로 검색',
					allowClear: true,
					ajax: ajaxOpts,
					// 모달 안에서 열릴 때 드롭다운이 뒤로 깔리지 않게 부모를 모달로 지정한다.
					dropdownParent: jQuery('#kt_debt_new_modal'),
				});
				$modalSel.on('change', function () {
					var v = $modalSel.val() || '';
					document.getElementById('dn_rider_id').value = v;
					var txt = $modalSel.find('option:selected').text();
					document.getElementById('dn_rider_picked').textContent = v ? ('선택: ' + txt) : '';
				});
			}
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initRiderSelect2);
		} else {
			initRiderSelect2();
		}
		document.getElementById('dn_save').addEventListener('click', function () {
			var rid = parseInt(document.getElementById('dn_rider_id').value, 10) || 0;
			if (!rid) { setAlert('debt_new_alert', '라이더를 검색해서 선택하세요.'); return; }
			var btn = this; btn.disabled = true;
			var mig = document.getElementById('dn_migrate').checked && kindEl.value !== 'advance';
			var payload = {
				action: 'create', rider_id: rid, kind: kindEl.value,
				title: document.getElementById('dn_title').value.trim(),
				principal_amount: Number(document.getElementById('dn_principal').value) || 0,
				daily_amount: Number(document.getElementById('dn_daily').value) || 0,
				opened_on: document.getElementById('dn_opened').value,
				is_migrated: mig ? 1 : 0,
				migrate_balance: mig ? (Number(document.getElementById('dn_migrate_balance').value) || 0) : 0,
				migrate_as_of: mig ? document.getElementById('dn_migrate_as_of').value : '',
				lease_provider: document.getElementById('dn_lease_provider').value,
				vin: document.getElementById('dn_vin').value.trim(),
				fee_hq: Number(document.getElementById('dn_fee_hq').value) || 0,
				fee_distributor: Number(document.getElementById('dn_fee_distributor').value) || 0,
				fee_agency: Number(document.getElementById('dn_fee_agency').value) || 0,
				note: document.getElementById('dn_note').value.trim()
			};
			var send = function () {
				return post(payload).then(function (d) {
					if (d.ok) { location.reload(); return; }
					// 같은 종류가 이미 진행 중 — 서버가 목록을 돌려준다. 확인하면 그대로 다시 보낸다.
					if (d.duplicate && !payload.confirm_duplicate) {
						var lines = d.duplicate.map(function (x) {
							return '· ' + (x.title || '(제목 없음)') + ' · 잔액 ' + won(x.balance) + (x.opened ? ' · ' + x.opened + ' 개시' : '');
						}).join('\n');
						if (confirm(d.message + '\n\n' + lines)) {
							payload.confirm_duplicate = 1;
							return send();
						}
						btn.disabled = false;
						return;
					}
					setAlert('debt_new_alert', d.message || '오류');
					btn.disabled = false;
				});
			};
			send().catch(function () { setAlert('debt_new_alert', '네트워크 오류'); btn.disabled = false; });
		});

		// ── 차감 ──
		function recalcHint() {
			var daily = Number(document.getElementById('kt_debt_repay_modal').dataset.daily) || 0;
			var days  = Number(document.getElementById('dr_days').value) || 0;
			var amt   = document.getElementById('dr_amount').value;
			var calc  = amt !== '' ? Number(amt) : daily * days;
			document.getElementById('dr_hint').textContent = amt !== ''
				? ('입력 금액 ' + won(calc))
				: ('예상 차감 ' + won(calc) + ' (일납 ' + won(daily) + ' × ' + days + '일)');
		}
		document.querySelectorAll('.debt-repay-btn').forEach(function (b) {
			b.addEventListener('click', function () {
				var m = document.getElementById('kt_debt_repay_modal');
				m.dataset.daily = b.dataset.daily;
				document.getElementById('dr_debt_id').value = b.dataset.id;
				document.getElementById('dr_title').textContent = b.dataset.title;
				document.getElementById('dr_balance').textContent = (b.dataset.kind === 'lease') ? '해당없음' : won(b.dataset.balance);
				document.getElementById('dr_amount').value = '';
				document.getElementById('dr_days').value = 7;
				document.getElementById('debt_repay_alert').className = 'd-none';
				recalcHint();
				modal('kt_debt_repay_modal').show();
			});
		});
		['dr_days', 'dr_amount'].forEach(function (i) { document.getElementById(i).addEventListener('input', recalcHint); });
		document.getElementById('dr_save').addEventListener('click', function () {
			var btn = this; btn.disabled = true;
			post({
				action: 'repay', debt_id: Number(document.getElementById('dr_debt_id').value),
				applied_date: document.getElementById('dr_date').value,
				days: Number(document.getElementById('dr_days').value) || 0,
				amount: document.getElementById('dr_amount').value, memo: ''
			}).then(function (d) {
				if (d.ok) { location.reload(); } else { setAlert('debt_repay_alert', d.message || '오류'); btn.disabled = false; }
			}).catch(function () { setAlert('debt_repay_alert', '네트워크 오류'); btn.disabled = false; });
		});

		// ── 수정 ──
		document.querySelectorAll('.debt-edit-btn').forEach(function (b) {
			b.addEventListener('click', function () {
				document.getElementById('de_debt_id').value = b.dataset.id;
				document.getElementById('de_title').value = b.dataset.title || '';
				document.getElementById('de_daily').value = b.dataset.daily || 0;
				document.getElementById('de_status').value = b.dataset.status || 'active';
				document.getElementById('de_balance').value = b.dataset.balance || 0;
				document.getElementById('de_balance_wrap').style.display = (b.dataset.amort === '1') ? '' : 'none';
				document.getElementById('de_planned_end').value = b.dataset.plannedEnd || '';
				var isLease = (b.dataset.kind === 'lease');
				document.getElementById('de_planned_end_wrap').classList.toggle('d-none', !isLease);
				document.getElementById('de_lease_wrap').classList.toggle('d-none', !isLease);
				if (isLease) {
					document.getElementById('de_lease_provider').value = b.dataset.provider || 'hq';
					document.getElementById('de_vin').value = b.dataset.vin || '';
					document.getElementById('de_fee_hq').value = b.dataset.feeHq || 0;
					document.getElementById('de_fee_distributor').value = b.dataset.feeDist || 0;
					document.getElementById('de_fee_agency').value = b.dataset.feeAg || 0;
					syncFeeFields('de');
				}
				document.getElementById('debt_edit_alert').className = 'd-none';
				modal('kt_debt_edit_modal').show();
			});
		});
		document.getElementById('de_save').addEventListener('click', function () {
			var btn = this; btn.disabled = true;
			var payload = {
				action: 'update', debt_id: Number(document.getElementById('de_debt_id').value),
				title: document.getElementById('de_title').value.trim(),
				daily_amount: Number(document.getElementById('de_daily').value) || 0,
				status: document.getElementById('de_status').value
			};
			if (document.getElementById('de_balance_wrap').style.display !== 'none') {
				payload.balance_amount = Number(document.getElementById('de_balance').value) || 0;
			}

			if (!document.getElementById('de_lease_wrap').classList.contains('d-none')) {
				payload.lease_provider  = document.getElementById('de_lease_provider').value;
				payload.vin             = document.getElementById('de_vin').value.trim();
				payload.fee_hq          = Number(document.getElementById('de_fee_hq').value) || 0;
				payload.fee_distributor = Number(document.getElementById('de_fee_distributor').value) || 0;
				payload.fee_agency      = Number(document.getElementById('de_fee_agency').value) || 0;
			}
			post(payload).then(function (d) {
				if (d.ok) { location.reload(); } else { setAlert('debt_edit_alert', d.message || '오류'); btn.disabled = false; }
			}).catch(function () { setAlert('debt_edit_alert', '네트워크 오류'); btn.disabled = false; });
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderDebt.php';

$debtApi  = ADMIN_BASE . '/api/debt_action.php';
$riderApi = ADMIN_BASE . '/api/riders.php';
$canWrite = admin_can_write('deduction');
$needsMigrate = !RiderDebt::tableReady();

$filterKind   = trim((string) ($_GET['kind']   ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterQ      = trim((string) ($_GET['q']      ?? ''));

$rows = [];
$kpi  = ['active' => 0, 'balance' => 0, 'lease_daily' => 0, 'closed' => 0, 'lease_no_end' => 0, 'lease_overdue' => 0];

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
    if ($filterQ !== '') {
        $like    = '%' . $filterQ . '%';
        $where[] = '(r.name LIKE ? OR r.rider_code LIKE ? OR d.title LIKE ? OR d.creditor LIKE ?)';
        $params  = array_merge($params, [$like, $like, $like, $like]);
    }
    $whereStr = implode(' AND ', $where);

    $rows = db_rows(
        "SELECT d.*, r.id AS rider_id, r.name AS rider_name, r.rider_code, o.name AS agency_name,
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
            SUM(CASE WHEN d.status = 'active' AND d.kind IN ('loan','advance') THEN d.balance_amount ELSE 0 END) AS balance_sum,
            SUM(CASE WHEN d.status = 'active' AND d.kind = 'lease' THEN d.daily_amount ELSE 0 END) AS lease_daily,
            SUM(CASE WHEN d.status = 'closed' THEN 1 ELSE 0 END) AS closed_cnt,
            SUM(CASE WHEN d.status = 'active' AND d.kind = 'lease' AND (d.opened_on IS NULL OR d.planned_end_on IS NULL) THEN 1 ELSE 0 END) AS lease_no_end,
            SUM(CASE WHEN d.status = 'active' AND d.kind = 'lease' AND d.opened_on IS NOT NULL AND d.planned_end_on IS NOT NULL
                     AND d.opened_on <= CURDATE()
                     AND DATEDIFF(LEAST(CURDATE(), d.planned_end_on), COALESCE(d.due_updated_on, DATE_SUB(d.opened_on, INTERVAL 1 DAY))) >= " . RiderDebt::GAP_WARNING_DAYS . "
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

	<!--begin::Filter-->
	<form method="get" action="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">
		<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL): ?>
		<input type="hidden" name="route" value="deduction/debts" />
		<?php endif; ?>
		<div class="card card-flush mb-8"><div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-4">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="라이더명·코드·항목·채권자" />
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
				<div class="col-md-4 d-flex gap-2 justify-content-md-end">
					<button type="submit" class="btn btn-primary">필터 적용</button>
					<a href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">초기화</a>
				</div>
			</div>
		</div></div>
	</form>
	<!--end::Filter-->

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold m-0">미수금 목록 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format(count($rows)) ?>건</span></h3>
		</div>
		<div class="card-body pt-2">
			<?php if (empty($rows)): ?>
			<div class="text-center text-gray-500 py-10">
				<?= ($filterQ !== '' || $filterKind !== '' || $filterStatus !== '') ? '조건에 맞는 미수금이 없습니다.' : '등록된 미수금(대여금·리스·선지급)이 없습니다. 우측 상단 「미수금 등록」으로 추가하세요.' ?>
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
							<th class="min-w-90px">채권자</th>
							<th class="min-w-140px">계약기간(리스)</th>
							<th class="min-w-90px">미납갱신</th>
							<th class="min-w-80px">상태</th>
							<th class="text-end min-w-140px">관리</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $d):
							$dk = (string) $d['kind'];
							$isAmort = in_array($dk, ['loan', 'advance'], true);
						?>
						<tr>
							<td>
								<a href="<?= htmlspecialchars($riderDetailBase . (int) $d['rider_id'], ENT_QUOTES, 'UTF-8') ?>" class="fw-bold text-gray-900 text-hover-primary"><?= htmlspecialchars((string) $d['rider_name'], ENT_QUOTES, 'UTF-8') ?></a>
								<div class="text-muted fs-8 font-monospace"><?= htmlspecialchars((string) $d['rider_code'], ENT_QUOTES, 'UTF-8') ?></div>
							</td>
							<td>
								<span class="badge badge-light-<?= $kindBadge[$dk] ?? 'secondary' ?> mb-1"><?= htmlspecialchars(RiderDebt::kindLabel($dk), ENT_QUOTES, 'UTF-8') ?></span>
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
							<td class="text-gray-700 fs-7"><?= htmlspecialchars((string) ($d['creditor'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-8">
								<?php $gap = $dk === "lease" ? RiderDebt::leaseAccrualGap($d) : null; ?>
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
				리스/렌탈은 개시일~계약 종료 예정일이 설정되어 있으면, 업로드된 정산기간과 겹치는 일수만큼 자동 계산되어 차감됩니다(같은 기간 재업로드해도 중복 차감되지 않습니다).
				대여금·선지급금은 자동 계산이 없어 「차감」 버튼으로 직접 실행해야 합니다.
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
					<div class="input-group">
						<input type="text" class="form-control form-control-sm form-control-solid" id="dn_rider_q" placeholder="이름 또는 라이더코드" />
						<button class="btn btn-sm btn-light-primary" type="button" id="dn_rider_search">검색</button>
					</div>
					<select class="form-select form-select-sm form-select-solid mt-2 d-none" id="dn_rider_sel" size="4"></select>
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
						<label class="form-label fs-7 fw-semibold">원금(대여금·선지급)</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dn_principal" min="0" step="1000" placeholder="예: 1250000" />
						<div class="form-text fs-8">남은 잔액의 시작값. 리스는 불필요.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">일납금액</label>
						<input type="number" class="form-control form-control-sm form-control-solid" id="dn_daily" min="0" step="100" placeholder="예: 24000" />
						<div class="form-text fs-8">차감 시 일납×일수로 자동 계산.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">채권자/구분</label>
						<input type="text" class="form-control form-control-sm form-control-solid" id="dn_creditor" maxlength="120" placeholder="예: 본사, XX리스" />
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">개시일/출고일</label>
						<input type="date" class="form-control form-control-sm form-control-solid" id="dn_opened" />
					</div>
					<div class="col-md-6 d-none" id="dn_planned_end_wrap">
						<label class="form-label fs-7 fw-semibold">계약 종료 예정일</label>
						<input type="date" class="form-control form-control-sm form-control-solid" id="dn_planned_end" />
						<div class="form-text fs-8">개시일과 함께 계약기간을 이뤄, 정산 반영 시 이 기간과 겹치는 일수만큼 자동 차감됩니다. 비워두면 자동 차감이 되지 않아 수동으로 차감해야 합니다.</div>
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
										<div class="input-group input-group-sm">
											<span class="input-group-text">본사</span>
											<input type="number" class="form-control form-control-solid dn-fee" id="dn_fee_hq" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4 d-none" id="dn_fee_dist_wrap">
										<div class="input-group input-group-sm">
											<span class="input-group-text">총판</span>
											<input type="number" class="form-control form-control-solid dn-fee" id="dn_fee_distributor" min="0" step="10" value="0" />
											<span class="input-group-text">원</span>
										</div>
									</div>
									<div class="col-md-4" id="dn_fee_ag_wrap">
										<div class="input-group input-group-sm">
											<span class="input-group-text">대리점</span>
											<input type="number" class="form-control form-control-solid dn-fee" id="dn_fee_agency" min="0" step="10" value="0" />
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
						<label class="form-label fs-7 fw-semibold">계약 종료 예정일(리스)</label>
						<input type="date" class="form-control form-control-sm form-control-solid" id="de_planned_end" />
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
										<div class="input-group input-group-sm">
											<span class="input-group-text">본사</span>
											<input type="number" class="form-control form-control-solid de-fee" id="de_fee_hq" min="0" step="10" value="0" />
										</div>
									</div>
									<div class="col-md-4 d-none" id="de_fee_dist_wrap">
										<div class="input-group input-group-sm">
											<span class="input-group-text">총판</span>
											<input type="number" class="form-control form-control-solid de-fee" id="de_fee_distributor" min="0" step="10" value="0" />
										</div>
									</div>
									<div class="col-md-4" id="de_fee_ag_wrap">
										<div class="input-group input-group-sm">
											<span class="input-group-text">대리점</span>
											<input type="number" class="form-control form-control-solid de-fee" id="de_fee_agency" min="0" step="10" value="0" />
										</div>
									</div>
								</div>
								<div class="form-text fs-8" id="de_fee_hint"></div>
							</div>
						</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fs-7 fw-semibold">채권자/구분</label>
						<input type="text" class="form-control form-control-sm form-control-solid" id="de_creditor" maxlength="120" />
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

		function syncPrincipal() {
			var isLease = (kindEl.value === 'lease');
			document.getElementById('dn_principal_wrap').style.display = isLease ? 'none' : '';
			document.getElementById('dn_planned_end_wrap').classList.toggle('d-none', !isLease);
			document.getElementById('dn_lease_wrap').classList.toggle('d-none', !isLease);
			if (isLease) { syncFeeFields('dn'); }
		}
		kindEl.addEventListener('change', syncPrincipal);
		document.getElementById('dn_lease_provider').addEventListener('change', function () { syncFeeFields('dn'); });
		document.getElementById('de_lease_provider').addEventListener('change', function () { syncFeeFields('de'); });
		['dn_daily', 'dn_fee_hq', 'dn_fee_distributor', 'dn_fee_agency'].forEach(function (i) {
			document.getElementById(i).addEventListener('input', function () { syncFeeHint('dn'); });
		});
		['de_daily', 'de_fee_hq', 'de_fee_distributor', 'de_fee_agency'].forEach(function (i) {
			document.getElementById(i).addEventListener('input', function () { syncFeeHint('de'); });
		});

		document.getElementById('btn_debt_new').addEventListener('click', function () {
			['dn_title', 'dn_principal', 'dn_daily', 'dn_creditor', 'dn_opened', 'dn_planned_end', 'dn_note', 'dn_rider_q', 'dn_rider_id', 'dn_vin'].forEach(function (i) { document.getElementById(i).value = ''; });
			['dn_fee_hq', 'dn_fee_distributor', 'dn_fee_agency'].forEach(function (i) { document.getElementById(i).value = 0; });
			document.getElementById('dn_lease_provider').value = 'hq';
			document.getElementById('dn_rider_picked').textContent = '';
			document.getElementById('dn_rider_sel').classList.add('d-none');
			kindEl.value = 'loan'; syncPrincipal();
			document.getElementById('debt_new_alert').className = 'd-none';
			modal('kt_debt_new_modal').show();
		});
		document.getElementById('dn_rider_search').addEventListener('click', function () {
			var q = document.getElementById('dn_rider_q').value.trim();
			fetch(RIDER_API + '?q=' + encodeURIComponent(q) + '&limit=20', { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					var sel = document.getElementById('dn_rider_sel');
					sel.innerHTML = '';
					(res.items || []).forEach(function (it) {
						var o = document.createElement('option');
						o.value = it.id; o.textContent = it.name + ' (' + it.rider_code + ')';
						sel.appendChild(o);
					});
					sel.classList.toggle('d-none', (res.items || []).length === 0);
					if ((res.items || []).length === 0) { setAlert('debt_new_alert', '검색 결과가 없습니다.', 'warning'); }
				});
		});
		document.getElementById('dn_rider_sel').addEventListener('change', function () {
			document.getElementById('dn_rider_id').value = this.value;
			document.getElementById('dn_rider_picked').textContent = '선택: ' + this.options[this.selectedIndex].textContent;
		});
		document.getElementById('dn_save').addEventListener('click', function () {
			var rid = parseInt(document.getElementById('dn_rider_id').value, 10) || 0;
			if (!rid) { setAlert('debt_new_alert', '라이더를 검색해서 선택하세요.'); return; }
			var btn = this; btn.disabled = true;
			post({
				action: 'create', rider_id: rid, kind: kindEl.value,
				title: document.getElementById('dn_title').value.trim(),
				principal_amount: Number(document.getElementById('dn_principal').value) || 0,
				daily_amount: Number(document.getElementById('dn_daily').value) || 0,
				creditor: document.getElementById('dn_creditor').value.trim(),
				opened_on: document.getElementById('dn_opened').value,
				planned_end_on: document.getElementById('dn_planned_end').value,
				lease_provider: document.getElementById('dn_lease_provider').value,
				vin: document.getElementById('dn_vin').value.trim(),
				fee_hq: Number(document.getElementById('dn_fee_hq').value) || 0,
				fee_distributor: Number(document.getElementById('dn_fee_distributor').value) || 0,
				fee_agency: Number(document.getElementById('dn_fee_agency').value) || 0,
				note: document.getElementById('dn_note').value.trim()
			}).then(function (d) {
				if (d.ok) { location.reload(); } else { setAlert('debt_new_alert', d.message || '오류'); btn.disabled = false; }
			}).catch(function () { setAlert('debt_new_alert', '네트워크 오류'); btn.disabled = false; });
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
				document.getElementById('de_creditor').value = b.dataset.creditor || '';
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
				creditor: document.getElementById('de_creditor').value.trim(),
				status: document.getElementById('de_status').value
			};
			if (document.getElementById('de_balance_wrap').style.display !== 'none') {
				payload.balance_amount = Number(document.getElementById('de_balance').value) || 0;
			}
			if (!document.getElementById('de_planned_end_wrap').classList.contains('d-none')) {
				payload.planned_end_on = document.getElementById('de_planned_end').value;
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

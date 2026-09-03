<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyWallet.php';
require_once INC_PATH . '/Org.php';

$won = static fn (int $n): string => number_format($n) . '원';
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
$filterOrg  = (int) ($_GET['org'] ?? 0);
$filterDir  = trim((string) ($_GET['direction'] ?? ''));
$filterReason = trim((string) ($_GET['reason'] ?? ''));

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}
if ($filterDir !== 'credit' && $filterDir !== 'debit') {
    $filterDir = '';
}
if ($filterReason !== '' && !isset(AgencyWallet::REASON_LABELS[$filterReason])) {
    $filterReason = '';
}

$level = admin_org_level();
// 대리점·세무대리는 자기 지갑만 본다(단일 조직 뷰).
$isAgencyLevel = $level === Org::LEVEL_AGENCY || $level === Org::LEVEL_TAX_AGENT;
if ($isAgencyLevel) {
    $filterOrg = admin_org_id();
}
if ($filterOrg > 0 && !Org::canAccessOrg($filterOrg)) {
    $filterOrg = 0;
}

const WALLET_LEDGER_ROW_CAP = 500;

$filters = [
    'from'      => $filterFrom,
    'to'        => $filterTo,
    'org_id'    => $filterOrg,
    'direction' => $filterDir,
    'reason'    => $filterReason,
];

$listError = null;
$rows = [];
$sum = ['count' => 0, 'credit' => 0, 'debit' => 0];
$orgOptions = [];
$needsMigrate = !AgencyWallet::tableExists() || !db_table_exists('agency_wallet_ledger');

if (!$needsMigrate) {
    try {
        $orgOptions = AgencyWallet::orgFilterOptions();
        $sum  = AgencyWallet::sumLedgerScoped($filters);
        $rows = AgencyWallet::listLedgerScoped($filters + ['limit' => WALLET_LEDGER_ROW_CAP]);
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

$selectedWallet = null;
if (!$needsMigrate && $filterOrg > 0) {
    $w = AgencyWallet::withdrawable($filterOrg);
    $selectedOrg = Org::find($filterOrg);
    $selectedWallet = [
        'name'                => (string) ($selectedOrg['name'] ?? ''),
        'level_label'         => Org::levelLabel((string) ($selectedOrg['level'] ?? '')),
        'balance'             => $w['balance'],
        'rider_debt'          => $w['rider_debt'],
        'withholding_reserve' => $w['withholding_reserve'],
        'withdrawable'        => $w['withdrawable'],
        'is_agency'           => (($selectedOrg['level'] ?? '') === Org::LEVEL_AGENCY),
    ];
}
$scopedBalance = 0;
foreach ($orgOptions as $opt) {
    $scopedBalance += (int) $opt['balance'];
}

$listUrl = admin_url('withdrawal/wallet-ledger');

$quickRanges = [
    '오늘'      => [date('Y-m-d'), date('Y-m-d')],
    '최근 7일'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '이번 달'   => [date('Y-m-01'), date('Y-m-d')],
    '지난 달'   => [date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];

$levelBadge = static function (string $lvl): string {
    return match ($lvl) {
        Org::LEVEL_ADMIN       => 'badge-light-primary',
        Org::LEVEL_DISTRIBUTOR => 'badge-light-info',
        Org::LEVEL_AGENCY      => 'badge-light-secondary',
        default                => 'badge-light',
    };
};

$wallet_ledger_range_url = static function (
    string $base,
    string $from,
    string $to,
    int $orgId,
    string $dir,
    string $reason
): string {
    $sep = str_contains($base, '?') ? '&' : '?';
    $query = array_filter([
        'from'      => $from,
        'to'        => $to,
        'org'       => $orgId > 0 ? $orgId : null,
        'direction' => $dir !== '' ? $dir : null,
        'reason'    => $reason !== '' ? $reason : null,
    ], static fn ($v) => $v !== null && $v !== '');

    return $base . $sep . http_build_query($query);
};

$net = $sum['credit'] - $sum['debit'];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">지갑 입출금</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">지갑 입출금</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= $esc($listError) ?></div>
	<?php else : ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-wallet fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			본사·총판·대리점 지갑(<code>agency_wallets</code>)의 입출금 원장입니다.
			PG 정산 조달, 플랫폼 수수료 수입, 라이더 지급, 자체 인출, 리스 수수료 이동, 수동 조정이 모두 여기에 쌓입니다.
			본사·총판은 하위 조직 지갑까지 조회할 수 있습니다.
		</div>
	</div>

	<div class="card card-flush mb-6">
		<div class="card-body py-5">
			<form method="get" action="<?= $esc($listUrl) ?>" class="row g-3 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="withdrawal/wallet-ledger" />
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">시작일</label>
					<input type="date" name="from" class="form-control form-control-sm" value="<?= $esc($filterFrom) ?>" />
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">종료일</label>
					<input type="date" name="to" class="form-control form-control-sm" value="<?= $esc($filterTo) ?>" />
				</div>
				<?php if (!$isAgencyLevel) : ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">조직</label>
					<select name="org" class="form-select form-select-sm" style="min-width:200px">
						<option value="0">전체</option>
						<?php foreach ($orgOptions as $ao) : ?>
						<option value="<?= (int) $ao['id'] ?>" <?= $filterOrg === (int) $ao['id'] ? 'selected' : '' ?>>
							[<?= $esc($ao['level_label']) ?>] <?= $esc($ao['name']) ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">구분</label>
					<select name="direction" class="form-select form-select-sm">
						<option value="">전체</option>
						<option value="credit" <?= $filterDir === 'credit' ? 'selected' : '' ?>>입금</option>
						<option value="debit" <?= $filterDir === 'debit' ? 'selected' : '' ?>>출금</option>
					</select>
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">유형</label>
					<select name="reason" class="form-select form-select-sm" style="min-width:170px">
						<option value="">전체</option>
						<?php foreach (AgencyWallet::REASON_LABELS as $code => $label) : ?>
						<option value="<?= $esc($code) ?>" <?= $filterReason === $code ? 'selected' : '' ?>><?= $esc($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-auto">
					<button type="submit" class="btn btn-sm btn-primary">조회</button>
				</div>
				<div class="col-auto d-flex flex-wrap gap-1">
					<?php foreach ($quickRanges as $label => [$qf, $qt]) :
					    $active = $filterFrom === $qf && $filterTo === $qt; ?>
					<a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
						href="<?= $esc($wallet_ledger_range_url($listUrl, $qf, $qt, $filterOrg, $filterDir, $filterReason)) ?>">
						<?= $esc($label) ?>
					</a>
					<?php endforeach; ?>
				</div>
			</form>
		</div>
	</div>

	<div class="row g-5 g-xl-8 mb-6">
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1"><?= $selectedWallet !== null ? '현재 잔액' : '스코프 잔액 합계' ?></div>
					<div class="fs-2 fw-bold text-gray-800"><?= $won($selectedWallet !== null ? $selectedWallet['balance'] : $scopedBalance) ?></div>
					<div class="fs-8 text-muted mt-1">
						<?php if ($selectedWallet !== null) : ?>
							<?= $esc($selectedWallet['level_label'] . ' · ' . $selectedWallet['name']) ?>
						<?php else : ?>
							조직 <?= number_format(count($orgOptions)) ?>곳 (거래 없는 조직은 0원)
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">기간 입금</div>
					<div class="fs-2 fw-bold text-success">+<?= $won($sum['credit']) ?></div>
					<div class="fs-8 text-muted mt-1"><?= number_format($sum['count']) ?>건 중 credit</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">기간 출금</div>
					<div class="fs-2 fw-bold text-danger">−<?= $won($sum['debit']) ?></div>
					<div class="fs-8 text-muted mt-1"><?= number_format($sum['count']) ?>건 중 debit</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100 border border-primary">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">기간 순증감</div>
					<div class="fs-2 fw-bold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($net >= 0 ? '+' : '−') . $won(abs($net)) ?></div>
					<div class="fs-8 text-muted mt-1">입금 − 출금</div>
				</div>
			</div>
		</div>
	</div>

	<?php if ($selectedWallet !== null && $selectedWallet['is_agency']) : ?>
	<div class="card card-flush mb-6">
		<div class="card-body py-5 d-flex flex-wrap gap-8 fs-7">
			<div><span class="text-muted">라이더 정산금</span> <span class="fw-bold ms-2"><?= $won($selectedWallet['rider_debt']) ?></span></div>
			<div><span class="text-muted">원천세 예수금</span> <span class="fw-bold ms-2"><?= $won($selectedWallet['withholding_reserve']) ?></span></div>
			<div><span class="text-muted">인출가능액</span> <span class="fw-bold text-primary ms-2"><?= $won($selectedWallet['withdrawable']) ?></span></div>
		</div>
	</div>
	<?php endif; ?>

	<?php if (!$isAgencyLevel && $filterOrg === 0 && $orgOptions !== []) : ?>
	<div class="card card-flush mb-6">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">조직별 현재 잔액</h3>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
					<thead>
						<tr class="fw-bold text-muted">
							<th>조직</th>
							<th>구분</th>
							<th class="text-end">잔액</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($orgOptions as $ao) :
						    $orgLink = $wallet_ledger_range_url($listUrl, $filterFrom, $filterTo, (int) $ao['id'], $filterDir, $filterReason);
						    ?>
						<tr>
							<td class="fw-semibold"><?= $esc($ao['name']) ?></td>
							<td><span class="badge <?= $esc($levelBadge($ao['level'])) ?>"><?= $esc($ao['level_label']) ?></span></td>
							<td class="text-end fw-bold"><?= $won((int) $ao['balance']) ?></td>
							<td class="text-end"><a class="btn btn-sm btn-light" href="<?= $esc($orgLink) ?>">내역</a></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">입출금 원장</h3>
			<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">최근 <?= WALLET_LEDGER_ROW_CAP ?>건까지 표시 · 합계는 조회 기간 전체 기준</span>
		</div>
		<div class="card-body pt-0">
			<?php if ($rows === []) : ?>
			<p class="text-muted fs-7 py-10 mb-0 text-center">조회 결과가 없습니다.</p>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle fs-7 gy-2" id="walletLedgerTable">
					<thead>
						<tr class="fw-bold text-muted">
							<th>일시</th>
							<?php if (!$isAgencyLevel) : ?><th>조직</th><?php endif; ?>
							<th>구분</th>
							<th>유형</th>
							<th class="text-end">금액</th>
							<th class="text-end">거래 후 잔액</th>
							<th>메모</th>
							<th>처리자</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) :
						    $isCredit = $r['direction'] === 'credit';
						    ?>
						<tr>
							<td class="text-gray-700 text-nowrap"><?= $esc((string) $r['created_at']) ?></td>
							<?php if (!$isAgencyLevel) : ?>
							<td>
								<span class="fw-semibold"><?= $esc((string) $r['org_name']) ?></span>
								<span class="badge <?= $esc($levelBadge((string) $r['org_level'])) ?> ms-1"><?= $esc((string) $r['org_level_label']) ?></span>
							</td>
							<?php endif; ?>
							<td>
								<span class="badge <?= $isCredit ? 'badge-light-success' : 'badge-light-danger' ?>"><?= $esc((string) $r['direction_label']) ?></span>
							</td>
							<td><?= $esc((string) $r['reason_label']) ?></td>
							<td class="text-end fw-bold <?= $isCredit ? 'text-success' : 'text-danger' ?>">
								<?= $isCredit ? '+' : '−' ?><?= $won((int) $r['amount']) ?>
							</td>
							<td class="text-end"><?= $won((int) $r['balance_after']) ?></td>
							<td class="text-muted"><?= $esc((string) $r['note'] !== '' ? (string) $r['note'] : '—') ?></td>
							<td class="text-muted"><?= $esc((string) $r['actor_name'] !== '' ? (string) $r['actor_name'] : '시스템') ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr class="fw-bold bg-light">
							<td colspan="<?= $isAgencyLevel ? 3 : 4 ?>">기간 합계 <span class="text-muted fs-8 fw-normal">(<?= number_format($sum['count']) ?>건 전체)</span></td>
							<td class="text-end">
								<span class="text-success">+<?= $won($sum['credit']) ?></span>
								<span class="text-muted mx-1">/</span>
								<span class="text-danger">−<?= $won($sum['debit']) ?></span>
							</td>
							<td colspan="3"></td>
						</tr>
					</tfoot>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		var walletLedgerTable = document.getElementById('walletLedgerTable');
		if (walletLedgerTable) {
			initTablePaginate(walletLedgerTable, { pageSize: 30 });
		}
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

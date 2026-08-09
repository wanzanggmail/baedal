<?php

declare(strict_types=1);

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$needsMigrate = !db_table_exists('settlement_order_details');

$filterFrom = trim((string) ($_GET['date_from'] ?? ''));
$filterTo   = trim((string) ($_GET['date_to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}
if ($filterFrom > $filterTo) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
$filterPlatform = trim((string) ($_GET['platform'] ?? 'all'));
if (!isset($platformLabels[$filterPlatform])) {
    $filterPlatform = 'all';
}

const RIDER_ORDER_LIST_CAP = 300;

$rows      = [];
$listTotal = 0;
$listCount = 0;
$truncated = false;

if ($riderId > 0 && !$needsMigrate) {
    $where  = ['od.rider_id = ?', 'od.settlement_date >= ?', 'od.settlement_date <= ?'];
    $params = [$riderId, $filterFrom, $filterTo];
    if ($filterPlatform !== 'all') {
        $where[]  = 'u.platform = ?';
        $params[] = $filterPlatform;
    }
    $whereSql = implode(' AND ', $where);

    $sumRow = db_row(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(od.net_amount), 0) AS total
           FROM settlement_order_details od
           INNER JOIN settlement_uploads u ON u.id = od.upload_id
          WHERE {$whereSql}",
        $params
    );
    $listCount = (int) ($sumRow['cnt'] ?? 0);
    $listTotal = (int) ($sumRow['total'] ?? 0);
    $truncated = $listCount > RIDER_ORDER_LIST_CAP;

    $rows = db_rows(
        "SELECT od.settlement_date, od.store_name, od.pickup_area, od.delivered_at, od.net_amount, u.platform
           FROM settlement_order_details od
           INNER JOIN settlement_uploads u ON u.id = od.upload_id
          WHERE {$whereSql}
          ORDER BY od.settlement_date DESC, od.delivered_at DESC, od.id DESC
          LIMIT " . RIDER_ORDER_LIST_CAP,
        $params
    );
}

// 날짜별로 묶어서 표시 — 하루에 여러 건이라 평평한 목록이면 어느 날짜인지 알기 어렵다.
$byDate = [];
foreach ($rows as $row) {
    $byDate[(string) $row['settlement_date']][] = $row;
}

$weekdayKr = ['일', '월', '화', '수', '목', '금', '토'];
$fmtDateKr = static function (string $ymd) use ($weekdayKr): string {
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return date('n월 j일', $ts) . ' (' . $weekdayKr[(int) date('w', $ts)] . ')';
};
$fmtWon = static fn (int $n): string => number_format($n) . '원';

$formAction = htmlspecialchars(rider_url('settlement/list'), ENT_QUOTES, 'UTF-8');
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산 목록</h2>
		<span class="text-gray-500 fs-7">기간·플랫폼으로 건별 배달 내역을 검색합니다.</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($needsMigrate) : ?>
		<p class="text-muted fs-7 py-6 mb-0">정산 목록을 준비 중입니다.</p>
		<?php else : ?>

		<form method="get" action="<?= $formAction ?>" class="mb-6">
			<?php if (defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL) : ?>
			<input type="hidden" name="route" value="settlement/list" />
			<?php endif; ?>
			<div class="row g-3">
				<div class="col-6">
					<label class="form-label fs-7" for="rider_settle_date_from">시작일</label>
					<input type="date" name="date_from" id="rider_settle_date_from" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-6">
					<label class="form-label fs-7" for="rider_settle_date_to">종료일</label>
					<input type="date" name="date_to" id="rider_settle_date_to" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-12">
					<label class="form-label fs-7" for="rider_settle_platform">플랫폼</label>
					<select name="platform" id="rider_settle_platform" class="form-select form-select-sm form-select-solid">
						<option value="all"<?= $filterPlatform === 'all' ? ' selected' : '' ?>>전체</option>
						<?php foreach ($platformLabels as $pv => $pl) : ?>
						<option value="<?= htmlspecialchars($pv, ENT_QUOTES, 'UTF-8') ?>"<?= $filterPlatform === $pv ? ' selected' : '' ?>><?= htmlspecialchars($pl, ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-12">
					<button type="submit" class="btn btn-primary w-100">검색</button>
				</div>
			</div>
		</form>

		<div class="separator my-4"></div>

		<?php if ($rows === []) : ?>
			<div class="text-center py-10 px-4 bg-light rounded-3 border border-gray-200">
				<p class="text-gray-700 fw-semibold mb-1">조건에 맞는 정산 내역이 없습니다.</p>
				<p class="text-gray-500 fs-7 mb-0">기간이나 플랫폼을 바꿔 검색해 보세요.</p>
			</div>
		<?php else : ?>
			<?php if ($truncated) : ?>
			<div class="alert bg-light-warning fs-8 p-3 mb-4">건수가 많아 최근 <?= RIDER_ORDER_LIST_CAP ?>건까지만 표시합니다(합계는 조건 전체 기준). 기간을 좁혀서 검색해 보세요.</div>
			<?php endif; ?>

			<div class="rider-settle-list border border-gray-200 rounded-3 overflow-hidden bg-body">
				<?php foreach ($byDate as $date => $dayRows) :
				    $dayTotal = 0;
				    foreach ($dayRows as $r) { $dayTotal += (int) $r['net_amount']; }
				    ?>
				<div class="d-flex align-items-center justify-content-between gap-2 px-3 py-2 bg-light-primary border-bottom border-gray-200">
					<span class="fw-bold fs-8 text-gray-800"><?= htmlspecialchars($fmtDateKr($date), ENT_QUOTES, 'UTF-8') ?> <span class="text-muted fw-normal">· <?= count($dayRows) ?>건</span></span>
					<span class="fw-bold fs-8 text-primary tabular-nums">₩<?= number_format($dayTotal) ?></span>
				</div>
				<?php foreach ($dayRows as $row) :
				    $storeName = (string) ($row['store_name'] ?: ($row['pickup_area'] ?: '주문'));
				    $timeStr   = $row['delivered_at'] ? substr((string) $row['delivered_at'], 11, 5) : '';
				    $pf        = $platformLabels[(string) $row['platform']] ?? (string) $row['platform'];
				    ?>
				<div class="rider-settle-list-row d-flex align-items-center gap-2 px-3 py-2 fs-7 text-gray-900 border-bottom border-gray-200">
					<span class="badge badge-light-primary fs-9 flex-shrink-0"><?= htmlspecialchars($pf, ENT_QUOTES, 'UTF-8') ?></span>
					<span class="flex-grow-1 min-w-0 text-truncate"><?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></span>
					<span class="flex-shrink-0 text-gray-600 text-nowrap tabular-nums" style="width: 3.25rem;"><?= htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8') ?></span>
					<span class="flex-shrink-0 fw-bold text-gray-900 text-end tabular-nums" style="min-width: 5.5rem;">₩<?= number_format((int) $row['net_amount']) ?></span>
				</div>
				<?php endforeach; ?>
				<?php endforeach; ?>
				<div class="d-flex align-items-center justify-content-between gap-2 px-3 py-3 bg-light border-top border-gray-300">
					<span class="fw-bold text-gray-900">합계 <span class="text-gray-500 fw-semibold fs-8">(<?= number_format($listCount) ?>건)</span></span>
					<span class="fs-4 fw-bold text-primary tabular-nums">₩ <?= number_format($listTotal) ?></span>
				</div>
			</div>
		<?php endif; ?>

		<div class="mt-5">
			<a href="<?= htmlspecialchars(rider_url('settlement/calendar'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light-primary w-100">달력 보기</a>
		</div>

		<?php endif; ?>
	</div>
</div>

<?php

declare(strict_types=1);

$tz = new DateTimeZone('Asia/Seoul');
$today = new DateTimeImmutable('now', $tz);
$monthStart = $today->modify('first day of this month');

$dateFromIn = trim((string) ($_GET['date_from'] ?? ''));
$dateToIn = trim((string) ($_GET['date_to'] ?? ''));
$platformIn = trim((string) ($_GET['platform'] ?? 'all'));

$dateFrom = $dateFromIn !== '' ? $dateFromIn : $monthStart->format('Y-m-d');
$dateTo = $dateToIn !== '' ? $dateToIn : $today->format('Y-m-d');

if ($dateFrom > $dateTo) {
	$tmp = $dateFrom;
	$dateFrom = $dateTo;
	$dateTo = $tmp;
}

$platformKeys = ['all', 'baemin', 'yogiyo', 'coupang'];
if (!in_array($platformIn, $platformKeys, true)) {
	$platformIn = 'all';
}

$platformDefs = [
	'baemin' => '배달의민족',
	'yogiyo' => '요기요',
	'coupang' => '쿠팡이츠',
];

$mockStoreNames = [
	'교촌치킨 강남점',
	'맥도날드 역삼점',
	'본죽&비빔밥cafe 서초점',
	'스타벅스 테헤란로점',
	'도미노피자 논현점',
	'버거킹 신논현점',
	'BHC 삼성점',
	'써브웨이 선릉점',
	'네네치킨 잠실점',
	'한솥도시락 송파점',
	'공차 가락점',
	'미스터피자 방이점',
	'또래오래 위례점',
	'멕시카나 중계점',
	'파리바게뜨 월계점',
];

/** 정산 일자별 목록 목업: 최근 90일·일 1건 (실연동 시 API/DB) */
$riderSettlementListMock = [];
$mockStart = $today->modify('-90 days');
$cursor = $mockStart;
$platformCycle = ['baemin', 'yogiyo', 'coupang', 'baemin'];
$i = 0;
while ($cursor <= $today) {
	$p = $platformCycle[$i % count($platformCycle)];
	$seed = (int) $cursor->format('Ymd');
	$amount = 155000 + ($seed % 97) * 900;
	$storeIdx = ($seed + $i * 17) % count($mockStoreNames);
	$store = $mockStoreNames[$storeIdx];
	$minOfDay = (($seed * 11 + $i * 23) % (11 * 60)) + (10 * 60);
	$deliveryTime = sprintf('%02d:%02d', intdiv($minOfDay, 60), $minOfDay % 60);
	$riderSettlementListMock[] = [
		'date' => $cursor->format('Y-m-d'),
		'platform' => $p,
		'label' => $platformDefs[$p],
		'store' => $store,
		'delivery_time' => $deliveryTime,
		'amount' => $amount,
		'status' => '확정',
	];
	$i++;
	$cursor = $cursor->modify('+1 day');
}

$filtered = [];
foreach ($riderSettlementListMock as $row) {
	if ($row['date'] < $dateFrom || $row['date'] > $dateTo) {
		continue;
	}
	if ($platformIn !== 'all' && $row['platform'] !== $platformIn) {
		continue;
	}
	$filtered[] = $row;
}

$listTotal = 0;
foreach ($filtered as $row) {
	$listTotal += (int) $row['amount'];
}

$formAction = htmlspecialchars(rider_url('settlement/list'), ENT_QUOTES, 'UTF-8');
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산 목록</h2>
		<span class="text-gray-500 fs-7">기간·플랫폼으로 검색합니다.</span>
	</div>
	<div class="card-body pt-0">
		<form method="get" action="<?= $formAction ?>" class="mb-6">
			<div class="row g-3">
				<div class="col-6">
					<label class="form-label fs-7" for="rider_settle_date_from">시작일</label>
					<input type="date" name="date_from" id="rider_settle_date_from" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-6">
					<label class="form-label fs-7" for="rider_settle_date_to">종료일</label>
					<input type="date" name="date_to" id="rider_settle_date_to" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-12">
					<label class="form-label fs-7" for="rider_settle_platform">플랫폼</label>
					<select name="platform" id="rider_settle_platform" class="form-select form-select-sm form-select-solid">
						<option value="all"<?= $platformIn === 'all' ? ' selected' : '' ?>>전체</option>
						<option value="baemin"<?= $platformIn === 'baemin' ? ' selected' : '' ?>>배달의민족</option>
						<option value="yogiyo"<?= $platformIn === 'yogiyo' ? ' selected' : '' ?>>요기요</option>
						<option value="coupang"<?= $platformIn === 'coupang' ? ' selected' : '' ?>>쿠팡이츠</option>
					</select>
				</div>
				<div class="col-12">
					<button type="submit" class="btn btn-primary w-100">검색</button>
				</div>
			</div>
		</form>

		<div class="separator my-4"></div>

		<?php if ($filtered === []) : ?>
			<div class="text-center py-10 px-4 bg-light rounded-3 border border-gray-200">
				<p class="text-gray-700 fw-semibold mb-1">조건에 맞는 정산 내역이 없습니다.</p>
				<p class="text-gray-500 fs-7 mb-0">기간이나 플랫폼을 바꿔 검색해 보세요.</p>
			</div>
		<?php else : ?>
			<div class="rider-settle-list border border-gray-200 rounded-3 overflow-hidden bg-body">
				<?php foreach ($filtered as $row) : ?>
					<?php
					$storeName = (string) ($row['store'] ?? $row['store_name'] ?? '');
					$timeStr = (string) ($row['delivery_time'] ?? $row['delivered_at'] ?? '');
					?>
					<a href="<?= htmlspecialchars(rider_url('settlement/detail'), ENT_QUOTES, 'UTF-8') ?>" class="rider-settle-list-row d-flex align-items-center gap-2 px-3 py-2 fs-7 text-gray-900 text-decoration-none border-bottom border-gray-200 bg-hover-light">
						<span class="flex-grow-1 min-w-0 text-truncate fw-semibold"><?= htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8') ?></span>
						<span class="flex-shrink-0 text-gray-600 text-nowrap tabular-nums" style="width: 3.25rem;"><?= htmlspecialchars($timeStr, ENT_QUOTES, 'UTF-8') ?></span>
						<span class="flex-shrink-0 fw-bold text-gray-900 text-end tabular-nums" style="min-width: 5.5rem;">₩<?= htmlspecialchars(number_format((int) $row['amount']), ENT_QUOTES, 'UTF-8') ?></span>
					</a>
				<?php endforeach; ?>
				<div class="d-flex align-items-center justify-content-between gap-2 px-3 py-3 bg-light border-top border-gray-300">
					<span class="fw-bold text-gray-900">합계 <span class="text-gray-500 fw-semibold fs-8">(<?= count($filtered) ?>건)</span></span>
					<span class="fs-4 fw-bold text-primary tabular-nums">₩ <?= htmlspecialchars(number_format($listTotal), ENT_QUOTES, 'UTF-8') ?></span>
				</div>
			</div>
		<?php endif; ?>

		<div class="mt-5">
			<a href="<?= htmlspecialchars(rider_url('settlement/calendar'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light-primary w-100">달력 보기</a>
		</div>
	</div>
</div>

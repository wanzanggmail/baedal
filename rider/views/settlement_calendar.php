<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

/** 정산 반영(지갑 적립) 완료된 사이클만 표시 — settlement_fees.php와 동일 기준 */
$calendarData = [];
if ($riderId > 0 && SettlementLedger::tableExists()) {
    $cycles = db_rows(
        'SELECT id, settlement_date, net_amount FROM settlement_rider_cycles WHERE rider_id = ? ORDER BY settlement_date',
        [$riderId]
    );
    if ($cycles !== []) {
        $dates   = array_column($cycles, 'settlement_date');
        $minDate = min($dates);
        $maxDate = max($dates);
        $orderRows = db_rows(
            'SELECT settlement_date, store_name, pickup_area, net_amount
               FROM settlement_order_details
              WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?
              ORDER BY delivered_at ASC',
            [$riderId, $minDate, $maxDate]
        );
        $itemsByDate = [];
        foreach ($orderRows as $o) {
            $d = (string) $o['settlement_date'];
            $storeName = (string) ($o['store_name'] ?: ($o['pickup_area'] ?: '주문'));
            $itemsByDate[$d][] = ['store' => $storeName, 'amount' => (int) $o['net_amount']];
        }
        foreach ($cycles as $c) {
            $d = (string) $c['settlement_date'];
            $calendarData[$d] = [
                'items'    => $itemsByDate[$d] ?? [],
                'delivery' => (int) $c['net_amount'],
            ];
        }
    }
}

?>
<link rel="stylesheet" href="<?= htmlspecialchars(web_asset('css/rider-settlement-calendar.css'), ENT_QUOTES, 'UTF-8') ?>" />

<div class="card card-flush shadow-sm mb-4 rider-cal-page-card">
	<div class="card-body p-0">
		<div class="rider-cal-wrap">
			<div class="rider-cal-container">
				<div class="rider-cal-header">
					<div class="rider-cal-nav">
						<button type="button" class="rider-cal-nav-btn" id="riderPrevBtn" aria-label="이전 달">‹</button>
						<div class="rider-cal-month-year" id="riderMonthYear"></div>
						<button type="button" class="rider-cal-nav-btn" id="riderNextBtn" aria-label="다음 달">›</button>
					</div>
				</div>

				<div class="rider-cal-grid">
					<div class="rider-cal-days-header">
						<div class="rider-cal-day-header rider-cal-weekend">일</div>
						<div class="rider-cal-day-header">월</div>
						<div class="rider-cal-day-header">화</div>
						<div class="rider-cal-day-header">수</div>
						<div class="rider-cal-day-header">목</div>
						<div class="rider-cal-day-header">금</div>
						<div class="rider-cal-day-header rider-cal-saturday">토</div>
					</div>
					<div class="rider-cal-days-grid" id="riderDaysGrid"></div>
				</div>

				<div class="rider-cal-stats">
					<div class="rider-cal-summary">
						<div class="rider-cal-summary-item">
							<span class="rider-cal-summary-label">총 근무일수</span>
							<span class="rider-cal-summary-value" id="riderTotalWorkDays">0일</span>
						</div>
						<div class="rider-cal-summary-item">
							<span class="rider-cal-summary-label">총 배달료 (이번 달)</span>
							<span class="rider-cal-summary-value text-primary" id="riderTotalDelivery">0원</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="d-flex flex-wrap gap-2 px-4 py-4">
			<a href="<?= htmlspecialchars(rider_url('settlement/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">목록 보기</a>
			<a href="<?= htmlspecialchars(rider_url('settlement/detail'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">상세 샘플</a>
		</div>
	</div>
</div>

<div id="riderCalDetailOverlay" class="rider-cal-overlay" aria-hidden="true">
	<div class="rider-cal-sheet rider-cal-detail-sheet" role="dialog" aria-modal="true" aria-labelledby="riderCalDetailTitle">
		<div class="rider-cal-sheet-head">
			<h2 class="rider-cal-sheet-title" id="riderCalDetailTitle">배달 상세</h2>
			<button type="button" class="rider-cal-sheet-close" id="riderCalDetailClose" aria-label="닫기">&times;</button>
		</div>
		<div class="rider-cal-sheet-body">
			<div class="rider-cal-detail-date" id="riderCalDetailDateLine"></div>
			<ul class="rider-cal-detail-list" id="riderCalDetailList"></ul>
			<div class="rider-cal-detail-empty text-gray-600 fs-7 py-6 text-center" id="riderCalDetailEmpty" style="display: none;">등록된 건별 내역이 없습니다.</div>
			<div class="rider-cal-detail-total">
				<span class="rider-cal-detail-total-label">합계</span>
				<span class="rider-cal-detail-total-value text-primary" id="riderCalDetailTotal">0원</span>
			</div>
		</div>
	</div>
</div>

<script>
window.RIDER_SETTLEMENT_CALENDAR_DATA = <?= json_encode($calendarData, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php

declare(strict_types=1);

/** 관리자 입력 데이터 목업 (실연동 시 API/DB로 대체) — 키: Y-m-d, items: 건별 스토어·금액 */
$riderSettlementCalendarMock = [
	'2026-04-28' => [
		'items' => [
			['store' => '버거킹 서초점', 'amount' => 52000],
			['store' => '교촌치킨 방배점', 'amount' => 61000],
			['store' => '써브웨이 강남역점', 'amount' => 59000],
		],
	],
	'2026-04-29' => [
		'items' => [
			['store' => 'BHC 삼성점', 'amount' => 68000],
			['store' => '본죽 역삼점', 'amount' => 45000],
			['store' => '스타벅스 테헤란로점', 'amount' => 42000],
			['store' => '도미노피자 논현점', 'amount' => 40000],
		],
	],
	'2026-04-30' => [
		'items' => [
			['store' => '맥도날드 강남점', 'amount' => 55000],
			['store' => '땅스부대찌개 선릉점', 'amount' => 73000],
			['store' => '파리바게뜨 역삼점', 'amount' => 60000],
		],
	],
	'2026-05-02' => [
		'items' => [
			['store' => '네네치킨 신논현점', 'amount' => 72000],
			['store' => '한솥도시락 양재점', 'amount' => 48000],
			['store' => '공차 강남점', 'amount' => 39000],
			['store' => '죠스떡볶이 서초점', 'amount' => 51000],
		],
	],
	'2026-05-03' => [
		'items' => [
			['store' => '멕시카나 반포점', 'amount' => 64000],
			['store' => '김밥천국 서초대로점', 'amount' => 38000],
			['store' => '요아정 강남본점', 'amount' => 74000],
		],
	],
	'2026-05-05' => [
		'items' => [
			['store' => '굽네치킨 역삼점', 'amount' => 81000],
			['store' => '빕스 코엑스점', 'amount' => 67000],
			['store' => '이디야 삼성역점', 'amount' => 44000],
			['store' => '또래오래 개포점', 'amount' => 33000],
		],
	],
	'2026-05-06' => [
		'items' => [
			['store' => '60계치킨 대치점', 'amount' => 59000],
			['store' => '백종원의본죽 삼성점', 'amount' => 47000],
			['store' => '롯데리아 잠실점', 'amount' => 92000],
		],
	],
	'2026-05-07' => [
		'items' => [
			['store' => '처갓집양념치킨 잠실새내', 'amount' => 63500],
			['store' => '뽕뜨락피자 송파점', 'amount' => 52000],
			['store' => '카페봄봄 방이점', 'amount' => 38000],
			['store' => '빽다방 올림픽공원점', 'amount' => 51000],
		],
	],
	'2026-05-09' => [
		'items' => [
			['store' => '호식이두마리치킨 문정점', 'amount' => 56000],
			['store' => '신전떡볶이 가락시장점', 'amount' => 42000],
			['store' => '던킨 올림픽공원역점', 'amount' => 91000],
		],
	],
	'2026-05-10' => [
		'items' => [
			['store' => 'BBQ치킨 잠실본점', 'amount' => 78000],
			['store' => '미스터피자 석촌호수점', 'amount' => 55000],
			['store' => '컴포즈커피 송파나루점', 'amount' => 41000],
			['store' => '원할머니보쌈 방이점', 'amount' => 41000],
		],
	],
	'2026-05-12' => [
		'items' => [
			['store' => '페리카나 문정법원로점', 'amount' => 61000],
			['store' => '서브웨이 가든파이브점', 'amount' => 44000],
			['store' => '빕스 송파타운점', 'amount' => 96000],
		],
	],
	'2026-05-13' => [
		'items' => [
			['store' => '치킨매니아 위례점', 'amount' => 54000],
			['store' => '한끼통살 장지점', 'amount' => 48000],
			['store' => '메가커피 위례중앙점', 'amount' => 36000],
			['store' => '피자헛 위례아이파크점', 'amount' => 54000],
		],
	],
	'2026-05-14' => [
		'items' => [
			['store' => '교촌치킨 위례점', 'amount' => 70000],
			['store' => '본도시락 장지역점', 'amount' => 49000],
			['store' => '파스쿠찌 위례점', 'amount' => 89000],
		],
	],
	'2026-05-16' => [
		'items' => [
			['store' => '맘스터치 복정점', 'amount' => 51000],
			['store' => '김가네 덕천점', 'amount' => 37000],
			['store' => '투썸플레이스 복정역점', 'amount' => 90000],
		],
	],
	'2026-05-17' => [
		'items' => [
			['store' => '또봉이통닭 수유점', 'amount' => 62000],
			['store' => '죠스떡볶이 미아점', 'amount' => 45000],
			['store' => '스타벅스 수유역점', 'amount' => 48000],
			['store' => '도미노피자 미아사거리점', 'amount' => 70000],
		],
	],
	'2026-05-19' => [
		'items' => [
			['store' => '호치킨 쌍문점', 'amount' => 58000],
			['store' => '한솥 수유본점', 'amount' => 46000],
			['store' => '탐앤탐스 쌍문역점', 'amount' => 95000],
		],
	],
	'2026-05-20' => [
		'items' => [
			['store' => '페리카나 번동점', 'amount' => 55000],
			['store' => '빕스 월계점', 'amount' => 62000],
			['store' => '이디야 중계본동점', 'amount' => 69000],
		],
	],
	'2026-05-21' => [
		'items' => [
			['store' => 'BHC 중계점', 'amount' => 73000],
			['store' => '서울식당 상계점', 'amount' => 44000],
			['store' => '메가MGC커피 노원점', 'amount' => 41000],
			['store' => '피자스쿨 상계역점', 'amount' => 54000],
		],
	],
	'2026-05-23' => [
		'items' => [
			['store' => '굽자노을치킨 노원점', 'amount' => 48000],
			['store' => '김밥나라 중계역점', 'amount' => 32000],
			['store' => '할리스 노원역점', 'amount' => 85000],
		],
	],
	'2026-05-24' => [
		'items' => [
			['store' => '처갓집 노원점', 'amount' => 81000],
			['store' => '본죽 월계점', 'amount' => 52000],
			['store' => '스무디킹 노원점', 'amount' => 43000],
			['store' => '미스터피자 상계점', 'amount' => 52000],
		],
	],
	'2026-05-26' => [
		'items' => [
			['store' => '네네치킨 공릉점', 'amount' => 57000],
			['store' => '또래오래 태릉입구점', 'amount' => 49000],
			['store' => '파리바게뜨 공릉점', 'amount' => 88000],
		],
	],
	'2026-05-28' => [
		'items' => [
			['store' => '맥도날드 태릉점', 'amount' => 61000],
			['store' => '서브웨이 화랑대점', 'amount' => 47000],
			['store' => '던킨 태릉역점', 'amount' => 97000],
		],
	],
	'2026-05-30' => [
		'items' => [
			['store' => 'BBQ 공릉점', 'amount' => 54000],
			['store' => '땅땅치킨 하월곡점', 'amount' => 43000],
			['store' => '컴포즈커피 월곡점', 'amount' => 85000],
		],
	],
	'2026-05-31' => [
		'items' => [
			['store' => '60계 하월곡점', 'amount' => 62500],
			['store' => '한끼뚝딱 월곡점', 'amount' => 41000],
			['store' => '카페베네 월곡역점', 'amount' => 95000],
		],
	],
	'2026-06-02' => [
		'items' => [
			['store' => '페리카나 돌곶이점', 'amount' => 56000],
			['store' => '김밥천국 석계점', 'amount' => 44000],
			['store' => '탐앤탐스 석계역점', 'amount' => 90000],
		],
	],
	'2026-06-03' => [
		'items' => [
			['store' => '교촌 석계점', 'amount' => 71000],
			['store' => '본도시락 장안점', 'amount' => 48000],
			['store' => '메가커피 장안동점', 'amount' => 42000],
			['store' => '도미노피자 장안점', 'amount' => 42000],
		],
	],
];
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
window.RIDER_SETTLEMENT_CALENDAR_DATA = <?= json_encode($riderSettlementCalendarMock, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php

declare(strict_types=1);

/** 출금 가능 금액 목업 (실연동 시 정산/출금 API와 동일 소스 권장) — withdrawal_apply 샘플과 동일 */
$riderHomeWithdrawableAmount = 512400;
?>
<div class="mb-6">
	<div class="card card-flush shadow-sm mb-6">
		<div class="card-body">
			<div class="pb-6 mb-6 border-bottom border-gray-200">
				<span class="text-gray-500 fs-7 fw-semibold d-block mb-1">이번 달 정산 합계 (샘플)</span>
				<div class="d-flex flex-wrap align-items-center gap-2">
					<span class="fs-2x fw-bold text-gray-900">₩ 3,842,500</span>
					<span class="badge badge-light-success fs-8">반영 완료</span>
				</div>
			</div>
			<div>
				<span class="text-gray-500 fs-7 fw-semibold d-block mb-1">출금 가능 금액</span>
				<span class="fs-2x fw-bold text-primary d-block">₩ <?= htmlspecialchars(number_format($riderHomeWithdrawableAmount), ENT_QUOTES, 'UTF-8') ?></span>
				<span class="fs-8 text-gray-600 d-block mt-1">인출 가능한 잔액입니다. (샘플)</span>
				<a href="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>" class="fs-8 fw-semibold mt-2 d-inline-block">출금 신청하기</a>
			</div>
		</div>
	</div>

	<div class="row rider-home-actions-row g-3 mb-6">
		<div class="col-6">
			<a href="<?= htmlspecialchars(rider_url('settlement/calendar'), ENT_QUOTES, 'UTF-8') ?>" class="rider-home-big-action btn btn-primary w-100 shadow-sm">
				<i class="ki-duotone ki-calendar text-white"><span class="path1"></span><span class="path2"></span></i>
				<span class="rider-home-big-action-label text-white">달력 보기</span>
			</a>
		</div>
		<div class="col-6">
			<a href="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>" class="rider-home-big-action btn btn-light-primary w-100 border border-primary border-dashed border-2">
				<i class="ki-duotone ki-wallet text-primary"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
				<span class="rider-home-big-action-label text-gray-900">출금 신청</span>
			</a>
		</div>
	</div>

	<div class="mb-6">
		<span class="text-gray-600 fs-7 fw-semibold d-block mb-3">롤링 배너</span>
		<div id="kt_rider_home_carousel" class="carousel slide rounded-3 overflow-hidden shadow-sm" data-bs-ride="carousel" data-bs-interval="4000">
			<div class="carousel-inner">
				<div class="carousel-item active">
					<div class="bg-primary bg-opacity-10 d-flex align-items-center justify-content-center px-6 py-8" style="min-height: 7.5rem;">
						<div class="text-center">
							<div class="fw-bold text-gray-900 mb-1">안전 운행 캠페인</div>
							<div class="fs-7 text-gray-700">헬멧 착용 인증 시 포인트 지급 (샘플)</div>
						</div>
					</div>
				</div>
				<div class="carousel-item">
					<div class="bg-success bg-opacity-10 d-flex align-items-center justify-content-center px-6 py-8" style="min-height: 7.5rem;">
						<div class="text-center">
							<div class="fw-bold text-gray-900 mb-1">배달용품 할인</div>
							<div class="fs-7 text-gray-700">제휴 몰 오픈 기념 (샘플)</div>
						</div>
					</div>
				</div>
				<div class="carousel-item">
					<div class="bg-warning bg-opacity-10 d-flex align-items-center justify-content-center px-6 py-8" style="min-height: 7.5rem;">
						<div class="text-center">
							<div class="fw-bold text-gray-900 mb-1">우천 배달 수당</div>
							<div class="fs-7 text-gray-700">기상 조건 자동 반영 (샘플)</div>
						</div>
					</div>
				</div>
			</div>
			<button class="carousel-control-prev w-auto ms-2" type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide="prev">
				<span class="carousel-control-prev-icon bg-dark bg-opacity-25 rounded" aria-hidden="true"></span>
			</button>
			<button class="carousel-control-next w-auto me-2" type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide="next">
				<span class="carousel-control-next-icon bg-dark bg-opacity-25 rounded" aria-hidden="true"></span>
			</button>
		</div>
	</div>

	<div>
		<span class="text-gray-600 fs-7 fw-semibold d-block mb-3">바로가기</span>
		<a href="https://example.com/rider-supplies" target="_blank" rel="noopener noreferrer" class="btn btn-flex btn-light-primary w-100 py-4 justify-content-between">
			<span class="d-flex align-items-center">
				<i class="ki-duotone ki-shop fs-2 text-primary me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
				<span class="text-start">
					<span class="fw-bold text-gray-900 d-block">배달용품 판매</span>
					<span class="fs-8 text-muted">외부 링크 (샘플)</span>
				</span>
			</span>
			<i class="ki-duotone ki-arrow-right fs-2 text-gray-500"><span class="path1"></span><span class="path2"></span></i>
		</a>
	</div>
</div>

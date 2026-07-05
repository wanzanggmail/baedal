<?php

declare(strict_types=1);

require_once INC_PATH . '/Banner.php';
require_once INC_PATH . '/Notice.php';
require_once INC_PATH . '/RiderWallet.php';

$riderUser = rider_current_user();
$riderHomeWithdrawableAmount = 0;
if ($riderUser) {
    try {
        $riderHomeWithdrawableAmount = (int) (RiderWallet::previewWithdrawal((int) $riderUser['id'])['payout_amount'] ?? 0);
    } catch (Throwable) {
        $riderHomeWithdrawableAmount = 0;
    }
}

$homeBanners = [];
$homeNotices = [];
$contentError = null;

try {
    $riderAgencyId = rider_current_agency_id();
    $homeBanners = Banner::listActiveForRiderHome(20, $riderAgencyId);
    $homeNotices = Notice::listPublishedForRider(5, $riderAgencyId);
} catch (Throwable $e) {
    $contentError = $e->getMessage();
}

$carouselBg = ['primary', 'success', 'warning', 'info'];
?>
<div class="mb-6">
	<?php if ($contentError !== null) : ?>
	<div class="alert alert-warning fs-7 mb-5">콘텐츠를 불러오지 못했습니다. 관리자에게 문의하세요.</div>
	<?php endif; ?>

	<?php if ($homeNotices !== []) : ?>
	<div class="card card-flush shadow-sm rider-home-notices mb-6">
		<div class="card-header border-0 min-h-auto">
			<h2 class="card-title fw-bold fs-6 mb-0">공지</h2>
			<div class="card-toolbar">
				<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary py-1 px-3">전체</a>
			</div>
		</div>
		<div class="card-body">
			<ul class="list-unstyled mb-0 rider-home-notice-list">
				<?php foreach (array_slice($homeNotices, 0, 3) as $n) : ?>
				<li>
					<a href="<?= htmlspecialchars(rider_notice_detail_url((int) $n['id']), ENT_QUOTES, 'UTF-8') ?>"
						class="rider-home-notice-item d-flex align-items-center gap-2 text-gray-800 text-hover-primary text-decoration-none">
						<?php if ($n['pinned']) : ?>
						<span class="badge badge-light-success fs-9 flex-shrink-0 py-1 px-2">고정</span>
						<?php endif; ?>
						<span class="flex-grow-1 text-truncate fs-7 fw-medium"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></span>
						<span class="fs-8 text-muted flex-shrink-0"><?= htmlspecialchars($n['published_date'] ?: '', ENT_QUOTES, 'UTF-8') ?></span>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php endif; ?>

	<div class="card card-flush shadow-sm rider-home-settlement mb-6">
		<div class="card-body">
			<div class="rider-home-settlement-top border-bottom border-gray-200">
				<span class="text-gray-500 fs-8 fw-semibold d-block mb-0">이번 달 정산 합계 (샘플)</span>
				<div class="d-flex flex-wrap align-items-center gap-2 mt-1">
					<span class="fs-2 fw-bold text-gray-900">₩ 3,842,500</span>
					<span class="badge badge-light-success fs-9">반영 완료</span>
				</div>
			</div>
			<div class="rider-home-settlement-withdraw">
				<span class="text-gray-500 fs-8 fw-semibold d-block mb-0">출금 가능 금액</span>
				<span class="fs-2 fw-bold text-primary d-block mt-1">₩ <?= htmlspecialchars(number_format($riderHomeWithdrawableAmount), ENT_QUOTES, 'UTF-8') ?></span>
				<span class="fs-8 text-gray-600 d-block mt-0">보증금·건당 수수료 차감 후 전액 출금 가능액</span>
				<a href="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>" class="fs-8 fw-semibold mt-1 d-inline-block">출금 신청하기</a>
			</div>
		</div>
	</div>

	<div class="row rider-home-actions-row g-3<?= $homeBanners !== [] ? ' mb-6' : '' ?>">
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

	<?php if ($homeBanners !== []) : ?>
	<div class="rider-home-banners mt-2">
		<div id="kt_rider_home_carousel" class="carousel slide rider-home-carousel rounded-3 overflow-hidden shadow-sm" data-bs-ride="carousel" data-bs-interval="4500">
			<?php if (count($homeBanners) > 1) : ?>
			<div class="carousel-indicators rider-home-carousel-indicators">
				<?php foreach ($homeBanners as $i => $bn) : ?>
				<button type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide-to="<?= $i ?>"<?= $i === 0 ? ' class="active" aria-current="true"' : '' ?> aria-label="<?= htmlspecialchars($bn['title'], ENT_QUOTES, 'UTF-8') ?>"></button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="carousel-inner">
				<?php foreach ($homeBanners as $i => $bn) :
				    $active = $i === 0 ? ' active' : '';
				    $bg = $carouselBg[$i % count($carouselBg)];
				    $hasLink = $bn['link_url'] !== '';
				    $imgSrc = (string) ($bn['image_src'] ?? '');
				    ?>
				<div class="carousel-item<?= $active ?>">
					<?php if ($hasLink) : ?>
					<a href="<?= htmlspecialchars($bn['link_url'], ENT_QUOTES, 'UTF-8') ?>" class="d-block text-decoration-none rider-home-banner-link" target="_blank" rel="noopener noreferrer">
					<?php endif; ?>
					<?php if ($imgSrc !== '') : ?>
					<img src="<?= htmlspecialchars($imgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($bn['title'], ENT_QUOTES, 'UTF-8') ?>"
						class="d-block w-100 rider-home-banner-img" width="800" height="200" loading="lazy" decoding="async" />
					<?php else : ?>
					<div class="bg-<?= htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') ?> bg-opacity-10 rider-home-banner-fallback">
						<div class="text-center px-4">
							<div class="fw-bold text-gray-900 mb-1"><?= htmlspecialchars($bn['title'], ENT_QUOTES, 'UTF-8') ?></div>
							<?php if ($bn['subtitle'] !== '') : ?>
							<div class="fs-7 text-gray-700"><?= htmlspecialchars($bn['subtitle'], ENT_QUOTES, 'UTF-8') ?></div>
							<?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
					<?php if ($hasLink) : ?>
					</a>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php if (count($homeBanners) > 1) : ?>
			<button class="carousel-control-prev" type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide="prev" aria-label="이전">
				<span class="carousel-control-prev-icon" aria-hidden="true"></span>
			</button>
			<button class="carousel-control-next" type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide="next" aria-label="다음">
				<span class="carousel-control-next-icon" aria-hidden="true"></span>
			</button>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
</div>

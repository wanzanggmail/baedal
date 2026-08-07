<?php

declare(strict_types=1);

require_once INC_PATH . '/Banner.php';
require_once INC_PATH . '/Notice.php';
require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/RiderDebt.php';
require_once INC_PATH . '/Promotion.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

$won  = static fn (int $n): string => '₩ ' . number_format($n);
$esc  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// ── 지갑 / 출금 가능액 ─────────────────────────────────────────
$withdrawable = 0;
$reserveAmount = 0;
$walletBalance = 0;
if ($riderId > 0) {
    try {
        $pv = RiderWallet::previewWithdrawal($riderId);
        $withdrawable  = (int) ($pv['payout_amount'] ?? 0);
        $reserveAmount = (int) ($pv['reserve_amount'] ?? 0);
        $walletBalance = (int) ($pv['balance'] ?? 0);
    } catch (Throwable) {
        // 지갑 조회 실패해도 홈 전체를 막지 않는다
    }
}

// ── 이번 달 정산 요약 (반영 완료된 사이클 기준) ────────────────
$monthSum = ['count' => 0, 'orders' => 0, 'net' => 0];
$recentCycles = [];
if ($riderId > 0 && SettlementLedger::tableExists()) {
    try {
        $monthSum = SettlementLedger::sumForRider($riderId, ['from' => date('Y-m-01'), 'to' => date('Y-m-t')]);
        $recentCycles = SettlementLedger::listForRider($riderId, ['limit' => 10]);
        $recentCycles = array_slice($recentCycles, 0, 3);
    } catch (Throwable) {
        // 통계 실패는 무시
    }
}

// ── 진행 중인 출금 신청 ────────────────────────────────────────
$openWithdrawal = null;
if ($riderId > 0) {
    try {
        if (Withdrawal::hasOpenRiderRequest($riderId)) {
            $wrs = Withdrawal::listForRider($riderId, 5);
            foreach ($wrs as $w) {
                if (in_array((string) $w['status'], ['pending', 'downloaded'], true)) {
                    $openWithdrawal = $w;
                    break;
                }
            }
        }
    } catch (Throwable) {
        // 무시
    }
}

// ── 미수금 잔액 ────────────────────────────────────────────────
$debtTotal = 0;
$debtCount = 0;
if ($riderId > 0 && RiderDebt::tableReady()) {
    try {
        foreach (RiderDebt::forRider($riderId, true) as $d) {
            $debtTotal += (int) $d['balance_amount'];
            $debtCount++;
        }
    } catch (Throwable) {
        // 무시
    }
}

// ── 이번 달 프로모션 ───────────────────────────────────────────
$promoMonthTotal = 0;
if ($riderId > 0 && Promotion::tableReady()) {
    try {
        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-t');
        foreach (Promotion::listForRider($riderId, 100) as $p) {
            $d = (string) $p['pay_date'];
            if ($d >= $monthStart && $d <= $monthEnd) {
                $promoMonthTotal += (int) $p['total_amount'];
            }
        }
    } catch (Throwable) {
        // 무시
    }
}

// ── 계좌 등록 여부 (미등록이면 출금 자체가 막힌다) ─────────────
$bankMissing = false;
if ($riderId > 0) {
    try {
        $bank = db_row('SELECT bank_code, bank_account FROM riders WHERE id = ? LIMIT 1', [$riderId]);
        $bankMissing = $bank === null
            || trim((string) ($bank['bank_code'] ?? '')) === ''
            || trim((string) ($bank['bank_account'] ?? '')) === '';
    } catch (Throwable) {
        // 무시
    }
}

// ── 공지 · 배너 ────────────────────────────────────────────────
$homeBanners  = [];
$homeNotices  = [];
$contentError = null;
try {
    $riderAgencyId = rider_current_agency_id();
    $homeBanners = Banner::listActiveForRiderHome(20, $riderAgencyId);
    $homeNotices = Notice::listPublishedForRider(5, $riderAgencyId);
} catch (Throwable $e) {
    $contentError = $e->getMessage();
}

$carouselBg = ['primary', 'success', 'warning', 'info'];
$isDaily = !empty($riderUser['is_daily_settlement']);
?>
<div class="rider-home">

	<?php if ($contentError !== null) : ?>
	<div class="alert alert-warning fs-7 mb-4">콘텐츠를 불러오지 못했습니다. 관리자에게 문의하세요.</div>
	<?php endif; ?>

	<!--begin::인사말-->
	<div class="d-flex align-items-center justify-content-between mb-4">
		<div class="min-w-0">
			<div class="rider-home-greeting-name text-truncate">
				<?= $esc((string) ($riderUser['name'] ?? '라이더')) ?><span class="text-gray-500 fw-semibold"> 님</span>
			</div>
			<div class="rider-home-greeting-sub text-gray-500 text-truncate">
				<?= $esc((string) ($riderUser['rider_code'] ?? '')) ?>
			</div>
		</div>
		<span class="badge <?= $isDaily ? 'badge-light-success' : 'badge-light-primary' ?> flex-shrink-0">
			<?= $isDaily ? '선정산' : '주정산' ?>
		</span>
	</div>
	<!--end::인사말-->

	<!--begin::알림(막힘 상태)-->
	<?php if ($bankMissing) : ?>
	<a href="<?= $esc(rider_url('profile/bank')) ?>" class="rider-home-alert rider-home-alert-danger text-decoration-none d-flex align-items-center gap-3 mb-3">
		<i class="ki-duotone ki-information-5 flex-shrink-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="flex-grow-1">
			<div class="rider-home-alert-title">출금 계좌를 등록해 주세요</div>
			<div class="rider-home-alert-sub">계좌가 없으면 출금 신청을 할 수 없습니다.</div>
		</div>
		<i class="ki-duotone ki-right flex-shrink-0 fs-4"></i>
	</a>
	<?php endif; ?>

	<?php if ($openWithdrawal !== null) : ?>
	<a href="<?= $esc(rider_url('withdrawal/history')) ?>" class="rider-home-alert rider-home-alert-info text-decoration-none d-flex align-items-center gap-3 mb-3">
		<i class="ki-duotone ki-time flex-shrink-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="flex-grow-1">
			<div class="rider-home-alert-title">출금 신청 <?= $esc((string) $openWithdrawal['status_label']) ?></div>
			<div class="rider-home-alert-sub"><?= $esc($won((int) $openWithdrawal['amount'])) ?> · <?= $esc(substr((string) $openWithdrawal['requested_at'], 0, 16)) ?></div>
		</div>
		<i class="ki-duotone ki-right flex-shrink-0 fs-4"></i>
	</a>
	<?php endif; ?>
	<!--end::알림-->

	<!--begin::출금 가능액 (핵심)-->
	<div class="rider-home-hero mb-4">
		<div class="rider-home-hero-label">출금 가능 금액</div>
		<div class="rider-home-hero-amount"><?= $esc($won($withdrawable)) ?></div>
		<div class="rider-home-hero-note">보증금·정산수수료 차감 후 금액</div>

		<?php if ($openWithdrawal !== null) : ?>
		<a href="<?= $esc(rider_url('withdrawal/history')) ?>" class="rider-home-hero-cta rider-home-hero-cta-ghost">신청 내역 보기</a>
		<?php elseif ($bankMissing) : ?>
		<a href="<?= $esc(rider_url('profile/bank')) ?>" class="rider-home-hero-cta rider-home-hero-cta-ghost">계좌 등록하고 출금하기</a>
		<?php else : ?>
		<a href="<?= $esc(rider_url('withdrawal/apply')) ?>" class="rider-home-hero-cta">출금 신청하기</a>
		<?php endif; ?>

		<div class="rider-home-hero-foot">
			<div class="rider-home-hero-foot-item">
				<span class="rider-home-hero-foot-label">보증금</span>
				<span class="rider-home-hero-foot-value"><?= $esc($won($reserveAmount)) ?></span>
			</div>
			<div class="rider-home-hero-foot-divider"></div>
			<div class="rider-home-hero-foot-item">
				<span class="rider-home-hero-foot-label">지갑 잔액</span>
				<span class="rider-home-hero-foot-value"><?= $esc($won($walletBalance)) ?></span>
			</div>
		</div>
	</div>
	<!--end::출금 가능액-->

	<!--begin::이번 달 요약-->
	<div class="rider-home-stats mb-4">
		<a href="<?= $esc(rider_url('settlement/fees')) ?>" class="rider-home-stat">
			<span class="rider-home-stat-label"><?= (int) date('n') ?>월 정산</span>
			<span class="rider-home-stat-value"><?= $esc($won((int) $monthSum['net'])) ?></span>
			<span class="rider-home-stat-sub"><?= (int) $monthSum['count'] ?>일 반영</span>
		</a>
		<a href="<?= $esc(rider_url('settlement/calendar')) ?>" class="rider-home-stat">
			<span class="rider-home-stat-label"><?= (int) date('n') ?>월 배달</span>
			<span class="rider-home-stat-value"><?= number_format((int) $monthSum['orders']) ?><span class="rider-home-stat-unit">건</span></span>
			<span class="rider-home-stat-sub">달력 보기</span>
		</a>
		<?php if ($debtTotal > 0) : ?>
		<a href="<?= $esc(rider_url('profile/debt')) ?>" class="rider-home-stat rider-home-stat-warn">
			<span class="rider-home-stat-label">미수금</span>
			<span class="rider-home-stat-value"><?= $esc($won($debtTotal)) ?></span>
			<span class="rider-home-stat-sub"><?= (int) $debtCount ?>건 남음</span>
		</a>
		<?php else : ?>
		<a href="<?= $esc(rider_url('promotions')) ?>" class="rider-home-stat">
			<span class="rider-home-stat-label"><?= (int) date('n') ?>월 프로모션</span>
			<span class="rider-home-stat-value"><?= $esc($won($promoMonthTotal)) ?></span>
			<span class="rider-home-stat-sub">내역 보기</span>
		</a>
		<?php endif; ?>
	</div>
	<!--end::이번 달 요약-->

	<!--begin::바로가기-->
	<div class="rider-home-quick mb-5">
		<a href="<?= $esc(rider_url('settlement/calendar')) ?>" class="rider-home-quick-item">
			<span class="rider-home-quick-icon bg-light-primary">
				<i class="ki-duotone ki-calendar text-primary"><span class="path1"></span><span class="path2"></span></i>
			</span>
			<span class="rider-home-quick-label">정산 달력</span>
		</a>
		<a href="<?= $esc(rider_url('settlement/fees')) ?>" class="rider-home-quick-item">
			<span class="rider-home-quick-icon bg-light-success">
				<i class="ki-duotone ki-chart-simple text-success"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
			</span>
			<span class="rider-home-quick-label">수수료 내역</span>
		</a>
		<a href="<?= $esc(rider_url('promotions')) ?>" class="rider-home-quick-item">
			<span class="rider-home-quick-icon bg-light-warning">
				<i class="ki-duotone ki-gift text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
			</span>
			<span class="rider-home-quick-label">프로모션</span>
		</a>
		<a href="<?= $esc(rider_url('withdrawal/history')) ?>" class="rider-home-quick-item">
			<span class="rider-home-quick-icon bg-light-info">
				<i class="ki-duotone ki-wallet text-info"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
			</span>
			<span class="rider-home-quick-label">출금 내역</span>
		</a>
	</div>
	<!--end::바로가기-->

	<!--begin::최근 정산-->
	<?php if ($recentCycles !== []) : ?>
	<div class="card card-flush shadow-sm mb-4">
		<div class="card-header border-0 min-h-auto pt-4">
			<h2 class="card-title fw-bold fs-6 mb-0">최근 정산</h2>
			<div class="card-toolbar">
				<a href="<?= $esc(rider_url('settlement/fees')) ?>" class="btn btn-sm btn-light-primary py-1 px-3">전체</a>
			</div>
		</div>
		<div class="card-body pt-2">
			<?php foreach ($recentCycles as $c) : ?>
			<a href="<?= $esc(rider_url('settlement/fees')) ?>" class="rider-home-cycle text-decoration-none">
				<div class="min-w-0">
					<div class="rider-home-cycle-date"><?= $esc((string) $c['settlement_date']) ?></div>
					<div class="rider-home-cycle-sub text-truncate">
						<?= $esc((string) $c['platform_label']) ?> · <?= number_format((int) $c['order_count']) ?>건
					</div>
				</div>
				<div class="text-end flex-shrink-0">
					<div class="rider-home-cycle-amount">+<?= $esc($won((int) $c['net_amount'])) ?></div>
					<?php if ((int) $c['total_fee_amount'] > 0) : ?>
					<div class="rider-home-cycle-fee">수수료 <?= $esc($won((int) $c['total_fee_amount'])) ?></div>
					<?php endif; ?>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>
	<!--end::최근 정산-->

	<!--begin::공지-->
	<?php if ($homeNotices !== []) : ?>
	<div class="card card-flush shadow-sm rider-home-notices mb-4">
		<div class="card-header border-0 min-h-auto pt-4">
			<h2 class="card-title fw-bold fs-6 mb-0">공지</h2>
			<div class="card-toolbar">
				<a href="<?= $esc(rider_url('notices')) ?>" class="btn btn-sm btn-light-primary py-1 px-3">전체</a>
			</div>
		</div>
		<div class="card-body pt-2">
			<ul class="list-unstyled mb-0 rider-home-notice-list">
				<?php foreach (array_slice($homeNotices, 0, 3) as $n) : ?>
				<li>
					<a href="<?= $esc(rider_notice_detail_url((int) $n['id'])) ?>"
						class="rider-home-notice-item d-flex align-items-center gap-2 text-gray-800 text-hover-primary text-decoration-none">
						<?php if ($n['pinned']) : ?>
						<span class="badge badge-light-success fs-9 flex-shrink-0 py-1 px-2">고정</span>
						<?php endif; ?>
						<span class="flex-grow-1 text-truncate fs-7 fw-medium"><?= $esc((string) $n['title']) ?></span>
						<span class="fs-8 text-muted flex-shrink-0"><?= $esc((string) ($n['published_date'] ?: '')) ?></span>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
	<?php endif; ?>
	<!--end::공지-->

	<!--begin::배너-->
	<?php if ($homeBanners !== []) : ?>
	<div class="rider-home-banners">
		<div id="kt_rider_home_carousel" class="carousel slide rider-home-carousel rounded-3 overflow-hidden shadow-sm" data-bs-ride="carousel" data-bs-interval="4500">
			<?php if (count($homeBanners) > 1) : ?>
			<div class="carousel-indicators rider-home-carousel-indicators">
				<?php foreach ($homeBanners as $i => $bn) : ?>
				<button type="button" data-bs-target="#kt_rider_home_carousel" data-bs-slide-to="<?= $i ?>"<?= $i === 0 ? ' class="active" aria-current="true"' : '' ?> aria-label="<?= $esc((string) $bn['title']) ?>"></button>
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
					<a href="<?= $esc((string) $bn['link_url']) ?>" class="d-block text-decoration-none rider-home-banner-link" target="_blank" rel="noopener noreferrer">
					<?php endif; ?>
					<?php if ($imgSrc !== '') : ?>
					<img src="<?= $esc($imgSrc) ?>" alt="<?= $esc((string) $bn['title']) ?>"
						class="d-block w-100 rider-home-banner-img" width="800" height="200" loading="lazy" decoding="async" />
					<?php else : ?>
					<div class="bg-<?= $esc($bg) ?> bg-opacity-10 rider-home-banner-fallback">
						<div class="text-center px-4">
							<div class="fw-bold text-gray-900 mb-1"><?= $esc((string) $bn['title']) ?></div>
							<?php if ($bn['subtitle'] !== '') : ?>
							<div class="fs-7 text-gray-700"><?= $esc((string) $bn['subtitle']) ?></div>
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
	<!--end::배너-->
</div>

<?php

declare(strict_types=1);

/** @var string $riderRoute */
$riderRoute = $riderRoute ?? '';
?>
<div class="menu menu-column menu-rounded menu-sub-indention fw-semibold fs-6 px-3 py-5" data-kt-menu="true" data-kt-menu-expand="false">
	<div class="menu-item mb-1">
		<a class="menu-link<?= rider_nav_active('home') ?>" href="<?= htmlspecialchars(rider_url('home'), ENT_QUOTES, 'UTF-8') ?>">
			<span class="menu-icon"><i class="ki-duotone ki-element-11 fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
			<span class="menu-title">홈</span>
		</a>
	</div>

	<div data-kt-menu-trigger="click" class="menu-item menu-accordion mb-1<?= rider_nav_accordion_show('settlement') ?>">
		<span class="menu-link">
			<span class="menu-icon"><i class="ki-duotone ki-chart-simple fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
			<span class="menu-title">정산</span>
			<span class="menu-arrow"></span>
		</span>
		<div class="menu-sub menu-sub-accordion">
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('settlement/calendar') ?>" href="<?= htmlspecialchars(rider_url('settlement/calendar'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">달력 보기</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('settlement/list') ?>" href="<?= htmlspecialchars(rider_url('settlement/list'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">목록 보기</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= (($riderRoute ?? '') === 'settlement/fees' || ($riderRoute ?? '') === 'settlement/fee-detail') ? ' active' : '' ?>" href="<?= htmlspecialchars(rider_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">수수료 내역</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('settlement/detail') ?>" href="<?= htmlspecialchars(rider_url('settlement/detail'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">상세 (샘플)</span>
				</a>
			</div>
		</div>
	</div>

	<div data-kt-menu-trigger="click" class="menu-item menu-accordion mb-1<?= rider_nav_accordion_show('withdrawal') ?>">
		<span class="menu-link">
			<span class="menu-icon"><i class="ki-duotone ki-wallet fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
			<span class="menu-title">출금</span>
			<span class="menu-arrow"></span>
		</span>
		<div class="menu-sub menu-sub-accordion">
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('withdrawal/apply') ?>" href="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">출금 신청</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('withdrawal/history') ?>" href="<?= htmlspecialchars(rider_url('withdrawal/history'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">신청 내역</span>
				</a>
			</div>
		</div>
	</div>

	<div data-kt-menu-trigger="click" class="menu-item menu-accordion mb-1<?= rider_nav_accordion_show('notices') ?>">
		<span class="menu-link">
			<span class="menu-icon"><i class="ki-duotone ki-notification-bing fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span>
			<span class="menu-title">공지</span>
			<span class="menu-arrow"></span>
		</span>
		<div class="menu-sub menu-sub-accordion">
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('notices') ?>" href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">공지 목록</span>
				</a>
			</div>
		</div>
	</div>

	<div data-kt-menu-trigger="click" class="menu-item menu-accordion mb-1<?= rider_nav_accordion_show('profile') ?>">
		<span class="menu-link">
			<span class="menu-icon"><i class="ki-duotone ki-user fs-2"><span class="path1"></span><span class="path2"></span></i></span>
			<span class="menu-title">내 정보</span>
			<span class="menu-arrow"></span>
		</span>
		<div class="menu-sub menu-sub-accordion">
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('profile') ?>" href="<?= htmlspecialchars(rider_url('profile'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">프로필</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('profile/bank') ?>" href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">계좌 정보</span>
				</a>
			</div>
			<div class="menu-item">
				<a class="menu-link<?= rider_nav_active('profile/password') ?>" href="<?= htmlspecialchars(rider_url('profile/password'), ENT_QUOTES, 'UTF-8') ?>">
					<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
					<span class="menu-title">비밀번호 변경</span>
				</a>
			</div>
		</div>
	</div>

	<div class="separator my-5"></div>
	<div class="menu-item">
		<a class="menu-link" href="<?= htmlspecialchars(rider_url('logout'), ENT_QUOTES, 'UTF-8') ?>">
			<span class="menu-icon"><i class="ki-duotone ki-entrance-left fs-2"><span class="path1"></span><span class="path2"></span></i></span>
			<span class="menu-title">로그아웃</span>
		</a>
	</div>
</div>

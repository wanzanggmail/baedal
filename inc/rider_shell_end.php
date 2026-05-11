<?php

declare(strict_types=1);

require_once INC_PATH . '/rider_auth.php';

$riderRoute = $riderRoute ?? '';
$riderMinimalShell = !empty($riderMinimalShell);
?>
		</main>

		<?php if (!$riderMinimalShell) : ?>
		<nav id="kt_rider_bottom_nav" class="border-top border-gray-200 bg-body position-fixed bottom-0 start-0 end-0 pb-safe z-index-3" style="z-index: 99; padding-bottom: env(safe-area-inset-bottom, 12px);">
			<div class="d-flex justify-content-between align-items-center py-2 px-2">
				<a class="nav-link flex-fill d-flex flex-column align-items-center py-2 px-1 text-gray-600 fs-8 <?= ($riderRoute === 'home') ? 'active' : '' ?>" href="<?= htmlspecialchars(rider_url('home'), ENT_QUOTES, 'UTF-8') ?>">
					<i class="ki-duotone ki-element-11 fs-2x mb-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<span>홈</span>
				</a>
				<a class="nav-link flex-fill d-flex flex-column align-items-center py-2 px-1 text-gray-600 fs-8 <?= str_starts_with($riderRoute, 'settlement/') ? 'active' : '' ?>" href="<?= htmlspecialchars(rider_url('settlement/list'), ENT_QUOTES, 'UTF-8') ?>">
					<i class="ki-duotone ki-chart-simple fs-2x mb-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<span>정산</span>
				</a>
				<a class="nav-link flex-fill d-flex flex-column align-items-center py-2 px-1 text-gray-600 fs-8 <?= str_starts_with($riderRoute, 'withdrawal/') ? 'active' : '' ?>" href="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>">
					<i class="ki-duotone ki-wallet fs-2x mb-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<span>출금</span>
				</a>
				<a class="nav-link flex-fill d-flex flex-column align-items-center py-2 px-1 text-gray-600 fs-8 <?= str_starts_with($riderRoute, 'profile') ? 'active' : '' ?>" href="<?= htmlspecialchars(rider_url('profile'), ENT_QUOTES, 'UTF-8') ?>">
					<i class="ki-duotone ki-profile-circle fs-2x mb-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<span>내 정보</span>
				</a>
			</div>
		</nav>
		<?php endif; ?>
	</div>
	<?php
	$riderNoticePopup = false;
	if (!$riderMinimalShell && rider_is_logged_in() && !empty($_SESSION['rider_show_notice_popup'])) {
		$riderNoticePopup = true;
		unset($_SESSION['rider_show_notice_popup']);
	}
	?>
	<?php if ($riderNoticePopup) : ?>
	<div class="modal fade" id="kt_rider_notice_modal" tabindex="-1" aria-labelledby="kt_rider_notice_modal_label" aria-hidden="true" data-bs-backdrop="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable px-3">
			<div class="modal-content shadow-lg">
				<div class="modal-header border-0 pb-0">
					<div class="d-flex align-items-center gap-2">
						<span class="badge badge-light-primary">공지</span>
						<span class="text-muted fs-8">2026-05-10</span>
					</div>
					<div class="btn btn-icon btn-sm btn-active-light-primary" data-bs-dismiss="modal" aria-label="닫기">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body pt-4 pb-2">
					<h2 class="fs-4 fw-bold text-gray-900 mb-4" id="kt_rider_notice_modal_label">5월 정산 일정 안내</h2>
					<div class="fs-6 text-gray-800 lh-lg">
						<p class="mb-3">안녕하세요.</p>
						<p class="mb-3">5월 정산 지급일은 <strong>5월 15일(목)</strong> 예정입니다. 자세한 내역은 정산 메뉴에서 확인해 주세요.</p>
						<p class="mb-0 text-muted fs-7">긴급 공지는 앱 실행 직후 이 창으로 안내합니다. (목업)</p>
					</div>
				</div>
				<div class="modal-footer flex-nowrap gap-2 border-0 pt-0">
					<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light-primary flex-grow-1">공지 목록</a>
					<button type="button" class="btn btn-primary flex-grow-1" data-bs-dismiss="modal">확인</button>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
	<?php if (rider_is_logged_in()) : ?>
	<?php $ru = rider_current_user(); ?>
	<script>
	(function () {
		try {
			localStorage.setItem('baedal_rider_auth', JSON.stringify({
				v: 1,
				loginId: <?= json_encode($ru['login_id'], JSON_THROW_ON_ERROR) ?>,
				name: <?= json_encode($ru['name'], JSON_THROW_ON_ERROR) ?>,
				savedAt: Date.now()
			}));
		} catch (e) {}
	})();
	</script>
	<?php elseif ($riderMinimalShell && isset($_GET['out'])) : ?>
	<script>
	(function () {
		try { localStorage.removeItem('baedal_rider_auth'); } catch (e) {}
	})();
	</script>
	<?php endif; ?>
	<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
	<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<?php if (($riderRoute ?? '') === 'settlement/calendar') : ?>
	<script src="<?= htmlspecialchars(web_asset('js/rider-settlement-calendar.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<?php endif; ?>
	<?php if (!empty($riderNoticePopup)) : ?>
	<script>
	(function () {
		var el = document.getElementById('kt_rider_notice_modal');
		if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
		var modal = new bootstrap.Modal(el);
		modal.show();
	})();
	</script>
	<?php endif; ?>
</body>
</html>

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
				<a class="nav-link flex-fill d-flex flex-column align-items-center py-2 px-1 text-gray-600 fs-8 <?= str_starts_with($riderRoute, 'notices') ? 'active' : '' ?>" href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>">
					<i class="ki-duotone ki-notification-bing fs-2x mb-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
					<span>공지</span>
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
	$riderNoticePopupQueue = [];
	if (!$riderMinimalShell && rider_is_logged_in() && !empty($_SESSION['rider_notice_popup_queue'])) {
		$riderNoticePopupQueue = is_array($_SESSION['rider_notice_popup_queue'])
			? $_SESSION['rider_notice_popup_queue']
			: [];
		unset($_SESSION['rider_notice_popup_queue']);
	}
	$riderNoticePopupJson = $riderNoticePopupQueue !== []
		? json_encode($riderNoticePopupQueue, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
		: '[]';
	?>
	<?php if ($riderNoticePopupQueue !== []) : ?>
	<div class="modal fade" id="kt_rider_notice_modal" tabindex="-1" aria-labelledby="kt_rider_notice_modal_label" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable px-3">
			<div class="modal-content shadow-lg">
				<div class="modal-header border-0 pb-0">
					<div class="d-flex align-items-center gap-2 flex-wrap">
						<span class="badge badge-light-primary" id="kt_rider_notice_modal_cat">공지</span>
						<span class="text-muted fs-8" id="kt_rider_notice_modal_date"></span>
					</div>
					<button type="button" class="btn btn-icon btn-sm btn-active-light-primary" id="kt_rider_notice_modal_close_icon" aria-label="닫기">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</button>
				</div>
				<div class="modal-body pt-4 pb-2">
					<h2 class="fs-4 fw-bold text-gray-900 mb-4" id="kt_rider_notice_modal_label"></h2>
					<div class="fs-6 text-gray-800 lh-lg" id="kt_rider_notice_modal_body"></div>
				</div>
				<div class="modal-footer flex-nowrap gap-2 border-0 pt-0">
					<a href="#" class="btn btn-light-primary flex-grow-1" id="kt_rider_notice_modal_detail">자세히</a>
					<button type="button" class="btn btn-primary flex-grow-1" id="kt_rider_notice_modal_dismiss">닫기</button>
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
	<?php
    $swPath = ROOT_PATH . '/rider/service-worker.js';
    $swBust = is_file($swPath) ? (string) filemtime($swPath) : '1';
    $swRegisterUrl = htmlspecialchars(rider_pwa_service_worker_url() . '?v=' . rawurlencode($swBust), ENT_QUOTES, 'UTF-8');
    $swScope = htmlspecialchars(rtrim(RIDER_BASE, '/') . '/', ENT_QUOTES, 'UTF-8');
    ?>
	<script>
	(function () {
		if (!('serviceWorker' in navigator)) return;
		var url = '<?= $swRegisterUrl ?>';
		var scope = '<?= $swScope ?>';
		window.addEventListener('load', function () {
			navigator.serviceWorker.register(url, { scope: scope }).catch(function () {});
		});
	})();
	</script>
	<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
	<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<?php if (($riderRoute ?? '') === 'settlement/calendar') : ?>
	<script src="<?= htmlspecialchars(web_asset('js/rider-settlement-calendar.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<?php endif; ?>
	<?php if ($riderNoticePopupQueue !== []) : ?>
	<script>
	(function () {
		var queue = <?= $riderNoticePopupJson ?>;
		var el = document.getElementById('kt_rider_notice_modal');
		if (!el || !queue.length || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;

		var idx = 0;
		var modal = new bootstrap.Modal(el);
		var catEl = document.getElementById('kt_rider_notice_modal_cat');
		var dateEl = document.getElementById('kt_rider_notice_modal_date');
		var titleEl = document.getElementById('kt_rider_notice_modal_label');
		var bodyEl = document.getElementById('kt_rider_notice_modal_body');
		var detailEl = document.getElementById('kt_rider_notice_modal_detail');
		var dismissBtn = document.getElementById('kt_rider_notice_modal_dismiss');
		var closeIcon = document.getElementById('kt_rider_notice_modal_close_icon');

		function catClass(c) {
			if (c === '긴급') return 'danger';
			if (c === '안내') return 'primary';
			return 'secondary';
		}

		function render() {
			var n = queue[idx];
			if (!n) return;
			if (catEl) {
				catEl.textContent = n.category || '공지';
				catEl.className = 'badge badge-light-' + catClass(n.category);
			}
			if (dateEl) dateEl.textContent = n.published_date || '';
			if (titleEl) titleEl.textContent = n.title || '';
			if (bodyEl) {
				bodyEl.innerHTML = (n.body || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
			}
			if (detailEl && n.id) {
				detailEl.href = <?= json_encode(rider_url('notices/detail'), JSON_THROW_ON_ERROR) ?> + (<?= defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL ? "'&'" : "'?'" ?>) + 'id=' + encodeURIComponent(String(n.id));
			}
			var label = '닫기 (' + (idx + 1) + '/' + queue.length + ')';
			if (dismissBtn) dismissBtn.textContent = label;
		}

		function advance() {
			idx++;
			if (idx < queue.length) {
				render();
				return;
			}
			modal.hide();
		}

		function onDismiss() {
			advance();
		}

		if (dismissBtn) dismissBtn.addEventListener('click', onDismiss);
		if (closeIcon) closeIcon.addEventListener('click', onDismiss);

		render();
		modal.show();
	})();
	</script>
	<?php endif; ?>
</body>
</html>

<?php

declare(strict_types=1);

// 로그인 직후 팝업 공지 — rider/index.php 가 세션 큐에 담아두면 첫 화면에서 한 번만 띄운다.
// 큐는 여기서 **바로 비운다**(unset). 페이지를 옮길 때마다 다시 뜨면 안 되기 때문.
$riderNoticePopups = [];
if (empty($riderMinimalShell) && !empty($_SESSION['rider_notice_popup_queue'])) {
    $riderNoticePopups = (array) $_SESSION['rider_notice_popup_queue'];
    unset($_SESSION['rider_notice_popup_queue']);
}
?>
		</main>
	</div>
	<?php if (empty($riderMinimalShell)) : ?>
	<?php require INC_PATH . '/rider_tabbar.php'; ?>
	<?php endif; ?>

	<?php if ($riderNoticePopups !== []) : ?>
	<!--begin::로그인 팝업 공지-->
	<div class="modal fade" id="kt_rider_notice_popup" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
		<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header py-4">
					<h3 class="modal-title fs-5" id="rnp_title">공지</h3>
					<span class="badge badge-light-secondary fs-8" id="rnp_counter"></span>
				</div>
				<div class="modal-body fs-7">
					<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
						<span class="badge badge-light-primary fs-8" id="rnp_category"></span>
						<span class="text-muted fs-8" id="rnp_date"></span>
					</div>
					<div id="rnp_body" class="text-gray-800" style="white-space:pre-wrap;word-break:break-word"></div>
				</div>
				<div class="modal-footer py-3">
					<button type="button" class="btn btn-sm btn-light" id="rnp_prev">이전</button>
					<button type="button" class="btn btn-sm btn-primary" id="rnp_next">다음</button>
					<button type="button" class="btn btn-sm btn-light-primary" data-bs-dismiss="modal" id="rnp_close">닫기</button>
				</div>
			</div>
		</div>
	</div>
	<script id="rnp_data" type="application/json"><?= json_encode(array_map(static fn (array $n): array => [
		    'title'    => (string) ($n['title'] ?? ''),
		    'body'     => (string) ($n['body'] ?? ''),
		    'category' => (string) ($n['category'] ?? ''),
		    'date'     => (string) ($n['published_at'] ?? ''),
		    'pinned'   => !empty($n['pinned']),
		], $riderNoticePopups), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
	<!--end::로그인 팝업 공지-->
	<?php endif; ?>
	<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
	<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<?php if ($riderNoticePopups !== []) : ?>
	<script>
	// bootstrap 번들이 로드된 뒤 실행돼야 Modal을 쓸 수 있어 여기(스크립트 태그 아래)에 둔다.
	(function () {
		var raw = document.getElementById('rnp_data');
		if (!raw || typeof bootstrap === 'undefined') return;
		var items = [];
		try { items = JSON.parse(raw.textContent) || []; } catch (e) { return; }
		if (!items.length) return;

		var i = 0;
		var el = document.getElementById('kt_rider_notice_popup');
		function render() {
			var n = items[i];
			document.getElementById('rnp_title').textContent = n.title || '공지';
			// 본문은 textContent로만 넣는다 — 관리자가 입력한 문자열이라 HTML로 해석하지 않는다.
			document.getElementById('rnp_body').textContent = n.body || '';
			document.getElementById('rnp_category').textContent = n.pinned ? '고정' : (n.category || '공지');
			document.getElementById('rnp_date').textContent = n.date || '';
			document.getElementById('rnp_counter').textContent = items.length > 1 ? (i + 1) + ' / ' + items.length : '';
			document.getElementById('rnp_prev').classList.toggle('d-none', items.length < 2 || i === 0);
			document.getElementById('rnp_next').classList.toggle('d-none', items.length < 2 || i >= items.length - 1);
		}
		document.getElementById('rnp_prev').addEventListener('click', function () { if (i > 0) { i--; render(); } });
		document.getElementById('rnp_next').addEventListener('click', function () { if (i < items.length - 1) { i++; render(); } });
		render();
		bootstrap.Modal.getOrCreateInstance(el).show();
	})();
	</script>
	<?php endif; ?>
</body>
</html>

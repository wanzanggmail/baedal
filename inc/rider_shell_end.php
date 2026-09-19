<?php

declare(strict_types=1);

// 팝업 공지 — 라이더 앱에 들어오면 공개된 공지를 모달로 띄운다(2026-09-18 갑).
//  · 세션당 한 번만 — 페이지를 옮길 때마다 다시 뜨면 안 된다.
//  · 「하루 동안 보지 않기」를 누르면 쿠키가 살아 있는 동안 아예 만들지 않는다.
$riderNoticePopups = [];
if (empty($riderMinimalShell)
    && function_exists('rider_current_user') && rider_current_user() !== null
    && empty($_SESSION['rider_notice_popup_done'])
    && (int) ($_COOKIE['rider_notice_hide'] ?? 0) < time()
) {
    try {
        require_once INC_PATH . '/Notice.php';
        // 로그인 직후엔 이미 담아 둔 큐를 쓰고, 그 외에는 지금 조회한다.
        $riderNoticePopups = !empty($_SESSION['rider_notice_popup_queue'])
            ? (array) $_SESSION['rider_notice_popup_queue']
            : Notice::loginPopupQueue(rider_current_agency_id());
    } catch (Throwable) {
        $riderNoticePopups = [];
    }
    unset($_SESSION['rider_notice_popup_queue']);
    $_SESSION['rider_notice_popup_done'] = 1;
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
					<div id="rnp_body" class="text-gray-800" style="word-break:break-word"></div>
				</div>
				<div class="modal-footer py-3 d-flex justify-content-between">
					<?php // 「하루 동안 보지 않기」는 **전부 본 뒤에만** 눌릴 수 있게 한다(갑 지시). ?>
					<button type="button" class="btn btn-sm btn-light text-muted d-none" id="rnp_hide">하루 동안 보지 않기</button>
					<div class="d-flex gap-2 ms-auto">
						<button type="button" class="btn btn-sm btn-light d-none" id="rnp_prev">이전</button>
						<button type="button" class="btn btn-sm btn-primary" id="rnp_next">확인</button>
					</div>
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
	<script src="<?= htmlspecialchars(web_asset_v('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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
		var seenAll = items.length === 1;   // 한 건뿐이면 그 자체로 «전부 본» 것
		var el = document.getElementById('kt_rider_notice_popup');
		var prevBtn = document.getElementById('rnp_prev');
		var nextBtn = document.getElementById('rnp_next');
		var hideBtn = document.getElementById('rnp_hide');

		function render() {
			var n = items[i];
			var last = i >= items.length - 1;
			document.getElementById('rnp_title').textContent = n.title || '공지';
			// 본문은 관리자(CKEditor)가 작성한 HTML이다 — innerHTML로 그대로 렌더링한다.
			document.getElementById('rnp_body').innerHTML = n.body || '';
			document.getElementById('rnp_category').textContent = n.pinned ? '고정' : (n.category || '공지');
			document.getElementById('rnp_date').textContent = n.date || '';
			// 몇 개 중 몇 번째인지 항상 보여준다(갑 지시) — 한 건이어도 1 / 1.
			document.getElementById('rnp_counter').textContent = (i + 1) + ' / ' + items.length;
			prevBtn.classList.toggle('d-none', i === 0);
			// 마지막 장에서는 「확인」이 닫기 역할을 한다.
			nextBtn.textContent = last ? '확인' : '확인 (다음 ' + (items.length - i - 1) + '건)';
			if (last) { seenAll = true; }
			hideBtn.classList.toggle('d-none', !seenAll);
			el.querySelector('.modal-body').scrollTop = 0;
		}
		prevBtn.addEventListener('click', function () { if (i > 0) { i--; render(); } });
		nextBtn.addEventListener('click', function () {
			if (i < items.length - 1) { i++; render(); return; }
			bootstrap.Modal.getOrCreateInstance(el).hide();
		});
		hideBtn.addEventListener('click', function () {
			// 하루(86400초) 동안 팝업을 만들지 않는다 — 서버가 이 쿠키를 보고 큐 자체를 비운다.
			document.cookie = 'rider_notice_hide=' + Math.floor(Date.now() / 1000 + 86400)
				+ '; max-age=86400; path=/; samesite=lax';
			bootstrap.Modal.getOrCreateInstance(el).hide();
		});
		render();
		bootstrap.Modal.getOrCreateInstance(el).show();
	})();
	</script>
	<?php endif; ?>
	<?php require INC_PATH . '/channel_talk.php'; ?>
</body>
</html>

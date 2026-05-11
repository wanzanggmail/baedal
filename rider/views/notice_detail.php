<?php

declare(strict_types=1);
?>
<div class="card card-flush shadow-sm">
	<div class="card-body">
		<div class="d-flex flex-wrap gap-2 mb-3">
			<span class="badge badge-light-primary">안내</span>
			<span class="fs-8 text-muted">2026-05-10 10:00</span>
		</div>
		<h2 class="fs-3 fw-bold text-gray-900 mb-4">5월 정산 일정 안내</h2>
		<div class="fs-6 text-gray-800 lh-lg">
			<p>안녕하세요.</p>
			<p>5월 정산 지급일은 <strong>5월 15일(목)</strong> 예정입니다. 자세한 내역은 정산 메뉴에서 확인해 주세요.</p>
			<p class="mb-0 text-muted fs-7">팝업 공지와 동일 본문을 레이어로 띄우는 동작은 이후 단계에서 적용할 수 있습니다.</p>
		</div>
		<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light w-100 mt-6">목록으로</a>
	</div>
</div>

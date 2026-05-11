<?php

declare(strict_types=1);
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">공지</h2>
	</div>
	<div class="card-body pt-0">
		<div class="d-flex flex-column gap-2">
			<a href="<?= htmlspecialchars(rider_url('notices/detail'), ENT_QUOTES, 'UTF-8') ?>" class="d-block border border-gray-200 rounded p-4 text-gray-800 text-hover-primary">
				<div class="d-flex justify-content-between mb-1">
					<span class="badge badge-light-danger fs-9">긴급</span>
					<span class="fs-8 text-muted">2026-05-09</span>
				</div>
				<div class="fw-bold">앱 점검 안내 (5/12)</div>
			</a>
			<a href="<?= htmlspecialchars(rider_url('notices/detail'), ENT_QUOTES, 'UTF-8') ?>" class="d-block border border-gray-200 rounded p-4 text-gray-800 text-hover-primary">
				<div class="d-flex justify-content-between mb-1">
					<span class="badge badge-light-primary fs-9">안내</span>
					<span class="fs-8 text-muted">2026-05-10</span>
				</div>
				<div class="fw-bold">5월 정산 일정 안내</div>
			</a>
		</div>
	</div>
</div>

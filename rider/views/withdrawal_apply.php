<?php

declare(strict_types=1);
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청</h2>
		<span class="text-gray-500 fs-7">금액·계좌 확인 후 제출</span>
	</div>
	<div class="card-body pt-0">
		<div class="mb-4">
			<label class="form-label">출금 가능 잔액 (샘플)</label>
			<div class="fs-2 fw-bold text-gray-900">₩ 512,400</div>
		</div>
		<div class="mb-4">
			<label class="form-label">신청 금액</label>
			<input type="text" class="form-control form-control-solid" placeholder="₩" disabled />
		</div>
		<div class="mb-4">
			<label class="form-label">입금 계좌</label>
			<div class="form-control form-control-solid bg-light">신한 ****1234 · 홍길동</div>
			<div class="form-text"><a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>">계좌 변경</a></div>
		</div>
		<button type="button" class="btn btn-primary w-100" disabled>제출 (준비 중)</button>
	</div>
</div>

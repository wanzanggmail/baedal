<?php

declare(strict_types=1);
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">비밀번호 변경</h2>
	</div>
	<div class="card-body pt-0">
		<div class="mb-4">
			<label class="form-label">현재 비밀번호</label>
			<input type="password" class="form-control form-control-solid" autocomplete="current-password" disabled />
		</div>
		<div class="mb-4">
			<label class="form-label">새 비밀번호</label>
			<input type="password" class="form-control form-control-solid" autocomplete="new-password" disabled />
		</div>
		<div class="mb-4">
			<label class="form-label">새 비밀번호 확인</label>
			<input type="password" class="form-control form-control-solid" autocomplete="new-password" disabled />
		</div>
		<button type="button" class="btn btn-primary w-100" disabled>저장 (준비 중)</button>
	</div>
</div>

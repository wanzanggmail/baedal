<?php

declare(strict_types=1);

/**
 * 내 계정 — 본인 정보 확인 · 이름/이메일 수정 · 비밀번호 변경 (2026-09-06 갑).
 *
 * 지금까지 관리자 계정은 **본인 비밀번호를 바꿀 방법이 없었다**. 남의 계정을 고치는
 * 화면(시스템 > 관리자·권한, 대표·서브계정 관리)만 있어서, 초기 비밀번호를 받은 계정이
 * 그대로 쓰는 수밖에 없었다.
 */

$myId = (int) ($_SESSION['admin_id'] ?? 0);
$me   = $myId > 0 ? db_row('SELECT * FROM admins WHERE id = ? LIMIT 1', [$myId]) : null;
$org  = admin_org();
$api  = ADMIN_BASE . '/api/mypage.php';
$esc  = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">내 계정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">내 계정</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($me === null) : ?>
	<div class="alert alert-danger">계정 정보를 불러올 수 없습니다. 다시 로그인해 주세요.</div>
	<?php else : ?>

	<div id="mp_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="mp_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<!--begin::내 정보-->
		<div class="col-lg-5">
			<div class="card card-flush h-100">
				<div class="card-header pt-6">
					<h3 class="card-title fw-bold text-gray-900">내 정보</h3>
				</div>
				<div class="card-body pt-2">
					<div class="d-flex align-items-center mb-6">
						<div class="symbol symbol-60px me-4">
							<div class="symbol-label fs-2 fw-bold bg-light-primary text-primary">
								<?= $esc(mb_substr((string) $me['name'], 0, 1)) ?>
							</div>
						</div>
						<div>
							<div class="fw-bold fs-5 text-gray-900"><?= $esc((string) $me['name']) ?></div>
							<div class="text-muted fs-7">
								<?= $esc(admin_role_label((string) $me['role'])) ?>
								<?php if ($org !== null) : ?>
								· <?= $esc((string) ($org['name'] ?? '')) ?>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<div class="mb-4">
						<label class="form-label fw-semibold fs-7">로그인 ID</label>
						<input type="text" class="form-control form-control-solid" value="<?= $esc((string) $me['login_id']) ?>" disabled />
						<div class="form-text fs-8">로그인 ID는 바꿀 수 없습니다.</div>
					</div>
					<div class="mb-4">
						<label class="form-label fw-semibold fs-7" for="mp_name">이름</label>
						<input type="text" class="form-control form-control-solid" id="mp_name" maxlength="80" value="<?= $esc((string) $me['name']) ?>" />
					</div>
					<div class="mb-5">
						<label class="form-label fw-semibold fs-7" for="mp_email">이메일</label>
						<input type="email" class="form-control form-control-solid" id="mp_email" maxlength="120" value="<?= $esc((string) ($me['email'] ?? '')) ?>" />
					</div>
					<button type="button" class="btn btn-light-primary w-100" id="mp_save_profile">내 정보 저장</button>

					<div class="separator separator-dashed my-5"></div>
					<div class="text-muted fs-8">
						최근 로그인 <?= $esc((string) ($me['last_login_at'] ?? '—')) ?>
					</div>
				</div>
			</div>
		</div>
		<!--end::내 정보-->

		<!--begin::비밀번호 변경-->
		<div class="col-lg-7">
			<div class="card card-flush h-100">
				<div class="card-header pt-6">
					<h3 class="card-title fw-bold text-gray-900">비밀번호 변경</h3>
				</div>
				<div class="card-body pt-2">
					<div class="mb-4">
						<label class="form-label fw-semibold fs-7" for="mp_cur">현재 비밀번호</label>
						<input type="password" class="form-control form-control-solid" id="mp_cur" autocomplete="current-password" />
					</div>
					<div class="mb-4">
						<label class="form-label fw-semibold fs-7" for="mp_new">새 비밀번호</label>
						<input type="password" class="form-control form-control-solid" id="mp_new" autocomplete="new-password" />
						<div class="form-text fs-8">8자 이상. 영문·숫자·기호를 섞을수록 안전합니다.</div>
					</div>
					<div class="mb-5">
						<label class="form-label fw-semibold fs-7" for="mp_confirm">새 비밀번호 확인</label>
						<input type="password" class="form-control form-control-solid" id="mp_confirm" autocomplete="new-password" />
						<div class="form-text fs-8 d-none" id="mp_match"></div>
					</div>
					<button type="button" class="btn btn-primary w-100" id="mp_save_password">비밀번호 변경</button>

					<div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-4 mt-6">
						<i class="ki-duotone ki-shield-tick fs-2tx text-warning me-4"><span class="path1"></span><span class="path2"></span></i>
						<div class="fs-8 text-gray-700">
							<div class="mb-1">설치 직후 받은 <span class="fw-bold">기본 비밀번호를 그대로 쓰고 있다면 지금 바꿔주세요.</span></div>
							<div>비밀번호는 저장 시 암호화되며, 변경 사실만 감사 로그에 남습니다(비밀번호 값은 남기지 않습니다).</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<!--end::비밀번호 변경-->
	</div>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>
<script>
(function () {
	var API = <?= json_encode($api, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	var toast = document.getElementById('mp_toast');
	var toastMsg = document.getElementById('mp_toast_msg');
	if (!toast) { return; }

	function show(msg, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = msg;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function post(payload, btn, done) {
		if (btn) { btn.disabled = true; }
		fetch(API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) {
			return r.json().catch(function () { return { ok: false, message: '서버 응답을 읽을 수 없습니다.' }; });
		}).then(function (d) {
			show(d.message || (d.ok ? '저장되었습니다.' : '실패했습니다.'), !!d.ok);
			if (d.ok && done) { done(); }
		}).catch(function () {
			show('네트워크 오류가 발생했습니다.', false);
		}).finally(function () {
			if (btn) { btn.disabled = false; }
		});
	}

	var saveProfile = document.getElementById('mp_save_profile');
	if (saveProfile) {
		saveProfile.addEventListener('click', function () {
			post({
				action: 'profile',
				name: document.getElementById('mp_name').value.trim(),
				email: document.getElementById('mp_email').value.trim()
			}, saveProfile);
		});
	}

	// 확인란 실시간 일치 표시 — 저장 눌러보고 나서야 틀린 걸 알면 답답하다.
	var newEl = document.getElementById('mp_new');
	var cfEl = document.getElementById('mp_confirm');
	var matchEl = document.getElementById('mp_match');
	function checkMatch() {
		if (!cfEl.value) { matchEl.className = 'form-text fs-8 d-none'; return; }
		var same = newEl.value === cfEl.value;
		matchEl.className = 'form-text fs-8 ' + (same ? 'text-success' : 'text-danger');
		matchEl.textContent = same ? '일치합니다.' : '새 비밀번호와 다릅니다.';
	}
	if (newEl && cfEl) {
		newEl.addEventListener('input', checkMatch);
		cfEl.addEventListener('input', checkMatch);
	}

	var savePw = document.getElementById('mp_save_password');
	if (savePw) {
		savePw.addEventListener('click', function () {
			post({
				action: 'password',
				current: document.getElementById('mp_cur').value,
				'new': newEl.value,
				confirm: cfEl.value
			}, savePw, function () {
				document.getElementById('mp_cur').value = '';
				newEl.value = '';
				cfEl.value = '';
				matchEl.className = 'form-text fs-8 d-none';
			});
		});
	}
})();
</script>

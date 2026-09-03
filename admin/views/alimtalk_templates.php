<?php

declare(strict_types=1);

require_once INC_PATH . '/AlimtalkTemplate.php';
require_once INC_PATH . '/MessagingConfig.php';

if (!admin_has_role('super')) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">알림톡 템플릿은 본사 최고관리자만 관리할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$esc    = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$apiUrl = ADMIN_BASE . '/api/alimtalk_templates.php';
$ready  = AlimtalkTemplate::ready();
$rows   = $ready ? AlimtalkTemplate::all() : [];
$cfg    = MessagingConfig::get();

// event_key => row
$byEvent = [];
foreach ($rows as $r) {
    $byEvent[(string) $r['event_key']] = $r;
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">알림톡 템플릿</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">상황별 템플릿·채널</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if (!$ready) : ?>
	<div class="alert alert-warning mb-6">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="at_toast" class="alert alert-dismissible d-none mb-6" role="alert"><span id="at_toast_msg"></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>

	<div class="alert bg-light-info fs-8 p-3 mb-6">
		ℹ️ 알림톡은 <strong>카카오에 사전 승인된 템플릿</strong>만 발송할 수 있습니다. 본문에서 변하는 부분은 <code>#{키}</code> 치환변수로 두고, 시스템이 발송할 때 값을 채웁니다.<br>
		발신 프로필키(채널)는 계정 단위라 <a href="<?= $esc(admin_url('system/messages')) ?>">문자·알림톡 설정</a>에서 관리합니다 — 현재:
		<strong><?= $cfg['alimtalk_channel'] !== '' ? $esc($cfg['alimtalk_channel']) : '미설정' ?></strong>
	</div>

	<div class="card card-flush mb-6">
		<div class="card-header pt-5 flex-wrap gap-2">
			<h3 class="card-title fw-bold">발송 요금 (현재 설정)</h3>
			<div class="card-toolbar">
				<a href="<?= $esc(admin_url('system/messages')) ?>" class="btn btn-sm btn-light">요금 변경</a>
			</div>
		</div>
		<div class="card-body pt-2 fs-7">
			<div class="d-flex flex-wrap gap-6">
				<div><span class="text-muted">알림톡</span> <strong><?= number_format((int) $cfg['price_alimtalk']) ?>원</strong></div>
				<div><span class="text-muted">SMS</span> <strong><?= number_format((int) $cfg['price_sms']) ?>원</strong></div>
				<div><span class="text-muted">LMS</span> <strong><?= number_format((int) $cfg['price_lms']) ?>원</strong></div>
				<div><span class="text-muted">SMS 최대</span> <strong><?= (int) $cfg['sms_max_bytes'] ?>바이트</strong> <span class="text-muted fs-8">(초과 시 LMS)</span></div>
			</div>
			<div class="text-muted fs-8 mt-3">발송 1건마다 이 금액이 <strong>대리점 지갑에서 본사로</strong> 이체됩니다(지갑 원장 「메시지 발송 요금」). 발송 실패 건은 과금하지 않습니다.</div>
		</div>
	</div>

	<?php if ($rows === []) : ?>
	<div class="card card-flush mb-6"><div class="card-body text-center py-10">
		<div class="text-muted mb-4">등록된 템플릿이 없습니다.</div>
		<button type="button" class="btn btn-primary" id="at_seed">기본 템플릿 만들기</button>
	</div></div>
	<?php endif; ?>

	<?php foreach (AlimtalkTemplate::EVENTS as $key => $ev) :
		$t = $byEvent[$key] ?? null; ?>
	<div class="card card-flush mb-6 at-card" data-event="<?= $esc($key) ?>">
		<div class="card-header pt-5 flex-wrap gap-2">
			<div>
				<h3 class="card-title fw-bold mb-1"><?= $esc($ev['label']) ?>
					<?php if ($t !== null && (int) $t['is_active'] === 1) : ?>
						<span class="badge badge-light-success ms-2">사용중</span>
					<?php else : ?>
						<span class="badge badge-light-secondary ms-2">미사용</span>
					<?php endif; ?>
				</h3>
				<div class="text-muted fs-8"><?= $esc($ev['desc']) ?></div>
			</div>
		</div>
		<div class="card-body pt-2 fs-7">
			<div class="row g-4">
				<div class="col-md-4">
					<label class="form-label">템플릿 코드 <span class="text-muted fs-9">(카카오 승인)</span></label>
					<input type="text" class="form-control form-control-solid at-code" value="<?= $esc($t['template_code'] ?? '') ?>" placeholder="예: STMT_001" />
				</div>
				<div class="col-md-4">
					<label class="form-label">강조 제목 <span class="text-muted fs-9">(선택)</span></label>
					<input type="text" class="form-control form-control-solid at-title" value="<?= $esc($t['title'] ?? '') ?>" maxlength="120" />
				</div>
				<div class="col-md-4">
					<label class="form-label">채널 정책</label>
					<select class="form-select form-select-solid at-policy">
						<option value="alimtalk_first" <?= (($t['channel_policy'] ?? 'alimtalk_first') === 'alimtalk_first') ? 'selected' : '' ?>>알림톡 우선 (실패 시 문자 대체)</option>
						<option value="sms_only" <?= (($t['channel_policy'] ?? '') === 'sms_only') ? 'selected' : '' ?>>문자만 (SMS/LMS 자동)</option>
					</select>
				</div>
				<div class="col-12">
					<label class="form-label">본문</label>
					<textarea class="form-control form-control-solid at-content" rows="7" maxlength="2000"><?= $esc($t['content'] ?? '') ?></textarea>
					<div class="form-text fs-9">
						사용 가능한 치환변수:
						<?php foreach ($ev['vars'] as $v) : ?><code class="me-1">#{<?= $esc($v) ?>}</code><?php endforeach; ?>
					</div>
				</div>
				<div class="col-12">
					<label class="form-check form-switch form-check-custom form-check-solid">
						<input class="form-check-input me-3 at-active" type="checkbox" <?= ($t !== null && (int) $t['is_active'] === 1) ? 'checked' : '' ?> />
						<span class="fw-semibold">이 상황에서 사용</span>
					</label>
					<div class="text-muted fs-9 mt-1">끄면 이 상황은 기존 문구 그대로 <strong>문자</strong>로 나갑니다(알림톡 미사용).</div>
				</div>
			</div>
			<div class="d-flex justify-content-between align-items-center mt-4">
				<button type="button" class="btn btn-sm btn-light at-preview">미리보기</button>
				<button type="button" class="btn btn-sm btn-primary at-save">저장</button>
			</div>
			<pre class="at-preview-box bg-light rounded p-3 fs-8 mt-3 d-none" style="white-space:pre-wrap"></pre>
		</div>
	</div>
	<?php endforeach; ?>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('at_toast'), toastMsg = document.getElementById('at_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); window.scrollTo(0, 0); }
		function post(payload) {
			return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); });
		}

		var seedBtn = document.getElementById('at_seed');
		if (seedBtn) {
			seedBtn.addEventListener('click', function () {
				post({ action: 'seed' }).then(function (res) {
					if (!res.ok) { showToast(res.message || '실패', false); return; }
					showToast(res.message, true);
					setTimeout(function () { location.reload(); }, 700);
				});
			});
		}

		document.querySelectorAll('.at-card').forEach(function (card) {
			var ev = card.getAttribute('data-event');
			var get = function (cls) { return card.querySelector('.' + cls); };

			get('at-save').addEventListener('click', function () {
				var btn = this; btn.disabled = true;
				post({
					action: 'save',
					event_key: ev,
					template_code: get('at-code').value.trim(),
					title: get('at-title').value.trim(),
					channel_policy: get('at-policy').value,
					content: get('at-content').value,
					is_active: get('at-active').checked ? 1 : 0
				}).then(function (res) {
					btn.disabled = false;
					showToast(res.ok ? '저장했습니다.' : (res.message || '저장 실패'), !!res.ok);
					if (res.ok) setTimeout(function () { location.reload(); }, 600);
				}).catch(function () { btn.disabled = false; showToast('저장 요청 실패', false); });
			});

			get('at-preview').addEventListener('click', function () {
				var box = get('at-preview-box');
				post({ action: 'preview', event_key: ev, content: get('at-content').value })
					.then(function (res) {
						if (!res.ok) { showToast(res.message || '실패', false); return; }
						var ch = res.sms_channel.toUpperCase();
						box.textContent = res.rendered
							+ '\n\n--- ' + res.bytes + '바이트 · 문자로 나가면 ' + ch + '(' + res.price_sms + '원)'
							+ ' · 알림톡이면 ' + res.price_alimtalk + '원 ---';
						box.classList.remove('d-none');
					});
			});
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

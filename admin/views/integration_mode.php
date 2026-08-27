<?php

declare(strict_types=1);

/**
 * 연동 모드 — PG(결제)와 펌뱅킹(이체)의 모의/실연동을 한 화면에서 전환한다.
 *
 * 설정 화면이 따로 있는데도 이 화면을 두는 이유: **"지금 진짜 돈이 움직이는가"** 는
 * 한눈에 봐야 한다. 두 화면을 오가며 확인하면 한쪽만 켜 둔 걸 놓친다.
 *
 * 자격증명 입력은 여기서 하지 않는다 — 각 설정 화면으로 보낸다.
 * 여기는 **스위치만** 있는 곳이다.
 */

require_once INC_PATH . '/IntegrationMode.php';

$isSuper = admin_has_role('super') && admin_org_level() === Org::LEVEL_ADMIN;
if (!$isSuper) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">연동 모드는 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$status  = IntegrationMode::status();
$anyLive = IntegrationMode::anyLive();
$esc     = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">연동 모드</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">연동 모드</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<button type="button" class="btn btn-sm btn-light-danger fw-bold" id="im_all_mock">전체 모의로 되돌리기</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<div id="im_toast" class="alert alert-dismissible mb-6 d-none">
	<span id="im_toast_msg"></span>
	<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
</div>

<!--begin::전체 상태-->
<div class="alert <?= $anyLive ? 'alert-danger' : 'alert-success' ?> p-5 mb-6" id="im_summary">
	<?php if ($anyLive) : ?>
	<div class="fw-bold fs-5">⚠️ 실연동이 켜져 있습니다 — 실제 돈이 움직입니다.</div>
	<div class="fs-7 mt-1">시험 중이라면 켜져 있는 항목을 모의로 되돌리세요.</div>
	<?php else : ?>
	<div class="fw-bold fs-5 text-gray-800">전부 모의 모드입니다 — 실제 돈은 움직이지 않습니다.</div>
	<div class="fs-7 text-gray-600 mt-1">마음껏 시험해도 됩니다.</div>
	<?php endif; ?>
</div>
<!--end::전체 상태-->

<div class="row g-5 g-xl-8">
	<?php foreach ($status as $s) : ?>
	<div class="col-lg-6">
		<div class="card card-flush shadow-sm h-100" data-im-card="<?= $esc((string) $s['key']) ?>">
			<div class="card-header pt-5">
				<div class="card-title flex-column">
					<h3 class="fw-bold m-0"><?= $esc((string) $s['label']) ?></h3>
					<span class="text-gray-500 fs-8 mt-1">
						<?= $esc((string) $s['provider']) ?> · 돈이 <strong><?= $esc((string) $s['direction']) ?></strong>
					</span>
				</div>
				<div class="card-toolbar">
					<span class="badge badge-<?= $s['live'] ? 'danger' : 'secondary' ?> fs-7 px-4 py-2" data-im-badge>
						<?= $s['live'] ? '실연동' : '모의' ?>
					</span>
				</div>
			</div>
			<div class="card-body pt-2 d-flex flex-column">
				<div class="text-gray-700 fs-7 mb-4">
					<div class="fw-semibold text-gray-800 mb-1">영향받는 기능</div>
					<ul class="mb-0 ps-4">
						<?php foreach ((array) $s['affects'] as $a) : ?>
						<li><?= $esc((string) $a) ?></li>
						<?php endforeach; ?>
					</ul>
				</div>

				<?php if ((string) $s['key'] === IntegrationMode::CH_FIRM) : ?>
				<div class="fs-8 text-gray-600 mb-4">
					접속 서버: <code><?= $esc((string) ($s['env'] ?? 'dev')) === 'prod' ? '운영 (firm-api)' : '개발 (dev-firm-api)' ?></code>
					<span class="d-block mt-1">서버 구분은 <a href="<?= $esc(admin_url((string) $s['settings'])) ?>">설정 화면</a>에서 바꿉니다.</span>
				</div>
				<?php endif; ?>

				<?php if (!$s['can_go_live']) : ?>
				<div class="alert alert-warning fs-8 py-3 px-4 mb-4">
					<span class="fw-semibold">실연동에 필요한 값이 없습니다</span> —
					<?= $esc(implode(', ', (array) $s['missing'])) ?>
					<a class="d-block mt-1" href="<?= $esc(admin_url((string) $s['settings'])) ?>">설정 화면에서 입력하기 →</a>
				</div>
				<?php endif; ?>

				<div class="mt-auto">
					<div class="d-flex gap-2 flex-wrap">
						<button type="button"
							class="btn <?= $s['live'] ? 'btn-light' : 'btn-secondary' ?> flex-grow-1"
							data-im-set="mock" data-im-channel="<?= $esc((string) $s['key']) ?>"
							<?= $s['live'] ? '' : 'disabled' ?>>모의로</button>
						<button type="button"
							class="btn <?= $s['live'] ? 'btn-danger' : 'btn-light-danger' ?> flex-grow-1"
							data-im-set="live" data-im-channel="<?= $esc((string) $s['key']) ?>"
							data-im-risk="<?= $esc(strip_tags(str_replace('**', '', (string) $s['risk']))) ?>"
							data-im-label="<?= $esc((string) $s['label']) ?>"
							<?= ($s['live'] || !$s['can_go_live']) ? 'disabled' : '' ?>>실연동으로</button>
					</div>
					<div class="form-text mt-2">
						<a href="<?= $esc(admin_url((string) $s['settings'])) ?>">자격증명·상세 설정 →</a>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endforeach; ?>
</div>

<div class="card card-flush shadow-sm mt-6">
	<div class="card-body py-5 fs-8 text-gray-700">
		<div class="fw-semibold text-gray-800 mb-2">알아 두실 것</div>
		<ul class="mb-0 ps-4">
			<li><strong>자격증명이 빠지면 실연동을 골라도 모의로 돕니다.</strong> 안 되는 걸 되는 척하지 않으려는 장치라, 위 배지가 실제 동작 상태입니다.</li>
			<li>펌뱅킹 이체는 <strong>접수만 즉시</strong> 되고 결과는 나중에 통보로 옵니다 — 실연동에서 출금은 「이체 접수중」을 거쳐 완료됩니다.</li>
			<li>전환은 모두 <a href="<?= $esc(admin_url('system/audit')) ?>">감사 로그</a>에 남습니다.</li>
		</ul>
	</div>
</div>

<script>
(function () {
	'use strict';
	// ⚠️ admin_url() 은 index.php?route=… 라우터 URL 이라 API 파일에 닿지 않는다.
	var API = <?= json_encode(ADMIN_BASE . '/api/integration_mode.php', JSON_UNESCAPED_SLASHES) ?>;
	var toast = document.getElementById('im_toast');
	var toastMsg = document.getElementById('im_toast_msg');

	function showToast(m, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = m;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}

	function post(payload, btn) {
		if (btn) { btn.disabled = true; }
		return fetch(API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) {
			return r.text().then(function (t) {
				try { return JSON.parse(t); }
				catch (e) { throw new Error('서버 응답을 해석할 수 없습니다 (HTTP ' + r.status + ')'); }
			});
		}).catch(function (e) {
			showToast(e.message || '요청 실패', false);
			return null;
		}).finally(function () {
			if (btn) { btn.disabled = false; }
		});
	}

	document.querySelectorAll('[data-im-set]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var live = btn.dataset.imSet === 'live';
			if (live) {
				// 돈이 실제로 움직이기 시작하는 지점 — 무엇이 벌어지는지 그대로 보여주고 묻는다.
				var msg = '[' + btn.dataset.imLabel + '] 을 실연동으로 켭니다.\n\n'
					+ btn.dataset.imRisk + '\n\n계속할까요?';
				if (!confirm(msg)) { return; }
			}
			post({ action: 'switch', channel: btn.dataset.imChannel, live: live }, btn).then(function (j) {
				if (!j) { return; }
				showToast(j.message || '전환했습니다.', !!j.ok);
				if (j.ok) { setTimeout(function () { location.reload(); }, 900); }
			});
		});
	});

	document.getElementById('im_all_mock').addEventListener('click', function () {
		if (!confirm('PG 와 펌뱅킹을 모두 모의로 되돌립니다.\n실제 결제·송금이 멈춥니다.\n\n계속할까요?')) { return; }
		post({ action: 'all_mock' }, this).then(function (j) {
			if (!j) { return; }
			showToast(j.message || '전환했습니다.', !!j.ok);
			if (j.ok) { setTimeout(function () { location.reload(); }, 900); }
		});
	});
})();
</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

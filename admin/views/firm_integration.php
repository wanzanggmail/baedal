<?php

declare(strict_types=1);

/**
 * 펌뱅킹(바움P&S) 연동 설정 — 본사 super 전용.
 *
 * 여기는 **돈이 나가는 통로**다. PG(카드 결제)는 돈이 들어오는 쪽이고, 여기는 라이더·대리점
 * 계좌로 실제 송금이 나간다. 그래서 실 연동 전환 경고를 더 강하게 띄운다.
 */

require_once INC_PATH . '/FirmConfig.php';
require_once INC_PATH . '/FirmBankingGateway.php';

// 최고관리자(본사·개발사)만 — 라우트 규칙(`admin_can_access_route`)과 같은 조건이다.
$isSuper = admin_has_role('super')
    && in_array(admin_org_level(), [Org::LEVEL_ADMIN, Org::LEVEL_DEVELOPER], true);
if (!$isSuper) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">펌뱅킹 연동 설정은 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$needsMigrate = !FirmConfig::tableExists();
$cfg          = $needsMigrate ? [] : FirmConfig::publicView();
$isMock       = FirmBankingGatewayFactory::isMock();

$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

require_once INC_PATH . '/FirmTransfer.php';
// 표(이체 현황·통보 수신)는 「펌뱅킹 이체 내역」 화면으로 옮겼다 — 여기서는 미확정 건수만 쓴다.
$pendingCount = $needsMigrate ? 0 : FirmTransfer::pendingCount();

// 바움 관리자에 등록할 통보 URL — 화면에서 복사해 쓴다.
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$notiUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/firm/noti.php';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">펌뱅킹 연동</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">펌뱅킹 연동</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<?php if ($needsMigrate) : ?>
<div class="alert alert-warning p-5"><code>firm_config</code> 테이블이 없습니다. 서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
<?php else : ?>

<div id="firm_toast" class="alert alert-dismissible mb-6 d-none">
	<span id="firm_toast_msg"></span>
	<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
</div>

<div class="alert <?= $isMock ? 'alert-secondary' : 'alert-danger' ?> p-5 mb-6">
	<div class="d-flex flex-stack flex-wrap gap-3">
		<div>
		<?php if ($isMock) : ?>
		<div class="fw-bold text-gray-800">모의 모드로 동작 중입니다 — 실제로 송금되지 않습니다.</div>
		<div class="fs-7 text-gray-600 mt-1">
			아래 값을 모두 채우고 드라이버를 <code>바움P&amp;S(실연동)</code> 으로 바꾸면 실 송금이 시작됩니다.
		</div>
		<?php else : ?>
		<div class="fw-bold">⚠️ 실 연동이 켜져 있습니다 — 출금 확정 시 <strong>실제로 돈이 나갑니다.</strong></div>
		<div class="fs-7 mt-1">시험 중이라면 드라이버를 <code>모의</code> 로 되돌리세요.</div>
		<?php endif; ?>
		</div>
		<a href="<?= $esc(admin_url('system/integration-mode')) ?>" class="btn btn-sm btn-light fw-bold text-nowrap">연동 모드에서 전환</a>
	</div>
</div>

<!--begin::비동기 안내-->
<div class="alert alert-warning p-5 mb-6">
	<div class="fw-bold text-gray-800 mb-1">이체 결과는 즉시 확정되지 않습니다</div>
	<div class="fs-7 text-gray-700">
		바움 API 는 <strong>접수(RECEPTION)</strong> 만 즉시 응답하고, 성공/실패는 나중에
		「계좌이체 처리결과 통보」(웹훅)로 옵니다.
		<span class="d-block mt-1">
			<code>RECEPTION → PROGRESS → NEED_CHECK → SUCCESS / FAILED / CANCELLED</code>
		</span>
		<span class="d-block mt-2 text-gray-800">
			출금은 접수 시 <strong>「이체 접수중」</strong> 으로 두고, <strong>통보를 받은 뒤에</strong> 완료 처리하며
			그때 지갑이 차감됩니다. 통보가 오지 않은 건은 아래 <strong>「보정 조회」</strong> 로 직접 확인하세요.
		</span>
	</div>
</div>
<!--end::비동기 안내-->

<div class="card card-flush shadow-sm mb-6">
	<div class="card-header pt-5">
		<div class="card-title">
			<h3 class="fw-bold m-0">연동 정보</h3>
			<span class="text-gray-500 fs-8 d-block mt-1">
				지금 <strong class="text-gray-800"><?= ($cfg['env'] ?? 'dev') === 'prod' ? '운영' : '개발' ?></strong> 서버의 자격증명을 보고 있습니다 —
				<strong>개발과 운영은 값이 전부 다르고 따로 저장됩니다.</strong>
				<span class="d-block mt-1">🔒 Secret Key·암호화 KEY/IV 는 DB 에 암호화 저장됩니다. 화면에는 앞 4자리만 보입니다.</span>
			</span>
		</div>
		<div class="card-toolbar">
			<span class="badge badge-light-<?= ($cfg['is_ready'] ?? false) ? 'success' : 'secondary' ?> fs-8">
				<?= ($cfg['is_ready'] ?? false) ? '실 연동 준비됨' : '설정 미완료' ?>
			</span>
		</div>
	</div>
	<div class="card-body">
		<div class="row g-5 mb-5">
			<div class="col-md-6">
				<label class="form-label fw-bold" for="firm_client_id">Client ID</label>
				<input type="text" class="form-control form-control-solid" id="firm_client_id" value="<?= $esc((string) ($cfg['client_id'] ?? '')) ?>" autocomplete="off" />
				<div class="form-text">바움에서 발급받은 값입니다. 식별자라 그대로 저장됩니다.</div>
			</div>
			<div class="col-md-6">
				<label class="form-label fw-bold" for="firm_secret_key">Secret Key</label>
				<input type="password" class="form-control form-control-solid" id="firm_secret_key" placeholder="<?= $esc((string) ($cfg['secret_key_masked'] ?? '')) ?: '미설정' ?>" autocomplete="new-password" />
				<div class="form-text">바꿀 때만 입력하세요. 비워두면 기존 값이 유지됩니다.</div>
			</div>
		</div>

		<div class="row g-5 mb-5">
			<div class="col-md-6">
				<label class="form-label fw-bold" for="firm_enc_key">암호화 KEY (Base64, 32바이트)</label>
				<input type="password" class="form-control form-control-solid" id="firm_enc_key" placeholder="<?= $esc((string) ($cfg['enc_key_masked'] ?? '')) ?: '미설정' ?>" autocomplete="off" />
			</div>
			<div class="col-md-6">
				<label class="form-label fw-bold" for="firm_enc_iv">암호화 IV (Base64, 16바이트)</label>
				<input type="password" class="form-control form-control-solid" id="firm_enc_iv" placeholder="<?= $esc((string) ($cfg['enc_iv_masked'] ?? '')) ?: '미설정' ?>" autocomplete="off" />
			</div>
			<div class="col-12">
				<div class="form-text">
					<code>/auth/access_token</code> 을 제외한 <strong>모든 요청·응답 Body 가 이 키로 AES-256-CBC 암호화</strong>됩니다.
					우리 DB 저장 암호화(<code>APP_ENC_KEY</code>)와는 별개의 키입니다.
				</div>
			</div>
		</div>

		<div class="row g-5 mb-5">
			<div class="col-md-4">
				<label class="form-label fw-bold" for="firm_pocket">출금 포켓코드</label>
				<input type="text" class="form-control form-control-solid" id="firm_pocket" value="<?= $esc((string) ($cfg['pocket_code'] ?? '')) ?>" autocomplete="off" />
				<div class="form-text">비우면 기본 포켓에서 나갑니다.</div>
			</div>
			<div class="col-md-4">
				<label class="form-label fw-bold" for="firm_env">서버</label>
				<select class="form-select form-select-solid" id="firm_env">
					<option value="dev"<?= ($cfg['env'] ?? 'dev') === 'dev' ? ' selected' : '' ?>>개발 (dev-firm-api)</option>
					<option value="prod"<?= ($cfg['env'] ?? '') === 'prod' ? ' selected' : '' ?>>운영 (firm-api)</option>
				</select>
				<div class="form-text">
					현재: <code><?= $esc((string) ($cfg['host'] ?? '')) ?></code>
					<span class="d-block mt-1">
						<?php foreach ((array) ($cfg['envs'] ?? []) as $e => $x) : ?>
						<span class="badge badge-light-<?= $x['filled'] ? 'success' : 'secondary' ?> me-1"><?= $e === 'prod' ? '운영' : '개발' ?> <?= $x['filled'] ? '설정됨' : '미설정' ?></span>
						<?php endforeach; ?>
					</span>
					<span class="d-block mt-1 text-gray-600">서버를 바꾸면 <strong>그 서버의 자격증명</strong>으로 함께 바뀝니다.</span>
				</div>
			</div>
			<div class="col-md-4">
				<label class="form-label fw-bold" for="firm_driver">드라이버</label>
				<select class="form-select form-select-solid" id="firm_driver">
					<option value="mock"<?= ($cfg['driver'] ?? 'mock') === 'mock' ? ' selected' : '' ?>>모의 (송금 안 됨)</option>
					<option value="baum"<?= ($cfg['driver'] ?? '') === 'baum' ? ' selected' : '' ?>>바움P&amp;S (실연동)</option>
				</select>
				<div class="form-text text-danger fw-semibold">실연동을 고르면 실제로 송금됩니다.</div>
			</div>
		</div>

		<div class="row g-5 mb-5">
			<div class="col-12">
				<label class="form-label fw-bold" for="firm_ips">처리결과 통보 허용 IP (쉼표 구분)</label>
				<input type="text" class="form-control form-control-solid" id="firm_ips" value="<?= $esc((string) ($cfg['noti_allow_ips'] ?? '')) ?>" autocomplete="off" />
				<div class="form-text">
					여기 없는 주소에서 온 통보는 거절합니다. <strong>매뉴얼에 발신 IP 가 적혀 있지 않으니 바움에 문의해 채우세요.</strong>
					비워두면 IP 검사를 하지 않습니다(권장하지 않음).
				</div>
			</div>
		</div>

		<div class="row g-5 mb-5">
			<div class="col-md-3">
				<label class="form-label fw-bold" for="firm_block_from">이체 제한 시작</label>
				<input type="time" class="form-control form-control-solid" id="firm_block_from" value="<?= $esc((string) ($cfg['block_from'] ?? '')) ?>" />
			</div>
			<div class="col-md-3">
				<label class="form-label fw-bold" for="firm_block_to">이체 제한 종료</label>
				<input type="time" class="form-control form-control-solid" id="firm_block_to" value="<?= $esc((string) ($cfg['block_to'] ?? '')) ?>" />
			</div>
			<div class="col-md-6">
				<label class="form-label fw-bold d-block">현재 상태</label>
				<?php if (empty($cfg['has_block_window'])) : ?>
				<span class="badge badge-light-warning fs-7">DB 마이그레이션 필요</span>
				<div class="form-text">배포 화면에서 <strong>DB 마이그레이션</strong>을 실행해야 이 값이 저장됩니다. 그때까지는 기본값(23:30~00:30)으로 동작합니다.</div>
				<?php else : ?>
				<?php if (!empty($cfg['blocked_now'])) : ?>
				<span class="badge badge-light-danger fs-7">지금은 이체 제한 시간</span>
				<?php else : ?>
				<span class="badge badge-light-success fs-7">지금은 이체 가능</span>
				<?php endif; ?>
				<?php endif; ?>
				<div class="form-text">
					은행 점검 시간대에 보내면 바움이 <code>TRANSFER_RESTRICTED_TIME</code> 으로 거절하고,
					거절된 건은 <strong>실패로 남아 라이더가 재신청도 못 합니다.</strong>
					이 시간대에는 아예 보내지 않고 <strong>신청 상태 그대로 대기</strong>시킵니다 — 시간이 지난 뒤 그대로 확정하면 됩니다.
					자정을 넘는 구간(예: 23:30~00:30)도 됩니다. 비우면 제한하지 않습니다.
				</div>
			</div>
		</div>

		<div class="d-flex gap-2 flex-wrap">
			<button type="button" class="btn btn-primary" id="firm_save">설정 저장</button>
			<button type="button" class="btn btn-light-primary" id="firm_test">연결 테스트</button>
		</div>
		<div class="form-text mt-2">연결 테스트는 토큰 발급 후 <strong>잔액 조회</strong>만 합니다 — 돈을 움직이지 않습니다.</div>
	</div>
</div>

<!--begin::이체 내역 바로가기-->
<div class="card card-flush shadow-sm mb-6">
	<div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-5">
		<div>
			<div class="fw-bold text-gray-900 fs-6">이체 현황 · 처리결과 통보</div>
			<div class="text-gray-500 fs-8 mt-1">
				보낸 이체와 받은 통보는 <strong>「펌뱅킹 이체 내역」</strong> 화면에서 탭으로 봅니다.
				<?php if ($pendingCount > 0) : ?>
				<span class="badge badge-light-warning fs-8 ms-2">미확정 <?= number_format($pendingCount) ?>건</span>
				<?php endif; ?>
			</div>
		</div>
		<a href="<?= $esc(admin_url('system/firm-monitor')) ?>" class="btn btn-sm btn-light-primary fw-bold text-nowrap">이체 내역 보기</a>
	</div>
</div>
<!--end::이체 내역 바로가기-->

<!--begin::통보 URL 등록-->
<div class="card card-flush shadow-sm mb-6">
	<div class="card-header pt-5">
		<div class="card-title">
			<h3 class="fw-bold m-0">통보 URL 등록</h3>
			<span class="text-gray-500 fs-8 d-block mt-1">
				바움에 <strong>이 주소를 등록해야 처리결과 통보가 옵니다.</strong> 등록 전에는 이체가 「접수중」에서 멈춥니다.
			</span>
		</div>
		<div class="card-toolbar">
			<button type="button" class="btn btn-sm btn-light" id="firm_wh_list">목록 조회</button>
		</div>
	</div>
	<div class="card-body pt-0">
		<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
			<input type="text" class="form-control form-control-solid flex-grow-1 min-w-250px" id="firm_wh_url" value="<?= $esc($notiUrl) ?>" />
			<button type="button" class="btn btn-sm btn-primary text-nowrap" id="firm_wh_register">이 주소 등록</button>
		</div>
		<div class="form-text mb-4">
			재전송 간격 1분 · 최대 10회 · 응답 대기 60초로 등록합니다. 포켓코드는 비워서 <strong>모든 포켓의 통보</strong>를 받습니다.
			주소가 바뀌면 옛 주소는 삭제하세요 — 등록된 모든 주소로 통보가 갑니다.
		</div>
		<div id="firm_wh_result" class="text-gray-800 fs-7"><span class="text-muted">「목록 조회」를 눌러 현재 등록 상태를 확인하세요.</span></div>
	</div>
</div>
<!--end::통보 URL 등록-->
<!--begin::예금주 조회-->
<div class="card card-flush shadow-sm">
	<div class="card-header pt-5">
		<div class="card-title">
			<h3 class="fw-bold m-0">예금주 조회 (시험)</h3>
			<span class="text-gray-500 fs-8 d-block mt-1">계좌가 실제로 존재하는지, 예금주가 누구인지 확인합니다.</span>
		</div>
	</div>
	<div class="card-body">
		<div class="row g-3 align-items-end">
			<div class="col-md-4">
				<label class="form-label fw-bold" for="firm_ah_bank">은행</label>
				<select class="form-select form-select-solid" id="firm_ah_bank">
					<option value="">선택하세요…</option>
					<?php foreach (db_rows("SELECT code, label FROM system_codes WHERE category = 'bank' ORDER BY sort_order, code") as $b) : ?>
					<option value="<?= $esc((string) $b['code']) ?>"><?= $esc((string) $b['label']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-md-5">
				<label class="form-label fw-bold" for="firm_ah_acct">계좌번호</label>
				<input type="text" class="form-control form-control-solid font-monospace" id="firm_ah_acct" placeholder="숫자만" autocomplete="off" />
			</div>
			<div class="col-md-3">
				<button type="button" class="btn btn-light-primary w-100" id="firm_ah_go">조회</button>
			</div>
		</div>
		<div class="mt-3 fs-7" id="firm_ah_result"></div>
	</div>
</div>
<!--end::예금주 조회-->

<script>
(function () {
	'use strict';
	// ⚠️ admin_url() 은 index.php?route=… 라우터 URL 이라 API 파일에 닿지 않는다(404 HTML).
	var API = <?= json_encode(ADMIN_BASE . '/api/firm_config.php', JSON_UNESCAPED_UNICODE) ?>;
	var toast = document.getElementById('firm_toast');
	var toastMsg = document.getElementById('firm_toast_msg');

	function showToast(m, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = m;
		window.scrollTo({ top: 0, behavior: 'smooth' });
	}
	function val(id) { return (document.getElementById(id).value || '').trim(); }

	function post(payload, btn, done) {
		if (btn) { btn.disabled = true; }
		fetch(API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) {
			return r.text().then(function (t) {
				var j;
				try { j = JSON.parse(t); } catch (e) { throw new Error('서버 응답을 해석할 수 없습니다 (HTTP ' + r.status + ')'); }
				return j;
			});
		}).then(function (j) {
			done(j);
		}).catch(function (e) {
			showToast(e.message || '요청 실패', false);
		}).finally(function () {
			if (btn) { btn.disabled = false; }
		});
	}

	document.getElementById('firm_save').addEventListener('click', function () {
		var p = {
			action: 'save',
			driver: val('firm_driver'),
			env: val('firm_env'),
			client_id: val('firm_client_id'),
			pocket_code: val('firm_pocket'),
			noti_allow_ips: val('firm_ips'),
			block_from: val('firm_block_from'),
			block_to: val('firm_block_to')
		};
		// 비밀값은 입력했을 때만 보낸다 — 빈 값은 "안 건드림" 이다.
		['secret_key', 'enc_key', 'enc_iv'].forEach(function (k) {
			var v = val('firm_' + k);
			if (v !== '') { p[k] = v; }
		});
		if (p.driver === 'baum' && !confirm('실 연동을 켜면 출금 확정 시 실제로 돈이 나갑니다.\n계속할까요?')) { return; }

		post(p, this, function (j) {
			showToast(j.message || (j.ok ? '저장했습니다.' : '저장 실패'), !!j.ok);
			if (j.ok) { setTimeout(function () { location.reload(); }, 800); }
		});
	});

	document.getElementById('firm_test').addEventListener('click', function () {
		post({ action: 'test' }, this, function (j) {
			showToast(j.message || (j.ok ? '연결 성공' : '연결 실패'), !!j.ok);
		});
	});

	// ── 통보 URL 관리 ──
	var whBox = document.getElementById('firm_wh_result');
	function whRender(urls) {
		if (!urls || !urls.length) {
			whBox.innerHTML = '<span class="text-danger fw-bold">등록된 통보 URL 이 없습니다 — 통보가 오지 않습니다.</span>';
			return;
		}
		var mine = document.getElementById('firm_wh_url').value.trim();
		var html = '<div class="fw-bold mb-2">등록된 주소 ' + urls.length + '건</div>';
		urls.forEach(function (u) {
			var same = (u.url || '') === mine;
			html += '<div class="d-flex flex-wrap align-items-center gap-2 mb-2">'
				+ '<span class="badge badge-light-' + (u.useUrl === false ? 'secondary' : 'success') + '">'
				+ (u.useUrl === false ? '미사용' : '사용중') + '</span>'
				+ (same ? '<span class="badge badge-light-primary">이 서버</span>' : '')
				+ '<code class="text-gray-800">' + esc(u.url || '') + '</code>'
				+ '<span class="text-muted fs-8">' + (u.sendDelay || '?') + '분 간격 · 최대 ' + (u.sendMax || '?') + '회 · ' + (u.readTimeout || '?') + '초</span>'
				+ '<button type="button" class="btn btn-sm btn-light-danger py-1 px-2 fs-8 firm-wh-del" data-url="' + esc(u.url || '') + '">삭제</button>'
				+ '</div>';
		});
		whBox.innerHTML = html;
		whBox.querySelectorAll('.firm-wh-del').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('이 통보 URL 을 삭제할까요?\n삭제하면 그 주소로는 처리결과 통보가 가지 않습니다.\n\n' + b.dataset.url)) { return; }
				post({ action: 'webhook_delete', url: b.dataset.url }, b, function (j) {
					showToast(j.message || (j.ok ? '삭제했습니다.' : '삭제 실패'), !!j.ok);
					post({ action: 'webhook_list' }, b, function (r) { if (r.ok) { whRender(r.urls); } });
				});
			});
		});
	}
	function esc(v) {
		return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
		});
	}
	var whListBtn = document.getElementById('firm_wh_list');
	if (whListBtn) {
		whListBtn.addEventListener('click', function () {
			post({ action: 'webhook_list' }, this, function (j) {
				if (!j.ok) { showToast(j.message || '조회 실패', false); return; }
				whRender(j.urls);
			});
		});
	}
	var whRegBtn = document.getElementById('firm_wh_register');
	if (whRegBtn) {
		whRegBtn.addEventListener('click', function () {
			var u = document.getElementById('firm_wh_url').value.trim();
			if (!confirm('이 주소로 처리결과 통보를 받도록 바움에 등록합니다.\n\n' + u)) { return; }
			post({ action: 'webhook_register', url: u }, this, function (j) {
				showToast(j.message || (j.ok ? '등록했습니다.' : '등록 실패'), !!j.ok);
				post({ action: 'webhook_list' }, whRegBtn, function (r) { if (r.ok) { whRender(r.urls); } });
			});
		});
	}
	var reconcileBtn = document.getElementById('firm_reconcile');
	if (reconcileBtn) {
		reconcileBtn.addEventListener('click', function () {
			post({ action: 'reconcile', min_age: 5 }, this, function (j) {
				showToast(j.message || (j.ok ? '조회 완료' : '조회 실패'), !!j.ok);
				// 확정된 건이 있으면 표를 새로 그린다.
				if (j.ok && j.result && j.result.finalized > 0) {
					setTimeout(function () { location.reload(); }, 1200);
				}
			});
		});
	}

	document.getElementById('firm_ah_go').addEventListener('click', function () {
		var out = document.getElementById('firm_ah_result');
		out.innerHTML = '';
		var bank = val('firm_ah_bank'), acct = val('firm_ah_acct');
		if (!bank || !acct) { out.innerHTML = '<span class="text-danger">은행과 계좌번호를 입력하세요.</span>'; return; }

		post({ action: 'account_holder', bank_code: bank, account_no: acct }, this, function (j) {
			out.innerHTML = j.ok
				? '<span class="badge badge-light-success">확인</span> <strong>' + (j.holder || '') + '</strong>'
				: '<span class="badge badge-light-danger">실패</span> ' + (j.message || '조회하지 못했습니다.');
		});
	});
})();
</script>

<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

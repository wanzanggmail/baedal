<?php

declare(strict_types=1);

/**
 * PG(위루트) 연동·결제통지 — 본사 super 전용.
 *
 * 두 가지를 한 화면에서 본다.
 *   ① 가맹점 관리자에 등록할 **Noti URL**과 허용 IP·자격증명 설정
 *   ② 실제로 들어온 **결제통지 수신 이력**(대사용)
 *
 * 결제통지는 지갑을 움직이지 않는다 — 우리 결제는 동기 흐름이라 승인 응답에서 이미
 * 반영되고, 통지는 그 기록이 PG 기록과 맞는지 대조하는 용도다(§PgWebhook).
 */

require_once INC_PATH . '/PgConfig.php';
require_once INC_PATH . '/PgWebhook.php';

$isSuper = admin_has_role('super') && admin_org_level() === Org::LEVEL_ADMIN;
if (!$isSuper) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">PG 연동 설정은 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$needsMigrate = !PgConfig::tableExists() || !PgWebhook::tableExists();
$cfg   = $needsMigrate ? [] : PgConfig::get();
$pub   = $needsMigrate ? [] : PgConfig::publicView();
$allow = PgWebhook::allowedIps();

// Noti URL — 지금 접속한 주소를 기준으로 만들어 준다. 그대로 복사해 가맹점 관리자에 넣으면 된다.
$notiUrl = rtrim(web_request_origin(), '/') . '/pg/noti.php';

$filterState = trim((string) ($_GET['state'] ?? ''));
$events = [];
$stats  = ['matched' => 0, 'unmatched' => 0, 'mismatch' => 0, 'ignored' => 0];
if (!$needsMigrate) {
    foreach (db_rows('SELECT match_state, COUNT(*) c FROM pg_webhook_events GROUP BY match_state') as $r) {
        $stats[(string) $r['match_state']] = (int) $r['c'];
    }
    $where  = [];
    $params = [];
    if (in_array($filterState, ['matched', 'unmatched', 'mismatch', 'ignored'], true)) {
        $where[]  = 'match_state = ?';
        $params[] = $filterState;
    }
    $events = db_rows(
        'SELECT * FROM pg_webhook_events'
        . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY id DESC LIMIT 200',
        $params
    );
}

$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$stateBadge = [
    'matched'   => ['label' => '대조됨', 'badge' => 'success'],
    'unmatched' => ['label' => '미대조', 'badge' => 'warning'],
    'mismatch'  => ['label' => '금액 불일치', 'badge' => 'danger'],
    'ignored'   => ['label' => '대상 아님', 'badge' => 'secondary'],
];
$currentUrl = admin_url('system/pg-integration');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">PG 연동·결제통지</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">PG 연동</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<?php if ($needsMigrate) : ?>
<div class="alert alert-warning p-5">PG 연동 테이블이 아직 없습니다. 서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
<?php else : ?>

<div id="pg_toast" class="alert alert-dismissible d-none mb-6" role="alert"><span id="pg_toast_msg"></span></div>

<div class="row g-5 g-xl-8">
	<!--begin::결제통지 설정-->
	<div class="col-xl-7">
		<div class="card card-flush h-100">
			<div class="card-header pt-5">
				<h3 class="card-title fw-bold">결제통지(Webhook)</h3>
			</div>
			<div class="card-body pt-0 fs-7">
				<div class="mb-6">
					<label class="form-label fw-bold">Noti URL <span class="text-muted fw-normal">— 가맹점 관리자에 등록</span></label>
					<div class="input-group">
						<input type="text" class="form-control form-control-solid font-monospace" id="pg_noti_url" value="<?= $esc($notiUrl) ?>" readonly />
						<button type="button" class="btn btn-light-primary" id="pg_copy_url">복사</button>
					</div>
					<div class="form-text">세션 인증이 없는 공개 경로입니다. 아래 <strong>허용 IP</strong>와 <strong>서명</strong>으로 막습니다.</div>
				</div>

				<div class="mb-6">
					<label class="form-label fw-bold" for="pg_allow_ips">허용 IP <span class="text-muted fw-normal">(쉼표 구분)</span></label>
					<input type="text" class="form-control form-control-solid font-monospace" id="pg_allow_ips"
						value="<?= $esc((string) ($cfg['noti_allow_ips'] ?? '')) ?>" placeholder="<?= $esc(PgWebhook::DEFAULT_ALLOW_IP) ?>" />
					<div class="form-text">
						여기 없는 주소에서 온 요청은 <code>403</code>으로 거절하고 기록도 남기지 않습니다.
						비우면 IP 검사를 하지 않습니다(권장하지 않음).
						<span class="d-block mt-1">현재 적용 중: <strong class="font-monospace"><?= $esc(implode(', ', $allow)) ?></strong></span>
					</div>
				</div>

				<div class="mb-6">
					<label class="form-label fw-bold" for="pg_sign_key">sign_key <span class="text-muted fw-normal">— 서명 검증용</span></label>
					<input type="password" class="form-control form-control-solid" id="pg_sign_key" placeholder="<?= empty($pub['has_sign_key']) ? '미설정 — 입력하면 서명 검증이 켜집니다' : '변경할 때만 입력 (현재 ' . $esc((string) $pub['sign_key_masked']) . ')' ?>" autocomplete="new-password" />
					<div class="form-text">
						검증식 <code>sha256("sign_key=..&amp;timestamp=..&amp;mid=..")</code>.
						<?php if (empty($pub['has_sign_key'])) : ?>
						<span class="d-block mt-1 text-warning fw-semibold">⚠️ 아직 미설정이라 <strong>서명 검증을 건너뜁니다</strong>(IP 검사만 적용). PG사에서 키를 받으면 여기 넣으세요 — 넣는 순간 검증이 켜집니다.</span>
						<?php else : ?>
						<span class="d-block mt-1 text-success fw-semibold">✅ 서명 검증이 켜져 있습니다. 서명이 틀린 요청은 <code>401</code>로 거절합니다.</span>
						<?php endif; ?>
					</div>
				</div>

				<div class="separator separator-dashed my-5"></div>

				<div class="row g-4 mb-6">
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_mid">가맹점 ID (mid)</label>
						<input type="text" class="form-control form-control-solid" id="pg_mid" value="<?= $esc((string) ($cfg['mid'] ?? '')) ?>" />
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_login_id">가맹점 관리자 아이디</label>
						<input type="text" class="form-control form-control-solid" id="pg_login_id" value="<?= $esc((string) ($cfg['login_id'] ?? '')) ?>" />
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_login_pw">가맹점 관리자 비밀번호</label>
						<input type="password" class="form-control form-control-solid" id="pg_login_pw" placeholder="<?= empty($cfg['login_pw']) ? '미설정' : '변경할 때만 입력' ?>" autocomplete="new-password" />
						<div class="form-text">대사(정산) API 로그인에 원문이 필요해 평문 보관됩니다.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_tid">단말기 ID (tid)</label>
						<input type="text" class="form-control form-control-solid" id="pg_tid" value="<?= $esc((string) ($cfg['tid'] ?? '')) ?>" />
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_pay_key">결제 KEY</label>
						<input type="password" class="form-control form-control-solid" id="pg_pay_key" placeholder="<?= empty($pub['has_pay_key']) ? '미설정' : '변경할 때만 입력 (현재 ' . $esc((string) $pub['pay_key_masked']) . ')' ?>" autocomplete="new-password" />
						<div class="form-text">거래 API 인증 — <code>Authorization</code> 에 <strong>원문</strong>으로 들어갑니다(Bearer 아님).</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_api_key">API KEY <span class="text-muted fw-normal">(대사)</span></label>
						<input type="password" class="form-control form-control-solid" id="pg_api_key" placeholder="<?= empty($pub['has_api_key']) ? '미설정' : '변경할 때만 입력 (현재 ' . $esc((string) $pub['api_key_masked']) . ')' ?>" autocomplete="new-password" />
						<div class="form-text">정산 대사 API — <code>External-Api: Bearer {api_key}</code>.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_enc_key">암호화 KEY <span class="text-muted fw-normal">(AES)</span></label>
						<input type="password" class="form-control form-control-solid" id="pg_enc_key" placeholder="<?= empty($pub['has_enc_key']) ? '미설정' : '변경할 때만 입력 (현재 ' . $esc((string) $pub['enc_key_masked']) . ')' ?>" autocomplete="new-password" />
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_enc_iv">Initialization Vector</label>
						<input type="password" class="form-control form-control-solid" id="pg_enc_iv" placeholder="<?= empty($pub['has_enc_key']) ? '미설정' : '변경할 때만 입력 (현재 ' . $esc((string) $pub['enc_iv_masked']) . ')' ?>" autocomplete="new-password" />
						<div class="form-text">카드번호 등 민감 필드를 AES-256-CBC 로 암호화할 때 씁니다(<code>PgCrypto</code>).</div>
					</div>
					<div class="col-md-6">
						<label class="form-label fw-bold" for="pg_driver">드라이버</label>
						<select class="form-select form-select-solid" id="pg_driver">
							<option value="mock"<?= ($cfg['driver'] ?? 'mock') === 'mock' ? ' selected' : '' ?>>mock (모의)</option>
							<option value="weroute"<?= ($cfg['driver'] ?? '') === 'weroute' ? ' selected' : '' ?>>weroute (실연동)</option>
						</select>
						<div class="form-text">실 드라이버는 아직 미구현이라 weroute 를 골라도 mock 으로 동작합니다.</div>
					</div>
				</div>

				<button type="button" class="btn btn-primary" id="pg_save">설정 저장</button>
			</div>
		</div>
	</div>
	<!--end::결제통지 설정-->

	<!--begin::수신 현황-->
	<div class="col-xl-5">
		<div class="card card-flush h-100">
			<div class="card-header pt-5">
				<h3 class="card-title fw-bold">수신 현황</h3>
			</div>
			<div class="card-body pt-0 fs-7">
				<div class="row g-3 mb-5">
					<?php foreach ($stateBadge as $key => $meta) : ?>
					<div class="col-6">
						<a href="<?= $esc($currentUrl . (str_contains($currentUrl, '?') ? '&' : '?') . 'state=' . $key) ?>"
							class="d-block border border-gray-300 rounded p-3 text-hover-primary<?= $filterState === $key ? ' border-primary' : '' ?>">
							<div class="text-gray-500 fs-8"><?= $esc($meta['label']) ?></div>
							<div class="fw-bold fs-4 text-<?= $esc($meta['badge']) ?>"><?= number_format($stats[$key]) ?></div>
						</a>
					</div>
					<?php endforeach; ?>
				</div>
				<div class="alert bg-light-primary text-gray-800 fs-8 p-4 mb-0">
					결제통지는 <strong>지갑을 움직이지 않습니다.</strong> 우리 결제는 요청→응답 동기 흐름이라 승인 응답에서 이미 지갑이 반영되고,
					통지는 그 기록이 PG 기록과 맞는지 <strong>대조</strong>하는 용도입니다.
					<span class="d-block mt-1"><strong>미대조</strong>·<strong>금액 불일치</strong>가 쌓이면 우리 기록과 PG 기록이 어긋난 것이니 확인하세요.</span>
				</div>
			</div>
		</div>
	</div>
	<!--end::수신 현황-->
</div>

<!--begin::수신 이력-->
<div class="card card-flush mt-8">
	<div class="card-header pt-5">
		<h3 class="card-title fw-bold">수신 이력 <span class="text-gray-500 fs-7 fw-semibold ms-2">최근 <?= number_format(count($events)) ?>건<?= $filterState !== '' ? ' · ' . $esc($stateBadge[$filterState]['label'] ?? $filterState) : '' ?></span></h3>
		<div class="card-toolbar">
			<?php if ($filterState !== '') : ?>
			<a href="<?= $esc($currentUrl) ?>" class="btn btn-sm btn-light">전체 보기</a>
			<?php endif; ?>
		</div>
	</div>
	<div class="card-body pt-0">
		<?php if ($events === []) : ?>
		<div class="text-center text-gray-500 py-10">
			아직 수신한 결제통지가 없습니다.
			<span class="d-block fs-8 mt-2">가맹점 관리자에 위 Noti URL 을 등록하면 결제 발생 시 이곳에 쌓입니다.</span>
		</div>
		<?php else : ?>
		<div class="table-responsive">
			<table class="table table-row-bordered align-middle fs-8 gy-3">
				<thead>
					<tr class="fw-bold text-muted">
						<th class="min-w-140px">수신일시</th>
						<th class="min-w-140px">trx_id</th>
						<th class="min-w-140px">주문번호</th>
						<th class="min-w-80px text-end">금액</th>
						<th class="min-w-90px">대조</th>
						<th class="min-w-70px">서명</th>
						<th class="min-w-100px">발신 IP</th>
						<th class="min-w-200px">비고</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($events as $ev) :
					    $st = $stateBadge[(string) $ev['match_state']] ?? ['label' => (string) $ev['match_state'], 'badge' => 'secondary'];
					    ?>
					<tr>
						<td class="text-muted"><?= $esc((string) $ev['received_at']) ?></td>
						<td class="font-monospace text-gray-800"><?= $esc((string) $ev['trx_id']) ?></td>
						<td class="font-monospace text-gray-700"><?= $esc((string) ($ev['ord_num'] ?: '—')) ?></td>
						<td class="text-end fw-semibold"><?= number_format((int) $ev['amount']) ?>원</td>
						<td>
							<span class="badge badge-light-<?= $esc($st['badge']) ?>"><?= $esc($st['label']) ?></span>
							<?php if (!empty($ev['payment_id'])) : ?>
							<div class="text-muted fs-9 mt-1">결제 #<?= (int) $ev['payment_id'] ?></div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ((int) $ev['verified'] === 1) : ?>
							<span class="badge badge-light-success">검증됨</span>
							<?php else : ?>
							<span class="badge badge-light-secondary" title="sign_key 미설정 상태에서 받은 통지입니다.">생략</span>
							<?php endif; ?>
						</td>
						<td class="font-monospace text-muted"><?= $esc((string) $ev['source_ip']) ?></td>
						<td class="text-gray-700"><?= $esc((string) ($ev['note'] ?: '—')) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
</div>
<!--end::수신 이력-->

<script>
(function () {
	'use strict';
	// ⚠️ admin_url() 은 index.php?route=... 라우터 URL 이라 API 파일에 닿지 않는다(404 HTML 이 돌아온다).
	//    다른 화면과 동일하게 ADMIN_BASE 로 실제 파일 경로를 만든다.
	var PG_CONFIG_API = <?= json_encode(ADMIN_BASE . '/api/pg_config.php', JSON_UNESCAPED_UNICODE) ?>;
	var toast = document.getElementById('pg_toast');
	var toastMsg = document.getElementById('pg_toast_msg');
	function showToast(m, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = m;
		toast.classList.remove('d-none');
		window.scrollTo(0, 0);
	}

	document.getElementById('pg_copy_url').addEventListener('click', function () {
		var el = document.getElementById('pg_noti_url');
		el.select();
		el.setSelectionRange(0, 99999);
		try {
			// clipboard API 는 https 가 아니면 막히는 환경이 있어 execCommand 로 폴백한다.
			if (navigator.clipboard) { navigator.clipboard.writeText(el.value); }
			else { document.execCommand('copy'); }
			showToast('Noti URL 을 복사했습니다.', true);
		} catch (e) {
			showToast('복사에 실패했습니다. 직접 선택해 복사하세요.', false);
		}
	});

	document.getElementById('pg_save').addEventListener('click', function () {
		var payload = {
			noti_allow_ips: document.getElementById('pg_allow_ips').value.trim(),
			mid:            document.getElementById('pg_mid').value.trim(),
			login_id:       document.getElementById('pg_login_id').value.trim(),
			driver:         document.getElementById('pg_driver').value
		};
		// 비밀값은 **입력했을 때만** 보낸다 — 빈 값을 보내면 기존 값이 지워진다.
		var sk = document.getElementById('pg_sign_key').value;
		if (sk !== '') { payload.sign_key = sk; }
		var pw = document.getElementById('pg_login_pw').value;
		if (pw !== '') { payload.login_pw = pw; }
		payload.tid = document.getElementById('pg_tid').value.trim();
		[['pg_pay_key', 'pay_key'], ['pg_api_key', 'api_key'], ['pg_enc_key', 'enc_key'], ['pg_enc_iv', 'enc_iv']]
			.forEach(function (pair) {
				var v = document.getElementById(pair[0]).value;
				if (v !== '') { payload[pair[1]] = v; }
			});

		fetch(PG_CONFIG_API, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.ok) { throw new Error(j.message || '저장 실패'); }
				showToast(j.message || '저장했습니다.', true);
				setTimeout(function () { window.location.reload(); }, 800);
			})
			.catch(function (e) { showToast(e.message || String(e), false); });
	});
})();
</script>

<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

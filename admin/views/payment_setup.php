<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyCard.php';
require_once INC_PATH . '/BankAccount.php';
require_once INC_PATH . '/AgencyWallet.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/PgGateway.php';

$apiUrl   = ADMIN_BASE . '/api/payment_setup.php';
$needsMigrate = !AgencyCard::tableExists() || !BankAccount::tableExists();

// 대리점 계정=자기 것만 / 본사(super)=?agency=ID 로 대상 대리점 선택해 대신 설정
$isAgencySelf  = admin_org_level() === Org::LEVEL_AGENCY;
$isSuper       = admin_has_role('super');
$agencyOptions = [];
$targetAgency  = null;
$agencyId      = 0;

// 본사는 대리점 카드·수령계좌 대행 설정 외에, **자기 출금 원천 계좌**도 여기서 관리한다.
// (2026-08-15 단일 계좌 구조 — 라이더 이체·대리점 인출이 전부 이 계좌에서 나간다.)
$isHqAccount = false;

if ($isAgencySelf) {
    $agencyId = admin_org_id();
} elseif ($isSuper) {
    $agencyOptions = Organization::agencyOptions();
    $selected = (int) ($_GET['agency'] ?? 0);
    $hqId     = Org::hqId();

    if ($selected === $hqId && $hqId > 0) {
        $isHqAccount = true;
        $agencyId    = $hqId;
    } elseif ($selected > 0) {
        $row = Organization::find($selected);
        if ($row !== null && $row['level'] === Org::LEVEL_AGENCY) {
            $targetAgency = $row;
            $agencyId     = (int) $row['id'];
        }
    }
}

$canUse  = $agencyId > 0 && !$needsMigrate;
$cards   = $canUse ? AgencyCard::listForAgency($agencyId) : [];
$account = $canUse ? BankAccount::get($agencyId) : null;
$wallet  = $canUse ? AgencyWallet::withdrawable($agencyId) : ['balance' => 0];
$banks   = db_table_exists('system_codes') ? db_rows("SELECT code, label FROM system_codes WHERE category = 'bank' AND is_active = 1 ORDER BY label ASC") : [];
$setupBaseUrl = admin_url('withdrawal/payment-setup');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">결제 설정 (카드·계좌)</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">결제 설정</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif (!$isAgencySelf && !$isSuper) : ?>
	<div class="alert alert-info mb-8">이 화면은 대리점 계정과 본사 최고관리자만 사용할 수 있습니다.</div>
	<?php else : ?>

	<?php if (!$isAgencySelf) : ?>
	<!--begin::본사용 대리점 선택-->
	<div class="card card-flush mb-6">
		<div class="card-body py-4">
			<form method="get" action="<?= htmlspecialchars($setupBaseUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-flex flex-wrap align-items-center gap-3">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
					<input type="hidden" name="route" value="withdrawal/payment-setup" />
				<?php endif; ?>
				<label class="form-label fw-bold m-0">대상</label>
				<select name="agency" class="form-select form-select-solid w-250px" onchange="this.form.submit()">
					<option value="0"<?= (!$isHqAccount && $targetAgency === null) ? ' selected' : '' ?>>선택하세요…</option>
					<?php if (Org::hqId() > 0) : ?>
					<option value="<?= Org::hqId() ?>"<?= $isHqAccount ? ' selected' : '' ?>>★ 본사 — 출금 원천 계좌</option>
					<?php endif; ?>
					<?php foreach ($agencyOptions as $opt) : ?>
					<option value="<?= (int) $opt['id'] ?>"<?= $targetAgency !== null && (int) $targetAgency['id'] === (int) $opt['id'] ? ' selected' : '' ?>>
						<?= htmlspecialchars($opt['name'] . ' (' . $opt['code'] . ')', ENT_QUOTES, 'UTF-8') ?>
					</option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="btn btn-sm btn-light-primary">이동</button></noscript>
				<?php if ($isHqAccount) : ?>
					<span class="badge badge-light-danger fs-7">라이더 이체·대리점 인출이 전부 이 계좌에서 나갑니다</span>
				<?php elseif ($targetAgency !== null) : ?>
					<span class="badge badge-light-warning fs-7">본사 대행 — 이 대리점 결제수단을 대신 설정합니다(감사로그 기록)</span>
				<?php endif; ?>
			</form>
		</div>
	</div>
	<!--end::본사용 대리점 선택-->
	<?php endif; ?>

	<?php if (!$canUse) : ?>
	<div class="alert alert-info mb-8">위에서 설정할 대리점을 선택하세요.</div>
	<?php else : ?>

	<?php // 배너는 실제 상태를 따라간다 — 실 연동인데 "모의"라고 떠 있으면 진짜 카드를 마음 놓고 넣는다. ?>
	<?php if (PgGatewayFactory::isMock()) : ?>
	<div class="alert bg-light-warning fs-8 p-3 mb-6">🧪 <strong>모의(mock) 연동</strong> — 실 PG사·오픈뱅킹 계약 전까지 <?= $isHqAccount ? '핀테크이용번호는' : '빌링키/핀테크번호는' ?> 모의 값으로 동작합니다.<?php if (!$isHqAccount) : ?> 카드 <strong>모의 한도</strong>를 낮게 잡으면 대체결제(다음 카드 자동 시도)를 테스트할 수 있습니다.<?php endif; ?></div>
	<?php else : ?>
	<div class="alert bg-light-danger fs-8 p-3 mb-6">⚠️ <strong>실 연동(<?= htmlspecialchars(PgGatewayFactory::make()->label(), ENT_QUOTES, 'UTF-8') ?>)</strong> — 카드를 등록하면 <strong>실제 빌키가 발급</strong>되고, 결제 기능은 <strong>실제로 청구</strong>됩니다. 취소는 우리 시스템이 아니라 PG 가맹점 관리자에서 해야 합니다.</div>
	<?php endif; ?>

	<div id="ps_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="ps_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<?php // 본사 행은 **출금 원천 계좌** 하나만 쓴다. PG 결제 카드와 잔액 충전은 대리점 기능이라 감춘다. ?>
		<?php if (!$isHqAccount) : ?>
		<!-- 카드 -->
		<div class="col-xl-7">
			<div class="card card-flush mb-6">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">등록 카드 (PG 결제 · 우선순위 순 대체결제)</h3></div>
				<div class="card-body pt-2">
					<div class="table-responsive mb-4">
						<table class="table table-row-bordered align-middle fs-7 gy-2">
							<thead><tr class="fw-bold text-muted"><th>우선</th><th>별칭</th><th>카드</th><?php if (PgGatewayFactory::isMock()) : ?><th>모의한도</th><?php endif; ?><th class="text-center">상태</th><th></th></tr></thead>
							<tbody id="ps_cards">
								<?php if ($cards === []) : ?>
								<tr><td colspan="<?= PgGatewayFactory::isMock() ? 6 : 5 ?>" class="text-center text-muted py-4">등록된 카드가 없습니다.</td></tr>
								<?php else : foreach ($cards as $c) : ?>
								<tr data-id="<?= (int) $c['id'] ?>">
									<td style="width:70px"><input type="number" class="form-control form-control-sm form-control-solid ps-pri" value="<?= (int) $c['priority'] ?>" /></td>
									<td class="fw-bold"><?= htmlspecialchars($c['alias'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-muted"><?= htmlspecialchars(trim($c['brand'] . ' ' . ($c['last4'] ? '****' . $c['last4'] : '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
									<?php if (PgGatewayFactory::isMock()) : ?><td class="text-muted"><?= $c['mock_limit'] > 0 ? number_format($c['mock_limit']) . '원' : '무제한' ?></td><?php endif; ?>
									<td class="text-center"><span class="badge badge-light-<?= $c['active'] ? 'success' : 'secondary' ?> ps-toggle" role="button"><?= $c['active'] ? '활성' : '비활성' ?></span></td>
									<td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-light-danger ps-del">×</button></td>
								</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
					<div class="separator separator-dashed mb-4"></div>
					<h4 class="fw-bold fs-7 mb-1">카드 추가</h4>
					<div class="text-muted fs-8 mb-4">
						카드 정보는 <strong>PG사로 전달만 하고 저장하지 않습니다.</strong>
						저장되는 건 PG가 발급한 결제키와 표시용 끝 4자리뿐입니다.
					</div>
					<div class="row g-4">
						<?php // 카드사는 입력받지 않는다 — PG가 빌키 발급 응답으로 돌려주는 발급사를 그대로 쓴다(표기 흔들림 방지). ?>
						<div class="col-md-8">
							<label class="form-label fs-8 required" for="ps_alias">별칭</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="ps_alias" placeholder="예: 국민 법인카드" />
						</div>
						<div class="col-md-4">
							<label class="form-label fs-8" for="ps_priority">우선순위</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="ps_priority" min="0" max="9999" value="100" />
							<div class="form-text fs-9">낮을수록 먼저 시도</div>
						</div>

						<div class="col-12"><div class="separator separator-dashed my-1"></div></div>

						<div class="col-md-5">
							<label class="form-label fs-8 required" for="ps_cardnum">카드번호</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="ps_cardnum"
								inputmode="numeric" maxlength="19" autocomplete="off" placeholder="숫자만 입력" />
						</div>
						<div class="col-md-2">
							<label class="form-label fs-8 required" for="ps_yymm">유효기간</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="ps_yymm"
								inputmode="numeric" maxlength="4" autocomplete="off" placeholder="YYMM" />
							<div class="form-text fs-9">예: 2509</div>
						</div>
						<div class="col-md-3">
							<label class="form-label fs-8" for="ps_authnum">생년월일/사업자번호</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="ps_authnum"
								inputmode="numeric" maxlength="12" autocomplete="off" placeholder="YYMMDD 또는 사업자번호" />
						</div>
						<div class="col-md-2">
							<label class="form-label fs-8" for="ps_cardpw">카드 비번</label>
							<input type="password" class="form-control form-control-sm form-control-solid" id="ps_cardpw"
								inputmode="numeric" maxlength="2" autocomplete="new-password" placeholder="앞 2자리" />
						</div>

						<?php if (PgGatewayFactory::isMock()) : ?>
						<div class="col-md-4">
							<label class="form-label fs-8" for="ps_mocklimit">모의 한도</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="ps_mocklimit" min="0" step="10000" value="0" />
							<div class="form-text fs-9">0 = 무제한 · 대체결제 테스트용</div>
						</div>
						<?php endif; ?>
						<div class="col-md-3 d-flex align-items-end">
							<button type="button" class="btn btn-sm btn-primary w-100" id="ps_card_add">＋ 카드 등록</button>
						</div>
					</div>
					<div class="text-muted fs-8 mt-3">
						<i class="ki-duotone ki-information-5 fs-6 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
						<?php if (PgGatewayFactory::isMock()) : ?>
							현재 <strong>모의 모드</strong>라 실제 카드 승인 없이 모의 결제키가 발급됩니다. (카드번호 <code>0000…</code>으로 시작하면 실패 시뮬레이션)
						<?php else : ?>
							PG사(<?= htmlspecialchars(PgGatewayFactory::make()->label(), ENT_QUOTES, 'UTF-8') ?>)에 결제키 발급을 요청합니다.
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<!-- 계좌 + PG 충전 -->
		<div class="<?= $isHqAccount ? 'col-xl-7' : 'col-xl-5' ?>">
			<div class="card card-flush mb-6">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold"><?= $isHqAccount ? '출금 원천 계좌 (본사)' : '정산금 수령 계좌' ?></h3>
				</div>
				<div class="card-body pt-2 fs-7">
					<?php if ($isHqAccount) : ?>
					<div class="alert bg-light-danger fs-8 p-3 mb-4">
						<strong>라이더 이체·대리점 자체 인출이 전부 이 계좌에서 나갑니다.</strong>
						시스템에서 돈이 실제로 빠져나가는 유일한 계좌이므로 신중히 설정하세요.
						대리점 지갑은 이 계좌 잔액을 조직별로 나눠 보여주는 내부 장부입니다.
					</div>
					<?php else : ?>
					<div class="alert bg-light-primary fs-8 p-3 mb-4">
						대리점이 <strong>자체 인출</strong>로 정산금을 받을 계좌입니다.
						라이더에게 나가는 이체는 <strong>본사 단일 출금 계좌</strong>에서 실행되므로 여기 설정과 무관합니다.
					</div>
					<?php endif; ?>
					<div class="mb-3">
						<label class="form-label required">은행</label>
						<select class="form-select form-select-solid" id="ps_bank">
							<option value="">선택…</option>
							<?php foreach ($banks as $b) : ?>
							<option value="<?= htmlspecialchars($b['code'], ENT_QUOTES, 'UTF-8') ?>" <?= ($account && $account['bank_code'] === $b['code']) ? 'selected' : '' ?>><?= htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mb-3"><label class="form-label required">계좌번호</label><input type="text" class="form-control form-control-solid" id="ps_account" value="<?= htmlspecialchars((string) ($account['account_no'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" /></div>
					<div class="mb-3"><label class="form-label">예금주</label><input type="text" class="form-control form-control-solid" id="ps_holder" value="<?= htmlspecialchars((string) ($account['holder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" /></div>
					<?php // 핀테크이용번호는 **출금 원천 계좌**(본사)에만 필요하다. 수령 계좌엔 쓰이지 않아 표시하지 않는다. ?>
					<button type="button" class="btn btn-primary" id="ps_account_save">계좌 저장</button>
				</div>
			</div>
			<?php if (!$isHqAccount) : ?>
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">PG 잔액 충전</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="d-flex justify-content-between py-2 border-bottom border-gray-200 mb-3">
						<span class="text-muted">현재 대리점 잔액</span><span class="fw-bold" id="ps_balance"><?= number_format((int) $wallet['balance']) ?>원</span>
					</div>
					<div class="mb-3"><label class="form-label required">충전 금액 (원)</label><input type="number" class="form-control form-control-solid" id="ps_charge_amt" min="1" step="10000" /></div>
					<button type="button" class="btn btn-success" id="ps_charge">카드로 충전</button>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		// 본사가 대상(대리점 또는 본사 출금 원천 계좌)을 골라 설정할 때만 값이 있다.
		// 대리점 자기 계정은 0 → 서버가 세션 조직으로 고정한다.
		// ⚠️ `$targetAgency`만 보면 **본사 자신을 고른 경우**(그때는 null)에 0이 나가 "대상을 선택하세요"가 뜬다.
		var TARGET_AGENCY_ID = <?= $isAgencySelf ? 0 : (int) $agencyId ?>;
		var toast = document.getElementById('ps_toast'), toastMsg = document.getElementById('ps_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); window.scrollTo(0, 0); }
		function post(p) {
			if (TARGET_AGENCY_ID > 0) { p.agency_id = TARGET_AGENCY_ID; }
			return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(p) }).then(function (r) { return r.json(); });
		}
		// 본사(출금 원천 계좌) 화면에는 카드·충전 영역이 없다 → 없는 요소는 조용히 건너뛴다.
		function on(id, ev, fn) { var el = document.getElementById(id); if (el) { el.addEventListener(ev, fn); } }
		function reloadSoon() { setTimeout(function () { location.reload(); }, 700); }
		// 마지막 카드를 지우면 빈 테이블만 남아 "카드가 없다"는 걸 알 수 없으므로 안내 행을 되돌린다.
		function renderEmptyIfNone() {
			var tbody = document.getElementById('ps_cards');
			if (!tbody.querySelector('tr[data-id]')) {
				// 컬럼 수는 모의/실 연동에 따라 달라진다 — 서버 판정을 그대로 받아 쓴다.
				tbody.innerHTML = '<tr><td colspan="<?= PgGatewayFactory::isMock() ? 6 : 5 ?>" class="text-center text-muted py-4">등록된 카드가 없습니다.</td></tr>';
			}
		}

		on('ps_card_add', 'click', function () {
			var mockLimitEl = document.getElementById('ps_mocklimit');
			var payload = {
				action: 'card_add',
				alias: document.getElementById('ps_alias').value.trim(),
				// brand(카드사)는 보내지 않는다 — 서버가 PG 응답의 발급사로 채운다.
				priority: parseInt(document.getElementById('ps_priority').value, 10) || 100,
				mock_limit: mockLimitEl ? (parseInt(mockLimitEl.value, 10) || 0) : 0,
				// 카드 정보 — 서버가 PG로 전달만 하고 저장하지 않는다.
				card_num: document.getElementById('ps_cardnum').value.trim(),
				yymm: document.getElementById('ps_yymm').value.trim(),
				auth_num: document.getElementById('ps_authnum').value.trim(),
				card_pw: document.getElementById('ps_cardpw').value.trim(),
			};
			if (!payload.alias) { showToast('카드 별칭을 입력하세요.', false); return; }
			if (!payload.card_num || !payload.yymm) { showToast('카드번호와 유효기간을 입력하세요.', false); return; }

			post(payload)
				.then(function (r) {
					if (!r.ok) throw new Error(r.message);
					// 등록 성공 여부와 무관하게 입력칸의 카드정보는 즉시 지운다(화면 잔류 방지).
					['ps_cardnum', 'ps_yymm', 'ps_authnum', 'ps_cardpw'].forEach(function (id) { document.getElementById(id).value = ''; });
					showToast(r.message, true); reloadSoon();
				})
				.catch(function (e) {
					['ps_cardnum', 'ps_yymm', 'ps_authnum', 'ps_cardpw'].forEach(function (id) { document.getElementById(id).value = ''; });
					showToast(e.message, false);
				});
		});
		on('ps_cards', 'click', function (ev) {
			var tr = ev.target.closest('tr'); if (!tr) return; var id = parseInt(tr.getAttribute('data-id'), 10);
			if (ev.target.closest('.ps-del')) { if (!confirm('카드를 삭제할까요?')) return; post({ action: 'card_delete', id: id }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); tr.remove(); renderEmptyIfNone(); }).catch(function (e) { showToast(e.message, false); }); }
			if (ev.target.closest('.ps-toggle')) { var on = ev.target.textContent.trim() === '활성'; post({ action: 'card_toggle', id: id, active: !on }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); reloadSoon(); }).catch(function (e) { showToast(e.message, false); }); }
		});
		on('ps_cards', 'change', function (ev) {
			if (ev.target.classList.contains('ps-pri')) { var tr = ev.target.closest('tr'); post({ action: 'card_priority', id: parseInt(tr.getAttribute('data-id'), 10), priority: parseInt(ev.target.value, 10) || 100 }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); }).catch(function (e) { showToast(e.message, false); }); }
		});
		document.getElementById('ps_account_save').addEventListener('click', function () {
			post({ action: 'account_save', bank_code: document.getElementById('ps_bank').value, account_no: document.getElementById('ps_account').value.trim(), holder: document.getElementById('ps_holder').value.trim() })
				.then(function (r) {
					if (!r.ok) throw new Error(r.message);
					showToast(r.message, true);
				}).catch(function (e) { showToast(e.message, false); });
		});
		on('ps_charge', 'click', function () {
			var amt = parseInt(document.getElementById('ps_charge_amt').value, 10) || 0;
			if (amt <= 0) { showToast('충전 금액을 입력하세요.', false); return; }
			post({ action: 'pg_charge', amount: amt }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); if (r.wallet) document.getElementById('ps_balance').textContent = (r.wallet.balance || 0).toLocaleString('ko-KR') + '원'; }).catch(function (e) { showToast(e.message, false); });
		});
	})();
	</script>
	<?php endif; // $canUse — 대상 대리점 선택됨 ?>
	<?php endif; // 마이그레이션 / 권한 ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

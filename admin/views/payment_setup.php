<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyCard.php';
require_once INC_PATH . '/BankAccount.php';
require_once INC_PATH . '/AgencyWallet.php';

$apiUrl   = ADMIN_BASE . '/api/payment_setup.php';
$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$agencyId = $isAgency ? admin_org_id() : 0;
$needsMigrate = !AgencyCard::tableExists() || !BankAccount::tableExists();

$cards   = ($isAgency && !$needsMigrate) ? AgencyCard::listForAgency($agencyId) : [];
$account = ($isAgency && !$needsMigrate) ? BankAccount::get($agencyId) : null;
$wallet  = ($isAgency && !$needsMigrate) ? AgencyWallet::withdrawable($agencyId) : ['balance' => 0];
$banks   = db_table_exists('system_codes') ? db_rows("SELECT code, label FROM system_codes WHERE category = 'bank' AND is_active = 1 ORDER BY label ASC") : [];
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
	<?php elseif (!$isAgency) : ?>
	<div class="alert alert-info mb-8">이 화면은 대리점 계정 전용입니다.</div>
	<?php else : ?>

	<div class="alert bg-light-warning fs-8 p-3 mb-6">🧪 <strong>모의(mock) 연동</strong> — 실 PG사·오픈뱅킹 계약 전까지 빌링키/핀테크번호는 모의 값으로 동작합니다. 카드 <strong>모의 한도</strong>를 낮게 잡으면 대체결제(다음 카드 자동 시도)를 테스트할 수 있습니다.</div>

	<div id="ps_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="ps_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<!-- 카드 -->
		<div class="col-xl-7">
			<div class="card card-flush mb-6">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">등록 카드 (PG 결제 · 우선순위 순 대체결제)</h3></div>
				<div class="card-body pt-2">
					<div class="table-responsive mb-4">
						<table class="table table-row-bordered align-middle fs-7 gy-2">
							<thead><tr class="fw-bold text-muted"><th>우선</th><th>별칭</th><th>카드</th><th>모의한도</th><th class="text-center">상태</th><th></th></tr></thead>
							<tbody id="ps_cards">
								<?php if ($cards === []) : ?>
								<tr><td colspan="6" class="text-center text-muted py-4">등록된 카드가 없습니다.</td></tr>
								<?php else : foreach ($cards as $c) : ?>
								<tr data-id="<?= (int) $c['id'] ?>">
									<td style="width:70px"><input type="number" class="form-control form-control-sm form-control-solid ps-pri" value="<?= (int) $c['priority'] ?>" /></td>
									<td class="fw-bold"><?= htmlspecialchars($c['alias'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-muted"><?= htmlspecialchars(trim($c['brand'] . ' ' . ($c['last4'] ? '****' . $c['last4'] : '')), ENT_QUOTES, 'UTF-8') ?: '—' ?></td>
									<td class="text-muted"><?= $c['mock_limit'] > 0 ? number_format($c['mock_limit']) . '원' : '무제한' ?></td>
									<td class="text-center"><span class="badge badge-light-<?= $c['active'] ? 'success' : 'secondary' ?> ps-toggle" role="button"><?= $c['active'] ? '활성' : '비활성' ?></span></td>
									<td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-light-danger ps-del">×</button></td>
								</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
					<div class="separator separator-dashed mb-4"></div>
					<div class="row g-3">
						<div class="col-md-4"><input type="text" class="form-control form-control-sm form-control-solid" id="ps_alias" placeholder="별칭*" /></div>
						<div class="col-md-3"><input type="text" class="form-control form-control-sm form-control-solid" id="ps_brand" placeholder="카드사" /></div>
						<div class="col-md-2"><input type="text" class="form-control form-control-sm form-control-solid" id="ps_last4" placeholder="끝4자리" maxlength="4" /></div>
						<div class="col-md-3"><input type="number" class="form-control form-control-sm form-control-solid" id="ps_priority" placeholder="우선순위" value="100" /></div>
						<div class="col-md-4"><input type="number" class="form-control form-control-sm form-control-solid" id="ps_mocklimit" placeholder="모의 한도(0=무제한)" value="0" /></div>
						<div class="col-md-4 d-flex align-items-center text-muted fs-8">빌링키는 실연동 전 자동(모의) 발급</div>
						<div class="col-md-4 text-end"><button type="button" class="btn btn-sm btn-primary" id="ps_card_add">＋ 카드 등록</button></div>
					</div>
				</div>
			</div>
		</div>

		<!-- 계좌 + PG 충전 -->
		<div class="col-xl-5">
			<div class="card card-flush mb-6">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">오픈뱅킹 출금 계좌</h3></div>
				<div class="card-body pt-2 fs-7">
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
					<div class="mb-4 text-muted fs-8">핀테크이용번호: <?= $account ? htmlspecialchars(substr((string) $account['fintech_use_num'], 0, 12), ENT_QUOTES, 'UTF-8') . '…' : '미발급(저장 시 모의 발급)' ?></div>
					<button type="button" class="btn btn-primary" id="ps_account_save">계좌 저장</button>
				</div>
			</div>
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
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('ps_toast'), toastMsg = document.getElementById('ps_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); window.scrollTo(0, 0); }
		function post(p) { return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(p) }).then(function (r) { return r.json(); }); }
		function reloadSoon() { setTimeout(function () { location.reload(); }, 700); }

		document.getElementById('ps_card_add').addEventListener('click', function () {
			post({ action: 'card_add', alias: document.getElementById('ps_alias').value.trim(), brand: document.getElementById('ps_brand').value.trim(), last4: document.getElementById('ps_last4').value.trim(), priority: parseInt(document.getElementById('ps_priority').value, 10) || 100, mock_limit: parseInt(document.getElementById('ps_mocklimit').value, 10) || 0 })
				.then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); reloadSoon(); }).catch(function (e) { showToast(e.message, false); });
		});
		document.getElementById('ps_cards').addEventListener('click', function (ev) {
			var tr = ev.target.closest('tr'); if (!tr) return; var id = parseInt(tr.getAttribute('data-id'), 10);
			if (ev.target.closest('.ps-del')) { if (!confirm('카드를 삭제할까요?')) return; post({ action: 'card_delete', id: id }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); tr.remove(); }).catch(function (e) { showToast(e.message, false); }); }
			if (ev.target.closest('.ps-toggle')) { var on = ev.target.textContent.trim() === '활성'; post({ action: 'card_toggle', id: id, active: !on }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); reloadSoon(); }).catch(function (e) { showToast(e.message, false); }); }
		});
		document.getElementById('ps_cards').addEventListener('change', function (ev) {
			if (ev.target.classList.contains('ps-pri')) { var tr = ev.target.closest('tr'); post({ action: 'card_priority', id: parseInt(tr.getAttribute('data-id'), 10), priority: parseInt(ev.target.value, 10) || 100 }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); }).catch(function (e) { showToast(e.message, false); }); }
		});
		document.getElementById('ps_account_save').addEventListener('click', function () {
			post({ action: 'account_save', bank_code: document.getElementById('ps_bank').value, account_no: document.getElementById('ps_account').value.trim(), holder: document.getElementById('ps_holder').value.trim() })
				.then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); }).catch(function (e) { showToast(e.message, false); });
		});
		document.getElementById('ps_charge').addEventListener('click', function () {
			var amt = parseInt(document.getElementById('ps_charge_amt').value, 10) || 0;
			if (amt <= 0) { showToast('충전 금액을 입력하세요.', false); return; }
			post({ action: 'pg_charge', amount: amt }).then(function (r) { if (!r.ok) throw new Error(r.message); showToast(r.message, true); if (r.wallet) document.getElementById('ps_balance').textContent = (r.wallet.balance || 0).toLocaleString('ko-KR') + '원'; }).catch(function (e) { showToast(e.message, false); });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

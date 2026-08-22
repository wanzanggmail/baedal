<?php

declare(strict_types=1);

$apiUrl = ADMIN_BASE . '/api/manual_adjust.php';
$needsMigrate = !db_table_exists('agency_wallets') || !db_table_exists('rider_wallets');

$agencies = $needsMigrate ? [] : db_rows(
    "SELECT id, name, code FROM organizations WHERE level = 'agency' AND is_active = 1 ORDER BY name ASC"
);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산/잔액 수동 조정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수동 조정</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert alert-dismissible bg-light-danger d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-cross fs-2hx text-danger me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>본사 전용 · 위험 작업.</strong> PG결제·오픈뱅킹 이체 자체를 되돌리지는 않습니다(실제 환불은 PG/은행 콘솔에서 별도 처리).
			여기서는 <strong>시스템 잔액·기록만</strong> 직접 바로잡습니다. 모든 조정은 <strong>사유 필수</strong>이며 변경 전/후 값이 <strong>감사 로그</strong>에 남습니다.
		</div>
	</div>

	<div id="ma_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="ma_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<!-- 라이더 지갑 조정 -->
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">라이더 지갑 조정</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-3">
						<label class="form-label required">대리점</label>
						<select class="form-select form-select-solid" id="ma_wallet_agency_sel" data-control="select2" data-placeholder="대리점을 먼저 선택하세요">
							<option value=""></option>
							<?php foreach ($agencies as $a) : ?>
							<option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mb-4">
						<select class="form-select form-select-solid" id="ma_wallet_rider_sel" disabled></select>
						<button class="btn btn-light-primary mt-3 w-100" type="button" id="ma_rider_lookup" disabled>조회</button>
					</div>
					<div id="ma_rider_panel" class="d-none">
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">대상</span><span class="fw-bold" id="ma_rider_name">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200 mb-4">
							<span class="text-muted">현재 잔액</span><span class="fw-bold" id="ma_rider_cur">—</span>
						</div>
						<input type="hidden" id="ma_rider_id" value="" />
						<div class="mb-3">
							<label class="form-label required">변경할 잔액 (원)</label>
							<input type="number" class="form-control form-control-solid" id="ma_rider_bal" min="0" step="1" />
						</div>
						<div class="mb-3">
							<label class="form-label required">사유</label>
							<input type="text" class="form-control form-control-solid" id="ma_rider_reason" maxlength="300" placeholder="예: 이중 정산 반영 정정" />
						</div>
						<button type="button" class="btn btn-danger" id="ma_rider_save">잔액 조정</button>
					</div>
				</div>
			</div>
		</div>

		<!-- 대리점 지갑 조정 -->
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점 지갑 조정</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-4">
						<select class="form-select form-select-solid" id="ma_agency_sel" data-control="select2" data-placeholder="대리점 선택…">
							<option value=""></option>
							<?php foreach ($agencies as $a) : ?>
							<option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
						<button class="btn btn-light-primary mt-3 w-100" type="button" id="ma_agency_lookup">조회</button>
					</div>
					<div id="ma_agency_panel" class="d-none">
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">잔액</span><span class="fw-bold" id="ma_ag_balance">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">라이더 정산금</span><span id="ma_ag_debt">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200 mb-4">
							<span class="text-muted">원천세 예수금</span><span id="ma_ag_reserve">—</span>
						</div>
						<div class="mb-3">
							<label class="form-label required">사유</label>
							<input type="text" class="form-control form-control-solid" id="ma_agency_reason" maxlength="300" placeholder="예: PG 결제 취소 반영" />
						</div>
						<div class="row g-3">
							<div class="col-8">
								<label class="form-label">변경할 잔액 (원)</label>
								<input type="number" class="form-control form-control-solid" id="ma_ag_bal" min="0" step="1" />
							</div>
							<div class="col-4 d-flex align-items-end">
								<button type="button" class="btn btn-danger w-100" id="ma_agency_bal_save">잔액</button>
							</div>
							<div class="col-8">
								<label class="form-label">변경할 예수금 (원)</label>
								<input type="number" class="form-control form-control-solid" id="ma_ag_res" min="0" step="1" />
							</div>
							<div class="col-4 d-flex align-items-end">
								<button type="button" class="btn btn-danger w-100" id="ma_agency_res_save">예수금</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('ma_toast');
		var toastMsg = document.getElementById('ma_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }
		function postJson(payload) {
			return fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(payload),
			}).then(function (r) { return r.json(); });
		}

		// 라이더 — 대리점 먼저 선택 → 그 대리점 소속 라이더만 검색(select2 ajax)
		// jQuery/select2는 plugins.bundle.js에서 오는데 그 스크립트가 이 뷰의 아래(inc/shell_close.php)에
		// 위치해 아직 로드 전이다 — DOMContentLoaded(모든 동기 스크립트 실행 후 발생) 이후로 초기화를 미룬다.
		var RIDERS_API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/riders.php', JSON_UNESCAPED_UNICODE) ?>;
		var lookupBtn = document.getElementById('ma_rider_lookup');

		function initWalletRiderCascade() {
			var walletAgencySel = jQuery('#ma_wallet_agency_sel');
			var walletRiderSel = jQuery('#ma_wallet_rider_sel');

			walletRiderSel.select2({
				placeholder: '라이더 선택',
				allowClear: false,
				ajax: {
					url: RIDERS_API,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return { q: params.term || '', agency: walletAgencySel.val() || 0, limit: 30 };
					},
					processResults: function (data) {
						return {
							results: (data.items || []).map(function (r) {
								return { id: r.id, text: r.name + (r.phone_masked ? ' (' + r.phone_masked + ')' : '') };
							}),
						};
					},
				},
			});

			walletAgencySel.on('change', function () {
				var agencyId = walletAgencySel.val();
				walletRiderSel.val(null).trigger('change');
				walletRiderSel.prop('disabled', !agencyId);
				lookupBtn.disabled = true;
				document.getElementById('ma_rider_panel').classList.add('d-none');
			});
			walletRiderSel.on('change', function () {
				lookupBtn.disabled = !walletRiderSel.val();
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initWalletRiderCascade);
		} else {
			initWalletRiderCascade();
		}

		lookupBtn.addEventListener('click', function () {
			var walletRiderSel = jQuery('#ma_wallet_rider_sel');
			var riderId = parseInt(walletRiderSel.val(), 10) || 0;
			if (!riderId) { showToast('라이더를 선택하세요.', false); return; }
			fetch(API + '?type=rider&rider=' + riderId, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					document.getElementById('ma_rider_id').value = res.rider.id;
					document.getElementById('ma_rider_name').textContent = res.rider.code + ' / ' + res.rider.name;
					document.getElementById('ma_rider_cur').textContent = won(res.balance);
					document.getElementById('ma_rider_bal').value = res.balance;
					document.getElementById('ma_rider_panel').classList.remove('d-none');
				})
				.catch(function (e) { showToast(e.message, false); });
		});
		document.getElementById('ma_rider_save').addEventListener('click', function () {
			postJson({
				action: 'adjust_rider',
				rider_id: parseInt(document.getElementById('ma_rider_id').value, 10) || 0,
				balance: parseInt(document.getElementById('ma_rider_bal').value, 10) || 0,
				reason: document.getElementById('ma_rider_reason').value.trim(),
			}).then(function (res) {
				if (!res.ok) throw new Error(res.message);
				showToast(res.message, true);
				document.getElementById('ma_rider_cur').textContent = won(res.result.after);
			}).catch(function (e) { showToast(e.message, false); });
		});

		// 대리점
		function loadAgency() {
			var id = parseInt(document.getElementById('ma_agency_sel').value, 10) || 0;
			if (!id) { showToast('대리점을 선택하세요.', false); return; }
			fetch(API + '?type=agency&agency_id=' + id, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					document.getElementById('ma_ag_balance').textContent = won(res.wallet.balance);
					document.getElementById('ma_ag_debt').textContent = won(res.wallet.rider_debt);
					document.getElementById('ma_ag_reserve').textContent = won(res.wallet.withholding_reserve);
					document.getElementById('ma_ag_bal').value = res.wallet.balance;
					document.getElementById('ma_ag_res').value = res.wallet.withholding_reserve;
					document.getElementById('ma_agency_panel').classList.remove('d-none');
				})
				.catch(function (e) { showToast(e.message, false); });
		}
		document.getElementById('ma_agency_lookup').addEventListener('click', loadAgency);
		function agencyPost(action, field, valId) {
			var id = parseInt(document.getElementById('ma_agency_sel').value, 10) || 0;
			var payload = { action: action, agency_id: id, reason: document.getElementById('ma_agency_reason').value.trim() };
			payload[field] = parseInt(document.getElementById(valId).value, 10) || 0;
			postJson(payload).then(function (res) {
				if (!res.ok) throw new Error(res.message);
				showToast(res.message, true);
				loadAgency();
			}).catch(function (e) { showToast(e.message, false); });
		}
		document.getElementById('ma_agency_bal_save').addEventListener('click', function () { agencyPost('adjust_agency', 'balance', 'ma_ag_bal'); });
		document.getElementById('ma_agency_res_save').addEventListener('click', function () { agencyPost('adjust_reserve', 'reserve', 'ma_ag_res'); });
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

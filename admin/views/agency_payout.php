<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyPayout.php';

// 자체 인출은 자기 조직 지갑을 빼는 것 — 대리점·총판·세무대리(원천세 납입)·개발사(배분 몫) 모두 "셀프".
// 본사는 조회만. 개발사는 메뉴 권한이 본사와 같지만(2026-09-05 갑) 지갑만은 자기 것을 빼야 한다.
$isSelf    = in_array(admin_org_level(), [Org::LEVEL_AGENCY, Org::LEVEL_DISTRIBUTOR, Org::LEVEL_TAX_AGENT, Org::LEVEL_DEVELOPER], true);
$myOrg     = $isSelf ? admin_org_id() : 0;
$apiUrl    = ADMIN_BASE . '/api/agency_payout.php';
$needsMigrate = !db_table_exists('agency_wallets') || !db_table_exists('withdrawal_requests');

$wallet = $isSelf && !$needsMigrate ? AgencyWallet::withdrawable($myOrg) : null;
$rows   = !$needsMigrate ? AgencyPayout::listScoped($isSelf ? $myOrg : null) : [];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">자체 정산금 인출</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">자체 인출</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="ap_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="ap_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<?php if ($isSelf) : ?>
		<div class="col-xl-5">
			<div class="card card-flush mb-6">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">지갑 요약</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
						<span class="text-muted">조직 잔액</span>
						<span class="fw-bold" id="ap_balance"><?= number_format((int) $wallet['balance']) ?>원</span>
					</div>
					<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
						<span class="text-muted">− 라이더 정산금</span>
						<span id="ap_debt"><?= number_format((int) $wallet['rider_debt']) ?>원</span>
					</div>
					<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
						<span class="text-muted">− 원천세 예수금</span>
						<span id="ap_reserve"><?= number_format((int) $wallet['withholding_reserve']) ?>원</span>
					</div>
					<div class="d-flex justify-content-between py-3 fs-5">
						<span class="fw-bold text-gray-900">인출 가능액</span>
						<span class="fw-bolder text-primary" id="ap_withdrawable"><?= number_format((int) $wallet['withdrawable']) ?>원</span>
					</div>
				</div>
			</div>
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">인출 신청</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="alert bg-light-primary text-gray-800 fs-8 p-4 mb-5">본사 승인 없이 <strong>신청 즉시 처리</strong>됩니다. (인출가능액은 이미 예수금·라이더 정산금을 제외한 순수 조직 몫)</div>
					<div class="mb-4">
						<label class="form-label required" for="ap_amount">인출 금액 (원)</label>
						<input type="number" class="form-control form-control-solid" id="ap_amount" min="1" step="1000" placeholder="0" />
					</div>
					<button type="button" class="btn btn-primary" id="ap_submit">인출 신청</button>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<div class="col-xl-<?= $isSelf ? '7' : '12' ?>">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">인출 내역</h3></div>
				<div class="card-body pt-2">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle fs-7 gy-3">
							<thead>
								<tr class="fw-bold text-muted">
									<?php if (!$isSelf) : ?><th>조직</th><?php endif; ?>
									<th class="text-end">금액</th>
									<th class="text-center">상태</th>
									<th>신청일시</th>
									<th>완료일시</th>
								</tr>
							</thead>
							<tbody id="ap_tbody">
								<?php if ($rows === []) : ?>
								<tr><td colspan="<?= $isSelf ? 4 : 5 ?>" class="text-center text-muted py-6">인출 내역이 없습니다.</td></tr>
								<?php else : foreach ($rows as $r) : ?>
								<tr>
									<?php if (!$isSelf) : ?><td><?= htmlspecialchars($r['agency_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
									<td class="text-end fw-bold"><?= number_format((int) $r['amount']) ?>원</td>
									<td class="text-center"><span class="badge badge-light-<?= htmlspecialchars($r['status_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r['status_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
									<td class="text-muted"><?= htmlspecialchars($r['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-muted"><?= htmlspecialchars($r['completed_at'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var isAgency = <?= $isSelf ? 'true' : 'false' ?>;
		var toast = document.getElementById('ap_toast');
		var toastMsg = document.getElementById('ap_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }

		if (isAgency) {
			var submitBtn = document.getElementById('ap_submit');
			submitBtn.addEventListener('click', function () {
				var amount = parseInt(document.getElementById('ap_amount').value, 10) || 0;
				if (amount <= 0) { showToast('인출 금액을 입력하세요.', false); return; }
				submitBtn.disabled = true;
				fetch(API, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ action: 'create', amount: amount }),
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '신청 실패');
						showToast(res.message, true);
						if (res.wallet) {
							document.getElementById('ap_balance').textContent = won(res.wallet.balance);
							document.getElementById('ap_debt').textContent = won(res.wallet.rider_debt);
							document.getElementById('ap_reserve').textContent = won(res.wallet.withholding_reserve);
							document.getElementById('ap_withdrawable').textContent = won(res.wallet.withdrawable);
						}
						document.getElementById('ap_amount').value = '';
						setTimeout(function () { location.reload(); }, 900);
					})
					.catch(function (e) { showToast(e.message || '신청 실패', false); })
					.finally(function () { submitBtn.disabled = false; });
			});
		}
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

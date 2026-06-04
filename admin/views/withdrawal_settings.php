<?php

declare(strict_types=1);

require_once INC_PATH . '/WithdrawalConfig.php';

$config  = WithdrawalConfig::get();
$apiUrl  = ADMIN_BASE . '/api/withdrawal_config.php';
$listUrl = admin_url('withdrawal/list');
$needsMigrate = !db_table_exists('withdrawal_config');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 정책 설정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">정책</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">신청 목록</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate_withdrawal_wallet.php</code> 를 실행하세요.</div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-wallet fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			라이더 출금은 <strong>전액 출금</strong>만 가능합니다. 지갑 잔액에서 <strong>보증금</strong>을 남기고, <strong>건당 출금 수수료</strong>를 차감한 금액이 이체액(<code>amount</code>)입니다.
			수수료 구간은 <strong>적립 일수</strong>(<code>rider_wallets.accrued_days</code>) 기준입니다.
		</div>
	</div>

	<div id="wd_cfg_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="wd_cfg_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">정책 값</h3></div>
				<div class="card-body pt-0">
					<form id="wd_cfg_form" class="fs-7">
						<div class="mb-6">
							<label class="form-label required" for="cfg_reserve">보증금 (원)</label>
							<input type="number" class="form-control form-control-solid" id="cfg_reserve" min="0" step="1000"
								value="<?= (int) $config['reserve_amount'] ?>" required />
							<div class="form-text">출금 후에도 지갑에 남기는 금액. 잔액 − 보증금 − 수수료 = 실지급액</div>
						</div>
						<div class="mb-6">
							<label class="form-label required" for="cfg_threshold">적립 일수 기준</label>
							<input type="number" class="form-control form-control-solid" id="cfg_threshold" min="1" max="365"
								value="<?= (int) $config['fee_day_threshold'] ?>" required />
							<div class="form-text">이 값 <strong>미만</strong>이면 짧은 구간 수수료, <strong>이상</strong>이면 긴 구간 수수료(건당)</div>
						</div>
						<div class="row g-4 mb-6">
							<div class="col-md-6">
								<label class="form-label required" for="cfg_fee_short">건당 수수료 — 기준 미만 (원)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_fee_short" min="0"
									value="<?= (int) $config['fee_per_tx_short'] ?>" required />
							</div>
							<div class="col-md-6">
								<label class="form-label required" for="cfg_fee_long">건당 수수료 — 기준 이상 (원)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_fee_long" min="0"
									value="<?= (int) $config['fee_per_tx_long'] ?>" required />
							</div>
						</div>
						<button type="button" class="btn btn-primary" id="cfg_save_btn">저장</button>
					</form>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">계산 예시</h3></div>
				<div class="card-body pt-0 fs-7 text-gray-700">
					<p class="mb-3">잔액 600,000원 · 보증금 50,000원 · 적립 5일 → 수수료 <strong><?= (int) $config['fee_per_tx_short'] ?>원</strong></p>
					<p class="mb-3">실지급 = 600,000 − 50,000 − <?= (int) $config['fee_per_tx_short'] ?> = <strong><?= number_format(600000 - (int) $config['reserve_amount'] - (int) $config['fee_per_tx_short']) ?>원</strong> (보증금은 설정값 기준)</p>
					<p class="mb-0">적립 <?= (int) $config['fee_day_threshold'] ?>일 이상이면 건당 <?= (int) $config['fee_per_tx_long'] ?>원 적용</p>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('wd_cfg_toast');
		var toastMsg = document.getElementById('wd_cfg_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		document.getElementById('cfg_save_btn').addEventListener('click', function () {
			var payload = {
				action: 'save',
				reserve_amount: parseInt(document.getElementById('cfg_reserve').value, 10) || 0,
				fee_day_threshold: parseInt(document.getElementById('cfg_threshold').value, 10) || 7,
				fee_per_tx_short: parseInt(document.getElementById('cfg_fee_short').value, 10) || 0,
				fee_per_tx_long: parseInt(document.getElementById('cfg_fee_long').value, 10) || 0,
			};
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					showToast(res.message || '저장되었습니다.', true);
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

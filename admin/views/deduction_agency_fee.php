<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyFeeConfig.php';

// 멀티테넌시: 대리점=자기 설정 / 본사=전역 기본 / 총판=전역 기본 조회만
$level        = admin_org_level();
$isAgencySelf = $level === Org::LEVEL_AGENCY;
$isHq         = $level === Org::LEVEL_ADMIN;
$cfgOrgId     = $isAgencySelf ? admin_org_id() : null;
$config       = AgencyFeeConfig::get($cfgOrgId);
$apiUrl       = ADMIN_BASE . '/api/agency_fee_config.php';
$needsMigrate = !AgencyFeeConfig::tableReady();
// 총판은 저장 불가 — 저장 대상이 전역 기본값이라 하위 대리점 전체에 영향이 가기 때문(API에서도 차단).
$canWrite     = admin_can_write('deduction') && ($isAgencySelf || $isHq);
$readOnlyNote = (!$isAgencySelf && !$isHq);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">선공제(대행 수수료) 설정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">차감·수수료</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">선공제(대행)</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">정산 수수료 내역</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-wallet fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			정산 반영 시 차감되는 <strong>대행 수수료</strong>는 정산액 비율이 아니라 <strong>건당 정액</strong>입니다.
			라이더 <strong>적립 일수</strong>(<code>rider_wallets.accrued_days</code>)가 기준 미만이면 짧은 구간, 이상이면 긴 구간 금액이 적용됩니다.
			(출금 수수료와 동일한 구간 방식, 금액은 이 화면에서 별도 설정)
		</div>
	</div>

	<div id="agency_fee_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="agency_fee_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">수수료 정책</h3></div>
				<div class="card-body pt-0">
					<form id="agency_fee_form" class="fs-7">
						<div class="mb-6">
							<label class="form-label required" for="cfg_threshold">적립 일수 기준</label>
							<input type="number" class="form-control form-control-solid" id="cfg_threshold" min="1" max="365"
								value="<?= (int) $config['fee_day_threshold'] ?>" <?= $canWrite ? '' : 'readonly' ?> required />
							<div class="form-text">이 값 <strong>미만</strong>이면 짧은 구간, <strong>이상</strong>이면 긴 구간 건당 수수료</div>
						</div>
						<div class="row g-4 mb-6">
							<div class="col-md-6">
								<label class="form-label required" for="cfg_fee_short">건당 수수료 — 기준 미만 (원)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_fee_short" min="0"
									value="<?= (int) $config['fee_per_tx_short'] ?>" <?= $canWrite ? '' : 'readonly' ?> required />
							</div>
							<div class="col-md-6">
								<label class="form-label required" for="cfg_fee_long">건당 수수료 — 기준 이상 (원)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_fee_long" min="0"
									value="<?= (int) $config['fee_per_tx_long'] ?>" <?= $canWrite ? '' : 'readonly' ?> required />
							</div>
						</div>
						<?php if ($canWrite) : ?>
						<button type="button" class="btn btn-primary" id="cfg_save_btn">저장</button>
						<?php elseif ($readOnlyNote) : ?>
						<p class="text-muted mb-0">
							총판 계정은 <strong>조회만</strong> 가능합니다. 전역 기본값은 본사가, 대리점별 설정은 해당 대리점이 관리합니다.
						</p>
						<?php else : ?>
						<p class="text-muted mb-0">조회 전용 계정은 설정을 변경할 수 없습니다.</p>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">적용 예시</h3></div>
				<div class="card-body pt-0 fs-7 text-gray-700">
					<p class="mb-3">적립 5일 → 대행 수수료 <strong><?= (int) $config['fee_per_tx_short'] ?>원</strong> (기준 <?= (int) $config['fee_day_threshold'] ?>일 미만)</p>
					<p class="mb-3">적립 <?= (int) $config['fee_day_threshold'] ?>일 이상 → 대행 수수료 <strong><?= (int) $config['fee_per_tx_long'] ?>원</strong></p>
					<p class="mb-0 text-muted">정산 반영 시점의 적립 일수로 판단하며, 반영 후 적립 일수는 +1 됩니다.</p>
				</div>
			</div>
		</div>
	</div>

	<?php if ($canWrite) : ?>
	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('agency_fee_toast');
		var toastMsg = document.getElementById('agency_fee_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		document.getElementById('cfg_save_btn').addEventListener('click', function () {
			var payload = {
				action: 'save',
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
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

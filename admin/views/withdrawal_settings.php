<?php

declare(strict_types=1);

require_once INC_PATH . '/org_scope_picker.php';

require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/Organization.php';

// 멀티테넌시: 대리점=자기 설정 편집 / 본사=대리점 지정해 편집 / 총판=하위 대리점 조회만
$isAgencySelf = admin_org_level() === Org::LEVEL_AGENCY;
$isSuper      = admin_has_role('super');
$isDistributor = admin_org_level() === Org::LEVEL_DISTRIBUTOR;
// 총판은 저장 불가(API에서도 차단) — 대리점 정책은 대리점 본인 또는 본사가 정한다.
$canEdit      = $isAgencySelf || $isSuper;
$agencyOptions = [];
$targetAgency  = null;

if ($isAgencySelf) {
    $cfgOrgId = admin_org_id();
} else {
    $agencyOptions = Organization::agencyOptions();
    $agencyIdParam = (int) ($_GET['agency'] ?? 0);
    if ($agencyIdParam > 0) {
        $targetAgency = Organization::find($agencyIdParam);
        if ($targetAgency === null || $targetAgency['level'] !== Org::LEVEL_AGENCY) {
            $targetAgency = null;
        }
    }
    $cfgOrgId = $targetAgency !== null ? (int) $targetAgency['id'] : null;
}

$config  = WithdrawalConfig::get($cfgOrgId);

require_once INC_PATH . '/FirmBankingGateway.php';
$firmIsMock = FirmBankingGatewayFactory::isMock();

// 플랫폼(PG) 수수료 — 예전엔 「수수료 설정」 화면에서 따로 편집했으나, 그 화면이 정산수수료까지
// 같이 편집해 **같은 값을 두 화면에서 고치는** 상태였다. 편집은 이 화면 한 곳으로 모으고
// 「수수료 설정」은 전 대리점 비교용 읽기 전용으로 돌렸다.
require_once INC_PATH . '/PgFeeConfig.php';
$pgFeeReady = PgFeeConfig::tableExists();
$pgFee      = ($pgFeeReady && $cfgOrgId !== null) ? PgFeeConfig::breakdownForAgency($cfgOrgId) : null;
$apiUrl  = ADMIN_BASE . '/api/withdrawal_config.php';
$listUrl = admin_url('withdrawal/list');
$settingsBaseUrl = admin_url('withdrawal/settings');
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
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-wallet fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			라이더 출금은 <strong>전액 출금</strong>만 가능합니다. 지갑 잔액에서 <strong>보증금</strong>을 남기고, <strong>건당 출금 수수료</strong>를 차감한 금액이 이체액(<code>amount</code>)입니다.
			수수료 구간은 <strong>주문의 정산일로부터 경과일</strong> 기준입니다 — 한 번의 출금 안에서도 최근 주문과 오래된 주문에 서로 다른 단가가 붙어 합산됩니다.
		</div>
	</div>

	<?php if (!$isAgencySelf) : ?>
	<!--begin::본사용 대리점 선택-->
	<div class="card card-flush mb-6">
		<div class="card-body py-4">
			<form method="get" action="<?= htmlspecialchars($settingsBaseUrl, ENT_QUOTES, 'UTF-8') ?>" class="d-flex flex-wrap align-items-center gap-3">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
					<input type="hidden" name="route" value="withdrawal/settings" />
				<?php endif; ?>
				<?php // 총판 → 대상(전역/대리점). 대리점 고르면 자동 이동. agency 파라미터 유지.
				org_scope_picker('wd', 0, $targetAgency !== null ? (int) $targetAgency['id'] : 0, [
					'dist_col' => 'w-200px', 'agency_col' => 'w-250px',
					'dist_label' => '총판', 'agency_label' => '대상',
					'agency_name' => 'agency', 'submit_on_change' => true,
					'extra_options' => [['value' => 0, 'label' => '전역 기본값(대리점 미지정 폴백)', 'selected' => $targetAgency === null]],
				]); ?>
				<noscript><button type="submit" class="btn btn-sm btn-light-primary">이동</button></noscript>
				<?php if ($targetAgency !== null) : ?>
					<span class="badge badge-light-primary fs-7">이 대리점 전용값을 보는 중</span>
				<?php else : ?>
					<span class="badge badge-light-secondary fs-7">전역 기본값 — 대리점 전용 설정이 없는 곳에 적용됨</span>
				<?php endif; ?>
			</form>
		</div>
	</div>
	<!--end::본사용 대리점 선택-->
	<?php endif; ?>

	<div id="wd_cfg_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="wd_cfg_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">정책 값<?php if (!$isAgencySelf) : ?><span class="text-muted fs-7 fw-normal ms-2"><?= $targetAgency !== null ? htmlspecialchars((string) $targetAgency['name'], ENT_QUOTES, 'UTF-8') : '전역 기본값' ?></span><?php endif; ?></h3>
				</div>
				<div class="card-body pt-0">
					<form id="wd_cfg_form" class="fs-7">
						<div class="mb-6 p-4 border border-gray-300 rounded">
							<label class="form-check form-switch form-check-custom form-check-solid mb-2">
								<input class="form-check-input" type="checkbox" id="cfg_auto_transfer"
									<?= (int) $config['auto_transfer_on_request'] === 1 ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> />
								<span class="form-check-label fw-bold text-gray-800 ms-3">출금 신청 즉시 이체</span>
							</label>
							<div class="form-text mb-0">
								켜면 라이더가 앱에서 출금을 신청하는 <strong>즉시 펌뱅킹으로 송금</strong>되고 「출금 신청 목록」에 바로 완료로 들어옵니다.
								끄면 지금처럼 <strong>대기</strong> 상태로 쌓이고, 관리자가 「출금 확정」을 눌러야 나갑니다.
								<span class="d-block mt-1">이체가 실패하면 신청은 그대로 남고 목록에서 재시도할 수 있습니다.</span>
							</div>
							<?php if ($firmIsMock) : ?>
							<div class="alert bg-light-warning text-gray-800 fs-8 p-3 mt-3 mb-0">
								⚠️ 펌뱅킹이 아직 <strong>모의(Mock) 연동</strong>입니다. 지금 이걸 켜면 <strong>실제 송금 없이</strong> 완료로 기록되고 지갑만 차감됩니다.
								실제 이체가 필요하면 중계사 연동을 마친 뒤 켜세요.
							</div>
							<?php endif; ?>
						</div>
						<div class="mb-6">
							<label class="form-label required" for="cfg_reserve">보증금 (원)</label>
							<input type="number" class="form-control form-control-solid" id="cfg_reserve" min="0" step="1000"
								value="<?= (int) $config['reserve_amount'] ?>" required />
							<div class="form-text">출금 후에도 지갑에 남기는 금액. 잔액 − 보증금 − 수수료 = 실지급액</div>
						</div>
						<div class="mb-6">
							<label class="form-label required" for="cfg_threshold">경과일 기준</label>
							<input type="number" class="form-control form-control-solid" id="cfg_threshold" min="1" max="365"
								value="<?= (int) $config['fee_day_threshold'] ?>" required />
							<div class="form-text">정산일로부터 이 일수 <strong>미만</strong>인 주문은 짧은 구간 단가, <strong>이상</strong>이면 긴 구간 단가(건당)</div>
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

						<div class="separator separator-dashed my-6"></div>
						<h4 class="fw-bold fs-6 mb-2">정산수수료 배분 <span class="badge badge-light-danger fs-8 ms-1">본사만 설정</span></h4>
						<div class="text-muted fs-8 mb-4">
							위에서 라이더에게 받은 정산수수료를 본사·총판·대리점이 나눠 갖습니다.
							<strong>본사 몫은 배달 건당 정액</strong>이고, 남은 금액을 총판 비율만큼 떼고 <strong>나머지 전부가 대리점 몫</strong>입니다.
						</div>
						<div class="row g-4 mb-6">
							<div class="col-md-6">
								<label class="form-label" for="cfg_hq_per_order">본사 몫 — 배달 건당 (원)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_hq_per_order" min="0"
									value="<?= (int) $config['hq_fee_per_order'] ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
								<div class="form-text fs-9">배달 <strong>1건당</strong> 본사가 가져갈 금액입니다(출금 건당 수수료와 단위가 다릅니다). 본사 몫이 걷은 정산수수료 총액을 넘으면 총액까지만 가져가고, 그만큼 대리점 몫은 0원이 될 수 있습니다. 0이면 배분 없이 전액 대리점.</div>
							</div>
							<div class="col-md-6">
								<label class="form-label" for="cfg_dist_pct">총판 몫 — 본사 몫 제외 후 (%)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_dist_pct" min="0" max="100" step="0.01"
									value="<?= htmlspecialchars((string) $config['fee_share_distributor_pct'], ENT_QUOTES, 'UTF-8') ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
								<div class="form-text fs-9">나머지는 대리점 몫입니다.</div>
							</div>
						</div>
						<?php if ($isAgencySelf) : ?>
						<div class="alert bg-light-secondary fs-8 p-3 mb-6">배분 설정은 본사가 관리합니다. 조회만 가능합니다.</div>
						<?php endif; ?>

						<?php if ($pgFee !== null) : ?>
						<div class="separator separator-dashed my-6"></div>
						<h4 class="fw-bold fs-6 mb-2">플랫폼 수수료 <span class="text-muted fs-8 fw-normal">(PG 결제 시 분배)</span><?= $isAgencySelf ? ' <span class="badge badge-light-danger fs-8 ms-1">본사만 설정</span>' : '' ?></h4>
						<div class="text-muted fs-8 mb-4">
							라이더에게 자금을 조달(PG 카드결제)할 때 붙는 수수료를 본사·총판·대리점이 나눠 갖습니다.
							결제 시점의 요율이 그대로 저장되므로 나중에 값을 바꿔도 과거 내역은 변하지 않습니다.
						</div>
						<div class="row g-4 mb-3">
							<div class="col-md-4">
								<label class="form-label" for="cfg_pf_hq">본사 몫 (%)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_pf_hq" min="0" max="100" step="0.01"
									value="<?= htmlspecialchars(number_format($pgFee['hq'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
							</div>
							<div class="col-md-4">
								<label class="form-label" for="cfg_pf_dist">총판 몫 (%)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_pf_dist" min="0" max="100" step="0.01"
									value="<?= htmlspecialchars(number_format($pgFee['distributor'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
							</div>
							<div class="col-md-4">
								<label class="form-label" for="cfg_pf_agency">대리점 몫 (%)</label>
								<input type="number" class="form-control form-control-solid" id="cfg_pf_agency" min="0" max="100" step="0.01"
									value="<?= htmlspecialchars(number_format($pgFee['agency'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
							</div>
						</div>
						<div class="form-text fs-9 mb-6">합계 <strong id="cfg_pf_total"><?= number_format($pgFee['hq'] + $pgFee['distributor'] + $pgFee['agency'], 2) ?></strong>% 가 결제금액에 붙습니다.</div>
						<?php endif; ?>

						<?php if ($canEdit) : ?>
						<button type="button" class="btn btn-primary" id="cfg_save_btn">저장</button>
						<?php else : ?>
						<div class="alert bg-light-secondary fs-8 p-3 mb-0">
							총판 계정은 <strong>조회만</strong> 가능합니다. 대리점 정책은 해당 대리점 또는 본사가 설정합니다.
						</div>
						<?php endif; ?>
					</form>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">계산 예시</h3></div>
				<div class="card-body pt-0 fs-7 text-gray-700">
					<p class="mb-3">잔액 600,000원 · 보증금 50,000원 · 정산 5일 지난 주문 10건 → 수수료 <strong><?= number_format(10 * (int) $config['fee_per_tx_short']) ?>원</strong> (10건 × <?= (int) $config['fee_per_tx_short'] ?>원)</p>
					<p class="mb-3">실지급 = 600,000 − <?= number_format((int) $config['reserve_amount']) ?> − <?= number_format(10 * (int) $config['fee_per_tx_short']) ?> = <strong><?= number_format(600000 - (int) $config['reserve_amount'] - 10 * (int) $config['fee_per_tx_short']) ?>원</strong> (보증금은 설정값 기준)</p>
					<p class="mb-0">정산일로부터 <?= (int) $config['fee_day_threshold'] ?>일 이상 지난 주문은 건당 <?= (int) $config['fee_per_tx_long'] ?>원 적용</p>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var PG_API = <?= json_encode(ADMIN_BASE . '/api/pg_fee_config.php', JSON_UNESCAPED_UNICODE) ?>;
		var TARGET_AGENCY_ID = <?= $targetAgency !== null ? (int) $targetAgency['id'] : 0 ?>;
		var toast = document.getElementById('wd_cfg_toast');
		var toastMsg = document.getElementById('wd_cfg_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		// 플랫폼 수수료 합계 실시간 표시
		var pfIds = ['cfg_pf_hq', 'cfg_pf_dist', 'cfg_pf_agency'];
		var pfTotal = document.getElementById('cfg_pf_total');
		if (pfTotal) {
			pfIds.forEach(function (id) {
				var el = document.getElementById(id);
				if (el) {
					el.addEventListener('input', function () {
						var t = pfIds.reduce(function (a, i) {
							var e = document.getElementById(i);
							return a + (e ? parseFloat(e.value) || 0 : 0);
						}, 0);
						pfTotal.textContent = t.toFixed(2);
					});
				}
			});
		}

		// 플랫폼 수수료는 저장 엔드포인트가 다르다(org_fee_config). 출금 정책과 한 버튼으로
		// 묶되, 편집 가능한 상태가 아니면 아무것도 보내지 않고 즉시 성공 처리한다.
		function savePlatformFee() {
			var hq = document.getElementById('cfg_pf_hq');
			if (!hq || hq.disabled || TARGET_AGENCY_ID < 1) { return Promise.resolve(); }
			return fetch(PG_API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({
					action: 'save_platform',
					org_id: TARGET_AGENCY_ID,
					hq_pct: parseFloat(hq.value) || 0,
					distributor_pct: parseFloat(document.getElementById('cfg_pf_dist').value) || 0,
					agency_pct: parseFloat(document.getElementById('cfg_pf_agency').value) || 0
				})
			}).then(function (r) { return r.json(); }).then(function (res) {
				if (!res.ok) { throw new Error(res.message || '플랫폼 수수료 저장 실패'); }
			});
		}

		var saveBtn = document.getElementById('cfg_save_btn');
		if (!saveBtn) return; // 조회 전용(총판)이면 저장 버튼이 아예 없다.
		saveBtn.addEventListener('click', function () {
			var payload = {
				action: 'save',
				reserve_amount: parseInt(document.getElementById('cfg_reserve').value, 10) || 0,
				fee_day_threshold: parseInt(document.getElementById('cfg_threshold').value, 10) || 7,
				fee_per_tx_short: parseInt(document.getElementById('cfg_fee_short').value, 10) || 0,
				fee_per_tx_long: parseInt(document.getElementById('cfg_fee_long').value, 10) || 0,
				auto_transfer_on_request: document.getElementById('cfg_auto_transfer').checked ? 1 : 0,
			};
			if (TARGET_AGENCY_ID > 0) { payload.agency_id = TARGET_AGENCY_ID; }
			// 배분 설정은 본사만 보낸다 — 대리점이 저장할 땐 키를 아예 빼서 서버가 기존 값을 유지하게 한다.
			var hqEl = document.getElementById('cfg_hq_per_order');
			if (hqEl && !hqEl.disabled) {
				payload.hq_fee_per_order = parseInt(hqEl.value, 10) || 0;
				payload.fee_share_distributor_pct = parseFloat(document.getElementById('cfg_dist_pct').value) || 0;
			}
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					return savePlatformFee().then(function () {
						showToast(res.message || '저장되었습니다.', true);
					});
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

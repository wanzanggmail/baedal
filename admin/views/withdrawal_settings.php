<?php

declare(strict_types=1);

require_once INC_PATH . '/org_scope_picker.php';

require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/AgencyFeeConfig.php';

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
// 전역 기본값($cfgOrgId === null)일 때도 보여준다(2026-09-06 갑) — 예전엔 여기서 null 이 돼
// 플랫폼 수수료 블록이 통째로 숨었고, 그래서 **전역 기본값을 볼 수도 고칠 수도 없었다.**
$pgFee = $pgFeeReady
    ? ($cfgOrgId !== null ? PgFeeConfig::breakdownForAgency($cfgOrgId) : PgFeeConfig::globalBreakdown())
    : null;
$apiUrl  = ADMIN_BASE . '/api/withdrawal_config.php';
// 본사 몫 하한값은 「대행수수료 설정」의 최저 금액(구간별)을 그대로 쓴다 — 별도 필드 없음.
// 최저 금액(하한) 장치는 2026-09-08 폐지 — 총액이 합에서 나오므로 대리점이 본사 몫을 깎을 수 없다.
// 대리점 선차감(2026-09-06 갑) — 여기서도 대리점을 골라 바로 정할 수 있게 한다.
// 값이 없는 대리점은 전역 상속분이 보인다(저장하면 그 값으로 자기 행이 생긴다).
$predeductReady = AgencyFeeConfig::predeductReady();
$predeductFee   = $predeductReady ? AgencyFeeConfig::prededuct($cfgOrgId) : 0;
$agencyFeeUrl = admin_url('deduction/agency-fee');
$listUrl = admin_url('withdrawal/list');
$settingsBaseUrl = admin_url('withdrawal/settings');
$needsMigrate = !db_table_exists('withdrawal_config');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">수수료 설정(관리)</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료 설정(관리)</li>
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
						<?php // 건당 수수료는 2026-09-08 부터 **파생값**이다 — 아래 「정산수수료 구성」의 합.
						      //    입력칸으로 두면 그 합과 어긋날 수 있어(예전에 실제로 어긋났다) 읽기 전용으로 보여준다. ?>
						<div class="row g-4 mb-6">
							<div class="col-md-6">
								<label class="form-label">건당 수수료 — 기준 미만 (원)</label>
								<div class="form-control form-control-solid bg-light fw-bold fs-5"><?= number_format((int) $config['fee_per_tx_short']) ?></div>
							</div>
							<div class="col-md-6">
								<label class="form-label">건당 수수료 — 기준 이상 (원)</label>
								<div class="form-control form-control-solid bg-light fw-bold fs-5"><?= number_format((int) $config['fee_per_tx_long']) ?></div>
							</div>
							<div class="col-12">
								<div class="form-text">아래 <strong>「정산수수료 구성」</strong>의 합입니다. 바꾸려면 그 표에서 조정하세요.</div>
							</div>
						</div>

						<div class="mb-6" style="max-width:320px">
							<label class="form-label" for="cfg_transfer_fee">이체 수수료 (원) <span class="badge badge-light-danger fs-8 ms-1">본사만 설정</span></label>
							<input type="number" class="form-control form-control-solid" id="cfg_transfer_fee" min="0" step="10"
								value="<?= (int) $config['transfer_fee'] ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
							<div class="form-text fs-9">펌뱅킹 이체(일일이체·출금신청·출금대행)가 <strong>일어날 때마다</strong> 라이더에게 부과하는 정액입니다. 실지급액에서 빠져 <strong>본사로 귀속</strong>됩니다. 정산수수료를 뗀 뒤에도 지급액이 남을 때만 부과됩니다.</div>
						</div>

						<?php if ($predeductReady) : ?>
						<div class="mb-6" style="max-width:320px">
							<label class="form-label" for="cfg_prededuct">대리점 선차감 수수료 — 배달 건당 (원)
								<span class="badge badge-light-<?= $targetAgency !== null ? 'primary' : 'warning' ?> fs-8 ms-1"><?= $targetAgency !== null
									? htmlspecialchars((string) $targetAgency['name'], ENT_QUOTES, 'UTF-8') : '전역 기본값' ?></span></label>
							<input type="number" class="form-control form-control-solid" id="cfg_prededuct" min="0" step="10"
								value="<?= (int) $predeductFee ?>"<?= $isAgencySelf ? ' disabled' : '' ?> />
							<div class="form-text fs-9">
								배달 건당 이 금액을 <strong>라이더 정산 기준액에서 먼저</strong> 뗍니다. 뗀 돈은 <strong>대리점에 남습니다</strong>(별도 이체 없음).
								<strong>라이더에게는 보이지 않습니다</strong> — 명세서·앱에는 그만큼 낮아진 정산금액만 나옵니다.
								원천세·고용·산재도 선차감을 뺀 금액 기준입니다. <strong>0</strong>이면 사용 안 함.
								<?php if ($targetAgency === null) : ?><br><span class="text-warning fw-semibold">전역 기본값</span> — 자기 설정이 있는 대리점에는 적용되지 않습니다.<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>

						<div class="separator separator-dashed my-6"></div>
						<h4 class="fw-bold fs-6 mb-2">정산수수료 구성</h4>
						<div class="text-muted fs-8 mb-4">
							라이더가 내는 정산수수료는 <strong>전역 고정분 + 추가분</strong>의 합입니다(2026-09-08 개편).
							예전처럼 총액을 따로 정하지 않으므로 <strong>총액과 배분이 어긋날 수 없습니다.</strong>
							<div class="mt-2 font-monospace text-gray-800">
								정산수수료(건당) = 본사 + 세무대리 + 개발사 <span class="text-muted">(전역 고정)</span>
								&nbsp;+&nbsp; 총판 추가 &nbsp;+&nbsp; 대리점 추가 <span class="text-muted">(대리점이 설정)</span>
							</div>
						</div>
						<div class="table-responsive mb-2">
							<table class="table table-row-bordered align-middle gy-2 mb-0">
								<thead>
									<tr class="fw-semibold fs-8 text-muted">
										<th class="min-w-80px">구간</th>
										<th class="min-w-100px">본사<br><span class="fw-normal fs-9 text-danger">전역 고정</span></th>
										<th class="min-w-100px">세무대리<br><span class="fw-normal fs-9 text-danger">전역 고정</span></th>
										<th class="min-w-100px">개발사<br><span class="fw-normal fs-9 text-danger">전역 고정</span></th>
										<th class="min-w-100px">총판 추가<br><span class="fw-normal fs-9 text-primary">대리점 설정</span></th>
										<th class="min-w-100px">대리점 추가<br><span class="fw-normal fs-9 text-primary">대리점 설정</span></th>
										<th class="min-w-90px text-end">합계<br>(라이더 부담)</th>
									</tr>
								</thead>
								<tbody>
									<?php
									// 전역 고정 3칸은 **전역 기본값 화면에서만** 편집한다. 대리점을 고르고 들어오면
									// 읽기 전용 — 대리점이 본사 몫을 만질 수 있으면 「전역 고정」이 아니게 된다.
									$fixedRO = ($targetAgency !== null || $isAgencySelf) ? ' disabled' : '';
									$addRO   = $isAgencySelf ? ' disabled' : '';
									foreach ([['short', '기준 미만'], ['long', '기준 이상']] as [$b, $bLabel]) : ?>
									<tr>
										<td class="fw-semibold"><?= $bLabel ?></td>
										<td><input type="number" class="form-control form-control-solid form-control-sm" id="cfg_hq_<?= $b ?>" min="0"
											value="<?= (int) ($config['hq_fee_' . $b] ?? 0) ?>"<?= $fixedRO ?> /></td>
										<td><input type="number" class="form-control form-control-solid form-control-sm" id="cfg_tax_<?= $b ?>" min="0"
											value="<?= (int) ($config['tax_fee_' . $b] ?? 0) ?>"<?= $fixedRO ?> /></td>
										<td><input type="number" class="form-control form-control-solid form-control-sm" id="cfg_dev_<?= $b ?>" min="0"
											value="<?= (int) ($config['dev_fee_' . $b] ?? 0) ?>"<?= $fixedRO ?> /></td>
										<td><input type="number" class="form-control form-control-solid form-control-sm" id="cfg_dist_<?= $b ?>" min="0"
											value="<?= (int) ($config['dist_fee_' . $b] ?? 0) ?>"<?= $addRO ?> /></td>
										<td><input type="number" class="form-control form-control-solid form-control-sm" id="cfg_add_<?= $b ?>" min="0"
											value="<?= (int) ($config['agency_add_' . $b] ?? 0) ?>"<?= $addRO ?> /></td>
										<td class="text-end fw-bold fs-6" id="cfg_total_<?= $b ?>">–</td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<div class="form-text fs-9 mb-6" id="cfg_share_hint">
							세무대리·개발사 몫은 <strong>각 조직 지갑으로 실제 이체</strong>됩니다.
							<?php if ($targetAgency !== null) : ?>
							<br><span class="text-danger fw-semibold">본사·세무대리·개발사 몫은 전역 고정</span>이라 여기서는 못 바꿉니다 —
							대상을 <strong>「전역 기본값」</strong>으로 바꿔 수정하세요. 이 화면에서는 <strong>총판 추가·대리점 추가</strong>만 저장됩니다.
							<?php else : ?>
							<br>여기서 정한 <strong>전역 고정분은 모든 대리점에 그대로 적용</strong>됩니다. 추가분은 대리점별로 따로 정합니다.
							<?php endif; ?>
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
							<?php if ($targetAgency === null) : ?>
							<div class="mt-2 text-gray-700"><span class="fw-bold">여기는 전역 기본값입니다.</span>
								대리점별로 따로 정하지 않은 곳에 이 값이 적용됩니다.</div>
							<?php endif; ?>
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
		var FEE_API = <?= json_encode(ADMIN_BASE . '/api/agency_fee_config.php', JSON_UNESCAPED_UNICODE) ?>;
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

		// 정산수수료 구성 — 합계(라이더 부담)를 입력하는 동안 바로 보여준다.
		// 총액은 다섯 칸의 합이라 어긋날 수가 없다(2026-09-08 개편).
		(function () {
			function intv(id) { var e = document.getElementById(id); return e ? (parseInt(e.value, 10) || 0) : 0; }
			var PARTS = ['hq', 'tax', 'dev', 'dist', 'add'];
			function recompute() {
				['short', 'long'].forEach(function (b) {
					var sum = 0;
					PARTS.forEach(function (p) { sum += intv('cfg_' + p + '_' + b); });
					var out = document.getElementById('cfg_total_' + b);
					if (out) { out.textContent = sum.toLocaleString() + '원'; }
				});
			}
			['short', 'long'].forEach(function (b) {
				PARTS.forEach(function (p) {
					var el = document.getElementById('cfg_' + p + '_' + b);
					if (el) { el.addEventListener('input', recompute); }
				});
			});
			recompute();
		})();

		// 플랫폼 수수료는 저장 엔드포인트가 다르다(org_fee_config). 출금 정책과 한 버튼으로
		// 묶되, 편집 가능한 상태가 아니면 아무것도 보내지 않고 즉시 성공 처리한다.
		function savePlatformFee() {
			var hq = document.getElementById('cfg_pf_hq');
			/* TARGET_AGENCY_ID 가 0 이면 전역 기본값 저장이다 — 예전엔 여기서 걸러져
			   전역 값을 저장할 방법이 아예 없었다(2026-09-06). */
			if (!hq || hq.disabled) { return Promise.resolve(); }
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

		/* 선차감은 저장 엔드포인트가 다르다(deduction_global_config). 다른 값을 덮지 않도록
		   컬럼 하나만 쓰는 전용 액션(save_prededuct)을 쓴다. */
		function savePrededuct() {
			var el = document.getElementById('cfg_prededuct');
			if (!el || el.disabled) { return Promise.resolve(); }
			return fetch(FEE_API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({
					action: 'save_prededuct',
					agency_id: TARGET_AGENCY_ID,
					prededuct_fee: parseInt(el.value, 10) || 0
				})
			}).then(function (r) { return r.json(); }).then(function (res) {
				if (!res.ok) { throw new Error(res.message || '선차감 저장 실패'); }
			});
		}

		var saveBtn = document.getElementById('cfg_save_btn');
		if (!saveBtn) return; // 조회 전용(총판)이면 저장 버튼이 아예 없다.
		saveBtn.addEventListener('click', function () {
			var payload = {
				action: 'save',
				reserve_amount: parseInt(document.getElementById('cfg_reserve').value, 10) || 0,
				fee_day_threshold: parseInt(document.getElementById('cfg_threshold').value, 10) || 7,
				auto_transfer_on_request: document.getElementById('cfg_auto_transfer').checked ? 1 : 0,
			};
			if (TARGET_AGENCY_ID > 0) { payload.agency_id = TARGET_AGENCY_ID; }

			/* 정산수수료 구성(2026-09-08 개편) — 총액은 서버가 합에서 만든다. 여기서 안 보낸다.
			   전역 고정 3칸은 disabled 면 아예 빼서 서버가 기존 값을 지키게 한다(대리점 저장 시).
			   추가분 2칸은 편집 가능할 때만 보낸다. */
			var num = function (id) { return parseInt((document.getElementById(id) || {}).value, 10) || 0; };
			var hqEl = document.getElementById('cfg_hq_short');
			if (hqEl && !hqEl.disabled) {
				payload.hq_fee_short  = num('cfg_hq_short');
				payload.hq_fee_long   = num('cfg_hq_long');
				payload.tax_fee_short = num('cfg_tax_short');
				payload.tax_fee_long  = num('cfg_tax_long');
				payload.dev_fee_short = num('cfg_dev_short');
				payload.dev_fee_long  = num('cfg_dev_long');
			}
			var addEl = document.getElementById('cfg_add_short');
			if (addEl && !addEl.disabled) {
				payload.dist_fee_short   = num('cfg_dist_short');
				payload.dist_fee_long    = num('cfg_dist_long');
				payload.agency_add_short = num('cfg_add_short');
				payload.agency_add_long  = num('cfg_add_long');
			}
			// 이체 수수료도 본사 전용 — 편집 가능할 때만 보낸다.
			var tfEl = document.getElementById('cfg_transfer_fee');
			if (tfEl && !tfEl.disabled) {
				payload.transfer_fee = parseInt(tfEl.value, 10) || 0;
			}
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					return savePlatformFee().then(savePrededuct).then(function () {
						showToast(res.message || '저장되었습니다.', true);
					});
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

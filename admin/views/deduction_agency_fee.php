<?php

declare(strict_types=1);

require_once INC_PATH . '/AgencyFeeConfig.php';

// 멀티테넌시: 대리점=자기 설정 / 본사=전역 기본 / 총판=전역 기본 조회만
$level        = admin_org_level();
$isAgencySelf = $level === Org::LEVEL_AGENCY;
$isHq         = $level === Org::LEVEL_ADMIN;
$cfgOrgId     = $isAgencySelf ? admin_org_id() : null;
// 대행수수료 요율(get())은 2026-09-07 폐지 — 이 화면에 남은 값은 선차감뿐이다.
$config       = ['prededuct_fee' => AgencyFeeConfig::prededuct($cfgOrgId)];
$apiUrl       = ADMIN_BASE . '/api/agency_fee_config.php';
$needsMigrate = !AgencyFeeConfig::tableReady();
// 최저 금액(하한)은 2026-09-08 폐지 — 총액이 「전역고정 + 추가분」의 합이라
// 대리점이 본사 몫을 깎을 방법 자체가 없어졌다.
$rates        = AgencyFeeConfig::rates();   // 공제 요율(원천세·고용·산재) — 본사 전용 전역값
// 정산수수료 추가금(2026-09-08 갑) — 대리점이 자기 값을 여기서 정한다.
// 전역 고정분(본사·세무대리·개발사)은 여기서 못 만진다 — 「수수료 설정(관리)」 전역 기본값에서만.
require_once INC_PATH . '/WithdrawalConfig.php';
$wdCfg      = WithdrawalConfig::get($cfgOrgId);
$fixedShort = (int) $wdCfg['hq_fee_short'] + (int) $wdCfg['tax_fee_short'] + (int) $wdCfg['dev_fee_short'];
$fixedLong  = (int) $wdCfg['hq_fee_long'] + (int) $wdCfg['tax_fee_long'] + (int) $wdCfg['dev_fee_long'];
$feeMgmtUrl = admin_url('withdrawal/settings');
// 총판은 저장 불가 — 저장 대상이 전역 기본값이라 하위 대리점 전체에 영향이 가기 때문(API에서도 차단).
$canWrite     = admin_can_write('deduction') && ($isAgencySelf || $isHq);
// 대리점 선차감(2026-09-06 갑) — **대리점이 자기 금액을 직접 정한다**(갑 지시:
// "대리점에서 대리점마다 선차감 금액을 설정할꺼야"). 본사가 저장하면 전역 기본값이 되어
// 전용 설정이 없는 대리점에 적용된다 — 위 대행수수료와 같은 폴백 구조.
$predeductReady = AgencyFeeConfig::predeductReady();
$canEditPreded  = $canWrite && $predeductReady;
$readOnlyNote = (!$isAgencySelf && !$isHq);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">수수료 설정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">설정</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료 설정(본사 기본값)</li>
					<?php // 라우트/메뉴 상 이름은 「수수료 설정」이고, 본사 기본값을 정하는 화면이다. ?>
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
			이 화면에서는 <strong>대리점 선차감</strong>과 <strong>공제 요율</strong>(원천세·고용·산재)을 정합니다.
			<strong>정산수수료</strong>의 건당 단가·배분은 <a href="<?= htmlspecialchars(admin_url('withdrawal/settings'), ENT_QUOTES, 'UTF-8') ?>" class="link-primary fw-semibold">수수료 설정(관리)</a>에서 정합니다.
		</div>
	</div>

	<div id="agency_fee_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="agency_fee_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점 선차감</h3></div>
				<div class="card-body pt-0">
					<form id="agency_fee_form" class="fs-7">
						<?php // ⛔ 대행수수료 요율(적립일수·건당 수수료)은 2026-09-07 폐지 — 정산수수료와 통합.
						      //    같은 수수료를 두 곳에서 설정하던 것을 하나로 합쳤다. ?>
						<!-- <div class="alert bg-light-warning fs-8 p-4 mb-6">
							<span class="fw-bold">대행수수료는 정산수수료와 합쳐졌습니다.</span>
							같은 수수료를 두 이름으로 따로 설정하던 것을 하나로 정리했습니다(2026-09-07).
							건당 단가·적립일수 기준은 <a href="<?= htmlspecialchars(admin_url('withdrawal/settings'), ENT_QUOTES, 'UTF-8') ?>" class="link-primary fw-semibold">수수료 설정(관리)</a>
							의 <strong>정산수수료</strong>에서 정합니다 — 주정산 라이더는 출금 신청 시, 일정산 라이더는 일일이체 시
							<strong>주문 건수 × 단가</strong>로 한 번만 부과됩니다.
						</div> -->

						<?php // ── 대리점 선차감 수수료 (2026-09-06 갑) ── ?>
						<?php if ($predeductReady) : ?>
						<div class="separator separator-dashed my-6"></div>
						<div class="mb-6">
							<label class="form-label" for="cfg_prededuct">대리점 선차감 수수료 — 배달 건당 (원)
								<?php if ($isHq) : ?><span class="badge badge-light-warning fs-8 ms-1">전역 기본값</span>
								<?php elseif ($isAgencySelf) : ?><span class="badge badge-light-primary fs-8 ms-1">우리 대리점</span><?php endif; ?></label>
							<input type="number" class="form-control form-control-solid" id="cfg_prededuct" min="0"
								value="<?= (int) ($config['prededuct_fee'] ?? 0) ?>" <?= $canEditPreded ? '' : 'readonly' ?> />
							<div class="form-text">
								배달 건당 이 금액을 <strong>라이더 정산 기준액에서 먼저 뗍니다.</strong> 뗀 돈은 <strong>대리점에 남습니다</strong>(별도 이체 없음).<br>
								<strong>라이더에게는 이 항목이 보이지 않습니다</strong> — 명세서·앱에는 그만큼 낮아진 정산금액만 나옵니다.
								예: 건당 1,100원 · 선차감 100원 → 관리자 1,100원 / 라이더 1,000원.<br>
								원천세·고용보험·산재보험도 <strong>선차감을 뺀 금액 기준</strong>으로 매깁니다. <strong>0</strong>이면 사용하지 않습니다.
								<?php if ($isHq) : ?>
								<br><span class="text-warning fw-semibold">여기 값은 전역 기본값입니다</span> — 자기 설정을 따로 저장한 대리점에는 적용되지 않고, 그 대리점 값이 우선합니다.
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>

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
				<div class="card-header pt-5"><h3 class="card-title fw-bold">이 화면에서 정하는 것</h3></div>
				<div class="card-body pt-0 fs-7 text-gray-700">
					<p class="mb-2"><strong>대리점 선차감</strong> — 배달 건당, 대리점 몫. 라이더에게는 보이지 않습니다.</p>
					<p class="mb-2"><strong>정산수수료 추가금</strong> — 배달 건당,본사 및 총판, 대리점 몫. </p>
					<!-- <p class="mb-2"><strong>공제 요율</strong> — 원천세·고용보험·산재보험(법정요율, 본사 전용).</p>
					<p class="mb-3"><strong>최저 금액</strong> — 정산수수료 배분에서 <strong>본사 몫(본사+세무대리+개발사)</strong>의 하한.</p>
					<p class="mb-0 text-muted">건당 단가는 「수수료 설정(관리)」의 정산수수료에서 정합니다.</p> -->
				</div>
			</div>
		</div>
		<?php // ── 정산수수료 추가금 (2026-09-08 갑) ── ?>
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">정산수수료 추가금
						<?php if ($isHq) : ?><span class="badge badge-light-warning fs-8 ms-2">전역 기본값</span>
						<?php elseif ($isAgencySelf) : ?><span class="badge badge-light-primary fs-8 ms-2">우리 대리점</span><?php endif; ?></h3>
				</div>
				<div class="card-body pt-0 fs-7">
					<div class="text-muted fs-8 mb-4">
						라이더가 내는 정산수수료는 <strong>전역 고정분 + 추가분</strong>입니다.
						여기서는 <strong>총판 추가금</strong>과 <strong>대리점 추가금</strong>만 정합니다 
					</div>
					<div class="table-responsive mb-2">
						<table class="table table-row-bordered align-middle gy-2 mb-0">
							<thead>
								<tr class="fw-semibold fs-8 text-muted">
									<th class="min-w-80px">구간</th>
									<th class="min-w-110px text-end">전역 고정</th>
									<th class="min-w-110px">총판 추가 (원/건)</th>
									<th class="min-w-110px">대리점 추가 (원/건)</th>
									<th class="min-w-100px text-end">합계<br>(라이더 부담)</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ([['short', '기준 미만', $fixedShort], ['long', '기준 이상', $fixedLong]] as [$b, $bLabel, $fixed]) : ?>
								<tr>
									<td class="fw-semibold"><?= $bLabel ?></td>
									<td class="text-end text-gray-700" id="add_fixed_<?= $b ?>" data-fixed="<?= (int) $fixed ?>"><?= number_format((int) $fixed) ?>원</td>
									<td><input type="number" class="form-control form-control-solid form-control-sm" id="add_dist_<?= $b ?>" min="0"
										value="<?= (int) ($wdCfg['dist_fee_' . $b] ?? 0) ?>" <?= $canWrite ? '' : 'readonly' ?> /></td>
									<td><input type="number" class="form-control form-control-solid form-control-sm" id="add_agency_<?= $b ?>" min="0"
										value="<?= (int) ($wdCfg['agency_add_' . $b] ?? 0) ?>" <?= $canWrite ? '' : 'readonly' ?> /></td>
									<td class="text-end fw-bold fs-6" id="add_total_<?= $b ?>">–</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="form-text fs-9 mb-4">
						추가금을 올리면 <strong>라이더가 내는 정산수수료가 그만큼 늘어납니다.</strong>
						총판 추가금은 총판 지갑으로, 대리점 추가금은 대리점 몫으로 갑니다.
						<?php if ($isHq) : ?><br><span class="text-warning fw-semibold">여기 값은 전역 기본값입니다</span> — 자기 설정을 따로 저장한 대리점에는 적용되지 않습니다.<?php endif; ?>
					</div>
					<?php if ($canWrite) : ?>
					<button type="button" class="btn btn-primary" id="cfg_addons_save_btn">추가금 저장</button>
					<?php endif; ?>
				</div>
			</div>
		</div>



		<?php // 「정산수수료 최저 금액」 카드는 2026-09-08 폐지 — 총액이 전역고정+추가분의 합이라
		      //    대리점이 본사 몫을 깎을 방법 자체가 없어졌다(하한을 둘 이유가 사라짐). ?>

		<?php // 공제 요율 — 법정요율이라 대리점이 협상할 값이 아니다(본사 전용, 전역 1벌). ?>
		<?php if ($isHq) : ?>
		<div class="col-12">
			<div class="card card-flush border border-primary">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">공제 요율 <span class="badge badge-light-primary ms-2">본사 전용</span></h3>
				</div>
				<div class="card-body pt-0 fs-7">
					<div class="text-gray-700 mb-5">
						정산 반영 시 라이더 정산금에서 공제하는 요율입니다. <strong>법정요율이라 대리점별로 다를 수 없어 전역 1벌</strong>로 관리하며,
						저장하면 대리점 전용 설정이 있는 곳도 같은 값으로 맞춥니다.
						<span class="text-muted">※ 이미 반영된 과거 정산분은 바뀌지 않습니다 — 변경 이후 반영분부터 적용됩니다.</span>
					</div>
					<div class="row g-4">
						<div class="col-md-3">
							<label class="form-label" for="cfg_rate_withholding">원천세율 (%)</label>
							<input type="number" step="0.01" min="0" max="100" class="form-control form-control-solid" id="cfg_rate_withholding" value="<?= htmlspecialchars(number_format((float) $rates['withholding_tax_pct'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canWrite ? '' : 'readonly' ?> />
							<div class="form-text fs-9">원천세 대상 라이더만 공제</div>
						</div>
						<div class="col-md-3">
							<label class="form-label" for="cfg_rate_employment">고용보험료율 (%)</label>
							<input type="number" step="0.01" min="0" max="100" class="form-control form-control-solid" id="cfg_rate_employment" value="<?= htmlspecialchars(number_format((float) $rates['employment_ins_pct'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canWrite ? '' : 'readonly' ?> />
							<div class="form-text fs-9">대리점 예수금으로 누적</div>
						</div>
						<div class="col-md-3">
							<label class="form-label" for="cfg_rate_accident">산재보험료율 (%)</label>
							<input type="number" step="0.01" min="0" max="100" class="form-control form-control-solid" id="cfg_rate_accident" value="<?= htmlspecialchars(number_format((float) $rates['industrial_accident_ins_pct'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" <?= $canWrite ? '' : 'readonly' ?> />
							<div class="form-text fs-9">대리점 예수금으로 누적</div>
						</div>
						<?php if ($canWrite) : ?>
						<div class="col-md-3 d-flex align-items-start" style="padding-top:1.9rem">
							<button type="button" class="btn btn-primary" id="cfg_rates_save_btn">요율 저장</button>
						</div>
						<?php endif; ?>
					</div>
					<div class="alert bg-light-info fs-8 p-3 mt-5 mb-0">
						고용·산재 공제분은 대리점 지갑의 <strong>예수금</strong>으로 쌓이고, 세무대리가 <a href="<?= htmlspecialchars(admin_url('tax/dashboard'), ENT_QUOTES, 'UTF-8') ?>">고용·산재 예수금</a> 화면에서 월별로 수집합니다.
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
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
		function send(payload, okMsg) {
			return fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					showToast(res.message || okMsg, true);
					return res;
				})
				.catch(function (e) { showToast(e.message || '저장 실패', false); });
		}
		/* 최저금액 저장은 2026-09-08 폐지 — 하한 장치 자체가 없어졌다. */

		/* 정산수수료 추가금 — 합계를 입력하는 동안 바로 보여주고, 저장은 전용 액션으로. */
		(function () {
			function intv(id) { var e = document.getElementById(id); return e ? (parseInt(e.value, 10) || 0) : 0; }
			function recompute() {
				['short', 'long'].forEach(function (b) {
					var fx = document.getElementById('add_fixed_' + b);
					var base = fx ? (parseInt(fx.getAttribute('data-fixed'), 10) || 0) : 0;
					var out = document.getElementById('add_total_' + b);
					if (out) { out.textContent = (base + intv('add_dist_' + b) + intv('add_agency_' + b)).toLocaleString() + '원'; }
				});
			}
			['short', 'long'].forEach(function (b) {
				['dist', 'agency'].forEach(function (p) {
					var el = document.getElementById('add_' + p + '_' + b);
					if (el) { el.addEventListener('input', recompute); }
				});
			});
			recompute();

			var btn = document.getElementById('cfg_addons_save_btn');
			if (btn) {
				btn.addEventListener('click', function () {
					send({
						action: 'save_addons',
						dist_fee_short: intv('add_dist_short'),
						dist_fee_long: intv('add_dist_long'),
						agency_add_short: intv('add_agency_short'),
						agency_add_long: intv('add_agency_long'),
					}, '추가금이 저장되었습니다.');
				});
			}
		})();
		var ratesBtn = document.getElementById('cfg_rates_save_btn');
		if (ratesBtn) {
			ratesBtn.addEventListener('click', function () {
				send({
					action: 'save_rates',
					withholding_tax_pct: parseFloat(document.getElementById('cfg_rate_withholding').value) || 0,
					employment_ins_pct: parseFloat(document.getElementById('cfg_rate_employment').value) || 0,
					industrial_accident_ins_pct: parseFloat(document.getElementById('cfg_rate_accident').value) || 0,
				}, '공제 요율이 저장되었습니다.');
			});
		}
		document.getElementById('cfg_save_btn').addEventListener('click', function () {
			/* 요율 설정은 정산수수료로 통합됐다(2026-09-07). 이 화면은 선차감만 저장한다. */
			var pdEl = document.getElementById('cfg_prededuct');
			if (!pdEl || pdEl.readOnly) { showToast('변경할 수 있는 값이 없습니다.', false); return; }
			var payload = {
				action: 'save_prededuct',
				prededuct_fee: parseInt(pdEl.value, 10) || 0,
			};
			/* 선차감은 본사만 보낸다 — 대리점이 저장할 땐 키를 아예 빼서 서버가 기존 값을
			   지키게 한다(키가 오면 0으로 덮여 선차감이 조용히 꺼진다). */
			var pd = document.getElementById('cfg_prededuct');
			if (pd && !pd.readOnly) {
				payload.prededuct_fee = parseInt(pd.value, 10) || 0;
			}
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

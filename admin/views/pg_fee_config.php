<?php

declare(strict_types=1);

/**
 * 수수료 설정 — 대리점 기준으로 두 종류를 함께 관리(본사 전용).
 *   ① 정산수수료: 출금 시 주문 건별로 붙는 단가(경과일 기준) + 보증금
 *   ② 플랫폼 수수료: PG 결제 시 본사/총판/대리점이 나눠 갖는 비율
 */

require_once INC_PATH . '/PgFeeConfig.php';

$apiUrl = ADMIN_BASE . '/api/pg_fee_config.php';
$needsMigrate = !PgFeeConfig::tableExists();
$rows = $needsMigrate ? [] : PgFeeConfig::listAgencyConfigs();
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">수수료 설정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료 설정</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="pf_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="pf_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="alert bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-percentage fs-2hx text-primary me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>대리점마다 개별로</strong> 수수료를 설정합니다.
			<span class="d-block mt-1">
				· <strong>정산수수료</strong> — 라이더 출금 시 주문 <strong>건별</strong>로 붙는 금액(정산일로부터 경과일 기준으로 단가가 갈립니다) + 출금 후 지갑에 남기는 보증금<br />
				· <strong>플랫폼 수수료</strong> — PG 결제 시 붙는 비율을 <strong>본사·총판·대리점</strong>이 나눠 갖습니다(대리점별로 각각 다르게 지정 가능)
			</span>
			<span class="badge badge-light-warning mt-2">플랫폼 수수료 기본 각 1% (임시값 — 갑 확정 대기)</span>
		</div>
	</div>

	<?php if ($rows === []) : ?>
	<div class="card card-flush"><div class="card-body text-center text-muted py-15">등록된 대리점이 없습니다.</div></div>
	<?php else : ?>

	<?php foreach ($rows as $r) : ?>
	<div class="card card-flush mb-6" data-org="<?= (int) $r['id'] ?>">
		<div class="card-header pt-5 align-items-center">
			<div class="card-title">
				<h3 class="fw-bold m-0"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?>
					<span class="text-muted fs-8 fw-normal ms-2"><?= htmlspecialchars($r['code'], ENT_QUOTES, 'UTF-8') ?> · 상위 <?= htmlspecialchars($r['parent_name'], ENT_QUOTES, 'UTF-8') ?></span>
				</h3>
			</div>
			<div class="card-toolbar">
				<span class="badge badge-light-primary">플랫폼 수수료 합계 <span class="pf-total"><?= number_format((float) $r['total_pct'], 2) ?></span>%</span>
			</div>
		</div>
		<div class="card-body pt-2">
			<div class="row g-6">
				<!-- 정산수수료 -->
				<div class="col-xl-6">
					<div class="border border-gray-200 rounded p-4 h-100">
						<div class="fw-bold text-gray-800 mb-3">정산수수료 <span class="text-muted fs-8 fw-normal">(출금 시 건별 부과)</span></div>
						<div class="row g-3">
							<div class="col-6">
								<label class="form-label fs-8 mb-1">경과일 기준</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid sf-threshold" min="1" max="365" value="<?= (int) $r['fee_day_threshold'] ?>" />
									<span class="input-group-text">일</span>
								</div>
							</div>
							<div class="col-6">
								<label class="form-label fs-8 mb-1">보증금 (지갑 유지)</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid sf-reserve" min="0" step="1000" value="<?= (int) $r['reserve_amount'] ?>" />
									<span class="input-group-text">원</span>
								</div>
							</div>
							<div class="col-6">
								<label class="form-label fs-8 mb-1">기준 이내 건당</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid sf-short" min="0" value="<?= (int) $r['fee_per_tx_short'] ?>" />
									<span class="input-group-text">원</span>
								</div>
							</div>
							<div class="col-6">
								<label class="form-label fs-8 mb-1">기준 경과 건당</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid sf-long" min="0" value="<?= (int) $r['fee_per_tx_long'] ?>" />
									<span class="input-group-text">원</span>
								</div>
							</div>
						</div>
						<div class="form-text fs-9 mt-2">정산일로부터 <strong class="sf-threshold-echo"><?= (int) $r['fee_day_threshold'] ?></strong>일 이내 주문은 위 단가, 그 이후 주문은 아래 단가로 계산합니다.</div>
						<button type="button" class="btn btn-sm btn-light-primary mt-3 sf-save">정산수수료 저장</button>
					</div>
				</div>

				<!-- 플랫폼 수수료 -->
				<div class="col-xl-6">
					<div class="border border-gray-200 rounded p-4 h-100">
						<div class="fw-bold text-gray-800 mb-3">플랫폼 수수료 <span class="text-muted fs-8 fw-normal">(PG 결제 시 분배)</span></div>
						<div class="row g-3">
							<div class="col-4">
								<label class="form-label fs-8 mb-1">본사 몫</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid pf-hq" step="0.01" min="0" max="100" value="<?= number_format((float) $r['hq_pct'], 2, '.', '') ?>" />
									<span class="input-group-text">%</span>
								</div>
							</div>
							<div class="col-4">
								<label class="form-label fs-8 mb-1">총판 몫</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid pf-dist" step="0.01" min="0" max="100" value="<?= number_format((float) $r['distributor_pct'], 2, '.', '') ?>" />
									<span class="input-group-text">%</span>
								</div>
							</div>
							<div class="col-4">
								<label class="form-label fs-8 mb-1">대리점 몫</label>
								<div class="input-group input-group-sm">
									<input type="number" class="form-control form-control-sm form-control-solid pf-agency" step="0.01" min="0" max="100" value="<?= number_format((float) $r['agency_pct'], 2, '.', '') ?>" />
									<span class="input-group-text">%</span>
								</div>
							</div>
						</div>
						<div class="form-text fs-9 mt-2">이 대리점 결제에 붙는 총 요율 = 본사 + 총판 + 대리점 = <strong class="pf-total-echo"><?= number_format((float) $r['total_pct'], 2) ?></strong>%</div>
						<button type="button" class="btn btn-sm btn-light-primary mt-3 pf-save">플랫폼 수수료 저장</button>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endforeach; ?>

	<?php endif; ?>

	<script>
	(function () {
		'use strict';
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('pf_toast'), toastMsg = document.getElementById('pf_toast_msg');
		function showToast(m, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = m;
			toast.classList.remove('d-none');
		}
		function post(payload, btn) {
			btn.disabled = true;
			fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					showToast(res.message, true);
					setTimeout(function () { location.reload(); }, 600);
				})
				.catch(function (e) { showToast(e.message, false); btn.disabled = false; });
		}
		function num(el, sel) { return parseFloat(el.querySelector(sel).value) || 0; }

		document.querySelectorAll('.card[data-org]').forEach(function (card) {
			var orgId = parseInt(card.getAttribute('data-org'), 10);

			// 합계 실시간 표시
			function refreshTotal() {
				var t = num(card, '.pf-hq') + num(card, '.pf-dist') + num(card, '.pf-agency');
				card.querySelectorAll('.pf-total, .pf-total-echo').forEach(function (e) { e.textContent = t.toFixed(2); });
			}
			['.pf-hq', '.pf-dist', '.pf-agency'].forEach(function (sel) {
				card.querySelector(sel).addEventListener('input', refreshTotal);
			});
			card.querySelector('.sf-threshold').addEventListener('input', function () {
				card.querySelector('.sf-threshold-echo').textContent = this.value;
			});

			card.querySelector('.pf-save').addEventListener('click', function () {
				post({
					action: 'save_platform', org_id: orgId,
					hq_pct: num(card, '.pf-hq'),
					distributor_pct: num(card, '.pf-dist'),
					agency_pct: num(card, '.pf-agency')
				}, this);
			});
			card.querySelector('.sf-save').addEventListener('click', function () {
				post({
					action: 'save_settlement', org_id: orgId,
					fee_day_threshold: num(card, '.sf-threshold'),
					reserve_amount: num(card, '.sf-reserve'),
					fee_per_tx_short: num(card, '.sf-short'),
					fee_per_tx_long: num(card, '.sf-long')
				}, this);
			});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

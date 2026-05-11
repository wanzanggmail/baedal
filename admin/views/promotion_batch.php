<?php

declare(strict_types=1);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 배치 실행</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">프로모션</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">배치 실행</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('promotion/rules'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">프로모션 규칙</a>
			<a href="<?= htmlspecialchars(admin_url('promotion/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">실행 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-discount fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="d-flex flex-column pe-0 pe-sm-10">
			<h5 class="mb-1">화면 목업입니다</h5>
			<span class="fs-7 text-gray-700">기간·조건 입력과 「조건 추가」는 브라우저에서만 동작합니다. 「실행」은 백엔드 연동 전까지 비활성화되어 있습니다.</span>
		</div>
	</div>

	<!-- 1. 기간 설정 -->
	<div class="card card-flush mb-8">
		<div class="card-header">
			<div class="card-title">
				<h3 class="fw-bold m-0">1. 기간 설정</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">프로모션 적용·집계 기준 기간 (예: 정산 주간)</span>
			</div>
		</div>
		<div class="card-body pt-5">
			<div class="row g-6">
				<div class="col-md-8">
					<label class="form-label required">적용 기간</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="promo_period_start" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="promo_period_end" data-kt-daterange-to value="2026-05-10" />
					</div>
				</div>
				<div class="col-md-4 d-flex align-items-end">
					<div class="text-gray-600 fs-7 pb-3">실제 서비스에서는 라이더 실적·정산 데이터와 교차 검증합니다.</div>
				</div>
			</div>
		</div>
	</div>

	<!-- 2. 조건 설정 -->
	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">2. 조건 설정</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">누적 배달 건수 구간별 지급 금액 (N건 이상 ~ M건 이하)</span>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light-primary" id="promoConditionAdd">
					<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
					조건 추가
				</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div id="promoConditionList" class="d-flex flex-column gap-5"></div>
			<div class="form-text mt-6">구간은 겹치지 않게 입력하는 것을 권장합니다. (연동 시 서버에서 검증 예정)</div>
		</div>
	</div>

	<!-- 3. 실행 -->
	<div class="card card-flush">
		<div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-4 py-8">
			<div>
				<h3 class="fw-bold text-gray-900 mb-1">3. 실행</h3>
				<p class="text-gray-600 fs-7 mb-0">위 기간·조건으로 프로모션 배치를 실행합니다. (현재는 UI만 제공)</p>
			</div>
			<div class="d-flex gap-3">
				<button type="button" class="btn btn-light" id="promoConditionReset">조건 초기화</button>
				<button type="button" class="btn btn-primary" id="promoBatchRun" disabled title="백엔드 연동 후 사용 가능">
					<i class="ki-duotone ki-rocket fs-3"><span class="path1"></span><span class="path2"></span></i>
					실행
				</button>
			</div>
		</div>
	</div>

	<template id="promoConditionRowTpl">
		<div class="promo-condition-row border border-dashed border-gray-300 rounded p-6 bg-light" data-promo-row>
			<div class="d-flex flex-wrap align-items-start justify-content-between gap-4 mb-4">
				<span class="badge badge-light text-gray-800 fs-7 fw-bold px-3 py-2" data-promo-row-label>구간 1</span>
				<button type="button" class="btn btn-sm btn-icon btn-light-danger" data-promo-row-remove title="이 조건 삭제">
					<i class="ki-duotone ki-trash fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
				</button>
			</div>
			<div class="row g-4 align-items-end">
				<div class="col-sm-4 col-lg-3">
					<label class="form-label">건수 From <span class="text-muted fs-8">(이상)</span></label>
					<div class="input-group input-group-solid">
						<input type="number" class="form-control" min="0" step="1" placeholder="예: 1" data-field="from" />
						<span class="input-group-text">건</span>
					</div>
				</div>
				<div class="col-sm-4 col-lg-3">
					<label class="form-label">건수 To <span class="text-muted fs-8">(이하)</span></label>
					<div class="input-group input-group-solid">
						<input type="number" class="form-control" min="0" step="1" placeholder="예: 50" data-field="to" />
						<span class="input-group-text">건</span>
					</div>
				</div>
				<div class="col-sm-8 col-lg-4">
					<label class="form-label">지급 금액</label>
					<div class="input-group input-group-solid">
						<input type="number" class="form-control" min="0" step="100" placeholder="예: 50000" data-field="amount" />
						<span class="input-group-text">원</span>
					</div>
				</div>
			</div>
		</div>
	</template>

	<script>
	(function () {
		var listEl = document.getElementById('promoConditionList');
		var tpl = document.getElementById('promoConditionRowTpl');
		var addBtn = document.getElementById('promoConditionAdd');
		var resetBtn = document.getElementById('promoConditionReset');
		if (!listEl || !tpl || !addBtn) return;

		function renumberRows() {
			var rows = listEl.querySelectorAll('[data-promo-row]');
			rows.forEach(function (row, i) {
				var badge = row.querySelector('[data-promo-row-label]');
				if (badge) badge.textContent = '구간 ' + (i + 1);
				var rm = row.querySelector('[data-promo-row-remove]');
				if (rm) rm.style.visibility = rows.length > 1 ? 'visible' : 'hidden';
			});
		}

		function addRow(preset) {
			var node = tpl.content.firstElementChild.cloneNode(true);
			if (preset) {
				var from = node.querySelector('[data-field="from"]');
				var to = node.querySelector('[data-field="to"]');
				var amount = node.querySelector('[data-field="amount"]');
				if (from && preset.from != null) from.value = preset.from;
				if (to && preset.to != null) to.value = preset.to;
				if (amount && preset.amount != null) amount.value = preset.amount;
			}
			node.querySelector('[data-promo-row-remove]').addEventListener('click', function () {
				if (listEl.querySelectorAll('[data-promo-row]').length <= 1) return;
				node.remove();
				renumberRows();
			});
			listEl.appendChild(node);
			renumberRows();
		}

		addBtn.addEventListener('click', function () { addRow(null); });

		resetBtn.addEventListener('click', function () {
			listEl.innerHTML = '';
			addRow({ from: 1, to: 50, amount: 30000 });
			addRow({ from: 51, to: 100, amount: 50000 });
		});

		addRow({ from: 1, to: 50, amount: 30000 });
		addRow({ from: 51, to: 100, amount: 50000 });
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

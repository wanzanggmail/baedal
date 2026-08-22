<?php

declare(strict_types=1);

/**
 * 프로모션 계산기 — 기간과 건수 구간 룰을 정하면 라이더별 프로모션 금액을 산출한다.
 *
 * 계산만 하고 저장·지급은 하지 않는다. 결과를 엑셀로 받아 기존 「프로모션 지급」에
 * 업로드하면 지급까지 이어진다(다운로드 파일이 그 화면의 템플릿과 같은 형식이다).
 */

require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/PromotionCalculator.php';

$isAgency      = admin_org_level() === Org::LEVEL_AGENCY;
$agencyOptions = $isAgency ? [] : Organization::agencyOptions();
$calcApi       = rtrim(ADMIN_BASE, '/') . '/api/promotion_calc.php';
$exportApi     = rtrim(ADMIN_BASE, '/') . '/api/promotion_calc_export.php';

// 기본 기간 = 지난달 1일 ~ 말일 (프로모션은 보통 월 단위로 정산한다)
$defFrom = date('Y-m-01', strtotime('-1 month'));
$defTo   = date('Y-m-t', strtotime('-1 month'));

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-column flex-md-row flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 계산기</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">지급·출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">프로모션 계산기</li>
			</ul>
		</div>
		<div class="d-flex flex-wrap align-items-center gap-2 mt-4 mt-md-0">
			<a href="<?= $esc(admin_url('promotion')) ?>" class="btn btn-sm btn-light fw-bold">프로모션 지급</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert bg-light-primary fs-8 p-4 mb-6">
		기간 안의 <strong>배달 건수</strong>를 구간 룰에 맞춰 <strong>누진 계산</strong>합니다 —
		각 구간에 해당하는 건수만큼만 그 구간 단가가 붙습니다.
		<span class="d-block mt-1 text-gray-700">
			예) <code>100~200건 건당 100원 · 201~300건 건당 200원</code> 이고 250건이면
			→ 101건×100원 + 50건×200원 = <strong>20,100원</strong>
		</span>
		<span class="d-block mt-1 text-muted">
			건수는 <strong>정산 반영(지갑 적립)이 끝난 배달</strong>만 셉니다. 아직 반영 안 된 최근 업로드분은 빠집니다.
			이 화면은 계산만 하며, 지급은 결과를 내려받아 「프로모션 지급」에 올리면 됩니다.
		</span>
	</div>

	<div id="pc_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="pc_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<!--begin::조건-->
	<div class="card card-flush mb-6">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">계산 조건</h3></div>
		<div class="card-body pt-2">
			<div class="row g-4 mb-6">
				<?php if (!$isAgency) : ?>
				<div class="col-md-4">
					<label class="form-label required fs-8">대리점</label>
					<select class="form-select form-select-sm" id="pc_agency">
						<option value="">선택하세요…</option>
						<?php foreach ($agencyOptions as $ao) : ?>
						<option value="<?= (int) $ao['id'] ?>"><?= $esc((string) $ao['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label required fs-8">시작일</label>
					<input type="date" class="form-control form-control-sm" id="pc_from" value="<?= $esc($defFrom) ?>" />
				</div>
				<div class="col-md-3">
					<label class="form-label required fs-8">종료일</label>
					<input type="date" class="form-control form-control-sm" id="pc_to" value="<?= $esc($defTo) ?>" />
				</div>
				<div class="col-md-2 d-flex align-items-end">
					<div class="btn-group btn-group-sm w-100">
						<button type="button" class="btn btn-light" data-pc-month="0">이번 달</button>
						<button type="button" class="btn btn-light" data-pc-month="-1">지난 달</button>
					</div>
				</div>
			</div>

			<div class="separator separator-dashed mb-5"></div>

			<div class="d-flex flex-stack mb-3">
				<h4 class="fw-bold fs-6 m-0">건수 구간 룰</h4>
				<button type="button" class="btn btn-sm btn-light-primary" id="pc_add_tier">＋ 구간 추가</button>
			</div>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle fs-7 mb-0" id="pc_tier_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-100px">시작 건수</th>
							<th class="min-w-100px">종료 건수</th>
							<th class="min-w-120px">건당 금액(원)</th>
							<th class="w-50px"></th>
						</tr>
					</thead>
					<tbody id="pc_tiers"></tbody>
				</table>
			</div>
			<div class="form-text mt-2">
				구간은 <strong>겹치지 않게</strong> 넣으세요(겹치면 같은 건수가 두 번 계산돼 저장이 거부됩니다).
				마지막 구간의 <strong>종료 건수를 넘는 건은 계산에서 빠집니다</strong> — 상한 없이 계속 주려면 종료 건수를 넉넉히(예: 99999) 넣으세요.
			</div>

			<div class="d-flex gap-2 mt-6">
				<button type="button" class="btn btn-primary" id="pc_run">계산하기</button>
				<button type="button" class="btn btn-light-success d-none" id="pc_export">엑셀 다운로드</button>
			</div>
		</div>
	</div>
	<!--end::조건-->

	<!--begin::결과-->
	<div id="pc_result_wrap" class="d-none">
		<div class="row g-5 g-xl-8 mb-6">
			<div class="col-sm-3">
				<div class="card card-flush h-100"><div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">대상 라이더</div>
					<div class="fs-2 fw-bold text-gray-800"><span id="pc_sum_paid">0</span><span class="fs-6 text-muted">/<span id="pc_sum_riders">0</span>명</span></div>
					<div class="fs-9 text-muted mt-1">구간 도달 / 전체</div>
				</div></div>
			</div>
			<div class="col-sm-3">
				<div class="card card-flush h-100"><div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">총 배달 건수</div>
					<div class="fs-2 fw-bold text-gray-800" id="pc_sum_orders">0건</div>
				</div></div>
			</div>
			<div class="col-sm-6">
				<div class="card card-flush h-100"><div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">프로모션 지급 예정 합계</div>
					<div class="fs-2 fw-bold text-primary" id="pc_sum_amount">0원</div>
					<div class="fs-9 text-muted mt-1" id="pc_rule_label"></div>
				</div></div>
			</div>
		</div>

		<div class="card card-flush">
			<div class="card-header pt-5">
				<h3 class="card-title fw-bold">라이더별 계산 결과</h3>
				<div class="card-toolbar">
					<label class="form-check form-check-sm form-check-custom form-check-solid">
						<input class="form-check-input" type="checkbox" id="pc_hide_zero" checked />
						<span class="form-check-label fs-8 text-muted">0원 라이더 숨기기</span>
					</label>
				</div>
			</div>
			<div class="card-body pt-0">
				<div class="table-responsive">
					<table class="table table-row-dashed align-middle fs-7 gy-2" id="pc_table">
						<thead>
							<tr class="fw-bold text-muted">
								<th>라이더</th>
								<th class="text-end">정산일수</th>
								<th class="text-end">배달건수</th>
								<th class="text-end">정산액</th>
								<th>계산 내역</th>
								<th class="text-end">프로모션</th>
							</tr>
						</thead>
						<tbody id="pc_tbody"></tbody>
					</table>
				</div>
				<p class="text-muted fs-8 mb-0 mt-3 d-none" id="pc_empty">해당 기간에 정산 반영된 라이더가 없습니다.</p>
			</div>
		</div>
	</div>
	<!--end::결과-->

	<script>
	(function () {
		var CALC_API   = <?= json_encode($calcApi, JSON_UNESCAPED_UNICODE) ?>;
		var EXPORT_API = <?= json_encode($exportApi, JSON_UNESCAPED_UNICODE) ?>;
		var IS_AGENCY  = <?= $isAgency ? 'true' : 'false' ?>;

		var lastRows = [];

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function won(n) { return Number(n || 0).toLocaleString('ko-KR') + '원'; }
		function num(n) { return Number(n || 0).toLocaleString('ko-KR'); }
		function toast(msg, ok) {
			var el = document.getElementById('pc_toast');
			document.getElementById('pc_toast_msg').textContent = msg;
			el.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			el.classList.remove('d-none');
			if (!ok) window.scrollTo({ top: 0, behavior: 'smooth' });
		}

		// ── 구간 입력 행 ────────────────────────────────────────
		var tbody = document.getElementById('pc_tiers');
		function addTier(from, to, amount) {
			var tr = document.createElement('tr');
			tr.innerHTML =
				'<td><input type="number" class="form-control form-control-sm pc-from" min="1" step="1" value="' + (from != null ? from : '') + '" /></td>' +
				'<td><input type="number" class="form-control form-control-sm pc-to" min="1" step="1" value="' + (to != null ? to : '') + '" /></td>' +
				'<td><input type="number" class="form-control form-control-sm pc-amt" min="0" step="10" value="' + (amount != null ? amount : '') + '" /></td>' +
				'<td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-light-danger pc-del" title="삭제">×</button></td>';
			tbody.appendChild(tr);
		}
		document.getElementById('pc_add_tier').addEventListener('click', function () {
			// 새 구간은 마지막 구간 다음 건수부터 시작하도록 미리 채워준다(겹침 실수 방지).
			var lastTo = 0;
			tbody.querySelectorAll('.pc-to').forEach(function (i) { lastTo = Math.max(lastTo, parseInt(i.value, 10) || 0); });
			addTier(lastTo > 0 ? lastTo + 1 : '', '', '');
		});
		tbody.addEventListener('click', function (ev) {
			var b = ev.target.closest('.pc-del');
			if (!b) return;
			if (tbody.rows.length <= 1) { toast('구간은 최소 1개 필요합니다.', false); return; }
			b.closest('tr').remove();
		});

		// 사용자가 예로 든 구간을 기본값으로 깔아둔다(그대로 쓰거나 고쳐 쓰면 된다).
		addTier(100, 200, 100);
		addTier(201, 300, 200);
		addTier(301, 400, 300);

		function readTiers() {
			return Array.from(tbody.rows).map(function (tr) {
				return {
					from: parseInt(tr.querySelector('.pc-from').value, 10) || 0,
					to: parseInt(tr.querySelector('.pc-to').value, 10) || 0,
					amount: parseInt(tr.querySelector('.pc-amt').value, 10) || 0,
				};
			});
		}

		// ── 기간 바로가기 ───────────────────────────────────────
		document.querySelectorAll('[data-pc-month]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var off = parseInt(btn.getAttribute('data-pc-month'), 10) || 0;
				var d = new Date();
				d.setDate(1);
				d.setMonth(d.getMonth() + off);
				var y = d.getFullYear(), m = d.getMonth();
				var pad = function (v) { return String(v).padStart(2, '0'); };
				document.getElementById('pc_from').value = y + '-' + pad(m + 1) + '-01';
				document.getElementById('pc_to').value = y + '-' + pad(m + 1) + '-' + pad(new Date(y, m + 1, 0).getDate());
			});
		});

		// ── 계산 ────────────────────────────────────────────────
		function currentParams() {
			var p = {
				from: document.getElementById('pc_from').value,
				to: document.getElementById('pc_to').value,
				tiers: readTiers(),
			};
			if (!IS_AGENCY) p.agency_id = parseInt(document.getElementById('pc_agency').value, 10) || 0;
			return p;
		}

		function render(res) {
			lastRows = res.rows || [];
			document.getElementById('pc_sum_paid').textContent = num(res.summary.paid_riders);
			document.getElementById('pc_sum_riders').textContent = num(res.summary.riders);
			document.getElementById('pc_sum_orders').textContent = num(res.summary.orders) + '건';
			document.getElementById('pc_sum_amount').textContent = won(res.summary.amount);
			document.getElementById('pc_rule_label').textContent = res.rule + ' · ' + res.from + ' ~ ' + res.to;
			drawRows();
			document.getElementById('pc_result_wrap').classList.remove('d-none');
			document.getElementById('pc_export').classList.remove('d-none');
		}

		function drawRows() {
			var hideZero = document.getElementById('pc_hide_zero').checked;
			var rows = hideZero ? lastRows.filter(function (r) { return r.amount > 0; }) : lastRows;
			document.getElementById('pc_empty').classList.toggle('d-none', rows.length > 0);
			document.getElementById('pc_tbody').innerHTML = rows.map(function (r) {
				var detail = (r.breakdown || []).map(function (b) {
					return '<span class="text-muted">' + num(b.from) + '~' + num(b.to) + '건</span> '
						+ num(b.orders) + '건×' + num(b.amount) + '원';
				}).join('<span class="text-muted"> + </span>');
				if (!detail) detail = '<span class="text-muted">구간 미달</span>';
				return '<tr>'
					+ '<td><span class="fw-semibold text-gray-800">' + esc(r.name) + '</span></td>'
					+ '<td class="text-end text-muted">' + num(r.days) + '일</td>'
					+ '<td class="text-end fw-semibold">' + num(r.order_count) + '건</td>'
					+ '<td class="text-end text-muted">' + won(r.net_amount) + '</td>'
					+ '<td class="fs-8">' + detail + '</td>'
					+ '<td class="text-end fw-bold ' + (r.amount > 0 ? 'text-primary' : 'text-muted') + '">' + won(r.amount) + '</td>'
					+ '</tr>';
			}).join('');
		}
		document.getElementById('pc_hide_zero').addEventListener('change', drawRows);

		document.getElementById('pc_run').addEventListener('click', function () {
			var btn = this;
			btn.disabled = true;
			fetch(CALC_API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(currentParams()),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '계산 실패');
					render(res);
					toast(res.summary.paid_riders + '명에게 ' + won(res.summary.amount) + ' 계산되었습니다.', true);
				})
				.catch(function (e) { toast(e.message, false); })
				.finally(function () { btn.disabled = false; });
		});

		document.getElementById('pc_export').addEventListener('click', function () {
			var p = currentParams();
			var qs = new URLSearchParams({ from: p.from, to: p.to, tiers: JSON.stringify(p.tiers) });
			if (p.agency_id) qs.set('agency_id', String(p.agency_id));
			window.location.href = EXPORT_API + '?' + qs.toString();
		});
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

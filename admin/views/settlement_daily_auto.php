<?php

declare(strict_types=1);

require_once INC_PATH . '/DailySettlement.php';

$defaultDate = date('Y-m-d');
try {
    $dates = DailySettlement::availableDates('baemin');
    if ($dates !== []) {
        $defaultDate = $dates[0];
    }
} catch (Throwable) {
    // migrate 전
}

$sdaApi = ADMIN_BASE . '/api/settlement_daily_auto.php';
$def = DailySettlement::defaultParams();

?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">자동 일일정산</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">자동 일일정산</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">엑셀 업로드</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">업로드 이력</a>
			<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">출금 신청 목록</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>일일정산 대상만</strong> 처리합니다. 라이더 마스터의 「일일정산」이 꺼져 있으면 <strong>주간 정산</strong>으로 분류되어 이 화면에서 제외됩니다.<br />
			당일 <strong>일간 정산서(배민)</strong>의 실지급 합계에서 <strong>세금·환불 대비 보류</strong>와 <strong>보증금(최소 보유)</strong>·기타 공제를 남긴 뒤, 나머지만 <strong>출금 대기</strong>로 생성합니다.
		</div>
	</div>

	<div class="row g-5 g-xl-10 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title fw-bold text-gray-900">집계 기준일</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">일간 정산서의 정산 귀속일과 동일하게 선택 (연동 시 업로드 배치와 조인)</span>
				</div>
				<div class="card-body pt-5">
					<div class="mb-6">
						<label class="form-label fw-semibold required">정산 귀속일</label>
						<input type="text" class="form-control form-control-solid" id="sda_settlement_date" value="<?= htmlspecialchars($defaultDate, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" data-kt-flatpickr />
					</div>
					<div class="form-check form-switch form-check-custom form-check-solid mb-4">
						<input class="form-check-input" type="checkbox" value="1" id="sda_skip_dup" checked />
						<label class="form-check-label fw-semibold text-gray-800" for="sda_skip_dup">같은 귀속일·같은 라이더 자동출금이 이미 있으면 건너뛰기</label>
					</div>
					<div class="form-check form-switch form-check-custom form-check-solid">
						<input class="form-check-input" type="checkbox" value="1" id="sda_skip_manual_pending" />
						<label class="form-check-label fw-semibold text-gray-800" for="sda_skip_manual_pending">당일 수동 출금 신청(대기)이 있는 라이더는 자동 생성 제외</label>
					</div>
					<div class="form-text mt-2">「일일정산」등록·출금 보류·계좌 정보는 라이더 마스터에서 관리합니다.</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title fw-bold text-gray-900">보류·보증금 (출금 전 차감)</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">세금·환불·보증금은 당일 출금하지 않고 보류합니다. 남은 금액만 이체 후보입니다.</span>
				</div>
				<div class="card-body pt-5">
					<div class="row g-4">
						<div class="col-md-6">
							<label class="form-label fw-semibold">원천·세금 보류율 (%)</label>
							<input type="number" class="form-control form-control-solid" id="sda_tax_pct" value="<?= htmlspecialchars((string) $def['tax_pct'], ENT_QUOTES, 'UTF-8') ?>" min="0" max="100" step="0.1" />
							<div class="form-text">정산총액 × 비율 (내림)</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fw-semibold">환불·클레임 대비율 (%)</label>
							<input type="number" class="form-control form-control-solid" id="sda_refund_pct" value="<?= htmlspecialchars((string) $def['refund_pct'], ENT_QUOTES, 'UTF-8') ?>" min="0" max="100" step="0.1" />
							<div class="form-text">정산총액 × 비율 (내림)</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fw-semibold">환불 대비 고정 (원)</label>
							<input type="number" class="form-control form-control-solid" id="sda_refund_fixed" value="<?= (int) $def['refund_fixed'] ?>" min="0" step="1000" />
						</div>
						<div class="col-md-6">
							<label class="form-label fw-semibold">보증금 — 최소 보유 (원)</label>
							<input type="number" class="form-control form-control-solid" id="sda_min_retain" value="<?= (int) $def['min_retain'] ?>" min="0" step="1000" />
							<div class="form-text">정해진 금액만큼 지갑에 남김(당일 출금 제외)</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fw-semibold">출금액 절사 단위 (원)</label>
							<select class="form-select form-select-solid" id="sda_round_unit">
								<option value="1">절사 없음</option>
								<option value="10">10원</option>
								<option value="100">100원</option>
								<option value="1000" selected>1,000원</option>
								<option value="10000">10,000원</option>
							</select>
							<div class="form-text">절사분은 미출금(다음 정산 이월)으로 적립 처리 예정</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">당일 일간 정산서 집계</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1" id="sda_source_sub">귀속일·배민 일간 업로드 기준 라이더별 실지급 합계</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gs-0 gy-3">
					<thead>
						<tr class="fw-bold text-muted fs-7 text-uppercase">
							<th>라이더</th>
							<th>일일정산 대상</th>
							<th class="text-end">당일 정산 합계</th>
							<th class="text-end">기타 공제(연동)</th>
							<th>비고</th>
						</tr>
					</thead>
					<tbody id="sda_source_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-header align-items-center py-5 flex-wrap gap-3">
			<div class="card-title flex-grow-1">
				<h3 class="fw-bold m-0">출금 예정 미리보기</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">보류 항목별 차감 후 실제 이체 후보 금액</span>
			</div>
			<div class="card-toolbar gap-2">
				<button type="button" class="btn btn-sm btn-light-primary" id="sda_btn_preview">미리보기 계산</button>
				<button type="button" class="btn btn-sm btn-success" id="sda_btn_commit">
					<i class="ki-duotone ki-wallet fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					출금 대기열에 반영
				</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gs-0 gy-3 d-none" id="sda_preview_table">
					<thead>
						<tr class="fw-bold text-muted fs-7 text-uppercase">
							<th>라이더</th>
							<th class="text-end">정산총액</th>
							<th class="text-end">세금보류</th>
							<th class="text-end">환불대비</th>
							<th class="text-end">기타공제</th>
							<th class="text-end">보증금</th>
							<th class="text-end">절사</th>
							<th class="text-end">출금후보</th>
						</tr>
					</thead>
					<tbody id="sda_preview_tbody"></tbody>
				</table>
			</div>
			<div class="alert alert-light border border-dashed mb-0" id="sda_preview_empty">미리보기를 눌러 계산합니다.</div>
			<div class="alert alert-warning d-none mt-5 mb-0" id="sda_toast_warn" role="alert"></div>
			<div class="alert alert-success d-none mt-5 mb-0" id="sda_toast_ok" role="alert"></div>
			<div class="alert alert-danger d-none mt-5 mb-0" id="sda_toast_err" role="alert"></div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($sdaApi, JSON_UNESCAPED_UNICODE) ?>;
		var lastPreview = null;

		function escapeHtml(s) {
			return String(s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		function hideToasts() {
			['sda_toast_ok', 'sda_toast_warn', 'sda_toast_err'].forEach(function (id) {
				document.getElementById(id).classList.add('d-none');
			});
		}

		function readParams() {
			return {
				settlement_date: (document.getElementById('sda_settlement_date').value || '').trim(),
				tax_pct: parseFloat(document.getElementById('sda_tax_pct').value) || 0,
				refund_pct: parseFloat(document.getElementById('sda_refund_pct').value) || 0,
				refund_fixed: parseInt(document.getElementById('sda_refund_fixed').value, 10) || 0,
				min_retain: parseInt(document.getElementById('sda_min_retain').value, 10) || 0,
				round_unit: parseInt(document.getElementById('sda_round_unit').value, 10) || 1,
				skip_dup: document.getElementById('sda_skip_dup').checked,
				skip_manual: document.getElementById('sda_skip_manual_pending').checked,
				platform: 'baemin'
			};
		}

		function apiGet(qs) {
			return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(function (r) {
				return r.json().then(function (j) {
					if (!r.ok || !j.ok) throw new Error(j.message || '요청 실패');
					return j;
				});
			});
		}

		function apiPost(action, payload) {
			return fetch(API + '?action=' + encodeURIComponent(action), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (r) {
				return r.json().then(function (j) {
					if (!r.ok || !j.ok) throw new Error(j.message || '요청 실패');
					return j;
				});
			});
		}

		function renderSource(sources) {
			var tb = document.getElementById('sda_source_tbody');
			tb.innerHTML = '';
			if (!sources.length) {
				tb.innerHTML = '<tr><td colspan="5" class="text-muted py-6 text-center">해당 귀속일 정산 데이터가 없습니다. 먼저 엑셀을 업로드하세요.</td></tr>';
				return;
			}
			sources.forEach(function (r) {
				var daily = !!r.is_daily_settlement;
				var note = daily ? '' : '주간 정산 — 자동 일일정산 제외';
				if (r.withdrawal_hold) note = (note ? note + ' · ' : '') + '출금 보류';
				var tr = document.createElement('tr');
				if (!daily) tr.classList.add('opacity-50');
				tr.innerHTML =
					'<td><span class="text-gray-900 fw-bold">' + escapeHtml(r.rider_name) + '</span>' +
					'<span class="text-muted fs-7 d-block">' + escapeHtml(r.rider_code || '') + '</span></td>' +
					'<td>' + (daily ? '<span class="badge badge-light-success">예</span>' : '<span class="badge badge-light">아니오</span>') + '</td>' +
					'<td class="text-end fw-bold">' + Number(r.gross_amount || 0).toLocaleString() + '원</td>' +
					'<td class="text-end">' + Number(r.other_withhold || 0).toLocaleString() + '원</td>' +
					'<td class="text-gray-600 fs-7">' + escapeHtml(note) + '</td>';
				tb.appendChild(tr);
			});
		}

		function renderPreview(data) {
			var table = document.getElementById('sda_preview_table');
			var empty = document.getElementById('sda_preview_empty');
			var tb = document.getElementById('sda_preview_tbody');
			tb.innerHTML = '';
			var rows = (data && data.rows) ? data.rows : [];
			if (!rows.length) {
				table.classList.add('d-none');
				empty.classList.remove('d-none');
				var skipped = (data && data.skipped) ? data.skipped.length : 0;
				empty.textContent = '출금 후보가 없습니다. (일일정산 미등록·보류·중복·출금액 0원 등, 제외 ' + skipped + '명)';
				return;
			}
			empty.classList.add('d-none');
			table.classList.remove('d-none');
			rows.forEach(function (x) {
				var L = x.line;
				var tr = document.createElement('tr');
				tr.innerHTML =
					'<td><span class="text-gray-900 fw-bold">' + escapeHtml(x.rider_name) + '</span>' +
					'<span class="text-muted fs-7 d-block">' + escapeHtml(x.rider_code || '') + '</span></td>' +
					'<td class="text-end">' + L.gross.toLocaleString() + '</td>' +
					'<td class="text-end">' + L.tax.toLocaleString() + '</td>' +
					'<td class="text-end">' + L.refund.toLocaleString() + '</td>' +
					'<td class="text-end">' + L.other.toLocaleString() + '</td>' +
					'<td class="text-end">' + L.deposit.toLocaleString() + '</td>' +
					'<td class="text-end text-muted">' + L.round_trim.toLocaleString() + '</td>' +
					'<td class="text-end fw-bold text-primary">' + L.net.toLocaleString() + '원</td>';
				tb.appendChild(tr);
			});
			if (data.summary) {
				document.getElementById('sda_source_sub').textContent =
					'대상 ' + data.summary.preview_count + '명 · 정산합 ' + data.summary.total_gross.toLocaleString() +
					'원 → 출금합 ' + data.summary.total_withdraw.toLocaleString() + '원';
			}
		}

		function loadSource() {
			var p = readParams();
			if (!p.settlement_date) return Promise.resolve();
			return apiGet('action=source&settlement_date=' + encodeURIComponent(p.settlement_date))
				.then(function (j) { renderSource(j.sources || []); });
		}

		function runPreview() {
			var p = readParams();
			if (!p.settlement_date) {
				window.alert('정산 귀속일을 선택하세요.');
				return Promise.reject();
			}
			hideToasts();
			return apiPost('preview', { settlement_date: p.settlement_date, params: p })
				.then(function (j) {
					lastPreview = j;
					renderPreview(j);
					if (j.skipped && j.skipped.length) {
						var w = document.getElementById('sda_toast_warn');
						w.classList.remove('d-none');
						w.textContent = '제외 ' + j.skipped.length + '명 (주간정산·보류·중복 등). 상세는 서버 로그/개발자도구 응답을 참고하세요.';
					}
				});
		}

		document.getElementById('sda_btn_preview').addEventListener('click', function () {
			runPreview().catch(function (e) {
				document.getElementById('sda_toast_err').classList.remove('d-none');
				document.getElementById('sda_toast_err').textContent = e.message || String(e);
			});
		});

		document.getElementById('sda_btn_commit').addEventListener('click', function () {
			var p = readParams();
			if (!p.settlement_date) {
				window.alert('정산 귀속일을 선택하세요.');
				return;
			}
			var doCommit = function () {
				apiPost('commit', { settlement_date: p.settlement_date, params: p })
					.then(function (j) {
						hideToasts();
						document.getElementById('sda_toast_ok').classList.remove('d-none');
						document.getElementById('sda_toast_ok').textContent =
							(j.created || 0) + '건을 출금 대기(DB)에 반영했습니다. 「출금 신청 목록」에서 확인하세요.';
						return runPreview();
					})
					.catch(function (e) {
						document.getElementById('sda_toast_err').classList.remove('d-none');
						document.getElementById('sda_toast_err').textContent = e.message || String(e);
					});
			};
			if (!lastPreview || !lastPreview.rows || !lastPreview.rows.length) {
				runPreview().then(function () {
					if (!window.confirm('미리보기 결과를 출금 대기열에 반영할까요?')) return;
					doCommit();
				});
				return;
			}
			if (!window.confirm('출금 대기열(DB)에 반영할까요?')) return;
			doCommit();
		});

		document.getElementById('sda_settlement_date').addEventListener('change', function () {
			loadSource().catch(function () {});
		});

		loadSource().then(runPreview).catch(function (e) {
			document.getElementById('sda_toast_err').classList.remove('d-none');
			document.getElementById('sda_toast_err').textContent = e.message || String(e);
		});
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

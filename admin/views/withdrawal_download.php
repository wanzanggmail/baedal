<?php

declare(strict_types=1);

$mockPending = [
    ['id' => 'wd-20260510-008', 'rider_id' => 'R-104418', 'rider_name' => '권성진', 'bank' => '국민은행', 'account' => '123456-01-789012', 'holder' => '권성진', 'amount' => 1850000],
    ['id' => 'wd-20260510-007', 'rider_id' => 'R-203274', 'rider_name' => '민세훈', 'bank' => '신한은행', 'account' => '110-345-678901', 'holder' => '민세훈', 'amount' => 920000],
    ['id' => 'wd-20260510-006', 'rider_id' => 'R-880647', 'rider_name' => '노동현', 'bank' => '우리은행', 'account' => '1002-123-456789', 'holder' => '노동현', 'amount' => 3400000],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 — 은행 이체 파일</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">다운로드</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">신청 목록</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-warning d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-bank fs-2hx text-warning me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 실제 은행별 고정길이·전용 포맷은 연동 시 적용하고, 여기서는 <strong>CSV(UTF-8 BOM)</strong> 샘플을 내려받습니다. 다운로드 후 선택한 건은 신청 목록에서 <strong>다운로드 완료</strong>로 표시됩니다.
		</div>
	</div>

	<div class="row g-6">
		<div class="col-xl-5">
			<div class="card card-flush h-xl-100">
				<div class="card-header">
					<div class="card-title">
						<h3 class="fw-bold m-0">파일 생성 옵션</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">출금일·이체자명은 은행 업로드 시 검증용</span>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="mb-6">
						<label class="form-label fw-semibold">은행/시스템 포맷 (목업)</label>
						<select class="form-select form-select-solid" id="wd_bank_format">
							<option value="csv_common" selected>공통 CSV (수취인·계좌·금액)</option>
							<option value="csv_kb">KB국민 기업뱅킹 유사 (콤마 구분)</option>
							<option value="fixed_mock">고정길이 텍스트 (데모 128바이트 패딩)</option>
						</select>
						<div class="form-text">실서비스에서는 은행·증권사 스펙에 맞춘 변환기를 붙입니다.</div>
					</div>
					<div class="mb-6">
						<label class="form-label fw-semibold">출금 기준일</label>
						<input type="text" class="form-control form-control-solid" id="wd_payout_date" value="2026-05-10" data-kt-flatpickr autocomplete="off" />
					</div>
					<div class="mb-6">
						<label class="form-label fw-semibold">입금자명(통장 표시)</label>
						<input type="text" class="form-control form-control-solid" id="wd_sender_name" value="도깨비배달 정산" maxlength="40" />
					</div>
					<div class="mb-8">
						<label class="form-label fw-semibold">비고 접두어 (파일 헤더)</label>
						<input type="text" class="form-control form-control-solid" id="wd_batch_note" value="WITHDRAW-20260510" maxlength="32" />
					</div>
					<button type="button" class="btn btn-primary w-100" id="wd_btn_download">
						<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
						선택 건 은행 파일 다운로드
					</button>
					<div class="alert alert-primary d-flex align-items-center p-4 mt-6 mb-0 d-none" id="wd_toast" role="alert">
						<span class="fs-7" id="wd_toast_msg"></span>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-7">
			<div class="card card-flush h-xl-100">
				<div class="card-header align-items-center py-5 gap-2">
					<div class="card-title">
						<h3 class="fw-bold m-0">다운로드 대상 (대기 건)</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">체크한 건만 파일에 포함 · 다운로드 시 다운로드 완료 처리</span>
					</div>
					<div class="card-toolbar">
						<button type="button" class="btn btn-sm btn-light-primary" id="wd_check_all">전체 선택</button>
						<button type="button" class="btn btn-sm btn-light" id="wd_check_none">전체 해제</button>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-3" id="wd_pick_table">
							<thead>
								<tr class="fw-bold text-muted fs-7 text-uppercase">
									<th class="w-25px"></th>
									<th>신청 ID / 라이더</th>
									<th>입금 계좌</th>
									<th class="text-end">금액</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($mockPending as $p) : ?>
								<tr data-pick-id="<?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?>">
									<td>
										<div class="form-check form-check-custom form-check-solid">
											<input class="form-check-input wd-pick" type="checkbox" value="<?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?>" checked />
										</div>
									</td>
									<td>
										<span class="text-gray-900 fw-bold"><?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-gray-700 d-block"><?= htmlspecialchars($p['rider_name'], ENT_QUOTES, 'UTF-8') ?> <span class="text-muted">(<?= htmlspecialchars($p['rider_id'], ENT_QUOTES, 'UTF-8') ?>)</span></span>
									</td>
									<td>
										<span class="text-gray-800"><?= htmlspecialchars($p['bank'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-muted fs-7 d-block font-monospace"><?= htmlspecialchars($p['account'], ENT_QUOTES, 'UTF-8') ?></span>
									</td>
									<td class="text-end fw-bold"><?= number_format((int) $p['amount']) ?>원</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="text-gray-600 fs-7 mb-0">이미 「다운로드 완료」로 바뀐 건은 목업에서 아래 목록에서 숨깁니다. 초기화는 브라우저 개발자 도구에서 <code class="fs-8">localStorage.removeItem('baedal_withdrawal_mock')</code> 로 가능합니다.</p>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var STORAGE_KEY = 'baedal_withdrawal_mock';
		var rows = <?= json_encode($mockPending, JSON_UNESCAPED_UNICODE) ?>;

		function loadOverrides() {
			try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {}; } catch (e) { return {}; }
		}

		function saveOverrides(o) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(o));
		}

		function refreshPickRows() {
			var o = loadOverrides();
			document.querySelectorAll('#wd_pick_table tbody tr[data-pick-id]').forEach(function (tr) {
				var id = tr.getAttribute('data-pick-id');
				if (o[id] === 'downloaded' || o[id] === 'completed') {
					tr.style.display = 'none';
					var cb = tr.querySelector('.wd-pick');
					if (cb) cb.checked = false;
				} else {
					tr.style.display = '';
				}
			});
		}

		function bankCodeLabel(name) {
			var m = { '국민은행': '004', '신한은행': '088', '우리은행': '020', '농협': '011', '카카오뱅크': '090', '하나은행': '081', 'IBK기업은행': '003', '토스뱅크': '092' };
			return m[name] || '000';
		}

		function selectedRows() {
			var ids = [];
			document.querySelectorAll('.wd-pick:checked').forEach(function (c) { ids.push(c.value); });
			return rows.filter(function (r) { return ids.indexOf(r.id) !== -1; });
		}

		function buildCsvCommon(list, payoutDate, sender) {
			var lines = ['수취인명,계좌번호,은행명,은행코드(목업),금액,입금자명,출금기준일,비고'];
			list.forEach(function (r) {
				var line = [r.holder, r.account.replace(/,/g, ''), r.bank, bankCodeLabel(r.bank), String(r.amount), sender, payoutDate, r.id].map(function (cell) {
					var s = String(cell);
					if (/[",\n]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
					return s;
				}).join(',');
				lines.push(line);
			});
			return lines.join('\r\n');
		}

		function buildCsvKb(list, payoutDate, sender) {
			var lines = ['입금계좌번호,입금금액,입금통장표시,예금주명,적요(목업)'];
			list.forEach(function (r) {
				var acct = r.account.replace(/-/g, '');
				lines.push([acct, String(r.amount), sender, r.holder, payoutDate + ' ' + r.id].join(','));
			});
			return lines.join('\r\n');
		}

		function buildFixedMock(list, batchNote) {
			var out = [];
			out.push('HDR' + batchNote.substring(0, 20).padEnd(20, ' ') + String(list.length).padStart(6, '0'));
			list.forEach(function (r, i) {
				var rec = 'DTL' + String(i + 1).padStart(6, '0');
				rec += r.holder.substring(0, 10).padEnd(10, ' ');
				rec += r.account.replace(/-/g, '').substring(0, 14).padEnd(14, ' ');
				rec += String(r.amount).padStart(12, '0');
				rec = rec.padEnd(128, ' ');
				out.push(rec);
			});
			return out.join('\r\n');
		}

		function downloadBlob(filename, mime, text) {
			var bom = '\uFEFF';
			var blob = new Blob([bom + text], { type: mime });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = filename;
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
		}

		document.getElementById('wd_check_all').addEventListener('click', function () {
			document.querySelectorAll('#wd_pick_table tbody tr:not([style*="display: none"]) .wd-pick').forEach(function (c) { c.checked = true; });
		});
		document.getElementById('wd_check_none').addEventListener('click', function () {
			document.querySelectorAll('.wd-pick').forEach(function (c) { c.checked = false; });
		});

		document.getElementById('wd_btn_download').addEventListener('click', function () {
			var list = selectedRows();
			var toast = document.getElementById('wd_toast');
			var toastMsg = document.getElementById('wd_toast_msg');
			if (!list.length) {
				toastMsg.textContent = '다운로드할 건을 선택하세요. (이미 다운로드 완료 처리된 건은 목록에서 숨겨집니다.)';
				toast.classList.remove('d-none');
				return;
			}
			var fmt = document.getElementById('wd_bank_format').value;
			var payoutDate = document.getElementById('wd_payout_date').value || '2026-05-10';
			var sender = document.getElementById('wd_sender_name').value || '정산';
			var batchNote = document.getElementById('wd_batch_note').value || 'BATCH';
			var body, fname, mime;
			var ts = new Date();
			var tsStr = ts.getFullYear() + String(ts.getMonth() + 1).padStart(2, '0') + String(ts.getDate()).padStart(2, '0') + '_' + String(ts.getHours()).padStart(2, '0') + String(ts.getMinutes()).padStart(2, '0');
			if (fmt === 'csv_kb') {
				body = buildCsvKb(list, payoutDate, sender);
				fname = 'withdraw_kb_like_' + tsStr + '.csv';
				mime = 'text/csv;charset=utf-8';
			} else if (fmt === 'fixed_mock') {
				body = buildFixedMock(list, batchNote);
				fname = 'withdraw_fixed_demo_' + tsStr + '.txt';
				mime = 'text/plain;charset=utf-8';
			} else {
				body = buildCsvCommon(list, payoutDate, sender);
				fname = 'withdraw_common_' + tsStr + '.csv';
				mime = 'text/csv;charset=utf-8';
			}
			downloadBlob(fname, mime, body);

			var o = loadOverrides();
			list.forEach(function (r) { o[r.id] = 'downloaded'; });
			saveOverrides(o);
			refreshPickRows();

			toastMsg.textContent = list.length + '건 파일을 받았습니다. 신청 목록에서 상태가 「다운로드 완료」로 갱신됩니다.';
			toast.classList.remove('d-none');
		});

		refreshPickRows();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

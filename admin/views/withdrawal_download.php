<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$pendingRows = [];
$downloadError = null;
$summary = ['pending_count' => 0, 'pending_amount' => 0];

try {
    $pendingRows = Withdrawal::list(['status' => 'pending', 'limit' => 500]);
    $summary     = Withdrawal::summary();
} catch (Throwable $e) {
    $downloadError = $e->getMessage();
}

$downloadApi = ADMIN_BASE . '/api/withdrawal_download_file.php';
$defaultPayoutDate = date('Y-m-d');
$exportRows = array_map(static function (array $p): array {
    return [
        'db_id'      => $p['db_id'],
        'id'         => $p['id'],
        'rider_id'   => $p['rider_id'],
        'rider_name' => $p['rider_name'],
        'bank'       => $p['bank'],
        'bank_code'  => $p['bank_code'],
        'account'    => $p['account'],
        'holder'     => $p['holder'],
        'amount'     => $p['amount'],
    ];
}, $pendingRows);
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
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
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

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-bank fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>신한은행 BizBank</strong> 자금이체 「파일불러오기」 양식입니다.
			열 순서: <strong>입금은행(코드)</strong> → <strong>입금계좌</strong> → <strong>고객관리성명</strong> → <strong>입금액</strong>.
			입금은행은 <strong>은행코드 3자리</strong>만 인식합니다(한글 은행명 불가).
			다운로드 후 건은 <strong>다운로드 완료</strong>로 바뀝니다.
		</div>
	</div>

	<?php if ($downloadError === null) : ?>
	<div class="row g-5 mb-8">
		<div class="col-md-6">
			<div class="card card-flush border border-primary border-dashed h-100">
				<div class="card-body py-6">
					<span class="text-gray-500 fs-7">출금 대기</span>
					<div class="fs-2hx fw-bold text-gray-900 mt-1"><?= number_format($summary['pending_count']) ?>건</div>
					<span class="text-gray-700 fw-semibold"><?= number_format($summary['pending_amount']) ?>원</span>
				</div>
			</div>
		</div>
		<div class="col-md-6">
			<div class="card card-flush h-100">
				<div class="card-body py-6 fs-7 text-gray-700">
					<strong>BizBank 업로드 경로</strong><br />
					이체 → 대량이체 → 자금이체 등록 → 파일불러오기<br />
					<span class="text-muted">엑셀(.xlsx) 권장 · 컬럼 순서가 같으면 필드지정 없이 불러올 수 있습니다.</span>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="row g-6">
		<div class="col-xl-5">
			<div class="card card-flush h-xl-100">
				<div class="card-header">
					<div class="card-title">
						<h3 class="fw-bold m-0">신한 이체 파일 생성</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">출금 기준일은 파일명·적요 참고용</span>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="mb-6">
						<label class="form-label fw-semibold required">파일 형식</label>
						<select class="form-select form-select-solid" id="wd_file_format">
							<option value="xlsx" selected>엑셀 (.xlsx) — BizBank 권장</option>
							<option value="txt">텍스트 (.txt, 탭 구분)</option>
							<option value="csv">CSV (.csv, UTF-8)</option>
						</select>
					</div>
					<div class="mb-6">
						<div class="form-check form-check-custom form-check-solid">
							<input class="form-check-input" type="checkbox" id="wd_include_header" checked />
							<label class="form-check-label fw-semibold" for="wd_include_header">1행에 컬럼명 포함 (입금은행, 입금계좌…)</label>
						</div>
						<div class="form-text">헤더 없이 데이터만 넣으려면 체크 해제하세요.</div>
					</div>
					<div class="mb-6">
						<label class="form-label fw-semibold">출금 기준일</label>
						<input type="text" class="form-control form-control-solid" id="wd_payout_date" value="<?= htmlspecialchars($defaultPayoutDate, ENT_QUOTES, 'UTF-8') ?>" data-kt-flatpickr autocomplete="off" />
					</div>
					<button type="button" class="btn btn-primary w-100" id="wd_btn_download">
						<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
						선택 건 신한 이체 파일 다운로드
					</button>
					<div class="alert alert-warning d-flex align-items-start p-4 mt-6 mb-0 d-none" id="wd_warn" role="alert">
						<span class="fs-7" id="wd_warn_msg"></span>
					</div>
					<div class="alert alert-primary d-flex align-items-center p-4 mt-4 mb-0 d-none" id="wd_toast" role="alert">
						<span class="fs-7" id="wd_toast_msg"></span>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-7">
			<div class="card card-flush h-xl-100">
				<div class="card-header align-items-center py-5 gap-2">
					<div class="card-title">
						<h3 class="fw-bold m-0">다운로드 대상 (출금 대기)</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">은행코드·계좌는 라이더 등록 정보 사용</span>
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
									<th>신청 / 라이더</th>
									<th>은행코드</th>
									<th>입금 계좌</th>
									<th class="text-end">금액</th>
								</tr>
							</thead>
							<tbody>
								<?php if ($downloadError !== null) : ?>
								<tr><td colspan="5" class="text-danger py-6 text-center"><?= htmlspecialchars($downloadError, ENT_QUOTES, 'UTF-8') ?></td></tr>
								<?php elseif ($pendingRows === []) : ?>
								<tr><td colspan="5" class="text-muted py-6 text-center">출금 대기 건이 없습니다.</td></tr>
								<?php else : ?>
								<?php foreach ($pendingRows as $p) :
								    $bc = htmlspecialchars($p['bank_code'] !== '' ? $p['bank_code'] : '—', ENT_QUOTES, 'UTF-8');
								    $bcClass = $p['bank_code'] === '' ? 'text-danger' : 'text-gray-800';
								    ?>
								<tr data-pick-db-id="<?= (int) $p['db_id'] ?>">
									<td>
										<div class="form-check form-check-custom form-check-solid">
											<input class="form-check-input wd-pick" type="checkbox" value="<?= (int) $p['db_id'] ?>" checked />
										</div>
									</td>
									<td>
										<span class="text-gray-900 fw-bold"><?= htmlspecialchars($p['id'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="badge badge-light-info fs-9 ms-1"><?= htmlspecialchars($p['kind_label'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-gray-700 d-block"><?= htmlspecialchars($p['rider_name'], ENT_QUOTES, 'UTF-8') ?>
											<span class="text-muted">(<?= htmlspecialchars($p['rider_id'], ENT_QUOTES, 'UTF-8') ?>)</span></span>
									</td>
									<td class="<?= $bcClass ?> fw-semibold font-monospace"><?= $bc ?></td>
									<td>
										<span class="text-gray-800"><?= htmlspecialchars($p['bank'], ENT_QUOTES, 'UTF-8') ?></span>
										<span class="text-muted fs-7 d-block font-monospace"><?= htmlspecialchars($p['account'], ENT_QUOTES, 'UTF-8') ?></span>
									</td>
									<td class="text-end fw-bold"><?= number_format((int) $p['amount']) ?>원</td>
								</tr>
								<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
					<p class="text-gray-600 fs-7 mb-0">
						미리보기 샘플(첫 선택 건): 입금은행 <code class="fs-8" id="wd_sample_bank">—</code>,
						계좌 <code class="fs-8" id="wd_sample_acct">—</code>,
						성명 <code class="fs-8" id="wd_sample_name">—</code>
					</p>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($downloadApi, JSON_UNESCAPED_UNICODE) ?>;
		var baseRows = <?= json_encode($exportRows, JSON_UNESCAPED_UNICODE) ?>;

		function selectedIds() {
			var ids = [];
			document.querySelectorAll('.wd-pick:checked').forEach(function (c) {
				ids.push(parseInt(c.value, 10));
			});
			return ids;
		}

		function updateSample() {
			var id = selectedIds()[0];
			var row = baseRows.find(function (r) { return r.db_id === id; });
			var elB = document.getElementById('wd_sample_bank');
			var elA = document.getElementById('wd_sample_acct');
			var elN = document.getElementById('wd_sample_name');
			if (!row || !elB) return;
			elB.textContent = row.bank_code || '(없음)';
			elA.textContent = (row.account || '').replace(/\D/g, '') || '(없음)';
			elN.textContent = (row.holder || row.rider_name || '').replace(/\s/g, '');
		}

		function parseFilename(disposition) {
			if (!disposition) return 'shinhan_bizbank.xlsx';
			var m = /filename="?([^";]+)"?/i.exec(disposition);
			return m ? m[1] : 'shinhan_bizbank.xlsx';
		}

		function decodeWarnings(res) {
			var h = res.headers.get('X-Baedal-Warnings');
			if (!h) return [];
			try {
				return JSON.parse(atob(h)) || [];
			} catch (e) {
				return [];
			}
		}

		document.getElementById('wd_check_all').addEventListener('click', function () {
			document.querySelectorAll('.wd-pick').forEach(function (c) { c.checked = true; });
			updateSample();
		});
		document.getElementById('wd_check_none').addEventListener('click', function () {
			document.querySelectorAll('.wd-pick').forEach(function (c) { c.checked = false; });
			updateSample();
		});
		document.getElementById('wd_pick_table').addEventListener('change', function (ev) {
			if (ev.target.classList.contains('wd-pick')) updateSample();
		});

		document.getElementById('wd_btn_download').addEventListener('click', function () {
			var ids = selectedIds();
			var toast = document.getElementById('wd_toast');
			var toastMsg = document.getElementById('wd_toast_msg');
			var warn = document.getElementById('wd_warn');
			var warnMsg = document.getElementById('wd_warn_msg');
			warn.classList.add('d-none');
			toast.classList.add('d-none');

			if (!ids.length) {
				toastMsg.textContent = '다운로드할 건을 선택하세요.';
				toast.classList.remove('d-none');
				return;
			}

			var btn = this;
			btn.disabled = true;

			fetch(API, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					ids: ids,
					format: document.getElementById('wd_file_format').value,
					include_header: document.getElementById('wd_include_header').checked,
					payout_date: document.getElementById('wd_payout_date').value,
					mark_downloaded: true
				})
			})
			.then(function (res) {
				var ct = res.headers.get('Content-Type') || '';
				if (!res.ok || ct.indexOf('application/json') !== -1) {
					return res.json().then(function (j) {
						throw new Error(j.message || '다운로드 실패');
					});
				}
				var warnings = decodeWarnings(res);
				return res.blob().then(function (blob) {
					return { blob: blob, filename: parseFilename(res.headers.get('Content-Disposition')), warnings: warnings };
				});
			})
			.then(function (payload) {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(payload.blob);
				a.download = payload.filename;
				document.body.appendChild(a);
				a.click();
				a.remove();
				setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);

				if (payload.warnings.length) {
					warnMsg.textContent = '일부 건 제외: ' + payload.warnings.join(' / ');
					warn.classList.remove('d-none');
				}
				toastMsg.textContent = '파일을 받았습니다. 잠시 후 목록이 갱신됩니다. 신청 목록에서 입금 완료 처리하세요.';
				toast.classList.remove('d-none');
				setTimeout(function () { window.location.reload(); }, 1500);
			})
			.catch(function (e) {
				toastMsg.textContent = e.message || String(e);
				toast.classList.remove('d-none');
			})
			.finally(function () {
				btn.disabled = false;
			});
		});

		updateSample();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

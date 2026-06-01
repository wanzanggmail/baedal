<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$filterStatus = trim((string) ($_GET['status'] ?? ''));
$filterKind   = trim((string) ($_GET['kind'] ?? ''));
$filterQ      = trim((string) ($_GET['q'] ?? ''));
$filterFrom   = trim((string) ($_GET['from'] ?? ''));
$filterTo     = trim((string) ($_GET['to'] ?? ''));

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$listError = null;
$withdrawals = [];
$summary = ['pending_count' => 0, 'pending_amount' => 0, 'downloaded_count' => 0];

try {
    $withdrawals = Withdrawal::list([
        'status' => $filterStatus,
        'kind'   => $filterKind,
        'from'   => $filterFrom,
        'to'     => $filterTo,
        'q'      => $filterQ,
    ]);
    $summary = Withdrawal::summary();
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$listUrl = admin_url('withdrawal/list');
$apiUrl  = ADMIN_BASE . '/api/withdrawals.php';
$totalAmount = array_sum(array_map(static fn (array $r): int => (int) $r['amount'], $withdrawals));
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 신청 목록</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">신청 목록</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
				은행 파일 다운로드
			</a>
			<a href="<?= htmlspecialchars(admin_url('withdrawal/complete'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">처리 완료 내역</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($listError !== null) : ?>
	<div class="alert alert-danger p-5 mb-8">
		<strong>DB 오류</strong> — <?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?>
	</div>
	<?php else : ?>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-warning border-dashed">
				<div class="card-body py-6">
					<span class="text-gray-500 fw-semibold fs-7">출금 대기</span>
					<div class="d-flex align-items-baseline gap-2 mt-2">
						<span class="fs-2hx fw-bold text-gray-900"><?= number_format($summary['pending_count']) ?></span>
						<span class="text-gray-600 fs-6">건</span>
					</div>
					<span class="text-gray-700 fs-6 fw-semibold"><?= number_format($summary['pending_amount']) ?>원</span>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100 border border-primary border-dashed">
				<div class="card-body py-6">
					<span class="text-gray-500 fw-semibold fs-7">다운로드 완료 (입금 대기)</span>
					<div class="fs-2hx fw-bold text-gray-900 mt-2"><?= number_format($summary['downloaded_count']) ?></div>
					<span class="text-gray-600 fs-7">건 — 아래에서 입금 완료 처리</span>
				</div>
			</div>
		</div>
		<div class="col-md-4">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<span class="text-gray-500 fw-semibold fs-7">현재 목록 (필터 적용)</span>
					<div class="fs-2hx fw-bold text-gray-900 mt-2"><?= number_format(count($withdrawals)) ?></div>
					<span class="text-gray-600 fs-7">건 · 합계 <?= number_format($totalAmount) ?>원</span>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end" id="wd_filter_form">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="withdrawal/list" />
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">신청일</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="from" data-kt-daterange-from value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
						<input type="hidden" name="to" data-kt-daterange-to value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">상태</label>
					<select class="form-select form-select-solid" name="status">
						<option value=""<?= $filterStatus === '' ? ' selected' : '' ?>>전체</option>
						<option value="pending"<?= $filterStatus === 'pending' ? ' selected' : '' ?>>대기</option>
						<option value="downloaded"<?= $filterStatus === 'downloaded' ? ' selected' : '' ?>>다운로드 완료</option>
						<option value="completed"<?= $filterStatus === 'completed' ? ' selected' : '' ?>>처리 완료</option>
						<option value="rejected"<?= $filterStatus === 'rejected' ? ' selected' : '' ?>>반려</option>
					</select>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">유형</label>
					<select class="form-select form-select-solid" name="kind">
						<option value=""<?= $filterKind === '' ? ' selected' : '' ?>>전체</option>
						<option value="auto_daily"<?= $filterKind === 'auto_daily' ? ' selected' : '' ?>>자동 일일정산</option>
						<option value="rider_manual"<?= $filterKind === 'rider_manual' ? ' selected' : '' ?>>라이더 신청</option>
					</select>
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="이름, 라이더코드, 계좌" />
				</div>
				<div class="col-md-2 text-md-end">
					<button type="submit" class="btn btn-light-primary w-100">조회</button>
				</div>
			</form>
		</div>
	</div>

	<div id="wd_action_alert" class="alert d-none mb-6" role="alert"></div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">신청 내역</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">대기 → 은행 파일 다운로드 → 다운로드 완료 → 입금 완료</span>
			</div>
			<div class="card-toolbar gap-2 flex-wrap justify-content-end">
				<button type="button" class="btn btn-sm btn-light-success" id="wd_bulk_complete_selected">
					<i class="ki-duotone ki-check fs-5"><span class="path1"></span><span class="path2"></span></i>
					선택 입금 완료
				</button>
				<button type="button" class="btn btn-sm btn-success" id="wd_bulk_complete_all">
					<i class="ki-duotone ki-check-circle fs-5"><span class="path1"></span><span class="path2"></span></i>
					다운로드 완료 일괄 입금
				</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="wd_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="w-40px ps-0">
								<div class="form-check form-check-sm form-check-custom form-check-solid me-1">
									<input class="form-check-input" type="checkbox" id="wd_master_pick" aria-label="다운로드 완료 행 전체 선택" />
								</div>
							</th>
							<th class="min-w-120px">신청 ID</th>
							<th class="min-w-100px">라이더</th>
							<th class="min-w-80px">유형</th>
							<th class="min-w-140px">은행 / 계좌</th>
							<th class="min-w-100px">예금주</th>
							<th class="min-w-100px text-end">금액</th>
							<th class="min-w-120px">정산일</th>
							<th class="min-w-140px">신청일시</th>
							<th class="min-w-120px">상태</th>
							<th class="min-w-125px text-end">액션</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($withdrawals === []) : ?>
						<tr>
							<td colspan="11" class="text-center text-muted py-10">조건에 맞는 출금 신청이 없습니다.</td>
						</tr>
						<?php else : ?>
						<?php foreach ($withdrawals as $row) : ?>
						<tr data-wd-db-id="<?= (int) $row['db_id'] ?>"
							data-wd-status="<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>"
							<?= $row['tip'] !== '' ? ' title="' . htmlspecialchars($row['tip'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
							<td class="ps-0">
								<div class="form-check form-check-sm form-check-custom form-check-solid me-1">
									<input class="form-check-input wd-bulk-pick <?= $row['status'] === 'downloaded' ? '' : 'd-none' ?>"
										type="checkbox"
										value="<?= (int) $row['db_id'] ?>"
										aria-label="선택"
										<?= $row['status'] !== 'downloaded' ? 'disabled' : '' ?> />
								</div>
							</td>
							<td class="text-gray-800 fw-semibold"><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-800 fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['rider_id'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td>
								<span class="badge badge-light<?= $row['kind'] === 'auto_daily' ? '-info' : '' ?> fs-8">
									<?= htmlspecialchars($row['kind_label'], ENT_QUOTES, 'UTF-8') ?>
								</span>
							</td>
							<td>
								<span class="text-gray-800"><?= htmlspecialchars($row['bank'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block font-monospace"><?= htmlspecialchars($row['account'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><?= htmlspecialchars($row['holder'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold text-gray-900"><?= number_format((int) $row['amount']) ?>원</td>
							<td class="text-gray-600 fs-7"><?= $row['settlement_date'] !== '' ? htmlspecialchars($row['settlement_date'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>">
									<?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?>
								</span>
							</td>
							<td class="text-end">
								<?php if ($row['status'] === 'downloaded') : ?>
								<button type="button" class="btn btn-sm btn-light-success" data-wd-complete-btn>입금 완료</button>
								<?php elseif ($row['status'] === 'pending') : ?>
								<a href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">다운로드</a>
								<?php else : ?>
								<span class="text-muted fs-7">—</span>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;

		function showAlert(msg, type) {
			var el = document.getElementById('wd_action_alert');
			el.className = 'alert alert-' + (type || 'success') + ' mb-6';
			el.textContent = msg;
			el.classList.remove('d-none');
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}

		function apiPost(payload) {
			return fetch(API, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			}).then(function (r) {
				return r.json().then(function (j) {
					if (!r.ok || !j.ok) throw new Error(j.message || '처리 실패');
					return j;
				});
			});
		}

		function collectDownloadedIds(onlyChecked) {
			var ids = [];
			document.querySelectorAll('#wd_table tbody tr[data-wd-db-id]').forEach(function (tr) {
				if (tr.getAttribute('data-wd-status') !== 'downloaded') return;
				var cb = tr.querySelector('.wd-bulk-pick');
				if (onlyChecked && (!cb || !cb.checked)) return;
				ids.push(parseInt(tr.getAttribute('data-wd-db-id'), 10));
			});
			return ids;
		}

		function completeIds(ids) {
			if (!ids.length) {
				window.alert('다운로드 완료 상태인 건을 선택하세요.');
				return;
			}
			if (!window.confirm(ids.length + '건을 입금 완료 처리할까요?')) return;
			apiPost({ action: 'complete_bulk', ids: ids })
				.then(function (j) {
					showAlert(j.message || '처리했습니다.', 'success');
					setTimeout(function () { window.location.reload(); }, 600);
				})
				.catch(function (e) {
					showAlert(e.message || String(e), 'danger');
				});
		}

		document.getElementById('wd_master_pick').addEventListener('change', function () {
			var on = this.checked;
			document.querySelectorAll('#wd_table .wd-bulk-pick:not(.d-none):not([disabled])').forEach(function (cb) {
				cb.checked = on;
			});
		});

		document.getElementById('wd_bulk_complete_all').addEventListener('click', function () {
			completeIds(collectDownloadedIds(false));
		});

		document.getElementById('wd_bulk_complete_selected').addEventListener('click', function () {
			completeIds(collectDownloadedIds(true));
		});

		document.getElementById('wd_table').addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-wd-complete-btn]');
			if (!btn) return;
			var tr = btn.closest('tr[data-wd-db-id]');
			if (!tr) return;
			completeIds([parseInt(tr.getAttribute('data-wd-db-id'), 10)]);
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/FirmBankingGateway.php';

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
			<a href="<?= htmlspecialchars(admin_url('withdrawal/settings'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">수수료 설정</a>
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
						<option value="transferring"<?= $filterStatus === 'transferring' ? ' selected' : '' ?>>이체 접수중</option>
						<option value="completed"<?= $filterStatus === 'completed' ? ' selected' : '' ?>>처리 완료</option>
						<option value="failed"<?= $filterStatus === 'failed' ? ' selected' : '' ?>>이체 실패</option>
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

	<?php if (FirmBankingGatewayFactory::isMock()) : ?>
	<div class="alert alert-warning d-flex align-items-center p-5 mb-6">
		<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7">
			<strong>펌뱅킹 모의 모드</strong> — 쿠콘·하이픈 실 API가 아직 연동되지 않아
			「출금 확정」을 눌러도 <strong>실제 송금은 일어나지 않습니다</strong>(시스템 상태만 완료로 바뀝니다).
			실 연동 후에는 이 문구가 사라지고 진짜 이체가 실행됩니다.
		</div>
	</div>
	<?php endif; ?>

	<div id="wd_action_alert" class="alert d-none mb-6" role="alert"></div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">신청 내역</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">
					대기 → <strong>출금 확정</strong>(펌뱅킹 이체) → <strong>이체 접수중</strong> → 처리 완료
					<span class="d-block text-gray-400">실 연동에서는 접수 직후가 아니라 <strong>은행 처리 결과가 통보된 뒤</strong>에 완료로 바뀌고 지갑이 차감됩니다.</span>
					<span class="d-block text-gray-400">자동이체가 안 될 때는 <strong>은행 파일 다운로드</strong> → 은행에서 직접 이체 → <strong>입금 완료 기록</strong>. 마지막 단계는 송금을 새로 보내지 않고, 이미 보낸 것으로 보아 지갑을 차감합니다.</span>
				</span>
			</div>
			<div class="card-toolbar gap-2 flex-wrap justify-content-end align-items-center">
				<span class="text-muted fs-8 me-1" id="wd_pick_count">선택 0건</span>
				<button type="button" class="btn btn-sm btn-primary" id="wd_bulk_transfer_selected"
					title="선택한 건을 펌뱅킹으로 즉시 이체합니다. 대기·다운로드 완료·이체 실패 상태만 처리됩니다.">
					<i class="ki-duotone ki-send fs-5"><span class="path1"></span><span class="path2"></span></i>
					출금 확정
				</button>
				<button type="button" class="btn btn-sm btn-light-success" id="wd_bulk_complete_selected"
					title="은행 사이트에서 이체를 이미 끝냈을 때 누릅니다. 송금을 새로 보내지는 않지만 라이더·대리점 지갑을 차감하고 완료로 기록합니다. 다운로드 완료 상태만 처리됩니다.">
					<i class="ki-duotone ki-check fs-5"><span class="path1"></span><span class="path2"></span></i>
					입금 완료 기록
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
									<input class="form-check-input" type="checkbox" id="wd_master_pick" aria-label="처리 가능한 행 전체 선택" />
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
								<?php
								// 이체 가능(대기·다운로드완료·이체실패) 건은 모두 선택 가능.
								// 각 일괄 버튼이 자기가 처리할 수 있는 상태만 다시 걸러낸다.
								$wdSelectable = in_array($row['status'], ['pending', 'downloaded', 'failed'], true);
								?>
								<input class="form-check-input wd-bulk-pick <?= $wdSelectable ? '' : 'd-none' ?>"
									type="checkbox"
									value="<?= (int) $row['db_id'] ?>"
									aria-label="선택"
									<?= $wdSelectable ? '' : 'disabled' ?> />
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
								<?php $wdReason = $row['status'] === 'rejected' ? $row['rejected_reason'] : $row['fail_reason']; ?>
								<?php if ($wdReason !== '') : ?>
								<div class="text-danger fs-8 mt-1" style="max-width:200px;"><?= htmlspecialchars($wdReason, ENT_QUOTES, 'UTF-8') ?></div>
								<?php endif; ?>
							</td>
							<td class="text-end text-nowrap">
								<?php if ($wdSelectable) : ?>
								<button type="button" class="btn btn-sm btn-primary" data-wd-transfer-btn>
									<?= $row['status'] === 'failed' ? '재시도' : '출금 확정' ?>
								</button>
								<?php endif; ?>
								<?php if ($row['status'] === 'downloaded') : ?>
								<button type="button" class="btn btn-sm btn-light-success" data-wd-complete-btn>입금 완료</button>
								<?php endif; ?>
								<?php if (!$wdSelectable) : ?>
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

		/** 지정한 상태들에 해당하는 행의 id 수집. onlyChecked=true면 체크된 것만. */
		function collectIds(statuses, onlyChecked) {
			var ids = [];
			document.querySelectorAll('#wd_table tbody tr[data-wd-db-id]').forEach(function (tr) {
				if (statuses.indexOf(tr.getAttribute('data-wd-status')) === -1) return;
				var cb = tr.querySelector('.wd-bulk-pick');
				if (onlyChecked && (!cb || !cb.checked)) return;
				ids.push(parseInt(tr.getAttribute('data-wd-db-id'), 10));
			});
			return ids;
		}

		var TRANSFERABLE = ['pending', 'downloaded', 'failed'];

		function collectDownloadedIds(onlyChecked) {
			return collectIds(['downloaded'], onlyChecked);
		}

		/** 「출금 확정」 — 펌뱅킹 건별 즉시 이체. 실패해도 나머지는 계속 진행된다. */
		function transferIds(ids) {
			if (!ids.length) {
				window.alert('이체할 건을 선택하세요. (대기 · 다운로드 완료 · 이체 실패 상태만 가능)');
				return;
			}
			if (!window.confirm(
				ids.length + '건을 지금 이체할까요?\n\n'
				+ '실제 송금이 실행되며 되돌릴 수 없습니다.\n'
				+ '중간에 실패한 건이 있어도 나머지는 계속 이체되고, 성공한 건만 완료 처리됩니다.'
			)) return;

			apiPost({ action: 'execute_transfer', ids: ids })
				.then(function (j) {
					showAlert(j.message || '이체했습니다.', j.failed > 0 ? 'warning' : 'success');
					setTimeout(function () { window.location.reload(); }, 1200);
				})
				.catch(function (e) {
					showAlert(e.message || String(e), 'danger');
				});
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

		/** 선택 건수 표시 — '전체' 버튼을 없앴으므로 지금 몇 건이 잡혔는지 보여야 한다. */
		function syncPickCount() {
			var picks = Array.from(document.querySelectorAll('#wd_table .wd-bulk-pick:not(.d-none):not([disabled])'));
			var on = picks.filter(function (cb) { return cb.checked; });
			var label = document.getElementById('wd_pick_count');
			if (label) { label.textContent = '선택 ' + on.length + '건'; }

			var master = document.getElementById('wd_master_pick');
			master.checked = picks.length > 0 && on.length === picks.length;
			master.indeterminate = on.length > 0 && on.length < picks.length;
			master.disabled = picks.length === 0;
		}

		document.getElementById('wd_master_pick').addEventListener('change', function () {
			var on = this.checked;
			document.querySelectorAll('#wd_table .wd-bulk-pick:not(.d-none):not([disabled])').forEach(function (cb) {
				cb.checked = on;
			});
			syncPickCount();
		});

		document.getElementById('wd_table').addEventListener('change', function (ev) {
			if (ev.target.classList.contains('wd-bulk-pick')) syncPickCount();
		});

		syncPickCount();

		document.getElementById('wd_bulk_complete_selected').addEventListener('click', function () {
			completeIds(collectDownloadedIds(true));
		});

		document.getElementById('wd_bulk_transfer_selected').addEventListener('click', function () {
			transferIds(collectIds(TRANSFERABLE, true));
		});

		document.getElementById('wd_table').addEventListener('click', function (ev) {
			var tr = ev.target.closest('tr[data-wd-db-id]');
			if (!tr) return;
			var id = parseInt(tr.getAttribute('data-wd-db-id'), 10);

			if (ev.target.closest('[data-wd-transfer-btn]')) {
				transferIds([id]);
				return;
			}
			if (ev.target.closest('[data-wd-complete-btn]')) {
				completeIds([id]);
			}
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

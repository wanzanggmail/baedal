<?php

declare(strict_types=1);

require_once INC_PATH . '/DailyPayout.php';

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$myAgency = $isAgency ? admin_org_id() : 0;
$apiUrl   = ADMIN_BASE . '/api/daily_payout.php';
$needsMigrate = !db_table_exists('rider_wallets') || !db_table_exists('agency_wallets');

$data     = $needsMigrate ? ['rows' => [], 'agency_wallets' => []] : DailyPayout::listPayable($isAgency ? $myAgency : null);
$rows     = $data['rows'];
$wallets  = $data['agency_wallets'];
$myBalance = $isAgency ? (int) ($wallets[$myAgency] ?? AgencyWallet::get($myAgency)['balance']) : 0;
// 정산수수료를 뗀 뒤 실제로 이체될 금액 기준(2026-08-12 일일정산 수수료 부과).
$totalPayable = array_sum(array_map(static fn ($r) => (int) $r['payout'], $rows));
$totalFee     = array_sum(array_map(static fn ($r) => (int) $r['fee'], $rows));
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">일일정산 지급 리스트</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">일일정산 지급</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="dp_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="dp_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6 mb-6">
		<div class="col-sm-4">
			<div class="card card-flush bg-light-primary"><div class="card-body py-4">
				<div class="fs-7 text-muted">지급 대상</div>
				<div class="fs-2 fw-bold text-gray-900"><span id="dp_count"><?= count($rows) ?></span>명</div>
			</div></div>
		</div>
		<div class="col-sm-4">
			<div class="card card-flush bg-light-warning"><div class="card-body py-4">
				<div class="fs-7 text-muted">지급 예정 총액<span class="fs-9">(수수료 차감 후)</span></div>
				<div class="fs-2 fw-bold text-gray-900"><span id="dp_total"><?= number_format($totalPayable) ?></span>원</div>
				<?php if ($totalFee > 0) : ?><div class="fs-8 text-muted">정산수수료 <?= number_format($totalFee) ?>원 차감</div><?php endif; ?>
			</div></div>
		</div>
		<?php if ($isAgency) : ?>
		<div class="col-sm-4">
			<div class="card card-flush bg-light-success"><div class="card-body py-4">
				<div class="fs-7 text-muted">대리점 잔액(지급 재원)</div>
				<div class="fs-2 fw-bold text-gray-900"><span id="dp_balance"><?= number_format($myBalance) ?></span>원</div>
			</div></div>
		</div>
		<?php endif; ?>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">지급 대상 (선정산 라이더)</h3>
			<?php if ($isAgency) : ?>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-primary" id="dp_pay_all">선택 일괄 지급</button>
			</div>
			<?php endif; ?>
		</div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<?php if ($isAgency) : ?><th class="w-40px"><input type="checkbox" class="form-check-input" id="dp_check_all" /></th><?php endif; ?>
							<?php if (!$isAgency) : ?><th>대리점</th><?php endif; ?>
							<th>라이더</th>
							<th>계좌</th>
							<th class="text-center">적립</th>
							<th class="text-end">잔액</th>
							<th class="text-end">정산수수료</th>
							<th class="text-end">실지급액</th>
							<?php if ($isAgency) : ?><th class="text-end">처리</th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="dp_tbody">
						<?php if ($rows === []) : ?>
						<tr><td colspan="10" class="text-center text-muted py-6">지급 대상이 없습니다.</td></tr>
						<?php else : foreach ($rows as $r) : ?>
						<tr data-rid="<?= (int) $r['rider_id'] ?>" data-amount="<?= (int) $r['payout'] ?>">
							<?php if ($isAgency) : ?><td><input type="checkbox" class="form-check-input dp-row-check" <?= $r['has_bank'] ? '' : 'disabled' ?> /></td><?php endif; ?>
							<?php if (!$isAgency) : ?><td><?= htmlspecialchars($r['agency_name'], ENT_QUOTES, 'UTF-8') ?></td><?php endif; ?>
							<td><span class="fw-bold"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></span> <span class="text-muted">(<?= htmlspecialchars($r['rider_code'], ENT_QUOTES, 'UTF-8') ?>)</span></td>
							<td class="text-muted"><?= $r['has_bank'] ? htmlspecialchars($r['bank_label'], ENT_QUOTES, 'UTF-8') : '<span class="badge badge-light-danger">계좌없음</span>' ?></td>
							<td class="text-center"><?= (int) $r['accrued_days'] ?>일</td>
							<td class="text-end text-muted"><?= number_format((int) $r['balance']) ?>원</td>
							<td class="text-end text-danger">
								<?= (int) $r['fee'] > 0 ? '− ' . number_format((int) $r['fee']) . '원' : '—' ?>
								<?php if ((int) $r['order_count'] > 0) : ?><div class="text-muted fs-9">배달 <?= (int) $r['order_count'] ?>건</div><?php endif; ?>
							</td>
							<td class="text-end fw-bold"><?= number_format((int) $r['payout']) ?>원</td>
							<?php if ($isAgency) : ?><td class="text-end"><button type="button" class="btn btn-sm btn-light-primary dp-pay-one" <?= $r['has_bank'] ? '' : 'disabled' ?>>지급</button></td><?php endif; ?>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<?php if ($isAgency) : ?>
	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('dp_toast');
		var toastMsg = document.getElementById('dp_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function post(payload) {
			return fetch(API, {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
				body: JSON.stringify(payload),
			}).then(function (r) { return r.json(); });
		}
		document.getElementById('dp_check_all').addEventListener('change', function (e) {
			document.querySelectorAll('.dp-row-check:not(:disabled)').forEach(function (c) { c.checked = e.target.checked; });
		});
		document.querySelectorAll('.dp-pay-one').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var tr = btn.closest('tr');
				var rid = parseInt(tr.getAttribute('data-rid'), 10);
				btn.disabled = true;
				post({ action: 'pay', rider_id: rid }).then(function (res) {
					if (!res.ok) throw new Error(res.message);
					showToast(res.message, true);
					setTimeout(function () { location.reload(); }, 800);
				}).catch(function (e) { showToast(e.message, false); btn.disabled = false; });
			});
		});
		document.getElementById('dp_pay_all').addEventListener('click', function () {
			var ids = [];
			document.querySelectorAll('.dp-row-check:checked').forEach(function (c) {
				ids.push(parseInt(c.closest('tr').getAttribute('data-rid'), 10));
			});
			if (ids.length === 0) { showToast('지급할 라이더를 선택하세요.', false); return; }
			post({ action: 'pay_batch', rider_ids: ids }).then(function (res) {
				if (!res.ok) throw new Error(res.message);
				showToast(res.message + (res.result && res.result.failed && res.result.failed.length ? ' — ' + res.result.failed[0] : ''), res.result.failed.length === 0);
				setTimeout(function () { location.reload(); }, 1200);
			}).catch(function (e) { showToast(e.message, false); });
		});
	})();
	</script>
	<?php endif; ?>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

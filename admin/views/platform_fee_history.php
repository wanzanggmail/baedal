<?php

declare(strict_types=1);

require_once INC_PATH . '/org_scope_picker.php';

require_once INC_PATH . '/PgPayment.php';
require_once INC_PATH . '/Organization.php';
require_once INC_PATH . '/Org.php';

$won = static fn (int $n): string => number_format($n) . '원';
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
$filterAgency = (int) ($_GET['agency'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

$level = admin_org_level();
$isAgencyLevel = $level === Org::LEVEL_AGENCY;
$myShareCol = $isAgencyLevel ? 'agency' : ($level === Org::LEVEL_DISTRIBUTOR ? 'distributor' : 'hq');
$myShareLabel = ['agency' => '대리점 몫', 'distributor' => '총판 몫', 'hq' => '본사 몫'][$myShareCol];

const PLATFORM_FEE_ROW_CAP = 300;

$filters = ['from' => $filterFrom, 'to' => $filterTo, 'status' => $filterStatus];
if ($filterAgency > 0) {
    $filters['agency_id'] = $filterAgency;
}

$listError = null;
$rows = [];
$sum = ['count' => 0, 'success_count' => 0, 'net' => 0, 'fee' => 0, 'hq' => 0, 'distributor' => 0, 'agency' => 0];
$needsMigrate = !PgPayment::tableExists();

if (!$needsMigrate) {
    try {
        $sum  = PgPayment::sumScoped($filters);
        $rows = PgPayment::listScoped($filters + ['limit' => PLATFORM_FEE_ROW_CAP]);
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

$agencyOptions = $isAgencyLevel ? [] : Organization::agencyOptions();

$listUrl = admin_url('settlement/platform-fee');

$statusLabel = ['success' => '성공', 'failed' => '실패', 'canceled' => '취소됨'];
$statusBadge = ['success' => 'badge-light-success', 'failed' => 'badge-light-danger', 'canceled' => 'badge-light-dark'];

// 취소는 돈을 되돌리는 작업이라 본사 최고관리자만 — API 도 같은 조건으로 막는다.
$canCancel = admin_has_role('super') && admin_org_level() === Org::LEVEL_ADMIN;

$quickRanges = [
    '오늘'      => [date('Y-m-d'), date('Y-m-d')],
    '최근 7일'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '이번 달'   => [date('Y-m-01'), date('Y-m-d')],
    '지난 달'   => [date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];

/** 기간 빠른 선택 버튼용 URL — admin_url()이 이미 ?route=...를 포함할 수 있어 안전하게 이어붙인다 */
function platform_fee_range_url(string $base, string $from, string $to, int $agencyId, string $status): string
{
    $sep = str_contains($base, '?') ? '&' : '?';
    $query = array_filter([
        'from'   => $from,
        'to'     => $to,
        'agency' => $agencyId > 0 ? $agencyId : null,
        'status' => $status !== '' ? $status : null,
    ], static fn ($v) => $v !== null && $v !== '');

    return $base . $sep . http_build_query($query);
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">플랫폼 수수료 내역</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">플랫폼 수수료 내역</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= $esc($listError) ?></div>
	<?php else : ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-percentage fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			라이더에게 자금을 조달(PG 카드결제)할 때마다 붙는 <strong>플랫폼 수수료</strong> 내역입니다.
			본사/총판/대리점 몫은 <strong>결제 시점에 적용된 요율 그대로 저장</strong>되므로, 이후 수수료 설정이 바뀌어도 과거 내역은 그대로입니다.
		</div>
	</div>

	<!--begin::필터-->
	<div class="card card-flush mb-6">
		<div class="card-body py-5">
			<form method="get" action="<?= $esc($listUrl) ?>" class="row g-3 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/platform-fee" />
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">시작일</label>
					<input type="date" name="from" class="form-control form-control-sm" value="<?= $esc($filterFrom) ?>" />
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">종료일</label>
					<input type="date" name="to" class="form-control form-control-sm" value="<?= $esc($filterTo) ?>" />
				</div>
				<?php // 총판 → 대리점 연동 선택기(공용). agency 파라미터는 그대로 유지된다.
				org_scope_picker('pf', 0, $filterAgency, [
					'dist_col' => 'col-auto', 'agency_col' => 'col-auto',
					'agency_name' => 'agency',
				]); ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">상태</label>
					<select name="status" class="form-select form-select-sm">
						<option value="">전체</option>
						<option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>성공</option>
						<option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>실패</option>
						<option value="canceled" <?= $filterStatus === 'canceled' ? 'selected' : '' ?>>취소됨</option>
					</select>
				</div>
				<div class="col-auto">
					<button type="submit" class="btn btn-sm btn-primary">조회</button>
				</div>
				<div class="col-auto d-flex flex-wrap gap-1">
					<?php foreach ($quickRanges as $label => [$qf, $qt]) :
					    $active = $filterFrom === $qf && $filterTo === $qt; ?>
					<a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
						href="<?= $esc(platform_fee_range_url($listUrl, $qf, $qt, $filterAgency, $filterStatus)) ?>">
						<?= $esc($label) ?>
					</a>
					<?php endforeach; ?>
				</div>
			</form>
		</div>
	</div>
	<!--end::필터-->

	<!--begin::요약-->
	<div class="row g-5 g-xl-8 mb-6">
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">결제 건수</div>
					<div class="fs-2 fw-bold text-gray-800"><?= number_format($sum['success_count']) ?><span class="fs-7 text-muted fw-normal"> / <?= number_format($sum['count']) ?>건</span></div>
					<div class="fs-8 text-muted mt-1">성공 / 전체(실패 포함)</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">조달 원금 합계</div>
					<div class="fs-2 fw-bold text-gray-800"><?= $won($sum['net']) ?></div>
					<div class="fs-8 text-muted mt-1">라이더에게 지급된 net</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">플랫폼 수수료 합계</div>
					<div class="fs-2 fw-bold text-primary"><?= $won($sum['fee']) ?></div>
					<div class="fs-8 text-muted mt-1">본사+총판+대리점 합계</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100 border border-primary">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">내 몫(<?= $esc($myShareLabel) ?>)</div>
					<div class="fs-2 fw-bold text-success"><?= $won($sum[$myShareCol]) ?></div>
					<div class="fs-8 text-muted mt-1">조회 범위 합계</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::요약-->

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">결제 내역</h3>
			<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">최근 <?= PLATFORM_FEE_ROW_CAP ?>건까지 표시 · 합계는 전체 기준</span>
		</div>
		<div class="card-body pt-0">
			<?php if ($rows === []) : ?>
			<p class="text-muted fs-7 py-10 mb-0 text-center">조회 결과가 없습니다.</p>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle fs-7 gy-2" id="pfhTable">
					<thead>
						<tr class="fw-bold text-muted">
							<th>일시</th>
							<?php if (!$isAgencyLevel) : ?><th>대리점</th><?php endif; ?>
							<th>라이더</th>
							<th class="text-end">조달 원금</th>
							<th class="text-end">수수료</th>
							<th class="text-end">본사</th>
							<th class="text-end">총판</th>
							<th class="text-end">대리점</th>
							<th>카드</th>
							<th>상태</th>
							<?php if ($canCancel) : ?><th class="text-end min-w-90px">관리</th><?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) :
						    $st = (string) $r['status'];
						    ?>
						<tr>
							<td class="text-gray-700"><?= $esc(substr((string) $r['created_at'], 0, 16)) ?></td>
							<?php if (!$isAgencyLevel) : ?>
							<td><?= $esc((string) ($r['agency_name'] ?? '')) ?> <span class="text-muted fs-8"><?= $esc((string) ($r['agency_code'] ?? '')) ?></span></td>
							<?php endif; ?>
							<td><?= $esc((string) ($r['rider_name'] ?? '—')) ?></td>
							<td class="text-end"><?= $won((int) $r['net_amount']) ?></td>
							<td class="text-end fw-bold"><?= $won((int) $r['service_fee']) ?></td>
							<td class="text-end text-muted"><?= $won((int) $r['hq_amount']) ?></td>
							<td class="text-end text-muted"><?= $won((int) $r['distributor_amount']) ?></td>
							<td class="text-end text-muted"><?= $won((int) $r['agency_amount']) ?></td>
							<td class="text-muted fs-8"><?= $esc((string) ($r['card_alias'] ?? '—')) ?></td>
							<td>
								<span class="badge <?= $esc($statusBadge[$st] ?? 'badge-light') ?>"><?= $esc($statusLabel[$st] ?? $st) ?></span>
								<?php if ($st === 'failed' && (string) ($r['fail_reason'] ?? '') !== '') : ?>
								<div class="text-danger fs-9 mt-1"><?= $esc((string) $r['fail_reason']) ?></div>
								<?php endif; ?>
								<?php if ($st === 'canceled') : ?>
								<div class="text-muted fs-9 mt-1">
									<?= $esc(substr((string) ($r['canceled_at'] ?? ''), 0, 16)) ?>
									<?php if ((string) ($r['cancel_reason'] ?? '') !== '') : ?><span class="d-block"><?= $esc((string) $r['cancel_reason']) ?></span><?php endif; ?>
								</div>
								<?php endif; ?>
							</td>
							<?php if ($canCancel) : ?>
							<td class="text-end">
								<?php if ($st === 'success') : ?>
								<button type="button" class="btn btn-sm btn-light-danger py-1 px-3 fs-8 pf-cancel"
									data-id="<?= (int) $r['id'] ?>"
									data-total="<?= (int) $r['total_charged'] ?>"
									data-net="<?= (int) $r['net_amount'] ?>"
									data-who="<?= $esc((string) ($r['rider_name'] ?? '—')) ?>">결제 취소</button>
								<?php else : ?>
								<span class="text-muted fs-9">—</span>
								<?php endif; ?>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<div class="text-muted fs-8 mt-3">
				결제가 성공하면 <strong>라이더 지급분(net)은 대리점 지갑에 충전</strong>되고,
				<strong>영업대행수수료는 본사·총판·대리점 지갑에 각자 몫만큼 적립</strong>됩니다(2026-08-08부터 실제 이체).
				세 몫의 합계는 항상 수수료 총액과 같습니다(반올림 잔차는 대리점 몫이 흡수).
				총판이 없는 본사 직속 대리점이면 총판 몫은 본사에 합산되며, 그 내역은 지갑 원장 메모에 남습니다.
			</div>
		</div>
	</div>
	<?php endif; ?>

<?php if ($canCancel) : ?>
<script>
(function () {
	'use strict';
	var API = <?= json_encode(ADMIN_BASE . '/api/pg_cancel.php', JSON_UNESCAPED_UNICODE) ?>;

	function won(n) { return Number(n || 0).toLocaleString('ko-KR') + '원'; }

	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest('.pf-cancel');
		if (!btn) { return; }

		var id = btn.getAttribute('data-id');
		var total = btn.getAttribute('data-total');
		var net = btn.getAttribute('data-net');
		var who = btn.getAttribute('data-who') || '';

		// 사유는 필수다 — 취소는 되돌릴 수 없고 나중에 반드시 "왜"를 묻게 된다.
		var reason = window.prompt(
			'결제를 취소합니다.\n\n'
			+ '대상: ' + who + '\n'
			+ '카드 취소액: ' + won(total) + '\n'
			+ '지갑 회수액: ' + won(net) + '\n\n'
			+ '※ 정산이 넘어간 건은 PG가 거절합니다(D+1 정산).\n'
			+ '※ 지갑에 재원이 없으면 잔액이 음수가 될 수 있습니다.\n\n'
			+ '취소 사유를 입력하세요.'
		);
		if (reason === null) { return; }
		reason = reason.trim();
		if (reason === '') { window.alert('취소 사유를 입력해야 합니다.'); return; }

		btn.disabled = true;
		btn.textContent = '취소 중…';

		fetch(API, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ payment_id: Number(id), reason: reason })
		})
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
			.then(function (res) {
				if (!res.ok || !res.j.ok) { throw new Error(res.j.message || '취소 실패'); }
				window.alert(res.j.message || '취소했습니다.');
				window.location.reload();
			})
			.catch(function (e) {
				window.alert(e.message || String(e));
				btn.disabled = false;
				btn.textContent = '결제 취소';
			});
	});
})();
</script>
<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

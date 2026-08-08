<?php

declare(strict_types=1);

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

$statusLabel = ['success' => '성공', 'failed' => '실패'];
$statusBadge = ['success' => 'badge-light-success', 'failed' => 'badge-light-danger'];

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
				<?php if (!$isAgencyLevel) : ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">대리점</label>
					<select name="agency" class="form-select form-select-sm" style="min-width:160px">
						<option value="0">전체</option>
						<?php foreach ($agencyOptions as $ao) : ?>
						<option value="<?= (int) $ao['id'] ?>" <?= $filterAgency === (int) $ao['id'] ? 'selected' : '' ?>><?= $esc((string) $ao['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">상태</label>
					<select name="status" class="form-select form-select-sm">
						<option value="">전체</option>
						<option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>성공</option>
						<option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>실패</option>
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
							</td>
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

<?php require_once INC_PATH . '/app_content_close.php'; ?>

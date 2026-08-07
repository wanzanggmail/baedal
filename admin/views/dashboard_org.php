<?php

declare(strict_types=1);

/**
 * 본사·총판 대시보드 — 대리점 관리 관점.
 * 대리점 계정은 admin/views/dashboard.php(라이더 운영 관점)를 그대로 본다.
 *
 * 차트는 Metronic 번들의 ApexCharts(plugins.bundle.js)를 직접 초기화한다.
 * widgets.bundle.js(데모용 자동 초기화)는 이 프로젝트에서 로드하지 않으므로
 * 데모 위젯 id와 충돌하지 않는다 — 초기화 코드는 이 파일 하단에 직접 둔다.
 */

require_once INC_PATH . '/AdminDashboard.php';
require_once INC_PATH . '/OrgDashboard.php';

$period = dashboard_period_from_get();
$dash  = OrgDashboard::load($period['from'], $period['to']);
$isHq  = (bool) $dash['is_hq'];
$rows  = $dash['agency_rows'];

$won   = static fn (int $n): string => AdminDashboard::formatWon($n);
$num   = static fn (int $n): string => number_format($n);
$esc   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$ridersUrl = static function (int $agencyId): string {
    $u = admin_url('riders/list');
    $u .= str_contains($u, '?') ? '&' : '?';

    return $u . 'agency=' . $agencyId;
};

if (!function_exists('org_delta_badge')) {
    function org_delta_badge(?float $delta): string
    {
        if ($delta === null) {
            return '<span class="badge badge-light-secondary fs-base">직전 기간 비교 없음</span>';
        }
        $up  = $delta >= 0;
        $cls = $up ? 'success' : 'danger';

        return '<span class="badge badge-light-' . $cls . ' fs-base">'
            . '<i class="ki-duotone ki-arrow-' . ($up ? 'up' : 'down') . ' fs-5 text-' . $cls . ' ms-n1"><span class="path1"></span><span class="path2"></span></i>'
            . ($up ? '+' : '') . number_format($delta, 1) . '%</span>';
    }
}

// ── 차트 데이터 (JS로 전달) ─────────────────────────────────────
$trend = $dash['trend'];
$hasTrend = ($trend['labels'] ?? []) !== [] && array_sum($trend['net'] ?? []) > 0;

$mix = $dash['platform_mix'];
$hasMix = $mix !== [];

// 정산액 순위 — 상위 8곳, 금액이 있는 대리점만
$rankRows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['week_payout'] > 0));
$rankRows = array_slice($rankRows, 0, 8);
$hasRank = $rankRows !== [];

$chartData = [
    'trend' => [
        'labels' => $trend['labels'] ?? [],
        'net'    => array_map('intval', $trend['net'] ?? []),
        'orders' => array_map('intval', $trend['orders'] ?? []),
        'bucket' => $trend['bucket'] ?? 'day',
    ],
    'mix' => [
        'labels' => array_map(static fn (array $m): string => (string) $m['label'], $mix),
        'values' => array_map(static fn (array $m): int => (int) $m['net'], $mix),
    ],
    'rank' => [
        'labels' => array_map(static fn (array $r): string => (string) $r['name'], $rankRows),
        'values' => array_map(static fn (array $r): int => (int) $r['week_payout'], $rankRows),
    ],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">
				<?= $isHq ? '본사 대시보드' : '총판 대시보드' ?>
			</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">대리점 현황</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 gap-lg-3">
			<?php $periodFrom = $period['from']; $periodTo = $period['to']; require INC_PATH . '/dashboard_range_picker.php'; ?>
			<?php if ($isHq && admin_can_access_route('system/orgs')) : ?>
			<a href="<?= $esc(admin_url('system/orgs')) ?>" class="btn btn-sm fw-bold btn-primary">
				<i class="ki-duotone ki-office-bag fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
				조직 관리
			</a>
			<?php endif; ?>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($dash['errors'] !== []) : ?>
	<div class="alert alert-warning p-5 mb-8">
		<strong>일부 지표를 불러오지 못했습니다.</strong>
		<ul class="mb-0 mt-2 fs-7">
			<?php foreach ($dash['errors'] as $e) : ?>
			<li><?= $esc((string) $e) ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<!--begin::메인 차트-->
	<div class="row gx-5 gx-xl-10 gy-5 mb-5 mb-xl-10">
		<div class="col-xl-8">
			<div class="card card-flush overflow-hidden h-xl-100">
				<div class="card-header py-5">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">정산 추이</span>
						<span class="text-gray-500 mt-1 fw-semibold fs-7">
							선택 기간 · <?= $trend['bucket'] === 'week' ? '주 단위 합계' : '일 단위' ?>
						</span>
					</h3>
					<div class="card-toolbar">
						<a href="<?= $esc(admin_url('settlement/fees')) ?>" class="btn btn-sm btn-light">수수료 내역</a>
					</div>
				</div>
				<div class="card-body d-flex justify-content-between flex-column pb-1 px-0">
					<div class="px-9 mb-5">
						<div class="d-flex align-items-center flex-wrap gap-3 mb-1">
							<span class="fs-2hx fw-bold text-gray-800 lh-1 ls-n2"><?= $won((int) $dash['week_payout']) ?></span>
							<?= org_delta_badge($dash['week_payout_delta'] !== null ? (float) $dash['week_payout_delta'] : null) ?>
						</div>
						<span class="fs-7 fw-semibold text-gray-500">
							기간 정산 합계 · 배달 <?= $num((int) $dash['week_orders']) ?>건
						</span>
					</div>
					<?php if ($hasTrend) : ?>
					<div id="kt_org_trend_chart" class="min-h-auto ps-4 pe-6" style="height: 300px"></div>
					<?php else : ?>
					<div class="px-9 pb-10 text-center text-muted fs-7">선택 기간에 반영된 정산 데이터가 없습니다.</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-4">
			<div class="card card-flush h-xl-100">
				<div class="card-header py-5">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">플랫폼 비중</span>
						<span class="text-gray-500 mt-1 fw-semibold fs-7">선택 기간 정산액 기준</span>
					</h3>
				</div>
				<div class="card-body d-flex flex-column justify-content-between pt-0">
					<?php if ($hasMix) : ?>
					<div id="kt_org_mix_chart" class="d-flex flex-center" style="min-height: 210px"></div>
					<?php else : ?>
					<div class="d-flex flex-center text-muted fs-7" style="min-height: 210px">데이터 없음</div>
					<?php endif; ?>
					<div class="separator separator-dashed my-4"></div>
					<div class="d-flex flex-stack">
						<div class="d-flex align-items-center">
							<i class="ki-duotone ki-percentage fs-2 text-primary me-3"><span class="path1"></span><span class="path2"></span></i>
							<div>
								<div class="fw-bold text-gray-800 fs-7">플랫폼 수수료 <?= $esc((string) $dash['fee_revenue_label']) ?></div>
								<div class="text-gray-500 fs-8"><?= (int) $dash['fee_revenue'] > 0 ? '선택 기간 카드결제분' : '기간 내 결제 없음' ?></div>
							</div>
						</div>
						<span class="fw-bold text-gray-900 fs-4"><?= $won((int) $dash['fee_revenue']) ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::메인 차트-->

	<!--begin::KPI-->
	<div class="row gy-5 gx-xl-10 mb-5 mb-xl-10">
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-office-bag fs-2hx text-primary"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['agency_count']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">관리 대리점</span>
					</div>
					<?php if ($isHq) : ?>
					<span class="badge badge-light-primary fs-base">총판 <?= $num((int) $dash['distributor_count']) ?>곳</span>
					<?php elseif ((int) $dash['agency_inactive'] > 0) : ?>
					<span class="badge badge-light-warning fs-base">중지 <?= $num((int) $dash['agency_inactive']) ?>곳</span>
					<?php else : ?>
					<span class="badge badge-light-success fs-base">전체 활성</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-people fs-2hx text-success"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['active_riders']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">활성 라이더</span>
					</div>
					<span class="badge badge-light-secondary fs-base">전 대리점 합계</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-time fs-2hx text-warning"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['pending_count']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">출금 신청 대기</span>
					</div>
					<?php if ((int) $dash['pending_count'] > 0) : ?>
					<a href="<?= $esc(admin_url('withdrawal/list')) ?>" class="badge badge-light-warning fs-base"><?= $won((int) $dash['pending_amount']) ?></a>
					<?php else : ?>
					<span class="badge badge-light-success fs-base">대기 없음</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-bank fs-2hx text-info"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= $won((int) $dash['wallet_total']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">대리점 지갑 총액</span>
					</div>
					<span class="badge badge-light-secondary fs-base">출금 지급 재원</span>
				</div>
			</div>
		</div>
	</div>
	<!--end::KPI-->

	<!--begin::순위 + 주의-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-xl-<?= $dash['attention'] !== [] ? '7' : '12' ?>">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">대리점별 정산액 순위</span>
						<span class="text-gray-500 mt-1 fw-semibold fs-7">선택 기간 · 상위 <?= count($rankRows) ?>곳</span>
					</h3>
				</div>
				<div class="card-body pt-2">
					<?php if ($hasRank) : ?>
					<div id="kt_org_rank_chart"></div>
					<?php else : ?>
					<p class="text-muted fs-7 py-10 mb-0 text-center">선택 기간에 정산 실적이 있는 대리점이 없습니다.</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php if ($dash['attention'] !== []) : ?>
		<div class="col-xl-5">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold text-gray-900">🛠 손이 필요한 대리점</h3>
					<div class="card-toolbar"><span class="badge badge-light-warning"><?= count($dash['attention']) ?>곳</span></div>
				</div>
				<div class="card-body pt-2">
					<div class="d-flex flex-column gap-3">
						<?php foreach ($dash['attention'] as $a) : ?>
						<div class="d-flex align-items-center flex-wrap gap-2">
							<a href="<?= $esc($ridersUrl((int) $a['id'])) ?>" class="fw-bold text-gray-800 text-hover-primary w-125px"><?= $esc((string) $a['name']) ?></a>
							<?php foreach ($a['issues'] as $iss) : ?>
							<span class="badge badge-light-<?= $esc((string) $iss['level']) ?> fs-8"><?= $esc((string) $iss['label']) ?></span>
							<?php endforeach; ?>
						</div>
						<?php endforeach; ?>
					</div>
					<div class="mt-6 d-flex flex-wrap gap-2">
						<a href="<?= $esc(admin_url('settlement/history')) ?>" class="btn btn-sm btn-light-primary">업로드 이력</a>
						<a href="<?= $esc(admin_url('withdrawal/list')) ?>" class="btn btn-sm btn-light-warning">출금 목록</a>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<!--end::순위 + 주의-->

	<?php if ($dash['risk_alerts'] !== []) : ?>
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold text-gray-900">⚠️ 리스크 알림</h3>
					<div class="card-toolbar"><span class="badge badge-light-danger"><?= count($dash['risk_alerts']) ?></span></div>
				</div>
				<div class="card-body pt-2">
					<div class="d-flex flex-column gap-2">
						<?php foreach ($dash['risk_alerts'] as $ra) : ?>
						<div class="d-flex align-items-center gap-3 border-start border-4 border-<?= $esc((string) $ra['level']) ?> ps-3 py-1">
							<span class="badge badge-light-<?= $esc((string) $ra['level']) ?> flex-shrink-0"><?= $esc((string) $ra['action']) ?></span>
							<div class="flex-grow-1 fs-7">
								<span class="text-gray-800"><?= $esc((string) $ra['detail']) ?></span>
								<span class="text-muted fs-8">· <?= $esc((string) $ra['actor']) ?></span>
							</div>
							<span class="text-muted fs-8 flex-shrink-0"><?= $esc((string) $ra['at']) ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($isHq && $dash['distributor_rows'] !== []) : ?>
	<!--begin::총판 롤업-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">총판별 요약</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">선택 기간 기준 · 하위 대리점 합산</span>
				</div>
				<div class="card-body pt-2">
					<div class="table-responsive">
						<table class="table table-row-dashed align-middle fs-7 gy-3 mb-0">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-140px">총판</th>
									<th class="text-end min-w-80px">대리점</th>
									<th class="text-end min-w-80px">라이더</th>
									<th class="text-end min-w-90px">배달 건수</th>
									<th class="text-end min-w-110px">정산 합계</th>
									<th class="text-end min-w-110px">수수료 본사몫</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($dash['distributor_rows'] as $d) : ?>
								<tr>
									<td class="fw-bold text-gray-800"><?= $esc((string) $d['name']) ?></td>
									<td class="text-end"><?= $num((int) $d['agencies']) ?></td>
									<td class="text-end"><?= $num((int) $d['riders']) ?></td>
									<td class="text-end"><?= $num((int) $d['week_orders']) ?></td>
									<td class="text-end fw-bold text-gray-800"><?= $won((int) $d['week_payout']) ?></td>
									<td class="text-end text-gray-700"><?= $won((int) $d['fee_share']) ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::총판 롤업-->
	<?php endif; ?>

	<!--begin::대리점별 실적-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header align-items-center py-5 gap-2 gap-md-5">
					<div class="card-title">
						<h3 class="fw-bold m-0">대리점별 현황</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">선택 기간 정산액 순 · 잔액/미수금은 현재 시점</span>
					</div>
					<div class="card-toolbar">
						<?php if (admin_can_access_route('system/pg-fee')) : ?>
						<a href="<?= $esc(admin_url('system/pg-fee')) ?>" class="btn btn-sm btn-light">수수료 설정</a>
						<?php endif; ?>
					</div>
				</div>
				<div class="card-body pt-0">
					<?php if ($rows === []) : ?>
					<p class="text-muted fs-7 py-10 mb-0 text-center">조회 범위 내 대리점이 없습니다.</p>
					<?php else : ?>
					<div class="table-responsive">
						<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4 fs-7">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-150px">대리점</th>
									<?php if ($isHq) : ?><th class="min-w-100px">총판</th><?php endif; ?>
									<th class="text-end min-w-70px">라이더</th>
									<th class="text-end min-w-80px">배달 건수</th>
									<th class="text-end min-w-100px">정산 합계</th>
									<th class="text-end min-w-100px">지갑 잔액</th>
									<th class="text-end min-w-100px">출금 대기</th>
									<th class="text-end min-w-90px">미수금</th>
									<th class="min-w-110px">최근 정산일</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($rows as $r) :
								    $short = $r['pending_amount'] > 0 && $r['pending_amount'] > $r['wallet_balance'];
								    ?>
								<tr<?= $r['is_active'] ? '' : ' class="opacity-75"' ?>>
									<td>
										<a href="<?= $esc($ridersUrl((int) $r['id'])) ?>" class="fw-bold text-gray-800 text-hover-primary"><?= $esc((string) $r['name']) ?></a>
										<?php if (!$r['is_active']) : ?><span class="badge badge-light-dark fs-9 ms-1">중지</span><?php endif; ?>
										<div class="text-muted fs-8"><?= $esc((string) $r['code']) ?></div>
									</td>
									<?php if ($isHq) : ?>
									<td class="text-gray-700"><?= $esc((string) $r['parent_name']) ?></td>
									<?php endif; ?>
									<td class="text-end text-gray-800"><?= $num((int) $r['riders']) ?></td>
									<td class="text-end text-gray-700"><?= $num((int) $r['week_orders']) ?></td>
									<td class="text-end fw-bold text-gray-800"><?= $won((int) $r['week_payout']) ?></td>
									<td class="text-end <?= $short ? 'text-danger fw-bold' : 'text-gray-700' ?>"><?= $won((int) $r['wallet_balance']) ?></td>
									<td class="text-end">
										<?php if ((int) $r['pending_count'] > 0) : ?>
										<span class="<?= $short ? 'text-danger fw-bold' : 'text-gray-700' ?>"><?= $won((int) $r['pending_amount']) ?></span>
										<span class="text-muted fs-8">(<?= $num((int) $r['pending_count']) ?>건)</span>
										<?php else : ?>
										<span class="text-muted">—</span>
										<?php endif; ?>
									</td>
									<td class="text-end text-gray-700"><?= (int) $r['debt_balance'] > 0 ? $won((int) $r['debt_balance']) : '<span class="text-muted">—</span>' ?></td>
									<td>
										<?php if ((string) $r['last_upload'] !== '') : ?>
										<span class="text-gray-700"><?= $esc((string) $r['last_upload']) ?></span>
										<?php else : ?>
										<span class="badge badge-light-warning fs-8">업로드 없음</span>
										<?php endif; ?>
										<?php if ((int) $r['unapplied'] > 0) : ?>
										<span class="badge badge-light-warning fs-9 ms-1">미반영 <?= (int) $r['unapplied'] ?></span>
										<?php endif; ?>
										<?php if ((int) $r['unmatched'] > 0) : ?>
										<span class="badge badge-light-danger fs-9 ms-1">미매칭 <?= $num((int) $r['unmatched']) ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr class="fw-bold text-gray-800 border-top">
									<td>합계</td>
									<?php if ($isHq) : ?><td></td><?php endif; ?>
									<td class="text-end"><?= $num((int) $dash['active_riders']) ?></td>
									<td class="text-end"><?= $num((int) $dash['week_orders']) ?></td>
									<td class="text-end"><?= $won((int) $dash['week_payout']) ?></td>
									<td class="text-end"><?= $won((int) $dash['wallet_total']) ?></td>
									<td class="text-end"><?= $won((int) $dash['pending_amount']) ?></td>
									<td colspan="2"></td>
								</tr>
							</tfoot>
						</table>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<!--end::대리점별 실적-->

	<?php if ($dash['large_withdrawals'] !== []) : ?>
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold text-gray-900">💰 큰 금액 출금</h3>
					<div class="card-toolbar"><span class="badge badge-light-warning"><?= number_format(AdminDashboard::LARGE_WITHDRAWAL_THRESHOLD) ?>원 이상 · 최근 14일</span></div>
				</div>
				<div class="card-body pt-2">
					<div class="table-responsive">
						<table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
							<tbody>
								<?php foreach ($dash['large_withdrawals'] as $lw) : ?>
								<tr>
									<td><span class="badge badge-light-secondary fs-8"><?= $esc((string) $lw['kind']) ?></span></td>
									<td class="fw-bold text-gray-800"><?= $esc((string) $lw['name']) ?></td>
									<td class="text-end fw-bolder text-danger"><?= $esc((string) $lw['amount_label']) ?></td>
									<td class="text-center"><span class="badge badge-light"><?= $esc((string) $lw['status']) ?></span></td>
									<td class="text-muted fs-8 text-end"><?= $esc((string) $lw['at']) ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

<script>
(function () {
	'use strict';

	var DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>;
	var charts = [];

	/** 테마 CSS 변수 조회 — KTUtil이 없거나 값이 비면 폴백 색을 쓴다. */
	function cssVar(name, fallback) {
		try {
			if (typeof KTUtil !== 'undefined' && KTUtil.getCssVariableValue) {
				var v = KTUtil.getCssVariableValue(name);
				if (v) return v;
			}
		} catch (e) { /* noop */ }
		return fallback;
	}

	/** 화면 표기와 같은 축약(억/만) — PHP AdminDashboard::formatWon과 같은 규칙 */
	function won(n) {
		n = Number(n) || 0;
		var sign = n < 0 ? '-' : '';
		var a = Math.abs(n);
		if (a >= 100000000) {
			var eok = a / 100000000;
			var s = Math.abs(eok - Math.round(eok)) < 0.05 ? String(Math.round(eok)) : eok.toFixed(1);
			return sign + '₩ ' + s + '억';
		}
		if (a >= 10000) return sign + '₩ ' + Math.round(a / 10000).toLocaleString('ko-KR') + '만';
		return sign + '₩ ' + a.toLocaleString('ko-KR');
	}

	function renderAll() {
		// 이전 차트 정리(테마 전환 시 재생성)
		charts.forEach(function (c) { try { c.destroy(); } catch (e) {} });
		charts = [];

		var labelColor  = cssVar('--bs-gray-500', '#99A1B7');
		var borderColor = cssVar('--bs-border-dashed-color', '#DBDFE9');
		var primary     = cssVar('--bs-primary', '#009EF7');
		var success     = cssVar('--bs-success', '#50CD89');
		var warning     = cssVar('--bs-warning', '#FFC700');
		var info        = cssVar('--bs-info', '#7239EA');
		var danger      = cssVar('--bs-danger', '#F1416C');

		// ── 1. 정산 추이 (area) ──────────────────────────────
		var trendEl = document.getElementById('kt_org_trend_chart');
		if (trendEl && DATA.trend.labels.length) {
			var t = new ApexCharts(trendEl, {
				series: [{ name: '정산 합계', data: DATA.trend.net }],
				chart: { fontFamily: 'inherit', type: 'area', height: 300, toolbar: { show: false } },
				legend: { show: false },
				dataLabels: { enabled: false },
				fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0, stops: [0, 80, 100] } },
				stroke: { curve: 'smooth', show: true, width: 3, colors: [primary] },
				colors: [primary],
				xaxis: {
					categories: DATA.trend.labels,
					axisBorder: { show: false },
					axisTicks: { show: false },
					tickAmount: Math.min(8, DATA.trend.labels.length),
					labels: { style: { colors: labelColor, fontSize: '12px' } },
					crosshairs: { position: 'front', stroke: { color: primary, width: 1, dashArray: 3 } }
				},
				yaxis: {
					tickAmount: 4,
					labels: { style: { colors: labelColor, fontSize: '12px' }, formatter: function (v) { return won(v); } }
				},
				tooltip: {
					style: { fontSize: '12px' },
					y: {
						formatter: function (v, opts) {
							var i = opts && opts.dataPointIndex;
							var o = (typeof i === 'number' && DATA.trend.orders[i] != null) ? DATA.trend.orders[i] : null;
							return won(v) + (o !== null ? ' · ' + o.toLocaleString('ko-KR') + '건' : '');
						}
					}
				},
				grid: { borderColor: borderColor, strokeDashArray: 4, yaxis: { lines: { show: true } } },
				markers: { strokeColor: primary, strokeWidth: 3 }
			});
			t.render();
			charts.push(t);
		}

		// ── 2. 플랫폼 비중 (donut) ───────────────────────────
		var mixEl = document.getElementById('kt_org_mix_chart');
		if (mixEl && DATA.mix.values.length) {
			var m = new ApexCharts(mixEl, {
				series: DATA.mix.values,
				labels: DATA.mix.labels,
				chart: { fontFamily: 'inherit', type: 'donut', height: 230 },
				colors: [success, primary, warning, info, danger],
				stroke: { width: 0 },
				dataLabels: {
					enabled: true,
					formatter: function (v) { return Math.round(v) + '%'; },
					style: { fontSize: '12px', fontWeight: '600' }
				},
				legend: {
					show: true,
					position: 'bottom',
					fontSize: '12px',
					labels: { colors: labelColor }
				},
				plotOptions: { pie: { donut: { size: '62%' } } },
				tooltip: { style: { fontSize: '12px' }, y: { formatter: function (v) { return won(v); } } }
			});
			m.render();
			charts.push(m);
		}

		// ── 3. 대리점별 정산액 순위 (horizontal bar) ─────────
		var rankEl = document.getElementById('kt_org_rank_chart');
		if (rankEl && DATA.rank.values.length) {
			var h = Math.max(220, DATA.rank.values.length * 46 + 40);
			var r = new ApexCharts(rankEl, {
				series: [{ name: '정산 합계', data: DATA.rank.values }],
				chart: { fontFamily: 'inherit', type: 'bar', height: h, toolbar: { show: false } },
				plotOptions: { bar: { horizontal: true, borderRadius: 6, barHeight: '58%', distributed: true } },
				legend: { show: false },
				colors: [primary, success, warning, info, danger, '#3E97FF', '#F1416C', '#50CD89'],
				dataLabels: {
					enabled: true,
					textAnchor: 'start',
					offsetX: 0,
					formatter: function (v) { return won(v); },
					style: { fontSize: '12px', fontWeight: '600', colors: ['#fff'] }
				},
				xaxis: {
					categories: DATA.rank.labels,
					axisBorder: { show: false },
					axisTicks: { show: false },
					labels: { style: { colors: labelColor, fontSize: '12px' }, formatter: function (v) { return won(v); } }
				},
				yaxis: { labels: { style: { colors: labelColor, fontSize: '12px' } } },
				grid: { borderColor: borderColor, strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
				tooltip: { style: { fontSize: '12px' }, y: { formatter: function (v) { return won(v); } } }
			});
			r.render();
			charts.push(r);
		}
	}

	function boot() {
		if (typeof ApexCharts === 'undefined') {
			// eslint-disable-next-line no-console
			console.warn('ApexCharts를 찾을 수 없어 대시보드 차트를 건너뜁니다.');
			return;
		}
		renderAll();
		// 라이트/다크 전환 시 색이 따라가도록 재생성
		try {
			if (typeof KTThemeMode !== 'undefined' && KTThemeMode.on) {
				KTThemeMode.on('kt.thememode.change', renderAll);
			}
		} catch (e) { /* noop */ }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
</script>

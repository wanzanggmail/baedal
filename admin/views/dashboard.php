<?php

declare(strict_types=1);

require_once INC_PATH . '/AdminDashboard.php';
require_once INC_PATH . '/Org.php';

// 본사·총판은 "대리점 관리" 관점의 별도 대시보드를 본다.
// 아래 화면(라이더 운영 관점)은 대리점 계정 전용.
if (in_array(admin_org_level(), [Org::LEVEL_ADMIN, Org::LEVEL_DISTRIBUTOR], true)) {
    require __DIR__ . '/dashboard_org.php';

    return;
}

$period = dashboard_period_from_get();
$dash = AdminDashboard::load($period['from'], $period['to']);
$dashErrors = $dash['errors'];

$uploadStatusLabels = [
    'uploaded' => ['label' => '업로드됨', 'badge' => 'badge-light-primary'],
    'parsing'  => ['label' => '파싱 중', 'badge' => 'badge-light-warning'],
    'parsed'   => ['label' => '파싱완료', 'badge' => 'badge-light-success'],
    'applied'  => ['label' => '반영완료', 'badge' => 'badge-light-info'],
    'error'    => ['label' => '오류', 'badge' => 'badge-light-danger'],
];
$platformBadge = [
    'baemin'  => 'badge-light-primary',
    'coupang' => 'badge-light-success',
    'other'   => 'badge-light-secondary',
];
$platformShort = [
    'baemin'  => '배민',
    'coupang' => '쿠팡',
    'other'   => '기타',
];

/**
 * @param ?float $delta
 */
function dash_delta_badge(?float $delta, bool $invert = false): string
{
    if ($delta === null) {
        return '<span class="badge badge-light-secondary fs-base">직전 기간 비교 없음</span>';
    }
    $up = $delta >= 0;
    $good = $invert ? !$up : $up;
    $cls = $good ? 'success' : 'danger';
    $arrow = $up ? 'ki-arrow-up' : 'ki-arrow-down';
    $sign = $up ? '+' : '';

    return '<span class="badge badge-light-' . $cls . ' fs-base">'
        . '<i class="ki-duotone ' . $arrow . ' fs-5 text-' . $cls . ' ms-n1"><span class="path1"></span><span class="path2"></span></i>'
        . $sign . number_format($delta, 1) . '%</span>';
}

// ── 차트 데이터 (JS로 전달) ─────────────────────────────────────
$trend = $dash['trend'];
$hasTrend = ($trend['labels'] ?? []) !== [] && array_sum($trend['payout'] ?? []) > 0;
$hasMix   = $dash['platform_rows'] !== [];
$topRiders = $dash['top_riders'];
$hasTop   = $topRiders !== [];

$chartData = [
    'trend' => [
        'labels' => $trend['labels'] ?? [],
        'payout' => array_map('intval', $trend['payout'] ?? []),
        'orders' => array_map('intval', $trend['orders'] ?? []),
    ],
    'mix' => [
        'labels' => array_map(static fn (array $p): string => (string) $p['label'], $dash['platform_rows']),
        'values' => array_map(static fn (array $p): int => (int) $p['amount'], $dash['platform_rows']),
    ],
    'riders' => [
        'labels' => array_map(static fn (array $r): string => (string) $r['name'], $topRiders),
        'values' => array_map(static fn (array $r): int => (int) $r['payout'], $topRiders),
        'orders' => array_map(static fn (array $r): int => (int) $r['orders'], $topRiders),
    ],
];

$riderDetailUrl = static function (int $riderId): string {
    $u = admin_url('riders/detail');
    $u .= str_contains($u, '?') ? '&' : '?';

    return $u . 'id=' . $riderId;
};
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">대시보드</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">요약</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 gap-lg-3">
			<?php $periodFrom = $period['from']; $periodTo = $period['to']; require INC_PATH . '/dashboard_range_picker.php'; ?>
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm fw-bold btn-primary">
				<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
				정산 엑셀 업로드
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($dashErrors !== []) : ?>
	<div class="alert alert-warning p-5 mb-8">
		<strong>일부 지표를 불러오지 못했습니다.</strong>
		<ul class="mb-0 mt-2 fs-7">
			<?php foreach ($dashErrors as $err) : ?>
			<li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
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
							선택 기간 · <?= $dash['trend']['bucket'] === 'week' ? '주 단위 합계' : '일 단위' ?> · 일간 정산서 반영분
						</span>
					</h3>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">수수료 내역</a>
					</div>
				</div>
				<div class="card-body d-flex justify-content-between flex-column pb-1 px-0">
					<div class="px-9 mb-5">
						<div class="d-flex align-items-center flex-wrap gap-3 mb-1">
							<span class="fs-2hx fw-bold text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatWon((int) $dash['week_payout']) ?></span>
							<?= dash_delta_badge($dash['week_payout_delta'] !== null ? (float) $dash['week_payout_delta'] : null) ?>
						</div>
						<span class="fs-7 fw-semibold text-gray-500">
							기간 정산 합계 · 배달 <?= AdminDashboard::formatCount((int) $dash['week_orders']) ?>건
							<?= dash_delta_badge($dash['week_orders_delta'] !== null ? (float) $dash['week_orders_delta'] : null) ?>
						</span>
					</div>
					<?php if ($hasTrend) : ?>
					<div id="kt_agency_trend_chart" class="min-h-auto ps-4 pe-6" style="height: 300px"></div>
					<?php else : ?>
					<div class="px-9 pb-10 text-center text-muted fs-7">선택 기간에 정산 데이터가 없습니다. 엑셀을 업로드해 주세요.</div>
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
					<div id="kt_agency_mix_chart" class="d-flex flex-center" style="min-height: 210px"></div>
					<?php else : ?>
					<div class="d-flex flex-center text-muted fs-7" style="min-height: 210px">데이터 없음</div>
					<?php endif; ?>
					<div class="separator separator-dashed my-4"></div>
					<div class="d-flex flex-stack">
						<span class="text-gray-600 fw-semibold fs-7">합계</span>
						<span class="text-gray-900 fw-bold fs-4"><?= AdminDashboard::formatWon((int) $dash['platform_total']) ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::메인 차트-->

	<!--begin::Row KPI-->
	<div class="row gy-5 gx-xl-10 mb-5 mb-xl-10">
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-people fs-2hx text-primary">
						<span class="path1"></span><span class="path2"></span><span class="path3"></span>
						<span class="path4"></span><span class="path5"></span>
					</i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['active_riders']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">활성 라이더</span>
					</div>
					<?php if ((int) $dash['riders_new_week'] > 0) : ?>
					<span class="badge badge-light-success fs-base">기간 내 +<?= (int) $dash['riders_new_week'] ?>명</span>
					<?php else : ?>
					<span class="badge badge-light-secondary fs-base">기간 내 신규 0</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-time fs-2hx text-warning"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['pending_withdrawals']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">출금 신청 대기</span>
					</div>
					<?php if ((int) $dash['pending_withdrawals'] > 0) : ?>
					<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="badge badge-light-warning fs-base"><?= AdminDashboard::formatWon((int) $dash['pending_withdraw_amount']) ?></a>
					<?php else : ?>
					<span class="badge badge-light-success fs-base">대기 없음</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-minus-circle fs-2hx text-danger"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatWon((int) $dash['month_deductions']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">당월 주간 차감 합계</span>
					</div>
					<?= dash_delta_badge(
					    $dash['month_deduction_delta'] !== null ? (float) $dash['month_deduction_delta'] : null,
					    true
					) ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column py-6">
					<i class="ki-duotone ki-notification fs-2hx text-info">
						<span class="path1"></span><span class="path2"></span><span class="path3"></span>
					</i>
					<div class="d-flex flex-column my-6">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['published_notices']) ?></span>
						<span class="fw-semibold fs-7 text-gray-500 mt-1">게시 공지</span>
					</div>
					<span class="badge badge-light-primary fs-base">광고 <?= (int) $dash['active_banners'] ?>건</span>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row KPI-->

	<?php if ($dash['risk_alerts'] !== [] || $dash['large_withdrawals'] !== []) : ?>
	<!--begin::Row 리스크-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<?php if ($dash['risk_alerts'] !== []) : ?>
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold text-gray-900">⚠️ 리스크 알림</h3>
					<div class="card-toolbar"><span class="badge badge-light-danger"><?= count($dash['risk_alerts']) ?></span></div>
				</div>
				<div class="card-body pt-2">
					<div class="d-flex flex-column gap-2">
						<?php foreach ($dash['risk_alerts'] as $ra) : ?>
						<div class="d-flex align-items-center gap-3 border-start border-4 border-<?= htmlspecialchars((string) $ra['level'], ENT_QUOTES, 'UTF-8') ?> ps-3 py-1">
							<span class="badge badge-light-<?= htmlspecialchars((string) $ra['level'], ENT_QUOTES, 'UTF-8') ?> flex-shrink-0"><?= htmlspecialchars((string) $ra['action'], ENT_QUOTES, 'UTF-8') ?></span>
							<div class="flex-grow-1 fs-7">
								<span class="text-gray-800"><?= htmlspecialchars((string) $ra['detail'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-8">· <?= htmlspecialchars((string) $ra['actor'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<span class="text-muted fs-8 flex-shrink-0"><?= htmlspecialchars((string) $ra['at'], ENT_QUOTES, 'UTF-8') ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
		<?php if ($dash['large_withdrawals'] !== []) : ?>
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold text-gray-900">💰 큰 금액 출금</h3>
					<div class="card-toolbar"><span class="badge badge-light-warning"><?= number_format(AdminDashboard::LARGE_WITHDRAWAL_THRESHOLD) ?>원 이상</span></div>
				</div>
				<div class="card-body pt-2">
					<div class="table-responsive">
						<table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
							<tbody>
								<?php foreach ($dash['large_withdrawals'] as $lw) : ?>
								<tr>
									<td><span class="badge badge-light-secondary fs-8"><?= htmlspecialchars((string) $lw['kind'], ENT_QUOTES, 'UTF-8') ?></span></td>
									<td class="fw-bold text-gray-800"><?= htmlspecialchars((string) $lw['name'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-end fw-bolder text-danger"><?= htmlspecialchars((string) $lw['amount_label'], ENT_QUOTES, 'UTF-8') ?></td>
									<td class="text-center"><span class="badge badge-light"><?= htmlspecialchars((string) $lw['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
									<td class="text-muted fs-8 text-end"><?= htmlspecialchars((string) $lw['at'], ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php endif; ?>
	</div>
	<!--end::Row 리스크-->
	<?php endif; ?>

	<!--begin::Row 2 cols-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-xl-7">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">라이더별 정산액 순위</span>
						<span class="text-gray-500 mt-1 fw-semibold fs-7">선택 기간 · 상위 <?= count($topRiders) ?>명</span>
					</h3>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('riders/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">라이더 관리</a>
					</div>
				</div>
				<div class="card-body pt-2">
					<?php if ($hasTop) : ?>
					<div id="kt_agency_rider_chart"></div>
					<div class="separator separator-dashed my-4"></div>
					<div class="d-flex flex-column gap-3">
						<?php foreach (array_slice($topRiders, 0, 3) as $tr) : ?>
						<div class="d-flex flex-stack">
							<div class="d-flex align-items-center">
								<span class="bullet bullet-vertical h-25px bg-primary me-3"></span>
								<div>
									<a href="<?= htmlspecialchars($riderDetailUrl((int) $tr['id']), ENT_QUOTES, 'UTF-8') ?>" class="fw-bold text-gray-800 text-hover-primary fs-7"><?= htmlspecialchars((string) $tr['name'], ENT_QUOTES, 'UTF-8') ?></a>
									<div class="text-gray-500 fs-8"><?= number_format((int) $tr['orders']) ?>건</div>
								</div>
							</div>
							<span class="fw-bold text-gray-800 fs-6"><?= AdminDashboard::formatWon((int) $tr['payout']) ?></span>
						</div>
						<?php endforeach; ?>
					</div>
					<?php else : ?>
					<p class="text-muted fs-7 py-10 mb-0 text-center">선택 기간에 정산 실적이 있는 라이더가 없습니다.</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">최근 처리 현황</span>
						<span class="text-gray-500 pt-2 fw-semibold fs-6">최근 3일 · 업로드 · 출금</span>
					</h3>
				</div>
				<div class="card-body pt-3">
					<?php if ($dash['timeline'] === []) : ?>
					<p class="text-muted fs-7">최근 활동이 없습니다.</p>
					<?php else : ?>
					<div class="timeline-label">
						<?php foreach ($dash['timeline'] as $ev) : ?>
						<div class="timeline-item">
							<div class="timeline-label fw-bold text-gray-800 fs-7 w-100px"><?= htmlspecialchars((string) $ev['time_label'], ENT_QUOTES, 'UTF-8') ?></div>
							<div class="timeline-badge">
								<i class="ki-duotone <?= htmlspecialchars((string) $ev['icon'], ENT_QUOTES, 'UTF-8') ?> text-<?= htmlspecialchars((string) $ev['icon_class'], ENT_QUOTES, 'UTF-8') ?> fs-2">
									<span class="path1"></span><span class="path2"></span>
								</i>
							</div>
							<div class="fw-semibold text-gray-700 ps-3 fs-6"><?= htmlspecialchars((string) $ev['text'], ENT_QUOTES, 'UTF-8') ?></div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
					<div class="mt-8 d-flex flex-wrap gap-2">
						<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">업로드 이력</a>
						<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-warning">출금 목록</a>
						<a href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">공지 관리</a>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row-->

	<!--begin::Row table-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header align-items-center py-5 gap-2 gap-md-5">
					<div class="card-title">
						<h3 class="fw-bold m-0">최근 정산 업로드 이력</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">DB · 최근 8건</span>
					</div>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">새 업로드</a>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-120px">일자</th>
									<th class="min-w-100px">플랫폼</th>
									<th class="min-w-120px">파일명</th>
									<th class="min-w-80px text-end">행 수</th>
									<th class="min-w-100px">상태</th>
									<th class="min-w-120px text-end">처리 시각</th>
								</tr>
							</thead>
							<tbody>
								<?php if ($dash['recent_uploads'] === []) : ?>
								<tr>
									<td colspan="6" class="text-center text-muted py-10">업로드 이력이 없습니다.</td>
								</tr>
								<?php else : ?>
								<?php foreach ($dash['recent_uploads'] as $u) :
								    $st = (string) ($u['status'] ?? '');
								    $stInfo = $uploadStatusLabels[$st] ?? ['label' => $st, 'badge' => 'badge-light'];
								    $plat = (string) ($u['platform'] ?? '');
								    $err = (int) ($u['error_rows'] ?? 0);
								    $detailUrl = admin_url('settlement/upload-detail');
								    $detailUrl .= str_contains($detailUrl, '?') ? '&' : '?';
								    $detailUrl .= 'id=' . (int) ($u['id'] ?? 0);
								    ?>
								<tr>
									<td><span class="text-gray-800 fw-bold"><?= htmlspecialchars((string) ($u['settlement_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td>
									<td><span class="badge <?= htmlspecialchars($platformBadge[$plat] ?? 'badge-light', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($platformShort[$plat] ?? $plat, ENT_QUOTES, 'UTF-8') ?></span></td>
									<td>
										<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-gray-700 text-hover-primary"><?= htmlspecialchars((string) ($u['original_filename'] ?? ''), ENT_QUOTES, 'UTF-8') ?></a>
									</td>
									<td class="text-end text-gray-800"><?= number_format((int) ($u['total_rows'] ?? 0)) ?></td>
									<td>
										<span class="badge <?= htmlspecialchars($stInfo['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stInfo['label'], ENT_QUOTES, 'UTF-8') ?></span>
										<?php if ($err > 0) : ?>
										<span class="badge badge-light-danger fs-9 ms-1">미매칭 <?= $err ?></span>
										<?php endif; ?>
									</td>
									<td class="text-end text-muted"><?= htmlspecialchars(substr((string) ($u['created_at'] ?? ''), 0, 19), ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row-->
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
		var trendEl = document.getElementById('kt_agency_trend_chart');
		if (trendEl && DATA.trend.labels.length) {
			var t = new ApexCharts(trendEl, {
				series: [{ name: '정산 합계', data: DATA.trend.payout }],
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
		var mixEl = document.getElementById('kt_agency_mix_chart');
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
				legend: { show: true, position: 'bottom', fontSize: '12px', labels: { colors: labelColor } },
				plotOptions: { pie: { donut: { size: '62%' } } },
				tooltip: { style: { fontSize: '12px' }, y: { formatter: function (v) { return won(v); } } }
			});
			m.render();
			charts.push(m);
		}

		// ── 3. 라이더별 정산액 순위 (horizontal bar) ─────────
		var rEl = document.getElementById('kt_agency_rider_chart');
		if (rEl && DATA.riders.values.length) {
			var h = Math.max(220, DATA.riders.values.length * 42 + 40);
			var rc = new ApexCharts(rEl, {
				series: [{ name: '정산 합계', data: DATA.riders.values }],
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
					categories: DATA.riders.labels,
					axisBorder: { show: false },
					axisTicks: { show: false },
					labels: { style: { colors: labelColor, fontSize: '12px' }, formatter: function (v) { return won(v); } }
				},
				yaxis: { labels: { style: { colors: labelColor, fontSize: '12px' } } },
				grid: { borderColor: borderColor, strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
				tooltip: {
					style: { fontSize: '12px' },
					y: {
						formatter: function (v, opts) {
							var i = opts && opts.dataPointIndex;
							var o = (typeof i === 'number' && DATA.riders.orders[i] != null) ? DATA.riders.orders[i] : null;
							return won(v) + (o !== null ? ' · ' + o.toLocaleString('ko-KR') + '건' : '');
						}
					}
				}
			});
			rc.render();
			charts.push(rc);
		}
	}

	function boot() {
		if (typeof ApexCharts === 'undefined') {
			// eslint-disable-next-line no-console
			console.warn('ApexCharts를 찾을 수 없어 대시보드 차트를 건너뜁니다.');
			return;
		}
		renderAll();
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

<?php

declare(strict_types=1);

require_once INC_PATH . '/AdminDashboard.php';

$dash = AdminDashboard::load();
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
        return '<span class="badge badge-light-secondary fs-base">전주 비교 없음</span>';
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
			<div class="btn btn-sm fw-bold btn-secondary d-flex align-items-center px-4">
				<span class="text-gray-700 fw-bold"><?= htmlspecialchars((string) $dash['period_label'], ENT_QUOTES, 'UTF-8') ?></span>
				<span class="text-gray-500 fs-8 ms-2">(이번 주)</span>
				<i class="ki-duotone ki-calendar-8 fs-2 ms-2 me-0 text-gray-600">
					<span class="path1"></span><span class="path2"></span><span class="path3"></span>
					<span class="path4"></span><span class="path5"></span><span class="path6"></span>
				</i>
			</div>
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

	<!--begin::Row KPI-->
	<div class="row gy-5 gx-xl-10">
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-people fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span>
							<span class="path4"></span><span class="path5"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['active_riders']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">활성 라이더</span>
						</div>
					</div>
					<?php if ((int) $dash['riders_new_week'] > 0) : ?>
					<span class="badge badge-light-success fs-base">이번 주 +<?= (int) $dash['riders_new_week'] ?>명</span>
					<?php else : ?>
					<span class="badge badge-light-secondary fs-base">이번 주 신규 0</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-wallet fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatWon((int) $dash['week_payout']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">이번 주 정산 합계</span>
						</div>
					</div>
					<?= dash_delta_badge($dash['week_payout_delta'] !== null ? (float) $dash['week_payout_delta'] : null) ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-delivery fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span>
							<span class="path4"></span><span class="path5"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['week_orders']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">이번 주 배달 건수</span>
						</div>
					</div>
					<?= dash_delta_badge($dash['week_orders_delta'] !== null ? (float) $dash['week_orders_delta'] : null) ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-time fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['pending_withdrawals']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">출금 신청 대기</span>
						</div>
					</div>
					<?php if ((int) $dash['pending_withdrawals'] > 0) : ?>
					<span class="badge badge-light-warning fs-base"><?= AdminDashboard::formatWon((int) $dash['pending_withdraw_amount']) ?></span>
					<?php else : ?>
					<span class="badge badge-light-success fs-base">대기 없음</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-notification fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatCount((int) $dash['published_notices']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">게시 공지</span>
						</div>
					</div>
					<span class="badge badge-light-primary fs-base">광고 <?= (int) $dash['active_banners'] ?>건</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-minus-circle fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= AdminDashboard::formatWon((int) $dash['month_deductions']) ?></span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">당월 주간 차감 합계</span>
						</div>
					</div>
					<?= dash_delta_badge(
					    $dash['month_deduction_delta'] !== null ? (float) $dash['month_deduction_delta'] : null,
					    true
					) ?>
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
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">플랫폼별 정산 요약</span>
						<span class="text-gray-500 pt-2 fw-semibold fs-6">이번 주 · 일간 정산서 반영분</span>
					</h3>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">수수료 내역</a>
					</div>
				</div>
				<div class="card-body pt-5">
					<?php if ($dash['platform_rows'] === []) : ?>
					<p class="text-muted fs-7 mb-0">이번 주 정산 데이터가 없습니다. 엑셀을 업로드해 주세요.</p>
					<?php else : ?>
					<?php
                    $barClass = ['baemin' => 'bg-primary', 'coupang' => 'bg-success', 'other' => 'bg-secondary'];
				    foreach ($dash['platform_rows'] as $pr) :
				        $plat = (string) $pr['platform'];
				        $bar = $barClass[$plat] ?? 'bg-info';
				        ?>
					<div class="d-flex align-items-center mb-2">
						<span class="fw-semibold text-gray-700 fs-6 w-100px"><?= htmlspecialchars((string) $pr['label'], ENT_QUOTES, 'UTF-8') ?></span>
						<div class="flex-grow-1 mx-3">
							<div class="progress h-8px bg-light">
								<div class="progress-bar <?= htmlspecialchars($bar, ENT_QUOTES, 'UTF-8') ?>" role="progressbar"
									style="width: <?= min(100, max(0, (float) $pr['pct'])) ?>%"
									aria-valuenow="<?= (float) $pr['pct'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
							</div>
						</div>
						<span class="fw-bold text-gray-800 fs-6 w-100px text-end"><?= AdminDashboard::formatWon((int) $pr['amount']) ?></span>
					</div>
					<?php endforeach; ?>
					<div class="separator separator-dashed my-5"></div>
					<div class="d-flex flex-stack">
						<span class="text-gray-600 fw-semibold fs-6">합계</span>
						<span class="text-gray-900 fw-bold fs-4"><?= AdminDashboard::formatWon((int) $dash['platform_total']) ?></span>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
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
								    $detailUrl = admin_url('settlement/upload-detail') . '?id=' . (int) ($u['id'] ?? 0);
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

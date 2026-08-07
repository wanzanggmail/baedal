<?php

declare(strict_types=1);

/**
 * 본사·총판 대시보드 — 대리점 관리 관점.
 * 대리점 계정은 admin/views/dashboard.php(라이더 운영 관점)를 그대로 본다.
 */

require_once INC_PATH . '/AdminDashboard.php';
require_once INC_PATH . '/OrgDashboard.php';

$dash  = OrgDashboard::load();
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
            return '<span class="badge badge-light-secondary fs-base">전주 비교 없음</span>';
        }
        $up  = $delta >= 0;
        $cls = $up ? 'success' : 'danger';

        return '<span class="badge badge-light-' . $cls . ' fs-base">'
            . '<i class="ki-duotone ki-arrow-' . ($up ? 'up' : 'down') . ' fs-5 text-' . $cls . ' ms-n1"><span class="path1"></span><span class="path2"></span></i>'
            . ($up ? '+' : '') . number_format($delta, 1) . '%</span>';
    }
}
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
			<div class="btn btn-sm fw-bold btn-secondary d-flex align-items-center px-4">
				<span class="text-gray-700 fw-bold"><?= $esc((string) $dash['period_label']) ?></span>
				<span class="text-gray-500 fs-8 ms-2">(이번 주)</span>
			</div>
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

	<!--begin::KPI-->
	<div class="row gy-5 gx-xl-10">
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-office-bag fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['agency_count']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">관리 대리점</span>
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
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-people fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['active_riders']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">활성 라이더</span>
					</div>
					<span class="badge badge-light-secondary fs-base">전 대리점 합계</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-wallet fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= $won((int) $dash['week_payout']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">이번 주 정산 합계</span>
					</div>
					<?= org_delta_badge($dash['week_payout_delta'] !== null ? (float) $dash['week_payout_delta'] : null) ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-percentage fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= $won((int) $dash['fee_revenue']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">플랫폼 수수료 <?= $esc((string) $dash['fee_revenue_label']) ?></span>
					</div>
					<?php if ((int) $dash['fee_revenue'] > 0) : ?>
					<span class="badge badge-light-success fs-base">이번 주 카드결제분</span>
					<?php else : ?>
					<span class="badge badge-light-secondary fs-base">이번 주 결제 없음</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-time fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2"><?= $num((int) $dash['pending_count']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">출금 신청 대기</span>
					</div>
					<?php if ((int) $dash['pending_count'] > 0) : ?>
					<span class="badge badge-light-warning fs-base"><?= $won((int) $dash['pending_amount']) ?></span>
					<?php else : ?>
					<span class="badge badge-light-success fs-base">대기 없음</span>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<i class="ki-duotone ki-bank fs-2hx text-gray-600"><span class="path1"></span><span class="path2"></span></i>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= $won((int) $dash['wallet_total']) ?></span>
						<span class="fw-semibold fs-6 text-gray-500">대리점 지갑 총액</span>
					</div>
					<span class="badge badge-light-secondary fs-base">출금 지급 재원</span>
				</div>
			</div>
		</div>
	</div>
	<!--end::KPI-->

	<?php if ($dash['attention'] !== [] || $dash['risk_alerts'] !== []) : ?>
	<!--begin::주의-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<?php if ($dash['attention'] !== []) : ?>
		<div class="col-xl-<?= $dash['risk_alerts'] !== [] ? '6' : '12' ?>">
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
		<?php if ($dash['risk_alerts'] !== []) : ?>
		<div class="col-xl-<?= $dash['attention'] !== [] ? '6' : '12' ?>">
			<div class="card card-flush h-xl-100">
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
		<?php endif; ?>
	</div>
	<!--end::주의-->
	<?php endif; ?>

	<?php if ($isHq && $dash['distributor_rows'] !== []) : ?>
	<!--begin::총판 롤업-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">총판별 요약</h3>
					<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">이번 주 기준 · 하위 대리점 합산</span>
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
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">이번 주 정산액 순 · 잔액/미수금은 현재 시점</span>
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

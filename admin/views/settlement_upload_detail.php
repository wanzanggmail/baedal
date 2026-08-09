<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$uploadId = (int) ($_GET['id'] ?? 0);
if ($uploadId <= 0) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">업로드 ID가 올바르지 않습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$upload = db_row(
    'SELECT u.*, a.name AS operator_name
       FROM settlement_uploads u
       LEFT JOIN admins a ON a.id = u.operator_id
      WHERE u.id = ? AND u.kind = ?',
    [$uploadId, 'daily']
);

if (!$upload) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning">업로드 이력을 찾을 수 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

// 멀티테넌시: 업로드 소유 대리점 스코프 밖이면 차단
if (!Org::canAccessAgency((int) ($upload['agency_id'] ?? 0))) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">이 업로드에 접근할 권한이 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$filterQ     = trim((string) ($_GET['q'] ?? ''));
$filterMatch = trim((string) ($_GET['match'] ?? ''));

$where  = ['dr.upload_id = ?'];
$params = [$uploadId];

if ($filterQ !== '') {
    $where[]  = '(dr.rider_name_raw LIKE ? OR dr.license_id LIKE ? OR r.name LIKE ? OR r.rider_code LIKE ?)';
    $like     = '%' . $filterQ . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterMatch === 'matched') {
    $where[] = 'dr.rider_id IS NOT NULL';
} elseif ($filterMatch === 'unmatched') {
    $where[] = 'dr.rider_id IS NULL';
}

$whereStr = implode(' AND ', $where);

$riders = db_rows(
    "SELECT dr.*,
            r.rider_code, r.name AS rider_name, r.team_code, r.phone, r.status AS rider_status,
            r.is_daily_settlement
       FROM settlement_daily_riders dr
       LEFT JOIN riders r ON r.id = dr.rider_id
      WHERE {$whereStr}
      ORDER BY dr.payout_amount DESC, dr.rider_name_raw ASC",
    $params
);

// 이 업로드에서 매칭된 라이더 중 일일정산(선정산) 대상자 수 — 정산 반영 시 자동 출금될 인원.
$dailyCount = (int) (db_row(
    'SELECT COUNT(*) AS cnt
       FROM settlement_daily_riders dr
       INNER JOIN riders r ON r.id = dr.rider_id
      WHERE dr.upload_id = ? AND r.is_daily_settlement = 1',
    [$uploadId]
)['cnt'] ?? 0);

$totals = db_row(
    'SELECT COUNT(*) AS cnt,
            COALESCE(SUM(order_count), 0) AS sum_orders,
            COALESCE(SUM(gross_amount), 0) AS sum_gross,
            COALESCE(SUM(payout_amount), 0) AS sum_payout,
            SUM(CASE WHEN rider_id IS NOT NULL THEN 1 ELSE 0 END) AS matched_cnt
       FROM settlement_daily_riders
      WHERE upload_id = ?',
    [$uploadId]
) ?: [];

$deductionCount = (int) (db_row(
    'SELECT COUNT(*) AS cnt FROM settlement_weekly_deductions WHERE upload_id = ?',
    [$uploadId]
)['cnt'] ?? 0);

$deductionRows = db_rows(
    'SELECT swd.id, swd.order_date, swd.rider_id, swd.rider_name_raw, swd.deduction_type,
            swd.store_name, swd.amount, swd.registered_entry_id,
            r.name AS rider_name, r.rider_code
       FROM settlement_weekly_deductions swd
       LEFT JOIN riders r ON r.id = swd.rider_id
      WHERE swd.upload_id = ?
      ORDER BY swd.order_date ASC, swd.id ASC',
    [$uploadId]
);

$orderDetailCount = (int) (db_row(
    'SELECT COUNT(*) AS cnt FROM settlement_order_details WHERE upload_id = ?',
    [$uploadId]
)['cnt'] ?? 0);

$hourlyInsCount = (int) (db_row(
    'SELECT COUNT(*) AS cnt FROM settlement_hourly_insurance WHERE upload_id = ?',
    [$uploadId]
)['cnt'] ?? 0);

$supportRow = db_row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM settlement_support_amounts WHERE upload_id = ?",
    [$uploadId]
) ?: [];
$supportCount = (int) ($supportRow['cnt'] ?? 0);
$supportTotal = (int) ($supportRow['total'] ?? 0);

// 팀지역은 정식 컬럼 우선(2026-08-04~), 없으면 예전 stored_path JSON에서 폴백
$meta = json_decode((string) ($upload['stored_path'] ?? ''), true);
$teamRegion = trim((string) ($upload['team_name'] ?? '') . ' ' . (string) ($upload['region_name'] ?? ''));
if ($teamRegion === '' && is_array($meta)) {
    $teamRegion = trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? ''));
}

$platformLabels = [
    'baemin'  => '배달의민족',
    'coupang' => '쿠팡이츠',
    'other'   => '기타',
];
$statusLabels = [
    'uploaded' => ['label' => '업로드됨', 'badge' => 'badge-light-primary'],
    'parsing'  => ['label' => '파싱 중', 'badge' => 'badge-light-warning'],
    'parsed'   => ['label' => '파싱완료', 'badge' => 'badge-light-success'],
    'applied'  => ['label' => '반영완료', 'badge' => 'badge-light-info'],
    'error'    => ['label' => '오류', 'badge' => 'badge-light-danger'],
];
$st = $statusLabels[$upload['status']] ?? ['label' => (string) $upload['status'], 'badge' => 'badge-light'];

$uploadListUrl  = admin_url('settlement/upload');
$historyUrl     = admin_url('settlement/history');
$riderDetailUrl = admin_url('riders/detail');
$riderDetailUrl .= str_contains($riderDetailUrl, '?') ? '&id=' : '?id=';

$detailBaseUrl = admin_url('settlement/upload-detail');
$detailBaseUrl .= str_contains($detailBaseUrl, '?') ? '&' : '?';
$detailBaseUrl .= 'id=' . $uploadId;

$fmtWon = static fn (int $n): string => number_format($n) . '원';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">
				정산 업로드 상세
			</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars($uploadListUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">엑셀 업로드</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900"><?= htmlspecialchars((string) $upload['settlement_date'], ENT_QUOTES, 'UTF-8') ?></li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<?php if (in_array(($upload['status'] ?? ''), ['parsed', 'applied'], true) && SettlementLedger::tableExists()) : ?>
			<button type="button" class="btn btn-sm btn-success fw-bold" id="btn_settlement_apply" data-upload-id="<?= (int) $uploadId ?>">
				<?= ($upload['status'] ?? '') === 'applied' ? '정산 재반영 (신규 매칭분)' : '정산 반영 · 수수료·지갑' ?>
			</button>
			<?php endif; ?>
			<a href="<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_upload_export.php?id=' . $uploadId, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-success fw-bold">
				<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
				엑셀 다운로드
			</a>
			<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">수수료 내역</a>
			<a href="<?= htmlspecialchars($uploadListUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">업로드</a>
			<a href="<?= htmlspecialchars($historyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">전체 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ((string) $upload['platform'] !== 'baemin' && (int) $upload['ok_rows'] > 0 && $orderDetailCount === 0) : ?>
	<div class="alert alert-warning d-flex align-items-center p-5 mb-6">
		<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>건단위(오더상세) 데이터가 없습니다.</strong> 라이더 요약은 정상 저장됐지만, 원본 파일에서 "오더별" 상세 탭을 찾지 못한 것으로 보입니다.
			탭 이름·헤더가 바뀌었는지 원본 파일을 확인한 뒤 다시 업로드해 주세요.
		</div>
	</div>
	<?php endif; ?>

	<?php if ($dailyCount > 0 && in_array(($upload['status'] ?? ''), ['parsed', 'applied'], true)) : ?>
	<div class="alert bg-light-success d-flex align-items-center p-5 mb-6">
		<i class="ki-duotone ki-wallet fs-2hx text-success me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>일일정산 대상 <?= number_format($dailyCount) ?>명</strong>이 포함되어 있습니다.
			「<?= ($upload['status'] ?? '') === 'applied' ? '정산 재반영' : '정산 반영 · 수수료·지갑' ?>」을 누르면 이 라이더들은 <strong>보증금을 제외한 금액이 곧바로 출금(실제 이체)</strong>됩니다.
			계좌 미등록·출금보류이거나 보증금이 아직 안 찬 라이더는 자동으로 건너뛰며, 반영 후 사유가 표시됩니다.
			<?php if (($upload['status'] ?? '') === 'applied') : ?>
			<span class="d-block mt-1">계좌를 뒤늦게 등록했다면 <strong>「정산 재반영」을 다시 누르면 자동출금이 재시도</strong>됩니다
			(이미 지급된 정산분은 중복 출금되지 않습니다).</span>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<!--begin::요약-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">귀속일 · 플랫폼</div>
					<div class="fw-bold fs-3 text-gray-900"><?= htmlspecialchars((string) $upload['settlement_date'], ENT_QUOTES, 'UTF-8') ?></div>
					<div class="text-gray-700 fs-6 mt-1"><?= htmlspecialchars($platformLabels[$upload['platform']] ?? $upload['platform'], ENT_QUOTES, 'UTF-8') ?></div>
					<?php if ($teamRegion !== '') : ?>
						<div class="text-muted fs-7 mt-2"><?= htmlspecialchars($teamRegion, ENT_QUOTES, 'UTF-8') ?></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">라이더 / 매칭</div>
					<div class="fw-bold fs-3 text-gray-900"><?= number_format((int) $upload['total_rows']) ?>명</div>
					<div class="text-success fs-7 mt-1">매칭 <?= number_format((int) $upload['ok_rows']) ?>명</div>
					<?php if ((int) $upload['error_rows'] > 0) : ?>
						<div class="text-warning fs-7">미매칭 <?= number_format((int) $upload['error_rows']) ?>명</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">합계 (저장된 행 기준)</div>
					<div class="fw-bold fs-4 text-gray-900"><?= $fmtWon((int) $totals['sum_payout']) ?></div>
					<div class="text-muted fs-7 mt-1">정산예정 <?= $fmtWon((int) $totals['sum_gross']) ?></div>
					<div class="text-muted fs-7">오더 <?= number_format((int) $totals['sum_orders']) ?>건</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">파일 · 상태</div>
					<div class="text-gray-800 fs-7 text-break mb-2"><?= htmlspecialchars((string) $upload['original_filename'], ENT_QUOTES, 'UTF-8') ?></div>
					<span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span>
					<div class="text-muted fs-8 mt-2">
						차감 <?= number_format($deductionCount) ?>건 ·
						<?php if ($orderDetailCount > 0) : ?>
						<?php
						$odUrl = admin_url('settlement/order-details');
						$odUrl .= (str_contains($odUrl, '?') ? '&' : '?')
							. 'upload_id=' . $uploadId
							. '&from=' . urlencode((string) $upload['settlement_date'])
							. '&to=' . urlencode((string) $upload['settlement_date']);
						?>
						<a href="<?= htmlspecialchars($odUrl, ENT_QUOTES, 'UTF-8') ?>">오더상세 <?= number_format($orderDetailCount) ?>건</a> ·
						<?php else : ?>
						오더상세 <?= number_format($orderDetailCount) ?>건 ·
						<?php endif; ?>
						시간제보험 <?= number_format($hourlyInsCount) ?>건<?php if ($supportCount > 0): ?> · 지원금 <?= number_format($supportCount) ?>건(<?= $fmtWon($supportTotal) ?>)<?php endif; ?>
					</div>
					<div class="text-muted fs-8"><?= htmlspecialchars((string) ($upload['operator_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(substr((string) $upload['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></div>
				</div>
			</div>
		</div>
	</div>
	<!--end::요약-->

	<!--begin::라이더 목록-->
	<div class="card card-flush">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title">
				<form method="get" action="<?= htmlspecialchars(admin_url('settlement/upload-detail'), ENT_QUOTES, 'UTF-8') ?>" class="d-flex flex-wrap align-items-center gap-3">
					<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
						<input type="hidden" name="route" value="settlement/upload-detail" />
					<?php endif; ?>
					<input type="hidden" name="id" value="<?= $uploadId ?>" />
					<div class="d-flex align-items-center position-relative">
						<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
						<input type="text" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>"
							class="form-control form-control-solid w-250px ps-12" placeholder="이름·라이선스·코드" />
					</div>
					<select name="match" class="form-select form-select-solid w-150px">
						<option value=""<?= $filterMatch === '' ? ' selected' : '' ?>>전체</option>
						<option value="matched"<?= $filterMatch === 'matched' ? ' selected' : '' ?>>매칭됨</option>
						<option value="unmatched"<?= $filterMatch === 'unmatched' ? ' selected' : '' ?>>미매칭</option>
					</select>
					<button type="submit" class="btn btn-light-primary btn-sm">검색</button>
					<?php if ($filterQ !== '' || $filterMatch !== '') : ?>
						<a href="<?= htmlspecialchars($detailBaseUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light btn-sm">초기화</a>
					<?php endif; ?>
				</form>
			</div>
			<div class="card-toolbar">
				<span class="text-muted fs-7">표시 <?= number_format(count($riders)) ?>명</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted bg-light">
							<th class="min-w-40px">#</th>
							<th class="min-w-120px">엑셀 이름</th>
							<th class="min-w-100px">라이더</th>
							<th class="min-w-80px">정산유형</th>
							<th class="min-w-110px">라이선스 ID</th>
							<th class="min-w-60px text-end">오더</th>
							<th class="min-w-100px text-end">정산예정</th>
							<th class="min-w-100px text-end">실지급</th>
							<th class="min-w-80px text-end">픽업</th>
							<th class="min-w-80px text-end">배달</th>
							<th class="min-w-80px text-end">지역단가</th>
							<th class="min-w-70px">매칭</th>
							<th class="min-w-70px"></th>
						</tr>
					</thead>
					<tbody>
					<?php if ($riders === []) : ?>
						<tr>
							<td colspan="13" class="text-center text-muted py-10">조건에 맞는 라이더가 없습니다.</td>
						</tr>
					<?php else :
					    $i = 0;
					    foreach ($riders as $row) :
					        $i++;
					        $matched = $row['rider_id'] !== null && (int) $row['rider_id'] > 0;
					        $payout  = (int) $row['payout_amount'];
					        $payoutClass = $payout < 0 ? 'text-danger' : 'text-gray-900';
					        ?>
						<tr>
							<td class="text-muted"><?= $i ?></td>
							<td>
								<span class="fw-semibold text-gray-900"><?= htmlspecialchars((string) $row['rider_name_raw'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td>
								<?php if ($matched) : ?>
									<a href="<?= htmlspecialchars($riderDetailUrl . (int) $row['rider_id'], ENT_QUOTES, 'UTF-8') ?>"
									   class="text-gray-900 text-hover-primary fw-bold">
										<?= htmlspecialchars((string) ($row['rider_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
									</a>
									<?php if (!empty($row['rider_code'])) : ?>
										<div class="text-muted fs-8"><?= htmlspecialchars((string) $row['rider_code'], ENT_QUOTES, 'UTF-8') ?></div>
									<?php endif; ?>
								<?php else : ?>
									<span class="text-muted">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if (!$matched) : ?>
									<span class="text-muted">—</span>
								<?php elseif (!empty($row['is_daily_settlement'])) : ?>
									<span class="badge badge-light-success" title="정산 반영 시 보증금을 제외한 금액이 자동으로 출금됩니다.">일일정산</span>
								<?php else : ?>
									<span class="badge badge-light-secondary">주정산</span>
								<?php endif; ?>
							</td>
							<td class="font-monospace fs-7"><?= htmlspecialchars((string) $row['license_id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end"><?= number_format((int) $row['order_count']) ?></td>
							<td class="text-end"><?= $fmtWon((int) $row['gross_amount']) ?></td>
							<td class="text-end fw-bold <?= $payoutClass ?>"><?= $fmtWon($payout) ?></td>
							<td class="text-end text-muted fs-7"><?= $fmtWon((int) $row['fee_pickup']) ?></td>
							<td class="text-end text-muted fs-7"><?= $fmtWon((int) $row['fee_delivery']) ?></td>
							<td class="text-end text-muted fs-7"><?= $fmtWon((int) $row['fee_area']) ?></td>
							<td>
								<?php if ($matched) : ?>
									<span class="badge badge-light-success">매칭</span>
									<button type="button" class="btn btn-sm btn-light py-1 px-2 fs-8 ms-1 btn-reg"
										data-row="<?= (int) $row['id'] ?>"
										data-license="<?= htmlspecialchars((string) $row['license_id'], ENT_QUOTES, 'UTF-8') ?>"
										data-name="<?= htmlspecialchars((string) $row['rider_name_raw'], ENT_QUOTES, 'UTF-8') ?>"
										data-platform="<?= htmlspecialchars((string) ($row['platform'] ?? $upload['platform']), ENT_QUOTES, 'UTF-8') ?>"
										data-matched="1"
										data-current-name="<?= htmlspecialchars((string) ($row['rider_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">재연결</button>
								<?php else : ?>
									<span class="badge badge-light-warning">미매칭</span>
									<button type="button" class="btn btn-sm btn-light-danger py-1 px-2 fs-8 ms-1 btn-reg"
										data-row="<?= (int) $row['id'] ?>"
										data-license="<?= htmlspecialchars((string) $row['license_id'], ENT_QUOTES, 'UTF-8') ?>"
										data-name="<?= htmlspecialchars((string) $row['rider_name_raw'], ENT_QUOTES, 'UTF-8') ?>"
										data-platform="<?= htmlspecialchars((string) ($row['platform'] ?? $upload['platform']), ENT_QUOTES, 'UTF-8') ?>"
										data-matched="0">연결/등록</button>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="btn btn-sm btn-light-primary dr-detail-btn"
									data-id="<?= (int) $row['id'] ?>"
									data-name="<?= htmlspecialchars((string) $row['rider_name_raw'], ENT_QUOTES, 'UTF-8') ?>">상세</button>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<!--end::라이더 목록-->

	<?php if ($deductionRows !== []) : ?>
	<!--begin::차감내역-->
	<div class="card card-flush mt-8">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title">
				<h3 class="fw-bold m-0">차감내역 (엑셀 원본)</h3>
			</div>
			<div class="card-toolbar">
				<span class="text-muted fs-7"><?= number_format(count($deductionRows)) ?>건</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="alert bg-light-warning fs-8 p-3 mb-4">업로드된 차감내역을 확인하고 "등록"하면 해당 라이더의 주문일자 정산 반영 시 자동으로 차감됩니다.</div>
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted bg-light">
							<th class="min-w-100px">주문일자</th>
							<th class="min-w-100px">라이더</th>
							<th class="min-w-100px">구분</th>
							<th class="min-w-140px">스토어명</th>
							<th class="min-w-100px text-end">금액</th>
							<th class="min-w-90px">상태</th>
						</tr>
					</thead>
					<tbody id="ded_tbody">
					<?php foreach ($deductionRows as $d) :
						$dMatched = $d['rider_id'] !== null;
						$registered = (int) ($d['registered_entry_id'] ?? 0) > 0;
					?>
						<tr data-id="<?= (int) $d['id'] ?>" data-matched="<?= $dMatched ? '1' : '0' ?>" data-registered="<?= $registered ? '1' : '0' ?>">
							<td class="text-muted"><?= htmlspecialchars((string) $d['order_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<?php if ($dMatched) : ?>
									<span class="fw-semibold text-gray-900"><?= htmlspecialchars((string) $d['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
									<div class="text-muted fs-8"><?= htmlspecialchars((string) $d['rider_code'], ENT_QUOTES, 'UTF-8') ?></div>
								<?php else : ?>
									<span class="text-muted"><?= htmlspecialchars((string) $d['rider_name_raw'], ENT_QUOTES, 'UTF-8') ?></span>
									<div><span class="badge badge-light-warning fs-8">미매칭</span></div>
								<?php endif; ?>
							</td>
							<td><?= htmlspecialchars((string) $d['deduction_type'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-muted fs-7"><?= htmlspecialchars((string) $d['store_name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold"><?= $fmtWon(abs((int) $d['amount'])) ?></td>
							<td>
								<?php if ($registered) : ?>
									<button type="button" class="btn btn-sm btn-light-danger ded-unregister">등록취소</button>
								<?php elseif ($dMatched) : ?>
									<button type="button" class="btn btn-sm btn-light-primary ded-register">등록</button>
								<?php else : ?>
									<span class="text-muted fs-8">라이더 매칭 필요</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<!--end::차감내역-->
	<?php endif; ?>

	<!--begin::원본 데이터 상세 모달-->
	<div class="modal fade" id="kt_dr_detail_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title" id="dr_detail_title">정산 원본 데이터 상세</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body fs-7" id="dr_detail_body">
					<div class="text-center text-muted py-10">불러오는 중…</div>
				</div>
				<div class="modal-footer">
					<a href="#" id="dr_detail_rider_link" class="btn btn-primary d-none" target="_self">라이더 상세 보기</a>
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">닫기</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::원본 데이터 상세 모달-->

	<!--begin::미매칭 라이더 연결/등록 모달-->
	<div class="modal fade" id="kt_quick_rider_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold fs-4">라이더 연결/등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-6 px-lg-8">
					<div id="qrRematchWarn" class="alert alert-warning d-none mb-4"></div>
					<div id="quickRiderAlert" class="d-none mb-4"></div>
					<input type="hidden" id="qrRowId" />
					<input type="hidden" id="qrLicense" />
					<input type="hidden" id="qrPlatform" />
					<input type="hidden" id="qrForce" value="0" />

					<ul class="nav nav-tabs nav-line-tabs fs-7 mb-5" id="qrModeTabs">
						<li class="nav-item"><a class="nav-link active" data-mode="create" href="#">신규 라이더 등록</a></li>
						<li class="nav-item"><a class="nav-link" data-mode="link" href="#">기존 라이더에 연결</a></li>
					</ul>

					<div id="qrCreatePane">
						<div class="mb-4">
							<label class="form-label required">이름</label>
							<input type="text" class="form-control form-control-solid" id="qrName" maxlength="50" />
						</div>
						<div class="mb-4">
							<label class="form-label">휴대전화</label>
							<input type="text" class="form-control form-control-solid" id="qrPhone" maxlength="20" placeholder="01012345678" />
						</div>
						<div class="row">
							<div class="col-md-6 mb-4">
								<label class="form-label">로그인 ID</label>
								<input type="text" class="form-control form-control-solid" id="qrLoginId" maxlength="60" placeholder="비우면 휴대전화번호로 자동 생성" autocomplete="off" />
							</div>
							<div class="col-md-6 mb-4">
								<label class="form-label">초기 비밀번호</label>
								<input type="text" class="form-control form-control-solid" id="qrPassword" value="0000" readonly />
								<div class="form-text fs-9">최초 로그인 시 라이더가 직접 변경합니다.</div>
							</div>
						</div>
						<div class="form-text">최소 정보로 등록하고 정산서 ID <code id="qrLicenseLabel">-</code> 를 연동합니다.</div>
					</div>

					<div id="qrLinkPane" class="d-none">
						<div class="alert bg-light-info fs-8 p-3 mb-4">기존 라이더에 <strong id="qrLinkPlatformLabel">이 플랫폼</strong> ID(<code id="qrLinkLicenseLabel">-</code>)를 연결합니다.</div>
						<div class="input-group mb-3">
							<input type="text" class="form-control form-control-solid" id="qrSearchInput" placeholder="이름·코드로 검색" />
							<button type="button" class="btn btn-light-primary" id="qrSearchBtn">검색</button>
						</div>
						<div id="qrSearchResults" class="d-flex flex-column gap-2" style="max-height:240px;overflow-y:auto"></div>
					</div>

					<div class="alert bg-light-primary fs-8 p-3 mt-4 mb-0">연결/등록 후 이 업로드의 같은 엑셀 이름으로 된 다른 원본 데이터(오더상세·시간제보험·지원금·차감내역)도 함께 매칭되고, 상단 「정산 반영」을 다시 누르면 새로 매칭된 건만 추가로 반영됩니다.</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="qrSubmitBtn">등록 및 매칭</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::미매칭 라이더 연결/등록 모달-->

	<script>
	(function () {
		'use strict';
		var platformLabels = { baemin: '배달의민족', coupang: '쿠팡이츠', other: '기타' };
		var registerApiUrl = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/settlement_register_rider.php', JSON_UNESCAPED_UNICODE) ?>;
		var uploadId = <?= (int) $uploadId ?>;
		var agencyId = <?= (int) $upload['agency_id'] ?>;
		var quickModalEl = document.getElementById('kt_quick_rider_modal');
		var activeBtn = null;

		function escHtml(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function randomPw() { return Math.random().toString(36).slice(2, 8); }

		function setQrMode(mode) {
			document.getElementById('qrCreatePane').classList.toggle('d-none', mode !== 'create');
			document.getElementById('qrLinkPane').classList.toggle('d-none', mode !== 'link');
			document.getElementById('qrSubmitBtn').classList.toggle('d-none', mode !== 'create');
			document.querySelectorAll('#qrModeTabs .nav-link').forEach(function (a) {
				a.classList.toggle('active', a.getAttribute('data-mode') === mode);
			});
		}
		document.querySelectorAll('#qrModeTabs .nav-link').forEach(function (a) {
			a.addEventListener('click', function (ev) { ev.preventDefault(); setQrMode(a.getAttribute('data-mode')); });
		});

		function onRegBtnClick() {
			var b = this;
			activeBtn = b;
			var license = b.getAttribute('data-license') || '';
			var name = b.getAttribute('data-name') || '';
			var platform = b.getAttribute('data-platform') || '';
			var matched = b.getAttribute('data-matched') === '1';
			var currentName = b.getAttribute('data-current-name') || '';
			document.getElementById('qrRowId').value = b.getAttribute('data-row');
			document.getElementById('qrLicense').value = license;
			document.getElementById('qrPlatform').value = platform;
			document.getElementById('qrForce').value = matched ? '1' : '0';
			document.getElementById('qrLicenseLabel').textContent = license || '(없음)';
			document.getElementById('qrName').value = name;
			document.getElementById('qrPhone').value = '';
			document.getElementById('qrLoginId').value = '';
			document.getElementById('qrPassword').value = '0000';
			var al = document.getElementById('quickRiderAlert'); al.className = 'd-none mb-4'; al.textContent = '';
			var warn = document.getElementById('qrRematchWarn');
			if (matched) {
				warn.className = 'alert alert-warning mb-4';
				warn.textContent = '현재 "' + currentName + '" 라이더로 매칭돼 있습니다. 다른 라이더로 재연결하면 엑셀 이름이 같은 다른 원본 데이터도 함께 옮겨집니다. 이미 정산 반영된 건은 재연결이 막힙니다.';
			} else {
				warn.className = 'd-none mb-4';
				warn.textContent = '';
			}
			document.getElementById('qrLinkPlatformLabel').textContent = platformLabels[platform] || platform;
			document.getElementById('qrLinkLicenseLabel').textContent = license || '(없음)';
			document.getElementById('qrSearchInput').value = name || '';
			document.getElementById('qrSearchResults').innerHTML = '';
			setQrMode(matched ? 'link' : 'create');
			bootstrap.Modal.getOrCreateInstance(quickModalEl).show();
		}
		function rebindRegBtn(b) {
			if (b) b.addEventListener('click', onRegBtnClick);
		}
		document.querySelectorAll('.btn-reg').forEach(rebindRegBtn);

		function markRowMatched(riderName, riderCode) {
			if (!activeBtn) return;
			var td = activeBtn.closest('td');
			var tr = activeBtn.closest('tr'); // td.innerHTML을 바꾸면 activeBtn이 DOM에서 떨어져 나가 이후 closest()가 null이 되므로 미리 잡아둔다.
			var row = activeBtn.getAttribute('data-row');
			var license = activeBtn.getAttribute('data-license') || '';
			var name = activeBtn.getAttribute('data-name') || '';
			var platform = activeBtn.getAttribute('data-platform') || '';
			td.innerHTML = '<span class="badge badge-light-success">매칭</span>' +
				'<button type="button" class="btn btn-sm btn-light py-1 px-2 fs-8 ms-1 btn-reg" data-row="' + escHtml(row) +
				'" data-license="' + escHtml(license) + '" data-name="' + escHtml(name) + '" data-platform="' + escHtml(platform) +
				'" data-matched="1" data-current-name="' + escHtml(riderName) + '">재연결</button>';
			rebindRegBtn(td.querySelector('.btn-reg'));
			var nameTd = tr ? tr.querySelector('td:nth-child(3)') : null;
			if (nameTd) {
				nameTd.innerHTML = '<span class="fw-bold">' + escHtml(riderName) + '</span><div class="text-muted fs-8">' + escHtml(riderCode) + '</div>';
			}
		}

		async function qrSearch() {
			var q = document.getElementById('qrSearchInput').value.trim();
			var platform = document.getElementById('qrPlatform').value;
			var box = document.getElementById('qrSearchResults');
			box.innerHTML = '<div class="text-muted fs-8">검색 중…</div>';
			try {
				var resp = await fetch(registerApiUrl + '?q=' + encodeURIComponent(q) + '&platform=' + encodeURIComponent(platform), { credentials: 'same-origin' });
				var data = await resp.json();
				if (!data.ok) throw new Error(data.message || '검색 실패');
				if (!data.riders.length) { box.innerHTML = '<div class="text-muted fs-8">결과가 없습니다.</div>'; return; }
				box.innerHTML = data.riders.map(function (r) {
					var has = r.platform_ext ? '<span class="badge badge-light-warning fs-8 ms-1">기존:' + escHtml(r.platform_ext) + '</span>' : '';
					return '<div class="d-flex align-items-center justify-content-between border border-gray-300 rounded p-2">' +
						'<div><span class="fw-bold">' + escHtml(r.name) + '</span> <span class="text-muted fs-8 font-monospace">' + escHtml(r.rider_code) + '</span>' + has + '</div>' +
						'<button type="button" class="btn btn-sm btn-light-primary qr-link-btn" data-id="' + r.id + '">연결</button></div>';
				}).join('');
			} catch (e) { box.innerHTML = '<div class="text-danger fs-8">' + escHtml(e.message) + '</div>'; }
		}
		document.getElementById('qrSearchBtn').addEventListener('click', qrSearch);
		document.getElementById('qrSearchInput').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); qrSearch(); } });
		document.getElementById('qrSearchResults').addEventListener('click', async function (ev) {
			var b = ev.target.closest('.qr-link-btn'); if (!b) return;
			var al = document.getElementById('quickRiderAlert');
			var force = document.getElementById('qrForce').value === '1';
			if (force && !confirm('현재 매칭을 이 라이더로 교정할까요?')) return;
			b.disabled = true;
			try {
				var resp = await fetch(registerApiUrl, {
					method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
					body: JSON.stringify({
						action: 'link', rider_id: Number(b.getAttribute('data-id')), agency_id: agencyId,
						platform: document.getElementById('qrPlatform').value, license_id: document.getElementById('qrLicense').value,
						upload_id: uploadId, row_id: Number(document.getElementById('qrRowId').value), force: force,
					})
				});
				var data = await resp.json();
				if (!data.ok) throw new Error(data.message || '연결 실패');
				markRowMatched(data.rider.name, data.rider.rider_code);
				bootstrap.Modal.getInstance(quickModalEl).hide();
			} catch (e) { al.className = 'alert alert-danger mb-4'; al.textContent = e.message || '연결 실패'; b.disabled = false; }
		});

		document.getElementById('qrSubmitBtn').addEventListener('click', async function () {
			var al = document.getElementById('quickRiderAlert');
			var payload = {
				action: 'create',
				agency_id: agencyId,
				platform: document.getElementById('qrPlatform').value,
				license_id: document.getElementById('qrLicense').value,
				name: document.getElementById('qrName').value.trim(),
				phone: document.getElementById('qrPhone').value.trim(),
				login_id: document.getElementById('qrLoginId').value.trim(),
				password: document.getElementById('qrPassword').value,
				upload_id: uploadId,
				row_id: Number(document.getElementById('qrRowId').value),
				force: document.getElementById('qrForce').value === '1',
			};
			if (!payload.name) { al.className = 'alert alert-danger mb-4'; al.textContent = '이름을 입력하세요.'; return; }
			if (payload.force && !confirm('현재 매칭을 새로 등록하는 라이더로 교정할까요?')) return;
			var submitBtn = this;
			submitBtn.disabled = true;
			try {
				var resp = await fetch(registerApiUrl, {
					method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
					body: JSON.stringify(payload)
				});
				var data = await resp.json();
				if (!data.ok) throw new Error(data.message || '등록 실패');
				markRowMatched(data.rider.name, data.rider.rider_code);
				bootstrap.Modal.getInstance(quickModalEl).hide();
			} catch (e) { al.className = 'alert alert-danger mb-4'; al.textContent = e.message || '등록 실패'; }
			submitBtn.disabled = false;
		});
	})();
	</script>

	<script>
	(function () {
		var API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/settlement_daily_rider_detail.php', JSON_UNESCAPED_UNICODE) ?>;
		var RIDER_DETAIL_URL = <?= json_encode($riderDetailUrl, JSON_UNESCAPED_UNICODE) ?>;
		var modalEl = document.getElementById('kt_dr_detail_modal');
		var titleEl = document.getElementById('dr_detail_title');
		var body = document.getElementById('dr_detail_body');
		var riderLink = document.getElementById('dr_detail_rider_link');

		function won(n) { return (Number(n) || 0).toLocaleString('ko-KR') + '원'; }
		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function plusWon(n) { return '+ ' + won(n); }
		function minusWon(n) { return '− ' + won(Math.abs(Number(n) || 0)); }
		function moneyRow(label, valueHtml, opts) {
			opts = opts || {};
			var wrap = opts.wrapClass || 'd-flex justify-content-between align-items-start py-2';
			if (opts.border) wrap += ' border-bottom';
			var note = opts.note ? '<span class="d-block text-muted fs-8 fw-normal">' + esc(opts.note) + '</span>' : '';
			return '<div class="' + wrap + '"><span>' + esc(label) + note + '</span>' +
				'<span class="fw-semibold text-nowrap ms-4">' + valueHtml + '</span></div>';
		}
		function itemTable(title, items, sign, footerLabel) {
			var active = [];
			items.forEach(function (it) {
				if ((Number(it.amt) || 0) !== 0 || (it.cnt || 0) > 0) active.push(it);
			});
			if (!active.length) return '';
			var sum = 0;
			var html = '<div class="fw-bold text-gray-800 mb-2">' + esc(title) + '</div>';
			html += '<table class="table table-row-bordered align-middle gy-2 mb-5">';
			html += '<thead><tr class="fw-bold text-muted fs-8"><th>항목</th><th class="text-end">건수</th><th class="text-end">금액</th></tr></thead><tbody>';
			active.forEach(function (it) {
				var amt = Number(it.amt) || 0;
				sum += amt;
				var amtClass = sign === '-' ? 'text-danger' : 'text-gray-900';
				var amtText = sign === '-' ? minusWon(amt) : plusWon(amt);
				html += '<tr><td class="text-gray-800">' + esc(it.label);
				if (it.note) html += '<span class="d-block text-muted fs-8 fw-normal">' + esc(it.note) + '</span>';
				html += '</td>' +
					'<td class="text-end text-muted">' + (it.cnt == null ? '—' : Number(it.cnt).toLocaleString('ko-KR') + '건') + '</td>' +
					'<td class="text-end fw-semibold ' + amtClass + '">' + amtText + '</td></tr>';
			});
			html += '</tbody><tfoot><tr class="fw-bold bg-light"><td>' + esc(footerLabel || '소계') + '</td><td></td>' +
				'<td class="text-end ' + (sign === '-' ? 'text-danger' : '') + '">' +
				(sign === '-' ? minusWon(sum) : plusWon(sum)) + '</td></tr></tfoot></table>';
			return html;
		}
		function render(d) {
			var matchBadge = d.matched
				? '<span class="badge badge-light-success">매칭</span>'
				: '<span class="badge badge-light-warning">미매칭</span>';
			var gross = Number(d.gross_amount) || 0;
			var payout = Number(d.payout_amount) || 0;
			var earn = Number(d.earn_amount) || 0;
			var vat = Number(d.vat_amount) || 0;
			var hourly = Number(d.fee_hourly != null ? d.fee_hourly : d.hourly_insurance) || 0;
			var other = Number(d.fee_other) || 0;
			var feeTotal = Number(d.fee_total);
			if (isNaN(feeTotal)) feeTotal = Math.max(0, (gross || payout) - payout);
			var support = Number(d.support_amount) || 0;
			var credit = gross > 0 ? gross : payout;

			var html = '<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-5 pb-4 border-bottom">';
			html += '<div>';
			html += '<div class="fs-4 fw-bold text-gray-900 mb-1">' + esc(d.rider_name_raw || '이름 없음') + '</div>';
			html += '<div class="text-muted fs-8">' + esc(d.settlement_date) + ' · ' + esc(d.platform_label);
			if (d.license_id) html += ' · 라이선스 ' + esc(d.license_id);
			html += ' · 오더 ' + (Number(d.order_count) || 0).toLocaleString('ko-KR') + '건';
			html += '</div></div><div class="text-end">' + matchBadge;
			if (d.matched && d.rider) {
				html += '<div class="fs-7 fw-semibold text-gray-800 mt-1">' + esc(d.rider.name) + '</div>';
				html += '<div class="text-muted fs-8">' + esc(d.rider.rider_code);
				if (d.rider.phone) html += ' · ' + esc(d.rider.phone);
				html += '</div>';
				html += '<div class="text-muted fs-8">' + esc(d.rider.status_label) + '</div>';
			} else {
				html += '<div class="text-muted fs-8 mt-1">연결된 라이더 없음</div>';
			}
			html += '</div></div>';

			html += '<div class="bg-light rounded p-5 mb-6">';
			html += moneyRow('정산예정', '<span class="text-gray-900">' + plusWon(credit) + '</span>', {
				border: true, note: vat > 0 ? '배달 구성 + 부가세 10%' : '엑셀 총 정산 금액',
			});
			html += moneyRow('수수료·공제', '<span class="text-danger">' + minusWon(feeTotal) + '</span>', {
				border: true, note: '정산예정 − 실지급',
			});
			html += moneyRow('실지급', '<span class="fs-2 fw-bold ' + (payout < 0 ? 'text-danger' : 'text-primary') + '">' + won(payout) + '</span>', {
				wrapClass: 'd-flex justify-content-between align-items-center pt-3',
			});
			html += '<div class="text-muted fs-8 mt-3">정산예정 ' + won(credit) + ' − 공제 ' + won(feeTotal) + ' = 실지급 ' + won(payout) + '</div>';
			if (support > 0) {
				html += '<div class="text-success fs-8 mt-2">+ 지원금 ' + won(support) + ' · 정산 반영 시 실지급에 가산됩니다</div>';
			}
			html += '</div>';

			var earnItems = [
				{ label: '픽업 비용', amt: d.fee_pickup },
				{ label: '배달 비용', amt: d.fee_delivery },
				{ label: '지역 단가', amt: d.fee_area },
				{ label: '거리구간 할증', cnt: d.fee_dist_cnt, amt: d.fee_dist_surge },
				{ label: '픽업콜 할증', cnt: d.fee_pickup_cnt, amt: d.fee_pickup_surge },
				{ label: '도착콜 할증', cnt: d.fee_dest_cnt, amt: d.fee_dest_surge },
				{ label: '기상할증', cnt: d.fee_weather_cnt, amt: d.fee_weather },
				{ label: '프로모션 1', amt: d.fee_promo1 },
				{ label: '프로모션 2', amt: d.fee_promo2 },
				{ label: '프로모션 3', amt: d.fee_promo3 },
				{ label: '프로모션 4', amt: d.fee_promo4 },
			];
			if (vat > 0) {
				earnItems.push({ label: '부가세 10%', amt: vat, note: '구성 합계 ' + won(earn) + ' × 10%' });
			}
			var earnHtml = itemTable('① 정산예정이 나온 과정', earnItems, '+', '정산예정');
			html += earnHtml || '<div class="fw-bold text-gray-800 mb-2">① 정산예정이 나온 과정</div><div class="text-muted fs-8 mb-5">구성 항목 없음 · 엑셀 정산예정 ' + won(credit) + '</div>';

			var deductItems = [];
			if (vat > 0) deductItems.push({ label: '부가세 10%', amt: vat, note: '정산예정에 포함, 실지급에서는 빠짐' });
			if (hourly > 0) deductItems.push({ label: '시간제보험', amt: hourly });
			if (other > 0) {
				deductItems.push({
					label: '기타 공제',
					amt: other,
					note: '엑셀 종합탭 고용보험·산재보험·원천세 등 (항목별 값은 현재 미저장)',
				});
			}
			if (deductItems.length) {
				html += itemTable('② 수수료·공제 내역', deductItems, '-', '공제 합계');
			} else {
				html += '<div class="fw-bold text-gray-800 mb-2">② 수수료·공제 내역</div>';
				html += '<div class="text-muted fs-8 mb-5">공제 항목 없음</div>';
			}

			var weekly = d.weekly_deductions || [];
			if (weekly.length) {
				html += '<div class="alert bg-light-warning d-flex flex-column p-4 mb-5">';
				html += '<div class="fw-bold text-gray-800 mb-2">정산 반영 시 추가 차감 (엑셀 차감내역 탭)</div>';
				html += '<div class="text-muted fs-8 mb-3">아래 금액은 엑셀 실지급에 아직 빠지지 않았고, 「정산 반영」할 때 따로 차감됩니다.</div>';
				weekly.forEach(function (w) {
					html += '<div class="d-flex justify-content-between py-1"><span>' + esc(w.type);
					if (w.store_name) html += ' <span class="text-muted">· ' + esc(w.store_name) + '</span>';
					html += '</span><span class="fw-semibold text-danger">' + minusWon(w.amount) + '</span></div>';
				});
				html += '</div>';
			}

			if (d.original_filename) {
				html += '<div class="text-muted fs-8">원본 파일 · ' + esc(d.original_filename) + '</div>';
			}

			body.innerHTML = html;

			if (d.matched && d.rider) {
				riderLink.href = RIDER_DETAIL_URL + d.rider.id;
				riderLink.classList.remove('d-none');
			} else {
				riderLink.classList.add('d-none');
			}
		}

		document.querySelectorAll('.dr-detail-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-id');
				var name = btn.getAttribute('data-name') || '';
				titleEl.textContent = name ? ('정산 원본 · ' + name) : '정산 원본 데이터 상세';
				body.innerHTML = '<div class="text-center text-muted py-10">불러오는 중…</div>';
				riderLink.classList.add('d-none');
				bootstrap.Modal.getOrCreateInstance(modalEl).show();
				fetch(API + '?id=' + id, { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '조회 실패');
						render(res.row);
					})
					.catch(function (e) {
						body.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
					});
			});
		});
	})();
	</script>

	<script>
	(function () {
		var tbody = document.getElementById('ded_tbody');
		if (!tbody) return;
		var API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/deduction_register.php', JSON_UNESCAPED_UNICODE) ?>;

		function post(payload) {
			return fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(payload),
			}).then(function (r) { return r.json(); });
		}

		tbody.addEventListener('click', function (ev) {
			var reg = ev.target.closest('.ded-register');
			var unreg = ev.target.closest('.ded-unregister');
			if (!reg && !unreg) return;

			var tr = ev.target.closest('tr');
			var id = parseInt(tr.getAttribute('data-id'), 10);
			var btn = reg || unreg;
			btn.disabled = true;

			post({ action: reg ? 'register' : 'unregister', id: id })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '처리 실패');
					location.reload();
				})
				.catch(function (e) {
					alert(e.message || '처리 실패');
					btn.disabled = false;
				});
		});
	})();
	</script>

	<?php if (in_array(($upload['status'] ?? ''), ['parsed', 'applied'], true) && SettlementLedger::tableExists()) : ?>
	<script>
	(function () {
		var btn = document.getElementById('btn_settlement_apply');
		if (!btn) return;
		var dailyCount = <?= (int) $dailyCount ?>;
		btn.addEventListener('click', function () {
			var msg = '매칭된 라이더에 정산 수수료를 계산하고 지갑에 반영할까요?\n이미 반영된 일자·플랫폼은 건너뜁니다.';
			if (dailyCount > 0) {
				msg += '\n\n⚠ 일일정산 대상 ' + dailyCount + '명은 반영 직후 보증금을 제외한 금액이'
					+ '\n곧바로 출금(실제 이체)됩니다. 되돌릴 수 없습니다.';
			}
			if (!confirm(msg)) return;
			btn.disabled = true;
			fetch('<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_apply.php', ENT_QUOTES, 'UTF-8') ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ upload_id: Number(btn.getAttribute('data-upload-id')) }),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '실패');
					var out = res.message || '반영되었습니다.';
					// 자동출금에서 건너뛰거나 실패한 건은 사유를 같이 보여준다(계좌 미등록·보증금 미달 등).
					var aw = res.auto_withdraw;
					if (aw && aw.results && aw.results.length) {
						var problems = aw.results.filter(function (x) { return x.status !== 'paid'; });
						if (problems.length) {
							out += '\n\n[자동출금 미처리]\n' + problems.slice(0, 10).map(function (x) {
								return '· ' + x.name + ': ' + x.message;
							}).join('\n');
							if (problems.length > 10) out += '\n… 외 ' + (problems.length - 10) + '명';
						}
					}
					alert(out);
					location.reload();
				})
				.catch(function (e) {
					alert(e.message || '정산 반영 실패');
					btn.disabled = false;
				});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

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
            r.rider_code, r.name AS rider_name, r.team_code, r.phone, r.status AS rider_status
       FROM settlement_daily_riders dr
       LEFT JOIN riders r ON r.id = dr.rider_id
      WHERE {$whereStr}
      ORDER BY dr.payout_amount DESC, dr.rider_name_raw ASC",
    $params
);

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

$meta = json_decode((string) ($upload['stored_path'] ?? ''), true);
$teamRegion = is_array($meta)
    ? trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? ''))
    : '';

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
			<?php if (($upload['status'] ?? '') === 'parsed' && SettlementLedger::tableExists()) : ?>
			<button type="button" class="btn btn-sm btn-success fw-bold" id="btn_settlement_apply" data-upload-id="<?= (int) $uploadId ?>">
				정산 반영 · 수수료·지갑
			</button>
			<?php endif; ?>
			<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">수수료 내역</a>
			<a href="<?= htmlspecialchars($uploadListUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">업로드</a>
			<a href="<?= htmlspecialchars($historyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">전체 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

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
					<div class="text-muted fs-8 mt-2">차감 <?= number_format($deductionCount) ?>건 · 오더상세 <?= number_format($orderDetailCount) ?>건 · 시간제보험 <?= number_format($hourlyInsCount) ?>건</div>
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
							<td colspan="12" class="text-center text-muted py-10">조건에 맞는 라이더가 없습니다.</td>
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
									<a href="<?= htmlspecialchars($riderDetailUrl . '?id=' . (int) $row['rider_id'], ENT_QUOTES, 'UTF-8') ?>"
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
								<?php else : ?>
									<span class="badge badge-light-warning">미매칭</span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="btn btn-sm btn-light-primary dr-detail-btn" data-id="<?= (int) $row['id'] ?>">상세</button>
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
		<div class="modal-dialog modal-dialog-centered modal-lg">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title">정산 원본 데이터 상세</h3>
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

	<script>
	(function () {
		var API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/settlement_daily_rider_detail.php', JSON_UNESCAPED_UNICODE) ?>;
		var RIDER_DETAIL_URL = <?= json_encode($riderDetailUrl, JSON_UNESCAPED_UNICODE) ?>;
		var modalEl = document.getElementById('kt_dr_detail_modal');
		var body = document.getElementById('dr_detail_body');
		var riderLink = document.getElementById('dr_detail_rider_link');

		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }
		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function row(label, value) {
			return '<div class="d-flex justify-content-between py-1 border-bottom border-gray-200">' +
				'<span class="text-muted">' + esc(label) + '</span><span class="fw-semibold">' + value + '</span></div>';
		}

		function render(d) {
			var html = '';
			html += '<div class="mb-4">';
			html += row('귀속일 · 플랫폼', esc(d.settlement_date) + ' · ' + esc(d.platform_label));
			html += row('원본 파일', esc(d.original_filename));
			html += row('엑셀 이름 / 라이선스ID', esc(d.rider_name_raw) + ' / ' + esc(d.license_id));
			html += '</div>';

			html += '<div class="separator separator-dashed mb-4"></div>';
			html += '<div class="mb-4">';
			html += row('오더 건수', esc(d.order_count) + '건');
			html += row('정산예정(gross)', won(d.gross_amount));
			html += row('실지급(payout)', won(d.payout_amount));
			html += '</div>';

			html += '<div class="separator separator-dashed mb-4"></div>';
			html += '<div class="fw-bold text-gray-800 mb-2">수수료 상세</div>';
			html += '<div class="row g-0 mb-4">';
			html += '<div class="col-6">' + row('픽업', won(d.fee_pickup)) + '</div>';
			html += '<div class="col-6">' + row('배달', won(d.fee_delivery)) + '</div>';
			html += '<div class="col-6">' + row('지역단가', won(d.fee_area)) + '</div>';
			html += '<div class="col-6">' + row('거리구간(건수/할증)', d.fee_dist_cnt + '건 / ' + won(d.fee_dist_surge)) + '</div>';
			html += '<div class="col-6">' + row('픽업콜(건수/할증)', d.fee_pickup_cnt + '건 / ' + won(d.fee_pickup_surge)) + '</div>';
			html += '<div class="col-6">' + row('도착콜(건수/할증)', d.fee_dest_cnt + '건 / ' + won(d.fee_dest_surge)) + '</div>';
			html += '<div class="col-6">' + row('기상할증(건수/금액)', d.fee_weather_cnt + '건 / ' + won(d.fee_weather)) + '</div>';
			html += '<div class="col-6">' + row('시간제보험', won(d.hourly_insurance)) + '</div>';
			html += '</div>';

			if (d.fee_promo1 || d.fee_promo2 || d.fee_promo3 || d.fee_promo4) {
				html += '<div class="fw-bold text-gray-800 mb-2">프로모션</div>';
				html += '<div class="row g-0 mb-4">';
				html += '<div class="col-6">' + row('프로모션1', won(d.fee_promo1)) + '</div>';
				html += '<div class="col-6">' + row('프로모션2', won(d.fee_promo2)) + '</div>';
				html += '<div class="col-6">' + row('프로모션3', won(d.fee_promo3)) + '</div>';
				html += '<div class="col-6">' + row('프로모션4', won(d.fee_promo4)) + '</div>';
				html += '</div>';
			}

			html += '<div class="separator separator-dashed mb-4"></div>';
			html += '<div class="fw-bold text-gray-800 mb-2">매칭 라이더</div>';
			if (d.matched && d.rider) {
				html += row('이름 / 코드', esc(d.rider.name) + ' / ' + esc(d.rider.rider_code));
				html += row('연락처', esc(d.rider.phone));
				html += row('상태', esc(d.rider.status_label));
			} else {
				html += '<div class="text-muted">미매칭 — 연결된 라이더가 없습니다.</div>';
			}

			body.innerHTML = html;

			if (d.matched && d.rider) {
				riderLink.href = RIDER_DETAIL_URL + '?id=' + d.rider.id;
				riderLink.classList.remove('d-none');
			} else {
				riderLink.classList.add('d-none');
			}
		}

		document.querySelectorAll('.dr-detail-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var id = btn.getAttribute('data-id');
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

	<?php if (($upload['status'] ?? '') === 'parsed' && SettlementLedger::tableExists()) : ?>
	<script>
	(function () {
		var btn = document.getElementById('btn_settlement_apply');
		if (!btn) return;
		btn.addEventListener('click', function () {
			if (!confirm('매칭된 라이더에 정산 수수료를 계산하고 지갑에 반영할까요?\n이미 반영된 일자·플랫폼은 건너뜁니다.')) return;
			btn.disabled = true;
			fetch('<?= htmlspecialchars(rtrim(ADMIN_BASE, '/') . '/api/settlement_apply.php', ENT_QUOTES, 'UTF-8') ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ upload_id: Number(btn.getAttribute('data-upload-id')) }),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '실패');
					alert(res.message || '반영되었습니다.');
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

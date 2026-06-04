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
					<div class="text-muted fs-8 mt-2">차감 <?= number_format($deductionCount) ?>건</div>
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
						</tr>
					</thead>
					<tbody>
					<?php if ($riders === []) : ?>
						<tr>
							<td colspan="11" class="text-center text-muted py-10">조건에 맞는 라이더가 없습니다.</td>
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
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
	<!--end::라이더 목록-->

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

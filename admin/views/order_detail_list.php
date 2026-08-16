<?php

declare(strict_types=1);

require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/Organization.php';

$won = static fn (int $n): string => number_format($n) . '원';
$num = static fn (int $n): string => number_format($n);
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$isAgencyLevel = admin_org_level() === Org::LEVEL_AGENCY;

$filterFrom    = trim((string) ($_GET['from'] ?? ''));
$filterTo      = trim((string) ($_GET['to'] ?? ''));
$filterAgency  = (int) ($_GET['agency'] ?? 0);
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));
$filterRider   = trim((string) ($_GET['rider'] ?? ''));
$filterStore   = trim((string) ($_GET['store'] ?? ''));
$filterOrderNo = trim((string) ($_GET['order_no'] ?? ''));
$filterUploadId = (int) ($_GET['upload_id'] ?? 0);

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-6 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

const ORDER_DETAIL_ROW_CAP = 500;

$listError = null;
$rows = [];
$sum = ['count' => 0, 'net' => 0, 'avg_minutes' => null];
$needsMigrate = !db_table_exists('settlement_order_details');

if (!$needsMigrate) {
    try {
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('u.agency_id');
        $conds  = [];
        $params = [];
        // upload_id로 특정 업로드 하나를 볼 때는 그 자체로 스코프가 명확하므로
        // 기간 필터(기본 최근 7일)를 별도로 걸지 않는다 — 안 그러면 오래된 업로드를
        // 상세화면에서 눌러 들어왔을 때 기간이 안 맞아 결과가 0건으로 보일 수 있다.
        if ($filterUploadId > 0) {
            $conds[] = 'od.upload_id = ?';
            $params[] = $filterUploadId;
        } else {
            $conds[] = 'od.settlement_date >= ?';
            $conds[] = 'od.settlement_date <= ?';
            $params = [$filterFrom, $filterTo];
        }
        if ($scopeSql !== '') {
            $conds[] = $scopeSql;
            $params  = array_merge($params, $scopeParams);
        }
        if (!$isAgencyLevel && $filterAgency > 0) {
            $conds[] = 'u.agency_id = ?';
            $params[] = $filterAgency;
        }
        if (in_array($filterPlatform, ['baemin', 'coupang', 'other'], true)) {
            $conds[] = 'u.platform = ?';
            $params[] = $filterPlatform;
        }
        if ($filterRider !== '') {
            $conds[] = '(od.rider_name_raw LIKE ? OR r.name LIKE ? OR r.rider_code LIKE ?)';
            $like = '%' . $filterRider . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($filterStore !== '') {
            $conds[] = 'od.store_name LIKE ?';
            $params[] = '%' . $filterStore . '%';
        }
        if ($filterOrderNo !== '') {
            $conds[] = 'od.order_no LIKE ?';
            $params[] = '%' . $filterOrderNo . '%';
        }
        $whereSql = implode(' AND ', $conds);

        $sumRow = db_row(
            "SELECT COUNT(*) cnt, COALESCE(SUM(od.net_amount), 0) net, AVG(od.duration_minutes) avg_min
               FROM settlement_order_details od
               INNER JOIN settlement_uploads u ON u.id = od.upload_id
               LEFT JOIN riders r ON r.id = od.rider_id
              WHERE {$whereSql}",
            $params
        );
        $sum = [
            'count' => (int) ($sumRow['cnt'] ?? 0),
            'net'   => (int) ($sumRow['net'] ?? 0),
            'avg_minutes' => $sumRow['avg_min'] !== null ? round((float) $sumRow['avg_min'], 1) : null,
        ];

        $rows = db_rows(
            "SELECT od.*, u.platform, u.agency_id, o.name AS agency_name, r.name AS rider_name, r.rider_code
               FROM settlement_order_details od
               INNER JOIN settlement_uploads u ON u.id = od.upload_id
               LEFT JOIN organizations o ON o.id = u.agency_id
               LEFT JOIN riders r ON r.id = od.rider_id
              WHERE {$whereSql}
              ORDER BY od.settlement_date DESC, od.id DESC
              LIMIT " . ORDER_DETAIL_ROW_CAP,
            $params
        );
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

$agencyOptions = $isAgencyLevel ? [] : Organization::agencyOptions();
$listUrl = admin_url('settlement/order-details');

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

$exportUrl = rtrim(ADMIN_BASE, '/') . '/api/order_detail_export.php?' . http_build_query([
    'from' => $filterFrom, 'to' => $filterTo, 'agency' => $filterAgency ?: '', 'platform' => $filterPlatform,
    'rider' => $filterRider, 'store' => $filterStore, 'order_no' => $filterOrderNo, 'upload_id' => $filterUploadId ?: '',
]);

$quickRanges = [
    '오늘'      => [date('Y-m-d'), date('Y-m-d')],
    '최근 7일'  => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '이번 달'   => [date('Y-m-01'), date('Y-m-d')],
    '지난 달'   => [date('Y-m-01', strtotime('-1 month')), date('Y-m-t', strtotime('-1 month'))],
    '최근 90일' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
];

/** 기간 빠른 선택 버튼용 URL */
function order_detail_range_url(string $base, string $from, string $to, array $extra): string
{
    $sep = str_contains($base, '?') ? '&' : '?';
    $query = array_filter(array_merge(['from' => $from, 'to' => $to], $extra), static fn ($v) => $v !== null && $v !== '' && $v !== 0);

    return $base . $sep . http_build_query($query);
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">오더별 상세 내역</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">오더별 상세</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= $esc($exportUrl) ?>" class="btn btn-sm btn-light-success fw-bold">
				<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
				엑셀 다운로드
			</a>
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

	<div class="alert alert-dismissible bg-light-primary d-flex p-5 mb-6">
		<div class="fs-7 text-gray-800">
			업로드된 정산서의 <strong>개별 주문(건) 단위</strong> 원본 데이터입니다. 라이더별 요약(「정산 수수료 내역」)과 달리 주문 하나하나를 확인할 수 있습니다.
		</div>
	</div>

	<!--begin::필터-->
	<div class="card card-flush mb-6">
		<div class="card-body py-5">
			<form method="get" action="<?= $esc($listUrl) ?>" class="row g-3 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/order-details" />
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">정산일 시작</label>
					<input type="date" name="from" class="form-control form-control-sm" value="<?= $esc($filterFrom) ?>" />
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">종료</label>
					<input type="date" name="to" class="form-control form-control-sm" value="<?= $esc($filterTo) ?>" />
				</div>
				<?php if (!$isAgencyLevel) : ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">대리점</label>
					<select name="agency" id="odFilterAgency" class="form-select form-select-sm" style="min-width:150px">
						<option value="0">전체</option>
						<?php foreach ($agencyOptions as $ao) : ?>
						<option value="<?= (int) $ao['id'] ?>" <?= $filterAgency === (int) $ao['id'] ? 'selected' : '' ?>><?= $esc((string) $ao['name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">플랫폼</label>
					<select name="platform" class="form-select form-select-sm">
						<option value="">전체</option>
						<?php foreach ($platformLabels as $pv => $pl) : ?>
						<option value="<?= $pv ?>" <?= $filterPlatform === $pv ? 'selected' : '' ?>><?= $esc($pl) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">라이더</label>
					<?php // select2(ajax, tags 허용) — 등록된 라이더는 검색해서 고르고, 미매칭 라이더는
					      // 엑셀 원본 이름을 그대로 타이핑해도 되도록 자유입력도 열어둔다(기존 LIKE 검색 그대로 유지). ?>
					<select name="rider" id="odFilterRider" class="form-select form-select-sm" style="width:170px">
						<?php if ($filterRider !== '') : ?>
						<option value="<?= $esc($filterRider) ?>" selected><?= $esc($filterRider) ?></option>
						<?php endif; ?>
					</select>
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">매장명</label>
					<input type="text" name="store" class="form-control form-control-sm" value="<?= $esc($filterStore) ?>" style="width:120px" />
				</div>
				<div class="col-auto">
					<label class="form-label fs-8 mb-1">주문번호</label>
					<input type="text" name="order_no" class="form-control form-control-sm" value="<?= $esc($filterOrderNo) ?>" style="width:130px" />
				</div>
				<?php if ($filterUploadId > 0) : ?>
				<input type="hidden" name="upload_id" value="<?= (int) $filterUploadId ?>" />
				<?php endif; ?>
				<div class="col-auto">
					<button type="submit" class="btn btn-sm btn-primary">조회</button>
				</div>
				<div class="col-auto d-flex flex-wrap gap-1">
					<?php foreach ($quickRanges as $label => [$qf, $qt]) :
					    $active = $filterFrom === $qf && $filterTo === $qt; ?>
					<a class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
						href="<?= $esc(order_detail_range_url($listUrl, $qf, $qt, ['agency' => $filterAgency, 'platform' => $filterPlatform, 'rider' => $filterRider, 'store' => $filterStore, 'order_no' => $filterOrderNo])) ?>">
						<?= $esc($label) ?>
					</a>
					<?php endforeach; ?>
				</div>
			</form>
			<?php if ($filterUploadId > 0) : ?>
			<div class="mt-3">
				<span class="badge badge-light-primary">업로드 #<?= (int) $filterUploadId ?> 만 보는 중</span>
				<a href="<?= $esc(order_detail_range_url($listUrl, $filterFrom, $filterTo, ['agency' => $filterAgency, 'platform' => $filterPlatform])) ?>" class="fs-8 ms-2">필터 해제</a>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<!--end::필터-->

	<!--begin::요약-->
	<div class="row g-5 g-xl-8 mb-6">
		<div class="col-sm-4">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">조회 건수</div>
					<div class="fs-2 fw-bold text-gray-800"><?= $num($sum['count']) ?>건</div>
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">순수익 합계</div>
					<div class="fs-2 fw-bold text-primary"><?= $won($sum['net']) ?></div>
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="card card-flush h-100">
				<div class="card-body">
					<div class="fs-7 text-gray-500 mb-1">평균 소요시간</div>
					<div class="fs-2 fw-bold text-gray-800"><?= $sum['avg_minutes'] !== null ? $sum['avg_minutes'] . '분' : '—' ?></div>
				</div>
			</div>
		</div>
	</div>
	<!--end::요약-->

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">주문 목록</h3>
			<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">최근 <?= ORDER_DETAIL_ROW_CAP ?>건까지 화면 표시 · 요약은 전체 기준 · 엑셀 다운로드는 전체 포함</span>
		</div>
		<div class="card-body pt-0">
			<?php if ($rows === []) : ?>
			<p class="text-muted fs-7 py-10 mb-0 text-center">조회 결과가 없습니다.</p>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle fs-7 gy-2" id="odTable">
					<thead>
						<tr class="fw-bold text-muted">
							<th>정산일</th>
							<?php if (!$isAgencyLevel) : ?><th>대리점</th><?php endif; ?>
							<th>플랫폼</th>
							<th>라이더</th>
							<th>주문번호</th>
							<th>매장명</th>
							<th class="min-w-140px">지역</th>
							<th>시간</th>
							<th class="text-end">소요(분)</th>
							<th>유형</th>
							<th class="text-end">기본비</th>
							<th class="text-end">할증</th>
							<th class="text-end">프로모션</th>
							<th class="text-end">순수익</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) :
						    $base = (int) $r['fee_pickup'] + (int) $r['fee_delivery'] + (int) $r['fee_area'];
						    $surge = (int) $r['fee_dist_surge'] + (int) $r['fee_pickup_surge'] + (int) $r['fee_dest_surge'] + (int) $r['fee_weather'];
						    $promo = (int) $r['fee_promo1'] + (int) $r['fee_promo2'] + (int) $r['fee_promo3'] + (int) $r['fee_promo4'];
						    ?>
						<tr>
							<td class="text-gray-700"><?= $esc((string) $r['settlement_date']) ?></td>
							<?php if (!$isAgencyLevel) : ?>
							<td><?= $esc((string) ($r['agency_name'] ?? '')) ?></td>
							<?php endif; ?>
							<td><span class="badge badge-light"><?= $esc($platformLabels[$r['platform']] ?? (string) $r['platform']) ?></span></td>
							<td><?= $esc((string) ($r['rider_name'] ?? $r['rider_name_raw'])) ?><?php if (($r['rider_name'] ?? '') === '') : ?> <span class="badge badge-light-warning fs-9">미매칭</span><?php endif; ?></td>
							<td class="font-monospace"><?= $esc((string) $r['order_no']) ?></td>
							<td><?= $esc((string) $r['store_name']) ?></td>
							<td class="fs-8 lh-sm">
								<div><?= $esc((string) $r['pickup_area']) ?></div>
								<div>→ <?= $esc((string) $r['delivery_area']) ?></div>
							</td>
							<td class="fs-8 lh-sm text-muted">
								<div><?= $esc(substr((string) $r['assigned_at'], 11, 5)) ?></div>
								<div>~ <?= $esc(substr((string) $r['delivered_at'], 11, 5)) ?></div>
							</td>
							<td class="text-end"><?= $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : '—' ?></td>
							<td class="fs-8"><?= $esc((string) $r['delivery_type']) ?></td>
							<td class="text-end"><?= $won($base) ?></td>
							<td class="text-end"><?= $surge > 0 ? $won($surge) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end"><?= $promo > 0 ? $won($promo) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end fw-bold text-gray-800"><?= $won((int) $r['net_amount']) ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php if (!$needsMigrate) : ?>
<script>
	// jQuery/select2는 plugins.bundle.js에서 오는데 그 스크립트가 이 뷰보다 뒤(inc/shell_close.php)에
	// 있어 아직 로드 전이다 — DOMContentLoaded 이후로 초기화를 미룬다(manual_adjust.php와 동일 패턴).
	(function () {
		var RIDERS_API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/riders.php', JSON_UNESCAPED_UNICODE) ?>;

		function initRiderFilter() {
			var riderSel = jQuery('#odFilterRider');
			if (!riderSel.length) return;
			var agencySel = jQuery('#odFilterAgency');

			riderSel.select2({
				placeholder: '이름·코드 검색',
				allowClear: true,
				tags: true, // 미매칭 라이더는 엑셀 원본 이름을 그대로 자유입력할 수 있어야 한다(기존 LIKE 검색 유지)
				ajax: {
					url: RIDERS_API,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return { q: params.term || '', q_field: 'name', agency: agencySel.length ? (agencySel.val() || 0) : 0, limit: 30 };
					},
					processResults: function (data) {
						return {
							results: (data.items || []).map(function (r) {
								return { id: r.name, text: r.name + ' (' + r.rider_code + ')' };
							}),
						};
					},
				},
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initRiderFilter);
		} else {
			initRiderFilter();
		}
	})();
</script>
<?php endif; ?>

<?php if (!$needsMigrate && $listError === null && $rows !== []) : ?>
<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
	var odTable = document.getElementById('odTable');
	if (odTable) {
		initTablePaginate(odTable, { pageSize: 30, unit: '건' });
	}
</script>
<?php endif; ?>

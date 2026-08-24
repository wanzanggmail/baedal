<?php

declare(strict_types=1);

$filterFrom     = trim((string) ($_GET['from'] ?? ''));
$filterTo       = trim((string) ($_GET['to'] ?? ''));
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));
$filterQ        = trim((string) ($_GET['q'] ?? ''));
// 일간/주간 전환 — 주간 정산서(프로모션·시간제보험)도 업로드되므로 여기서 확인할 수 있어야 한다.
$filterKind     = ($_GET['kind'] ?? '') === 'weekly' ? 'weekly' : 'daily';
$page           = max(1, (int) ($_GET['page'] ?? 1));
$perPage        = 30;
$offset         = ($page - 1) * $perPage;

if ($filterFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-90 days'));
}
if ($filterTo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}
if (!in_array($filterPlatform, ['', 'baemin', 'coupang', 'other'], true)) {
    $filterPlatform = '';
}

$statusLabels = [
    'uploaded' => ['label' => '업로드됨', 'badge' => 'badge-light-primary'],
    'parsing'  => ['label' => '파싱 중', 'badge' => 'badge-light-warning'],
    'parsed'   => ['label' => '파싱완료', 'badge' => 'badge-light-success'],
    'applied'  => ['label' => '반영완료', 'badge' => 'badge-light-info'],
    'error'    => ['label' => '오류', 'badge' => 'badge-light-danger'],
];

$platformLabels = [
    'baemin'  => '배달의민족',
    'coupang' => '쿠팡이츠',
    'other'   => '기타',
];

$listError    = null;
$uploads      = [];
$weeklySums   = [];   // upload_id => ['week'=>, 'promo'=>, 'ins'=>]  주간 탭에서만 채움
$totalCount   = 0;
$needsMigrate = !db_table_exists('settlement_uploads');

if (!$needsMigrate) {
    try {
        $where  = ['u.kind = ?', 'u.settlement_date >= ?', 'u.settlement_date <= ?'];
        $params = [$filterKind, $filterFrom, $filterTo];

        if ($filterPlatform !== '') {
            $where[]  = 'u.platform = ?';
            $params[] = $filterPlatform;
        }
        if ($filterQ !== '') {
            $where[]  = '(u.original_filename LIKE ? OR a.name LIKE ?)';
            $like     = '%' . $filterQ . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // 멀티테넌시: 업로드 소유 대리점 스코프
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('u.agency_id');
        if ($scopeSql !== '') {
            $where[] = $scopeSql;
            $params  = array_merge($params, $scopeParams);
        }

        $whereSql = implode(' AND ', $where);

        $countRow = db_row(
            "SELECT COUNT(*) AS cnt
               FROM settlement_uploads u
               LEFT JOIN admins a ON a.id = u.operator_id
              WHERE {$whereSql}",
            $params
        );
        $totalCount = (int) ($countRow['cnt'] ?? 0);

        $listParams   = $params;
        $listParams[] = $perPage;
        $listParams[] = $offset;

        $uploads = db_rows(
            "SELECT u.id, u.settlement_date, u.original_filename, u.platform, u.team_name, u.region_name,
                    u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at,
                    a.name AS uploaded_by_name, u.stored_path
               FROM settlement_uploads u
               LEFT JOIN admins a ON a.id = u.operator_id
              WHERE {$whereSql}
              ORDER BY u.settlement_date DESC, u.created_at DESC
              LIMIT ? OFFSET ?",
            $listParams
        );

        // 주간은 「상세」 화면이 없으므로(그 화면은 일간 전용) 반영 대상 금액을 목록에 바로 보여준다.
        if ($filterKind === 'weekly' && $uploads !== [] && db_table_exists('settlement_weekly_riders')) {
            $ids = array_map(static fn (array $u): int => (int) $u['id'], $uploads);
            foreach (db_rows(
                'SELECT upload_id, MIN(week_start) AS ws, MAX(week_end) AS we,
                        SUM(extra_pay) AS promo, SUM(hourly_ins) AS ins
                   FROM settlement_weekly_riders
                  WHERE upload_id IN (' . db_in($ids) . ')
                  GROUP BY upload_id',
                $ids
            ) as $w) {
                $weeklySums[(int) $w['upload_id']] = [
                    'week'  => (string) $w['ws'] . ' ~ ' . (string) $w['we'],
                    'promo' => (int) $w['promo'],
                    'ins'   => (int) $w['ins'],
                ];
            }
        }
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

$totalPages = max(1, (int) ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$listUrl      = admin_url('settlement/history');
$uploadUrl    = admin_url('settlement/upload');
$detailBase   = admin_url('settlement/upload-detail');
$detailBase  .= str_contains($detailBase, '?') ? '&' : '?';

function settlement_history_page_url(string $base, int $pageNum, array $query): string
{
    $query['page'] = $pageNum;
    $sep = str_contains($base, '?') ? '&' : '?';

    return $base . $sep . http_build_query($query);
}

$queryParams = array_filter([
    'route'    => (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) ? 'settlement/history' : null,
    // kind가 빠지면 2페이지로 넘어갈 때 주간 탭이 일간으로 되돌아간다.
    'kind'     => $filterKind,
    'from'     => $filterFrom,
    'to'       => $filterTo,
    'platform' => $filterPlatform !== '' ? $filterPlatform : null,
    'q'        => $filterQ !== '' ? $filterQ : null,
], static fn ($v) => $v !== null && $v !== '');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">업로드 이력</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">업로드 이력</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
				새 업로드
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>

	<?php
	// 일간/주간 전환 탭 — 현재 필터는 유지하고 kind만 바꾼다.
	$kindTabUrl = static function (string $kind) use ($listUrl, $filterFrom, $filterTo, $filterPlatform, $filterQ): string {
	    $q = array_filter([
	        'route'    => (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) ? 'settlement/history' : null,
	        'kind'     => $kind,
	        'from'     => $filterFrom,
	        'to'       => $filterTo,
	        'platform' => $filterPlatform !== '' ? $filterPlatform : null,
	        'q'        => $filterQ !== '' ? $filterQ : null,
	    ], static fn ($v) => $v !== null && $v !== '');

	    return (str_contains($listUrl, '?') ? $listUrl . '&' : $listUrl . '?') . http_build_query($q);
	};
	?>
	<ul class="nav nav-tabs nav-line-tabs fs-6 fw-semibold mb-6">
		<li class="nav-item">
			<a class="nav-link<?= $filterKind === 'daily' ? ' active' : '' ?>" href="<?= htmlspecialchars($kindTabUrl('daily'), ENT_QUOTES, 'UTF-8') ?>">일간 정산서</a>
		</li>
		<li class="nav-item">
			<a class="nav-link<?= $filterKind === 'weekly' ? ' active' : '' ?>" href="<?= htmlspecialchars($kindTabUrl('weekly'), ENT_QUOTES, 'UTF-8') ?>">주간 정산서</a>
		</li>
	</ul>

	<?php if ($filterKind === 'weekly') : ?>
	<div class="alert bg-light-primary fs-8 p-4 mb-6">
		주간 정산서는 <strong>프로모션과 시간제보험만</strong> 반영합니다. 고용·산재·원천세는 일간 정산서 반영 때 우리 기준으로 계산합니다.
		<span class="d-block mt-1 text-muted">주간은 「정산 반영」 대상이 아니라 조회·대조용입니다.</span>
	</div>
	<?php endif; ?>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/history" />
				<?php endif; ?>
				<input type="hidden" name="kind" value="<?= htmlspecialchars($filterKind, ENT_QUOTES, 'UTF-8') ?>" />
				<div class="col-md-4">
					<label class="form-label fw-semibold">귀속일</label>
					<?php // 공통 패턴 — inc/shell_close.php 의 admin-datepickers.js 가 초기화한다. ?>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="전체 기간" />
						<input type="hidden" name="from" data-kt-daterange-from value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
						<input type="hidden" name="to" data-kt-daterange-to value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">플랫폼</label>
					<select class="form-select form-select-solid" name="platform">
						<option value="">전체</option>
						<?php foreach ($platformLabels as $code => $label) : ?>
						<option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"<?= $filterPlatform === $code ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="파일명, 업로드자" />
				</div>
				<div class="col-md-2">
					<button type="submit" class="btn btn-light-primary w-100">조회</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-7">
			<h3 class="card-title align-items-start flex-column">
				<span class="card-label fw-bold text-gray-900">일간 정산 업로드</span>
				<span class="text-gray-500 mt-1 fw-semibold fs-7">총 <?= number_format($totalCount) ?>건</span>
			</h3>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted fs-7">
							<?php // 파일명·팀지역만 남는 폭을 쓰고 나머지는 고정폭 + 줄바꿈 금지 — 한 칸이 두 줄이 되면 표 전체가 세로로 늘어난다. ?>
							<th class="text-nowrap w-125px"><?= $filterKind === 'weekly' ? '정산 기간' : '귀속일' ?></th>
							<th class="w-125px">팀·지역</th>
							<th>파일명</th>
							<th class="text-nowrap w-100px">플랫폼</th>
							<th class="text-nowrap w-125px"><?= $filterKind === 'weekly' ? '라이더' : '건수' ?></th>
							<th class="text-nowrap w-100px">상태</th>
							<th class="text-nowrap w-125px">업로드자</th>
							<th class="text-nowrap w-125px">일시</th>
							<th class="min-w-70px"><?= $filterKind === 'weekly' ? '반영 대상' : '' ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ($uploads === []) : ?>
						<tr>
							<td colspan="9" class="text-center text-muted py-12">
								조건에 맞는 업로드 이력이 없습니다.<br>
								<a href="<?= htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8') ?>" class="fw-bold">엑셀 업로드</a>에서 새로 등록하세요.
							</td>
						</tr>
						<?php endif; ?>
						<?php foreach ($uploads as $up) :
                            $st = $statusLabels[$up['status']] ?? ['label' => (string) $up['status'], 'badge' => 'badge-light'];
                            $meta = json_decode((string) ($up['stored_path'] ?? ''), true);
                            $teamLabel = is_array($meta)
                                ? trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? ''))
                                : '';
                            $platLabel = $platformLabels[$up['platform']] ?? (string) $up['platform'];
                            $detailUrl = $detailBase . 'id=' . (int) $up['id'];
                            ?>
						<?php $wk = $weeklySums[(int) $up['id']] ?? null; ?>
						<tr>
							<td class="fw-bold text-gray-900 fs-8 text-nowrap">
								<?= htmlspecialchars($wk !== null ? $wk['week'] : (string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?>
							</td>
							<?php // 팀·지역은 파일명에서 뽑히다 보니 아주 긴 값이 섞인다 — 잘라 두고 전체는 툴팁으로. ?>
							<td class="fs-8 text-gray-700" title="<?= htmlspecialchars($teamLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($teamLabel !== '' ? mb_strimwidth($teamLabel, 0, 22, '…') : '-', ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700 fs-8 text-break">
							<?= htmlspecialchars((string) $up['original_filename'], ENT_QUOTES, 'UTF-8') ?>
						</td>
							<td class="text-nowrap"><?= htmlspecialchars($platLabel, ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-nowrap">
								<?= number_format((int) $up['total_rows']) ?>명
								<span class="text-muted fs-8">(<?= number_format((int) $up['ok_rows']) ?>)</span>
								<?php if ((int) $up['error_rows'] > 0) : ?>
								<span class="text-warning fs-8 ms-1">미매칭 <?= number_format((int) $up['error_rows']) ?></span>
								<?php endif; ?>
							</td>
							<td class="text-nowrap"><span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-nowrap"><?= htmlspecialchars((string) ($up['uploaded_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-8 text-nowrap"><?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<?php if ($filterKind === 'weekly') : ?>
								<?php // 주간은 상세 화면이 없다(그 화면은 일간 전용) — 반영 대상 금액을 여기 직접 보여준다. ?>
								<div class="fs-8 lh-sm">
									<div><span class="text-muted">프로모션</span> <span class="fw-semibold text-primary"><?= number_format($wk['promo'] ?? 0) ?>원</span></div>
									<div><span class="text-muted">시간제보험</span> <span class="fw-semibold"><?= number_format($wk['ins'] ?? 0) ?>원</span></div>
								</div>
								<?php else : ?>
								<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">상세</a>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ($totalPages > 1) : ?>
			<div class="d-flex flex-wrap justify-content-between align-items-center mt-6">
				<span class="text-muted fs-7"><?= number_format($page) ?> / <?= number_format($totalPages) ?> 페이지</span>
				<ul class="pagination pagination-sm mb-0">
					<?php if ($page > 1) : ?>
					<li class="page-item">
						<a class="page-link" href="<?= htmlspecialchars(settlement_history_page_url($listUrl, $page - 1, $queryParams), ENT_QUOTES, 'UTF-8') ?>">이전</a>
					</li>
					<?php endif; ?>
					<?php if ($page < $totalPages) : ?>
					<li class="page-item">
						<a class="page-link" href="<?= htmlspecialchars(settlement_history_page_url($listUrl, $page + 1, $queryParams), ENT_QUOTES, 'UTF-8') ?>">다음</a>
					</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

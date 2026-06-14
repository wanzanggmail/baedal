<?php

declare(strict_types=1);

$filterFrom     = trim((string) ($_GET['from'] ?? ''));
$filterTo       = trim((string) ($_GET['to'] ?? ''));
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));
$filterQ        = trim((string) ($_GET['q'] ?? ''));
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
$totalCount   = 0;
$needsMigrate = !db_table_exists('settlement_uploads');

if (!$needsMigrate) {
    try {
        $where  = ['u.kind = ?', 'u.settlement_date >= ?', 'u.settlement_date <= ?'];
        $params = ['daily', $filterFrom, $filterTo];

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
            "SELECT u.id, u.settlement_date, u.original_filename, u.platform,
                    u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at,
                    a.name AS uploaded_by_name, u.stored_path
               FROM settlement_uploads u
               LEFT JOIN admins a ON a.id = u.operator_id
              WHERE {$whereSql}
              ORDER BY u.settlement_date DESC, u.created_at DESC
              LIMIT ? OFFSET ?",
            $listParams
        );
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

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/history" />
				<?php endif; ?>
				<div class="col-md-2">
					<label class="form-label fw-semibold">귀속일 from</label>
					<input type="date" class="form-control form-control-solid" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">to</label>
					<input type="date" class="form-control form-control-solid" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
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
							<th>귀속일</th>
							<th>팀·지역</th>
							<th>파일명</th>
							<th>플랫폼</th>
							<th>건수</th>
							<th>상태</th>
							<th>업로드자</th>
							<th>일시</th>
							<th class="min-w-70px"></th>
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
						<tr>
							<td class="fw-bold text-gray-900"><?= htmlspecialchars((string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($teamLabel !== '' ? $teamLabel : '-', ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700 fs-7 text-break"><?= htmlspecialchars((string) $up['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($platLabel, ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<?= number_format((int) $up['total_rows']) ?>명
								<span class="text-muted fs-8">(매칭 <?= number_format((int) $up['ok_rows']) ?>)</span>
								<?php if ((int) $up['error_rows'] > 0) : ?>
								<span class="text-warning fs-8 ms-1">미매칭 <?= number_format((int) $up['error_rows']) ?></span>
								<?php endif; ?>
							</td>
							<td><span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td><?= htmlspecialchars((string) ($up['uploaded_by_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-7"><?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">상세</a>
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

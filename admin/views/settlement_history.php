<?php

declare(strict_types=1);

$filterDateFrom = trim((string) ($_GET['from'] ?? ''));
$filterDateTo   = trim((string) ($_GET['to'] ?? ''));
$filterPlatform = trim((string) ($_GET['platform'] ?? ''));

$where  = ['u.kind = ?'];
$params = ['daily'];

if ($filterDateFrom !== '') {
    $where[]  = 'u.settlement_date >= ?';
    $params[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $where[]  = 'u.settlement_date <= ?';
    $params[] = $filterDateTo;
}
if ($filterPlatform !== '' && in_array($filterPlatform, ['baemin', 'coupang', 'other'], true)) {
    $where[]  = 'u.platform = ?';
    $params[] = $filterPlatform;
}

$whereStr = implode(' AND ', $where);

$uploads = [];
try {
    $uploads = db_rows(
        "SELECT u.id, u.settlement_date, u.platform, u.original_filename,
                u.total_rows, u.ok_rows, u.error_rows, u.status, u.created_at, u.stored_path,
                a.name AS operator_name
           FROM settlement_uploads u
           LEFT JOIN admins a ON a.id = u.operator_id
          WHERE {$whereStr}
          ORDER BY u.settlement_date DESC, u.id DESC
          LIMIT 200",
        $params
    );
} catch (Throwable) {
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

$historyUrl = admin_url('settlement/history');
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
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">정산</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">업로드 이력</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">엑셀 업로드</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($historyUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
					<input type="hidden" name="route" value="settlement/history" />
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label">귀속일 (부터)</label>
					<input type="date" name="from" value="<?= htmlspecialchars($filterDateFrom, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-solid" />
				</div>
				<div class="col-md-3">
					<label class="form-label">귀속일 (까지)</label>
					<input type="date" name="to" value="<?= htmlspecialchars($filterDateTo, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-solid" />
				</div>
				<div class="col-md-3">
					<label class="form-label">플랫폼</label>
					<select name="platform" class="form-select form-select-solid">
						<option value="">전체</option>
						<option value="baemin"<?= $filterPlatform === 'baemin' ? ' selected' : '' ?>>배달의민족</option>
						<option value="coupang"<?= $filterPlatform === 'coupang' ? ' selected' : '' ?>>쿠팡이츠</option>
						<option value="other"<?= $filterPlatform === 'other' ? ' selected' : '' ?>>기타</option>
					</select>
				</div>
				<div class="col-md-3">
					<button type="submit" class="btn btn-light-primary me-2">조회</button>
					<a href="<?= htmlspecialchars($historyUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">초기화</a>
				</div>
			</form>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-7">
			<h3 class="card-title">일간 정산 업로드 (<?= number_format(count($uploads)) ?>건)</h3>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th>귀속일</th>
							<th>플랫폼</th>
							<th>팀·지역</th>
							<th>파일명</th>
							<th>라이더</th>
							<th>상태</th>
							<th>업로드</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php if ($uploads === []) : ?>
						<tr>
							<td colspan="8" class="text-center text-muted py-10">업로드 이력이 없습니다.</td>
						</tr>
					<?php else :
					    foreach ($uploads as $up) :
					        $st = $statusLabels[$up['status']] ?? ['label' => $up['status'], 'badge' => 'badge-light'];
					        $meta = json_decode((string) ($up['stored_path'] ?? ''), true);
					        $teamLabel = is_array($meta)
					            ? trim(($meta['team'] ?? '') . ' ' . ($meta['region'] ?? ''))
					            : '';
					        $detailUrl = admin_url('settlement/upload-detail');
					        $detailUrl .= (str_contains($detailUrl, '?') ? '&' : '?') . 'id=' . (int) $up['id'];
					        ?>
						<tr>
							<td class="fw-bold"><?= htmlspecialchars((string) $up['settlement_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($platformLabels[$up['platform']] ?? $up['platform'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="fs-7"><?= htmlspecialchars($teamLabel !== '' ? $teamLabel : '-', ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-7"><?= htmlspecialchars((string) $up['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<?= number_format((int) $up['total_rows']) ?>명
								<span class="text-muted fs-8">(매칭 <?= number_format((int) $up['ok_rows']) ?>)</span>
							</td>
							<td><span class="badge <?= htmlspecialchars($st['badge'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="text-gray-600 fs-7">
								<?= htmlspecialchars((string) ($up['operator_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?><br />
								<?= htmlspecialchars(substr((string) $up['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
							</td>
							<td>
								<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">라이더 목록</a>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

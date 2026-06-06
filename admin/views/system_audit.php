<?php

declare(strict_types=1);

require_once INC_PATH . '/AuditLog.php';

$listError = null;
$result = ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'pages' => 1];
$needsMigrate = false;

$filterQ = trim((string) ($_GET['q'] ?? ''));
$filterActor = trim((string) ($_GET['actor'] ?? ''));
$filterPrefix = trim((string) ($_GET['action_prefix'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

try {
    if (!AuditLog::tableExists()) {
        $needsMigrate = true;
    } else {
        $result = AuditLog::list([
            'q'             => $filterQ,
            'actor'         => $filterActor,
            'action_prefix' => $filterPrefix,
            'page'          => $page,
            'limit'         => 50,
        ]);
    }
} catch (Throwable $e) {
    $listError = $e->getMessage();
    if (str_contains($listError, 'audit_logs') || str_contains($listError, "doesn't exist")) {
        $needsMigrate = true;
        $listError = null;
    }
}

$rows = $result['rows'];
$total = (int) $result['total'];
$pages = (int) $result['pages'];
$listUrl = admin_url('system/audit');
$exportBase = ADMIN_BASE . '/api/audit_export.php';
$exportQs = http_build_query(array_filter([
    'q'             => $filterQ !== '' ? $filterQ : null,
    'actor'         => $filterActor !== '' ? $filterActor : null,
    'action_prefix' => $filterPrefix !== '' ? $filterPrefix : null,
]));
$exportUrl = $exportBase . ($exportQs !== '' ? ('?' . $exportQs) : '');

function audit_page_url(string $base, int $page, string $q, string $actor, string $prefix): string
{
    $qs = http_build_query(array_filter([
        'route'         => 'system/audit',
        'q'             => $q !== '' ? $q : null,
        'actor'         => $actor !== '' ? $actor : null,
        'action_prefix' => $prefix !== '' ? $prefix : null,
        'page'          => $page > 1 ? $page : null,
    ]));

    return $base . ($qs !== '' ? ('?' . $qs) : '');
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">감사 로그</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">감사</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">관리자</a>
			<a href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">코드/마스터</a>
			<?php if (!$needsMigrate) : ?>
			<a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">CSV 내보내기</a>
			<?php endif; ?>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning p-5 mb-8">
		<strong>감사 로그 테이블이 없습니다.</strong>
		<div class="fs-7 mt-2">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	</div>
	<?php elseif ($listError !== null) : ?>
	<div class="alert alert-danger p-5 mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-info d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-tick fs-2hx text-info me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			관리자 <strong>로그인·로그아웃</strong>, 공지·배너·정산·출금·라이더 조치 등이
			<strong>DB <code>audit_logs</code></strong> (actor_type / target_table / before·after JSON) 에 기록됩니다.
		</div>
	</div>
	<?php endif; ?>

	<?php if (!$needsMigrate && $listError === null) : ?>
	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<input type="hidden" name="route" value="system/audit" />
				<div class="col-md-4">
					<label class="form-label fs-7" for="audit_q">검색 (행동·대상·상세)</label>
					<input type="search" class="form-control form-control-solid" id="audit_q" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="예: login, notice, withdrawal" autocomplete="off" />
				</div>
				<div class="col-md-3">
					<label class="form-label fs-7" for="audit_actor">수행자</label>
					<input type="text" class="form-control form-control-solid" id="audit_actor" name="actor" value="<?= htmlspecialchars($filterActor, ENT_QUOTES, 'UTF-8') ?>" placeholder="전체" />
				</div>
				<div class="col-md-3">
					<label class="form-label fs-7" for="audit_action_prefix">행동 접두사</label>
					<input type="text" class="form-control form-control-solid" id="audit_action_prefix" name="action_prefix" value="<?= htmlspecialchars($filterPrefix, ENT_QUOTES, 'UTF-8') ?>" placeholder="예: LOGIN, UPDATE, content_notices" />
				</div>
				<div class="col-md-2 d-flex gap-2">
					<button type="submit" class="btn btn-primary flex-grow-1">필터</button>
					<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light" title="초기화">↺</a>
				</div>
			</form>
			<div class="fs-8 text-gray-600 mt-3 mb-0">
				총 <?= number_format($total) ?>건 · <?= (int) $result['page'] ?> / <?= max(1, $pages) ?> 페이지
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">이벤트 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">최신 순 · DB 저장</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-3 fs-7">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-130px">일시</th>
							<th class="min-w-100px">수행자</th>
							<th class="min-w-140px">행동</th>
							<th class="min-w-120px">대상</th>
							<th>상세</th>
							<th class="min-w-100px">IP</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr><td colspan="6" class="text-center text-muted py-10">기록된 감사 로그가 없습니다.</td></tr>
						<?php else : ?>
						<?php foreach ($rows as $r) : ?>
						<tr>
							<td class="text-gray-800 text-nowrap"><?= htmlspecialchars((string) $r['at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="font-monospace"><?= htmlspecialchars((string) $r['actor'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light-primary"><?= htmlspecialchars((string) $r['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="font-monospace text-break"><?= htmlspecialchars((string) $r['target'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars((string) $r['detail'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-muted"><?= htmlspecialchars((string) $r['ip'], ENT_QUOTES, 'UTF-8') ?></td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ($pages > 1) : ?>
			<div class="d-flex justify-content-center gap-2 mt-6">
				<?php if ($page > 1) : ?>
				<a href="<?= htmlspecialchars(audit_page_url($listUrl, $page - 1, $filterQ, $filterActor, $filterPrefix), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">이전</a>
				<?php endif; ?>
				<?php if ($page < $pages) : ?>
				<a href="<?= htmlspecialchars(audit_page_url($listUrl, $page + 1, $filterQ, $filterActor, $filterPrefix), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">다음</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

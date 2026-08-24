<?php

declare(strict_types=1);

require_once INC_PATH . '/AuditLog.php';

$listError = null;
$result = ['rows' => [], 'total' => 0, 'page' => 1, 'limit' => 50, 'pages' => 1];
$needsMigrate = false;

$filterQ = trim((string) ($_GET['q'] ?? ''));
$filterActor = trim((string) ($_GET['actor'] ?? ''));
$filterPrefix = trim((string) ($_GET['action_prefix'] ?? ''));
$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
// 형식이 어긋난 값은 조용히 버린다 — SQL 로 넘겨봐야 안 걸리고 사용자만 헷갈린다.
foreach (['filterFrom', 'filterTo'] as $v) {
    if ($$v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) {
        $$v = '';
    }
}
$page = max(1, (int) ($_GET['page'] ?? 1));
// 페이지당 건수 — 다른 목록 화면(table-paginate)과 같은 선택지를 준다.
$allowedLimits = [20, 30, 50, 100];

// 행동 목록은 **실제로 쌓인 값**에서 뽑는다 — 상수로 박아두면 새 행동이 생겨도 안 보인다.
$actionOptions = [];
if (db_table_exists('audit_logs')) {
    $actionOptions = db_rows(
        "SELECT action, COUNT(*) c FROM audit_logs WHERE action <> '' GROUP BY action ORDER BY action ASC"
    );
}
$limit = (int) ($_GET['limit'] ?? 50);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 50;
}

try {
    if (!AuditLog::tableExists()) {
        $needsMigrate = true;
    } else {
        $result = AuditLog::list([
            'q'             => $filterQ,
            'actor'         => $filterActor,
            'action_prefix' => $filterPrefix,
            'from'          => $filterFrom,
            'to'            => $filterTo,
            'page'          => $page,
            'limit'         => $limit,
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
    'from'          => $filterFrom !== '' ? $filterFrom : null,
    'to'            => $filterTo !== '' ? $filterTo : null,
]));
$exportUrl = $exportBase . ($exportQs !== '' ? ('?' . $exportQs) : '');

/**
 * 페이징·필터 링크.
 *
 * ⚠️ `route` 를 여기서 넣으면 안 된다 — `admin_url()` 이 쿼리형 URL 일 때
 * 이미 `?route=system%2Faudit` 를 담아 돌려주므로, 또 넣으면
 * `?route=...?route=...` 처럼 물음표가 두 번 붙어 링크가 깨진다(실제로 그랬다).
 * 구분자도 `?` 로 고정하지 말고 기존 쿼리 유무를 보고 정해야 한다.
 *
 * 폼(GET)은 사정이 다르다 — 제출 시 action 의 쿼리가 통째로 대체되므로
 * 거기서는 hidden 으로 route 를 따로 넘긴다.
 */
function audit_page_url(string $base, int $page, string $q, string $actor, string $prefix, string $from = '', string $to = '', int $limit = 50): string
{
    $qs = http_build_query(array_filter([
        'limit'         => $limit !== 50 ? $limit : null,
        'q'             => $q !== '' ? $q : null,
        'actor'         => $actor !== '' ? $actor : null,
        'action_prefix' => $prefix !== '' ? $prefix : null,
        'from'          => $from !== '' ? $from : null,
        'to'            => $to !== '' ? $to : null,
        'page'          => $page > 1 ? $page : null,
    ]));
    if ($qs === '') {
        return $base;
    }

    return $base . (str_contains($base, '?') ? '&' : '?') . $qs;
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
				<div class="col-md-3">
					<label class="form-label fs-7">기간</label>
					<?php // 프로젝트 공통 패턴 — inc/shell_close.php 가 싣는 admin-datepickers.js 가 초기화한다. ?>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="전체 기간" />
						<input type="hidden" name="from" data-kt-daterange-from value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
						<input type="hidden" name="to" data-kt-daterange-to value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fs-7" for="audit_q">검색 (대상·상세)</label>
					<input type="search" class="form-control form-control-solid" id="audit_q" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="예: login, notice, withdrawal" autocomplete="off" />
				</div>
				<div class="col-md-2">
					<label class="form-label fs-7" for="audit_actor">수행자</label>
					<input type="text" class="form-control form-control-solid" id="audit_actor" name="actor" value="<?= htmlspecialchars($filterActor, ENT_QUOTES, 'UTF-8') ?>" placeholder="전체" />
				</div>
				<div class="col-md-3">
					<label class="form-label fs-7" for="audit_action_prefix">행동</label>
					<select class="form-select form-select-solid" id="audit_action_prefix" name="action_prefix" data-placeholder="전체">
						<option value=""></option>
						<?php foreach ($actionOptions as $opt) : ?>
						<option value="<?= htmlspecialchars((string) $opt['action'], ENT_QUOTES, 'UTF-8') ?>"<?= $filterPrefix === (string) $opt['action'] ? ' selected' : '' ?>>
							<?= htmlspecialchars((string) $opt['action'], ENT_QUOTES, 'UTF-8') ?> (<?= number_format((int) $opt['c']) ?>)
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-2">
					<?php // 라벨 자리를 비워 두지 않으면 버튼이 인풋보다 위에서 시작해 줄이 어긋난다. ?>
					<label class="form-label fs-7 d-block">&nbsp;</label>
					<?php // text-nowrap 이 없으면 좁은 칸에서 글자가 두 줄로 접히며 버튼만 키가 커진다. ?>
					<div class="d-flex gap-2 flex-nowrap">
						<button type="submit" class="btn btn-primary flex-grow-1 text-nowrap px-4">필터</button>
						<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light flex-shrink-0 text-nowrap px-4" title="조건 초기화">초기화</a>
					</div>
				</div>
			</form>
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
			<?php
			// 페이저 — 다른 목록 화면(assets/js/table-paginate.js)과 같은 모양이지만 **서버 페이징**이다.
			// 감사 로그는 계속 쌓이는 표라 전체를 내려받아 화면에서 나누면 언젠가 못 버틴다.
			$pageUrl = static fn (int $p): string => audit_page_url($listUrl, $p, $filterQ, $filterActor, $filterPrefix, $filterFrom, $filterTo, $limit);
			$from1 = $total > 0 ? (($page - 1) * $limit) + 1 : 0;
			$to1   = min($page * $limit, $total);
			// 번호는 현재 쪽 좌우 2개씩만 — 페이지가 수백 개가 되어도 줄이 넘치지 않게.
			$winFrom = max(1, $page - 2);
			$winTo   = min($pages, $page + 2);
			?>
			<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-6">
				<span class="text-muted fs-7">
					총 <?= number_format($total) ?>건<?= $total > 0 ? ' 중 ' . number_format($from1) . '~' . number_format($to1) : '' ?>
				</span>

				<div class="d-flex align-items-center gap-2">
					<span class="text-muted fs-7">페이지당</span>
					<select class="form-select form-select-sm form-select-solid w-100px" id="audit_limit">
						<?php foreach ($allowedLimits as $n) : ?>
						<option value="<?= $n ?>"<?= $limit === $n ? ' selected' : '' ?>><?= $n ?>건</option>
						<?php endforeach; ?>
					</select>
				</div>

				<?php if ($pages > 1) : ?>
				<div class="d-flex flex-wrap gap-1">
					<a href="<?= htmlspecialchars($pageUrl(1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light<?= $page <= 1 ? ' disabled' : '' ?>">처음</a>
					<?php if ($page > 1) : ?>
					<a href="<?= htmlspecialchars($pageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">이전</a>
					<?php endif; ?>
					<?php for ($p = $winFrom; $p <= $winTo; $p++) : ?>
					<a href="<?= htmlspecialchars($pageUrl($p), ENT_QUOTES, 'UTF-8') ?>"
						class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-light' ?>"><?= $p ?></a>
					<?php endfor; ?>
					<?php if ($page < $pages) : ?>
					<a href="<?= htmlspecialchars($pageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">다음</a>
					<?php endif; ?>
					<a href="<?= htmlspecialchars($pageUrl($pages), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light<?= $page >= $pages ? ' disabled' : '' ?>">마지막</a>
				</div>
				<?php endif; ?>
			</div>
			<script>
				// 페이지당 건수를 바꾸면 1쪽부터 다시 본다 — 현재 쪽 번호를 유지하면
				// 건수가 줄었을 때 존재하지 않는 쪽으로 가 빈 화면이 된다.
				(function () {
					var el = document.getElementById('audit_limit');
					if (!el) return;
					el.addEventListener('change', function () {
						var u = new URL(window.location.href);
						u.searchParams.set('limit', el.value);
						u.searchParams.delete('page');
						window.location.href = u.toString();
					});
				})();
			</script>
		</div>
	</div>
	<?php endif; ?>

<script>
(function () {
	'use strict';
	// 행동 값이 20종을 넘어 그냥 select 로는 찾기 어렵다 — 타이핑으로 좁힐 수 있게 select2 를 건다.
	// (select2 는 Metronic plugins.bundle.js 에 포함돼 있다.)
	function initActionSelect() {
		if (typeof jQuery === 'undefined' || !jQuery.fn.select2) { return; }
		var $el = jQuery('#audit_action_prefix');
		if (!$el.length || $el.hasClass('select2-hidden-accessible')) { return; }
		$el.select2({
			placeholder: $el.data('placeholder') || '전체',
			allowClear: true,
			width: '100%'
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initActionSelect);
	} else {
		initActionSelect();
	}
	// 번들이 늦게 로드되는 경우가 있어 한 번 더 시도한다(실패해도 기본 select 로 동작한다).
	window.addEventListener('load', initActionSelect);
})();
</script>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

require_once INC_PATH . '/ManualDocs.php';

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$docs = ManualDocs::forAudience('admin');
$docId = trim((string) ($_GET['doc'] ?? ''));
if (!ManualDocs::allowedFor($docId, 'admin')) {
    $docId = 'overview';
}

$listUrl = admin_url('docs/manual');
$listSep = str_contains($listUrl, '?') ? '&' : '?';
$linkBuilder = static fn (string $targetId): string => $listUrl . $listSep . 'doc=' . $targetId;

// 검색어(?q=)가 있으면 실시간(JS) 검색과 별개로, JS 없이도(또는 Enter로) 항상 동작하는
// 서버 렌더 검색 결과를 보여준다 — AJAX가 어떤 이유로든 막혀도 검색 자체는 항상 된다.
$q = trim((string) ($_GET['q'] ?? ''));
$searchResults = $q !== '' ? ManualDocs::search($q, 'admin') : [];

$html = ManualDocs::renderHtml($docId, $linkBuilder);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">매뉴얼</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900"><?= $esc(ManualDocs::title($docId)) ?></li>
			</ul>
		</div>
			<div class="d-flex align-items-center" style="min-width: 280px;">
				<form method="get" action="<?= $esc($listUrl) ?>" class="position-relative w-100" id="manual_search_wrap">
					<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
					<input type="hidden" name="route" value="docs/manual" />
					<?php endif; ?>
					<i class="ki-duotone ki-magnifier fs-3 position-absolute top-50 translate-middle-y ms-3 text-gray-500"><span class="path1"></span><span class="path2"></span></i>
					<input type="text" name="q" id="manual_search_input" class="form-control form-control-sm ps-10" placeholder="매뉴얼 검색 (2자 이상, Enter로도 검색)" autocomplete="off" value="<?= $esc($q) ?>" />
					<div id="manual_search_results" class="menu menu-sub menu-sub-dropdown w-350px w-md-450px shadow-sm d-none" style="position:absolute; right:0; top:100%; z-index:105; max-height:420px; overflow-y:auto;"></div>
				</form>
			</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<style>
		.manual-body h2 { font-size: 1.35rem; font-weight: 700; margin-top: 2rem; padding-top: .5rem; border-top: 1px solid var(--bs-gray-200); }
		.manual-body h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
		.manual-body h3 { font-size: 1.1rem; font-weight: 700; margin-top: 1.5rem; }
		.manual-body h4 { font-size: 1rem; font-weight: 700; margin-top: 1.1rem; }
		.manual-body p, .manual-body li { font-size: .95rem; line-height: 1.7; color: var(--bs-gray-800); }
		.manual-body ul, .manual-body ol { padding-left: 1.4rem; }
		.manual-body code { background: var(--bs-gray-100); border-radius: 4px; padding: 1px 5px; font-size: .88em; }
		.manual-body pre { background: var(--bs-gray-100); border-radius: 8px; padding: 1rem; overflow-x: auto; }
		.manual-body pre code { background: transparent; padding: 0; }
		.manual-body blockquote { border-left: 3px solid var(--bs-primary); padding: .25rem 1rem; color: var(--bs-gray-600); background: var(--bs-light-primary); border-radius: 0 6px 6px 0; }
		.manual-body table { width: 100%; border-collapse: collapse; font-size: .9rem; margin: 1rem 0; }
		.manual-body th, .manual-body td { border: 1px solid var(--bs-gray-300); padding: .5rem .75rem; text-align: left; vertical-align: top; }
		.manual-body th { background: var(--bs-gray-100); font-weight: 700; }
		.manual-body hr { border-top: 1px solid var(--bs-gray-300); margin: 1.5rem 0; }
		.manual-nav-link.active { background: var(--bs-light-primary); color: var(--bs-primary); font-weight: 700; }
		.manual-search-item:hover { background: var(--bs-gray-100); }
	</style>

	<div class="row g-6">
		<div class="col-lg-3">
			<div class="card card-flush">
				<div class="card-body py-4">
					<div class="menu menu-column">
						<?php foreach ($docs as $d) : ?>
						<div class="menu-item">
							<a class="menu-link manual-nav-link rounded py-2 px-3<?= $d['id'] === $docId ? ' active' : '' ?>"
								href="<?= $esc($listUrl . $listSep . 'doc=' . $d['id']) ?>">
								<span class="menu-title"><?= $esc($d['title']) ?></span>
							</a>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="col-lg-9">
			<?php if ($q !== '') : ?>
			<div class="card card-flush mb-6">
				<div class="card-body py-5">
					<div class="d-flex justify-content-between align-items-center mb-3">
						<span class="fs-7 text-muted">"<?= $esc($q) ?>" 검색 결과 <?= count($searchResults) ?>건</span>
						<a href="<?= $esc($listUrl . $listSep . 'doc=' . $docId) ?>" class="fs-8">검색어 지우기</a>
					</div>
					<?php if ($searchResults === []) : ?>
					<p class="text-muted fs-7 mb-0">검색 결과가 없습니다. 다른 단어로 시도해 보세요.</p>
					<?php else : ?>
					<div class="d-flex flex-column gap-2">
						<?php foreach ($searchResults as $r) : ?>
						<a href="<?= $esc($listUrl . $listSep . 'doc=' . $r['doc_id'] . '#' . $r['anchor']) ?>" class="d-block border border-gray-200 rounded p-3 text-gray-800 text-hover-primary">
							<div class="fs-8 text-muted mb-1"><?= $esc((string) $r['doc_title']) ?></div>
							<div class="fw-bold fs-7 mb-1"><?= $esc((string) $r['title']) ?></div>
							<?php if ($r['snippet'] !== '') : ?>
							<div class="fs-8 text-gray-600"><?= $esc((string) $r['snippet']) ?></div>
							<?php endif; ?>
						</a>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
			<div class="card card-flush">
				<div class="card-body py-6 manual-body">
					<?php if ($html === '') : ?>
					<p class="text-muted">문서를 찾을 수 없습니다.</p>
					<?php else : ?>
					<?= $html ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<script>
(function () {
	'use strict';
	var input = document.getElementById('manual_search_input');
	var resultsBox = document.getElementById('manual_search_results');
	var apiUrl = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/manual_search.php', JSON_UNESCAPED_UNICODE) ?>;
	var listUrl = <?= json_encode($listUrl, JSON_UNESCAPED_UNICODE) ?>;
	var listSep = listUrl.indexOf('?') !== -1 ? '&' : '?';
	var timer = null;
	var currentReq = null;

	function esc(s) {
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
	}

	function hide() {
		resultsBox.classList.add('d-none');
		resultsBox.innerHTML = '';
	}

	function render(results, query) {
		if (results.length === 0) {
			resultsBox.innerHTML = '<div class="p-4 text-muted fs-7">"' + esc(query) + '"에 대한 검색 결과가 없습니다.</div>';
			resultsBox.classList.remove('d-none');
			return;
		}
		var html = '';
		results.forEach(function (r) {
			var href = listUrl + listSep + 'doc=' + encodeURIComponent(r.doc_id) + '#' + r.anchor;
			html += '<a href="' + href + '" class="manual-search-item d-block text-decoration-none px-4 py-3 border-bottom border-gray-200">'
				+ '<div class="fs-8 text-muted mb-1">' + esc(r.doc_title) + '</div>'
				+ '<div class="fw-bold text-gray-900 fs-7 mb-1">' + esc(r.title) + '</div>'
				+ (r.snippet ? '<div class="fs-8 text-gray-600">' + esc(r.snippet) + '</div>' : '')
				+ '</a>';
		});
		resultsBox.innerHTML = html;
		resultsBox.classList.remove('d-none');
	}

	if (input && resultsBox) {
		input.addEventListener('input', function () {
			var q = input.value.trim();
			clearTimeout(timer);
			if (q.length < 2) {
				hide();
				return;
			}
			timer = setTimeout(function () {
				if (currentReq) currentReq.abort();
				var ctrl = new AbortController();
				currentReq = ctrl;
				fetch(apiUrl + '?q=' + encodeURIComponent(q), { signal: ctrl.signal })
					.then(function (r) {
						if (!r.ok) throw new Error('HTTP ' + r.status);
						return r.json();
					})
					.then(function (data) {
						if (!data.ok) throw new Error(data.message || '검색 실패');
						render(data.results || [], q);
					})
					.catch(function (err) {
						if (err && err.name === 'AbortError') return;
						// eslint-disable-next-line no-console
						console.error('매뉴얼 실시간 검색 실패(Enter를 눌러 다시 시도할 수 있습니다):', err);
						resultsBox.innerHTML = '<div class="p-4 text-danger fs-7">실시간 검색에 실패했습니다. Enter 키를 눌러 검색해 주세요.</div>';
						resultsBox.classList.remove('d-none');
					});
			}, 250);
		});
	}

	document.addEventListener('click', function (e) {
		if (!document.getElementById('manual_search_wrap').contains(e.target)) {
			hide();
		}
	});
})();
</script>

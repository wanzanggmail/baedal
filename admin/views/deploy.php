<?php

declare(strict_types=1);

require_once INC_PATH . '/Deployer.php';

if (!admin_has_role('super')) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">배포는 본사 최고관리자만 실행할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$esc      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$apiUrl   = ADMIN_BASE . '/api/deploy.php';
$ready    = Deployer::ready();
$current  = $ready ? Deployer::currentCommit() : null;
// 배포 이력(릴리즈 노트) — 배포 서버가 아니어도 지난 기록은 보여준다(기록은 DB에 있다).
// 첫 페이지만 서버에서 그리고, 스크롤이 바닥에 닿으면 JS 가 이어서 불러온다(무한 스크롤).
$histPage = 15;
$history  = Deployer::history($histPage + 1);
$histMore = count($history) > $histPage;
$history  = array_slice($history, 0, $histPage);
$histTotal = db_table_exists('deploy_history')
    ? (int) (db_row('SELECT COUNT(*) c FROM deploy_history')['c'] ?? 0)
    : 0;
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">배포</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">코드 배포(production)</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if (!$ready) : ?>
	<div class="alert alert-warning mb-6">
		이 서버는 <strong>git 배포 대상이 아닙니다</strong>(rsync로 배포되는 서버 — 예: 테스트 서버).
		production 서버(Deploy Key로 git clone된 서버)에서만 이 기능이 동작합니다.
	</div>
	<?php else : ?>

	<div id="dp_toast" class="alert alert-dismissible d-none mb-6" role="alert"><span id="dp_toast_msg"></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>

	<div class="alert bg-light-info fs-8 p-3 mb-6">ℹ️ GitHub Actions가 SSH로 서버에 접속하는 대신, <strong>서버가 직접 GitHub의 <code>production</code> 브랜치를 당겨옵니다</strong>(SSH는 여전히 본사 IP만 허용). 여기서 「배포 실행」을 눌러야만 실제로 반영됩니다 — 그 자체가 승인 절차입니다.</div>

	<div class="row g-4 g-xl-6">
		<div class="col-xl-6">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">현재 배포된 커밋</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-3"><span class="text-muted">커밋</span> <code id="dp_cur_hash"><?= $esc($current['short']) ?></code></div>
					<div class="mb-3"><span class="text-muted">메시지</span> <span id="dp_cur_subject" class="fw-semibold"><?= $esc($current['subject']) ?></span></div>
					<div class="mb-3"><span class="text-muted">작성자</span> <span id="dp_cur_author"><?= $esc($current['author']) ?></span></div>
					<div><span class="text-muted">일시</span> <span id="dp_cur_date"><?= $esc($current['date']) ?></span></div>
				</div>
			</div>
		</div>

		<div class="col-xl-6">
			<div class="card card-flush">
				<div class="card-header pt-5 flex-wrap gap-2">
					<h3 class="card-title fw-bold">배포 대기 중인 커밋</h3>
					<div class="card-toolbar">
						<button type="button" class="btn btn-sm btn-light-primary" id="dp_check">최신 상태 확인</button>
					</div>
				</div>
				<div class="card-body pt-2 fs-8">
					<div id="dp_pending_empty" class="text-muted py-4 text-center">「최신 상태 확인」을 눌러 GitHub의 production 브랜치를 조회하세요.</div>
					<ul id="dp_pending_list" class="list-unstyled d-none mb-4"></ul>
					<button type="button" class="btn btn-primary w-100 d-none" id="dp_run" disabled>배포 실행</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mt-6">
		<div class="card-header pt-5 flex-wrap gap-2">
			<h3 class="card-title fw-bold">DB 마이그레이션</h3>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light-warning" id="dp_migrate">마이그레이션 실행</button>
			</div>
		</div>
		<div class="card-body pt-2 fs-8">
			<div class="text-muted">
				배포로 코드만 바뀌고 스키마가 안 따라오면 <code>Unknown column ...</code> 같은 오류가 납니다.
				<strong>배포 실행 → 마이그레이션 실행</strong> 순서로 진행하세요.
				반영할 게 없으면 전부 <code>SKIP</code>으로 끝나므로 여러 번 눌러도 안전합니다.
			</div>
			<div class="text-muted mt-2">
				※ 본사 조직·최고관리자·시스템 코드·차감 기본값은 마이그레이션이 함께 만듭니다(없을 때만 생성). 별도 초기 데이터 스크립트는 없습니다.
			</div>
		</div>
	</div>


	<div class="card card-flush mt-6">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">실행 로그</h3></div>
		<div class="card-body pt-2">
			<pre id="dp_output" class="bg-dark text-light rounded p-4 fs-9 mb-0" style="min-height:120px; white-space:pre-wrap; word-break:break-all;">아직 실행 기록이 없습니다.</pre>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('dp_toast'), toastMsg = document.getElementById('dp_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); }
		function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
		function setCurrent(c) {
			document.getElementById('dp_cur_hash').textContent = c.short;
			document.getElementById('dp_cur_subject').textContent = c.subject;
			document.getElementById('dp_cur_author').textContent = c.author;
			document.getElementById('dp_cur_date').textContent = c.date;
		}
		function post(payload) {
			return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); });
		}

		var runBtn = document.getElementById('dp_run');
		var checkBtn = document.getElementById('dp_check');
		var emptyBox = document.getElementById('dp_pending_empty');
		var listBox = document.getElementById('dp_pending_list');

		checkBtn.addEventListener('click', function () {
			checkBtn.disabled = true;
			post({ action: 'check' }).then(function (res) {
				checkBtn.disabled = false;
				if (!res.ok) { showToast(res.message || '확인 실패', false); return; }
				setCurrent(res.current);
				if (res.ahead > 0) {
					emptyBox.classList.add('d-none');
					listBox.classList.remove('d-none');
					listBox.innerHTML = res.commits.map(function (c) {
						return '<li class="mb-2 pb-2 border-bottom"><code>' + esc(c.hash) + '</code> ' + esc(c.subject) + '<div class="text-muted fs-9">' + esc(c.author) + '</div></li>';
					}).join('');
					runBtn.classList.remove('d-none');
					runBtn.disabled = false;
					runBtn.textContent = '배포 실행 (' + res.ahead + '건 반영)';
				} else {
					emptyBox.classList.remove('d-none');
					emptyBox.textContent = '이미 최신 상태입니다. 배포할 새 커밋이 없습니다.';
					listBox.classList.add('d-none');
					runBtn.classList.add('d-none');
				}
			}).catch(function () { checkBtn.disabled = false; showToast('확인 요청 실패', false); });
		});

		var migBtn = document.getElementById('dp_migrate');
		migBtn.addEventListener('click', function () {
			if (!confirm('DB 스키마 마이그레이션을 실행합니다.\n(반영할 게 없으면 아무것도 바뀌지 않습니다)\n계속할까요?')) return;
			migBtn.disabled = true;
			var label = migBtn.textContent;
			migBtn.textContent = '실행 중...';
			post({ action: 'migrate' }).then(function (res) {
				document.getElementById('dp_output').textContent = res.output || '(출력 없음)';
				showToast(res.ok ? '마이그레이션 완료' : (res.message || '마이그레이션 실패'), res.ok);
				migBtn.disabled = false;
				migBtn.textContent = label;
			}).catch(function () {
				migBtn.disabled = false;
				migBtn.textContent = label;
				showToast('마이그레이션 요청 실패', false);
			});
		});

		runBtn.addEventListener('click', function () {
			if (!confirm('production 서버에 실제로 배포합니다. 계속할까요?')) return;
			runBtn.disabled = true;
			runBtn.textContent = '배포 중...';
			post({ action: 'deploy' }).then(function (res) {
				document.getElementById('dp_output').textContent = res.output || '(출력 없음)';
				if (res.current) setCurrent(res.current);
				showToast(res.ok ? '배포 완료' : (res.message || '배포 실패'), res.ok);
				if (res.ok) {
					emptyBox.classList.remove('d-none');
					emptyBox.textContent = '배포 완료 — 최신 상태입니다.';
					listBox.classList.add('d-none');
					runBtn.classList.add('d-none');
				} else {
					runBtn.disabled = false;
					runBtn.textContent = '다시 시도';
				}
			}).catch(function () {
				runBtn.disabled = false;
				runBtn.textContent = '다시 시도';
				showToast('배포 요청 실패', false);
			});
		});
	})();
	</script>

	<?php endif; ?>

	<?php // ── 배포 이력(릴리즈 노트) ──────────────────────────────────────────
	      // 커밋 날짜로는 «언제 서버에 반영됐는지» 를 알 수 없어, 누를 때마다 그 묶음을 저장해 둔다. ?>
	<div class="card card-flush mt-6">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">배포 이력 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format($histTotal) ?>건</span></h3>
		</div>
		<div class="card-body pt-2">
			<?php if ($history === []) : ?>
			<div class="text-gray-500 fs-7 py-6 text-center">
				아직 기록이 없습니다. 다음 배포부터 «언제 · 누가 · 무엇이» 올라갔는지 여기에 쌓입니다.
			</div>
			<?php else : ?>
			<div class="timeline" id="dp_hist_list">
				<?php foreach ($history as $h) :
					$isMig  = (string) $h['kind'] === 'migrate';
					$failed = (int) $h['ok'] !== 1;
					$commits = (array) ($h['commits'] ?? []);
				?>
				<div class="d-flex border-start border-4 border-<?= $failed ? 'danger' : ($isMig ? 'info' : 'success') ?> ps-4 pb-5">
					<div class="flex-grow-1">
						<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
							<span class="badge badge-light-<?= $failed ? 'danger' : ($isMig ? 'info' : 'success') ?>">
								<?= $isMig ? 'DB 마이그레이션' : '배포' ?><?= $failed ? ' 실패' : '' ?>
							</span>
							<span class="fw-bold text-gray-800 fs-7"><?= $esc((string) $h['created_at']) ?></span>
							<span class="text-muted fs-8"><?= $esc((string) ($h['actor_login'] ?? '—')) ?></span>
							<?php if (!$isMig && (int) $h['commit_count'] > 0) : ?>
							<span class="text-muted fs-8">· 커밋 <?= (int) $h['commit_count'] ?>개</span>
							<?php endif; ?>
						</div>
						<div class="text-gray-600 fs-8 mb-2" style="white-space:pre-line"><?= $esc((string) ($h['note'] ?? '')) ?></div>
						<?php if ($commits !== []) : ?>
						<ul class="list-unstyled mb-0">
							<?php foreach ($commits as $c) : ?>
							<li class="fs-8 text-gray-800 mb-1">
								<code class="text-muted"><?= $esc((string) ($c['hash'] ?? '')) ?></code>
								<?= $esc((string) ($c['subject'] ?? '')) ?>
								<span class="text-muted">· <?= $esc((string) ($c['author'] ?? '')) ?></span>
							</li>
							<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php // 스크롤이 여기 닿으면 다음 페이지를 불러온다. 자동 로드가 막히면 버튼으로도 받을 수 있다. ?>
			<div id="dp_hist_more_wrap" class="text-center pt-2 <?= $histMore ? '' : 'd-none' ?>">
				<button type="button" class="btn btn-sm btn-light" id="dp_hist_more">더 보기</button>
				<div class="text-muted fs-8 mt-2 d-none" id="dp_hist_loading">불러오는 중…</div>
			</div>
			<div class="text-muted fs-8 text-center pt-2 d-none" id="dp_hist_end">마지막까지 다 봤습니다.</div>
			<?php endif; ?>
		</div>
	</div>

	<script>
	(function () {
		var list = document.getElementById('dp_hist_list');
		if (!list) { return; }
		var API      = <?= json_encode(ADMIN_BASE . '/api/deploy.php', JSON_UNESCAPED_UNICODE) ?>;
		var PAGE     = <?= (int) $histPage ?>;
		var offset   = <?= (int) count($history) ?>;
		var hasMore  = <?= $histMore ? 'true' : 'false' ?>;
		var loading  = false;
		var wrap     = document.getElementById('dp_hist_more_wrap');
		var btn      = document.getElementById('dp_hist_more');
		var spinner  = document.getElementById('dp_hist_loading');
		var endMsg   = document.getElementById('dp_hist_end');

		function esc(v) {
			return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
			});
		}
		// 서버(PHP)가 그리는 마크업과 같은 모양이어야 이어 붙였을 때 티가 안 난다.
		function row(h) {
			var isMig = h.kind === 'migrate';
			var color = !h.ok ? 'danger' : (isMig ? 'info' : 'success');
			var commits = (h.commits || []).map(function (c) {
				return '<li class="fs-8 text-gray-800 mb-1"><code class="text-muted">' + esc(c.hash) + '</code> '
					+ esc(c.subject) + ' <span class="text-muted">· ' + esc(c.author) + '</span></li>';
			}).join('');
			return '<div class="d-flex border-start border-4 border-' + color + ' ps-4 pb-5"><div class="flex-grow-1">'
				+ '<div class="d-flex flex-wrap align-items-center gap-2 mb-1">'
				+ '<span class="badge badge-light-' + color + '">' + (isMig ? 'DB 마이그레이션' : '배포') + (h.ok ? '' : ' 실패') + '</span>'
				+ '<span class="fw-bold text-gray-800 fs-7">' + esc(h.created_at) + '</span>'
				+ '<span class="text-muted fs-8">' + esc(h.actor_login || '—') + '</span>'
				+ (!isMig && h.commit_count > 0 ? '<span class="text-muted fs-8">· 커밋 ' + h.commit_count + '개</span>' : '')
				+ '</div>'
				+ '<div class="text-gray-600 fs-8 mb-2" style="white-space:pre-line">' + esc(h.note) + '</div>'
				+ (commits ? '<ul class="list-unstyled mb-0">' + commits + '</ul>' : '')
				+ '</div></div>';
		}

		function load() {
			if (loading || !hasMore) { return; }
			loading = true;
			btn.classList.add('d-none');
			spinner.classList.remove('d-none');
			fetch(API, {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
				body: JSON.stringify({ action: 'history', offset: offset, limit: PAGE })
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					loading = false;
					spinner.classList.add('d-none');
					if (!res.ok) { btn.classList.remove('d-none'); return; }
					list.insertAdjacentHTML('beforeend', (res.rows || []).map(row).join(''));
					offset += (res.rows || []).length;
					hasMore = !!res.has_more;
					if (hasMore) { btn.classList.remove('d-none'); }
					else { wrap.classList.add('d-none'); endMsg.classList.remove('d-none'); }
				})
				.catch(function () { loading = false; spinner.classList.add('d-none'); btn.classList.remove('d-none'); });
		}

		btn.addEventListener('click', load);
		// 바닥이 보이면 자동으로 다음 페이지 — 버튼은 자동 로드가 안 될 때를 위한 대비책이다.
		if ('IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				if (entries[0].isIntersecting) { load(); }
			}, { rootMargin: '200px' }).observe(wrap);
		}
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

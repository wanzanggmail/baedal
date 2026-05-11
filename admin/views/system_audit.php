<?php

declare(strict_types=1);

$auditSeed = [
    ['at' => '2026-05-10 09:14', 'actor' => 'super@baedal', 'action' => 'auth.login', 'target' => '세션', 'detail' => '관리자 로그인 성공', 'ip' => '10.0.1.20'],
    ['at' => '2026-05-10 09:18', 'actor' => 'settlement01', 'action' => 'settlement.upload', 'target' => '20260509_settlement.xlsx', 'detail' => '정산 엑셀 업로드(샘플)', 'ip' => '10.0.1.31'],
    ['at' => '2026-05-10 08:42', 'actor' => 'ops_hw', 'action' => 'content.notice.publish', 'target' => 'nt-20260510-001', 'detail' => '공지 게시', 'ip' => '10.0.2.5'],
    ['at' => '2026-05-09 17:05', 'actor' => 'super@baedal', 'action' => 'withdrawal.export', 'target' => 'batch-20260509', 'detail' => '출금 신청 엑셀 다운로드', 'ip' => '10.0.1.20'],
    ['at' => '2026-05-09 16:20', 'actor' => 'settlement01', 'action' => 'promotion.batch', 'target' => 'rule-APR-2026', 'detail' => '프로모션 배치 수동 실행', 'ip' => '10.0.1.31'],
    ['at' => '2026-05-09 14:11', 'actor' => 'viewer_cs', 'action' => 'rider.view', 'target' => 'R-10482', 'detail' => '라이더 상세 조회', 'ip' => '192.168.40.12'],
    ['at' => '2026-05-09 11:33', 'actor' => 'ops_hw', 'action' => 'rider.documents', 'target' => 'R-10490', 'detail' => '가입 서류 메타 확인(목업)', 'ip' => '10.0.2.5'],
    ['at' => '2026-05-08 19:50', 'actor' => 'super@baedal', 'action' => 'admin.role_change', 'target' => 'ops_hw', 'detail' => '역할 검토(샘플 로그)', 'ip' => '10.0.1.20'],
    ['at' => '2026-05-08 15:02', 'actor' => 'settlement01', 'action' => 'deduction.entry', 'target' => 'D-8821', 'detail' => '차감 내역 등록', 'ip' => '10.0.1.31'],
    ['at' => '2026-05-08 09:00', 'actor' => 'system', 'action' => 'job.backup', 'target' => 'db-snapshot', 'detail' => '일일 백업 완료(가정)', 'ip' => '127.0.0.1'],
    ['at' => '2026-05-07 22:15', 'actor' => 'super@baedal', 'action' => 'auth.logout', 'target' => '세션', 'detail' => '로그아웃', 'ip' => '10.0.1.20'],
    ['at' => '2026-05-07 18:40', 'actor' => 'viewer_cs', 'action' => 'stats.export', 'target' => 'summary.csv', 'detail' => '통계 내보내기 조회', 'ip' => '192.168.40.12'],
    ['at' => '2026-05-07 10:05', 'actor' => 'ops_hw', 'action' => 'content.banner.update', 'target' => 'bn-main-01', 'detail' => '배너 이미지 교체', 'ip' => '10.0.2.5'],
    ['at' => '2026-05-06 16:48', 'actor' => 'settlement01', 'action' => 'settlement.apply', 'target' => 'week-202605-1', 'detail' => '정산 반영 확정', 'ip' => '10.0.1.31'],
    ['at' => '2026-05-06 09:30', 'actor' => 'super@baedal', 'action' => 'codes.view', 'target' => 'bank', 'detail' => '마스터 코드 화면 조회', 'ip' => '10.0.1.20'],
];
$auditSeedJson = json_encode($auditSeed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">시스템</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">감사</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">관리자</a>
			<a href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">코드/마스터</a>
			<button type="button" class="btn btn-sm btn-light-primary fw-bold" id="btn_audit_export">CSV 내보내기</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-warning d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-warning me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업</strong>입니다. 아래 시드 로그는 고정 샘플이며, <strong>관리자·코드</strong> 화면에서 저장할 때 쌓인 이벤트는 <code class="fs-8">localStorage</code>에만 추가됩니다. 실제 SIEM·DB 감사 테이블과 다릅니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-4">
					<label class="form-label fs-7" for="audit_q">검색 (행동·대상·상세)</label>
					<input type="search" class="form-control form-control-solid" id="audit_q" placeholder="예: admin, codes, login" autocomplete="off" />
				</div>
				<div class="col-md-3">
					<label class="form-label fs-7" for="audit_actor">수행자</label>
					<input type="text" class="form-control form-control-solid" id="audit_actor" placeholder="전체" />
				</div>
				<div class="col-md-3">
					<label class="form-label fs-7" for="audit_action_prefix">행동 접두사</label>
					<input type="text" class="form-control form-control-solid" id="audit_action_prefix" placeholder="예: admin., codes." />
				</div>
				<div class="col-md-2 d-flex gap-2">
					<button type="button" class="btn btn-primary flex-grow-1" id="audit_apply">필터</button>
					<button type="button" class="btn btn-light" id="audit_clear" title="초기화">↺</button>
				</div>
			</div>
			<div class="fs-8 text-gray-600 mt-3 mb-0" id="audit_count"></div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">이벤트 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">최신 순 · 시드 + 브라우저에 기록된 목업 이벤트</span>
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
					<tbody id="audit_tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var SEED = <?= $auditSeedJson ?>;
		var AUDIT_KEY = 'baedal_audit_log';

		function clientEvents() {
			try {
				var list = JSON.parse(localStorage.getItem(AUDIT_KEY) || '[]');
				return Array.isArray(list) ? list : [];
			} catch (e) {
				return [];
			}
		}
		function merged() {
			return clientEvents().concat(SEED);
		}
		function esc(s) {
			var d = document.createElement('div');
			d.textContent = s == null ? '' : String(s);
			return d.innerHTML;
		}
		var filtered = merged();
		function applyFilter() {
			var q = document.getElementById('audit_q').value.trim().toLowerCase();
			var actor = document.getElementById('audit_actor').value.trim().toLowerCase();
			var ap = document.getElementById('audit_action_prefix').value.trim().toLowerCase();
			filtered = merged().filter(function (r) {
				if (actor && String(r.actor || '').toLowerCase().indexOf(actor) === -1) return false;
				if (ap && String(r.action || '').toLowerCase().indexOf(ap) !== 0) return false;
				if (q) {
					var blob = [r.action, r.target, r.detail, r.actor].join(' ').toLowerCase();
					if (blob.indexOf(q) === -1) return false;
				}
				return true;
			});
			render();
		}
		function render() {
			var tb = document.getElementById('audit_tbody');
			var cnt = document.getElementById('audit_count');
			if (cnt) cnt.textContent = '표시 ' + filtered.length + '건 / 전체 ' + merged().length + '건';
			if (!tb) return;
			tb.innerHTML = filtered
				.map(function (r) {
					return (
						'<tr><td class="text-gray-800 text-nowrap">' +
						esc(r.at) +
						'</td><td class="font-monospace">' +
						esc(r.actor) +
						'</td><td><span class="badge badge-light-primary">' +
						esc(r.action) +
						'</span></td><td class="font-monospace">' +
						esc(r.target) +
						'</td><td class="text-gray-700">' +
						esc(r.detail) +
						'</td><td class="text-muted">' +
						esc(r.ip) +
						'</td></tr>'
					);
				})
				.join('');
		}
		document.getElementById('audit_apply').addEventListener('click', applyFilter);
		document.getElementById('audit_clear').addEventListener('click', function () {
			document.getElementById('audit_q').value = '';
			document.getElementById('audit_actor').value = '';
			document.getElementById('audit_action_prefix').value = '';
			filtered = merged();
			render();
		});
		document.getElementById('audit_q').addEventListener('keydown', function (e) {
			if (e.key === 'Enter') applyFilter();
		});
		document.getElementById('btn_audit_export').addEventListener('click', function () {
			var rows = filtered.slice();
			var header = ['일시', '수행자', '행동', '대상', '상세', 'IP'];
			var lines = [header.join(',')].concat(
				rows.map(function (r) {
					function c(v) {
						var s = String(v == null ? '' : v).replace(/"/g, '""');
						if (/[",\n]/.test(s)) return '"' + s + '"';
						return s;
					}
					return [c(r.at), c(r.actor), c(r.action), c(r.target), c(r.detail), c(r.ip)].join(',');
				}),
			);
			var blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = 'audit-log-mock-' + new Date().toISOString().slice(0, 10) + '.csv';
			a.click();
			URL.revokeObjectURL(a.href);
		});
		filtered = merged();
		render();
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

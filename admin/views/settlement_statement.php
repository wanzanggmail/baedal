<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';
require_once INC_PATH . '/RiderDebt.php';
require_once INC_PATH . '/RiderStatement.php';
require_once INC_PATH . '/Org.php';

// 본사(super) 또는 대리점(자기 라이더). 총판은 라우트 허용목록에서 제외됨.
$level = admin_org_level();
if ($level !== Org::LEVEL_ADMIN && $level !== Org::LEVEL_AGENCY) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">정산명세서는 본사·대리점만 조회할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}
// 대리점은 「주급 명세서 발급 사용」 설정이 꺼져 있으면 접근 차단(URL 직접 진입 방어).
if ($level === Org::LEVEL_AGENCY && !Org::statementFlag((int) admin_org_id(), 'stmt_weekly_enabled')) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning p-5">정산명세서 발급 기능이 꺼져 있습니다. 본사에 문의하세요.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$won = static fn ($n): string => number_format((int) $n) . '원';
$platLabel = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }
$riderId    = (int) ($_GET['rider'] ?? 0);
$withOrders = (int) ($_GET['orders'] ?? 0) === 1;
$baseUrl    = admin_url('settlement/statement');
$qs = static fn (array $ov): string => $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?')
    . http_build_query(array_merge(['from' => $from, 'to' => $to], $ov));

$scopeAllowed = true;
if ($riderId > 0) {
    // 스코프: 대리점은 자기 라이더만
    $r = db_row('SELECT agency_id FROM riders WHERE id = ? LIMIT 1', [$riderId]);
    $scopeAllowed = $r !== null && Org::canAccessAgency((int) ($r['agency_id'] ?? 0));
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6 st-noprint">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산명세서</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">명세서 발급</li>
			</ul>
		</div>
		<form class="d-flex align-items-end gap-2" method="get">
			<input type="hidden" name="route" value="settlement/statement" />
			<?php if ($riderId > 0) : ?><input type="hidden" name="rider" value="<?= $riderId ?>" /><?php endif; ?>
			<div><label class="form-label fs-8 mb-1">시작</label><input type="date" name="from" value="<?= $esc($from) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<div><label class="form-label fs-8 mb-1">종료</label><input type="date" name="to" value="<?= $esc($to) ?>" class="form-control form-control-sm form-control-solid" /></div>
			<button class="btn btn-sm btn-primary" type="submit">조회</button>
		</form>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<style>
	@media print {
		.app-sidebar, #kt_app_header, #kt_app_toolbar, .app-footer, .st-noprint { display: none !important; }
		.app-wrapper, .app-main, .app-container, #kt_app_content, #kt_app_content_container { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
		.card { border: none !important; box-shadow: none !important; }
		.st-statement { font-size: 11px; }
		body { background: #fff !important; }
	}
	.st-statement table th, .st-statement table td { padding: .35rem .5rem; }
</style>

<?php if ($riderId > 0 && $scopeAllowed) :
	// ── 명세서 모드 (주급 명세서 레이아웃) ───────────────────────
	$rider = db_row(
		'SELECT r.*, o.name AS agency_name FROM riders r LEFT JOIN organizations o ON o.id = r.agency_id WHERE r.id = ? LIMIT 1',
		[$riderId]
	);
	$st = RiderStatement::build($riderId, $from, $to);
	$sm = $st['summary'];
	$pt = $st['participation'];
	?>
	<div class="d-flex justify-content-end gap-2 mb-4 st-noprint">
		<a href="<?= $esc($qs(['orders' => $withOrders ? 0 : 1, 'rider' => $riderId])) ?>" class="btn btn-sm btn-light-primary"><?= $withOrders ? '오더별 상세 숨기기' : '오더별 상세 포함' ?></a>
		<a href="<?= $esc($qs([])) ?>" class="btn btn-sm btn-light">목록으로</a>
		<button type="button" class="btn btn-sm btn-light-info" id="st_copy_link"
			data-rider="<?= (int) $riderId ?>" data-from="<?= $esc($from) ?>" data-to="<?= $esc($to) ?>"
			data-api="<?= $esc(ADMIN_BASE . '/api/statement_link.php') ?>">
			<i class="ki-duotone ki-link fs-5"><span class="path1"></span><span class="path2"></span></i> 모바일 링크 복사</button>
		<button type="button" class="btn btn-sm btn-primary" onclick="window.print()"><i class="ki-duotone ki-printer fs-5"><span class="path1"></span><span class="path2"></span></i> 인쇄</button>
	</div>
	<div id="st_link_box" class="alert alert-info d-none align-items-center gap-3 mb-4 st-noprint">
		<span class="fw-semibold text-nowrap">모바일 링크</span>
		<input type="text" class="form-control form-control-sm form-control-solid flex-grow-1" id="st_link_url" readonly onclick="this.select()" />
		<button type="button" class="btn btn-sm btn-light-primary text-nowrap" id="st_link_copy2">복사</button>
	</div>
	<script>
	(function () {
		var btn = document.getElementById('st_copy_link');
		if (!btn) return;
		var box = document.getElementById('st_link_box'), urlInput = document.getElementById('st_link_url');
		function copy(text) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return false; });
			}
			try { urlInput.select(); document.execCommand('copy'); return Promise.resolve(true); }
			catch (e) { return Promise.resolve(false); }
		}
		var cachedUrl = '';
		btn.addEventListener('click', function () {
			btn.disabled = true;
			var done = function () { btn.disabled = false; };
			var finish = function (url) {
				cachedUrl = url; urlInput.value = url; box.classList.remove('d-none'); box.classList.add('d-flex');
				copy(url).then(function (ok) {
					btn.innerHTML = ok ? '✔ 복사됨' : '링크 생성됨';
					setTimeout(function () { btn.innerHTML = '<i class="ki-duotone ki-link fs-5"><span class="path1"></span><span class="path2"></span></i> 모바일 링크 복사'; }, 2000);
					done();
				});
			};
			if (cachedUrl) { finish(cachedUrl); return; }
			fetch(btn.getAttribute('data-api'), {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
				body: JSON.stringify({ rider_id: Number(btn.getAttribute('data-rider')), from: btn.getAttribute('data-from'), to: btn.getAttribute('data-to') })
			}).then(function (r) { return r.json(); })
			.then(function (res) { if (!res.ok) throw new Error(res.message || '실패'); finish(res.url); })
			.catch(function (e) { alert(e.message || '링크 생성 실패'); done(); });
		});
		document.getElementById('st_link_copy2').addEventListener('click', function () {
			var b = this;
			copy(cachedUrl || urlInput.value).then(function (ok) {
				b.textContent = ok ? '✔ 복사됨' : '복사 실패';
				setTimeout(function () { b.textContent = '복사'; }, 2000);
			});
		});
	})();
	</script>

	<div class="card card-flush st-statement"><div class="card-body">
		<!--헤더-->
		<div class="text-center mb-4">
			<h1 class="fw-bold mb-2" style="font-size:1.8rem">주급 명세서</h1>
			<div class="fs-7 text-gray-700">기사명: <strong><?= $esc((string) ($rider['name'] ?? '')) ?></strong>
				&nbsp;|&nbsp; 인정구간: <strong><?= (int) $pt['total'] ?>구간</strong>
				&nbsp;|&nbsp; 정산일시: <?= date('Y-m-d H:i') ?></div>
			<div class="fs-8 text-muted mt-1"><?= $esc((string) ($rider['agency_name'] ?? '')) ?> · <?= (int) ($rider['is_daily_settlement'] ?? 0) === 1 ? '선정산' : '주정산' ?> · 정산기간 <?= $esc($from) ?> ~ <?= $esc($to) ?></div>
		</div>

		<!--주간 정산 요약-->
		<h4 class="fs-6 fw-bold text-center mb-2">주간 정산 요약</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-2">
			<thead class="bg-light fw-bold"><tr><th>총 오더수</th><th>정산금액</th><th>프로모션</th><th>프로모션2</th><th>지원금 합계</th></tr></thead>
			<tbody><tr>
				<td><?= number_format($sm['orders']) ?> 건</td>
				<td><?= $won($sm['settle_amount']) ?></td>
				<td><?= (int) $sm['promo'] === 0 ? '0 원 (미지급)' : $won($sm['promo']) ?></td>
				<td><?= $won($sm['promo2']) ?></td>
				<td><?= $won($sm['support']) ?></td>
			</tr></tbody>
		</table>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>차감액</th><th>원천세</th><th>고용보험</th><th>산재보험</th><th>시간제보험</th><th>정산수수료</th><th>선지급차감</th><th>고정차감</th><th>실수령액</th></tr></thead>
			<tbody><tr>
				<td><?= $won($sm['deduction']) ?></td>
				<td><?= $won($sm['withholding']) ?></td>
				<td><?= $won($sm['employment']) ?></td>
				<td><?= $won($sm['accident']) ?></td>
				<td><?= $won($sm['hourly_ins']) ?></td>
				<td><?= $won($sm['agency_fee']) ?></td>
				<td><?= $won($sm['advance']) ?></td>
				<td><?= $won($sm['fixed']) ?></td>
				<td class="fw-bold bg-light-warning"><?= $won($sm['net']) ?></td>
			</tr></tbody>
		</table>

		<!--일자별 상세 내역-->
		<h4 class="fs-6 fw-bold text-center mb-2">일자별 상세 내역</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>근무일자</th><th>오더수</th><th>정산금액</th><th>정산수수료</th><th>정산 예정금액</th><th>선지급금</th><th>차감 후 금액</th></tr></thead>
			<tbody>
				<?php if ($st['daily'] === []) : ?><tr><td colspan="7" class="text-muted py-4">해당 기간 정산 내역이 없습니다.</td></tr>
				<?php else : foreach ($st['daily'] as $d) : ?>
				<tr>
					<td><?= $esc((string) $d['date']) ?></td>
					<td><?= number_format((int) $d['orders']) ?> 건</td>
					<td><?= $won($d['gross']) ?></td>
					<td><?= $won($d['agency']) ?></td>
					<td><?= $won($d['planned']) ?></td>
					<td><?= $won($d['advance']) ?></td>
					<td class="fw-bold"><?= $won($d['after']) ?></td>
				</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>

		<!--추가지원금-->
		<?php if ($st['support_rows'] !== []) : ?>
		<h4 class="fs-6 fw-bold text-center mb-2">추가지원금</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>주문일자</th><th>축약형ID</th><th>구분</th><th>금액</th></tr></thead>
			<tbody>
				<?php foreach ($st['support_rows'] as $sr) : ?>
				<tr>
					<td><?= $esc((string) ($sr['assigned_at'] ?: $sr['settlement_date'])) ?></td>
					<td><?= $esc((string) ($sr['order_no'] ?? '')) ?></td>
					<td><?= $esc((string) ($sr['category'] ?? '')) ?></td>
					<td><?= $won($sr['amount']) ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<!--시간제보험-->
		<?php if ((int) $sm['hourly_ins'] > 0) : ?>
		<h4 class="fs-6 fw-bold text-center mb-2">시간제보험</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>구분</th><th>금액</th></tr></thead>
			<tbody><tr><td>해당 주차 시간제보험료 총액 (쿠팡 정산 기준)</td><td><?= $won($sm['hourly_ins']) ?></td></tr></tbody>
		</table>
		<?php endif; ?>

		<!--참여인정구간 세부 요약-->
		<h4 class="fs-6 fw-bold text-center mb-2">기사별 참여인정구간 세부 요약</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>총 참여인정구간</th><?php foreach (RiderStatement::BUCKETS as $lb) : ?><th><?= $esc($lb) ?></th><?php endforeach; ?></tr></thead>
			<tbody><tr>
				<td class="fw-bold"><?= (int) $pt['total'] ?></td>
				<?php foreach (RiderStatement::BUCKETS as $lb) : ?><td><?= (int) ($pt['scores'][$lb] ?? 0) ?></td><?php endforeach; ?>
			</tr></tbody>
		</table>

		<!--요일/구간별 수행 건수-->
		<h4 class="fs-6 fw-bold text-center mb-2">기사별 요일/구간별 수행 건수</h4>
		<table class="table table-bordered align-middle text-center fs-8 mb-5">
			<thead class="bg-light fw-bold"><tr><th>버킷 \ 요일</th><?php foreach ($pt['weekdays'] as $wd) : ?><th><?= $esc($wd) ?></th><?php endforeach; ?></tr></thead>
			<tbody>
				<?php foreach (RiderStatement::BUCKETS as $lb) : ?>
				<tr>
					<td class="fw-semibold"><?= $esc($lb) ?></td>
					<?php foreach ($pt['weekdays'] as $wd) : $v = (int) ($pt['grid'][$lb][$wd] ?? 0); ?>
					<td class="<?= $v > 0 ? 'bg-light-success fw-bold' : 'text-muted' ?>"><?= $v ?></td>
					<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!--오더별 상세 내역-->
		<h4 class="fs-6 fw-bold text-center mb-2">오더별 상세 내역</h4>
		<?php if ($withOrders) :
			$orders = db_rows(
				"SELECT settlement_date, assigned_at, order_no, store_name, delivery_area, distance_m, net_amount
				   FROM settlement_order_details WHERE rider_id = ? AND settlement_date BETWEEN ? AND ?
				  ORDER BY settlement_date ASC, assigned_at ASC LIMIT 2000",
				[$riderId, $from, $to]
			); ?>
		<div class="text-muted fs-9 mb-1"><?= number_format(count($orders)) ?>건</div>
		<table class="table table-bordered align-middle text-center fs-9">
			<thead class="bg-light fw-bold"><tr><th>일자</th><th>배정시각</th><th>주문번호</th><th>매장</th><th>도착지</th><th>거리(m)</th><th>배달비</th></tr></thead>
			<tbody>
				<?php if ($orders === []) : ?><tr><td colspan="7" class="text-muted py-3">건별 내역이 없습니다.</td></tr>
				<?php else : foreach ($orders as $od) : ?>
				<tr>
					<td><?= $esc((string) $od['settlement_date']) ?></td>
					<td><?= $esc(substr((string) ($od['assigned_at'] ?? ''), 11, 8)) ?></td>
					<td><?= $esc((string) ($od['order_no'] ?? '')) ?></td>
					<td class="text-start"><?= $esc((string) ($od['store_name'] ?? '')) ?></td>
					<td class="text-start"><?= $esc((string) ($od['delivery_area'] ?? '')) ?></td>
					<td class="text-end"><?= number_format((int) ($od['distance_m'] ?? 0)) ?></td>
					<td class="text-end"><?= $won($od['net_amount'] ?? 0) ?></td>
				</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php else : ?>
		<div class="text-center text-muted fs-8 py-3 border border-gray-300 rounded mb-3">상단의 <strong>「오더별 상세 포함」</strong>을 누르면 건별 배달 내역이 표시됩니다.</div>
		<?php endif; ?>

		<div class="text-center text-muted fs-9 mt-4 pt-3 border-top border-gray-300">본 명세서는 도깨비 배달 정산 시스템에서 발행되었습니다.</div>
	</div></div>

<?php elseif ($riderId > 0 && !$scopeAllowed) : ?>
	<div class="alert alert-danger p-5">이 라이더의 명세서를 조회할 권한이 없습니다. <a href="<?= $esc($qs([])) ?>" class="ms-2">목록으로</a></div>

<?php else :
	// ── 목록 모드 ──────────────────────────────────────────────
	[$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
	$where  = 'c.settlement_date BETWEEN ? AND ?';
	$params = [$from, $to];
	if ($scopeSql !== '') { $where .= ' AND ' . $scopeSql; $params = array_merge($params, $scopeParams); }
	$riders = db_rows(
		"SELECT r.id, r.name, r.rider_code, r.is_daily_settlement, o.name AS agency_name,
		        COUNT(DISTINCT c.settlement_date) AS days, SUM(c.order_count) AS orders,
		        SUM(c.gross_amount + c.support_amount) AS gross, SUM(c.total_fee_amount) AS fee, SUM(c.net_amount) AS net
		   FROM settlement_rider_cycles c
		   JOIN riders r ON r.id = c.rider_id
		   LEFT JOIN organizations o ON o.id = r.agency_id
		  WHERE {$where}
		  GROUP BY r.id, r.name, r.rider_code, r.is_daily_settlement, o.name
		  ORDER BY net DESC",
		$params
	);
	?>
	<div class="card card-flush">
		<div class="card-header pt-5"><h3 class="card-title fw-bold">라이더 선택 <span class="text-muted fs-8 fw-normal">· <?= $esc($from) ?> ~ <?= $esc($to) ?> 정산 있는 라이더 <?= number_format(count($riders)) ?>명</span></h3></div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-2">
					<thead><tr class="fw-bold text-muted"><th>라이더</th><th>소속</th><th>유형</th><th class="text-end">정산일수</th><th class="text-end">주문</th><th class="text-end">정산금액</th><th class="text-end">실지급</th><th class="text-end">명세서</th></tr></thead>
					<tbody>
						<?php if ($riders === []) : ?><tr><td colspan="8" class="text-center text-muted py-6">해당 기간 정산 내역이 있는 라이더가 없습니다.</td></tr>
						<?php else : foreach ($riders as $r) : ?>
						<tr>
							<td class="fw-semibold"><?= $esc((string) $r['name']) ?> <span class="text-muted fs-8"><?= $esc((string) $r['rider_code']) ?></span></td>
							<td class="text-muted"><?= $esc((string) ($r['agency_name'] ?? '')) ?></td>
							<td><span class="badge badge-light-<?= (int) $r['is_daily_settlement'] === 1 ? 'warning' : 'primary' ?>"><?= (int) $r['is_daily_settlement'] === 1 ? '선정산' : '주정산' ?></span></td>
							<td class="text-end"><?= number_format((int) $r['days']) ?>일</td>
							<td class="text-end"><?= number_format((int) $r['orders']) ?></td>
							<td class="text-end"><?= $won($r['gross']) ?></td>
							<td class="text-end fw-bold"><?= $won($r['net']) ?></td>
							<td class="text-end"><a href="<?= $esc($qs(['rider' => (int) $r['id']])) ?>" class="btn btn-sm btn-light-primary py-1 px-3">발급</a></td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>
<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

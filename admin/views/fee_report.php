<?php

declare(strict_types=1);

/**
 * 수수료·차감 통합 조회 — 라이더별로 두 시점의 공제를 한 화면에서 본다.
 *   ① 출금 시점: withdrawal_requests.withhold_other (정산수수료)
 *   ② 정산 반영 시점: settlement_fee_items (대행수수료·원천세·보험료·대여금/리스/선지급)
 *
 * ⚠️ 미수금(대여금·리스·선지급)은 "수수료"가 아니라 원금 상환 **차감**이다(rider_debts에 수수료
 *    개념 자체가 없음). 합계에서 수수료와 섞이지 않도록 열을 나눠 표기한다.
 *    참고: LOGIC.md §5.7 · §8-A #3(수수료 구조 갑 확인 대기)
 */

$needsMigrate = !db_table_exists('settlement_fee_items') || !db_table_exists('withdrawal_requests');

$filterFrom = trim((string) ($_GET['from'] ?? ''));
$filterTo   = trim((string) ($_GET['to'] ?? ''));
$filterQ    = trim((string) ($_GET['q'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = date('Y-m-d');
}

/** fee_code → 표시 묶음 */
$bucketOf = static function (string $code): string {
    return match ($code) {
        'agency_fee'                                              => 'agency_fee',
        'withholding'                                             => 'withholding',
        'employment_ins', 'accident_ins', 'hourly_ins', 'ins_refund' => 'insurance',
        'loan', 'lease', 'advance', 'rental'                      => 'debt',
        default                                                   => 'etc',
    };
};

$agg = [];   // rider_id => buckets
$listError = null;

if (!$needsMigrate) {
    try {
        [$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');

        // ── ① 출금 시점 정산수수료 (반려 건 제외) ──
        $wWhere  = ["wr.status <> 'rejected'", 'DATE(wr.requested_at) >= ?', 'DATE(wr.requested_at) <= ?'];
        $wParams = [$filterFrom, $filterTo];
        if ($scopeSql !== '') {
            $wWhere[] = $scopeSql;
            $wParams  = array_merge($wParams, $scopeParams);
        }
        if ($filterQ !== '') {
            $wWhere[] = '(r.name LIKE ? OR r.rider_code LIKE ?)';
            $like     = '%' . $filterQ . '%';
            $wParams  = array_merge($wParams, [$like, $like]);
        }
        foreach (db_rows(
            'SELECT wr.rider_id, COUNT(*) AS cnt, COALESCE(SUM(wr.withhold_other), 0) AS fee
               FROM withdrawal_requests wr
               INNER JOIN riders r ON r.id = wr.rider_id
              WHERE ' . implode(' AND ', $wWhere) . '
              GROUP BY wr.rider_id',
            $wParams
        ) as $w) {
            $rid = (int) $w['rider_id'];
            $agg[$rid]['withdraw_fee']  = (int) $w['fee'];
            $agg[$rid]['withdraw_cnt']  = (int) $w['cnt'];
        }

        // ── ② 정산 반영 시점 차감 항목 ──
        $fWhere  = ['c.settlement_date >= ?', 'c.settlement_date <= ?'];
        $fParams = [$filterFrom, $filterTo];
        if ($scopeSql !== '') {
            $fWhere[] = $scopeSql;
            $fParams  = array_merge($fParams, $scopeParams);
        }
        if ($filterQ !== '') {
            $fWhere[] = '(r.name LIKE ? OR r.rider_code LIKE ?)';
            $like     = '%' . $filterQ . '%';
            $fParams  = array_merge($fParams, [$like, $like]);
        }
        foreach (db_rows(
            'SELECT c.rider_id, fi.fee_code, COALESCE(SUM(fi.amount), 0) AS amt
               FROM settlement_fee_items fi
               INNER JOIN settlement_rider_cycles c ON c.id = fi.cycle_id
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE ' . implode(' AND ', $fWhere) . '
              GROUP BY c.rider_id, fi.fee_code',
            $fParams
        ) as $f) {
            $rid = (int) $f['rider_id'];
            $b   = $bucketOf((string) $f['fee_code']);
            $agg[$rid][$b] = ($agg[$rid][$b] ?? 0) + (int) $f['amt'];
        }

        // ── 라이더 정보 붙이기 ──
        if ($agg !== []) {
            $ids = array_keys($agg);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            foreach (db_rows(
                "SELECT r.id, r.rider_code, r.name, o.name AS agency_name
                   FROM riders r LEFT JOIN organizations o ON o.id = r.agency_id
                  WHERE r.id IN ({$ph})",
                $ids
            ) as $r) {
                $agg[(int) $r['id']]['rider_code']  = (string) $r['rider_code'];
                $agg[(int) $r['id']]['rider_name']  = (string) $r['name'];
                $agg[(int) $r['id']]['agency_name'] = (string) ($r['agency_name'] ?? '');
            }
        }
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

// 수수료 합계(미수금 차감은 제외 — 성격이 다름)
$rows = [];
foreach ($agg as $rid => $a) {
    $feeSum = (int) ($a['withdraw_fee'] ?? 0) + (int) ($a['agency_fee'] ?? 0)
        + (int) ($a['withholding'] ?? 0) + (int) ($a['insurance'] ?? 0) + (int) ($a['etc'] ?? 0);
    $rows[] = [
        'rider_id'     => $rid,
        'rider_code'   => (string) ($a['rider_code'] ?? ''),
        'rider_name'   => (string) ($a['rider_name'] ?? '(삭제된 라이더)'),
        'agency_name'  => (string) ($a['agency_name'] ?? ''),
        'withdraw_fee' => (int) ($a['withdraw_fee'] ?? 0),
        'withdraw_cnt' => (int) ($a['withdraw_cnt'] ?? 0),
        'agency_fee'   => (int) ($a['agency_fee'] ?? 0),
        'withholding'  => (int) ($a['withholding'] ?? 0),
        'insurance'    => (int) ($a['insurance'] ?? 0),
        'etc'          => (int) ($a['etc'] ?? 0),
        'debt'         => (int) ($a['debt'] ?? 0),
        'fee_sum'      => $feeSum,
    ];
}
usort($rows, static fn (array $a, array $b): int => ($b['fee_sum'] + $b['debt']) <=> ($a['fee_sum'] + $a['debt']));

$tot = ['withdraw_fee' => 0, 'agency_fee' => 0, 'withholding' => 0, 'insurance' => 0, 'etc' => 0, 'debt' => 0, 'fee_sum' => 0];
foreach ($rows as $r) {
    foreach ($tot as $k => $_) {
        $tot[$k] += (int) $r[$k];
    }
}

$listUrl   = admin_url('settlement/fee-report');
$detailApi = rtrim(ADMIN_BASE, '/') . '/api/fee_report_detail.php';
$fmtWon    = static fn (int $n): string => number_format($n) . '원';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">수수료·차감 통합 조회</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">정산</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료·차감 통합</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">정산 수수료 내역</a>
			<a href="<?= htmlspecialchars(admin_url('deduction/debts'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">미수금 원장</a>
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

	<div class="alert bg-light-primary d-flex p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			라이더별 공제를 <strong>발생 시점 2가지</strong>로 나눠 집계합니다 —
			<strong>출금 시점</strong>(정산수수료, 출금 신청일 기준)과 <strong>정산 반영 시점</strong>(대행수수료·원천세·보험료, 정산일 기준).
			<span class="d-block mt-1 text-gray-700">
				⚠️ <strong>미수금(대여금·리스·선지급)</strong>은 수수료가 아니라 <strong>원금 상환 차감</strong>이라 수수료 합계와 분리해 표시합니다.
				<br>리스는 걷은 금액을 본사·총판·대리점이 나눠 갖는데, 그 배분 실적은
				<a href="<?= htmlspecialchars(admin_url('deduction/lease-fees'), ENT_QUOTES, 'UTF-8') ?>" class="fw-bold">「리스 수수료 배분」</a> 화면에서 봅니다.
			</span>
		</div>
	</div>

	<!--begin::요약-->
	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">출금 정산수수료</div>
					<div class="fw-bold fs-3 text-danger"><?= $fmtWon($tot['withdraw_fee']) ?></div>
					<div class="text-muted fs-8 mt-1">출금 신청 시점 발생</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">원천세 · 보험료</div>
					<div class="fw-bold fs-3 text-gray-900"><?= $fmtWon($tot['withholding'] + $tot['insurance']) ?></div>
					<div class="text-muted fs-8 mt-1">원천세 <?= $fmtWon($tot['withholding']) ?> · 보험료 <?= $fmtWon($tot['insurance']) ?></div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">수수료 합계</div>
					<div class="fw-bold fs-3 text-gray-900"><?= $fmtWon($tot['fee_sum']) ?></div>
					<div class="text-muted fs-8 mt-1">대행수수료 <?= $fmtWon($tot['agency_fee']) ?> 포함</div>
				</div>
			</div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100">
				<div class="card-body py-6">
					<div class="text-gray-500 fw-semibold fs-7 mb-1">미수금 차감</div>
					<div class="fw-bold fs-3 text-warning"><?= $fmtWon($tot['debt']) ?></div>
					<div class="text-muted fs-8 mt-1">수수료 아님(원금 상환)</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::요약-->

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<form method="get" action="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="row g-4 align-items-end">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="settlement/fee-report" />
				<?php endif; ?>
				<div class="col-md-3">
					<label class="form-label fw-semibold">from</label>
					<input type="date" class="form-control form-control-solid" name="from" value="<?= htmlspecialchars($filterFrom, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-3">
					<label class="form-label fw-semibold">to</label>
					<input type="date" class="form-control form-control-solid" name="to" value="<?= htmlspecialchars($filterTo, ENT_QUOTES, 'UTF-8') ?>" />
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">라이더 검색</label>
					<input type="text" class="form-control form-control-solid" name="q" value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" placeholder="이름, 코드" />
				</div>
				<div class="col-md-2"><button type="submit" class="btn btn-light-primary w-100">조회</button></div>
			</form>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title"><h3 class="fw-bold m-0">라이더별 집계</h3></div>
			<div class="card-toolbar"><span class="text-muted fs-7"><?= number_format(count($rows)) ?>명</span></div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-4" id="feeReportTable">
					<thead>
						<tr class="fw-bold text-muted fs-7 bg-light">
							<th class="min-w-140px">라이더</th>
							<th class="min-w-110px text-end">출금 수수료</th>
							<th class="min-w-100px text-end">대행수수료</th>
							<th class="min-w-90px text-end">원천세</th>
							<th class="min-w-90px text-end">보험료</th>
							<th class="min-w-80px text-end">기타</th>
							<th class="min-w-110px text-end">수수료 합계</th>
							<th class="min-w-130px text-end">미수금 차감</th>
							<th class="min-w-70px text-end">상세</th>
						</tr>
					</thead>
					<tbody>
						<?php if ($rows === []) : ?>
						<tr data-tp-skip><td colspan="9" class="text-center text-muted py-10">해당 기간에 발생한 수수료·차감 내역이 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($rows as $r) : ?>
						<tr>
							<td>
								<?php
								$riderDetailUrl = admin_url('riders/detail');
								$riderDetailUrl .= str_contains($riderDetailUrl, '?') ? '&' : '?';
								$riderDetailUrl .= 'id=' . $r['rider_id'];
								?>
								<a href="<?= htmlspecialchars($riderDetailUrl, ENT_QUOTES, 'UTF-8') ?>" class="fw-bold text-gray-900 text-hover-primary"><?= htmlspecialchars($r['rider_name'], ENT_QUOTES, 'UTF-8') ?></a>
								<?php if ($r['agency_name'] !== '') : ?><span class="text-muted fs-8 d-block"><?= htmlspecialchars($r['agency_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
							</td>
							<td class="text-end">
								<?= $r['withdraw_fee'] > 0 ? '<span class="text-danger fw-semibold">' . $fmtWon($r['withdraw_fee']) . '</span>' : '<span class="text-muted">—</span>' ?>
								<?php if ($r['withdraw_cnt'] > 0) : ?>
								<span class="text-muted fs-8 d-block"><?= (int) $r['withdraw_cnt'] ?>건</span>
								<?php endif; ?>
							</td>
							<td class="text-end"><?= $r['agency_fee'] > 0 ? $fmtWon($r['agency_fee']) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end"><?= $r['withholding'] > 0 ? $fmtWon($r['withholding']) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end"><?= $r['insurance'] > 0 ? $fmtWon($r['insurance']) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end"><?= $r['etc'] > 0 ? $fmtWon($r['etc']) : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end fw-bold text-gray-900"><?= $fmtWon($r['fee_sum']) ?></td>
							<td class="text-end"><?= $r['debt'] > 0 ? '<span class="text-warning fw-semibold">' . $fmtWon($r['debt']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light-primary fr-detail-btn" data-rider="<?= $r['rider_id'] ?>" data-name="<?= htmlspecialchars($r['rider_name'], ENT_QUOTES, 'UTF-8') ?>">내역</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<?php if ($rows !== []) : ?>
					<tfoot>
						<tr class="fw-bold bg-light">
							<td>합계</td>
							<td class="text-end text-danger"><?= $fmtWon($tot['withdraw_fee']) ?></td>
							<td class="text-end"><?= $fmtWon($tot['agency_fee']) ?></td>
							<td class="text-end"><?= $fmtWon($tot['withholding']) ?></td>
							<td class="text-end"><?= $fmtWon($tot['insurance']) ?></td>
							<td class="text-end"><?= $fmtWon($tot['etc']) ?></td>
							<td class="text-end text-gray-900"><?= $fmtWon($tot['fee_sum']) ?></td>
							<td class="text-end text-warning"><?= $fmtWon($tot['debt']) ?></td>
							<td></td>
						</tr>
					</tfoot>
					<?php endif; ?>
				</table>
			</div>
		</div>
	</div>

	<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script>
		var feeReportTable = document.getElementById('feeReportTable');
		if (feeReportTable) {
			initTablePaginate(feeReportTable, { pageSize: 30, unit: '명' });
		}
	</script>

	<!--begin::라이더별 상세 모달-->
	<div class="modal fade" id="kt_fee_detail_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h3 class="modal-title" id="fr_detail_title">수수료·차감 내역</h3>
					<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
				</div>
				<div class="modal-body fs-7" id="fr_detail_body">
					<div class="text-center text-muted py-10">불러오는 중…</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">닫기</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::라이더별 상세 모달-->

	<script>
	(function () {
		'use strict';
		var API  = <?= json_encode($detailApi, JSON_UNESCAPED_UNICODE) ?>;
		var FROM = <?= json_encode($filterFrom, JSON_UNESCAPED_UNICODE) ?>;
		var TO   = <?= json_encode($filterTo, JSON_UNESCAPED_UNICODE) ?>;
		var modalEl = document.getElementById('kt_fee_detail_modal');
		var body = document.getElementById('fr_detail_body');
		var title = document.getElementById('fr_detail_title');

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function won(n) { return (Number(n) || 0).toLocaleString('ko-KR') + '원'; }

		function render(d) {
			var html = '';
			if (!d.items.length) {
				body.innerHTML = '<div class="text-center text-muted py-10">해당 기간 내역이 없습니다.</div>';
				return;
			}
			html += '<table class="table table-row-bordered align-middle gy-2 mb-0">';
			html += '<thead><tr class="fw-bold text-muted fs-8"><th>발생일</th><th>시점</th><th>항목</th><th class="text-end">금액</th></tr></thead><tbody>';
			d.items.forEach(function (it) {
				var badge = it.stage === 'withdraw'
					? '<span class="badge badge-light-danger fs-9">출금</span>'
					: '<span class="badge badge-light-info fs-9">정산반영</span>';
				var amtClass = it.is_debt ? 'text-warning' : 'text-danger';
				html += '<tr><td class="text-gray-700">' + esc(it.date) + '</td><td>' + badge + '</td>'
					+ '<td>' + esc(it.label) + (it.note ? '<span class="text-muted fs-8 d-block">' + esc(it.note) + '</span>' : '') + '</td>'
					+ '<td class="text-end fw-semibold ' + amtClass + '">' + won(it.amount) + '</td></tr>';
			});
			html += '</tbody><tfoot><tr class="fw-bold bg-light">'
				+ '<td colspan="3">수수료 합계</td><td class="text-end text-gray-900">' + won(d.fee_total) + '</td></tr>'
				+ '<tr class="fw-bold"><td colspan="3">미수금 차감</td><td class="text-end text-warning">' + won(d.debt_total) + '</td></tr>'
				+ '</tfoot></table>';
			body.innerHTML = html;
		}

		document.querySelectorAll('.fr-detail-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				title.textContent = btn.getAttribute('data-name') + ' — 수수료·차감 내역';
				body.innerHTML = '<div class="text-center text-muted py-10">불러오는 중…</div>';
				bootstrap.Modal.getOrCreateInstance(modalEl).show();
				fetch(API + '?rider_id=' + btn.getAttribute('data-rider') + '&from=' + encodeURIComponent(FROM) + '&to=' + encodeURIComponent(TO), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '조회 실패');
						render(res);
					})
					.catch(function (e) {
						body.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
					});
			});
		});
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

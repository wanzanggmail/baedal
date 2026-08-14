<?php

declare(strict_types=1);

/**
 * 출금 대행 — 대리점이 소속 라이더의 출금을 대신 신청·실행한다.
 *
 * 라이더가 앱을 못 쓰거나 대리점이 일괄 처리해야 할 때 쓴다. 계산은 라이더 본인 신청과
 * 완전히 같은 경로(`Withdrawal::applyForRider` → `executeTransfers`)를 타므로
 * 수수료·보증금·사이클 점유가 어긋나지 않는다.
 */

require_once INC_PATH . '/Org.php';
require_once INC_PATH . '/WithdrawalConfig.php';
require_once INC_PATH . '/WithdrawalCycles.php';

$isAgency = admin_org_level() === Org::LEVEL_AGENCY;
$agencyId = $isAgency ? admin_org_id() : 0;
$apiUrl   = ADMIN_BASE . '/api/withdrawal_proxy.php';
$today    = date('Y-m-d');

$rows = [];
if ($isAgency) {
    // 출금 대상 후보 = 주정산 라이더 중 지갑에 잔액이 있는 사람.
    // (선정산은 「일일정산 지급」으로 나가므로 여기 대상이 아니다.)
    $rows = db_rows(
        "SELECT r.id, r.name, r.rider_code, r.status, r.withdrawal_hold,
                r.bank_code, r.bank_account,
                COALESCE(w.balance, 0) AS balance,
                sc.label AS bank_label,
                (SELECT COUNT(*) FROM withdrawal_requests wr
                  WHERE wr.rider_id = r.id AND wr.kind = 'rider_manual'
                    AND wr.status IN ('pending','downloaded','failed')) AS open_req
           FROM riders r
           LEFT JOIN rider_wallets w ON w.rider_id = r.id
           LEFT JOIN system_codes sc ON sc.category = 'bank' AND sc.code = r.bank_code
          WHERE r.agency_id = ? AND r.is_daily_settlement = 0
            AND r.status = 'active'
          ORDER BY COALESCE(w.balance, 0) DESC, r.name ASC",
        [$agencyId]
    );
}

$cfg     = WithdrawalConfig::get($agencyId > 0 ? $agencyId : null);
$reserve = (int) $cfg['reserve_amount'];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 대행</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">지급·출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">출금 대행</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">출금 신청 목록</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if (!$isAgency) : ?>
	<div class="alert alert-info mb-8">출금 대행은 <strong>대리점 계정</strong>만 사용할 수 있습니다.</div>
	<?php else : ?>

	<div class="alert bg-light-primary fs-8 p-4 mb-6">
		라이더 대신 출금을 신청·이체합니다. <strong>선택한 일자까지의 정산분</strong>만 나가며,
		지갑에는 보증금 <strong><?= number_format($reserve) ?>원</strong>이 남습니다.
		계산은 라이더가 앱에서 직접 신청할 때와 동일합니다.
		<span class="d-block mt-1 text-danger">「출금하기」를 누르면 <strong>즉시 이체</strong>됩니다. 되돌릴 수 없습니다.</span>
	</div>

	<div id="wp_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="wp_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">주정산 라이더 <span class="text-muted fs-7 ms-1"><?= count($rows) ?>명</span></h3>
			<div class="card-toolbar">
				<div class="d-flex align-items-center position-relative">
					<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
					<input type="text" id="wp_search" class="form-control form-control-sm form-control-solid w-200px ps-11" placeholder="이름·코드 검색" />
				</div>
			</div>
		</div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">라이더</th>
							<th class="min-w-100px">계좌</th>
							<th class="min-w-100px text-end">지갑 잔액</th>
							<th class="min-w-130px">출금 기준일</th>
							<th class="min-w-220px">출금 가능 내역</th>
							<th class="min-w-100px text-end">처리</th>
						</tr>
					</thead>
					<tbody id="wp_tbody">
						<?php if ($rows === []) : ?>
						<tr><td colspan="6" class="text-center text-muted py-8">출금 대상 라이더가 없습니다. (주정산·활동중 라이더만 표시)</td></tr>
						<?php else : foreach ($rows as $r) :
						    $hasBank = trim((string) $r['bank_code']) !== '' && trim((string) $r['bank_account']) !== '';
						    $blocked = !$hasBank
						        || (int) $r['withdrawal_hold'] === 1
						        || (int) $r['open_req'] > 0
						        || (int) $r['balance'] <= 0;
						    ?>
						<tr data-rid="<?= (int) $r['id'] ?>"
							data-search="<?= htmlspecialchars(mb_strtolower($r['name'] . ' ' . $r['rider_code']), ENT_QUOTES, 'UTF-8') ?>">
							<td>
								<span class="fw-bold text-gray-900"><?= htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8') ?></span>
								<div class="text-muted fs-8"><?= htmlspecialchars((string) $r['rider_code'], ENT_QUOTES, 'UTF-8') ?></div>
							</td>
							<td class="text-muted fs-8">
								<?php if ($hasBank) : ?>
									<?= htmlspecialchars((string) ($r['bank_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
								<?php else : ?>
									<span class="badge badge-light-danger">계좌 없음</span>
								<?php endif; ?>
							</td>
							<td class="text-end fw-semibold"><?= number_format((int) $r['balance']) ?>원</td>
							<td>
								<input type="date" class="form-control form-control-sm form-control-solid wp-date"
									value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" max="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" />
							</td>
							<td class="wp-info text-muted fs-8">
								<?php if ((int) $r['withdrawal_hold'] === 1) : ?>
									<span class="text-danger">출금 보류 상태</span>
								<?php elseif ((int) $r['open_req'] > 0) : ?>
									<span class="text-danger">처리 중인 신청이 있습니다</span>
								<?php elseif (!$hasBank) : ?>
									<span class="text-danger">출금 계좌 미등록</span>
								<?php elseif ((int) $r['balance'] <= 0) : ?>
									잔액 없음
								<?php else : ?>
									<span class="text-muted">조회 중…</span>
								<?php endif; ?>
							</td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-primary wp-pay" <?= $blocked ? 'disabled' : '' ?>>출금하기</button>
							</td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
			<div id="wp_noresult" class="text-center text-muted py-6 d-none">검색 결과가 없습니다.</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('wp_toast'), toastMsg = document.getElementById('wp_toast_msg');
		function showToast(m, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = m;
			toast.classList.remove('d-none');
			window.scrollTo(0, 0);
		}
		function won(n) { return (Number(n) || 0).toLocaleString('ko-KR') + '원'; }
		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}

		// 선택한 일자 기준으로 "얼마 나가고 수수료가 얼마인지"를 서버에서 받아 그 행에 표시한다.
		function loadPreview(tr) {
			var rid = tr.getAttribute('data-rid');
			var date = tr.querySelector('.wp-date').value;
			var cell = tr.querySelector('.wp-info');
			var btn = tr.querySelector('.wp-pay');
			cell.innerHTML = '<span class="text-muted">조회 중…</span>';

			fetch(API + '?rider_id=' + rid + '&to=' + encodeURIComponent(date), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					var p = res.preview;

					if (res.block) {
						cell.innerHTML = '<span class="text-danger">' + esc(res.block) + '</span>';
						btn.disabled = true;
						return;
					}

					if (!p.can_apply) {
						var msg = '이 날짜까지는 출금할 정산분이 없습니다.';
						if (p.blocked_shortfall > 0) {
							msg = '보증금(' + won(p.reserve_amount) + ') 때문에 ' + won(p.blocked_shortfall) + ' 더 쌓여야 출금됩니다.';
						}
						cell.innerHTML = '<span class="text-muted">' + esc(msg) + '</span>';
						btn.disabled = true;
						return;
					}

					// 출금 가능 일자(이번에 소진되는 정산일) + 수수료 구간 내역
					var dates = (res.picked || []).map(function (c) { return c.date + '(' + c.orders + '건)'; });
					var feeParts = [];
					if (p.fee_short_orders > 0) feeParts.push(p.fee_short_orders + '건×' + p.fee_rate_short + '원');
					if (p.fee_long_orders > 0) feeParts.push(p.fee_long_orders + '건×' + p.fee_rate_long + '원');

					var h = '';
					h += '<div><span class="text-gray-600">출금 가능 일자</span> <span class="fw-semibold text-gray-800">'
					   + (dates.length ? esc(dates.join(', ')) : '—') + '</span></div>';
					h += '<div class="mt-1"><span class="text-gray-600">수수료</span> <span class="text-danger fw-semibold">− '
					   + won(p.fee) + '</span>'
					   + (feeParts.length ? ' <span class="text-muted">(' + esc(feeParts.join(' + ')) + ')</span>' : '') + '</div>';
					h += '<div class="mt-1"><span class="text-gray-600">실지급액</span> <span class="fw-bold text-primary fs-7">'
					   + won(p.payout_amount) + '</span>'
					   + ' <span class="text-muted">· 보증금 ' + won(p.reserve_amount) + ' 잔류</span></div>';
					cell.innerHTML = h;
					btn.disabled = false;
					btn.setAttribute('data-amount', p.payout_amount);
				})
				.catch(function (e) {
					cell.innerHTML = '<span class="text-danger">' + esc(e.message) + '</span>';
					btn.disabled = true;
				});
		}

		var tbody = document.getElementById('wp_tbody');

		// 최초 진입 시 오늘 날짜 기준으로 전부 조회(막힌 행은 건너뜀)
		tbody.querySelectorAll('tr[data-rid]').forEach(function (tr) {
			if (!tr.querySelector('.wp-pay').disabled) loadPreview(tr);
		});

		tbody.addEventListener('change', function (ev) {
			if (ev.target.classList.contains('wp-date')) loadPreview(ev.target.closest('tr'));
		});

		tbody.addEventListener('click', function (ev) {
			var btn = ev.target.closest('.wp-pay');
			if (!btn || btn.disabled) return;
			var tr = btn.closest('tr');
			var name = tr.querySelector('.fw-bold').textContent.trim();
			var date = tr.querySelector('.wp-date').value;
			var amt = Number(btn.getAttribute('data-amount') || 0);

			if (!confirm(name + '님에게 ' + won(amt) + '을 지금 이체합니다.\n(기준일 ' + date + ')\n\n되돌릴 수 없습니다. 진행할까요?')) return;

			btn.disabled = true;
			btn.textContent = '처리 중…';
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ action: 'apply', rider_id: Number(tr.getAttribute('data-rid')), to: date }),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '출금 실패');
					showToast(res.message, true);
					setTimeout(function () { location.reload(); }, 900);
				})
				.catch(function (e) {
					showToast(e.message, false);
					btn.disabled = false;
					btn.textContent = '출금하기';
					loadPreview(tr);
				});
		});

		// 검색
		var search = document.getElementById('wp_search');
		search.addEventListener('input', function () {
			var q = search.value.trim().toLowerCase();
			var shown = 0;
			tbody.querySelectorAll('tr[data-rid]').forEach(function (tr) {
				var ok = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
				tr.classList.toggle('d-none', !ok);
				if (ok) shown++;
			});
			document.getElementById('wp_noresult').classList.toggle('d-none', shown !== 0);
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

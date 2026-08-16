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

// 행별 상태를 미리 계산한다 — 보증금 미달은 서버에서 바로 판정해 API 호출조차 하지 않는다.
// (잔액 ≤ 보증금이면 출금 가능액이 0이라 계산해 볼 것도 없다.)
$prepared = [];
foreach ($rows as $r) {
    $balance = (int) $r['balance'];
    $hasBank = trim((string) $r['bank_code']) !== '' && trim((string) $r['bank_account']) !== '';
    $below   = $balance <= $reserve;

    // 사유는 **겹쳐서** 보여준다. 하나만 보여주면 "보증금 미달이라 숨겼다"고 해놓고
    // 화면엔 계좌 미등록만 떠서, 왜 감춰졌는지 알 수 없게 된다.
    $reasons = [];
    if ((int) $r['withdrawal_hold'] === 1) {
        $reasons[] = '출금 보류 상태';
    }
    if ((int) $r['open_req'] > 0) {
        $reasons[] = '처리 중인 신청 있음';
    }
    if (!$hasBank) {
        $reasons[] = '출금 계좌 미등록';
    }
    if ($below) {
        $reasons[] = sprintf(
            '보증금 미달(잔액 %s / 보증금 %s · %s원 더 필요)',
            number_format($balance),
            number_format($reserve),
            number_format(max(0, $reserve - $balance) + 1)
        );
    }

    $prepared[] = [
        'r'        => $r,
        'balance'  => $balance,
        'has_bank' => $hasBank,
        'below'    => $below,
        'blocked'  => $reasons !== [],
        'reason'   => implode(' · ', $reasons),
    ];
}

$cntAll   = count($prepared);
$cntBelow = count(array_filter($prepared, static fn (array $p): bool => $p['below']));
$cntReady = $cntAll - $cntBelow;
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
		<div class="card-header pt-5 flex-wrap gap-3">
			<h3 class="card-title fw-bold m-0">
				주정산 라이더
				<span class="text-muted fs-7 ms-1">출금 가능 <?= number_format($cntReady) ?>명 / 전체 <?= number_format($cntAll) ?>명</span>
			</h3>
			<div class="card-toolbar gap-3 flex-wrap">
				<?php // 보증금 미달자는 기본으로 감춘다 — 목록 대부분이 "출금 불가"로 차면 정작 처리할 사람이 안 보인다. ?>
				<div class="d-flex align-items-center gap-4">
					<label class="form-check form-check-sm form-check-custom form-check-solid m-0">
						<input class="form-check-input wp-filter" type="radio" name="wp_scope" value="ready" checked />
						<span class="form-check-label fs-8 fw-semibold">출금 가능만</span>
					</label>
					<label class="form-check form-check-sm form-check-custom form-check-solid m-0">
						<input class="form-check-input wp-filter" type="radio" name="wp_scope" value="all" />
						<span class="form-check-label fs-8 fw-semibold">
							전체 보기
							<?php if ($cntBelow > 0) : ?><span class="text-muted">(보증금 미달 <?= number_format($cntBelow) ?>명 포함)</span><?php endif; ?>
						</span>
					</label>
				</div>
				<div class="d-flex align-items-center position-relative">
					<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
					<input type="text" id="wp_search" class="form-control form-control-sm form-control-solid w-200px ps-11" placeholder="이름·코드 검색" />
				</div>
				<button type="button" class="btn btn-sm btn-primary" id="wp_bulk" disabled>선택 일괄 출금</button>
			</div>
		</div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="w-40px">
								<input class="form-check-input" type="checkbox" id="wp_check_all" title="보이는 출금 가능 건 전체 선택" />
							</th>
							<th class="min-w-140px">라이더</th>
							<th class="min-w-100px">계좌</th>
							<th class="min-w-100px text-end">지갑 잔액</th>
							<th class="min-w-130px">출금 기준일</th>
							<th class="min-w-220px">출금 가능 내역</th>
							<th class="min-w-100px text-end">처리</th>
						</tr>
					</thead>
					<tbody id="wp_tbody">
						<?php if ($prepared === []) : ?>
						<tr><td colspan="7" class="text-center text-muted py-8">출금 대상 라이더가 없습니다. (주정산·활동중 라이더만 표시)</td></tr>
						<?php else : foreach ($prepared as $p) :
						    $r       = $p['r'];
						    $hasBank = $p['has_bank'];
						    $blocked = $p['blocked'];
						    ?>
						<tr data-rid="<?= (int) $r['id'] ?>"
							data-below="<?= $p['below'] ? '1' : '0' ?>"
							class="<?= $p['below'] ? 'd-none' : '' ?>"
							data-search="<?= htmlspecialchars(mb_strtolower($r['name'] . ' ' . $r['rider_code']), ENT_QUOTES, 'UTF-8') ?>">
							<td>
								<?php // 차단 사유가 있으면 애초에 고를 수 없다. 미리보기 결과에 따라 JS가 다시 잠근다. ?>
								<input class="form-check-input wp-check" type="checkbox" <?= $blocked ? 'disabled' : '' ?> />
							</td>
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
								<?php if ($p['reason'] !== '') : ?>
									<span class="<?= $p['below'] ? 'text-muted' : 'text-danger' ?>"><?= htmlspecialchars($p['reason'], ENT_QUOTES, 'UTF-8') ?></span>
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
			<div id="wp_noresult" class="text-center text-muted py-6 d-none">
				<span id="wp_noresult_msg">검색 결과가 없습니다.</span>
			</div>
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

		// 출금 가능 여부가 바뀔 때 버튼과 체크박스를 함께 맞춘다.
		// 불가로 바뀌면 이미 켜둔 체크도 풀어야 한다 — 안 그러면 일괄 출금에 섞여 들어간다.
		function setRowPayable(tr, ok) {
			var btn = tr.querySelector('.wp-pay');
			var chk = tr.querySelector('.wp-check');
			btn.disabled = !ok;
			if (chk) {
				chk.disabled = !ok;
				if (!ok) chk.checked = false;
			}
			syncSelection();
		}

		/** 서버가 준 미리보기 1건을 그 행에 그린다(단건·일괄 공통). */
		function renderPreview(tr, res) {
			var cell = tr.querySelector('.wp-info');
			var btn = tr.querySelector('.wp-pay');
			var p = res.preview;

			if (res.block) {
				cell.innerHTML = '<span class="text-danger">' + esc(res.block) + '</span>';
				setRowPayable(tr, false);
				return;
			}

			if (!p.can_apply) {
				var msg = '이 날짜까지는 출금할 정산분이 없습니다.';
				if (p.blocked_shortfall > 0) {
					msg = '보증금(' + won(p.reserve_amount) + ') 때문에 ' + won(p.blocked_shortfall) + ' 더 쌓여야 출금됩니다.';
				}
				cell.innerHTML = '<span class="text-muted">' + esc(msg) + '</span>';
				setRowPayable(tr, false);
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
			btn.setAttribute('data-amount', p.payout_amount);
			setRowPayable(tr, true);
		}

		function previewFailed(tr, message) {
			tr.querySelector('.wp-info').innerHTML = '<span class="text-danger">' + esc(message) + '</span>';
			setRowPayable(tr, false);
		}

		// 선택한 일자 기준으로 "얼마 나가고 수수료가 얼마인지"를 서버에서 받아 그 행에 표시한다.
		function loadPreview(tr) {
			var rid = tr.getAttribute('data-rid');
			var date = tr.querySelector('.wp-date').value;
			tr.querySelector('.wp-info').innerHTML = '<span class="text-muted">조회 중…</span>';

			fetch(API + '?rider_id=' + rid + '&to=' + encodeURIComponent(date), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					renderPreview(tr, res);
				})
				.catch(function (e) { previewFailed(tr, e.message); });
		}

		/**
		 * 여러 행을 **요청 한 번**으로 조회한다. 예전엔 행마다 fetch를 날렸는데,
		 * 같은 세션의 요청은 PHP 세션 파일 락 때문에 줄서서 처리돼 라이더가 많을수록
		 * 대기가 선형으로 늘었다(85명이면 85번 왕복).
		 * 날짜가 행마다 다를 수 있으므로 **같은 날짜끼리 묶어** 호출한다.
		 */
		function loadPreviews(rows) {
			if (!rows.length) return;
			var byDate = {};
			rows.forEach(function (tr) {
				var d = tr.querySelector('.wp-date').value;
				(byDate[d] = byDate[d] || []).push(tr);
				tr.querySelector('.wp-info').innerHTML = '<span class="text-muted">조회 중…</span>';
			});

			Object.keys(byDate).forEach(function (date) {
				var group = byDate[date];
				var byId = {};
				group.forEach(function (tr) { byId[tr.getAttribute('data-rid')] = tr; });

				fetch(API + '?rider_ids=' + Object.keys(byId).join(',') + '&to=' + encodeURIComponent(date), { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res.ok) throw new Error(res.message || '조회 실패');
						(res.items || []).forEach(function (item) {
							var tr = byId[String(item.id)];
							if (!tr) return;
							if (item.error) { previewFailed(tr, item.error); return; }
							renderPreview(tr, item);
						});
						syncSelection();
					})
					.catch(function (e) {
						group.forEach(function (tr) { previewFailed(tr, e.message); });
					});
			});
		}

		var tbody = document.getElementById('wp_tbody');
		var bulkBtn = document.getElementById('wp_bulk');
		var checkAll = document.getElementById('wp_check_all');

		/** 지금 화면에 보이면서 고를 수 있는 행 (숨겨진 행은 일괄 대상에서 제외한다) */
		function selectableRows() {
			return Array.from(tbody.querySelectorAll('tr[data-rid]')).filter(function (tr) {
				var chk = tr.querySelector('.wp-check');
				return chk && !chk.disabled && !tr.classList.contains('d-none');
			});
		}
		function checkedRows() {
			return selectableRows().filter(function (tr) { return tr.querySelector('.wp-check').checked; });
		}

		/** 선택 개수·합계 표시와 전체선택 체크박스의 상태(부분선택 포함)를 갱신 */
		function syncSelection() {
			var sel = checkedRows();
			var total = sel.reduce(function (s, tr) {
				return s + Number(tr.querySelector('.wp-pay').getAttribute('data-amount') || 0);
			}, 0);

			bulkBtn.disabled = sel.length === 0;
			bulkBtn.textContent = sel.length
				? '선택 일괄 출금 ' + sel.length + '명 · ' + won(total)
				: '선택 일괄 출금';

			var avail = selectableRows();
			checkAll.checked = avail.length > 0 && sel.length === avail.length;
			checkAll.indeterminate = sel.length > 0 && sel.length < avail.length;
			checkAll.disabled = avail.length === 0;
		}

		// 최초 진입 시 오늘 날짜 기준으로 전부 조회(막힌 행은 건너뜀) — 요청 한 번으로 묶는다.
		loadPreviews(Array.from(tbody.querySelectorAll('tr[data-rid]')).filter(function (tr) {
			return !tr.querySelector('.wp-pay').disabled;
		}));
		syncSelection();

		checkAll.addEventListener('change', function () {
			var on = checkAll.checked;
			selectableRows().forEach(function (tr) { tr.querySelector('.wp-check').checked = on; });
			syncSelection();
		});
		tbody.addEventListener('change', function (ev) {
			if (ev.target.classList.contains('wp-check')) syncSelection();
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

		// 검색 + 범위(라디오)를 함께 적용한다. 둘 중 하나만 보고 d-none을 토글하면
		// 검색했다가 지웠을 때 감춰뒀던 보증금 미달자가 같이 튀어나온다.
		var search = document.getElementById('wp_search');
		function currentScope() {
			var checked = document.querySelector('.wp-filter:checked');
			return checked ? checked.value : 'ready';
		}
		function applyFilter() {
			var q = search.value.trim().toLowerCase();
			var scope = currentScope();
			var shown = 0;
			tbody.querySelectorAll('tr[data-rid]').forEach(function (tr) {
				var matchQ = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
				var matchScope = scope === 'all' || tr.getAttribute('data-below') !== '1';
				var ok = matchQ && matchScope;
				tr.classList.toggle('d-none', !ok);
				// 숨겨지는 행의 체크는 푼다 — 안 보이는 사람이 일괄 출금에 딸려 나가면 안 된다.
				if (!ok) {
					var chk = tr.querySelector('.wp-check');
					if (chk) chk.checked = false;
				}
				if (ok) shown++;
			});
			syncSelection();
			document.getElementById('wp_noresult').classList.toggle('d-none', shown !== 0);
			// 검색 때문인지, "출금 가능만" 필터 때문인지 구분해서 안내한다.
			document.getElementById('wp_noresult_msg').textContent = q
				? '검색 결과가 없습니다.'
				: (scope === 'ready'
					? '지금 출금할 수 있는 라이더가 없습니다. (보증금 미달자는 「전체 보기」에서 확인)'
					: '표시할 라이더가 없습니다.');
		}
		search.addEventListener('input', applyFilter);
		document.querySelectorAll('.wp-filter').forEach(function (el) {
			el.addEventListener('change', applyFilter);
		});

		// ── 일괄 출금 ──────────────────────────────
		// 서버가 건별로 이체하고 실패해도 다음 건을 계속한다(부분 성공 허용, LOGIC §5.4).
		bulkBtn.addEventListener('click', function () {
			var rows = checkedRows();
			if (rows.length === 0) return;

			var items = rows.map(function (tr) {
				return {
					rider_id: Number(tr.getAttribute('data-rid')),
					to: tr.querySelector('.wp-date').value,
					name: tr.querySelector('.fw-bold').textContent.trim(),
					amount: Number(tr.querySelector('.wp-pay').getAttribute('data-amount') || 0),
				};
			});
			var total = items.reduce(function (s, i) { return s + i.amount; }, 0);

			var preview = items.slice(0, 5).map(function (i) { return '· ' + i.name + ' ' + won(i.amount); }).join('\n');
			if (items.length > 5) preview += '\n… 외 ' + (items.length - 5) + '명';

			if (!confirm(items.length + '명에게 합계 ' + won(total) + '을 지금 이체합니다.\n\n'
				+ preview + '\n\n되돌릴 수 없습니다. 진행할까요?')) return;

			bulkBtn.disabled = true;
			bulkBtn.textContent = '처리 중… (0/' + items.length + ')';
			rows.forEach(function (tr) { tr.querySelector('.wp-pay').disabled = true; });

			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({
					action: 'apply_bulk',
					items: items.map(function (i) { return { rider_id: i.rider_id, to: i.to }; }),
				}),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '일괄 출금 실패');
					showToast(res.message, (res.failed || 0) === 0);
					if (res.errors && res.errors.length) {
						alert('처리하지 못한 건:\n\n' + res.errors.slice(0, 15).join('\n')
							+ (res.errors.length > 15 ? '\n… 외 ' + (res.errors.length - 15) + '건' : ''));
					}
					setTimeout(function () { location.reload(); }, 1200);
				})
				.catch(function (e) {
					showToast(e.message, false);
					bulkBtn.disabled = false;
					syncSelection();
					loadPreviews(rows);
				});
		});
	})();
	</script>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

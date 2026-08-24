<?php

declare(strict_types=1);

$apiUrl = ADMIN_BASE . '/api/manual_adjust.php';
$needsMigrate = !db_table_exists('agency_wallets') || !db_table_exists('rider_wallets');

$agencies = $needsMigrate ? [] : db_rows(
    "SELECT id, name, code FROM organizations WHERE level = 'agency' AND is_active = 1 ORDER BY name ASC"
);

// 조정 이력 — ManualAdjust::audit() 가 audit_logs 에 MANUAL_ADJUST 로 남기는 게 유일한 기록처다.
// before/after 는 JSON 이라 화면에서 풀어 보여준다(대상·이름·금액·사유).
$adjLogs = [];
if (db_table_exists('audit_logs')) {
    $targetLabel = [
        'agency_balance' => '대리점 잔액',
        'agency_reserve' => '대리점 예수금',
        'rider_wallet'   => '라이더 지갑',
    ];
    foreach (db_rows(
        "SELECT al.id, al.target_table, al.target_id, al.before_value, al.after_value, al.created_at,
                a.login_id AS admin_login_id
           FROM audit_logs al
           LEFT JOIN admins a ON al.actor_type = 'admin' AND a.id = al.actor_id
          WHERE al.action = 'MANUAL_ADJUST'
          ORDER BY al.id DESC
          LIMIT 500"
    ) as $r) {
        $before = json_decode((string) $r['before_value'], true);
        $after  = json_decode((string) $r['after_value'], true);
        $before = is_array($before) ? $before : [];
        $after  = is_array($after) ? $after : [];

        $target = (string) ($before['target'] ?? '');
        // 금액 키가 대상마다 다르다(balance / reserve). 있는 쪽을 집는다.
        $pick = static function (array $a): ?int {
            foreach (['balance', 'reserve'] as $k) {
                if (array_key_exists($k, $a)) {
                    return (int) $a[$k];
                }
            }

            return null;
        };
        $bAmt = $pick($before);
        $aAmt = $pick($after);

        $adjLogs[] = [
            'id'      => (int) $r['id'],
            'at'      => (string) $r['created_at'],
            'admin'   => (string) ($r['admin_login_id'] ?? '—'),
            'target'  => $targetLabel[$target] ?? ($target !== '' ? $target : (string) $r['target_table']),
            'name'    => (string) ($before['agency'] ?? $before['rider'] ?? $before['name'] ?? ('#' . (string) $r['target_id'])),
            'before'  => $bAmt,
            'after'   => $aAmt,
            'diff'    => ($bAmt !== null && $aAmt !== null) ? $aAmt - $bAmt : null,
            'reason'  => (string) ($after['reason'] ?? ''),
        ];
    }
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산/잔액 수동 조정</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수동 조정</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert alert-dismissible bg-light-danger d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-shield-cross fs-2hx text-danger me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>본사 전용 · 위험 작업.</strong> PG결제·오픈뱅킹 이체 자체를 되돌리지는 않습니다(실제 환불은 PG/은행 콘솔에서 별도 처리).
			여기서는 <strong>시스템 잔액·기록만</strong> 직접 바로잡습니다. 모든 조정은 <strong>사유 필수</strong>이며 변경 전/후 값이 <strong>감사 로그</strong>에 남습니다.
		</div>
	</div>

	<div id="ma_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="ma_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<!-- 라이더 지갑 조정 -->
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">라이더 지갑 조정</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-3">
						<label class="form-label required">대리점</label>
						<select class="form-select form-select-solid" id="ma_wallet_agency_sel" data-control="select2" data-placeholder="대리점을 먼저 선택하세요">
							<option value=""></option>
							<?php foreach ($agencies as $a) : ?>
							<option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="mb-4">
						<select class="form-select form-select-solid" id="ma_wallet_rider_sel" disabled></select>
						<button class="btn btn-light-primary mt-3 w-100" type="button" id="ma_rider_lookup" disabled>조회</button>
					</div>
					<div id="ma_rider_panel" class="d-none">
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">대상</span><span class="fw-bold" id="ma_rider_name">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200 mb-4">
							<span class="text-muted">현재 잔액</span><span class="fw-bold" id="ma_rider_cur">—</span>
						</div>
						<input type="hidden" id="ma_rider_id" value="" />
						<div class="mb-3">
							<label class="form-label required">변경할 잔액 (원)</label>
							<input type="number" class="form-control form-control-solid" id="ma_rider_bal" min="0" step="1" />
						</div>
						<div class="mb-3">
							<label class="form-label required">사유</label>
							<input type="text" class="form-control form-control-solid" id="ma_rider_reason" maxlength="300" placeholder="예: 이중 정산 반영 정정" />
						</div>
						<button type="button" class="btn btn-danger" id="ma_rider_save">잔액 조정</button>
					</div>
				</div>
			</div>
		</div>

		<!-- 대리점 지갑 조정 -->
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">대리점 지갑 조정</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-4">
						<select class="form-select form-select-solid" id="ma_agency_sel" data-control="select2" data-placeholder="대리점 선택…">
							<option value=""></option>
							<?php foreach ($agencies as $a) : ?>
							<option value="<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name'] . ' (' . $a['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
							<?php endforeach; ?>
						</select>
						<button class="btn btn-light-primary mt-3 w-100" type="button" id="ma_agency_lookup">조회</button>
					</div>
					<div id="ma_agency_panel" class="d-none">
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">잔액</span><span class="fw-bold" id="ma_ag_balance">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
							<span class="text-muted">라이더 정산금</span><span id="ma_ag_debt">—</span>
						</div>
						<div class="d-flex justify-content-between py-2 border-bottom border-gray-200 mb-4">
							<span class="text-muted">원천세 예수금</span><span id="ma_ag_reserve">—</span>
						</div>
						<div class="mb-3">
							<label class="form-label required">사유</label>
							<input type="text" class="form-control form-control-solid" id="ma_agency_reason" maxlength="300" placeholder="예: PG 결제 취소 반영" />
						</div>
						<div class="row g-3">
							<div class="col-8">
								<label class="form-label">변경할 잔액 (원)</label>
								<input type="number" class="form-control form-control-solid" id="ma_ag_bal" min="0" step="1" />
							</div>
							<div class="col-4 d-flex align-items-end">
								<button type="button" class="btn btn-danger w-100" id="ma_agency_bal_save">잔액</button>
							</div>
							<div class="col-8">
								<label class="form-label">변경할 예수금 (원)</label>
								<input type="number" class="form-control form-control-solid" id="ma_ag_res" min="0" step="1" />
							</div>
							<div class="col-4 d-flex align-items-end">
								<button type="button" class="btn btn-danger w-100" id="ma_agency_res_save">예수금</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('ma_toast');
		var toastMsg = document.getElementById('ma_toast_msg');
		function showToast(msg, ok) {
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }
		function postJson(payload) {
			return fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify(payload),
			}).then(function (r) { return r.json(); });
		}

		// 라이더 — 대리점 먼저 선택 → 그 대리점 소속 라이더만 검색(select2 ajax)
		// jQuery/select2는 plugins.bundle.js에서 오는데 그 스크립트가 이 뷰의 아래(inc/shell_close.php)에
		// 위치해 아직 로드 전이다 — DOMContentLoaded(모든 동기 스크립트 실행 후 발생) 이후로 초기화를 미룬다.
		var RIDERS_API = <?= json_encode(rtrim(ADMIN_BASE, '/') . '/api/riders.php', JSON_UNESCAPED_UNICODE) ?>;
		var lookupBtn = document.getElementById('ma_rider_lookup');

		function initWalletRiderCascade() {
			var walletAgencySel = jQuery('#ma_wallet_agency_sel');
			var walletRiderSel = jQuery('#ma_wallet_rider_sel');

			walletRiderSel.select2({
				placeholder: '라이더 선택',
				allowClear: false,
				ajax: {
					url: RIDERS_API,
					dataType: 'json',
					delay: 250,
					data: function (params) {
						return { q: params.term || '', agency: walletAgencySel.val() || 0, limit: 30 };
					},
					processResults: function (data) {
						return {
							results: (data.items || []).map(function (r) {
								return { id: r.id, text: r.name + (r.phone_masked ? ' (' + r.phone_masked + ')' : '') };
							}),
						};
					},
				},
			});

			walletAgencySel.on('change', function () {
				var agencyId = walletAgencySel.val();
				walletRiderSel.val(null).trigger('change');
				walletRiderSel.prop('disabled', !agencyId);
				lookupBtn.disabled = true;
				document.getElementById('ma_rider_panel').classList.add('d-none');
			});
			walletRiderSel.on('change', function () {
				lookupBtn.disabled = !walletRiderSel.val();
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', initWalletRiderCascade);
		} else {
			initWalletRiderCascade();
		}

		lookupBtn.addEventListener('click', function () {
			var walletRiderSel = jQuery('#ma_wallet_rider_sel');
			var riderId = parseInt(walletRiderSel.val(), 10) || 0;
			if (!riderId) { showToast('라이더를 선택하세요.', false); return; }
			fetch(API + '?type=rider&rider=' + riderId, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					document.getElementById('ma_rider_id').value = res.rider.id;
					document.getElementById('ma_rider_name').textContent = res.rider.code + ' / ' + res.rider.name;
					document.getElementById('ma_rider_cur').textContent = won(res.balance);
					document.getElementById('ma_rider_bal').value = res.balance;
					document.getElementById('ma_rider_panel').classList.remove('d-none');
				})
				.catch(function (e) { showToast(e.message, false); });
		});
		document.getElementById('ma_rider_save').addEventListener('click', function () {
			postJson({
				action: 'adjust_rider',
				rider_id: parseInt(document.getElementById('ma_rider_id').value, 10) || 0,
				balance: parseInt(document.getElementById('ma_rider_bal').value, 10) || 0,
				reason: document.getElementById('ma_rider_reason').value.trim(),
			}).then(function (res) {
				if (!res.ok) throw new Error(res.message);
				showToast(res.message, true);
				document.getElementById('ma_rider_cur').textContent = won(res.result.after);
			}).catch(function (e) { showToast(e.message, false); });
		});

		// 대리점
		function loadAgency() {
			var id = parseInt(document.getElementById('ma_agency_sel').value, 10) || 0;
			if (!id) { showToast('대리점을 선택하세요.', false); return; }
			fetch(API + '?type=agency&agency_id=' + id, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					document.getElementById('ma_ag_balance').textContent = won(res.wallet.balance);
					document.getElementById('ma_ag_debt').textContent = won(res.wallet.rider_debt);
					document.getElementById('ma_ag_reserve').textContent = won(res.wallet.withholding_reserve);
					document.getElementById('ma_ag_bal').value = res.wallet.balance;
					document.getElementById('ma_ag_res').value = res.wallet.withholding_reserve;
					document.getElementById('ma_agency_panel').classList.remove('d-none');
				})
				.catch(function (e) { showToast(e.message, false); });
		}
		document.getElementById('ma_agency_lookup').addEventListener('click', loadAgency);
		function agencyPost(action, field, valId) {
			var id = parseInt(document.getElementById('ma_agency_sel').value, 10) || 0;
			var payload = { action: action, agency_id: id, reason: document.getElementById('ma_agency_reason').value.trim() };
			payload[field] = parseInt(document.getElementById(valId).value, 10) || 0;
			postJson(payload).then(function (res) {
				if (!res.ok) throw new Error(res.message);
				showToast(res.message, true);
				loadAgency();
			}).catch(function (e) { showToast(e.message, false); });
		}
		document.getElementById('ma_agency_bal_save').addEventListener('click', function () { agencyPost('adjust_agency', 'balance', 'ma_ag_bal'); });
		document.getElementById('ma_agency_res_save').addEventListener('click', function () { agencyPost('adjust_reserve', 'reserve', 'ma_ag_res'); });
	})();
	</script>
	<?php endif; ?>

<!--begin::조정 이력-->
<div class="card card-flush mt-8">
	<div class="card-header pt-5">
		<div class="card-title">
			<h3 class="fw-bold m-0">조정 이력</h3>
			<span class="text-gray-500 fs-8 fw-semibold d-block mt-1">
				최근 500건 · 감사 로그(<code>MANUAL_ADJUST</code>)에서 가져옵니다. 조정은 되돌릴 수 없으므로 기록만 남습니다.
			</span>
		</div>
		<div class="card-toolbar">
			<a href="<?= htmlspecialchars(admin_url('system/audit') . (str_contains(admin_url('system/audit'), '?') ? '&' : '?') . 'q=MANUAL_ADJUST', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">감사 로그에서 보기</a>
		</div>
	</div>
	<div class="card-body pt-0">
		<?php if ($adjLogs === []) : ?>
		<div class="text-center text-gray-500 py-10">아직 수동 조정 이력이 없습니다.</div>
		<?php else : ?>
		<div class="table-responsive">
			<table class="table table-row-bordered align-middle fs-8 gy-3" id="adjLogTable">
				<thead>
					<tr class="fw-bold text-muted">
						<th class="min-w-130px">일시</th>
						<th class="min-w-100px">대상</th>
						<th class="min-w-140px">이름</th>
						<th class="min-w-100px text-end">변경 전</th>
						<th class="min-w-100px text-end">변경 후</th>
						<th class="min-w-100px text-end">증감</th>
						<th class="min-w-90px">수행자</th>
						<th class="min-w-220px">사유</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($adjLogs as $l) : ?>
					<tr>
						<td class="text-muted text-nowrap"><?= htmlspecialchars($l['at'], ENT_QUOTES, 'UTF-8') ?></td>
						<td><span class="badge badge-light-primary"><?= htmlspecialchars($l['target'], ENT_QUOTES, 'UTF-8') ?></span></td>
						<td class="fw-semibold text-gray-800"><?= htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8') ?></td>
						<td class="text-end text-gray-700"><?= $l['before'] !== null ? number_format($l['before']) . '원' : '—' ?></td>
						<td class="text-end fw-bold text-gray-900"><?= $l['after'] !== null ? number_format($l['after']) . '원' : '—' ?></td>
						<td class="text-end fw-semibold <?= ($l['diff'] ?? 0) < 0 ? 'text-danger' : 'text-success' ?>">
							<?= $l['diff'] !== null ? (($l['diff'] > 0 ? '+' : '') . number_format($l['diff']) . '원') : '—' ?>
						</td>
						<td class="font-monospace text-gray-700"><?= htmlspecialchars($l['admin'], ENT_QUOTES, 'UTF-8') ?></td>
						<td class="text-gray-700"><?= htmlspecialchars($l['reason'] !== '' ? $l['reason'] : '—', ENT_QUOTES, 'UTF-8') ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<script>
			var adjLogTable = document.getElementById('adjLogTable');
			if (adjLogTable) { initTablePaginate(adjLogTable, { pageSize: 20, unit: '건' }); }
		</script>
		<?php endif; ?>
	</div>
</div>
<!--end::조정 이력-->
<?php require_once INC_PATH . '/app_content_close.php'; ?>

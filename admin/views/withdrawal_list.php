<?php

declare(strict_types=1);

$mockWithdrawals = [
    [
        'id' => 'wd-20260510-008',
        'rider_id' => 'R-104418',
        'rider_name' => '권성진',
        'bank' => '국민은행',
        'account' => '123456-01-789012',
        'holder' => '권성진',
        'amount' => 1850000,
        'requested_at' => '2026-05-10 14:22:11',
        'status' => 'pending',
        'status_label' => '대기',
        'status_class' => 'warning',
    ],
    [
        'id' => 'wd-20260510-007',
        'rider_id' => 'R-203274',
        'rider_name' => '민세훈',
        'bank' => '신한은행',
        'account' => '110-345-678901',
        'holder' => '민세훈',
        'amount' => 920000,
        'requested_at' => '2026-05-10 13:05:00',
        'status' => 'pending',
        'status_label' => '대기',
        'status_class' => 'warning',
    ],
    [
        'id' => 'wd-20260510-006',
        'rider_id' => 'R-880647',
        'rider_name' => '노동현',
        'bank' => '우리은행',
        'account' => '1002-123-456789',
        'holder' => '노동현',
        'amount' => 3400000,
        'requested_at' => '2026-05-10 11:40:33',
        'status' => 'pending',
        'status_label' => '대기',
        'status_class' => 'warning',
    ],
    [
        'id' => 'wd-20260509-005',
        'rider_id' => 'R-551102',
        'rider_name' => '이하늘',
        'bank' => '농협',
        'account' => '302-1234-5678-90',
        'holder' => '이하늘',
        'amount' => 450000,
        'requested_at' => '2026-05-09 18:12:00',
        'status' => 'downloaded',
        'status_label' => '다운로드 완료',
        'status_class' => 'primary',
    ],
    [
        'id' => 'wd-20260509-004',
        'rider_id' => 'R-771203',
        'rider_name' => '박지민',
        'bank' => '카카오뱅크',
        'account' => '3333-12-4567890',
        'holder' => '박지민',
        'amount' => 1280000,
        'requested_at' => '2026-05-09 16:45:22',
        'status' => 'downloaded',
        'status_label' => '다운로드 완료',
        'status_class' => 'primary',
    ],
    [
        'id' => 'wd-20260508-003',
        'rider_id' => 'R-440012',
        'rider_name' => '최유진',
        'bank' => '하나은행',
        'account' => '123-456789-01234',
        'holder' => '최유진',
        'amount' => 2100000,
        'requested_at' => '2026-05-08 09:30:00',
        'status' => 'completed',
        'status_label' => '처리 완료',
        'status_class' => 'success',
    ],
    [
        'id' => 'wd-20260507-002',
        'rider_id' => 'R-991877',
        'rider_name' => '정우성',
        'bank' => 'IBK기업은행',
        'account' => '010-123456-78-901',
        'holder' => '정우성',
        'amount' => 675000,
        'requested_at' => '2026-05-07 15:20:00',
        'status' => 'completed',
        'status_label' => '처리 완료',
        'status_class' => 'success',
    ],
    [
        'id' => 'wd-20260507-001',
        'rider_id' => 'R-112233',
        'rider_name' => '김라이더',
        'bank' => '토스뱅크',
        'account' => '1000-1234-5678',
        'holder' => '김라이더',
        'amount' => 890000,
        'requested_at' => '2026-05-07 10:00:00',
        'status' => 'completed',
        'status_label' => '처리 완료',
        'status_class' => 'success',
    ],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">출금 신청 목록</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">출금</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">신청 목록</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<a href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">
				<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
				은행 파일 다운로드
			</a>
			<a href="<?= htmlspecialchars(admin_url('withdrawal/complete'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">처리 완료 내역</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			<strong>목업 화면</strong>입니다. 「은행 파일 다운로드」 후 <strong>다운로드 완료</strong>인 건은 행별 「입금 완료」 또는 상단 <strong>일괄·선택 입금</strong>으로 처리 완료할 수 있습니다.
		</div>
	</div>

	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-3">
					<label class="form-label fw-semibold">신청일</label>
					<div data-kt-daterange="true">
						<input type="text" class="form-control form-control-solid" data-kt-daterange-display readonly placeholder="기간 선택" />
						<input type="hidden" name="wd_filter_from" data-kt-daterange-from value="2026-05-01" />
						<input type="hidden" name="wd_filter_to" data-kt-daterange-to value="2026-05-10" />
					</div>
				</div>
				<div class="col-md-2">
					<label class="form-label fw-semibold">상태</label>
					<select class="form-select form-select-solid" id="wd_filter_status">
						<option value="" selected>전체</option>
						<option value="pending">대기</option>
						<option value="downloaded">다운로드 완료</option>
						<option value="completed">처리 완료</option>
					</select>
				</div>
				<div class="col-md-4">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" id="wd_filter_q" placeholder="이름, 라이더 ID, 계좌" />
				</div>
				<div class="col-md-3 text-md-end">
					<button type="button" class="btn btn-light-primary" id="wd_filter_apply">목록 필터 (목업)</button>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-2 gap-md-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">신청 내역</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">대기 → 다운로드 완료 → 「입금 완료」 또는 일괄 입금</span>
			</div>
			<div class="card-toolbar gap-2 flex-wrap justify-content-end">
				<button type="button" class="btn btn-sm btn-light-success" id="wd_bulk_complete_selected" title="체크한 다운로드 완료 건만">
					<i class="ki-duotone ki-check fs-5"><span class="path1"></span><span class="path2"></span></i>
					선택 입금 완료
				</button>
				<button type="button" class="btn btn-sm btn-success" id="wd_bulk_complete_all" title="목록에 있는 다운로드 완료 건 전부">
					<i class="ki-duotone ki-check-circle fs-5"><span class="path1"></span><span class="path2"></span></i>
					다운로드 완료 일괄 입금
				</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4" id="wd_table">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="w-40px ps-0">
								<div class="form-check form-check-sm form-check-custom form-check-solid me-1">
									<input class="form-check-input" type="checkbox" id="wd_master_pick" aria-label="다운로드 완료 행 전체 선택" />
								</div>
							</th>
							<th class="min-w-120px">신청 ID</th>
							<th class="min-w-100px">라이더</th>
							<th class="min-w-140px">은행 / 계좌</th>
							<th class="min-w-100px">예금주</th>
							<th class="min-w-100px text-end">금액</th>
							<th class="min-w-140px">신청일시</th>
							<th class="min-w-120px">상태</th>
							<th class="min-w-125px text-end">액션</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockWithdrawals as $row) : ?>
						<tr data-wd-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>" data-wd-status-base="<?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?>">
							<td class="ps-0">
								<div class="form-check form-check-sm form-check-custom form-check-solid me-1">
									<input class="form-check-input wd-bulk-pick d-none" type="checkbox" value="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>" aria-label="선택" disabled />
								</div>
							</td>
							<td class="text-gray-800 fw-semibold"><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="text-gray-800 fw-bold"><?= htmlspecialchars($row['rider_name'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block"><?= htmlspecialchars($row['rider_id'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td>
								<span class="text-gray-800"><?= htmlspecialchars($row['bank'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-7 d-block font-monospace"><?= htmlspecialchars($row['account'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td><?= htmlspecialchars($row['holder'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end fw-bold text-gray-900"><?= number_format((int) $row['amount']) ?>원</td>
							<td class="text-gray-700"><?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>" data-wd-status-badge><?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light-success d-none" data-wd-complete-btn data-wd-complete-id="<?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?>">입금 완료</button>
								<span class="text-muted fs-7" data-wd-no-action>—</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var STORAGE_KEY = 'baedal_withdrawal_mock';
		var labels = {
			pending: { t: '대기', c: 'warning' },
			downloaded: { t: '다운로드 완료', c: 'primary' },
			completed: { t: '처리 완료', c: 'success' }
		};

		function loadOverrides() {
			try {
				return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
			} catch (e) {
				return {};
			}
		}

		function saveOverrides(obj) {
			localStorage.setItem(STORAGE_KEY, JSON.stringify(obj));
		}

		function effectiveStatus(tr) {
			var base = tr.getAttribute('data-wd-status-base');
			var id = tr.getAttribute('data-wd-id');
			var o = loadOverrides()[id];
			if (o === 'completed') return 'completed';
			if (o === 'downloaded' && base === 'pending') return 'downloaded';
			return base;
		}

		function paintRow(tr) {
			var st = effectiveStatus(tr);
			var L = labels[st] || labels.pending;
			var b = tr.querySelector('[data-wd-status-badge]');
			if (b) {
				b.textContent = L.t;
				b.className = 'badge badge-light-' + L.c;
			}
			var btn = tr.querySelector('[data-wd-complete-btn]');
			var dash = tr.querySelector('[data-wd-no-action]');
			if (btn && dash) {
				var showComplete = st === 'downloaded';
				btn.classList.toggle('d-none', !showComplete);
				dash.classList.toggle('d-none', showComplete);
			}
			var cb = tr.querySelector('.wd-bulk-pick');
			if (cb) {
				if (st === 'downloaded') {
					cb.classList.remove('d-none');
					cb.disabled = false;
				} else {
					cb.checked = false;
					cb.classList.add('d-none');
					cb.disabled = true;
				}
			}
		}

		function paintAll() {
			document.querySelectorAll('#wd_table tbody tr[data-wd-id]').forEach(paintRow);
			var master = document.getElementById('wd_master_pick');
			if (master) master.checked = false;
		}

		function markCompletedIds(ids) {
			if (!ids.length) return 0;
			var o = loadOverrides();
			ids.forEach(function (id) { o[id] = 'completed'; });
			saveOverrides(o);
			paintAll();
			applyFilter();
			return ids.length;
		}

		function applyFilter() {
			var st = document.getElementById('wd_filter_status').value;
			var q = (document.getElementById('wd_filter_q').value || '').trim().toLowerCase();
			document.querySelectorAll('#wd_table tbody tr[data-wd-id]').forEach(function (tr) {
				var ok = true;
				if (st && effectiveStatus(tr) !== st) ok = false;
				if (ok && q) {
					var hay = tr.innerText.toLowerCase();
					if (hay.indexOf(q) === -1) ok = false;
				}
				tr.style.display = ok ? '' : 'none';
			});
		}

		paintAll();
		document.getElementById('wd_filter_apply').addEventListener('click', applyFilter);

		document.getElementById('wd_master_pick').addEventListener('change', function () {
			var on = this.checked;
			document.querySelectorAll('#wd_table tbody tr[data-wd-id] .wd-bulk-pick').forEach(function (cb) {
				if (!cb.classList.contains('d-none') && !cb.disabled) cb.checked = on;
			});
		});

		document.getElementById('wd_bulk_complete_all').addEventListener('click', function () {
			var ids = [];
			document.querySelectorAll('#wd_table tbody tr[data-wd-id]').forEach(function (tr) {
				if (effectiveStatus(tr) === 'downloaded') ids.push(tr.getAttribute('data-wd-id'));
			});
			if (!ids.length) {
				window.alert('다운로드 완료 상태인 건이 없습니다. 먼저 은행 파일을 다운로드하세요.');
				return;
			}
			markCompletedIds(ids);
		});

		document.getElementById('wd_bulk_complete_selected').addEventListener('click', function () {
			var ids = [];
			document.querySelectorAll('#wd_table tbody tr[data-wd-id]').forEach(function (tr) {
				var cb = tr.querySelector('.wd-bulk-pick');
				if (cb && cb.checked && !cb.classList.contains('d-none') && effectiveStatus(tr) === 'downloaded') {
					ids.push(tr.getAttribute('data-wd-id'));
				}
			});
			if (!ids.length) {
				window.alert('체크한 건이 없거나, 다운로드 완료가 아닙니다.');
				return;
			}
			markCompletedIds(ids);
		});

		document.getElementById('wd_table').addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-wd-complete-btn]');
			if (!btn) return;
			var tr = btn.closest('tr[data-wd-id]');
			if (!tr || effectiveStatus(tr) !== 'downloaded') return;
			markCompletedIds([tr.getAttribute('data-wd-id')]);
		});
		window.addEventListener('storage', function (e) {
			if (e.key === STORAGE_KEY) paintAll();
		});
		window.addEventListener('focus', paintAll);
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

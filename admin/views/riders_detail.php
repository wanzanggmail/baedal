<?php

declare(strict_types=1);

$riderId = (int) ($_GET['id'] ?? 0);
$listUrl = admin_url('riders/list');
$actionApi = ADMIN_BASE . '/api/rider_action.php?id=' . $riderId;

if ($riderId <= 0) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning p-5">라이더 ID가 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-light-primary ms-3">목록으로</a></div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

// ── DB 조회 ─────────────────────────────────────────────────
$rider = db_row(
    'SELECT r.*,
            sc_bank.label AS bank_label
     FROM riders r
     LEFT JOIN system_codes sc_bank
            ON sc_bank.category = \'bank\' AND sc_bank.code = r.bank_code
     WHERE r.id = ?',
    [$riderId]
);

if (!$rider) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning p-5">라이더를 찾을 수 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-light-primary ms-3">목록으로</a></div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$platforms = db_rows(
    'SELECT platform, external_id FROM rider_platforms WHERE rider_id = ? AND is_connected = 1 ORDER BY id',
    [$riderId]
);

// ── 뷰 변수 준비 ────────────────────────────────────────────
$statusLabel = [
    'active' => '활동 중', 'suspended' => '일시 정지',
    'leave_request' => '탈퇴 요청', 'offboarded' => '계약 종료',
];
$statusBadge = [
    'active' => 'success', 'suspended' => 'danger',
    'leave_request' => 'warning', 'offboarded' => 'dark',
];
$kycLabel     = ['none' => '미진행', 'pending' => '서류 대기', 'verified' => '본인인증 완료', 'rejected' => '인증 거부'];
$kycBadge     = ['none' => 'secondary', 'pending' => 'warning', 'verified' => 'success', 'rejected' => 'danger'];
$vehicleLabel = ['motor' => '오토바이', 'bike' => '자전거', 'car' => '자동차', 'walk' => '도보', 'kick' => '전동킥보드'];
$pfLabel      = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
$teamLabel    = [
    'gangseo_a' => '강서남부 A조', 'gangseo_b' => '강서남부 B조',
    'ydp' => '영등포', 'mapo' => '마포', 'etc' => '기타',
];

$st      = $rider['status'] ?? 'active';
$kyc     = $rider['kyc_status'] ?? 'pending';
$nm      = (string) $rider['name'];
$initial = mb_substr($nm, 0, 1, 'UTF-8');

$phone   = preg_replace('/\D/', '', $rider['phone'] ?? '');
$phoneMsk = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1-****-$2', $phone) ?: '—';

$acct    = $rider['bank_account'] ?? '';
$acctMsk = $acct !== '' && strlen($acct) > 4
             ? substr($acct, 0, 3) . str_repeat('*', max(0, strlen($acct) - 5)) . substr($acct, -2)
             : '****';

$bankDisplay = $rider['bank_label'] ?? $rider['bank_name'] ?? '—';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">
				라이더 상세 — <?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?>
			</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">라이더</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">상세</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">
				<i class="ki-duotone ki-left fs-3"><span class="path1"></span><span class="path2"></span></i>
				목록으로
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<!--begin::Alert (action feedback)-->
	<div id="action_alert" class="d-none mb-6"></div>

	<!--begin::Profile header-->
	<div class="row g-6 g-xl-9 mb-8">
		<div class="col-xl-3">
			<div class="card card-flush h-xl-100">
				<div class="card-body d-flex flex-column text-center pt-10 pb-10">
					<div class="symbol symbol-100px symbol-circle mb-7 mx-auto bg-light-primary">
						<span class="symbol-label fs-2x fw-bold text-primary"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<span class="fs-2 fw-bold text-gray-900"><?= htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') ?></span>
					<span class="text-gray-500 fs-7 fw-semibold mt-1"><?= htmlspecialchars($rider['rider_code'], ENT_QUOTES, 'UTF-8') ?></span>
					<div class="mt-5 d-flex flex-wrap justify-content-center gap-2">
						<span class="badge badge-light-<?= $statusBadge[$st] ?? 'primary' ?> fs-7">
							<?= htmlspecialchars($statusLabel[$st] ?? $st, ENT_QUOTES, 'UTF-8') ?>
						</span>
						<span class="badge badge-light-<?= $kycBadge[$kyc] ?? 'primary' ?> fs-7">
							<?= htmlspecialchars($kycLabel[$kyc] ?? $kyc, ENT_QUOTES, 'UTF-8') ?>
						</span>
						<?php if (!empty($rider['is_daily_settlement'])): ?>
						<span class="badge badge-light-success fs-7" id="daily_badge">일일정산</span>
						<?php else: ?>
						<span class="badge badge-light fs-7" id="daily_badge">주간정산</span>
						<?php endif; ?>
						<?php if (!empty($rider['withdrawal_hold'])): ?>
						<span class="badge badge-light-danger fs-7" id="hold_badge">출금 보류</span>
						<?php endif; ?>
					</div>
					<div class="separator separator-dashed my-6"></div>
					<!--begin::Status change-->
					<div class="d-flex flex-column gap-2">
						<label class="form-label fw-semibold text-start">상태 변경</label>
						<select class="form-select form-select-sm form-select-solid" id="status_select">
							<?php foreach ($statusLabel as $val => $lbl): ?>
							<option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $st === $val ? 'selected' : '' ?>>
								<?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
							</option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="btn btn-sm btn-primary w-100" id="btn_status_change">상태 저장</button>
					</div>
					<!--end::Status change-->
					<div class="separator separator-dashed my-4"></div>
					<div class="form-check form-switch d-flex align-items-center justify-content-center gap-2 mb-3">
						<input class="form-check-input" type="checkbox" id="daily_toggle" <?= !empty($rider['is_daily_settlement']) ? 'checked' : '' ?> />
						<label class="form-check-label fw-semibold" for="daily_toggle">일일정산 대상</label>
					</div>
					<div class="form-check form-switch d-flex align-items-center justify-content-center gap-2">
						<input class="form-check-input" type="checkbox" id="hold_toggle" <?= !empty($rider['withdrawal_hold']) ? 'checked' : '' ?> />
						<label class="form-check-label fw-semibold" for="hold_toggle">출금 보류</label>
					</div>
				</div>
			</div>
		</div>

		<div class="col-xl-9">
			<div class="row g-6">
				<!--begin::Contact info-->
				<div class="col-md-6">
					<div class="card card-flush h-100">
						<div class="card-header pt-5"><h3 class="card-title fw-bold">연락·계정</h3></div>
						<div class="card-body pt-0 fs-6">
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">로그인 ID</span>
								<span class="text-gray-900 font-monospace"><?= htmlspecialchars($rider['login_id'], ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">휴대전화</span>
								<span class="text-gray-900"><?= htmlspecialchars($phoneMsk, ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">이메일</span>
								<span class="text-gray-900"><?= htmlspecialchars($rider['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">생년월일</span>
								<span class="text-gray-900">
									<?= $rider['birth_date'] ? htmlspecialchars(substr((string)$rider['birth_date'], 0, 4) . '-**-**', ENT_QUOTES, 'UTF-8') : '—' ?>
								</span>
							</div>
							<div>
								<span class="text-gray-500 fw-semibold d-block fs-7">최근 앱 접속</span>
								<span class="text-gray-900">
									<?= $rider['last_login_at'] ? htmlspecialchars(substr((string)$rider['last_login_at'], 0, 16), ENT_QUOTES, 'UTF-8') : '—' ?>
								</span>
							</div>
						</div>
					</div>
				</div>
				<!--end::Contact info-->

				<!--begin::Delivery info-->
				<div class="col-md-6">
					<div class="card card-flush h-100">
						<div class="card-header pt-5"><h3 class="card-title fw-bold">소속·배달</h3></div>
						<div class="card-body pt-0 fs-6">
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">팀</span>
								<span class="text-gray-900"><?= htmlspecialchars($teamLabel[$rider['team_code'] ?? ''] ?? ($rider['team_code'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">주 플랫폼</span>
								<?php
								$primaryPf = db_row(
								    'SELECT platform FROM rider_platforms WHERE rider_id = ? AND is_connected = 1 ORDER BY id LIMIT 1',
								    [$riderId]
								);
								?>
								<span class="text-gray-900"><?= htmlspecialchars($pfLabel[$primaryPf['platform'] ?? ''] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">차량</span>
								<span class="text-gray-900"><?= htmlspecialchars($vehicleLabel[$rider['vehicle_type'] ?? ''] ?? ($rider['vehicle_type'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div class="mb-4">
								<span class="text-gray-500 fw-semibold d-block fs-7">활동 지역</span>
								<span class="text-gray-900"><?= htmlspecialchars($rider['address'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
							</div>
							<div>
								<span class="text-gray-500 fw-semibold d-block fs-7">가입일</span>
								<span class="text-gray-900"><?= htmlspecialchars(substr((string)($rider['created_at'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?></span>
							</div>
						</div>
					</div>
				</div>
				<!--end::Delivery info-->
			</div>
		</div>
	</div>
	<!--end::Profile header-->

	<!--begin::Platform & Account row-->
	<div class="row g-6 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">플랫폼 연동</h3></div>
				<div class="card-body pt-0">
					<?php if (empty($platforms)): ?>
					<p class="text-gray-500 fs-7 mb-0">등록된 플랫폼 연동 정보가 없습니다.</p>
					<?php else: ?>
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle gs-0 gy-3">
							<thead>
								<tr class="fw-bold text-muted fs-7">
									<th>플랫폼</th><th>연동</th><th>외부 ID</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($platforms as $p): ?>
								<tr>
									<td><?= htmlspecialchars($pfLabel[$p['platform']] ?? $p['platform'], ENT_QUOTES, 'UTF-8') ?></td>
									<td><span class="badge badge-light-success">연동됨</span></td>
									<td class="font-monospace fs-7"><?= htmlspecialchars($p['external_id'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">정산 계좌</h3></div>
				<div class="card-body pt-0 fs-6">
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">은행</span>
						<span class="text-gray-900"><?= htmlspecialchars($bankDisplay, ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">계좌번호</span>
						<span class="text-gray-900 font-monospace"><?= htmlspecialchars($acctMsk, ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div>
						<span class="text-gray-500 fw-semibold d-block fs-7">예금주</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['account_holder'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Platform & Account row-->

	<!--begin::Contract & Memo row-->
	<div class="row g-6 mb-8">
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">계약·보험</h3></div>
				<div class="card-body pt-0 fs-6">
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">본인인증 완료일</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['id_verified_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">계약 서명일</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['contract_signed_at'] ? substr((string)$rider['contract_signed_at'], 0, 10) : '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">보험</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['insurance_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div>
						<span class="text-gray-500 fw-semibold d-block fs-7">보험 만기</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['insurance_expires'] ? substr((string)$rider['insurance_expires'], 0, 10) : '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">관리자 메모</h3></div>
				<div class="card-body pt-0">
					<textarea class="form-control form-control-solid" rows="6" id="admin_memo_ta"
					          placeholder="메모 없음"><?= htmlspecialchars($rider['admin_memo'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
					<div class="d-flex justify-content-end mt-4">
						<button type="button" class="btn btn-sm btn-primary" id="btn_memo_save">메모 저장</button>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Contract & Memo row-->

<script>
(function () {
	var API = <?= json_encode($actionApi, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

	function showAlert(msg, type) {
		var el = document.getElementById('action_alert');
		el.className = 'alert alert-' + (type || 'danger') + ' mb-6';
		el.textContent = msg;
		window.scrollTo({ top: 0, behavior: 'smooth' });
		setTimeout(function () { el.className = 'd-none mb-6'; el.textContent = ''; }, 4000);
	}

	function apiPatch(payload, successMsg) {
		return fetch(API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (data.ok) {
				showAlert(successMsg || data.message, 'success');
			} else {
				showAlert(data.message || '오류가 발생했습니다.');
			}
		})
		.catch(function () {
			showAlert('네트워크 오류가 발생했습니다.');
		});
	}

	// 상태 저장
	document.getElementById('btn_status_change').addEventListener('click', function () {
		var val = document.getElementById('status_select').value;
		if (!confirm('상태를 변경하시겠습니까?')) return;
		apiPatch({ action: 'status', status: val }, '상태가 변경되었습니다. 화면을 새로 고침하세요.');
	});

	document.getElementById('daily_toggle').addEventListener('change', function () {
		var daily = this.checked;
		apiPatch(
			{ action: 'daily_settlement', daily: daily },
			daily ? '일일정산 대상으로 설정했습니다.' : '주간 정산 대상으로 변경했습니다.'
		);
		var badge = document.getElementById('daily_badge');
		if (badge) {
			badge.textContent = daily ? '일일정산' : '주간정산';
			badge.className = 'badge fs-7 ' + (daily ? 'badge-light-success' : 'badge-light');
		}
	});

	document.getElementById('hold_toggle').addEventListener('change', function () {
		var hold = this.checked;
		apiPatch({ action: 'withdrawal_hold', hold: hold }, hold ? '출금 보류로 설정했습니다.' : '출금 보류를 해제했습니다.');
	});

	// 메모 저장
	document.getElementById('btn_memo_save').addEventListener('click', function () {
		var memo = document.getElementById('admin_memo_ta').value;
		apiPatch({ action: 'memo', memo: memo }, '메모가 저장되었습니다.');
	});
})();
</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

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

// 멀티테넌시: 소속 대리점 스코프 밖이면 차단
if (!Org::canAccessAgency((int) ($rider['agency_id'] ?? 0))) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">이 라이더에 접근할 권한이 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-light-primary ms-3">목록으로</a></div>';
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

// 소속 대리점 (조직 트리)
$agency        = Org::find((int) ($rider['agency_id'] ?? 0));
$agencyDisplay = $agency
    ? trim(((string) ($agency['name'] ?? '')) . (($agency['code'] ?? '') !== '' ? ' (' . $agency['code'] . ')' : ''))
    : '미배정';

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

// 편집 모달용: 은행 목록
$banks = db_rows("SELECT code, label FROM system_codes WHERE category='bank' AND is_active=1 ORDER BY sort_order, label");

// 부채 원장(대여금/리스/선지급)
require_once INC_PATH . '/RiderDebt.php';
$debtReady = RiderDebt::tableReady();
$debts = $debtReady ? RiderDebt::forRider($riderId) : [];
foreach ($debts as &$__d) {
    $__d['entries'] = RiderDebt::entries((int) $__d['id']);
}
unset($__d);
$debtApi = ADMIN_BASE . '/api/debt_action.php';
$debtKindBadge = ['loan' => 'primary', 'lease' => 'warning', 'advance' => 'info'];
$debtStatusLabel = ['active' => '진행 중', 'paused' => '일시중지', 'closed' => '완납/종료'];
$fmtWon = static fn ($n): string => number_format((int) $n) . '원';
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
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_rider_edit_modal">
				<i class="ki-duotone ki-pencil fs-3"><span class="path1"></span><span class="path2"></span></i>
				정보 수정
			</button>
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
					<div class="form-check form-switch d-flex align-items-center justify-content-left gap-2 mb-3">
						<input class="form-check-input" type="checkbox" id="daily_toggle" <?= !empty($rider['is_daily_settlement']) ? 'checked' : '' ?> />
						<label class="form-check-label fw-semibold" for="daily_toggle">일일정산 대상</label>
					</div>
					<div class="form-check form-switch d-flex align-items-center justify-content-left gap-2 mb-3">
						<input class="form-check-input" type="checkbox" id="withholding_toggle" <?= !empty($rider['withholding_tax_enabled']) ? 'checked' : '' ?> />
						<label class="form-check-label fw-semibold" for="withholding_toggle">원천세 공제 대상 </label>
					</div>
					<div class="form-check form-switch d-flex align-items-center justify-content-left gap-2">
						<input class="form-check-input" type="checkbox" id="hold_toggle" <?= !empty($rider['withdrawal_hold']) ? 'checked' : '' ?> />
						<label class="form-check-label fw-semibold" for="hold_toggle">출금 보류</label>
					</div>
					<?php if ((string) ($rider['status'] ?? '') !== 'active') : ?>
					<div class="separator separator-dashed my-4"></div>
					<button type="button" class="btn btn-sm btn-light-danger w-100" id="btn_zero_close">잔여 잔액 0원 종결</button>
					<div class="form-text text-center">탈퇴/정지 라이더의 잔여 지갑 잔액을 0원 이체로 종결합니다.</div>
					<?php endif; ?>
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
								<span class="text-gray-500 fw-semibold d-block fs-7">소속 대리점</span>
								<span class="text-gray-900 fw-semibold"><?= htmlspecialchars($agencyDisplay, ENT_QUOTES, 'UTF-8') ?></span>
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
				<div class="card-header pt-5"><h3 class="card-title fw-bold">플랫폼 아이디 연동 <span class="text-muted fs-8">(정산 매칭 키)</span></h3></div>
				<div class="card-body pt-0">
					<?php
					$pfExt = ['coupang' => '', 'baemin' => ''];
					foreach ($platforms as $p) {
					    if (isset($pfExt[$p['platform']]) && $pfExt[$p['platform']] === '') {
					        $pfExt[$p['platform']] = (string) $p['external_id'];
					    }
					}
					?>
					<div class="alert bg-light-info fs-8 p-3 mb-4">쿠팡 정산서는 <strong>성함</strong>(정산서 표기 그대로, 예: 박성준1682), 배민 정산서는 <strong>UserID</strong>(예: adammins)를 넣으면 업로드 시 자동 매칭됩니다. <span class="text-muted">같은 대리점 안에서만 유일하면 됩니다.</span></div>
					<div class="mb-4">
						<label class="form-label fs-7 fw-semibold">쿠팡이츠 성함</label>
						<div class="input-group">
							<input type="text" class="form-control form-control-sm form-control-solid" id="pf_coupang" value="<?= htmlspecialchars($pfExt['coupang'], ENT_QUOTES, 'UTF-8') ?>" placeholder="예: 박성준1682" />
							<button type="button" class="btn btn-sm btn-light-primary pf-save-btn" data-pf="coupang" data-input="pf_coupang">저장</button>
						</div>
					</div>
					<div class="mb-2">
						<label class="form-label fs-7 fw-semibold">배달의민족 UserID</label>
						<div class="input-group">
							<input type="text" class="form-control form-control-sm form-control-solid font-monospace" id="pf_baemin" value="<?= htmlspecialchars($pfExt['baemin'], ENT_QUOTES, 'UTF-8') ?>" placeholder="예: adammins" />
							<button type="button" class="btn btn-sm btn-light-primary pf-save-btn" data-pf="baemin" data-input="pf_baemin">저장</button>
						</div>
						<div class="form-text fs-8">비우고 저장하면 연동이 해제됩니다.</div>
					</div>
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

	<?php if ($debtReady): ?>
	<!--begin::Debt row (대여금/리스/선지급)-->
	<div class="card card-flush mb-8">
		<div class="card-header pt-5 align-items-center">
			<h3 class="card-title fw-bold m-0">부채 · 대여금 / 리스 / 선지급
				<span class="text-muted fs-8 fw-normal ms-2">잔액이 이월되고 정산 반영 시 차감됩니다</span>
			</h3>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light-primary" id="btn_debt_new">
					<i class="ki-duotone ki-plus fs-4"></i>부채 등록
				</button>
			</div>
		</div>
		<div class="card-body pt-2">
			<?php if (empty($debts)): ?>
			<div class="text-gray-500 text-center py-8">등록된 대여금·리스·선지급이 없습니다.</div>
			<?php else: ?>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-3 mb-0">
					<thead>
						<tr class="fw-bold text-muted fs-7">
							<th>종류 · 항목</th>
							<th class="text-end">원금</th>
							<th class="text-end">남은 잔액</th>
							<th class="text-end">일납</th>
							<th>채권자</th>
							<th>미납갱신</th>
							<th>상태</th>
							<th class="text-end">관리</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($debts as $d):
							$dk = (string) $d['kind'];
							$isAmort = in_array($dk, ['loan', 'advance'], true);
						?>
						<tr>
							<td>
								<span class="badge badge-light-<?= $debtKindBadge[$dk] ?? 'secondary' ?> me-2"><?= htmlspecialchars($d['kind_label'], ENT_QUOTES, 'UTF-8') ?></span>
								<span class="fw-semibold text-gray-800"><?= htmlspecialchars((string) ($d['title'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-end text-gray-700"><?= $isAmort ? $fmtWon($d['principal_amount']) : '—' ?></td>
							<td class="text-end fw-bold <?= $isAmort && (int) $d['balance_amount'] > 0 ? 'text-danger' : 'text-gray-500' ?>"><?= $isAmort ? $fmtWon($d['balance_amount']) : '—' ?></td>
							<td class="text-end text-gray-700"><?= (int) $d['daily_amount'] > 0 ? $fmtWon($d['daily_amount']) : '—' ?></td>
							<td class="text-gray-700 fs-7"><?= htmlspecialchars((string) ($d['creditor'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-7"><?= htmlspecialchars((string) ($d['due_updated_on'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
							<td>
								<span class="badge badge-light-<?= $d['status'] === 'active' ? 'success' : ($d['status'] === 'closed' ? 'dark' : 'warning') ?> fs-8"><?= htmlspecialchars($debtStatusLabel[$d['status']] ?? $d['status'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-end text-nowrap">
								<?php if ($d['status'] === 'active'): ?>
								<button type="button" class="btn btn-sm btn-light-danger py-1 px-3 debt-repay-btn"
									data-id="<?= (int) $d['id'] ?>" data-kind="<?= htmlspecialchars($dk, ENT_QUOTES, 'UTF-8') ?>"
									data-title="<?= htmlspecialchars((string) $d['title'], ENT_QUOTES, 'UTF-8') ?>"
									data-daily="<?= (int) $d['daily_amount'] ?>" data-balance="<?= (int) $d['balance_amount'] ?>">차감</button>
								<?php endif; ?>
								<?php if (!empty($d['entries'])): ?>
								<button type="button" class="btn btn-sm btn-light py-1 px-3" data-bs-toggle="collapse" data-bs-target="#debt_hist_<?= (int) $d['id'] ?>">이력 <?= count($d['entries']) ?></button>
								<?php endif; ?>
								<button type="button" class="btn btn-sm btn-icon btn-light-warning debt-edit-btn"
									data-id="<?= (int) $d['id'] ?>" data-title="<?= htmlspecialchars((string) $d['title'], ENT_QUOTES, 'UTF-8') ?>"
									data-daily="<?= (int) $d['daily_amount'] ?>" data-creditor="<?= htmlspecialchars((string) $d['creditor'], ENT_QUOTES, 'UTF-8') ?>"
									data-balance="<?= (int) $d['balance_amount'] ?>" data-status="<?= htmlspecialchars((string) $d['status'], ENT_QUOTES, 'UTF-8') ?>"
									data-amort="<?= $isAmort ? 1 : 0 ?>" title="수정">
									<i class="ki-duotone ki-pencil fs-5"><span class="path1"></span><span class="path2"></span></i>
								</button>
							</td>
						</tr>
						<?php if (!empty($d['entries'])): ?>
						<tr class="collapse" id="debt_hist_<?= (int) $d['id'] ?>">
							<td colspan="8" class="bg-light-secondary rounded">
								<div class="fw-semibold fs-8 text-muted mb-2">차감 이력</div>
								<table class="table table-sm mb-0 fs-8">
									<thead><tr class="text-muted"><th>귀속일</th><th class="text-center">일수</th><th class="text-end">차감액</th><th class="text-end">차감후잔액</th><th>메모</th><th class="text-end"></th></tr></thead>
									<tbody>
										<?php foreach ($d['entries'] as $e): ?>
										<tr>
											<td><?= htmlspecialchars((string) $e['applied_date'], ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-center"><?= (int) $e['days'] ?></td>
											<td class="text-end text-danger">-<?= $fmtWon($e['amount']) ?></td>
											<td class="text-end"><?= $isAmort ? $fmtWon($e['balance_after']) : '—' ?></td>
											<td class="text-gray-600"><?= htmlspecialchars((string) ($e['memo'] ?: ''), ENT_QUOTES, 'UTF-8') ?></td>
											<td class="text-end"><button type="button" class="btn btn-sm btn-light-danger py-0 px-2 fs-8 debt-reverse-btn" data-debt="<?= (int) $d['id'] ?>" data-entry="<?= (int) $e['id'] ?>">취소</button></td>
										</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
						<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
			<div class="text-muted fs-8 mt-3">차감을 실행하면 해당 귀속일의 정산 반영 시 자동으로 차감됩니다. 리스/렌탈은 일납×일수만큼 매 정산 부과되며 잔액은 줄지 않습니다.</div>
		</div>
	</div>
	<!--end::Debt row-->
	<?php endif; ?>

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
						<span class="text-gray-900"><?= htmlspecialchars(!empty($rider['contract_signed_at']) ? substr((string) $rider['contract_signed_at'], 0, 10) : '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div class="mb-4">
						<span class="text-gray-500 fw-semibold d-block fs-7">보험</span>
						<span class="text-gray-900"><?= htmlspecialchars($rider['insurance_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<div>
						<span class="text-gray-500 fw-semibold d-block fs-7">보험 만기</span>
						<span class="text-gray-900"><?= htmlspecialchars(!empty($rider['insurance_expires']) ? substr((string) $rider['insurance_expires'], 0, 10) : '—', ENT_QUOTES, 'UTF-8') ?></span>
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

	<!--begin::Edit Modal-->
	<div class="modal fade" id="kt_rider_edit_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-750px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold">라이더 정보 수정</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="edit_alert" class="d-none mb-5"></div>
					<div class="row g-5">
						<!--연락·계정-->
						<div class="col-md-6">
							<div class="text-gray-500 fw-bold fs-8 text-uppercase mb-3">연락 · 계정</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold required">이름</label>
								<input type="text" class="form-control form-control-sm form-control-solid" id="ed_name" value="<?= htmlspecialchars((string) $rider['name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="80" />
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">휴대전화</label>
								<input type="text" class="form-control form-control-sm form-control-solid" id="ed_phone" value="<?= htmlspecialchars((string) ($rider['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="010-1234-5678" maxlength="20" />
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">이메일</label>
								<input type="email" class="form-control form-control-sm form-control-solid" id="ed_email" value="<?= htmlspecialchars((string) ($rider['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="120" />
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">생년월일</label>
								<input type="date" class="form-control form-control-sm form-control-solid" id="ed_birth" value="<?= htmlspecialchars($rider['birth_date'] ? substr((string) $rider['birth_date'], 0, 10) : '', ENT_QUOTES, 'UTF-8') ?>" />
							</div>
							<div class="mb-2">
								<label class="form-label fs-7 fw-semibold">본인인증 상태</label>
								<select class="form-select form-select-sm form-select-solid" id="ed_kyc">
									<?php foreach ($kycLabel as $val => $lbl): ?>
									<option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= ($rider['kyc_status'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<!--소속·계좌-->
						<div class="col-md-6">
							<div class="text-gray-500 fw-bold fs-8 text-uppercase mb-3">소속 · 정산 계좌</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">소속 대리점</label>
								<input type="text" class="form-control form-control-sm form-control-solid" value="<?= htmlspecialchars($agencyDisplay, ENT_QUOTES, 'UTF-8') ?>" disabled />
								<div class="form-text fs-8">대리점 이동은 신규 등록(새 로그인 ID 발급)으로 처리됩니다.</div>
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">차량</label>
								<select class="form-select form-select-sm form-select-solid" id="ed_vehicle">
									<?php foreach ($vehicleLabel as $val => $lbl): ?>
									<option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= ($rider['vehicle_type'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">활동 지역</label>
								<input type="text" class="form-control form-control-sm form-control-solid" id="ed_address" value="<?= htmlspecialchars((string) ($rider['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="255" />
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">은행</label>
								<select class="form-select form-select-sm form-select-solid" id="ed_bank">
									<option value="">선택 안 함</option>
									<?php foreach ($banks as $b): ?>
									<option value="<?= htmlspecialchars((string) $b['code'], ENT_QUOTES, 'UTF-8') ?>" <?= ($rider['bank_code'] ?? '') === $b['code'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $b['label'], ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="mb-4">
								<label class="form-label fs-7 fw-semibold">계좌번호</label>
								<input type="text" class="form-control form-control-sm form-control-solid font-monospace" id="ed_account" value="<?= htmlspecialchars((string) ($rider['bank_account'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="숫자·하이픈" maxlength="40" />
							</div>
							<div class="mb-2">
								<label class="form-label fs-7 fw-semibold">예금주</label>
								<input type="text" class="form-control form-control-sm form-control-solid" id="ed_holder" value="<?= htmlspecialchars((string) ($rider['account_holder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="80" />
							</div>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="btn_profile_save">저장</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::Edit Modal-->

	<?php if ($debtReady): ?>
	<!--begin::Debt New Modal-->
	<div class="modal fade" id="kt_debt_new_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-600px">
			<div class="modal-content">
				<div class="modal-header"><h2 class="fw-bold">부채 등록</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="debt_new_alert" class="d-none mb-4"></div>
					<div class="row g-4">
						<div class="col-md-4">
							<label class="form-label fs-7 fw-semibold required">종류</label>
							<select class="form-select form-select-sm form-select-solid" id="dn_kind">
								<option value="loan">대여금</option>
								<option value="lease">리스/렌탈</option>
								<option value="advance">선지급금</option>
							</select>
						</div>
						<div class="col-md-8">
							<label class="form-label fs-7 fw-semibold">항목명</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="dn_title" maxlength="120" placeholder="예: 차량대여금, 오토바이리스" />
						</div>
						<div class="col-md-6" id="dn_principal_wrap">
							<label class="form-label fs-7 fw-semibold">원금(대여금·선지급)</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="dn_principal" min="0" step="1000" placeholder="예: 1250000" />
							<div class="form-text fs-8">이 금액이 남은 잔액의 시작값이 됩니다. 리스는 불필요.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">일납금액</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="dn_daily" min="0" step="100" placeholder="예: 24000" />
							<div class="form-text fs-8">차감 시 일납×일수로 자동 계산됩니다.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">채권자/구분</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="dn_creditor" maxlength="120" placeholder="예: 본사, XX리스" />
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">개시일/출고일</label>
							<input type="date" class="form-control form-control-sm form-control-solid" id="dn_opened" />
						</div>
						<div class="col-12">
							<label class="form-label fs-7 fw-semibold">메모</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="dn_note" maxlength="255" />
						</div>
					</div>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-primary" id="dn_save">등록</button></div>
			</div>
		</div>
	</div>
	<!--end::Debt New Modal-->

	<!--begin::Debt Repay Modal-->
	<div class="modal fade" id="kt_debt_repay_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px">
			<div class="modal-content">
				<div class="modal-header"><h2 class="fw-bold">차감 실행</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="debt_repay_alert" class="d-none mb-4"></div>
					<input type="hidden" id="dr_debt_id" />
					<div class="mb-4 p-3 bg-light-primary rounded fs-7">
						<span id="dr_title" class="fw-bold"></span>
						<span class="text-muted ms-2">남은 잔액 <span id="dr_balance" class="fw-bold"></span></span>
					</div>
					<div class="row g-4">
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold required">차감 귀속일</label>
							<input type="date" class="form-control form-control-sm form-control-solid" id="dr_date" value="<?= date('Y-m-d') ?>" />
							<div class="form-text fs-8">이 날짜의 정산 반영 시 차감됩니다.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">차감일수</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="dr_days" min="0" value="7" />
						</div>
						<div class="col-12">
							<label class="form-label fs-7 fw-semibold">차감액 <span class="text-muted fs-8">(비우면 일납×일수)</span></label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="dr_amount" min="0" step="100" placeholder="자동 계산" />
							<div class="form-text fs-8" id="dr_hint"></div>
						</div>
					</div>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-danger" id="dr_save">차감</button></div>
			</div>
		</div>
	</div>
	<!--end::Debt Repay Modal-->

	<!--begin::Debt Edit Modal-->
	<div class="modal fade" id="kt_debt_edit_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-500px">
			<div class="modal-content">
				<div class="modal-header"><h2 class="fw-bold">부채 수정</h2><div class="btn btn-icon btn-sm" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div></div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="debt_edit_alert" class="d-none mb-4"></div>
					<input type="hidden" id="de_debt_id" />
					<div class="row g-4">
						<div class="col-12">
							<label class="form-label fs-7 fw-semibold">항목명</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="de_title" maxlength="120" />
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">일납금액</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="de_daily" min="0" step="100" />
						</div>
						<div class="col-md-6" id="de_balance_wrap">
							<label class="form-label fs-7 fw-semibold">남은 잔액 보정</label>
							<input type="number" class="form-control form-control-sm form-control-solid" id="de_balance" min="0" step="1000" />
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">채권자/구분</label>
							<input type="text" class="form-control form-control-sm form-control-solid" id="de_creditor" maxlength="120" />
						</div>
						<div class="col-md-6">
							<label class="form-label fs-7 fw-semibold">상태</label>
							<select class="form-select form-select-sm form-select-solid" id="de_status">
								<option value="active">진행 중</option>
								<option value="paused">일시중지</option>
								<option value="closed">완납/종료</option>
							</select>
						</div>
					</div>
				</div>
				<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button><button type="button" class="btn btn-primary" id="de_save">저장</button></div>
			</div>
		</div>
	</div>
	<!--end::Debt Edit Modal-->
	<?php endif; ?>

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

	document.getElementById('withholding_toggle').addEventListener('change', function () {
		var on = this.checked;
		apiPatch({ action: 'withholding_tax', enabled: on }, on ? '원천세 공제 대상으로 설정했습니다.' : '원천세 비대상으로 변경했습니다.');
	});

	document.getElementById('hold_toggle').addEventListener('change', function () {
		var hold = this.checked;
		apiPatch({ action: 'withdrawal_hold', hold: hold }, hold ? '출금 보류로 설정했습니다.' : '출금 보류를 해제했습니다.');
	});

	document.querySelectorAll('.pf-save-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var pf = btn.getAttribute('data-pf');
			var ext = (document.getElementById(btn.getAttribute('data-input')).value || '').trim();
			apiPatch({ action: 'set_platform', platform: pf, external_id: ext }, ext ? '플랫폼 ID가 저장되었습니다.' : '연동을 해제했습니다.');
		});
	});

	var zeroBtn = document.getElementById('btn_zero_close');
	if (zeroBtn) {
		zeroBtn.addEventListener('click', function () {
			if (!confirm('잔여 지갑 잔액을 0원 이체로 종결할까요? 되돌릴 수 없습니다.')) return;
			apiPatch({ action: 'zero_close' }, '0원 이체로 종결했습니다.');
		});
	}

	// 메모 저장
	document.getElementById('btn_memo_save').addEventListener('click', function () {
		var memo = document.getElementById('admin_memo_ta').value;
		apiPatch({ action: 'memo', memo: memo }, '메모가 저장되었습니다.');
	});

	// 프로필 정보 수정 저장
	var profileBtn = document.getElementById('btn_profile_save');
	if (profileBtn) {
		profileBtn.addEventListener('click', function () {
			var editAlert = document.getElementById('edit_alert');
			var val = function (id) { return (document.getElementById(id).value || '').trim(); };
			var payload = {
				action: 'update_profile',
				name: val('ed_name'),
				phone: val('ed_phone'),
				email: val('ed_email'),
				birth_date: val('ed_birth'),
				kyc_status: val('ed_kyc'),
				vehicle_type: val('ed_vehicle'),
				address: val('ed_address'),
				bank_code: val('ed_bank'),
				bank_account: val('ed_account'),
				account_holder: val('ed_holder')
			};
			if (payload.name === '') {
				editAlert.className = 'alert alert-danger mb-5';
				editAlert.textContent = '이름을 입력하세요.';
				return;
			}
			profileBtn.disabled = true;
			profileBtn.textContent = '저장 중…';
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload)
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.ok) {
					// 저장 성공 → 변경 내용 반영을 위해 새로고침
					window.location.reload();
				} else {
					editAlert.className = 'alert alert-danger mb-5';
					editAlert.textContent = data.message || '오류가 발생했습니다.';
					profileBtn.disabled = false;
					profileBtn.textContent = '저장';
				}
			})
			.catch(function () {
				editAlert.className = 'alert alert-danger mb-5';
				editAlert.textContent = '네트워크 오류가 발생했습니다.';
				profileBtn.disabled = false;
				profileBtn.textContent = '저장';
			});
		});
	}
})();
</script>

<?php if ($debtReady): ?>
<script>
(function () {
	var DEBT_API = <?= json_encode($debtApi, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	var RIDER_ID = <?= (int) $riderId ?>;
	var won = function (n) { return (Number(n) || 0).toLocaleString() + '원'; };

	function post(payload) {
		return fetch(DEBT_API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(payload)
		}).then(function (r) { return r.json(); });
	}
	function setAlert(id, msg, type) {
		var el = document.getElementById(id);
		el.className = 'alert alert-' + (type || 'danger') + ' mb-4';
		el.textContent = msg;
	}
	function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }

	// ── 신규 등록 ──
	var kindEl = document.getElementById('dn_kind');
	function syncPrincipal() {
		document.getElementById('dn_principal_wrap').style.display = (kindEl.value === 'lease') ? 'none' : '';
	}
	if (kindEl) { kindEl.addEventListener('change', syncPrincipal); }
	var btnNew = document.getElementById('btn_debt_new');
	if (btnNew) {
		btnNew.addEventListener('click', function () {
			['dn_title', 'dn_principal', 'dn_daily', 'dn_creditor', 'dn_opened', 'dn_note'].forEach(function (i) { document.getElementById(i).value = ''; });
			kindEl.value = 'loan'; syncPrincipal();
			document.getElementById('debt_new_alert').className = 'd-none';
			modal('kt_debt_new_modal').show();
		});
	}
	document.getElementById('dn_save').addEventListener('click', function () {
		var btn = this; btn.disabled = true;
		post({
			action: 'create', rider_id: RIDER_ID, kind: kindEl.value,
			title: document.getElementById('dn_title').value.trim(),
			principal_amount: Number(document.getElementById('dn_principal').value) || 0,
			daily_amount: Number(document.getElementById('dn_daily').value) || 0,
			creditor: document.getElementById('dn_creditor').value.trim(),
			opened_on: document.getElementById('dn_opened').value,
			note: document.getElementById('dn_note').value.trim()
		}).then(function (d) {
			if (d.ok) { window.location.reload(); }
			else { setAlert('debt_new_alert', d.message || '오류'); btn.disabled = false; }
		}).catch(function () { setAlert('debt_new_alert', '네트워크 오류'); btn.disabled = false; });
	});

	// ── 차감 실행 ──
	function recalcHint() {
		var daily = Number(document.getElementById('kt_debt_repay_modal').dataset.daily) || 0;
		var days = Number(document.getElementById('dr_days').value) || 0;
		var amt = document.getElementById('dr_amount').value;
		var calc = amt !== '' ? Number(amt) : daily * days;
		document.getElementById('dr_hint').textContent = amt !== '' ? ('입력 금액 ' + won(calc)) : ('예상 차감 ' + won(calc) + ' (일납 ' + won(daily) + ' × ' + days + '일)');
	}
	document.querySelectorAll('.debt-repay-btn').forEach(function (b) {
		b.addEventListener('click', function () {
			var m = document.getElementById('kt_debt_repay_modal');
			m.dataset.daily = b.dataset.daily;
			document.getElementById('dr_debt_id').value = b.dataset.id;
			document.getElementById('dr_title').textContent = (b.dataset.title || b.dataset.kind);
			document.getElementById('dr_balance').textContent = (b.dataset.kind === 'lease') ? '해당없음' : won(b.dataset.balance);
			document.getElementById('dr_amount').value = '';
			document.getElementById('dr_days').value = 7;
			document.getElementById('debt_repay_alert').className = 'd-none';
			recalcHint();
			modal('kt_debt_repay_modal').show();
		});
	});
	['dr_days', 'dr_amount'].forEach(function (i) { document.getElementById(i).addEventListener('input', recalcHint); });
	document.getElementById('dr_save').addEventListener('click', function () {
		var btn = this; btn.disabled = true;
		post({
			action: 'repay', debt_id: Number(document.getElementById('dr_debt_id').value),
			applied_date: document.getElementById('dr_date').value,
			days: Number(document.getElementById('dr_days').value) || 0,
			amount: document.getElementById('dr_amount').value,
			memo: ''
		}).then(function (d) {
			if (d.ok) { window.location.reload(); }
			else { setAlert('debt_repay_alert', d.message || '오류'); btn.disabled = false; }
		}).catch(function () { setAlert('debt_repay_alert', '네트워크 오류'); btn.disabled = false; });
	});

	// ── 수정 ──
	document.querySelectorAll('.debt-edit-btn').forEach(function (b) {
		b.addEventListener('click', function () {
			document.getElementById('de_debt_id').value = b.dataset.id;
			document.getElementById('de_title').value = b.dataset.title || '';
			document.getElementById('de_daily').value = b.dataset.daily || 0;
			document.getElementById('de_creditor').value = b.dataset.creditor || '';
			document.getElementById('de_status').value = b.dataset.status || 'active';
			document.getElementById('de_balance').value = b.dataset.balance || 0;
			document.getElementById('de_balance_wrap').style.display = (b.dataset.amort === '1') ? '' : 'none';
			document.getElementById('debt_edit_alert').className = 'd-none';
			modal('kt_debt_edit_modal').show();
		});
	});
	document.getElementById('de_save').addEventListener('click', function () {
		var btn = this; btn.disabled = true;
		var payload = {
			action: 'update', debt_id: Number(document.getElementById('de_debt_id').value),
			title: document.getElementById('de_title').value.trim(),
			daily_amount: Number(document.getElementById('de_daily').value) || 0,
			creditor: document.getElementById('de_creditor').value.trim(),
			status: document.getElementById('de_status').value
		};
		if (document.getElementById('de_balance_wrap').style.display !== 'none') {
			payload.balance_amount = Number(document.getElementById('de_balance').value) || 0;
		}
		post(payload).then(function (d) {
			if (d.ok) { window.location.reload(); }
			else { setAlert('debt_edit_alert', d.message || '오류'); btn.disabled = false; }
		}).catch(function () { setAlert('debt_edit_alert', '네트워크 오류'); btn.disabled = false; });
	});

	// ── 이력 취소 ──
	document.querySelectorAll('.debt-reverse-btn').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!confirm('이 차감을 취소할까요? 연결된 정산 차감도 함께 취소됩니다.')) { return; }
			post({ action: 'reverse', debt_id: Number(b.dataset.debt), entry_id: Number(b.dataset.entry) })
				.then(function (d) { if (d.ok) { window.location.reload(); } else { alert(d.message || '오류'); } })
				.catch(function () { alert('네트워크 오류'); });
		});
	});
})();
</script>
<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

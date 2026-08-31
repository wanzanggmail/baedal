<?php

declare(strict_types=1);

require_once INC_PATH . '/org_scope_picker.php';

// 예금주 조회는 펌뱅킹 실연동이 켜져 있을 때만 쓸 수 있다.
// 꺼져 있으면 버튼을 아예 내보내지 않는다 — 눌러도 "확인 불가" 만 나오는 버튼은
// 화면만 어지럽히고, 저장할 때마다 없앨 수 없는 경고 팝업까지 뜬다.
require_once INC_PATH . '/AccountVerifier.php';
$acctVerifyOn = AccountVerifier::available();

// ── 서버사이드 데이터 조회 ──────────────────────────────────
$filterQ      = trim((string) ($_GET['q']       ?? ''));
$filterAgency = (int) ($_GET['agency'] ?? 0);
$filterStatus = trim((string) ($_GET['status']  ?? ''));

$where  = ['1=1'];
$params = [];

if ($filterQ !== '') {
    $like    = '%' . $filterQ . '%';
    $where[] = '(r.rider_code LIKE ? OR r.login_id LIKE ? OR r.name LIKE ? OR r.phone LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like]);
}
if ($filterAgency > 0)    { $where[] = 'r.agency_id = ?'; $params[] = $filterAgency; }
if ($filterStatus !== '') { $where[] = 'r.status = ?';    $params[] = $filterStatus; }

// 멀티테넌시: 소속 대리점 스코프
[$scopeSql, $scopeParams] = Org::agencyScopeClause('r.agency_id');
if ($scopeSql !== '') {
    $where[]  = $scopeSql;
    $params   = array_merge($params, $scopeParams);
}

$whereStr = implode(' AND ', $where);

$totalCount = (int) (db_row(
    "SELECT COUNT(*) AS cnt FROM riders r WHERE {$whereStr}",
    $params
)['cnt'] ?? 0);

// ── 페이징 ──────────────────────────────────────────────────
$perPage    = 20;
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$riders = db_rows(
    "SELECT r.id, r.rider_code, r.login_id, r.name,
            r.phone, r.status,
            r.is_daily_settlement, r.withholding_tax_enabled,
            r.withdrawal_hold, r.created_at, r.last_login_at
     FROM riders r
     WHERE {$whereStr}
     ORDER BY r.name ASC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// 페이지 링크 baseURL (현재 필터 유지, page 만 교체)
$pageQuery = array_filter([
    'route'  => (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) ? 'riders/list' : null,
    'q'      => $filterQ !== ''      ? $filterQ      : null,
    'agency' => $filterAgency > 0    ? $filterAgency : null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
], static fn ($v) => $v !== null && $v !== '');
$pageBase = (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL)
    ? ADMIN_BASE . '/index.php'
    : admin_url('riders/list');
$pageUrl = static function (int $p) use ($pageBase, $pageQuery): string {
    $qs = http_build_query($pageQuery + ['page' => $p]);
    return $pageBase . '?' . $qs;
};

$statusLabel = [
    'active'        => '활동 중',
    'suspended'     => '일시 정지',
    'leave_request' => '탈퇴 요청',
    'offboarded'    => '계약 종료',
];
$statusBadge = [
    'active' => 'success', 'suspended' => 'danger',
    'leave_request' => 'warning', 'offboarded' => 'dark',
];
$banks      = db_rows("SELECT code, label FROM system_codes WHERE category='bank' AND is_active=1 ORDER BY sort_order");
$detailBase = admin_url('riders/detail');
$detailBase .= str_contains($detailBase, '?') ? '&id=' : '?id=';
$apiBase    = ADMIN_BASE . '/api/riders.php';
$currentUrl = admin_url('riders/list');
$bulkTplApi = ADMIN_BASE . '/api/riders_bulk_template.php';
$bulkUpApi  = ADMIN_BASE . '/api/riders_bulk_upload.php';

// 멀티테넌시: 라이더 소속 대리점 선택 (대리점 계정은 자기 대리점 자동)
require_once INC_PATH . '/Organization.php';
$isAgencyCreator = admin_org_level() === Org::LEVEL_AGENCY;
$agencyOptions   = $isAgencyCreator ? [] : Organization::agencyOptions();
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">라이더 관리</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">라이더 목록</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<button type="button" class="btn btn-sm btn-light-primary fw-bold"
			        data-bs-toggle="modal" data-bs-target="#kt_rider_bulk_modal">
				<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
				엑셀 일괄등록
			</button>
			<button type="button" class="btn btn-sm btn-primary fw-bold"
			        data-bs-toggle="modal" data-bs-target="#kt_rider_register_modal">
				<i class="ki-duotone ki-user-tick fs-3"><span class="path1"></span><span class="path2"></span></i>
				라이더 등록
			</button>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<form method="get" action="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">
	<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL): ?>
	<input type="hidden" name="route" value="riders/list" />
	<?php endif; ?>
	<div class="card card-flush mb-8">
		<div class="card-body py-5">
			<div class="row g-4 align-items-end">
				<div class="col-md-4">
					<label class="form-label fw-semibold">검색</label>
					<input type="text" class="form-control form-control-solid" name="q"
					       placeholder="이름, 로그인ID, 전화"
					       value="<?= htmlspecialchars($filterQ, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" />
				</div>
				<?php // 총판 → 대리점 필터(공용). agency 파라미터 유지.
				org_scope_picker('rl', 0, $filterAgency, [
					'dist_col' => 'col-md-2', 'agency_col' => 'col-md-2',
					'agency_name' => 'agency',
				]); ?>
				<div class="col-md-2">
					<label class="form-label fw-semibold">상태</label>
					<select class="form-select form-select-solid" name="status">
						<option value="">전체</option>
						<?php foreach ($statusLabel as $val => $lbl): ?>
						<option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"
							<?= $filterStatus === $val ? 'selected' : '' ?>>
							<?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?>
						</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-md-4 text-md-end d-flex gap-2 justify-content-end">
					<button type="submit" class="btn btn-primary">필터 적용</button>
					<a href="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light">초기화</a>
				</div>
			</div>
		</div>
	</div>
	</form>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">라이더 목록</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">
					총 <strong><?= number_format($totalCount) ?></strong>명
					<?php if ($filterQ !== '' || $filterAgency > 0 || $filterStatus !== ''): ?>
					<span class="badge badge-light-warning ms-2">필터 적용 중</span>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 table-hover align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-100px">로그인ID</th>
							<th class="min-w-90px">이름</th>
							<th class="min-w-120px">연락처</th>
							<th class="min-w-80px">일일정산</th>
							<th class="min-w-80px">원천세공제</th>
							<th class="min-w-100px">상태</th>
							<th class="min-w-110px">가입일</th>
							<th class="min-w-80px text-end">관리</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($riders)): ?>
						<tr>
							<td colspan="9" class="text-center text-gray-500 py-10">
								<?= ($filterQ !== '' || $filterAgency > 0 || $filterStatus !== '')
								    ? '검색 조건에 맞는 라이더가 없습니다.'
								    : '등록된 라이더가 없습니다. 「라이더 등록」으로 추가하세요.' ?>
							</td>
						</tr>
						<?php else: ?>
						<?php foreach ($riders as $r):
						    $st       = $r['status'] ?? 'active';
						    $badge    = $statusBadge[$st] ?? 'primary';
						    $phone    = preg_replace('/\D/', '', $r['phone'] ?? '');
						    $phoneMsk = preg_replace('/(\d{3})\d{4}(\d{4})/', '$1-****-$2', $phone) ?: '—';
						    $detailUrl = $detailBase . (int) $r['id'];
						    $isDaily  = !empty($r['is_daily_settlement']);
						    $isWht    = !empty($r['withholding_tax_enabled']);
						?>
						<tr>
							<td class="font-monospace fs-7 text-gray-800"><?= htmlspecialchars($r['login_id'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-900 fw-semibold"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($phoneMsk, ENT_QUOTES, 'UTF-8') ?></td>
							<td><span class="badge badge-light-<?= $isDaily ? 'success' : 'secondary' ?>"><?= $isDaily ? '일일' : '주정산' ?></span></td>
							<td><span class="badge badge-light-<?= $isWht ? 'info' : 'secondary' ?>"><?= $isWht ? '공제' : '미대상' ?></span></td>
							<td>
								<span class="badge badge-light-<?= $badge ?>">
									<?= htmlspecialchars($statusLabel[$st] ?? $st, ENT_QUOTES, 'UTF-8') ?>
								</span>
								<?php if (!empty($r['withdrawal_hold'])): ?>
								<span class="badge badge-light-danger ms-1">출금보류</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-700"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<a href="<?= htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8') ?>"
								   class="btn btn-sm btn-light-primary">상세</a>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php if ($totalCount > 0): ?>
		<div class="card-footer d-flex flex-stack flex-wrap gap-3 py-4">
			<div class="text-gray-600 fs-7">
				<?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalCount)) ?>
				/ 총 <strong class="text-gray-800"><?= number_format($totalCount) ?></strong>명
				<span class="text-muted ms-1">(<?= $page ?>/<?= $totalPages ?> 페이지)</span>
			</div>
			<?php if ($totalPages > 1):
				$win   = 2;
				$start = max(1, $page - $win);
				$end   = min($totalPages, $page + $win);
			?>
			<ul class="pagination mb-0">
				<li class="page-item previous <?= $page <= 1 ? 'disabled' : '' ?>">
					<a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars($pageUrl($page - 1), ENT_QUOTES, 'UTF-8') ?>"><i class="previous"></i></a>
				</li>
				<?php if ($start > 1): ?>
					<li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageUrl(1), ENT_QUOTES, 'UTF-8') ?>">1</a></li>
					<?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
				<?php endif; ?>
				<?php for ($p = $start; $p <= $end; $p++): ?>
					<li class="page-item <?= $p === $page ? 'active' : '' ?>">
						<a class="page-link" href="<?= htmlspecialchars($pageUrl($p), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
					</li>
				<?php endfor; ?>
				<?php if ($end < $totalPages): ?>
					<?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
					<li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageUrl($totalPages), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a></li>
				<?php endif; ?>
				<li class="page-item next <?= $page >= $totalPages ? 'disabled' : '' ?>">
					<a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($pageUrl($page + 1), ENT_QUOTES, 'UTF-8') ?>"><i class="next"></i></a>
				</li>
			</ul>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>

	<!--begin::Register Modal-->
	<div class="modal fade" id="kt_rider_register_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-750px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold">라이더 등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="reg_alert" class="d-none mb-6"></div>
					<form id="rider_register_form" novalidate>
						<?php if (!$isAgencyCreator): ?>
						<div class="row g-6 mb-6">
							<?php // 총판 → 소속 대리점(필수, 모달 안이라 dropdown_parent 지정). JS 는 'regap_osp_agency' 를 읽는다.
							org_scope_picker('regap', 0, 0, [
								'dist_col' => 'col-md-6', 'agency_col' => 'col-md-6',
								'dist_label' => '총판', 'agency_label' => '소속 대리점',
								'required' => true, 'agency_all' => false,
								'dropdown_parent' => '#kt_rider_register_modal',
							]); ?>
							<?php if ($agencyOptions === []): ?>
							<div class="col-12"><div class="form-text text-danger">먼저 「조직 관리」에서 대리점을 등록하세요.</div></div>
							<?php endif; ?>
						</div>
						<?php endif; ?>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">앱 로그인 ID</label>
								<input type="text" class="form-control form-control-solid" id="reg_login_id"
								       maxlength="60" placeholder="비우면 휴대전화번호로 자동 생성" autocomplete="off" />
								<div class="form-text fs-8">비워두면 휴대전화번호가 그대로 로그인 ID가 됩니다(겹치면 a, b, c…가 자동으로 붙어요).</div>
							</div>
							<div class="col-md-6">
								<label class="form-label">라이더 코드 (선택)</label>
								<input type="text" class="form-control form-control-solid" id="reg_rider_code"
								       maxlength="20" placeholder="비우면 자동 생성" autocomplete="off" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">비밀번호</label>
								<input type="password" class="form-control form-control-solid" id="reg_password" required minlength="4" autocomplete="new-password" />
							</div>
							<div class="col-md-6">
								<label class="form-label required">비밀번호 확인</label>
								<input type="password" class="form-control form-control-solid" id="reg_password_confirm" required autocomplete="new-password" />
							</div>
						</div>
						<div class="separator my-6"></div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">이름</label>
								<input type="text" class="form-control form-control-solid" id="reg_name" required maxlength="50" />
							</div>
							<div class="col-md-6">
								<label class="form-label required">휴대전화</label>
								<input type="text" class="form-control form-control-solid" id="reg_phone" required maxlength="20" placeholder="01012345678" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label">이메일</label>
								<input type="email" class="form-control form-control-solid" id="reg_email" maxlength="120" />
							</div>
							<div class="col-md-6">
								<label class="form-label">생년월일</label>
								<input type="text" class="form-control form-control-solid" id="reg_birth" placeholder="YYYY-MM-DD" maxlength="10" />
							</div>
						</div>
						<div class="row g-6 mb-6">
							<div class="col-md-6">
								<label class="form-label required">차량</label>
								<select class="form-select form-select-solid" id="reg_vehicle" required>
									<option value="motor">오토바이</option>
									<option value="bike">자전거</option>
									<option value="kick">전동킥보드</option>
									<option value="car">자동차</option>
									<option value="walk">도보</option>
								</select>
							</div>
							<input type="hidden" id="reg_platform" value="" />
						</div>
						<div class="separator separator-dashed my-2"></div>
						<div class="row g-4 mb-2">
							<div class="col-12"><label class="form-label fw-semibold">플랫폼 매칭 정보 <span class="text-muted fs-8">(정산서 매칭 키 · 나중에 라이더 상세에서도 등록 가능)</span></label></div>
							<div class="col-md-6">
								<label class="form-label fs-7">쿠팡이츠 성함</label>
								<input type="text" class="form-control form-control-solid" id="reg_coupang_id" maxlength="60" placeholder="예: 박성준1682" />
							</div>
							<div class="col-md-6">
								<label class="form-label fs-7">배달의민족 UserID</label>
								<input type="text" class="form-control form-control-solid font-monospace" id="reg_baemin_id" maxlength="60" placeholder="예: adammins" />
							</div>
						</div>
						<div class="mb-6">
							<label class="form-label">활동 지역</label>
							<input type="text" class="form-control form-control-solid" id="reg_address" maxlength="200" placeholder="예: 서울 강서구" />
						</div>
						<div class="separator my-6"></div>
						<h4 class="fw-bold mb-6">정산 계좌</h4>
						<div class="row g-6 mb-8">
							<div class="col-md-4">
								<label class="form-label">은행</label>
								<select class="form-select form-select-solid" id="reg_bank_code">
									<option value="">선택</option>
									<?php foreach ($banks as $b): ?>
									<option value="<?= htmlspecialchars($b['code'], ENT_QUOTES, 'UTF-8') ?>">
										<?= htmlspecialchars($b['label'], ENT_QUOTES, 'UTF-8') ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-4">
								<label class="form-label">예금주</label>
								<input type="text" class="form-control form-control-solid" id="reg_account_holder" maxlength="50" />
							</div>
							<div class="col-md-4">
								<label class="form-label">계좌번호</label>
								<div class="d-flex gap-2">
									<input type="text" class="form-control form-control-solid" id="reg_bank_account" maxlength="40" />
									<?php if ($acctVerifyOn) : ?>
									<button type="button" class="btn btn-light-primary text-nowrap px-3" id="reg_verify">확인</button>
									<?php endif; ?>
								</div>
								<?php // 계좌번호가 한 자리만 틀려도 모르는 사람에게 송금된다. ?>
								<?php if ($acctVerifyOn) : ?>
								<div class="fs-8 mt-2" id="reg_verify_msg"></div>
								<?php endif; ?>
							</div>
						</div>
						<div class="d-flex justify-content-end gap-3">
							<button type="button" class="btn btn-light" data-bs-dismiss="modal">취소</button>
							<button type="submit" class="btn btn-primary" id="reg_submit_btn">
								<span class="indicator-label">등록</span>
								<span class="indicator-progress d-none">처리 중…<span class="spinner-border spinner-border-sm ms-2"></span></span>
							</button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
	<!--end::Register Modal-->

	<!--begin::Bulk Upload Modal-->
	<div class="modal fade" id="kt_rider_bulk_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold">라이더 엑셀 일괄등록</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
						<i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
					</div>
				</div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div class="alert bg-light-primary d-flex p-5 mb-6">
						<i class="ki-duotone ki-information-5 fs-2hx text-primary me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
						<div class="fs-7 text-gray-800">
							신규 대리점이 입점했을 때 기존에 관리하던 라이더를 한 번에 옮겨 적을 때 사용합니다.
							① <strong>템플릿 다운로드</strong> → ② 이름·휴대전화 등 채워서 업로드 → ③ 미리보기로 오류 확인 → ④ <strong>등록 확정</strong>.
							이름·휴대전화만 필수이며, 로그인ID·라이더코드는 비우면 자동 생성되고 초기 비밀번호는 0000으로 통일됩니다.
						</div>
					</div>
					<div id="bulk_alert" class="d-none mb-6"></div>

					<form id="bulk_upload_form" enctype="multipart/form-data" accept-charset="UTF-8">
						<?php if (!$isAgencyCreator): ?>
						<div class="row g-6 mb-6">
							<?php // 총판 → 소속 대리점(필수, 일괄등록 모달). JS 는 'bulk_osp_agency' 를 읽는다.
							org_scope_picker('bulk', 0, 0, [
								'dist_col' => 'col-md-6', 'agency_col' => 'col-md-6',
								'dist_label' => '총판', 'agency_label' => '소속 대리점',
								'required' => true, 'agency_all' => false,
								'dropdown_parent' => '#kt_rider_bulk_modal',
							]); ?>
						</div>
						<?php endif; ?>

						<div class="row g-4 align-items-end mb-6">
							<div class="col-md-5">
								<button type="button" class="btn btn-light-primary w-100" id="bulk_tpl_btn">
									<i class="ki-duotone ki-file-down fs-3"><span class="path1"></span><span class="path2"></span></i>
									① 빈 템플릿 다운로드
								</button>
							</div>
							<div class="col-md-7">
								<label class="form-label required">작성한 엑셀 파일</label>
								<input type="file" class="form-control form-control-solid" id="bulk_file" accept=".xlsx" />
							</div>
						</div>

						<button type="submit" class="btn btn-primary w-100" id="bulk_preview_btn">
							<span class="indicator-label">
								<i class="ki-duotone ki-eye fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
								미리보기 · 오류 확인
							</span>
							<span class="indicator-progress">처리 중... <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
						</button>
					</form>

					<div id="bulk_preview_area" class="d-none mt-8">
						<div class="separator my-6"></div>
						<div id="bulk_summary" class="mb-4"></div>
						<div class="table-responsive" style="max-height: 420px;">
							<table class="table table-row-bordered align-middle gy-2 fs-7">
								<thead><tr class="fw-bold text-muted bg-light">
									<th>#</th><th>이름</th><th>휴대전화</th><th>로그인ID</th><th>상태</th>
								</tr></thead>
								<tbody id="bulk_tbody"></tbody>
							</table>
						</div>
					</div>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light" data-bs-dismiss="modal">닫기</button>
					<button type="button" class="btn btn-primary d-none" id="bulk_confirm_btn">
						<span class="indicator-label">등록 확정</span>
						<span class="indicator-progress">등록 중... <span class="spinner-border spinner-border-sm align-middle ms-2"></span></span>
					</button>
				</div>
			</div>
		</div>
	</div>
	<!--end::Bulk Upload Modal-->

<script>
(function () {
	var API_URL   = <?= json_encode($apiBase,    JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	var DETAIL_URL = <?= json_encode($detailBase, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

	function showAlert(msg, type) {
		var el = document.getElementById('reg_alert');
		el.className = 'alert alert-' + (type || 'danger') + ' mb-6';
		el.textContent = msg;
	}

	document.getElementById('rider_register_form').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var loginId = document.getElementById('reg_login_id').value.trim();
		var pw      = document.getElementById('reg_password').value;
		var pw2     = document.getElementById('reg_password_confirm').value;
		var name    = document.getElementById('reg_name').value.trim();
		var phone   = document.getElementById('reg_phone').value.trim();

		if (pw.length < 4)   { showAlert('비밀번호는 4자 이상이어야 합니다.'); return; }
		if (pw !== pw2)      { showAlert('비밀번호가 일치하지 않습니다.'); return; }
		if (!name)           { showAlert('이름을 입력하세요.'); return; }
		if (!phone)          { showAlert('휴대전화를 입력하세요.'); return; }
		var agencyEl = document.getElementById('regap_osp_agency');
		if (agencyEl && !agencyEl.value) { showAlert('소속 대리점을 선택하세요.'); return; }

		var btn = document.getElementById('reg_submit_btn');
		btn.querySelector('.indicator-label').classList.add('d-none');
		btn.querySelector('.indicator-progress').classList.remove('d-none');
		btn.disabled = true;

		fetch(API_URL, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				login_id:    loginId,
				agency_id:   agencyEl ? Number(agencyEl.value) : 0,
				rider_code:  document.getElementById('reg_rider_code').value.trim(),
				password:    pw,
				name:        name,
				phone:       phone,
				email:       document.getElementById('reg_email').value.trim(),
				birth_date:  document.getElementById('reg_birth').value.trim(),
				platform:    document.getElementById('reg_platform').value,
				coupang_id:  document.getElementById('reg_coupang_id').value.trim(),
				baemin_id:   document.getElementById('reg_baemin_id').value.trim(),
				vehicle_type: document.getElementById('reg_vehicle').value,
				address:     document.getElementById('reg_address').value.trim(),
				bank_code:   document.getElementById('reg_bank_code').value,
				bank_account: document.getElementById('reg_bank_account').value.trim(),
				account_holder: document.getElementById('reg_account_holder').value.trim(),
			})
		})
		.then(function (r) { return r.json(); })
		.then(function (data) {
			if (!data.ok) { showAlert(data.message || '오류가 발생했습니다.'); return; }
			var modal = bootstrap.Modal.getInstance(document.getElementById('kt_rider_register_modal'));
			if (modal) modal.hide();
			window.location.href = DETAIL_URL + data.id;
		})
		.catch(function () { showAlert('네트워크 오류가 발생했습니다.'); })
		.finally(function () {
			btn.querySelector('.indicator-label').classList.remove('d-none');
			btn.querySelector('.indicator-progress').classList.add('d-none');
			btn.disabled = false;
		});
	});

	document.getElementById('kt_rider_register_modal').addEventListener('show.bs.modal', function () {
		document.getElementById('rider_register_form').reset();
		var resetAgencyEl = document.getElementById('regap_osp_agency');
		if (resetAgencyEl && window.jQuery) { jQuery(resetAgencyEl).val('').trigger('change'); }
		var al = document.getElementById('reg_alert');
		al.className = 'd-none mb-6';
		al.textContent = '';
	});
})();

// ── 엑셀 일괄등록 ────────────────────────────────────────────
(function () {
	var TPL_API = <?= json_encode($bulkTplApi, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	var UP_API  = <?= json_encode($bulkUpApi,  JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	var IS_AGENCY = <?= $isAgencyCreator ? 'true' : 'false' ?>;

	var form        = document.getElementById('bulk_upload_form');
	var fileInput   = document.getElementById('bulk_file');
	var alertEl     = document.getElementById('bulk_alert');
	var previewArea = document.getElementById('bulk_preview_area');
	var previewBtn  = document.getElementById('bulk_preview_btn');
	var confirmBtn  = document.getElementById('bulk_confirm_btn');
	var modalEl     = document.getElementById('kt_rider_bulk_modal');

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function showAlert(msg, type) {
		alertEl.className = 'alert alert-' + (type || 'danger') + ' mb-6';
		alertEl.innerHTML = msg;
	}
	function agencyId() {
		if (IS_AGENCY) return null;
		var el = document.getElementById('bulk_osp_agency');
		return el ? el.value : '';
	}

	document.getElementById('bulk_tpl_btn').addEventListener('click', function () {
		var ag = agencyId();
		if (!IS_AGENCY && !ag) { showAlert('먼저 소속 대리점을 선택하세요.'); return; }
		window.location.href = TPL_API + (IS_AGENCY ? '' : ('?agency_id=' + encodeURIComponent(ag)));
	});

	function buildForm(mode) {
		var fd = new FormData();
		fd.append('mode', mode);
		fd.append('file', fileInput.files[0]);
		if (!IS_AGENCY) { fd.append('agency_id', agencyId()); }
		return fd;
	}

	function renderPreview(d) {
		var s = d.summary;
		document.getElementById('bulk_summary').innerHTML =
			'<div class="d-flex flex-wrap gap-2 fs-6">'
			+ '<span class="badge badge-light fs-7 py-2">총 ' + s.total + '행</span>'
			+ '<span class="badge badge-light-success fs-7 py-2">등록 가능 ' + s.ok + '명</span>'
			+ (s.fail ? '<span class="badge badge-light-danger fs-7 py-2">오류 ' + s.fail + '명</span>' : '')
			+ '</div>';

		document.getElementById('bulk_tbody').innerHTML = d.rows.map(function (r) {
			return '<tr class="' + (r.ok ? '' : 'table-danger') + '">'
				+ '<td>' + r.row_no + '</td>'
				+ '<td>' + esc(r.name) + '</td>'
				+ '<td>' + esc(r.phone) + '</td>'
				+ '<td class="font-monospace">' + esc(r.login_id) + '</td>'
				+ '<td>' + (r.ok
					? '<span class="badge badge-light-success fs-8">등록 가능</span>'
					: '<span class="badge badge-light-danger fs-8">' + esc(r.error) + '</span>')
				+ '</td></tr>';
		}).join('');

		previewArea.classList.remove('d-none');
		if (s.ok > 0) {
			confirmBtn.classList.remove('d-none');
		} else {
			confirmBtn.classList.add('d-none');
		}
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (!fileInput.files[0]) { showAlert('엑셀 파일을 선택하세요.'); return; }
		if (!IS_AGENCY && !agencyId()) { showAlert('소속 대리점을 선택하세요.'); return; }

		alertEl.className = 'd-none mb-6';
		previewArea.classList.add('d-none');
		confirmBtn.classList.add('d-none');
		previewBtn.setAttribute('data-kt-indicator', 'on');
		previewBtn.disabled = true;

		fetch(UP_API, { method: 'POST', body: buildForm('preview'), credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d.ok) throw new Error(d.error || d.message || '오류');
				renderPreview(d);
			})
			.catch(function (err) { showAlert(esc(err.message)); })
			.finally(function () {
				previewBtn.removeAttribute('data-kt-indicator');
				previewBtn.disabled = false;
			});
	});

	confirmBtn.addEventListener('click', function () {
		if (!confirm('오류 없는 행만 실제로 등록합니다. 계속할까요?')) return;
		confirmBtn.setAttribute('data-kt-indicator', 'on');
		confirmBtn.disabled = true;

		fetch(UP_API, { method: 'POST', body: buildForm('confirm'), credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d.ok) throw new Error(d.error || d.message || '오류');
				showAlert(esc(d.message), d.summary.fail > 0 ? 'warning' : 'success');
				renderPreview(d);
				confirmBtn.classList.add('d-none');
				setTimeout(function () { location.reload(); }, 2000);
			})
			.catch(function (err) { showAlert(esc(err.message)); })
			.finally(function () {
				confirmBtn.removeAttribute('data-kt-indicator');
				confirmBtn.disabled = false;
			});
	});

	modalEl.addEventListener('show.bs.modal', function () {
		form.reset();
		var resetAgencyEl = document.getElementById('bulk_osp_agency');
		if (resetAgencyEl && window.jQuery) { jQuery(resetAgencyEl).val('').trigger('change'); }
		alertEl.className = 'd-none mb-6';
		alertEl.innerHTML = '';
		previewArea.classList.add('d-none');
		confirmBtn.classList.add('d-none');
	});

	// ── 계좌 확인 ── 계좌번호 오타는 되돌리기 어려운 오송금으로 이어진다.
	document.addEventListener('DOMContentLoaded', function () {
		if (!window.AccountVerify) { return; }
		AccountVerify.attach({ bank: 'reg_bank_code', account: 'reg_bank_account', holder: 'reg_account_holder',
			button: 'reg_verify', result: 'reg_verify_msg' });
	});
})();
</script>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

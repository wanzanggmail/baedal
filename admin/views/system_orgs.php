<?php

declare(strict_types=1);

require_once INC_PATH . '/Organization.php';

$canManage   = admin_can_manage_orgs();
$myLevel     = admin_org_level();
$isAdmin     = $myLevel === Org::LEVEL_ADMIN;
$roleLabels  = Organization::accountRoleLabels();
$distOptions = $canManage ? Organization::distributorOptions() : [];
$apiUrl      = ADMIN_BASE . '/api/orgs.php';
$withdrawalSettingsUrl = admin_url('withdrawal/settings');
$withdrawalSettingsSep = str_contains($withdrawalSettingsUrl, '?') ? '&agency=' : '?agency=';

$listError = null;
$orgs      = [];
try {
    if (!$canManage) {
        $listError = '조직을 관리할 권한이 없습니다. (본사 전용)';
    } else {
        $orgs = Organization::listManageable();
    }
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

// KPI 집계
$kpiDist = 0;
$kpiAgency = 0;
$kpiActiveRiders = 0;
$kpiInactive = 0;
foreach ($orgs as $o) {
    if ($o['level'] === Org::LEVEL_DISTRIBUTOR) {
        $kpiDist++;
    } elseif ($o['level'] === Org::LEVEL_AGENCY) {
        $kpiAgency++;
        $kpiActiveRiders += (int) $o['active_rider_count'];
    }
    if (!$o['active']) {
        $kpiInactive++;
    }
}

// 트리 정렬: 총판 → 그 하위 대리점 순으로 재배열(들여쓰기 표현용)
$distributors  = array_values(array_filter($orgs, static fn ($o) => $o['level'] === Org::LEVEL_DISTRIBUTOR));
$agencies      = array_values(array_filter($orgs, static fn ($o) => $o['level'] === Org::LEVEL_AGENCY));
$agencyByParent = [];
foreach ($agencies as $a) {
    $agencyByParent[(int) ($a['parent_id'] ?? 0)][] = $a;
}
$ordered = [];
foreach ($distributors as $d) {
    $ordered[] = ['row' => $d, 'child' => false];
    foreach ($agencyByParent[(int) $d['id']] ?? [] as $a) {
        $ordered[] = ['row' => $a, 'child' => true];
    }
    unset($agencyByParent[(int) $d['id']]);
}
// 상위 총판이 목록에 없는 대리점(있을 경우)은 마지막에 평면 표시
foreach ($agencyByParent as $orphans) {
    foreach ($orphans as $a) {
        $ordered[] = ['row' => $a, 'child' => false];
    }
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">조직 관리(총판·대리점)</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">조직</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<?php if ($canManage) : ?>
			<button type="button" class="btn btn-sm btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#kt_org_modal" id="btn_org_create">
				<i class="ki-duotone ki-plus fs-3"><span class="path1"></span><span class="path2"></span></i>
				총판/대리점 추가
			</button>
			<?php endif; ?>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($listError !== null) : ?>
	<div class="alert alert-danger mb-8"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></div>
	<?php else : ?>

	<!--begin::KPI-->
	<div class="row g-5 g-xl-8 mb-6">
		<div class="col-6 col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-5">
				<div class="text-gray-500 fw-semibold fs-7">총판</div>
				<div class="fw-bold fs-2 text-gray-900"><?= number_format($kpiDist) ?><span class="fs-7 text-muted ms-1">개</span></div>
			</div></div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-5">
				<div class="text-gray-500 fw-semibold fs-7">대리점</div>
				<div class="fw-bold fs-2 text-gray-900"><?= number_format($kpiAgency) ?><span class="fs-7 text-muted ms-1">개</span></div>
			</div></div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-5">
				<div class="text-gray-500 fw-semibold fs-7">활성 라이더(전 대리점)</div>
				<div class="fw-bold fs-2 text-gray-900"><?= number_format($kpiActiveRiders) ?><span class="fs-7 text-muted ms-1">명</span></div>
			</div></div>
		</div>
		<div class="col-6 col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-5">
				<div class="text-gray-500 fw-semibold fs-7">중지된 조직</div>
				<div class="fw-bold fs-2 <?= $kpiInactive > 0 ? 'text-warning' : 'text-gray-900' ?>"><?= number_format($kpiInactive) ?><span class="fs-7 text-muted ms-1">개</span></div>
			</div></div>
		</div>
	</div>
	<!--end::KPI-->

	<?php endif; ?>

	<div id="org_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="org_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
	</div>

	<?php if ($listError === null) : ?>
	<div class="card card-flush">
		<div class="card-header align-items-center py-5 gap-3 flex-wrap">
			<div class="card-title flex-grow-1">
				<div class="d-flex align-items-center position-relative w-100 mw-300px">
					<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
					<input type="text" id="org_search" class="form-control form-control-solid ps-12" placeholder="이름·코드·대표계정 검색" />
				</div>
			</div>
			<div class="card-toolbar gap-2">
				<select id="org_filter_level" class="form-select form-select-sm form-select-solid w-125px">
					<option value="">유형 전체</option>
					<option value="distributor">총판</option>
					<option value="agency">대리점</option>
				</select>
				<select id="org_filter_status" class="form-select form-select-sm form-select-solid w-110px">
					<option value="">상태 전체</option>
					<option value="active">활성</option>
					<option value="inactive">중지</option>
				</select>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-90px">유형</th>
							<th class="min-w-120px">코드</th>
							<th class="min-w-160px">이름</th>
							<th class="min-w-130px">대표 계정 · 역할</th>
							<th class="min-w-80px text-center">라이더</th>
							<th class="min-w-70px">상태</th>
							<?php if ($canManage) : ?>
							<th class="min-w-160px text-end">관리</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody id="org_tbody">
						<?php if ($ordered === []) : ?>
						<tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="text-center text-muted py-10">등록된 조직이 없습니다. 위의 추가 버튼으로 만드세요.</td></tr>
						<?php endif; ?>
						<?php foreach ($ordered as $item) :
							$row = $item['row'];
							$isChild = $item['child'];
							$badge = $row['level'] === Org::LEVEL_DISTRIBUTOR ? 'primary' : 'success';
							$searchKey = mb_strtolower($row['name'] . ' ' . $row['code'] . ' ' . $row['primary_login']);
						?>
						<tr class="<?= !$row['active'] ? 'opacity-75' : '' ?>"
							data-id="<?= (int) $row['id'] ?>"
							data-level="<?= htmlspecialchars((string) $row['level'], ENT_QUOTES, 'UTF-8') ?>"
							data-status="<?= $row['active'] ? 'active' : 'inactive' ?>"
							data-search="<?= htmlspecialchars($searchKey, ENT_QUOTES, 'UTF-8') ?>">
							<td>
								<?php if ($isChild) : ?><span class="text-muted me-1">└</span><?php endif; ?>
								<span class="badge badge-light-<?= $badge ?>"><?= htmlspecialchars((string) $row['level_label'], ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="<?= $isChild ? 'ps-6' : '' ?>">
								<a href="#" class="font-monospace fw-bold text-gray-800 text-hover-primary org-detail-link" data-id="<?= (int) $row['id'] ?>"><?= htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8') ?></a>
							</td>
							<td>
								<a href="#" class="fw-semibold text-gray-900 text-hover-primary org-detail-link" data-id="<?= (int) $row['id'] ?>"><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></a>
								<?php if (($row['parent_name'] ?? '') !== '') : ?><div class="text-muted fs-8"><?= htmlspecialchars((string) $row['parent_name'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
							</td>
							<td>
								<?php if (($row['primary_login'] ?? '') !== '') : ?>
									<span class="fs-7 font-monospace text-gray-800"><?= htmlspecialchars((string) $row['primary_login'], ENT_QUOTES, 'UTF-8') ?></span>
									<?php if (($row['primary_role_label'] ?? '') !== '') : ?><span class="badge badge-light-info fs-8 ms-1"><?= htmlspecialchars((string) $row['primary_role_label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
									<?php if ((int) $row['account_count'] > 1) : ?><span class="badge badge-light-secondary fs-8 ms-1">+<?= (int) $row['account_count'] - 1 ?></span><?php endif; ?>
								<?php else : ?><span class="text-muted">—</span><?php endif; ?>
							</td>
							<td class="text-center">
								<?php if ($row['level'] === Org::LEVEL_AGENCY) : ?>
									<span class="fw-bold"><?= (int) $row['active_rider_count'] ?></span><span class="text-muted fs-8">/<?= (int) $row['rider_count'] ?></span>
								<?php else : ?>—<?php endif; ?>
							</td>
							<td><?= $row['active'] ? '<span class="badge badge-light-success">활성</span>' : '<span class="badge badge-light-dark">중지</span>' ?></td>
							<?php if ($canManage) : ?>
							<td class="text-end">
								<div class="d-flex flex-wrap justify-content-end gap-1">
									<button type="button" class="btn btn-sm btn-light org-detail-link" data-id="<?= (int) $row['id'] ?>">상세</button>
									<button type="button" class="btn btn-sm btn-light-primary btn-org-edit" data-json="<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">수정</button>
									<button type="button" class="btn btn-sm btn-light-<?= $row['active'] ? 'warning' : 'success' ?> btn-org-toggle" data-id="<?= (int) $row['id'] ?>" data-active="<?= $row['active'] ? '1' : '0' ?>"><?= $row['active'] ? '중지' : '활성' ?></button>
								</div>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div id="org_no_result" class="text-center text-muted py-8 d-none">검색 조건에 맞는 조직이 없습니다.</div>
		</div>
	</div>
	<?php endif; ?>

	<!--begin::상세 모달-->
	<div class="modal fade" id="kt_org_detail_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-700px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="org_detail_title">조직 상세</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div>
				</div>
				<div class="modal-body py-lg-8 px-lg-10">
					<div id="org_detail_body">
						<div class="text-center text-muted py-10">불러오는 중…</div>
					</div>
					<?php if ($canManage) : ?>
					<div id="org_acct_form_wrap" class="d-none border border-primary border-dashed rounded p-4 mt-4">
						<h5 class="fw-bold fs-6 mb-3" id="org_acct_form_title">계정 추가</h5>
						<input type="hidden" id="org_acct_id" value="" />
						<div class="row g-3">
							<div class="col-md-6" id="org_acct_login_wrap">
								<label class="form-label fs-8 required">로그인 ID</label>
								<input type="text" id="org_acct_login" class="form-control form-control-sm form-control-solid" maxlength="60" autocomplete="off" />
							</div>
							<div class="col-md-6">
								<label class="form-label fs-8 required">이름</label>
								<input type="text" id="org_acct_name" class="form-control form-control-sm form-control-solid" maxlength="50" />
							</div>
							<div class="col-md-6">
								<label class="form-label fs-8 required">역할</label>
								<select id="org_acct_role" class="form-select form-select-sm form-select-solid">
									<?php foreach ($roleLabels as $value => $label) : ?>
									<option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-6">
								<label class="form-label fs-8" id="org_acct_pw_label">비밀번호</label>
								<input type="password" id="org_acct_pw" class="form-control form-control-sm form-control-solid" autocomplete="new-password" />
								<div class="form-text fs-8" id="org_acct_pw_hint">8자 이상</div>
							</div>
						</div>
						<div class="d-flex justify-content-end gap-2 mt-3">
							<button type="button" class="btn btn-sm btn-light" id="org_acct_cancel">취소</button>
							<button type="button" class="btn btn-sm btn-primary" id="org_acct_save">저장</button>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<!--end::상세 모달-->

	<?php if ($canManage) : ?>
	<!--begin::생성/수정 모달-->
	<div class="modal fade" id="kt_org_modal" tabindex="-1" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered mw-650px">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="fw-bold" id="org_modal_title">조직 추가</h2>
					<div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal"><i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i></div>
				</div>
				<div class="modal-body py-lg-10 px-lg-17">
					<form id="org_form">
						<input type="hidden" id="org_edit_id" value="" />
						<div id="org_create_only">
							<div class="mb-5">
								<label class="form-label required">조직 유형</label>
								<select class="form-select form-select-solid" id="org_level">
									<option value="distributor">총판</option>
									<option value="agency">대리점</option>
								</select>
							</div>
							<div class="mb-5 d-none" id="org_parent_wrap">
								<label class="form-label required">상위 총판</label>
								<select class="form-select form-select-solid" id="org_parent_id">
									<option value="">선택하세요</option>
									<?php foreach ($distOptions as $d) : ?>
									<option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name'] . ' (' . $d['code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="mb-5">
								<label class="form-label required" for="org_code">조직 코드</label>
								<div class="input-group">
									<input type="text" class="form-control form-control-solid" id="org_code" maxlength="40" placeholder="자동 생성" />
									<button class="btn btn-light-primary" type="button" id="org_code_regen" title="코드 재생성">자동</button>
								</div>
								<div class="form-text">유형 선택 시 자동 생성됩니다. 직접 수정 가능(영문 대문자·숫자·_·-)</div>
							</div>
						</div>
						<div class="mb-5">
							<label class="form-label required" for="org_name">조직 이름</label>
							<input type="text" class="form-control form-control-solid" id="org_name" maxlength="120" />
						</div>
						<div class="row">
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_contact_name">담당자명</label>
								<input type="text" class="form-control form-control-solid" id="org_contact_name" maxlength="80" />
							</div>
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_contact_phone">연락처</label>
								<input type="text" class="form-control form-control-solid" id="org_contact_phone" maxlength="30" />
							</div>
						</div>
						<div class="mb-5">
							<label class="form-label" for="org_memo">메모</label>
							<textarea class="form-control form-control-solid" id="org_memo" rows="2" maxlength="500" placeholder="내부 관리용 메모 (계약 정보, 특이사항 등)"></textarea>
						</div>

						<div class="separator separator-dashed my-6"></div>
						<h4 class="fw-bold fs-6 mb-4">대표자 정보</h4>
						<div class="row">
							<div class="col-md-4 mb-5">
								<label class="form-label" for="org_ceo_name">대표자명</label>
								<input type="text" class="form-control form-control-solid" id="org_ceo_name" maxlength="80" />
							</div>
							<div class="col-md-4 mb-5">
								<label class="form-label" for="org_ceo_phone">대표자 휴대폰</label>
								<input type="text" class="form-control form-control-solid" id="org_ceo_phone" maxlength="30" placeholder="010-0000-0000" />
							</div>
							<div class="col-md-4 mb-5">
								<label class="form-label" for="org_ceo_birth">생년월일</label>
								<input type="text" class="form-control form-control-solid" id="org_ceo_birth" maxlength="10" placeholder="YYMMDD" />
							</div>
						</div>

						<div class="separator separator-dashed my-6"></div>
						<h4 class="fw-bold fs-6 mb-4">사업자 정보</h4>
						<div class="row">
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_biz_name">사업자명</label>
								<input type="text" class="form-control form-control-solid" id="org_biz_name" maxlength="120" />
							</div>
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_biz_reg_no">사업자번호</label>
								<input type="text" class="form-control form-control-solid" id="org_biz_reg_no" maxlength="20" placeholder="000-00-00000" />
							</div>
						</div>
						<div class="row">
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_biz_type">업태</label>
								<input type="text" class="form-control form-control-solid" id="org_biz_type" maxlength="60" />
							</div>
							<div class="col-md-6 mb-5">
								<label class="form-label" for="org_biz_category">업종</label>
								<input type="text" class="form-control form-control-solid" id="org_biz_category" maxlength="60" />
							</div>
						</div>
						<div class="mb-5">
							<label class="form-label" for="org_biz_address">사업장 주소</label>
							<input type="text" class="form-control form-control-solid" id="org_biz_address" maxlength="200" />
						</div>

						<div class="alert bg-light-info fs-8 py-2 px-3 mb-0 d-none" id="org_edit_account_hint">
							💡 로그인 계정(역할별 여러 개)은 <strong>상세 화면의 "소속 계정" 섹션</strong>에서 추가·관리할 수 있습니다.
						</div>
						<div id="org_account_section">
							<div class="separator separator-dashed my-6"></div>
							<h4 class="fw-bold fs-6 mb-4">로그인 계정 발급(대표 계정)</h4>
							<div class="row">
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_login_id">로그인 ID</label>
									<input type="text" class="form-control form-control-solid" id="org_login_id" maxlength="60" autocomplete="off" />
								</div>
								<div class="col-md-6 mb-5">
									<label class="form-label" for="org_account_name">계정 이름</label>
									<input type="text" class="form-control form-control-solid" id="org_account_name" maxlength="50" placeholder="비우면 조직명+담당자" />
								</div>
							</div>
							<div class="row">
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_password">비밀번호</label>
									<input type="password" class="form-control form-control-solid" id="org_password" autocomplete="new-password" minlength="8" />
									<div class="form-text">8자 이상</div>
								</div>
								<div class="col-md-6 mb-5">
									<label class="form-label required" for="org_role">계정 역할</label>
									<select class="form-select form-select-solid" id="org_role">
										<?php foreach ($roleLabels as $value => $label) : ?>
										<option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= $value === 'operation' ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
						</div>
					</form>
				</div>
				<div class="modal-footer flex-center">
					<button type="button" class="btn btn-light me-3" data-bs-dismiss="modal">취소</button>
					<button type="button" class="btn btn-primary" id="org_save_btn"><span class="indicator-label">저장</span></button>
				</div>
			</div>
		</div>
	</div>
	<!--end::생성/수정 모달-->
	<?php endif; ?>

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
		var CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
		var RIDERS_URL = <?= json_encode(admin_url('riders/list'), JSON_UNESCAPED_UNICODE) ?>;
		var WITHDRAWAL_SETTINGS_URL = <?= json_encode($withdrawalSettingsUrl, JSON_UNESCAPED_UNICODE) ?>;
		var WITHDRAWAL_SETTINGS_SEP = <?= json_encode($withdrawalSettingsSep, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('org_toast');
		var toastMsg = document.getElementById('org_toast_msg');
		function $(id) { return document.getElementById(id); }
		function showToast(msg, ok) {
			if (!toast) return;
			toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
			toastMsg.textContent = msg;
			toast.classList.remove('d-none');
		}
		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
			});
		}
		function won(n) { return (n || 0).toLocaleString('ko-KR') + '원'; }

		// ── 검색·필터 ──────────────────────────────
		var searchEl = $('org_search'), levelEl = $('org_filter_level'), statusEl = $('org_filter_status');
		function applyFilter() {
			var q = (searchEl.value || '').trim().toLowerCase();
			var lv = levelEl.value, st = statusEl.value;
			var rows = document.querySelectorAll('#org_tbody tr[data-id]');
			var shown = 0;
			rows.forEach(function (tr) {
				var ok = (!q || tr.getAttribute('data-search').indexOf(q) !== -1)
					&& (!lv || tr.getAttribute('data-level') === lv)
					&& (!st || tr.getAttribute('data-status') === st);
				tr.classList.toggle('d-none', !ok);
				if (ok) shown++;
			});
			var noRes = $('org_no_result');
			if (noRes) noRes.classList.toggle('d-none', shown !== 0 || rows.length === 0);
		}
		if (searchEl) {
			searchEl.addEventListener('input', applyFilter);
			levelEl.addEventListener('change', applyFilter);
			statusEl.addEventListener('change', applyFilter);
		}

		// ── 상세 모달 ──────────────────────────────
		var detailModalEl = $('kt_org_detail_modal');
		var detailBody = $('org_detail_body');
		var LEVEL_LABEL = { distributor: '총판', agency: '대리점', admin: '본사' };
		var STATUS_LABEL = { active: '활동 중', suspended: '일시 정지', leave_request: '탈퇴 요청', offboarded: '계약 종료' };

		function kv(label, value) {
			return '<div class="d-flex justify-content-between py-2 border-bottom border-gray-200"><span class="text-muted">' + esc(label) + '</span><span class="fw-semibold text-gray-800">' + value + '</span></div>';
		}
		function renderDetail(d) {
			var o = d.org;
			var h = '';
			$('org_detail_title').textContent = o.name + ' (' + o.code + ')';

			h += '<div class="d-flex align-items-center gap-2 mb-4">';
			h += '<span class="badge badge-light-' + (o.level === 'distributor' ? 'primary' : 'success') + '">' + esc(o.level_label) + '</span>';
			h += o.active ? '<span class="badge badge-light-success">활성</span>' : '<span class="badge badge-light-dark">중지</span>';
			h += '</div>';

			h += '<div class="row"><div class="col-md-6">';
			h += kv('코드', '<span class="font-monospace">' + esc(o.code) + '</span>');
			h += kv('상위', esc(o.parent_name || '—'));
			h += kv('담당자', esc(o.contact_name || '—'));
			h += '</div><div class="col-md-6">';
			h += kv('연락처', esc(o.contact_phone || '—'));
			h += kv('생성일', esc(o.created_at || '—'));
			h += kv('계정 수', o.account_count + '개 (활성 ' + o.active_account_count + ')');
			h += '</div></div>';

			if (o.ceo_name || o.ceo_phone || o.ceo_birth) {
				h += '<div class="separator separator-dashed my-5"></div>';
				h += '<h4 class="fw-bold fs-6 mb-3">대표자 정보</h4>';
				h += '<div class="row"><div class="col-md-6">';
				h += kv('대표자명', esc(o.ceo_name || '—'));
				h += '</div><div class="col-md-6">';
				h += kv('휴대폰', esc(o.ceo_phone || '—'));
				h += kv('생년월일', esc(o.ceo_birth || '—'));
				h += '</div></div>';
			}

			if (o.biz_name || o.biz_reg_no) {
				h += '<div class="separator separator-dashed my-5"></div>';
				h += '<h4 class="fw-bold fs-6 mb-3">사업자 정보</h4>';
				h += '<div class="row"><div class="col-md-6">';
				h += kv('사업자명', esc(o.biz_name || '—'));
				h += kv('사업자번호', esc(o.biz_reg_no || '—'));
				h += '</div><div class="col-md-6">';
				h += kv('업태 / 업종', esc((o.biz_type || '—') + ' / ' + (o.biz_category || '—')));
				h += kv('주소', esc(o.biz_address || '—'));
				h += '</div></div>';
			}

			// 계정 목록 (역할별 여러 개 관리)
			h += '<div class="separator separator-dashed my-5"></div>';
			h += '<div class="d-flex align-items-center justify-content-between mb-3">';
			h += '<h4 class="fw-bold fs-6 m-0">소속 계정 <span class="text-muted fs-8">(역할별 여러 개)</span></h4>';
			if (CAN_MANAGE) { h += '<button type="button" class="btn btn-sm btn-light-primary" id="org_acct_add_btn">＋ 계정 추가</button>'; }
			h += '</div>';
			h += '<div class="table-responsive"><table class="table table-row-bordered align-middle fs-7 gy-2 mb-0"><tbody>';
			(d.accounts || []).forEach(function (a) {
				h += '<tr><td class="font-monospace fw-bold">' + esc(a.login_id)
					+ (a.is_primary ? ' <span class="badge badge-light-primary fs-8">대표</span>' : '') + '</td>'
					+ '<td>' + esc(a.name) + '</td>'
					+ '<td><span class="badge badge-light-info fs-8">' + esc(a.role_label) + '</span></td>'
					+ '<td>' + (a.active ? '<span class="text-success">활성</span>' : '<span class="text-muted">중지</span>') + '</td>'
					+ '<td class="text-muted fs-8">' + esc(a.last_login_at || '로그인 없음') + '</td>';
				if (CAN_MANAGE) {
					h += '<td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-light-primary me-1 org-acct-edit" '
						+ 'data-acct=\'' + esc(JSON.stringify(a)) + '\' title="수정">✎</button>'
						+ '<button type="button" class="btn btn-sm btn-icon btn-light-' + (a.active ? 'warning' : 'success') + ' org-acct-toggle" '
						+ 'data-id="' + a.id + '" data-active="' + (a.active ? '1' : '0') + '" title="' + (a.active ? '중지' : '활성') + '">' + (a.active ? '⏸' : '▶') + '</button></td>';
				}
				h += '</tr>';
			});
			h += '</tbody></table></div>';

			// 대리점 상세
			if (d.agency) {
				var ag = d.agency, rs = ag.rider_status;
				h += '<div class="separator separator-dashed my-5"></div>';
				h += '<h4 class="fw-bold fs-6 mb-3">대리점 현황</h4>';
				h += '<div class="row"><div class="col-md-6">';
				h += kv('라이더 (활성/정지/탈퇴요청/종료)', rs.active + ' / ' + rs.suspended + ' / ' + rs.leave_request + ' / ' + rs.offboarded);
				h += kv('원천세 대상 라이더', ag.withholding_riders + '명');
				h += kv('정산 업로드', ag.upload_count + '건');
				h += '</div><div class="col-md-6">';
				if (ag.wallet) {
					h += kv('대리점 잔액', won(ag.wallet.balance));
					h += kv('인출가능액', '<span class="text-primary fw-bold">' + won(ag.wallet.withdrawable) + '</span>');
					h += kv('라이더 정산금 / 원천세예수금', won(ag.wallet.rider_debt) + ' / ' + won(ag.wallet.withholding_reserve));
				}
				h += '</div></div>';
				h += '<div class="row mt-2"><div class="col-md-6">';
				if (ag.fee_config) {
					h += kv('보증금', '<span class="fw-bold">' + won(ag.fee_config.reserve_amount) + '</span>');
					h += kv('정산수수료(기준일/미만/이상)', ag.fee_config.fee_day_threshold + '일 / ' + ag.fee_config.fee_per_tx_short + '원 / ' + ag.fee_config.fee_per_tx_long + '원');
				}
				if (ag.pg_fee) {
					h += kv('플랫폼 수수료(총)', ag.pg_fee.total + '%');
				}
				h += '</div><div class="col-md-6">';
				h += kv('등록 카드', ag.card_count + '개');
				h += kv('오픈뱅킹 계좌', ag.has_bank_account ? '<span class="text-success">등록됨</span>' : '<span class="text-muted">미등록</span>');
				h += '</div></div>';
				h += '<div class="mt-3"><a href="' + WITHDRAWAL_SETTINGS_URL + WITHDRAWAL_SETTINGS_SEP + o.id + '" class="btn btn-sm btn-light-primary">보증금·정산수수료 수정</a></div>';
			}

			// 총판 상세
			if (d.distributor) {
				var dist = d.distributor;
				h += '<div class="separator separator-dashed my-5"></div>';
				h += '<h4 class="fw-bold fs-6 mb-3">하위 대리점 (' + dist.agency_count + '개 · 라이더 ' + dist.total_riders + '명)</h4>';
				if (dist.children.length) {
					h += '<div class="table-responsive"><table class="table table-row-bordered align-middle fs-7 gy-2 mb-0"><tbody>';
					dist.children.forEach(function (c) {
						h += '<tr><td class="font-monospace">' + esc(c.code) + '</td><td class="fw-semibold">' + esc(c.name) + '</td>'
							+ '<td class="text-center">라이더 ' + c.active_rider_count + '/' + c.rider_count + '</td>'
							+ '<td>' + (c.active ? '<span class="text-success">활성</span>' : '<span class="text-muted">중지</span>') + '</td></tr>';
					});
					h += '</tbody></table></div>';
				} else {
					h += '<div class="text-muted">하위 대리점이 없습니다.</div>';
				}
			}

			detailBody.innerHTML = h;
		}

		var currentDetailOrgId = 0;
		function loadDetail(id) {
			currentDetailOrgId = Number(id);
			detailBody.innerHTML = '<div class="text-center text-muted py-10">불러오는 중…</div>';
			hideAcctForm();
			return fetch(API + '?detail=' + id, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '조회 실패');
					renderDetail(res.detail);
				})
				.catch(function (e) { detailBody.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>'; });
		}
		document.addEventListener('click', function (ev) {
			var link = ev.target.closest('.org-detail-link');
			if (!link) return;
			ev.preventDefault();
			bootstrap.Modal.getOrCreateInstance(detailModalEl).show();
			loadDetail(link.getAttribute('data-id'));
		});

		if (!CAN_MANAGE) return;

		// ── 상세 모달 내 계정 관리 ──────────────────
		var acctWrap = $('org_acct_form_wrap');
		function hideAcctForm() { if (acctWrap) acctWrap.classList.add('d-none'); }
		function openAcctForm(mode, a) {
			$('org_acct_id').value = mode === 'edit' ? a.id : '';
			$('org_acct_form_title').textContent = mode === 'edit' ? ('계정 수정 — ' + a.login_id) : '계정 추가';
			$('org_acct_login_wrap').style.display = mode === 'edit' ? 'none' : '';
			$('org_acct_login').value = '';
			$('org_acct_name').value = mode === 'edit' ? (a.name || '') : '';
			$('org_acct_role').value = mode === 'edit' ? a.role : 'operation';
			$('org_acct_pw').value = '';
			$('org_acct_pw_label').textContent = mode === 'edit' ? '비밀번호 재설정' : '비밀번호';
			$('org_acct_pw_hint').textContent = mode === 'edit' ? '변경 시에만 입력 (8자 이상)' : '8자 이상';
			acctWrap.classList.remove('d-none');
			acctWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
		$('org_acct_cancel').addEventListener('click', hideAcctForm);

		detailBody.addEventListener('click', function (ev) {
			if (ev.target.closest('#org_acct_add_btn')) { openAcctForm('add'); return; }
			var editBtn = ev.target.closest('.org-acct-edit');
			if (editBtn) { try { openAcctForm('edit', JSON.parse(editBtn.getAttribute('data-acct'))); } catch (e) {} return; }
			var togBtn = ev.target.closest('.org-acct-toggle');
			if (togBtn) {
				var active = togBtn.getAttribute('data-active') !== '1';
				acctPost({ action: 'account_toggle', org_id: currentDetailOrgId, account_id: Number(togBtn.getAttribute('data-id')), active: active });
			}
		});
		$('org_acct_save').addEventListener('click', function () {
			var id = $('org_acct_id').value;
			var payload = {
				org_id: currentDetailOrgId,
				name: $('org_acct_name').value.trim(),
				role: $('org_acct_role').value,
				password: $('org_acct_pw').value
			};
			if (!payload.name) { showToast('이름을 입력하세요.', false); return; }
			if (id) {
				payload.action = 'account_update';
				payload.account_id = Number(id);
			} else {
				payload.action = 'account_add';
				payload.login_id = $('org_acct_login').value.trim();
				if (!payload.login_id) { showToast('로그인 ID를 입력하세요.', false); return; }
				if (payload.password.length < 8) { showToast('비밀번호는 8자 이상이어야 합니다.', false); return; }
			}
			acctPost(payload);
		});
		function acctPost(payload) {
			fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '처리 실패');
					showToast(res.message, true);
					loadDetail(currentDetailOrgId); // 모달 유지한 채 새로고침
				})
				.catch(function (e) { showToast(e.message, false); });
		}

		// ── 생성/수정 ──────────────────────────────
		function syncParent() {
			var lvl = $('org_level').value;
			$('org_parent_wrap').classList.toggle('d-none', lvl !== 'agency');
		}
		function fetchCode() {
			var lvl = $('org_level').value;
			fetch(API + '?suggest_code=' + encodeURIComponent(lvl), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (res.ok) $('org_code').value = res.code; })
				.catch(function () {});
		}
		if ($('org_level')) {
			$('org_level').addEventListener('change', function () { syncParent(); fetchCode(); });
		}
		if ($('org_code_regen')) {
			$('org_code_regen').addEventListener('click', fetchCode);
		}

		function resetForm() {
			$('org_modal_title').textContent = '조직 추가';
			$('org_edit_id').value = '';
			$('org_form').reset();
			$('org_create_only').classList.remove('d-none');
			$('org_account_section').classList.remove('d-none');
			$('org_edit_account_hint').classList.add('d-none');
			syncParent();
			fetchCode();
		}
		$('btn_org_create').addEventListener('click', resetForm);

		$('org_tbody').addEventListener('click', function (ev) {
			var edit = ev.target.closest('.btn-org-edit');
			var toggle = ev.target.closest('.btn-org-toggle');
			if (edit) {
				var row = JSON.parse(edit.getAttribute('data-json') || '{}');
				$('org_modal_title').textContent = '조직 수정 — ' + (row.name || '');
				$('org_edit_id').value = row.id || '';
				$('org_create_only').classList.add('d-none');
				$('org_account_section').classList.add('d-none');
				$('org_edit_account_hint').classList.remove('d-none');
				$('org_name').value = row.name || '';
				$('org_contact_name').value = row.contact_name || '';
				$('org_contact_phone').value = row.contact_phone || '';
				$('org_memo').value = row.memo || '';
				$('org_ceo_name').value = row.ceo_name || '';
				$('org_ceo_phone').value = row.ceo_phone || '';
				$('org_ceo_birth').value = row.ceo_birth || '';
				$('org_biz_name').value = row.biz_name || '';
				$('org_biz_reg_no').value = row.biz_reg_no || '';
				$('org_biz_type').value = row.biz_type || '';
				$('org_biz_category').value = row.biz_category || '';
				$('org_biz_address').value = row.biz_address || '';
				bootstrap.Modal.getOrCreateInstance($('kt_org_modal')).show();
				return;
			}
			if (toggle) {
				var id = toggle.getAttribute('data-id');
				var active = toggle.getAttribute('data-active') !== '1';
				var label = active ? '활성화' : '중지';
				if (!confirm('이 조직을 ' + label + '할까요? 소속 계정 로그인도 함께 ' + label + '됩니다.')) return;
				post({ action: 'toggle_active', id: Number(id), active: active });
			}
		});

		$('org_save_btn').addEventListener('click', function () {
			var editId = $('org_edit_id').value;
			var payload = {
				action: 'save',
				id: editId ? Number(editId) : 0,
				name: $('org_name').value.trim(),
				contact_name: $('org_contact_name').value.trim(),
				contact_phone: $('org_contact_phone').value.trim(),
				memo: $('org_memo').value.trim(),
				ceo_name: $('org_ceo_name').value.trim(),
				ceo_phone: $('org_ceo_phone').value.trim(),
				ceo_birth: $('org_ceo_birth').value.trim(),
				biz_name: $('org_biz_name').value.trim(),
				biz_reg_no: $('org_biz_reg_no').value.trim(),
				biz_type: $('org_biz_type').value.trim(),
				biz_category: $('org_biz_category').value.trim(),
				biz_address: $('org_biz_address').value.trim()
			};
			if (!payload.name) { showToast('조직 이름을 입력하세요.', false); return; }
			if (!editId) {
				payload.level = $('org_level').value;
				payload.code = $('org_code').value.trim().toUpperCase();
				if (payload.level === 'agency') {
					payload.parent_id = Number($('org_parent_id').value || 0);
					if (!payload.parent_id) { showToast('상위 총판을 선택하세요.', false); return; }
				}
				payload.login_id = $('org_login_id').value.trim();
				payload.account_name = $('org_account_name').value.trim();
				payload.password = $('org_password').value;
				payload.role = $('org_role').value;
				if (!payload.login_id) { showToast('로그인 ID를 입력하세요.', false); return; }
				if (payload.password.length < 8) { showToast('비밀번호는 8자 이상이어야 합니다.', false); return; }
			}
			post(payload);
		});

		function post(payload) {
			fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '처리 실패');
					var inst = bootstrap.Modal.getInstance($('kt_org_modal'));
					if (inst) inst.hide();
					location.reload();
				})
				.catch(function (e) { showToast(e.message || '처리 실패', false); });
		}
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

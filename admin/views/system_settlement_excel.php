<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementExcelConfig.php';
require_once INC_PATH . '/Org.php';

$isAgencyLevel    = admin_org_level() === Org::LEVEL_AGENCY;
$isHq             = admin_org_level() === Org::LEVEL_ADMIN;
$excelConfigReady = SettlementExcelConfig::tableExists();
$excelConfigApi   = ADMIN_BASE . '/api/settlement_excel_config.php';
$canWrite         = admin_can_write('settlement');
$uploadUrl        = admin_url('settlement/upload');
$checkUrl         = rtrim(ADMIN_BASE, '/') . '/api/settlement_excel_check.php';

$platformLabels = [
    'baemin'  => '배달의민족',
    'coupang' => '쿠팡이츠',
    'other'   => '기타',
];

// 🔑 일일/주간은 열기 암호가 다르다(배민 확인: 주간=사업자등록번호, 일일=별도 암호).
//    그래서 "platform|kind" 키로 따로 저장·표시한다.
$excelPasswords  = $isAgencyLevel ? SettlementExcelConfig::allStoredByKind(admin_org_id()) : [];
$globalPasswords = $isHq ? SettlementExcelConfig::allStoredByKind(null) : null;
$agencyRows      = $isAgencyLevel ? [] : SettlementExcelConfig::listAgencyRows();
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">정산 엑셀 열기 암호</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">정산 엑셀 암호</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars($uploadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary fw-bold">정산 업로드</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if (!$excelConfigReady) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>
	<div class="alert alert-dismissible bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-lock fs-2hx text-primary me-4 mb-5 mb-sm-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			플랫폼에서 받은 정산서 xlsx에 <strong>파일 열기 암호</strong>가 걸려 있으면, 여기에 등록해 두세요.
			<strong>정산 업로드</strong> 시 자동으로 해제한 뒤 파싱합니다. 업로드 화면에서는 암호를 입력하지 않습니다.
			<?php if (!$isAgencyLevel) : ?>
			대리점이 자기 암호를 등록하지 않으면 <strong>전역 기본</strong> 암호로 대체 시도합니다.
			<?php endif; ?>
		</div>
	</div>

	<div id="excel_pw_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="excel_pw_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<?php if ($isAgencyLevel) : ?>
	<!-- 대리점 계정: 자기 암호만 -->
	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">플랫폼별 열기 암호 <span class="badge badge-light-info ms-2 fs-8">우리 대리점 전용</span></h3>
				</div>
				<div class="card-body pt-0 fs-7">
					<form class="row g-4 excel-pw-form" data-org-id="">
						<?php foreach (SettlementExcelConfig::platforms() as $pf) :
						    $pfLabel = $platformLabels[$pf] ?? $pf;
						    ?>
						<div class="col-md-4">
							<label class="form-label"><?= htmlspecialchars($pfLabel, ENT_QUOTES, 'UTF-8') ?></label>
							<?php foreach (SettlementExcelConfig::kinds() as $kd) : ?>
							<div class="input-group input-group-sm mb-2">
								<span class="input-group-text fs-8" style="min-width:52px"><?= htmlspecialchars(SettlementExcelConfig::kindLabel($kd), ENT_QUOTES, 'UTF-8') ?></span>
								<input type="password" class="form-control form-control-solid excel-pw-input"
									data-platform="<?= $pf ?>" data-kind="<?= $kd ?>"
									id="excel_pw_own_<?= htmlspecialchars($pf . '_' . $kd, ENT_QUOTES, 'UTF-8') ?>"
									value="<?= htmlspecialchars($excelPasswords[$pf . '|' . $kd] ?? '', ENT_QUOTES, 'UTF-8') ?>"
									autocomplete="new-password" placeholder="없으면 비움" <?= $canWrite ? '' : 'readonly' ?> />
							</div>
							<?php endforeach; ?>
						</div>
						<?php endforeach; ?>
						<div class="col-12">
							<?php if ($canWrite) : ?>
							<button type="button" class="btn btn-primary excel-pw-save-btn">저장</button>
							<span class="text-muted ms-3">비우고 저장하면 해당 플랫폼 등록 암호를 삭제합니다.</span>
							<?php else : ?>
							<p class="text-muted mb-0">조회 전용 계정은 설정을 변경할 수 없습니다.</p>
							<?php endif; ?>
						</div>
					</form>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<?php require INC_PATH . '/settlement_excel_requirements_card.php'; ?>
		</div>
	</div>
	<?php else : ?>
	<!-- 본사·총판 계정: 전역 기본 + 스코프 내 대리점 리스트 -->
	<div class="row g-6 mb-6">
		<?php if ($isHq) : ?>
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">전역 기본 <span class="badge badge-light-secondary ms-2 fs-8">미설정 대리점에 적용</span></h3>
				</div>
				<div class="card-body pt-0 fs-7">
					<form class="row g-4 excel-pw-form" data-org-id="global">
						<?php foreach (SettlementExcelConfig::platforms() as $pf) :
						    $pfLabel = $platformLabels[$pf] ?? $pf;
						    ?>
						<div class="col-md-4">
							<label class="form-label"><?= htmlspecialchars($pfLabel, ENT_QUOTES, 'UTF-8') ?></label>
							<?php foreach (SettlementExcelConfig::kinds() as $kd) : ?>
							<div class="input-group input-group-sm mb-2">
								<span class="input-group-text fs-8" style="min-width:52px"><?= htmlspecialchars(SettlementExcelConfig::kindLabel($kd), ENT_QUOTES, 'UTF-8') ?></span>
								<input type="password" class="form-control form-control-solid excel-pw-input"
									data-platform="<?= $pf ?>" data-kind="<?= $kd ?>"
									id="excel_pw_global_<?= htmlspecialchars($pf . '_' . $kd, ENT_QUOTES, 'UTF-8') ?>"
									value="<?= htmlspecialchars($globalPasswords[$pf . '|' . $kd] ?? '', ENT_QUOTES, 'UTF-8') ?>"
									autocomplete="new-password" placeholder="없으면 비움" <?= $canWrite ? '' : 'readonly' ?> />
							</div>
							<?php endforeach; ?>
						</div>
						<?php endforeach; ?>
						<div class="col-12">
							<?php if ($canWrite) : ?>
							<button type="button" class="btn btn-primary excel-pw-save-btn">저장</button>
							<?php else : ?>
							<p class="text-muted mb-0">조회 전용 계정은 설정을 변경할 수 없습니다.</p>
							<?php endif; ?>
						</div>
					</form>
				</div>
			</div>
		</div>
		<div class="col-xl-5">
			<?php require INC_PATH . '/settlement_excel_requirements_card.php'; ?>
		</div>
		<?php else : ?>
		<div class="col-12">
			<?php require INC_PATH . '/settlement_excel_requirements_card.php'; ?>
		</div>
		<?php endif; ?>
	</div>

	<div class="card card-flush">
		<div class="card-header pt-5">
			<h3 class="card-title fw-bold">대리점별 열기 암호</h3>
			<span class="text-gray-500 fs-7 fw-semibold d-block mt-1"><?= count($agencyRows) ?>개 대리점 · 비워두면 전역 기본을 사용합니다</span>
		</div>
		<div class="card-body pt-0">
			<?php if ($agencyRows === []) : ?>
			<p class="text-muted fs-7 py-6 mb-0 text-center">조회 범위 내 대리점이 없습니다.</p>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-dashed align-middle">
					<thead>
						<tr class="fw-bold text-muted">
							<th>대리점</th>
							<?php foreach (SettlementExcelConfig::platforms() as $pf) : ?>
							<th><?= htmlspecialchars($platformLabels[$pf] ?? $pf, ENT_QUOTES, 'UTF-8') ?></th>
							<?php endforeach; ?>
							<?php if ($canWrite) : ?>
							<th class="text-end">저장</th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($agencyRows as $row) : ?>
						<tr>
							<td>
								<div class="fw-semibold"><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></div>
								<div class="text-muted fs-8"><?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8') ?><?= $row['parent_name'] !== null ? ' · ' . htmlspecialchars($row['parent_name'], ENT_QUOTES, 'UTF-8') : '' ?></div>
							</td>
							<?php foreach (SettlementExcelConfig::platforms() as $pf) : ?>
							<td>
								<?php foreach (SettlementExcelConfig::kinds() as $kd) : ?>
								<div class="input-group input-group-sm mb-1">
									<span class="input-group-text fs-9 px-2"><?= htmlspecialchars(SettlementExcelConfig::kindLabel($kd), ENT_QUOTES, 'UTF-8') ?></span>
									<input type="password" class="form-control form-control-sm excel-pw-input"
										data-platform="<?= $pf ?>" data-kind="<?= $kd ?>"
										value="<?= htmlspecialchars($row['passwords'][$pf . '|' . $kd] ?? '', ENT_QUOTES, 'UTF-8') ?>"
										autocomplete="new-password" placeholder="비움=전역기본" <?= $canWrite ? '' : 'readonly' ?>
										form="excel_pw_row_<?= (int) $row['id'] ?>" />
								</div>
								<?php endforeach; ?>
							</td>
							<?php endforeach; ?>
							<?php if ($canWrite) : ?>
							<td class="text-end">
								<form id="excel_pw_row_<?= (int) $row['id'] ?>" class="excel-pw-form d-inline" data-org-id="<?= (int) $row['id'] ?>"></form>
								<button type="button" class="btn btn-sm btn-light-primary excel-pw-save-btn" data-form-id="excel_pw_row_<?= (int) $row['id'] ?>">저장</button>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php if ($excelConfigReady && $canWrite) : ?>
<script>
(function () {
	'use strict';
	var API = <?= json_encode($excelConfigApi, JSON_UNESCAPED_UNICODE) ?>;
	var toast = document.getElementById('excel_pw_toast');
	var toastMsg = document.getElementById('excel_pw_toast_msg');

	function showToast(msg, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = msg;
		toast.classList.remove('d-none');
	}

	function collectPasswords(root) {
		// 키는 "platform|kind" (예: baemin|weekly) — 일일/주간 암호가 서로 다르기 때문.
		var out = {};
		root.querySelectorAll('.excel-pw-input').forEach(function (input) {
			var kind = input.dataset.kind || 'daily';
			out[input.dataset.platform + '|' + kind] = input.value || '';
		});
		return out;
	}

	document.querySelectorAll('.excel-pw-save-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var formId = btn.dataset.formId;
			var root = formId ? document.getElementById(formId) : btn.closest('.excel-pw-form');
			var inputsRoot = formId ? btn.closest('tr') : root;
			var orgId = root.dataset.orgId || '';
			btn.disabled = true;
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ action: 'save', org_id: orgId, passwords: collectPasswords(inputsRoot) }),
			})
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) throw new Error(res.message || '저장 실패');
					showToast(res.message || '저장되었습니다.', true);
				})
				.catch(function (err) {
					showToast(err.message || '저장 실패', false);
				})
				.finally(function () {
					btn.disabled = false;
				});
		});
	});
})();
</script>
<?php endif; ?>

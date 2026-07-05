<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementExcelConfig.php';

// 멀티테넌시: 대리점=자기 암호 / 그 외=전역 기본
$cfgOrgId         = admin_org_level() === Org::LEVEL_AGENCY ? admin_org_id() : null;
$cfgScopeLabel    = $cfgOrgId !== null ? '우리 대리점 전용' : '전역 기본(미설정 대리점에 적용)';
$excelPasswords   = SettlementExcelConfig::allStored($cfgOrgId);
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
		</div>
	</div>

	<div id="excel_pw_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="excel_pw_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
	</div>

	<div class="row g-6">
		<div class="col-xl-7">
			<div class="card card-flush">
				<div class="card-header pt-5">
					<h3 class="card-title fw-bold">플랫폼별 열기 암호 <span class="badge badge-light-info ms-2 fs-8"><?= htmlspecialchars($cfgScopeLabel, ENT_QUOTES, 'UTF-8') ?></span></h3>
				</div>
				<div class="card-body pt-0 fs-7">
					<form id="excel_pw_form" class="row g-4">
						<?php foreach (SettlementExcelConfig::platforms() as $pf) :
						    $pfLabel = $platformLabels[$pf] ?? $pf;
						    $meta = SettlementExcelConfig::storedPasswordMeta($pf, $cfgOrgId);
						    ?>
						<div class="col-md-4">
							<label class="form-label" for="excel_pw_<?= htmlspecialchars($pf, ENT_QUOTES, 'UTF-8') ?>">
								<?= htmlspecialchars($pfLabel, ENT_QUOTES, 'UTF-8') ?>
								<?php if ($meta['configured']) : ?>
								<span class="text-muted fs-8">(등록됨 · <?= (int) $meta['length'] ?>자)</span>
								<?php endif; ?>
							</label>
							<input type="password" class="form-control form-control-solid" id="excel_pw_<?= htmlspecialchars($pf, ENT_QUOTES, 'UTF-8') ?>"
								value="<?= htmlspecialchars($excelPasswords[$pf] ?? '', ENT_QUOTES, 'UTF-8') ?>"
								autocomplete="new-password" placeholder="암호 없으면 비움" <?= $canWrite ? '' : 'readonly' ?> />
						</div>
						<?php endforeach; ?>
						<div class="col-12">
							<?php if ($canWrite) : ?>
							<button type="button" class="btn btn-primary" id="excel_pw_save_btn">저장</button>
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
			<div class="card card-flush h-100">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">서버 요구 사항</h3></div>
				<div class="card-body pt-0 fs-7 text-gray-700">
					<ul class="mb-4 ps-4">
						<li>PHP <code>zip</code> 확장 (xlsx 파싱)</li>
						<li>Python 3 + <code>msoffcrypto-tool</code> (암호 해제)</li>
					</ul>
					<p class="mb-2">설치 예:</p>
					<pre class="bg-light rounded p-3 fs-8 mb-4">sudo dnf install -y php-zip
sudo -u apache python3 -m pip install --user msoffcrypto-tool</pre>
					<a class="fw-semibold" href="<?= htmlspecialchars($checkUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Python·zip 환경 진단</a>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php if ($excelConfigReady && $canWrite) : ?>
<script>
(function () {
	'use strict';
	var API = <?= json_encode($excelConfigApi, JSON_UNESCAPED_UNICODE) ?>;
	var toast = document.getElementById('excel_pw_toast');
	var toastMsg = document.getElementById('excel_pw_toast_msg');
	var btn = document.getElementById('excel_pw_save_btn');
	if (!btn) return;

	function showToast(msg, ok) {
		toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger');
		toastMsg.textContent = msg;
		toast.classList.remove('d-none');
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		var passwords = {
			baemin: document.getElementById('excel_pw_baemin')?.value || '',
			coupang: document.getElementById('excel_pw_coupang')?.value || '',
			other: document.getElementById('excel_pw_other')?.value || '',
		};
		fetch(API, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ action: 'save', passwords: passwords }),
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
})();
</script>
<?php endif; ?>

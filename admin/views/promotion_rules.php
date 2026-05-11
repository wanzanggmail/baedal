<?php

declare(strict_types=1);

$mockRules = [
    ['name' => '2026-05 주간 누적 건수 인센티브', 'updated' => '2026-05-08 14:00', 'note' => '배치 실행 화면과 동일한 구간 로직'],
    ['name' => '신규 라이더 첫 달 프로모션', 'updated' => '2026-04-01 09:30', 'note' => '별도 마스터 연동 예정'],
];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 규칙</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-muted">프로모션</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">규칙</li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<a href="<?= htmlspecialchars(admin_url('promotion/batch'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary fw-bold">배치 실행</a>
			<a href="<?= htmlspecialchars(admin_url('promotion/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">실행 이력</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div class="alert alert-info d-flex align-items-center p-5 mb-8">
		<i class="ki-duotone ki-information-5 fs-2x text-info me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
		<div class="fs-7 text-gray-800">
			규칙 이름·설명은 목업입니다. 실제로는 <strong>기간 + 건수 구간별 금액</strong>을 <a href="<?= htmlspecialchars(admin_url('promotion/batch'), ENT_QUOTES, 'UTF-8') ?>" class="fw-bold">배치 실행</a> 화면에서 입력한 뒤 저장·재사용하는 흐름을 가정할 수 있습니다.
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center py-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">등록된 규칙 (샘플)</h3>
				<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">편집·저장 버튼은 연동 후 활성화</span>
			</div>
			<div class="card-toolbar">
				<button type="button" class="btn btn-sm btn-light-primary" disabled>새 규칙</button>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-200px">규칙명</th>
							<th class="min-w-160px">최종 수정</th>
							<th class="min-w-280px">비고</th>
							<th class="min-w-120px text-end">작업</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($mockRules as $r) : ?>
						<tr>
							<td class="fw-semibold text-gray-800"><?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-700"><?= htmlspecialchars($r['updated'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-gray-600 fs-7"><?= htmlspecialchars($r['note'], ENT_QUOTES, 'UTF-8') ?></td>
							<td class="text-end">
								<button type="button" class="btn btn-sm btn-light" disabled>수정</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

<?php

declare(strict_types=1);

/**
 * 수수료 현황 — 전 대리점의 수수료 설정을 한 화면에서 비교(본사 전용, 조회 전용).
 *   ① 정산수수료: 출금 시 주문 건별로 붙는 단가(경과일 기준) + 보증금
 *   ② 플랫폼 수수료: PG 결제 시 본사/총판/대리점이 나눠 갖는 비율
 *
 * ⚠️ 편집은 여기서 하지 않는다. 예전에는 이 화면과 「출금 정책 설정」이 **같은 값**
 *    (withdrawal_config 의 보증금·경과일 기준·건당 단가)을 각자 편집해, 한쪽에서 바꾸면
 *    다른 쪽도 조용히 바뀌는 상태였다. 편집은 「출금 정책 설정」 한 곳으로 모으고
 *    이 화면은 대리점 간 비교용으로 남겼다(2026-08-23).
 */

require_once INC_PATH . '/PgFeeConfig.php';

$needsMigrate = !PgFeeConfig::tableExists();
$rows = $needsMigrate ? [] : PgFeeConfig::listAgencyConfigs();

$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$settingsUrl = admin_url('withdrawal/settings');
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">수수료 현황</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">설정</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">수수료 현황</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<a href="<?= $esc($settingsUrl) ?>" class="btn btn-sm btn-primary fw-bold">수수료 편집</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div class="alert bg-light-primary d-flex flex-column flex-sm-row p-5 mb-8">
		<i class="ki-duotone ki-percentage fs-2hx text-primary me-4 mb-3 mb-sm-0"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-7 text-gray-800">
			대리점별 수수료 설정을 <strong>한눈에 비교</strong>하는 화면입니다. <strong>값을 바꾸려면 「수수료 설정」</strong>에서 대리점을 고르세요.
			<span class="d-block mt-1">
				· <strong>정산수수료</strong> — 라이더 출금 시 주문 <strong>건별</strong>로 붙는 금액(정산일로부터 경과일 기준으로 단가가 갈립니다) + 출금 후 지갑에 남기는 보증금<br />
				· <strong>플랫폼 수수료</strong> — PG 결제 시 붙는 비율을 <strong>본사·총판·대리점</strong>이 나눠 갖습니다(대리점별로 각각 다르게 지정 가능)
			</span>
			<span class="badge badge-light-warning mt-2">플랫폼 수수료 기본 각 1% (임시값 — 갑 확정 대기)</span>
		</div>
	</div>

	<?php if ($rows === []) : ?>
	<div class="card card-flush"><div class="card-body text-center text-muted py-15">등록된 대리점이 없습니다.</div></div>
	<?php else : ?>

	<div class="card card-flush">
		<div class="card-header pt-5 align-items-center gap-3 flex-wrap">
			<h3 class="card-title fw-bold m-0">대리점별 수수료 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format(count($rows)) ?>곳</span></h3>
			<div class="card-toolbar">
				<div class="d-flex align-items-center position-relative">
					<i class="ki-duotone ki-magnifier fs-3 position-absolute ms-4"><span class="path1"></span><span class="path2"></span></i>
					<input type="search" id="pgfee_search" class="form-control form-control-solid ps-12 w-250px" placeholder="대리점·코드·총판 검색" />
				</div>
			</div>
		</div>
		<div class="card-body pt-2">
			<div class="table-responsive">
				<table class="table table-row-bordered table-row-gray-300 align-middle gy-3 fs-7" id="pgFeeTable">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">대리점</th>
							<th class="text-center min-w-80px">경과일 기준</th>
							<th class="text-end min-w-90px">기준 이내 건당</th>
							<th class="text-end min-w-90px">기준 경과 건당</th>
							<th class="text-end min-w-90px">보증금</th>
							<th class="text-end min-w-70px">PG 본사</th>
							<th class="text-end min-w-70px">PG 총판</th>
							<th class="text-end min-w-70px">PG 대리점</th>
							<th class="text-end min-w-70px">PG 합계</th>
							<th class="text-end min-w-70px">편집</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) : ?>
						<tr>
							<td>
								<span class="fw-bold text-gray-900"><?= $esc($r['name']) ?></span>
								<div class="text-muted fs-8"><?= $esc($r['code']) ?> · 상위 <?= $esc($r['parent_name']) ?></div>
							</td>
							<td class="text-center"><?= (int) $r['fee_day_threshold'] ?>일</td>
							<td class="text-end"><?= number_format((int) $r['fee_per_tx_short']) ?>원</td>
							<td class="text-end"><?= number_format((int) $r['fee_per_tx_long']) ?>원</td>
							<td class="text-end"><?= number_format((int) $r['reserve_amount']) ?>원</td>
							<td class="text-end"><?= number_format((float) $r['hq_pct'], 2) ?>%</td>
							<td class="text-end"><?= number_format((float) $r['distributor_pct'], 2) ?>%</td>
							<td class="text-end"><?= number_format((float) $r['agency_pct'], 2) ?>%</td>
							<td class="text-end fw-bold"><?= number_format((float) $r['total_pct'], 2) ?>%</td>
							<td class="text-end">
								<a class="btn btn-sm btn-light" href="<?= $esc($settingsUrl . (str_contains($settingsUrl, '?') ? '&' : '?') . 'agency=' . (int) $r['id']) ?>">편집</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<?php endif; ?>
	<?php endif; ?>

<script src="<?= htmlspecialchars(web_asset('js/table-paginate.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
	// 목록이 길어지면 스크롤만 길어져 찾기 어렵다. 서버가 내려준 결과를 그대로 두고
	// 화면에서만 페이지로 나눈다(DataTables 없이 같은 UX — assets/js/table-paginate.js).
	var tp_pgFeeTable = document.getElementById('pgFeeTable');
	if (tp_pgFeeTable) { initTablePaginate(tp_pgFeeTable, { pageSize: 20, unit: '곳', searchInput: '#pgfee_search' }); }
</script>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

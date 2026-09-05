<?php

declare(strict_types=1);

/**
 * 세무신고용 자료 — 대리점별·기간별 (2026-09-05 갑).
 *
 * 기간을 고르면 그 기간에 원천세가 발생한 대리점이 나오고, 대리점마다 엑셀을 받는다.
 * 파일 레이아웃은 갑이 쓰던 「세무신고용_YYYY-MM-DD_YYYY-MM-DD.xlsx」 그대로다.
 */

require_once INC_PATH . '/TaxReport.php';

$won = static fn (int $v): string => number_format($v) . '원';

// 기본 기간 — 지난주(월~일). 세무신고가 주 단위로 도는 흐름에 맞춘다.
$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$okDate = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$okDate($from) || !$okDate($to) || $from > $to) {
    $from = date('Y-m-d', strtotime('monday last week'));
    $to   = date('Y-m-d', strtotime('sunday last week'));
}

$agencies = [];
$loadErr  = null;
try {
    $agencies = TaxReport::agencies($from, $to);
} catch (Throwable $e) {
    $loadErr = $e->getMessage();
}

$feePerCall = TaxReport::feePerCall();
$exportApi  = ADMIN_BASE . '/api/tax_report_export.php';

$tot = ['riders' => 0, 'calls' => 0, 'base' => 0, 'promo' => 0, 'total_base' => 0, 'total_wh' => 0];
foreach ($agencies as $a) {
    $tot['riders']     += $a['riders'];
    $tot['calls']      += $a['calls'];
    $tot['base']       += $a['base'];
    $tot['promo']      += $a['promo'];
    $tot['total_base'] += $a['total_base'];
    $tot['total_wh']   += $a['total_wh'];
}
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">세무신고용 자료</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">대리점별 신고 자료</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->

<div id="kt_app_content" class="app-content flex-column-fluid">
	<div id="kt_app_content_container" class="app-container container-xxl">

		<?php if ($loadErr !== null) : ?>
		<div class="alert alert-danger"><?= htmlspecialchars($loadErr, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>

		<!--begin::기간-->
		<div class="card mb-6">
			<div class="card-body py-5">
				<form method="get" class="row g-3 align-items-end">
					<input type="hidden" name="r" value="tax/report" />
					<div class="col-sm-3">
						<label class="form-label fw-semibold fs-7" for="f_from">시작일</label>
						<input type="date" class="form-control form-control-solid" id="f_from" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
					<div class="col-sm-3">
						<label class="form-label fw-semibold fs-7" for="f_to">종료일</label>
						<input type="date" class="form-control form-control-solid" id="f_to" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>" />
					</div>
					<div class="col-sm-2">
						<button type="submit" class="btn btn-primary w-100">조회</button>
					</div>
					<div class="col-sm-4 text-sm-end">
						<span class="badge badge-light-primary fs-7">세무비용 단가 <?= number_format($feePerCall) ?>원/콜</span>
					</div>
				</form>
			</div>
		</div>
		<!--end::기간-->

		<!--begin::요약-->
		<div class="row g-5 mb-6">
			<?php
			$cards = [
				['대상 대리점', number_format(count($agencies)) . '곳', 'ki-shop', 'primary'],
				['신고 라이더', number_format($tot['riders']) . '명', 'ki-people', 'info'],
				['합산 기준금액', $won($tot['total_base']), 'ki-dollar', 'success'],
				['총 징수원천세', $won($tot['total_wh']), 'ki-shield-tick', 'warning'],
			];
			foreach ($cards as [$label, $value, $icon, $color]) : ?>
			<div class="col-sm-6 col-xl-3">
				<div class="card card-flush h-100">
					<div class="card-body d-flex flex-column justify-content-between py-6">
						<i class="ki-duotone <?= $icon ?> fs-2hx text-<?= $color ?>"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
						<div class="d-flex flex-column mt-6">
							<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></span>
							<span class="fw-semibold fs-7 text-gray-500 mt-1"><?= $label ?></span>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<!--end::요약-->

		<!--begin::대리점 목록-->
		<div class="card">
			<div class="card-header pt-6">
				<h3 class="card-title fw-bold text-gray-900">
					대리점별 신고 자료
					<span class="text-muted fs-7 fw-semibold ms-2"><?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?> ~ <?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?></span>
				</h3>
			</div>
			<div class="card-body pt-2">
				<?php if ($agencies === []) : ?>
				<div class="text-center text-muted py-10">이 기간에 원천세가 발생한 대리점이 없습니다.</div>
				<?php else : ?>
				<div class="table-responsive">
					<table class="table table-row-bordered align-middle gy-3 fs-7">
						<thead>
							<tr class="fw-bold text-muted bg-light">
								<th class="ps-3">대리점</th>
								<th class="text-end">신고 라이더</th>
								<th class="text-end">총 콜수</th>
								<th class="text-end">세무비용</th>
								<th class="text-end">기사정산원금</th>
								<th class="text-end">프로모션</th>
								<th class="text-end">합산 기준금액</th>
								<th class="text-end">총 징수원천세</th>
								<th class="text-end pe-3">신고 파일</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($agencies as $a) :
								$q = http_build_query(['agency_id' => $a['agency_id'], 'from' => $from, 'to' => $to]); ?>
							<tr>
								<td class="ps-3">
									<span class="fw-bold text-gray-900"><?= htmlspecialchars($a['agency_name'], ENT_QUOTES, 'UTF-8') ?></span>
									<span class="text-muted fs-8 d-block"><?= htmlspecialchars($a['code'], ENT_QUOTES, 'UTF-8') ?></span>
								</td>
								<td class="text-end"><?= number_format($a['riders']) ?>명</td>
								<td class="text-end"><?= number_format($a['calls']) ?></td>
								<td class="text-end text-muted"><?= number_format($a['calls'] * $feePerCall) ?></td>
								<td class="text-end"><?= number_format($a['base']) ?></td>
								<td class="text-end"><?= $a['promo'] > 0 ? number_format($a['promo']) : '<span class="text-muted">-</span>' ?></td>
								<td class="text-end fw-bold"><?= number_format($a['total_base']) ?></td>
								<td class="text-end fw-bold text-danger"><?= number_format($a['total_wh']) ?></td>
								<td class="text-end pe-3">
									<a class="btn btn-sm btn-light-success" href="<?= htmlspecialchars($exportApi . '?' . $q, ENT_QUOTES, 'UTF-8') ?>">
										<i class="ki-duotone ki-file-down fs-4"><span class="path1"></span><span class="path2"></span></i>다운로드
									</a>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr class="fw-bold border-top">
								<td class="ps-3">합계</td>
								<td class="text-end"><?= number_format($tot['riders']) ?>명</td>
								<td class="text-end"><?= number_format($tot['calls']) ?></td>
								<td class="text-end"><?= number_format($tot['calls'] * $feePerCall) ?></td>
								<td class="text-end"><?= number_format($tot['base']) ?></td>
								<td class="text-end"><?= number_format($tot['promo']) ?></td>
								<td class="text-end"><?= number_format($tot['total_base']) ?></td>
								<td class="text-end text-danger"><?= number_format($tot['total_wh']) ?></td>
								<td></td>
							</tr>
						</tfoot>
					</table>
				</div>
				<?php endif; ?>

				<div class="notice d-flex bg-light-warning rounded border-warning border border-dashed p-4 mt-6">
					<i class="ki-duotone ki-information fs-2tx text-warning me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
					<div class="fs-8 text-gray-700">
						<div class="mb-1"><span class="fw-bold">주민번호 칸은 비어서 나갑니다.</span> 시스템에 주민등록번호를 보관하지 않기 때문입니다.</div>
						<div class="mb-1">「세금신고유무」·「금액조정필요」는 <span class="fw-semibold">라이더 상세</span>에서 설정한 값이 그대로 들어갑니다.</div>
						<div>「조정금액」·「비고」는 신고하시면서 직접 채우는 칸이라 비워 둡니다. PG 결제금액 열은 뺐습니다.</div>
					</div>
				</div>
			</div>
		</div>
		<!--end::대리점 목록-->
	</div>
</div>

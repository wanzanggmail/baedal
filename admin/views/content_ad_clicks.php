<?php

declare(strict_types=1);

require_once INC_PATH . '/Banner.php';
require_once INC_PATH . '/BannerClick.php';

// 기본 기간은 최근 30일 — 광고 정산이 보통 월 단위라 한 달치를 먼저 보여준다.
$today   = date('Y-m-d');
$dateOr  = static fn (mixed $v, string $fallback): string
    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : $fallback;
$from    = $dateOr($_GET['from'] ?? '', date('Y-m-d', strtotime('-29 day')));
$to      = $dateOr($_GET['to'] ?? '', $today);
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$bannerId = max(0, (int) ($_GET['banner_id'] ?? 0));
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 50;

$filter = ['from' => $from, 'to' => $to, 'banner_id' => $bannerId];

$ready    = BannerClick::tableExists();
$summary  = $ready ? BannerClick::summary($filter) : [];
$daily    = $ready ? BannerClick::daily($filter) : [];
$total    = $ready ? BannerClick::count($filter) : 0;
$lastPage = max(1, (int) ceil($total / $perPage));
$page     = min($page, $lastPage);
$rows     = $ready ? BannerClick::recent($filter, $perPage, ($page - 1) * $perPage) : [];

$banners = [];
try {
    foreach (Banner::listAdmin() as $b) {
        $banners[(int) $b['id']] = (string) $b['title'];
    }
} catch (Throwable) {
    $banners = [];
}

$totalClicks = 0;
$maxRiders   = 0;
$maxDaily    = 0;
foreach ($summary as $s) {
    $totalClicks += (int) $s['clicks'];
    $maxRiders    = max($maxRiders, (int) $s['riders']);
}
foreach ($daily as $d) {
    $maxDaily = max($maxDaily, (int) $d['clicks']);
}

$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$qs  = static fn (array $over): string => '?' . http_build_query(array_merge(
    ['from' => $from, 'to' => $to, 'banner_id' => $bannerId],
    $over
));
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">광고 클릭 로그</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">콘텐츠</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">광고 클릭 로그</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<a href="<?= $esc(admin_url('content/banners')) ?>" class="btn btn-sm btn-light fw-bold">광고 배너</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if (!$ready) : ?>
	<div class="alert alert-warning mb-8">
		<strong>DB 테이블이 없습니다.</strong> 배포 화면에서 <strong>DB 마이그레이션</strong>을 실행한 뒤 새로고침하세요.
	</div>
	<?php else : ?>

	<div class="card card-flush mb-6">
		<div class="card-body py-4">
			<form method="get" class="row g-3 align-items-end">
				<div class="col-6 col-md-3">
					<label class="form-label fs-8 text-muted mb-1">시작일</label>
					<input type="date" name="from" value="<?= $esc($from) ?>" class="form-control form-control-sm form-control-solid" />
				</div>
				<div class="col-6 col-md-3">
					<label class="form-label fs-8 text-muted mb-1">종료일</label>
					<input type="date" name="to" value="<?= $esc($to) ?>" class="form-control form-control-sm form-control-solid" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label fs-8 text-muted mb-1">광고</label>
					<select name="banner_id" class="form-select form-select-sm form-select-solid">
						<option value="0">전체 광고</option>
						<?php foreach ($banners as $bid => $btitle) : ?>
						<option value="<?= (int) $bid ?>"<?= $bannerId === (int) $bid ? ' selected' : '' ?>><?= $esc($btitle) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="col-12 col-md-2">
					<button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">조회</button>
				</div>
			</form>
		</div>
	</div>

	<div class="row g-4 mb-6">
		<div class="col-6 col-md-3">
			<div class="card card-flush h-100">
				<div class="card-body py-4">
					<div class="text-gray-500 fs-8 fw-semibold">총 클릭</div>
					<div class="fs-2 fw-bold text-gray-900"><?= number_format($totalClicks) ?></div>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			<div class="card card-flush h-100">
				<div class="card-body py-4">
					<div class="text-gray-500 fs-8 fw-semibold">클릭된 광고</div>
					<div class="fs-2 fw-bold text-gray-900"><?= number_format(count($summary)) ?></div>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			<div class="card card-flush h-100">
				<div class="card-body py-4">
					<div class="text-gray-500 fs-8 fw-semibold">고유 라이더(최다 광고)</div>
					<div class="fs-2 fw-bold text-gray-900"><?= number_format($maxRiders) ?></div>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			<div class="card card-flush h-100">
				<div class="card-body py-4">
					<div class="text-gray-500 fs-8 fw-semibold">조회 기간</div>
					<div class="fs-6 fw-bold text-gray-900"><?= $esc($from) ?></div>
					<div class="text-muted fs-8">~ <?= $esc($to) ?></div>
				</div>
			</div>
		</div>
	</div>

	<div class="card card-flush mb-6">
		<div class="card-header align-items-center py-4">
			<div class="card-title">
				<h3 class="fw-bold m-0">광고별 집계</h3>
				<span class="text-gray-500 fs-8 fw-semibold d-block mt-1">광고 정산 청구의 바탕이 되는 숫자입니다</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<?php if ($summary === []) : ?>
			<div class="text-center text-muted py-10">이 기간에 클릭이 없습니다.</div>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table align-middle table-row-dashed fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-200px">광고</th>
							<th class="min-w-90px text-end">클릭</th>
							<th class="min-w-110px text-end">고유 라이더</th>
							<th class="min-w-90px text-end">클릭된 일수</th>
							<th class="min-w-150px">마지막 클릭</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($summary as $s) : ?>
						<tr>
							<td class="fw-bold text-gray-900">
								<?= $esc((string) $s['title']) ?>
								<?php if ((int) $s['deleted'] === 1) : ?>
								<span class="badge badge-light-danger fs-8 ms-2">삭제된 광고</span>
								<?php endif; ?>
							</td>
							<td class="text-end fw-bold text-primary"><?= number_format((int) $s['clicks']) ?></td>
							<td class="text-end"><?= number_format((int) $s['riders']) ?></td>
							<td class="text-end"><?= number_format((int) $s['days']) ?></td>
							<td class="text-gray-700"><?= $esc((string) $s['last_click']) ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ($daily !== []) : ?>
	<div class="card card-flush mb-6">
		<div class="card-header align-items-center py-4">
			<div class="card-title"><h3 class="fw-bold m-0">일자별 클릭</h3></div>
		</div>
		<div class="card-body pt-0">
			<?php foreach ($daily as $d) : ?>
			<div class="d-flex align-items-center gap-3 mb-2">
				<span class="text-gray-700 fs-8 flex-shrink-0" style="width:86px"><?= $esc((string) $d['click_date']) ?></span>
				<div class="flex-grow-1 bg-light rounded" style="height:10px">
					<div class="bg-primary rounded" style="height:10px;width:<?= $maxDaily > 0 ? round((int) $d['clicks'] / $maxDaily * 100) : 0 ?>%"></div>
				</div>
				<span class="fw-bold fs-8 text-gray-800 flex-shrink-0 text-end" style="width:46px"><?= number_format((int) $d['clicks']) ?></span>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="card card-flush">
		<div class="card-header align-items-center py-4">
			<div class="card-title">
				<h3 class="fw-bold m-0">클릭 상세 <span class="text-gray-500 fs-7 fw-semibold ms-2"><?= number_format($total) ?>건</span></h3>
			</div>
		</div>
		<div class="card-body pt-0">
			<?php if ($rows === []) : ?>
			<div class="text-center text-muted py-10">이 기간에 클릭이 없습니다.</div>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table align-middle table-row-dashed fs-7 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-150px">클릭 시각</th>
							<th class="min-w-180px">광고</th>
							<th class="min-w-140px">라이더</th>
							<th class="min-w-140px">대리점</th>
							<th class="min-w-120px">IP</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($rows as $r) : ?>
						<tr>
							<td class="text-gray-800 fw-semibold"><?= $esc((string) $r['clicked_at']) ?></td>
							<td class="text-gray-900"><?= $esc((string) $r['title']) ?></td>
							<td class="text-gray-700">
								<?php if ((string) ($r['rider_name'] ?? '') !== '') : ?>
								<?= $esc((string) $r['rider_name']) ?>
								<span class="text-muted fs-8 d-block"><?= $esc((string) ($r['rider_code'] ?? '')) ?></span>
								<?php else : ?>
								<span class="text-muted">비로그인</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-700"><?= $esc((string) ($r['agency_name'] ?? '')) !== '' ? $esc((string) $r['agency_name']) : '—' ?></td>
							<td class="text-muted fs-8"><?= $esc((string) $r['ip']) ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php if ($lastPage > 1) : ?>
			<div class="d-flex justify-content-between align-items-center pt-3">
				<a href="<?= $esc($qs(['page' => max(1, $page - 1)])) ?>" class="btn btn-sm btn-light<?= $page <= 1 ? ' disabled' : '' ?>">이전</a>
				<span class="text-muted fs-8"><?= $page ?> / <?= $lastPage ?></span>
				<a href="<?= $esc($qs(['page' => min($lastPage, $page + 1)])) ?>" class="btn btn-sm btn-light<?= $page >= $lastPage ? ' disabled' : '' ?>">다음</a>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

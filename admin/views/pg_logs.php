<?php

declare(strict_types=1);

/**
 * PG 결제 이력 — 위루트 API 호출 요청/응답 기록. 본사 super 전용.
 *
 * 「PG 연동·결제통지」에서 떼어냈다. 설정 화면에 붙여 두니 이력이 쌓일수록 설정이 아래로
 * 밀려 둘 다 쓰기 불편했다. 이력은 자주 들여다보는 화면이라 메뉴로 세운다.
 *
 * 🔒 카드번호는 뒤 4자리만, 비밀번호·인증번호·키는 길이만 저장된다(`PgApiLog::mask()`).
 */

require_once INC_PATH . '/PgApiLog.php';

$isSuper = admin_has_role('super') && admin_org_level() === Org::LEVEL_ADMIN;
if (!$isSuper) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">PG 결제 이력은 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$needsMigrate = !PgApiLog::tableExists();
$apiOnly = trim((string) ($_GET['api'] ?? ''));
$apiOrd  = trim((string) ($_GET['ord'] ?? ''));

$apiLogs  = $needsMigrate ? [] : PgApiLog::recent(['only' => $apiOnly, 'ord_num' => $apiOrd], 300);
$apiStats = $needsMigrate ? ['total' => 0, 'fail' => 0, 'avg_ms' => 0] : PgApiLog::stats();

$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 필터 링크를 만들 때 기존 조건(주문번호 검색)을 잃지 않게 한다.
$linkFor = static function (string $only) use ($apiOrd): string {
    $u = admin_url('system/pg-logs');
    $q = ['api' => $only];
    if ($apiOrd !== '') {
        $q['ord'] = $apiOrd;
    }

    return $u . (str_contains($u, '?') ? '&' : '?') . http_build_query($q);
};
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">PG 결제 이력</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">PG 결제 이력</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2">
			<a href="<?= $esc(admin_url('system/pg-integration')) ?>" class="btn btn-sm btn-light fw-bold">PG 연동 설정</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

<?php if ($needsMigrate) : ?>
<div class="alert alert-warning p-5"><code>pg_api_logs</code> 테이블이 없습니다. 서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
<?php else : ?>

<div class="row g-5 g-xl-8 mb-2">
	<div class="col-md-4">
		<div class="card card-flush h-100"><div class="card-body py-5">
			<div class="text-gray-500 fw-semibold fs-7 mb-1">누적 호출</div>
			<div class="fw-bold fs-3 text-gray-900"><?= number_format($apiStats['total']) ?>건</div>
		</div></div>
	</div>
	<div class="col-md-4">
		<div class="card card-flush h-100"><div class="card-body py-5">
			<div class="text-gray-500 fw-semibold fs-7 mb-1">실패</div>
			<div class="fw-bold fs-3 text-danger"><?= number_format($apiStats['fail']) ?>건</div>
		</div></div>
	</div>
	<div class="col-md-4">
		<div class="card card-flush h-100"><div class="card-body py-5">
			<div class="text-gray-500 fw-semibold fs-7 mb-1">평균 응답</div>
			<div class="fw-bold fs-3 text-gray-900"><?= number_format($apiStats['avg_ms']) ?>ms</div>
		</div></div>
	</div>
</div>

<div class="card card-flush mt-6">
	<div class="card-header pt-5 flex-wrap gap-3">
		<div class="card-title">
			<h3 class="fw-bold m-0">호출 목록</h3>
			<span class="text-gray-500 fs-8 fw-semibold d-block mt-1">
				🔒 카드번호는 뒤 4자리만, 비밀번호·인증번호·키는 길이만 남기고 저장합니다.
			</span>
		</div>
		<div class="card-toolbar gap-2 flex-wrap">
			<form method="get" action="<?= $esc(admin_url('system/pg-logs')) ?>" class="d-flex gap-2">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
					<input type="hidden" name="route" value="system/pg-logs" />
				<?php endif; ?>
				<input type="hidden" name="api" value="<?= $esc($apiOnly) ?>" />
				<input type="text" name="ord" value="<?= $esc($apiOrd) ?>" class="form-control form-control-sm form-control-solid w-180px" placeholder="주문번호 검색" />
				<button type="submit" class="btn btn-sm btn-light-primary">검색</button>
			</form>
			<?php foreach (['' => '전체', 'fail' => '실패만', 'ok' => '성공만'] as $k => $label) : ?>
			<a href="<?= $esc($linkFor($k)) ?>" class="btn btn-sm <?= $apiOnly === $k ? 'btn-primary' : 'btn-light' ?>"><?= $esc($label) ?></a>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="card-body pt-0">
		<?php if ($apiLogs === []) : ?>
		<div class="text-center text-gray-500 py-10">
			<?= ($apiOnly !== '' || $apiOrd !== '') ? '조건에 맞는 호출이 없습니다.' : '아직 PG 호출 이력이 없습니다.' ?>
			<span class="d-block fs-8 mt-2">「PG 연동 설정」에서 <strong>연결 테스트</strong>를 누르거나 카드 등록·결제가 일어나면 여기에 쌓입니다.</span>
		</div>
		<?php else : ?>
		<div class="table-responsive">
			<table class="table table-row-bordered align-middle fs-8 gy-3">
				<thead>
					<tr class="fw-bold text-muted">
						<th class="min-w-130px">일시</th>
						<th class="min-w-70px">결과</th>
						<th class="min-w-160px">엔드포인트</th>
						<th class="min-w-130px">주문번호</th>
						<th class="min-w-60px text-end">소요</th>
						<th class="min-w-220px">응답</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($apiLogs as $l) : ?>
					<tr>
						<td class="text-muted text-nowrap"><?= $esc((string) $l['created_at']) ?></td>
						<td>
							<?php if ((int) $l['ok'] === 1) : ?>
							<span class="badge badge-light-success">성공</span>
							<?php else : ?>
							<span class="badge badge-light-danger"><?= (int) $l['http_code'] ?: '오류' ?></span>
							<?php endif; ?>
						</td>
						<td class="font-monospace text-gray-800"><?= $esc((string) $l['method']) ?> <?= $esc((string) $l['endpoint']) ?></td>
						<td class="font-monospace text-gray-700"><?= $esc((string) ($l['ord_num'] ?: '—')) ?></td>
						<td class="text-end text-muted"><?= number_format((int) $l['duration_ms']) ?>ms</td>
						<td class="text-gray-700">
							<?php if ((string) $l['result_cd'] !== '') : ?><span class="badge badge-light-secondary me-1"><?= $esc((string) $l['result_cd']) ?></span><?php endif; ?>
							<?= $esc((string) $l['result_msg']) ?>
							<details class="mt-1">
								<summary class="text-muted fs-9" style="cursor:pointer">요청/응답 원문</summary>
								<div class="bg-light rounded p-2 mt-1 font-monospace text-break" style="white-space:pre-wrap">요청 <?= $esc((string) $l['request_body']) ?>

응답 <?= $esc((string) $l['response_body']) ?></div>
							</details>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
	</div>
</div>

<?php endif; ?>
<?php require_once INC_PATH . '/app_content_close.php'; ?>

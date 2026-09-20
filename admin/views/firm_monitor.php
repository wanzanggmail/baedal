<?php

declare(strict_types=1);

/**
 * 펌뱅킹 이체 모니터 — 「이체 현황」과 「처리결과 통보」를 탭으로 나눠 본다(2026-09-20 갑).
 *
 * 연동 설정(자격증명·통보 URL 등록)은 `firm_integration.php` 에 그대로 두고, **매일 들여다보는
 * 운영 화면**만 여기로 떼어냈다. 설정은 한 번 하고 마는 것이고 이 둘은 계속 보게 된다.
 *
 * 두 표는 같은 사건의 양면이다 — 우리가 **보낸 것**(이체 현황)과 바움이 **알려 온 것**(통보).
 * 접수중인데 통보가 없다면 통보 URL 등록이나 발신 IP 를 의심해야 한다.
 */

require_once INC_PATH . '/FirmConfig.php';
require_once INC_PATH . '/FirmTransfer.php';
require_once INC_PATH . '/FirmWebhook.php';

$isSuper = admin_has_role('super') && admin_org_level() === Org::LEVEL_ADMIN;
if (!$isSuper) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">펌뱅킹 이체 내역은 본사 최고관리자만 볼 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$needsMigrate = !FirmTransfer::tableExists();

$only = (string) ($_GET['only'] ?? '');
if (!in_array($only, ['', 'pending', 'failed', 'success'], true)) {
    $only = '';
}
$q   = trim((string) ($_GET['q'] ?? ''));
$tab = (string) ($_GET['tab'] ?? 'transfers');
if (!in_array($tab, ['transfers', 'noti'], true)) {
    $tab = 'transfers';
}

$transfers    = $needsMigrate ? [] : FirmTransfer::recent(['only' => $only, 'q' => $q], 200);
$events       = $needsMigrate ? [] : FirmWebhook::recent(200);
$pendingCount = $needsMigrate ? 0 : FirmTransfer::pendingCount();
$orphans      = $needsMigrate ? [] : FirmTransfer::orphanTransferring(10);

$esc     = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$selfUrl = admin_url('withdrawal/firm-monitor');
$qs      = static fn (array $over): string => $selfUrl
    . (str_contains($selfUrl, '?') ? '&' : '?')
    . http_build_query(array_merge(['tab' => $tab, 'only' => $only, 'q' => $q], $over));
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">펌뱅킹 이체 내역</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= $esc(admin_url('dashboard')) ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-muted">지급·출금</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">펌뱅킹 이체 내역</li>
			</ul>
		</div>
		<div class="d-flex gap-2 flex-wrap align-items-center">
			<?php if ($pendingCount > 0) : ?>
			<span class="badge badge-light-warning fs-8 text-nowrap">미확정 <?= number_format($pendingCount) ?>건</span>
			<?php endif; ?>
			<button type="button" class="btn btn-sm btn-light-primary fw-bold" id="firm_reconcile">보정 조회</button>
			<a href="<?= $esc(admin_url('system/firm-integration')) ?>" class="btn btn-sm btn-light fw-bold">연동 설정</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">
		<strong>DB 테이블이 없습니다.</strong> 배포 화면에서 <strong>DB 마이그레이션</strong>을 실행한 뒤 새로고침하세요.
	</div>
	<?php else : ?>

	<?php if ($orphans !== []) : ?>
	<div class="alert alert-danger d-flex flex-column mb-6">
		<div class="fw-bold mb-1">접수 기록이 없는 「접수중」 출금 <?= count($orphans) ?>건</div>
		<div class="fs-7">
			접수 직후 처리가 끊긴 흔적입니다 — <strong>실제로 이체가 나갔는지 바움 관리자에서 확인</strong>한 뒤 처리하세요.
			해당 출금 번호: <?= $esc(implode(', #', array_map(static fn (array $r): string => '#' . (int) $r['id'], $orphans))) ?>
		</div>
	</div>
	<?php endif; ?>

	<div id="firm_toast" class="alert alert-dismissible d-none mb-6" role="alert">
		<span id="firm_toast_msg"></span>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="닫기"></button>
	</div>

	<!--begin::탭-->
	<ul class="nav nav-tabs nav-line-tabs fs-6 fw-semibold mb-6">
		<li class="nav-item">
			<a class="nav-link<?= $tab === 'transfers' ? ' active' : '' ?>" href="<?= $esc($qs(['tab' => 'transfers'])) ?>">
				이체 현황
				<span class="badge badge-light-secondary ms-2"><?= number_format(count($transfers)) ?></span>
			</a>
		</li>
		<li class="nav-item">
			<a class="nav-link<?= $tab === 'noti' ? ' active' : '' ?>" href="<?= $esc($qs(['tab' => 'noti'])) ?>">
				처리결과 통보 수신
				<span class="badge badge-light-secondary ms-2"><?= number_format(count($events)) ?></span>
			</a>
		</li>
	</ul>
	<!--end::탭-->

	<?php if ($tab === 'transfers') : ?>
	<!--begin::이체 현황-->
	<div class="card card-flush shadow-sm">
		<div class="card-header pt-5 flex-wrap gap-3">
			<div class="card-title">
				<h3 class="fw-bold m-0">이체 현황</h3>
				<span class="text-gray-500 fs-8 d-block mt-1">
					우리가 바움에 <strong>보낸</strong> 이체입니다. 접수만 되고 결과가 안 온 건은
					<strong>보정 조회</strong>로 직접 확인하세요 — 통보는 1분 간격 최대 10회 재전송 후 그칩니다.
				</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<form method="get" action="<?= $esc($selfUrl) ?>" class="row g-3 align-items-end mb-5">
				<?php if (defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL) : ?>
				<input type="hidden" name="route" value="withdrawal/firm-monitor" />
				<?php endif; ?>
				<input type="hidden" name="tab" value="transfers" />
				<div class="col-6 col-md-3">
					<label class="form-label fs-8 text-muted mb-1">상태</label>
					<select name="only" class="form-select form-select-sm form-select-solid">
						<option value="">전체</option>
						<option value="pending"<?= $only === 'pending' ? ' selected' : '' ?>>미확정(접수중)</option>
						<option value="success"<?= $only === 'success' ? ' selected' : '' ?>>성공</option>
						<option value="failed"<?= $only === 'failed' ? ' selected' : '' ?>>실패·취소</option>
					</select>
				</div>
				<div class="col-12 col-md-5">
					<label class="form-label fs-8 text-muted mb-1">거래 ID · 접수번호</label>
					<input type="text" name="q" value="<?= $esc($q) ?>" class="form-control form-control-sm form-control-solid" placeholder="WD366-… 또는 접수번호" />
				</div>
				<div class="col-6 col-md-2">
					<button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">조회</button>
				</div>
				<div class="col-6 col-md-2">
					<a href="<?= $esc($selfUrl) ?>" class="btn btn-sm btn-light w-100">초기화</a>
				</div>
			</form>

			<?php if ($transfers === []) : ?>
			<div class="text-center text-gray-500 py-10 fs-7">조건에 맞는 이체가 없습니다.</div>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-8 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">접수일시</th>
							<th class="min-w-90px">상태</th>
							<th class="min-w-100px">종류</th>
							<th class="min-w-170px">거래 ID</th>
							<th class="min-w-150px">접수번호</th>
							<th class="min-w-90px text-end">금액</th>
							<th class="min-w-110px">수취</th>
							<th class="min-w-140px">확정일시</th>
							<th class="min-w-160px">비고</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($transfers as $t) :
						    $st = (string) $t['status'];
						    [$stLabel, $stClass] = match ($st) {
						        'SUCCESS'   => ['성공', 'success'],
						        'FAILED'    => ['실패', 'danger'],
						        'CANCELLED' => ['취소', 'danger'],
						        'RECEPTION' => ['접수됨', 'info'],
						        default     => [$st, 'warning'],
						    };
						    $kindLabel = match ((string) $t['kind']) {
						        'withdrawal'    => '라이더 출금',
						        'daily_payout'  => '일일지급',
						        'agency_payout' => '자체 인출',
						        default         => (string) $t['kind'],
						    };
						    ?>
						<tr>
							<td class="text-muted text-nowrap"><?= $esc((string) $t['submitted_at']) ?></td>
							<td><span class="badge badge-light-<?= $stClass ?>"><?= $esc($stLabel) ?></span></td>
							<td class="text-gray-700 text-nowrap"><?= $esc($kindLabel) ?> #<?= (int) $t['ref_id'] ?></td>
							<td class="font-monospace text-gray-700"><?= $esc((string) $t['transaction_id']) ?></td>
							<td class="font-monospace text-gray-600"><?= $esc((string) $t['reception_id']) ?: '—' ?></td>
							<td class="text-end fw-bold text-gray-800 text-nowrap"><?= number_format((int) $t['amount']) ?>원</td>
							<td class="font-monospace text-gray-600 text-nowrap"><?= $esc((string) $t['account_masked']) ?></td>
							<td class="text-muted text-nowrap"><?= $esc((string) ($t['finalized_at'] ?? '')) ?: '<span class="badge badge-light-warning">대기</span>' ?></td>
							<td class="text-gray-600"><?= $esc((string) $t['fail_reason']) ?: '—' ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<!--end::이체 현황-->
	<?php else : ?>
	<!--begin::통보 수신-->
	<div class="card card-flush shadow-sm">
		<div class="card-header pt-5">
			<div class="card-title">
				<h3 class="fw-bold m-0">처리결과 통보 수신</h3>
				<span class="text-gray-500 fs-8 d-block mt-1">
					바움이 <strong>알려 온</strong> 결과입니다. 한 건도 없다면
					<strong>통보 URL 등록</strong>이나 <strong>허용 IP</strong>를 확인하세요(연동 설정 화면).
				</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<?php if ($events === []) : ?>
			<div class="text-center py-10">
				<div class="text-gray-700 fw-bold fs-6 mb-2">아직 수신한 통보가 없습니다.</div>
				<div class="text-gray-500 fs-7 mb-4">
					이체를 접수했는데도 통보가 없다면 <strong>바움에 통보 URL 이 등록되지 않은 것</strong>입니다.
				</div>
				<a href="<?= $esc(admin_url('system/firm-integration')) ?>" class="btn btn-sm btn-primary">연동 설정에서 통보 URL 등록</a>
			</div>
			<?php else : ?>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-8 gy-3">
					<thead>
						<tr class="fw-bold text-muted">
							<th class="min-w-140px">수신일시</th>
							<th class="min-w-70px">구분</th>
							<th class="min-w-80px">상태</th>
							<th class="min-w-160px">거래 ID</th>
							<th class="min-w-90px text-end">금액</th>
							<th class="min-w-110px">발신 IP</th>
							<th class="min-w-200px">처리</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($events as $e) : ?>
						<tr>
							<td class="text-muted text-nowrap"><?= $esc((string) $e['created_at']) ?></td>
							<td>
								<?php if ((string) $e['amount_sign'] === '+') : ?>
								<span class="badge badge-light-primary">입금</span>
								<?php else : ?>
								<span class="badge badge-light-secondary">출금</span>
								<?php endif; ?>
							</td>
							<td class="text-gray-800"><?= $esc((string) $e['transfer_status']) ?></td>
							<td class="font-monospace text-gray-700"><?= $esc((string) $e['transaction_id']) ?: '—' ?></td>
							<td class="text-end fw-bold text-gray-800 text-nowrap"><?= number_format((int) $e['amount']) ?>원</td>
							<td class="font-monospace text-gray-600 text-nowrap"><?= $esc((string) $e['source_ip']) ?></td>
							<td class="text-gray-600">
								<?php if ((int) $e['matched'] !== 1) : ?><span class="badge badge-light-warning me-1">미매칭</span><?php endif; ?>
								<?= $esc((string) $e['note']) ?: '—' ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<!--end::통보 수신-->
	<?php endif; ?>
	<?php endif; ?>

<script>
(function () {
	var API = <?= json_encode(ADMIN_BASE . '/api/firm_config.php', JSON_UNESCAPED_UNICODE) ?>;
	var box = document.getElementById('firm_toast');
	var msg = document.getElementById('firm_toast_msg');

	function toast(text, ok) {
		if (!box) { return; }
		box.className = 'alert alert-dismissible mb-6 ' + (ok ? 'alert-success' : 'alert-danger');
		msg.textContent = text;
		box.classList.remove('d-none');
	}

	var btn = document.getElementById('firm_reconcile');
	if (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ action: 'reconcile', min_age: 5 })
			})
				.then(function (r) { return r.json(); })
				.then(function (j) {
					toast(j.message || (j.ok ? '조회 완료' : '조회 실패'), !!j.ok);
					// 확정된 건이 있으면 표를 새로 그린다.
					if (j.ok && j.result && j.result.finalized > 0) {
						setTimeout(function () { location.reload(); }, 1200);
					}
				})
				.catch(function (e) { toast(e.message || '요청 실패', false); })
				.finally(function () { btn.disabled = false; });
		});
	}
})();
</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

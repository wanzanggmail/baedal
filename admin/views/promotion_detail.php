<?php

declare(strict_types=1);

/** 프로모션 지급 배치 상세 — 라이더별 결과 확인 + 실패 건 재시도 */

require_once INC_PATH . '/Promotion.php';

$batchId = (int) ($_GET['id'] ?? 0);
$listUrl = admin_url('promotion');

$batch = Promotion::findBatch($batchId);
if ($batch === null) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-warning">지급 배치를 찾을 수 없습니다. <a href="' . htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') . '">목록</a></div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}
// 멀티테넌시: 배치 소유 대리점 스코프 밖이면 차단
if (!Org::canAccessAgency((int) ($batch['agency_id'] ?? 0))) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger">이 배치에 접근할 권한이 없습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';
    return;
}

$entries = Promotion::entries($batchId);

$statusLabels = [
    'draft'   => ['미지급', 'badge-light-secondary'],
    'paid'    => ['지급 완료', 'badge-light-success'],
    'partial' => ['일부 실패', 'badge-light-warning'],
    'failed'  => ['전건 실패', 'badge-light-danger'],
];
$entryLabels = [
    'pending' => ['대기', 'badge-light-warning'],
    'paid'    => ['지급 완료', 'badge-light-success'],
    'failed'  => ['실패', 'badge-light-danger'],
    'skipped' => ['제외', 'badge-light-secondary'],
];
[$stLabel, $stBadge] = $statusLabels[$batch['status']] ?? [(string) $batch['status'], 'badge-light'];

$retryable = 0;
foreach ($entries as $e) {
    if (in_array((string) $e['status'], ['failed', 'pending'], true) && (int) $e['total_amount'] > 0 && $e['rider_id'] !== null) {
        $retryable++;
    }
}

$fmtWon = static fn (int $n): string => number_format($n) . '원';
$payApi = rtrim(ADMIN_BASE, '/') . '/api/promotion_pay.php';
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">프로모션 지급 상세</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted"><a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">프로모션</a></li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900"><?= htmlspecialchars((string) $batch['pay_date'], ENT_QUOTES, 'UTF-8') ?></li>
			</ul>
		</div>
		<div class="d-flex gap-2">
			<?php if ($retryable > 0) : ?>
			<button type="button" class="btn btn-sm btn-warning fw-bold" id="promoRetryBtn" data-batch="<?= (int) $batchId ?>">
				미지급·실패 <?= $retryable ?>건 재시도
			</button>
			<?php endif; ?>
			<a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light fw-bold">목록</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<div id="promoDetailResult" class="d-none mb-6"></div>

	<div class="row g-5 g-xl-8 mb-8">
		<div class="col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">지급일자 · 대리점</div>
				<div class="fw-bold fs-3 text-gray-900"><?= htmlspecialchars((string) $batch['pay_date'], ENT_QUOTES, 'UTF-8') ?></div>
				<div class="text-gray-700 fs-7 mt-1"><?= htmlspecialchars((string) ($batch['agency_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
				<span class="badge <?= htmlspecialchars($stBadge, ENT_QUOTES, 'UTF-8') ?> mt-2"><?= htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8') ?></span>
			</div></div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">지급 인원</div>
				<div class="fw-bold fs-3 text-gray-900"><?= number_format((int) $batch['paid_riders']) ?> / <?= number_format((int) $batch['total_riders']) ?>명</div>
				<div class="text-muted fs-8 mt-1">성공 / 대상</div>
			</div></div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">실지급액</div>
				<div class="fw-bold fs-3 text-primary"><?= $fmtWon((int) $batch['paid_amount']) ?></div>
				<div class="text-muted fs-8 mt-1">예정 <?= $fmtWon((int) $batch['total_amount']) ?></div>
			</div></div>
		</div>
		<div class="col-xl-3">
			<div class="card card-flush h-100"><div class="card-body py-6">
				<div class="text-gray-500 fw-semibold fs-7 mb-1">플랫폼 수수료</div>
				<div class="fw-bold fs-3 text-danger"><?= $fmtWon((int) $batch['fee_amount']) ?></div>
				<div class="text-muted fs-8 mt-1">카드 청구 = 지급액 + 수수료</div>
			</div></div>
		</div>
	</div>

	<div class="card card-flush">
		<div class="card-header align-items-center border-0 pt-6">
			<div class="card-title">
				<h3 class="fw-bold m-0">라이더별 지급 내역</h3>
				<span class="text-gray-500 fs-8 fw-semibold d-block mt-1">
					파일 <?= htmlspecialchars((string) ($batch['original_filename'] ?: '—'), ENT_QUOTES, 'UTF-8') ?>
					<?php if (($batch['memo'] ?? '') !== '') : ?> · <?= htmlspecialchars((string) $batch['memo'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
					· <?= htmlspecialchars((string) ($batch['operator_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
				</span>
			</div>
		</div>
		<div class="card-body pt-0">
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle gy-3">
					<thead><tr class="fw-bold text-muted fs-7 bg-light">
						<th>라이더</th>
						<th class="text-end">프로모션1</th>
						<th class="text-end">프로모션2</th>
						<th class="text-end">합계</th>
						<th class="text-end">수수료</th>
						<th>상태</th>
						<th>지급시각 / 사유</th>
					</tr></thead>
					<tbody>
						<?php if ($entries === []) : ?>
						<tr><td colspan="7" class="text-center text-muted py-10">내역이 없습니다.</td></tr>
						<?php endif; ?>
						<?php foreach ($entries as $e) :
						    [$eLabel, $eBadge] = $entryLabels[$e['status']] ?? [(string) $e['status'], 'badge-light'];
						    ?>
						<tr>
							<td>
								<span class="fw-bold text-gray-800"><?= htmlspecialchars((string) ($e['rider_name'] ?: $e['rider_name_raw']), ENT_QUOTES, 'UTF-8') ?></span>
								<span class="text-muted fs-8 d-block font-monospace"><?= htmlspecialchars((string) ($e['rider_code'] ?: $e['rider_code_raw']), ENT_QUOTES, 'UTF-8') ?></span>
							</td>
							<td class="text-end"><?= $fmtWon((int) $e['promo1_amount']) ?></td>
							<td class="text-end"><?= $fmtWon((int) $e['promo2_amount']) ?></td>
							<td class="text-end fw-bold"><?= $fmtWon((int) $e['total_amount']) ?></td>
							<td class="text-end text-muted fs-8"><?= (int) $e['fee_amount'] > 0 ? $fmtWon((int) $e['fee_amount']) : '—' ?></td>
							<td><span class="badge <?= htmlspecialchars($eBadge, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($eLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
							<td class="fs-8 text-gray-600">
								<?php if ($e['paid_at'] !== null) : ?>
								<?= htmlspecialchars(substr((string) $e['paid_at'], 0, 19), ENT_QUOTES, 'UTF-8') ?>
								<?php elseif (($e['fail_reason'] ?? '') !== '') : ?>
								<span class="text-danger"><?= htmlspecialchars((string) $e['fail_reason'], ENT_QUOTES, 'UTF-8') ?></span>
								<?php else : ?>—<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<script>
	(function () {
		var btn = document.getElementById('promoRetryBtn');
		if (!btn) return;
		var API = <?= json_encode($payApi, JSON_UNESCAPED_UNICODE) ?>;
		var box = document.getElementById('promoDetailResult');

		btn.addEventListener('click', function () {
			if (!confirm('미지급·실패 건에 대해 카드결제를 다시 시도할까요?')) return;
			btn.disabled = true;
			fetch(API, {
				method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
				body: JSON.stringify({ batch_id: Number(btn.getAttribute('data-batch')) })
			})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d.ok) throw new Error(d.message || '오류');
				box.className = 'alert alert-' + (d.failed > 0 ? 'warning' : 'success') + ' mb-6';
				box.textContent = d.message;
				setTimeout(function () { location.reload(); }, 1500);
			})
			.catch(function (e) {
				box.className = 'alert alert-danger mb-6';
				box.textContent = e.message;
				btn.disabled = false;
			});
		});
	})();
	</script>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

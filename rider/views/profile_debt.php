<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderDebt.php';

$ru = rider_current_user();
$riderId = $ru ? (int) $ru['id'] : 0;

$debtReady = RiderDebt::tableReady();
$debts = ($debtReady && $riderId > 0) ? RiderDebt::forRider($riderId) : [];
foreach ($debts as &$__d) {
    $__d['entries'] = RiderDebt::entries((int) $__d['id']);
}
unset($__d);

$debtKindBadge   = ['loan' => 'primary', 'lease' => 'warning', 'advance' => 'info'];
$debtStatusLabel = ['active' => '진행 중', 'paused' => '일시중지', 'closed' => '완납/종료'];
$fmtWon = static fn ($v): string => number_format((int) $v) . '원';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">대여금 · 리스 · 선지급</h2>
		<span class="text-gray-500 fs-7">잔액은 정산 반영 시 자동으로 차감됩니다</span>
	</div>
	<div class="card-body pt-0">
		<?php if (!$debtReady) : ?>
		<p class="text-muted fs-7 py-6 mb-0">준비 중입니다.</p>
		<?php elseif ($debts === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">등록된 미수금(대여금·리스·선지급)이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($debts as $d) :
				$dk = (string) $d['kind'];
				$isAmort = in_array($dk, ['loan', 'advance'], true);
			?>
			<div class="border border-gray-200 rounded p-4">
				<div class="d-flex justify-content-between align-items-start mb-2">
					<div>
						<span class="badge badge-light-<?= $debtKindBadge[$dk] ?? 'secondary' ?> me-2"><?= htmlspecialchars($d['kind_label'], ENT_QUOTES, 'UTF-8') ?></span>
						<span class="fw-semibold text-gray-800"><?= htmlspecialchars((string) ($d['title'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></span>
					</div>
					<span class="badge badge-light-<?= $d['status'] === 'active' ? 'success' : ($d['status'] === 'closed' ? 'dark' : 'warning') ?> fs-8"><?= htmlspecialchars($debtStatusLabel[$d['status']] ?? $d['status'], ENT_QUOTES, 'UTF-8') ?></span>
				</div>
				<div class="fs-7 text-gray-600 d-flex flex-wrap gap-4 mb-1">
					<?php if ($isAmort) : ?>
					<span>원금 <?= $fmtWon($d['principal_amount']) ?></span>
					<span class="fw-bold <?= (int) $d['balance_amount'] > 0 ? 'text-danger' : 'text-gray-500' ?>">남은 잔액 <?= $fmtWon($d['balance_amount']) ?></span>
					<?php endif; ?>
					<?php if ((int) $d['daily_amount'] > 0) : ?>
					<span>일납 <?= $fmtWon($d['daily_amount']) ?></span>
					<?php endif; ?>
					<?php if (!empty($d['due_updated_on'])) : ?>
					<span>최근 차감 <?= htmlspecialchars((string) $d['due_updated_on'], ENT_QUOTES, 'UTF-8') ?></span>
					<?php endif; ?>
				</div>
				<?php if (!empty($d['entries'])) : ?>
				<button type="button" class="btn btn-sm btn-light py-1 px-3 mt-2" data-bs-toggle="collapse" data-bs-target="#rd_hist_<?= (int) $d['id'] ?>">차감 이력 <?= count($d['entries']) ?></button>
				<div class="collapse mt-3" id="rd_hist_<?= (int) $d['id'] ?>">
					<table class="table table-sm mb-0 fs-8">
						<thead><tr class="text-muted"><th>귀속일</th><th class="text-center">일수</th><th class="text-end">차감액</th><th>메모</th></tr></thead>
						<tbody>
							<?php foreach ($d['entries'] as $e) : ?>
							<tr>
								<td><?= htmlspecialchars((string) $e['applied_date'], ENT_QUOTES, 'UTF-8') ?></td>
								<td class="text-center"><?= (int) $e['days'] ?></td>
								<td class="text-end text-danger">-<?= $fmtWon($e['amount']) ?></td>
								<td class="text-gray-600"><?= htmlspecialchars((string) ($e['memo'] ?: ''), ENT_QUOTES, 'UTF-8') ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

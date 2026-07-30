<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$rows      = $riderId > 0 ? Withdrawal::listForRider($riderId) : [];
$detailBase = rider_url('withdrawal/detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청 내역</h2>
	</div>
	<div class="card-body pt-0">
		<?php if ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">출금 신청 내역이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($rows as $row) : ?>
			<a href="<?= htmlspecialchars($detailBase . 'id=' . (int) $row['db_id'], ENT_QUOTES, 'UTF-8') ?>" class="border border-gray-200 rounded p-4 text-decoration-none text-gray-800 d-block">
				<div class="d-flex justify-content-between mb-2">
					<span class="fw-bold">₩ <?= number_format((int) $row['amount']) ?></span>
					<span class="badge badge-light-<?= htmlspecialchars($row['status_class'], ENT_QUOTES, 'UTF-8') ?>">
						<?= htmlspecialchars($row['status_label'], ENT_QUOTES, 'UTF-8') ?>
					</span>
				</div>
				<div class="fs-8 text-gray-600">
					신청 <?= htmlspecialchars($row['requested_at'], ENT_QUOTES, 'UTF-8') ?>
					<?php if ((int) ($row['accrued_days'] ?? 0) > 0) : ?>
					· 적립 <?= (int) $row['accrued_days'] ?>일
					<?php endif; ?>
				</div>
				<?php if ($row['completed_at'] !== '') : ?>
				<div class="fs-8 text-gray-600">완료 <?= htmlspecialchars($row['completed_at'], ENT_QUOTES, 'UTF-8') ?></div>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

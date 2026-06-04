<?php

declare(strict_types=1);

require_once INC_PATH . '/SettlementLedger.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$rows      = [];
$needsMigrate = !SettlementLedger::tableExists();

if ($riderId > 0 && !$needsMigrate) {
    try {
        $rows = SettlementLedger::listForRider($riderId, ['limit' => 50]);
    } catch (Throwable) {
        $rows = [];
    }
}

$detailBase = rider_url('settlement/fee-detail');
$detailBase .= str_contains($detailBase, '?') ? '&' : '?';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산 수수료 내역</h2>
		<span class="text-gray-500 fs-7">정산 완료 후 차감된 수수료·실지급</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($needsMigrate) : ?>
		<p class="text-muted fs-7 py-6 mb-0">정산 수수료 내역을 준비 중입니다.</p>
		<?php elseif ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">아직 정산 완료 내역이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-3">
			<?php foreach ($rows as $row) : ?>
			<a href="<?= htmlspecialchars($detailBase . 'cycle=' . (int) $row['id'], ENT_QUOTES, 'UTF-8') ?>"
				class="border border-gray-200 rounded p-4 text-decoration-none text-gray-800">
				<div class="d-flex justify-content-between mb-2">
					<span class="fw-bold"><?= htmlspecialchars($row['settlement_date'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="badge badge-light-success fs-8">완료</span>
				</div>
				<div class="fs-7 text-gray-600 mb-1"><?= htmlspecialchars($row['platform_label'], ENT_QUOTES, 'UTF-8') ?></div>
				<div class="d-flex justify-content-between fs-7">
					<span class="text-danger">수수료 −<?= number_format((int) $row['total_fee_amount']) ?>원</span>
					<span class="fw-bold text-primary">+<?= number_format((int) $row['net_amount']) ?>원</span>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

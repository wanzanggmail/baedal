<?php

declare(strict_types=1);

require_once INC_PATH . '/Promotion.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

$ready = Promotion::tableReady();
$rows  = ($ready && $riderId > 0) ? Promotion::listForRider($riderId, 100) : [];

$total        = 0;
$p1Sum        = 0;
$p2Sum        = 0;
$deductionSum = 0;
foreach ($rows as $r) {
    $total        += (int) $r['net_amount'];
    $p1Sum        += (int) $r['promo1_amount'];
    $p2Sum        += (int) $r['promo2_amount'];
    $deductionSum += (int) $r['withholding_amount'] + (int) $r['employment_ins_amount'] + (int) $r['accident_ins_amount'];
}
$grossSum = $p1Sum + $p2Sum;

$fmtWon = static fn (int $n): string => number_format($n) . '원';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">프로모션 내역</h2>
		<span class="text-gray-500 fs-7">지급받은 프로모션은 원천세·보험 공제 후 지갑에 바로 적립됩니다</span>
	</div>
	<div class="card-body pt-0">
		<?php if (!$ready) : ?>
		<p class="text-muted fs-7 py-6 mb-0">준비 중입니다.</p>
		<?php elseif ($rows === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">지급받은 프로모션이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">프로모션1 합계</span>
			<span class="fw-semibold"><?= $fmtWon($p1Sum) ?></span>
		</div>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">프로모션2 합계</span>
			<span class="fw-semibold"><?= $fmtWon($p2Sum) ?></span>
		</div>
		<?php if ($deductionSum > 0) : ?>
		<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
			<span class="text-gray-600 fs-7">원천세·보험 공제</span>
			<span class="fw-semibold text-danger">−<?= $fmtWon($deductionSum) ?></span>
		</div>
		<?php endif; ?>
		<div class="d-flex justify-content-between py-3">
			<span class="fw-bold text-gray-800">총 받은 프로모션(실수령)</span>
			<span class="fs-3 fw-bold text-primary"><?= $fmtWon($total) ?></span>
		</div>
		<?php endif; ?>
	</div>
</div>

<?php if ($ready && $rows !== []) : ?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-6">지급 내역</h3>
	</div>
	<div class="card-body pt-0">
		<div class="d-flex flex-column gap-3">
			<?php foreach ($rows as $r) :
			    $ded = (int) $r['withholding_amount'] + (int) $r['employment_ins_amount'] + (int) $r['accident_ins_amount'];
			    ?>
			<div class="border border-gray-200 rounded p-4">
				<div class="d-flex justify-content-between mb-2">
					<span class="fw-bold"><?= htmlspecialchars((string) $r['pay_date'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="fw-bold text-primary">+<?= $fmtWon((int) $r['net_amount']) ?></span>
				</div>
				<div class="fs-8 text-gray-600">
					<?php if ((int) $r['promo1_amount'] > 0) : ?>
					프로모션1 <?= $fmtWon((int) $r['promo1_amount']) ?>
					<?php endif; ?>
					<?php if ((int) $r['promo2_amount'] > 0) : ?>
					<?= (int) $r['promo1_amount'] > 0 ? ' · ' : '' ?>프로모션2 <?= $fmtWon((int) $r['promo2_amount']) ?>
					<?php endif; ?>
				</div>
				<?php if ($ded > 0) : ?>
				<div class="fs-9 text-gray-500 mt-1">
					공제 전 <?= $fmtWon((int) $r['total_amount']) ?>
					<?php if ((int) $r['withholding_amount'] > 0) : ?> · 원천세 −<?= $fmtWon((int) $r['withholding_amount']) ?><?php endif; ?>
					<?php $ins = (int) $r['employment_ins_amount'] + (int) $r['accident_ins_amount']; ?>
					<?php if ($ins > 0) : ?> · 보험 −<?= $fmtWon($ins) ?><?php endif; ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
<?php endif; ?>

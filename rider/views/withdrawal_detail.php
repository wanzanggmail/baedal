<?php

declare(strict_types=1);

require_once INC_PATH . '/Withdrawal.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$listUrl   = rider_url('withdrawal/history');

$dbId = Withdrawal::parseId($_GET['id'] ?? '');
$wr   = null;
if ($dbId !== null && $riderId > 0) {
    $wr = db_row(
        "SELECT wr.*, sc.label AS bank_label
           FROM withdrawal_requests wr
           LEFT JOIN system_codes sc ON sc.category = 'bank' AND sc.code = wr.bank_code
          WHERE wr.id = ? AND wr.rider_id = ?
          LIMIT 1",
        [$dbId, $riderId]
    );
}

if ($wr === null) {
    ?>
    <div class="alert alert-warning m-0">내역을 찾을 수 없습니다. <a href="<?= htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8') ?>">목록으로</a></div>
    <?php
    return;
}

$statusLabels = ['pending' => ['대기', 'warning'], 'downloaded' => ['처리 중', 'primary'], 'completed' => ['처리 완료', 'success'], 'rejected' => ['반려', 'danger']];
[$statusLabel, $statusClass] = $statusLabels[(string) $wr['status']] ?? [(string) $wr['status'], 'secondary'];
$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];

$picked = db_rows(
    'SELECT wrc.cycle_id, wrc.amount, wrc.order_count,
            src.settlement_date, src.platform, src.net_amount AS cycle_net_amount,
            src.gross_amount, src.support_amount
       FROM withdrawal_request_cycles wrc
       INNER JOIN settlement_rider_cycles src ON src.id = wrc.cycle_id
      WHERE wrc.request_id = ?
      ORDER BY src.settlement_date ASC, src.id ASC',
    [$dbId]
);

$cycleIds = array_map(static fn (array $p): int => (int) $p['cycle_id'], $picked);
$feeItemsByCycle = [];
if ($cycleIds !== []) {
    $ph = implode(',', array_fill(0, count($cycleIds), '?'));
    $feeRows = db_rows(
        "SELECT cycle_id, fee_code, label, amount FROM settlement_fee_items WHERE cycle_id IN ({$ph})",
        $cycleIds
    );
    foreach ($feeRows as $fr) {
        $feeItemsByCycle[(int) $fr['cycle_id']][] = $fr;
    }
}

// 정산 반영 시점 공제 항목(원천세·보험 등)을 이번 출금이 가져간 비율만큼 안분해 집계.
$feeAgg = []; // fee_code => ['label' => ..., 'amount' => sum]
$cycleNetSum = 0;
foreach ($picked as $p) {
    $cycleNetSum += (int) $p['amount'];
    $cycleNet = (int) $p['cycle_net_amount'];
    $ratio = $cycleNet > 0 ? min(1.0, (int) $p['amount'] / $cycleNet) : 0.0;
    foreach ($feeItemsByCycle[(int) $p['cycle_id']] ?? [] as $fi) {
        $code = (string) $fi['fee_code'];
        // 🔒 대리점 선차감(2026-09-06 갑)은 라이더에게 보이지 않는 대리점 몫이라
        // 공제 목록에 넣지 않는다. 라이더 기준으로는 애초에 그만큼 낮은 단가였다.
        if ($code === 'agency_prededuct') {
            continue;
        }
        $prorated = (int) round((int) $fi['amount'] * $ratio);
        if (!isset($feeAgg[$code])) {
            $feeAgg[$code] = ['label' => (string) $fi['label'], 'amount' => 0];
        }
        $feeAgg[$code]['amount'] += $prorated;
    }
}

$mainCodes = ['withholding' => '원천세', 'employment_ins' => '고용보험', 'accident_ins' => '산재보험'];
$mainFees = [];
$otherFeeTotal = 0;
foreach ($feeAgg as $code => $f) {
    if (isset($mainCodes[$code])) {
        $mainFees[$code] = $f;
    } else {
        $otherFeeTotal += (int) $f['amount'];
    }
}
$feeItemsTotal = array_sum(array_column($feeAgg, 'amount'));

$reserve = (int) ($wr['withhold_min_retain'] ?? 0);
$fee     = (int) ($wr['withhold_other'] ?? 0);
$payout  = (int) $wr['amount'];
$settlementTotal = $cycleNetSum + $feeItemsTotal;

$fmtWon = static fn ($v): string => number_format((int) $v) . '원';
?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">정산조회 상세</h2>
		<span class="badge badge-light-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span>
	</div>
	<div class="card-body pt-0">
		<div class="text-gray-600 fs-7 mb-1">실수령금액</div>
		<div class="fs-1 fw-bold text-success mb-5"><?= $fmtWon($payout) ?></div>

		<div class="d-flex justify-content-between fs-6 mb-4 p-3 bg-light rounded">
			<span>정산 <span class="fw-bold text-primary"><?= $fmtWon($settlementTotal) ?></span></span>
			<span>공제 <span class="fw-bold text-danger">−<?= $fmtWon($settlementTotal - $payout) ?></span></span>
		</div>

		<div class="fs-7 mb-6">
			<?php if ($mainFees !== [] || $otherFeeTotal > 0) : ?>
			<div class="text-gray-500 fw-semibold mb-2">정산 반영 시 공제 (안분)</div>
			<?php foreach (['withholding', 'employment_ins', 'accident_ins'] as $code) : ?>
				<?php if (isset($mainFees[$code]) && $mainFees[$code]['amount'] > 0) : ?>
				<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
					<span><?= htmlspecialchars($mainCodes[$code], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="text-danger">−<?= $fmtWon($mainFees[$code]['amount']) ?></span>
				</div>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ($otherFeeTotal > 0) : ?>
			<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
				<span>기타 공제(대여금·시간제보험 등)</span>
				<span class="text-danger">−<?= $fmtWon($otherFeeTotal) ?></span>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<div class="text-gray-500 fw-semibold mb-2 mt-4">출금 신청 시 공제</div>
			<div class="d-flex justify-content-between py-2 border-bottom border-gray-200">
				<span>정산수수료</span>
				<span class="text-danger">−<?= $fmtWon($fee) ?></span>
			</div>
			<?php // 보증금은 이번 출금에서 빠진 돈이 아니라 지갑에 남겨둔 최소 잔액 — 차감으로 표기하지 않는다. ?>
			<div class="d-flex justify-content-between py-2 text-gray-600 fs-8">
				<span>보증금(지갑에 유지)</span>
				<span><?= $fmtWon($reserve) ?></span>
			</div>
		</div>
	</div>
</div>

<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h3 class="card-title fw-bold fs-5">일별 상세</h3>
	</div>
	<div class="card-body pt-0">
		<?php if ($picked === []) : ?>
		<p class="text-muted fs-7 py-6 mb-0 text-center">연결된 일별 정산 내역이 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-2">
			<?php foreach ($picked as $p) :
				$dow = ['일', '월', '화', '수', '목', '금', '토'][(int) date('w', strtotime((string) $p['settlement_date']))];
			?>
			<div class="d-flex align-items-center justify-content-between border-bottom border-gray-200 py-3 fs-7">
				<div>
					<span class="fw-semibold"><?= htmlspecialchars(date('n월 j일', strtotime((string) $p['settlement_date'])), ENT_QUOTES, 'UTF-8') ?> (<?= $dow ?>)</span>
					<span class="badge badge-light-info ms-2 fs-9"><?= htmlspecialchars($platformLabels[$p['platform']] ?? $p['platform'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="text-gray-500 ms-1"><?= (int) $p['order_count'] ?>건</span>
				</div>
				<div class="fw-bold text-success"><?= $fmtWon($p['amount']) ?></div>
			</div>
			<?php endforeach; ?>
			<div class="d-flex align-items-center justify-content-between pt-3">
				<span class="fw-bold">합계</span>
				<span class="fs-4 fw-bold text-primary"><?= $fmtWon($cycleNetSum) ?></span>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>

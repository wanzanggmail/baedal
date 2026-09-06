<?php

declare(strict_types=1);

/**
 * 라이더 정산 상세 — 정산일 1건의 구성·공제 내역.
 *
 * 2026-08-13: 하드코딩 목업(2026-05-09 배민 124,800원 고정)을 실데이터로 교체.
 * `?date=YYYY-MM-DD` 로 특정 정산일을 보고, 없으면 가장 최근 정산일을 보여준다.
 */

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

$won = static fn (int $n): string => '₩ ' . number_format($n);
$esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$platformLabels = ['baemin' => '배달의민족', 'coupang' => '쿠팡이츠', 'other' => '기타'];
$feeLabels = [
    'hourly_ins'      => '시간제보험',
    'excel_deduction' => '차감내역',
    'agency_fee'      => '대행수수료',
    'withholding'     => '원천세',
    'employment_ins'  => '고용보험',
    'accident_ins'    => '산재보험',
    'advance'         => '선지급',
    'loan'            => '대여금',
    'lease'           => '리스',
    'vat'             => '부가세',
];

$needsMigrate = !db_table_exists('settlement_rider_cycles');
$reqDate = trim((string) ($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reqDate)) {
    $reqDate = '';
}

$cycle    = null;
$feeItems = [];
$orders   = [];
$navPrev  = null;
$navNext  = null;

if ($riderId > 0 && !$needsMigrate) {
    // 대상 사이클 — 지정일이 있으면 그 날, 없으면 가장 최근
    if ($reqDate !== '') {
        $cycle = db_row(
            'SELECT * FROM settlement_rider_cycles WHERE rider_id = ? AND settlement_date = ? ORDER BY id DESC LIMIT 1',
            [$riderId, $reqDate]
        );
    } else {
        $cycle = db_row(
            'SELECT * FROM settlement_rider_cycles WHERE rider_id = ? ORDER BY settlement_date DESC, id DESC LIMIT 1',
            [$riderId]
        );
    }

    if ($cycle !== null) {
        $date = (string) $cycle['settlement_date'];

        if (db_table_exists('settlement_fee_items')) {
            $feeItems = db_rows(
                'SELECT fee_code, label, amount FROM settlement_fee_items WHERE cycle_id = ? ORDER BY id',
                [(int) $cycle['id']]
            );
        }

        if (db_table_exists('settlement_order_details')) {
            $orders = db_rows(
                'SELECT settlement_date, store_name, pickup_area, delivery_area, delivered_at, net_amount
                   FROM settlement_order_details
                  WHERE rider_id = ? AND settlement_date = ?
                  ORDER BY delivered_at ASC, id ASC
                  LIMIT 200',
                [$riderId, $date]
            );
            // 🔒 건별 금액에서도 선차감을 뺀다(2026-09-06 갑). 총액만 낮추고 건별을 그대로 두면
            // 라이더가 건별을 더했을 때 총액과 안 맞아 바로 들통난다.
            require_once INC_PATH . '/RiderPrededuct.php';
            $orders = RiderPrededuct::applyToRows(
                $orders,
                RiderPrededuct::totalsByDate($riderId, $date, $date)
            );
        }

        $navPrev = db_row(
            'SELECT settlement_date FROM settlement_rider_cycles
              WHERE rider_id = ? AND settlement_date < ? ORDER BY settlement_date DESC LIMIT 1',
            [$riderId, $date]
        );
        $navNext = db_row(
            'SELECT settlement_date FROM settlement_rider_cycles
              WHERE rider_id = ? AND settlement_date > ? ORDER BY settlement_date ASC LIMIT 1',
            [$riderId, $date]
        );
    }
}

$detailUrl = static function (string $d): string {
    $u = rider_url('settlement/detail');

    return $u . (str_contains($u, '?') ? '&' : '?') . 'date=' . rawurlencode($d);
};
?>

<?php if ($needsMigrate) : ?>
<div class="alert alert-warning">정산 데이터가 아직 준비되지 않았습니다.</div>

<?php elseif ($cycle === null) : ?>
<div class="card card-flush shadow-sm">
	<div class="card-body text-center py-10">
		<i class="ki-duotone ki-file-deleted fs-3x text-gray-400 mb-3"><span class="path1"></span><span class="path2"></span></i>
		<div class="fs-6 fw-semibold text-gray-700 mb-1"><?= $reqDate !== '' ? '해당 날짜의 정산 내역이 없습니다.' : '아직 정산 내역이 없습니다.' ?></div>
		<div class="fs-8 text-gray-500">정산이 반영되면 여기에 표시됩니다.</div>
		<a href="<?= $esc(rider_url('settlement/calendar')) ?>" class="btn btn-sm btn-light-primary mt-4">정산 달력으로</a>
	</div>
</div>

<?php else :
    $gross    = (int) ($cycle['gross_amount'] ?? 0);
    $support  = (int) ($cycle['support_amount'] ?? 0);
    $feeTotal = (int) ($cycle['total_fee_amount'] ?? 0);
    $net      = (int) ($cycle['net_amount'] ?? 0);

    // 🔒 대리점 선차감(2026-09-06 갑) — 라이더 화면에서는 공제 줄로 보여주지 않고
    // 정산금액을 그만큼 낮춘다. 아래 $base = $net + $feeTotal 이 곧 정산금액이라,
    // 목록에서 빼고 합계에서도 빼면 화면 전체가 낮아진 단가 기준으로 딱 떨어진다.
    $prededucted = 0;
    foreach ($feeItems as $f) {
        if ((string) ($f['fee_code'] ?? '') === 'agency_prededuct') {
            $prededucted += (int) ($f['amount'] ?? 0);
        }
    }
    if ($prededucted > 0) {
        $feeTotal -= $prededucted;
        $gross    -= $prededucted;
        $feeItems  = array_values(array_filter(
            $feeItems,
            static fn (array $f): bool => (string) ($f['fee_code'] ?? '') !== 'agency_prededuct'
        ));
    }
    $platform = (string) ($cycle['platform'] ?? '');
    $date     = (string) $cycle['settlement_date'];
    $orderCnt = (int) ($cycle['order_count'] ?? 0);
    ?>

<!--begin::요약-->
<div class="card card-flush shadow-sm mb-4">
	<div class="card-body">
		<div class="d-flex align-items-center justify-content-between mb-1">
			<div class="fs-7 text-gray-500">
				<?= $esc($date) ?> · <?= $esc($platformLabels[$platform] ?? $platform) ?>
			</div>
			<span class="badge badge-light-success">반영 완료</span>
		</div>
		<div class="fs-2 fw-bold text-gray-900"><?= $won($net) ?></div>
		<div class="fs-8 text-gray-500 mt-1">실지급액 (지갑 적립분) · 배달 <?= number_format($orderCnt) ?>건</div>
	</div>
</div>
<!--end::요약-->

<!--begin::항목별 내역-->
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">항목별 내역</h2>
	</div>
	<div class="card-body pt-0 fs-7">
		<?php
		// 기준액은 저장된 실지급액에서 역산한다(= 실지급 + 공제합).
		// 2026-08-09 이전에 반영된 사이클은 net이 gross가 아니라 보수액 기준으로 저장돼 있어서
		// gross로 수식을 쓰면 화면에 "22,842 − 280 = 16,374" 같은 안 맞는 식이 나온다.
		// 라이더에게는 항상 딱 떨어지는 숫자만 보여준다.
		$base     = $net + $feeTotal;
		$grossFits = ($gross + $support) === $base;
		?>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span>정산금액<?php if ($support > 0) : ?><span class="d-block text-gray-500 fs-8">지원금 <?= $won($support) ?> 포함</span><?php endif; ?></span>
			<span class="fw-semibold"><?= $won($base) ?></span>
		</div>

		<?php if ($feeItems !== []) : ?>
			<?php foreach ($feeItems as $f) :
			    $code = (string) ($f['fee_code'] ?? '');
			    $amt  = (int) ($f['amount'] ?? 0);
			    if ($amt === 0) {
			        continue;
			    }
			    $label = (string) ($f['label'] ?? '') !== '' ? (string) $f['label'] : ($feeLabels[$code] ?? $code);
			    ?>
			<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
				<span><?= $esc($label) ?></span>
				<span class="fw-semibold text-danger">− <?= $won(abs($amt)) ?></span>
			</div>
			<?php endforeach; ?>
			<?php
			// 항목 합계가 저장된 공제 총액과 다르면(구 데이터 등) 차액을 "기타"로 드러낸다 —
			// 항목만 더해서는 실지급액이 안 나오는 화면이 되면 안 된다.
			$itemsSum = 0;
			foreach ($feeItems as $f) {
			    $itemsSum += abs((int) ($f['amount'] ?? 0));
			}
			$feeGap = $feeTotal - $itemsSum;
			?>
			<?php if ($feeGap > 0) : ?>
			<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
				<span>기타 공제</span>
				<span class="fw-semibold text-danger">− <?= $won($feeGap) ?></span>
			</div>
			<?php endif; ?>
		<?php elseif ($feeTotal > 0) : ?>
		<div class="d-flex justify-content-between py-3 border-bottom border-gray-200">
			<span>공제 합계</span>
			<span class="fw-semibold text-danger">− <?= $won($feeTotal) ?></span>
		</div>
		<?php endif; ?>

		<div class="d-flex justify-content-between py-3">
			<span class="fw-bold">실지급액</span>
			<span class="fw-bold fs-5 text-primary"><?= $won($net) ?></span>
		</div>
		<div class="text-gray-500 fs-8">
			<?= $won($base) ?> − 공제 <?= $won($feeTotal) ?> = <?= $won($net) ?>
		</div>
		<?php if (!$grossFits) : ?>
		<div class="text-gray-500 fs-8 mt-2">※ 정산 반영 시점의 지급 기준 금액입니다.</div>
		<?php endif; ?>
	</div>
</div>
<!--end::항목별 내역-->

<?php if ($orders !== []) : ?>
<!--begin::배달 내역-->
<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">배달 내역 <span class="text-gray-500 fs-8 ms-1"><?= count($orders) ?>건</span></h2>
	</div>
	<div class="card-body pt-0 fs-8">
		<?php foreach ($orders as $o) : ?>
		<div class="d-flex justify-content-between align-items-start py-3 border-bottom border-gray-200">
			<div class="pe-3">
				<div class="fw-semibold text-gray-800"><?= $esc((string) ($o['store_name'] ?? '') ?: '가게명 없음') ?></div>
				<div class="text-gray-500">
					<?= $esc((string) ($o['pickup_area'] ?? '')) ?>
					<?php if (($o['delivery_area'] ?? '') !== '') : ?>→ <?= $esc((string) $o['delivery_area']) ?><?php endif; ?>
					<?php if (($o['delivered_at'] ?? '') !== '') : ?>
						· <?= $esc(substr((string) $o['delivered_at'], 11, 5)) ?>
					<?php endif; ?>
				</div>
			</div>
			<span class="fw-semibold text-nowrap"><?= $won((int) ($o['net_amount'] ?? 0)) ?></span>
		</div>
		<?php endforeach; ?>
	</div>
</div>
<!--end::배달 내역-->
<?php endif; ?>

<!--begin::날짜 이동-->
<div class="d-flex justify-content-between gap-2 mb-4">
	<?php if ($navPrev !== null) : ?>
		<a href="<?= $esc($detailUrl((string) $navPrev['settlement_date'])) ?>" class="btn btn-sm btn-light flex-grow-1">← <?= $esc((string) $navPrev['settlement_date']) ?></a>
	<?php else : ?><div class="flex-grow-1"></div><?php endif; ?>

	<a href="<?= $esc(rider_url('settlement/calendar')) ?>" class="btn btn-sm btn-light-primary">달력</a>

	<?php if ($navNext !== null) : ?>
		<a href="<?= $esc($detailUrl((string) $navNext['settlement_date'])) ?>" class="btn btn-sm btn-light flex-grow-1"><?= $esc((string) $navNext['settlement_date']) ?> →</a>
	<?php else : ?><div class="flex-grow-1"></div><?php endif; ?>
</div>
<!--end::날짜 이동-->

<?php endif; ?>

<?php

declare(strict_types=1);

/**
 * 원천징수 내역 — 라이더가 종합소득세 신고(매년 5월) 때 보는 화면.
 *
 * ⚠️ 공식 원천징수영수증이 아니다. 화면에도 그렇게 안내한다 — 이걸 그대로 세무서에
 *    제출하면 안 된다. 근거는 `RiderTaxSummary` 클래스 주석 참고.
 */

require_once INC_PATH . '/RiderTaxSummary.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;
$riderName = (string) ($riderUser['name'] ?? '');

$ready = RiderTaxSummary::ready();
$years = $ready ? RiderTaxSummary::availableYears($riderId) : [];

// 기본 연도 — 기록이 있는 가장 최근 해. 없으면 올해.
$thisYear = (int) date('Y');
$year     = (int) ($_GET['year'] ?? 0);
if (!in_array($year, $years, true)) {
    $year = $years[0] ?? $thisYear;
}

$from = sprintf('%04d-01-01', $year);
$to   = sprintf('%04d-12-31', $year);

$sum = $ready
    ? RiderTaxSummary::forPeriod($riderId, $from, $to)
    : RiderTaxSummary::forPeriod(0, $from, $to);

$esc    = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$won    = static fn (int $n): string => number_format($n) . '원';
$hasAny = $sum['cycles'] > 0 || $sum['promo_gross'] > 0;

$yearUrl = static function (int $y): string {
    $u = rider_url('settlement/tax');

    return $u . (str_contains($u, '?') ? '&' : '?') . 'year=' . $y;
};

$monthKr = static fn (string $ym): string => (int) substr($ym, 5, 2) . '월';
?>

<?php if (!$ready) : ?>
<div class="card card-flush shadow-sm">
	<div class="card-body">
		<p class="text-muted fs-7 py-6 mb-0">준비 중입니다.</p>
	</div>
</div>
<?php else : ?>

<!--begin::연도 선택-->
<?php if (count($years) > 1) : ?>
<div class="d-flex flex-wrap gap-2 mb-4">
	<?php foreach ($years as $y) : ?>
	<a href="<?= $esc($yearUrl($y)) ?>"
		class="btn btn-sm px-4 <?= $y === $year ? 'btn-primary' : 'btn-light' ?>"><?= $y ?>년</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>
<!--end::연도 선택-->

<!--begin::요약-->
<div class="card card-flush shadow-sm mb-5">
	<div class="card-body">
		<div class="text-gray-500 fs-8 mb-1"><?= $year ?>년 · <?= $esc($riderName) ?></div>
		<div class="text-gray-600 fs-7 mb-2">원천징수세액</div>
		<div class="fs-2qx fw-bold text-gray-900 mb-4"><?= $won($sum['tax_withholding']) ?></div>

		<div class="separator separator-dashed mb-4"></div>

		<div class="d-flex justify-content-between fs-7 mb-2">
			<span class="text-gray-600">지급액 (공제 전)</span>
			<span class="fw-bold text-gray-900"><?= $won($sum['pay_total']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7 mb-2">
			<span class="text-gray-600">공제 합계</span>
			<span class="fw-bold text-danger">−<?= $won($sum['tax_total'] + $sum['other_total']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7">
			<span class="text-gray-800 fw-semibold">실수령액</span>
			<span class="fw-bold text-success"><?= $won($sum['net_total']) ?></span>
		</div>
	</div>
</div>
<!--end::요약-->

<?php if (!$hasAny) : ?>
<div class="card card-flush shadow-sm">
	<div class="card-body text-center py-10">
		<div class="text-gray-600 fs-7"><?= $year ?>년에 정산된 내역이 없습니다.</div>
	</div>
</div>
<?php else : ?>

<!--begin::세금·보험-->
<div class="card card-flush shadow-sm mb-5">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">세금 · 보험</h2>
		<span class="text-gray-500 fs-8">소득세 신고에 쓰는 항목입니다</span>
	</div>
	<div class="card-body pt-2">
		<div class="d-flex justify-content-between fs-7 py-2 border-bottom border-gray-200">
			<span class="text-gray-700">원천세 <span class="text-gray-500">(3.3%)</span></span>
			<span class="fw-bold text-gray-900"><?= $won($sum['tax_withholding']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7 py-2 border-bottom border-gray-200">
			<span class="text-gray-700">고용보험</span>
			<span class="fw-bold text-gray-900"><?= $won($sum['tax_employment']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7 py-2 border-bottom border-gray-200">
			<span class="text-gray-700">산재보험</span>
			<span class="fw-bold text-gray-900"><?= $won($sum['tax_accident']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7 py-3">
			<span class="text-gray-800 fw-semibold">합계</span>
			<span class="fw-bold text-danger"><?= $won($sum['tax_total']) ?></span>
		</div>
		<?php if ($sum['tax_withholding'] === 0) : ?>
		<div class="text-gray-500 fs-8 mt-1">
			원천세 대상이 아니면 0원으로 표시됩니다. 대상 여부는 대리점이 설정합니다.
		</div>
		<?php endif; ?>
	</div>
</div>
<!--end::세금·보험-->

<?php if ($sum['other'] !== []) : ?>
<!--begin::기타 공제-->
<div class="card card-flush shadow-sm mb-5">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">그 밖의 공제</h2>
		<span class="text-gray-500 fs-8">세금이 아니라 정산에서 차감된 금액입니다</span>
	</div>
	<div class="card-body pt-2">
		<?php foreach ($sum['other'] as $o) : ?>
		<div class="d-flex justify-content-between fs-7 py-2 border-bottom border-gray-200">
			<span class="text-gray-700"><?= $esc($o['label']) ?>
				<span class="text-gray-500"><?= number_format($o['count']) ?>건</span></span>
			<span class="fw-bold text-gray-900"><?= $won($o['amount']) ?></span>
		</div>
		<?php endforeach; ?>
		<div class="d-flex justify-content-between fs-7 py-3">
			<span class="text-gray-800 fw-semibold">합계</span>
			<span class="fw-bold text-danger"><?= $won($sum['other_total']) ?></span>
		</div>
	</div>
</div>
<!--end::기타 공제-->
<?php endif; ?>

<?php if ($sum['months'] !== []) : ?>
<!--begin::월별-->
<div class="card card-flush shadow-sm mb-5">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">월별 내역</h2>
	</div>
	<div class="card-body pt-2">
		<div class="table-responsive">
			<table class="table table-row-bordered align-middle fs-8 gy-2 mb-0">
				<thead>
					<tr class="text-muted fw-semibold">
						<th class="ps-0">월</th>
						<th class="text-end">지급액</th>
						<th class="text-end">원천세</th>
						<th class="text-end">보험</th>
						<th class="text-end pe-0">실수령</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($sum['months'] as $m) : ?>
					<tr>
						<td class="ps-0 fw-semibold text-gray-800 text-nowrap"><?= $esc($monthKr($m['month'])) ?></td>
						<td class="text-end text-gray-800 text-nowrap"><?= number_format($m['base']) ?></td>
						<td class="text-end text-gray-700 text-nowrap"><?= number_format($m['withholding']) ?></td>
						<td class="text-end text-gray-700 text-nowrap"><?= number_format($m['insurance']) ?></td>
						<td class="text-end pe-0 fw-bold text-success text-nowrap"><?= number_format($m['net']) ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<div class="text-gray-500 fs-8 mt-3">
			정산일 기준입니다. 프로모션은 위 요약에만 포함되고 월별 표에는 나오지 않습니다.
		</div>
	</div>
</div>
<!--end::월별-->
<?php endif; ?>

<?php if ($sum['promo_gross'] > 0) : ?>
<div class="card card-flush shadow-sm mb-5">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-5">프로모션</h2>
		<span class="text-gray-500 fs-8">정산과 같은 요율로 공제된 뒤 지급됐습니다</span>
	</div>
	<div class="card-body pt-2">
		<div class="d-flex justify-content-between fs-7 py-2 border-bottom border-gray-200">
			<span class="text-gray-700">지급액</span>
			<span class="fw-bold text-gray-900"><?= $won($sum['promo_gross']) ?></span>
		</div>
		<div class="d-flex justify-content-between fs-7 py-2">
			<span class="text-gray-700">실수령</span>
			<span class="fw-bold text-success"><?= $won($sum['promo_net']) ?></span>
		</div>
		<div class="text-gray-500 fs-8 mt-2">
			<a href="<?= $esc(rider_url('promotions')) ?>">프로모션 내역 자세히 보기</a>
		</div>
	</div>
</div>
<?php endif; ?>

<?php endif; ?>

<!--begin::안내-->
<div class="alert alert-light border border-gray-300 fs-8 text-gray-700 mb-0">
	<div class="fw-semibold text-gray-800 mb-1">이 화면은 공식 원천징수영수증이 아닙니다</div>
	<div class="mb-2">
		신고용 <strong>원천징수영수증</strong>이 필요하면 대리점에 요청하세요.
		여기 값은 우리 정산 장부 기준이라 대리점이 국세청에 신고한 금액과 다를 수 있습니다.
	</div>
	<div>
		원천세 3.3%는 소득세 3%와 지방소득세 0.3%를 합친 금액입니다.
		나눠서 표기된 값이 필요하면 대리점에 문의하세요.
	</div>
</div>
<!--end::안내-->

<?php endif; ?>

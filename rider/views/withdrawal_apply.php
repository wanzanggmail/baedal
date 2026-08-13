<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderWallet.php';
require_once INC_PATH . '/Withdrawal.php';
require_once INC_PATH . '/WithdrawalCycles.php';

$riderUser = rider_current_user();
$riderId   = $riderUser ? (int) $riderUser['id'] : 0;

// 선정산(일일지급) 라이더는 대리점이 매일 자동으로 지급하므로 앱 출금 신청 대상이 아니다.
// 신청 자체는 Withdrawal::applyForRider()가 막지만, 폼을 다 채운 뒤에 거절당하면
// 이유를 알기 어려우므로 화면 진입 시점에 안내한다.
if ($riderId > 0 && !empty($riderUser['is_daily_settlement'])) {
    ?>
	<div class="card card-flush shadow-sm">
		<div class="card-body text-center py-10">
			<i class="ki-duotone ki-check-circle fs-3x text-success mb-3"><span class="path1"></span><span class="path2"></span></i>
			<div class="fs-5 fw-bold text-gray-900 mb-2">출금 신청이 필요하지 않습니다</div>
			<div class="fs-7 text-gray-600">
				선정산(일일지급) 대상이라 정산분이 <strong>대리점에서 매일 자동으로 지급</strong>됩니다.<br />
				지급 내역은 정산 달력에서 확인하세요.
			</div>
			<a href="<?= htmlspecialchars(rider_url('settlement/calendar'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary mt-4">정산 달력 보기</a>
		</div>
	</div>
    <?php
    return;
}

$preview   = $riderId > 0 ? RiderWallet::previewWithdrawal($riderId) : [];
$hasOpen   = $riderId > 0 && Withdrawal::hasOpenRiderRequest($riderId);
$canApply  = $riderId > 0 && (bool) ($preview['can_apply'] ?? false) && !$hasOpen;

// 출금 가능한 미출금 정산일 — 달력에 표시하고, 라이더가 "어디까지" 출금할지 고른다.
$unwithdrawn = $riderId > 0 ? WithdrawalCycles::unwithdrawn($riderId) : [];
$calendarData = [];
foreach ($unwithdrawn as $c) {
    $calendarData[$c['settlement_date']] = [
        'amount'      => $c['remaining'],
        'order_count' => $c['order_count'],
    ];
}
$lastDate = $unwithdrawn !== [] ? (string) $unwithdrawn[count($unwithdrawn) - 1]['settlement_date'] : '';

$bankLabel = '—';
$bankAcct  = '—';
$bankHolder = $riderUser['name'] ?? '';
if ($riderId > 0) {
    $r = db_row(
        'SELECT r.bank_code, r.bank_account, r.account_holder, r.name, sc.label AS bank_label
         FROM riders r
         LEFT JOIN system_codes sc ON sc.category = \'bank\' AND sc.code = r.bank_code
         WHERE r.id = ? LIMIT 1',
        [$riderId]
    );
    if ($r) {
        $bankLabel  = (string) ($r['bank_label'] ?: $r['bank_code'] ?: '—');
        $acct       = (string) ($r['bank_account'] ?? '');
        $bankAcct   = $acct !== '' && strlen($acct) > 4
            ? substr($acct, 0, 3) . str_repeat('*', max(0, strlen($acct) - 5)) . substr($acct, -2)
            : '미등록';
        $bankHolder = (string) ($r['account_holder'] ?: $r['name']);
    }
}

$flashOk   = (string) ($_SESSION['rider_flash_ok'] ?? '');
$flashErr  = (string) ($_SESSION['rider_flash_error'] ?? '');
unset($_SESSION['rider_flash_ok'], $_SESSION['rider_flash_error']);

if (empty($_SESSION['rider_wd_csrf'])) {
    $_SESSION['rider_wd_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['rider_wd_csrf'];
?>
<link rel="stylesheet" href="<?= htmlspecialchars(web_asset('css/rider-settlement-calendar.css'), ENT_QUOTES, 'UTF-8') ?>" />

<div class="card card-flush shadow-sm mb-4">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">출금 신청</h2>
		<span class="text-gray-500 fs-7">달력에서 출금할 기간을 선택하세요</span>
	</div>
	<div class="card-body pt-0">
		<?php if ($flashOk !== '') : ?>
		<div class="alert alert-success fs-7 mb-4"><?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<?php if ($flashErr !== '') : ?>
		<div class="alert alert-danger fs-7 mb-4"><?= htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>

		<?php if ($unwithdrawn === []) : ?>
		<div class="alert bg-light-warning fs-7 mb-0">출금 가능한 정산 내역이 없습니다.</div>
		<?php else : ?>
		<div class="alert bg-light-primary fs-8 p-3 mb-4">
			출금은 <strong>오래된 정산분부터 순서대로</strong> 나갑니다. 달력에서 날짜를 누르면 <strong>그 날짜까지</strong> 출금 신청됩니다.
		</div>
		<?php endif; ?>
	</div>
</div>

<?php if ($unwithdrawn !== []) : ?>
<div class="card card-flush shadow-sm mb-4 rider-cal-page-card">
	<div class="card-body p-0">
		<div class="rider-cal-wrap">
			<div class="rider-cal-container">
				<div class="rider-cal-header">
					<div class="rider-cal-nav">
						<button type="button" class="rider-cal-nav-btn" id="riderPrevBtn" aria-label="이전 달">‹</button>
						<div class="rider-cal-month-year" id="riderMonthYear"></div>
						<button type="button" class="rider-cal-nav-btn" id="riderNextBtn" aria-label="다음 달">›</button>
					</div>
				</div>
				<div class="rider-cal-grid">
					<div class="rider-cal-days-header">
						<div class="rider-cal-day-header rider-cal-weekend">일</div>
						<div class="rider-cal-day-header">월</div>
						<div class="rider-cal-day-header">화</div>
						<div class="rider-cal-day-header">수</div>
						<div class="rider-cal-day-header">목</div>
						<div class="rider-cal-day-header">금</div>
						<div class="rider-cal-day-header rider-cal-saturday">토</div>
					</div>
					<div class="rider-cal-days-grid" id="riderDaysGrid"></div>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="card card-flush shadow-sm">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-center mb-3">
			<span class="fw-bold text-gray-800">출금 기간</span>
			<span class="fw-bold text-primary" id="wdPeriodLabel">전체</span>
		</div>

		<div class="mb-5 p-4 bg-light rounded" id="wdBreakdown">
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7">선택 정산액</span>
				<span class="fw-bold" id="wdConsume">₩ <?= number_format((int) ($preview['consume_amount'] ?? 0)) ?></span>
			</div>
			<div class="d-flex justify-content-between mb-2">
				<span class="text-gray-600 fs-7 fw-semibold">정산수수료</span>
				<span class="text-danger fw-semibold" id="wdFee">− ₩ <?= number_format((int) ($preview['fee_per_tx'] ?? 0)) ?></span>
			</div>
			<div class="fs-8 text-gray-600" id="wdFeeDetail"></div>
			<div class="border-top pt-3 mt-2 d-flex justify-content-between align-items-center">
				<span class="fw-bold text-gray-800">실지급 예정액</span>
				<span class="fs-3 fw-bold text-primary" id="wdPayout">₩ <?= number_format((int) ($preview['payout_amount'] ?? 0)) ?></span>
			</div>
			<?php // 보증금은 이번 출금에서 빠지는 돈이 아니라 "지갑에 남겨두는 최소 잔액"이다.
			      // 선택 가능 상한(잔액−보증금)에 이미 반영돼 있으므로 차감 항목으로 표기하지 않는다. ?>
			<div class="border-top pt-3 mt-3 fs-8 text-gray-600 d-flex justify-content-between">
				<span>지갑 잔액 ₩ <?= number_format((int) ($preview['balance'] ?? 0)) ?> · 보증금 ₩ <?= number_format((int) ($preview['reserve_amount'] ?? 0)) ?>은 지갑에 남습니다</span>
			</div>
		</div>

		<div class="mb-5">
			<label class="form-label fs-7">입금 계좌</label>
			<div class="form-control form-control-solid bg-light">
				<?= htmlspecialchars($bankLabel, ENT_QUOTES, 'UTF-8') ?>
				<?= htmlspecialchars($bankAcct, ENT_QUOTES, 'UTF-8') ?>
				· <?= htmlspecialchars($bankHolder, ENT_QUOTES, 'UTF-8') ?>
			</div>
			<div class="form-text"><a href="<?= htmlspecialchars(rider_url('profile/bank'), ENT_QUOTES, 'UTF-8') ?>">계좌 변경</a></div>
		</div>

		<form method="post" action="<?= htmlspecialchars(rider_url('withdrawal/apply'), ENT_QUOTES, 'UTF-8') ?>">
			<input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" />
			<input type="hidden" name="to_date" id="wdToDate" value="" />
			<button type="submit" class="btn btn-primary w-100" id="wdSubmitBtn"<?= $canApply ? '' : ' disabled' ?>>
				출금 신청
			</button>
		</form>
		<?php if ($hasOpen) : ?>
		<p class="text-muted fs-8 mt-3 mb-0">
			처리 중인 출금 신청이 있습니다. <a href="<?= htmlspecialchars(rider_url('withdrawal/history'), ENT_QUOTES, 'UTF-8') ?>">내역 확인</a>
		</p>
		<?php elseif (!$canApply && (bool) ($preview['blocked_by_policy'] ?? false)) : ?>
		<?php // 출금은 배달 건 단위로 나가므로, 출금가능액(잔액−보증금)이 배달 1건 값보다도
		      // 적으면 이번 회차엔 신청할 수 없다. 정산이 더 쌓이면 자동으로 풀린다. ?>
		<div class="alert bg-light-warning fs-8 mt-3 mb-0">
			출금은 <strong>배달 건 단위</strong>로 나가고 보증금 ₩ <?= number_format((int) ($preview['reserve_amount'] ?? 0)) ?>은 지갑에 남겨둡니다.
			<?php $sf = (int) ($preview['blocked_shortfall'] ?? 0); ?>
			<?php if ($sf > 0) : ?>
			지금은 배달 1건을 출금하기에 <strong>₩ <?= number_format($sf) ?></strong>이 부족합니다 — 정산이 그만큼 더 쌓이면 신청할 수 있습니다.
			<?php else : ?>
			지금은 배달 1건을 출금할 만큼 잔액이 쌓이지 않았습니다. 정산이 더 반영되면 신청할 수 있습니다.
			<?php endif; ?>
		</div>
		<?php elseif (!$canApply && $flashOk === '') : ?>
		<p class="text-muted fs-8 mt-3 mb-0">출금 가능 금액이 없거나 계좌·상태를 확인해 주세요.</p>
		<?php endif; ?>
	</div>
</div>

<script>
window.RIDER_WD_CYCLES = <?= json_encode($calendarData, JSON_UNESCAPED_UNICODE) ?>;
window.RIDER_WD_LAST_DATE = <?= json_encode($lastDate, JSON_UNESCAPED_UNICODE) ?>;
window.RIDER_WD_PREVIEW_URL = <?= json_encode(rtrim(RIDER_BASE, '/') . '/p/withdrawal_preview.php', JSON_UNESCAPED_UNICODE) ?>;
window.RIDER_WD_HAS_OPEN = <?= $hasOpen ? 'true' : 'false' ?>;
</script>
<script src="<?= htmlspecialchars(web_asset('js/rider-withdrawal-calendar.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>

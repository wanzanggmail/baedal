<?php

declare(strict_types=1);

require_once INC_PATH . '/RiderAuth.php';

$ru = rider_current_user();
$row = $ru ? RiderAuth::findById((int) $ru['id']) : null;

$bankLabels = [
    '088' => '신한은행',
    '004' => '국민은행',
    '020' => '우리은행',
    '090' => '카카오뱅크',
    '081' => '하나은행',
];

$bankCode = (string) ($row['bank_code'] ?? '');
$bankName = $bankLabels[$bankCode] ?? ($bankCode !== '' ? $bankCode : '—');
$account  = (string) ($row['bank_account'] ?? '');
$holder   = (string) ($row['account_holder'] ?? ($row['name'] ?? ''));
?>
<div class="alert alert-light d-flex fs-7 mb-5">
	<i class="ki-duotone ki-information-5 fs-2 text-primary me-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
	<div>계좌 변경은 운영센터를 통해 요청해 주세요.</div>
</div>
<div class="card card-flush shadow-sm">
	<div class="card-body">
		<div class="mb-4">
			<label class="form-label">은행</label>
			<input type="text" class="form-control form-control-solid" value="<?= htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') ?>" readonly />
		</div>
		<div class="mb-4">
			<label class="form-label">계좌번호</label>
			<input type="text" class="form-control form-control-solid font-monospace" value="<?= htmlspecialchars($account !== '' ? $account : '—', ENT_QUOTES, 'UTF-8') ?>" readonly />
		</div>
		<div class="mb-4">
			<label class="form-label">예금주</label>
			<input type="text" class="form-control form-control-solid" value="<?= htmlspecialchars($holder !== '' ? $holder : '—', ENT_QUOTES, 'UTF-8') ?>" readonly />
		</div>
	</div>
</div>

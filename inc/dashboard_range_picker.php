<?php

declare(strict_types=1);

/**
 * 대시보드 기간 선택 트리거(Metronic daterangepicker) — Website Analytics 데모의
 * data-kt-daterangepicker 위젯과 같은 스타일이지만, 전역 자동초기화(scripts.bundle.js)와
 * 충돌하지 않도록 별도 속성(data-kt-dashboard-range)으로 assets/js/admin-datepickers.js에서
 * 직접 초기화한다 — 서버가 넘긴 현재 선택 기간을 정확히 반영하고, 적용 시 from/to 쿼리로
 * 같은 페이지를 다시 불러온다.
 *
 * @var string $periodFrom
 * @var string $periodTo
 * @var string|null $rangeRoute 적용 시 이동할 라우트. 안 주면 대시보드(기존 동작).
 */
// require 는 호출부와 변수 스코프를 공유한다 — 한 페이지에서 두 번 쓸 때 앞의 값이
// 남지 않도록 읽고 바로 비운다.
$rangeBaseUrl = admin_url($rangeRoute ?? 'dashboard');
unset($rangeRoute);
// JS(daterangepicker) 초기화 전에도 화면에 기간이 비어 보이지 않도록 서버에서 먼저 텍스트를 채운다.
$rangeDisplayText = $periodFrom === $periodTo ? $periodFrom : ($periodFrom . ' ~ ' . $periodTo);
?>
<div data-kt-dashboard-range="true"
	data-kt-dashboard-range-from="<?= htmlspecialchars($periodFrom, ENT_QUOTES, 'UTF-8') ?>"
	data-kt-dashboard-range-to="<?= htmlspecialchars($periodTo, ENT_QUOTES, 'UTF-8') ?>"
	data-kt-dashboard-range-url="<?= htmlspecialchars($rangeBaseUrl, ENT_QUOTES, 'UTF-8') ?>"
	class="btn btn-sm fw-bold btn-secondary d-flex align-items-center justify-content-between px-4" role="button">
	<span class="text-gray-700 fw-bold" data-kt-dashboard-range-display><?= htmlspecialchars($rangeDisplayText, ENT_QUOTES, 'UTF-8') ?></span>
	<i class="ki-duotone ki-calendar-8 fs-2 ms-2 me-0 text-gray-600">
		<span class="path1"></span><span class="path2"></span><span class="path3"></span>
		<span class="path4"></span><span class="path5"></span><span class="path6"></span>
	</i>
</div>

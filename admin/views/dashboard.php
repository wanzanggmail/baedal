<?php

declare(strict_types=1);
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">대시보드</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">
					<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-hover-primary">홈</a>
				</li>
				<li class="breadcrumb-item">
					<span class="bullet bg-gray-500 w-5px h-2px"></span>
				</li>
				<li class="breadcrumb-item text-gray-900">요약</li>
			</ul>
		</div>
		<div class="d-flex align-items-center gap-2 gap-lg-3">
			<div class="btn btn-sm fw-bold btn-secondary d-flex align-items-center px-4">
				<span class="text-gray-700 fw-bold">2026년 5월 1일 ~ 5월 10일</span>
				<i class="ki-duotone ki-calendar-8 fs-2 ms-2 me-0 text-gray-600">
					<span class="path1"></span><span class="path2"></span><span class="path3"></span>
					<span class="path4"></span><span class="path5"></span><span class="path6"></span>
				</i>
			</div>
			<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm fw-bold btn-primary">
				<i class="ki-duotone ki-file-up fs-3"><span class="path1"></span><span class="path2"></span></i>
				정산 엑셀 업로드
			</a>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<!--begin::Row KPI-->
	<div class="row gy-5 gx-xl-10">
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-people fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span>
							<span class="path4"></span><span class="path5"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2">128</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">활성 라이더</span>
						</div>
					</div>
					<span class="badge badge-light-success fs-base">
						<i class="ki-duotone ki-arrow-up fs-5 text-success ms-n1"><span class="path1"></span><span class="path2"></span></i>+4명
					</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-wallet fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2">₩ 4.2억</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">이번 주 정산 반영 합계</span>
						</div>
					</div>
					<span class="badge badge-light-success fs-base">
						<i class="ki-duotone ki-arrow-up fs-5 text-success ms-n1"><span class="path1"></span><span class="path2"></span></i>전주 대비 3.1%
					</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-delivery fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span><span class="path3"></span>
							<span class="path4"></span><span class="path5"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2">18,420</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">이번 주 배달 건수(합산)</span>
						</div>
					</div>
					<span class="badge badge-light-success fs-base">
						<i class="ki-duotone ki-arrow-up fs-5 text-success ms-n1"><span class="path1"></span><span class="path2"></span></i>+2.4%
					</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-time fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2">14</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">출금 신청 대기</span>
						</div>
					</div>
					<span class="badge badge-light-warning fs-base">처리 필요</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-discount fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-3x text-gray-800 lh-1 ls-n2">2</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">프로모션 배치(당월)</span>
						</div>
					</div>
					<span class="badge badge-light-primary fs-base">최근: 5/8</span>
				</div>
			</div>
		</div>
		<div class="col-sm-6 col-xl-2 mb-5 mb-xl-10">
			<div class="card h-lg-100">
				<div class="card-body d-flex justify-content-between align-items-start flex-column">
					<div class="m-0">
						<i class="ki-duotone ki-minus-circle fs-2hx text-gray-600">
							<span class="path1"></span><span class="path2"></span>
						</i>
					</div>
					<div class="d-flex flex-column my-7">
						<span class="fw-semibold fs-2x text-gray-800 lh-1 ls-n2">₩ 1,280만</span>
						<div class="m-0">
							<span class="fw-semibold fs-6 text-gray-500">당월 차감·수수료 합계</span>
						</div>
					</div>
					<span class="badge badge-light-danger fs-base">
						<i class="ki-duotone ki-arrow-down fs-5 text-danger ms-n1"><span class="path1"></span><span class="path2"></span></i>전월 대비 0.8%
					</span>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row KPI-->

	<!--begin::Row 2 cols-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">플랫폼별 정산 요약</span>
						<span class="text-gray-500 pt-2 fw-semibold fs-6">샘플 데이터 · 일 단위 업로드 기준</span>
					</h3>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('stats/summary'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light">통계로 이동</a>
					</div>
				</div>
				<div class="card-body pt-5">
					<div class="mb-6">
						<div class="d-flex align-items-center mb-2">
							<span class="fw-semibold text-gray-700 fs-6 w-100px">배달의민족</span>
							<div class="flex-grow-1 mx-3">
								<div class="progress h-8px bg-light-primary">
									<div class="progress-bar bg-primary" role="progressbar" style="width: 62%" aria-valuenow="62" aria-valuemin="0" aria-valuemax="100"></div>
								</div>
							</div>
							<span class="fw-bold text-gray-800 fs-6 w-100px text-end">₩ 2.6억</span>
						</div>
						<div class="d-flex align-items-center mb-2">
							<span class="fw-semibold text-gray-700 fs-6 w-100px">쿠팡이츠</span>
							<div class="flex-grow-1 mx-3">
								<div class="progress h-8px bg-light-success">
									<div class="progress-bar bg-success" role="progressbar" style="width: 38%" aria-valuenow="38" aria-valuemin="0" aria-valuemax="100"></div>
								</div>
							</div>
							<span class="fw-bold text-gray-800 fs-6 w-100px text-end">₩ 1.6억</span>
						</div>
					</div>
					<div class="separator separator-dashed my-5"></div>
					<div class="d-flex flex-stack">
						<span class="text-gray-600 fw-semibold fs-6">합계 (샘플)</span>
						<span class="text-gray-900 fw-bold fs-4">₩ 4.2억</span>
					</div>
				</div>
			</div>
		</div>
		<div class="col-xl-6">
			<div class="card card-flush h-xl-100">
				<div class="card-header pt-7">
					<h3 class="card-title align-items-start flex-column">
						<span class="card-label fw-bold text-gray-900">오늘의 처리 현황</span>
						<span class="text-gray-500 pt-2 fw-semibold fs-6">업로드 · 출금 · 배치</span>
					</h3>
				</div>
				<div class="card-body pt-3">
					<div class="timeline-label">
						<div class="timeline-item">
							<div class="timeline-label fw-bold text-gray-800 fs-7 w-100px">09:12</div>
							<div class="timeline-badge">
								<i class="ki-duotone ki-file-added text-success fs-2"><span class="path1"></span><span class="path2"></span></i>
							</div>
							<div class="fw-semibold text-gray-700 ps-3 fs-6">배민 일일 정산서 업로드 완료 (행 842건)</div>
						</div>
						<div class="timeline-item">
							<div class="timeline-label fw-bold text-gray-800 fs-7 w-100px">10:05</div>
							<div class="timeline-badge">
								<i class="ki-duotone ki-discount text-primary fs-2"><span class="path1"></span><span class="path2"></span></i>
							</div>
							<div class="fw-semibold text-gray-700 ps-3 fs-6">프로모션 배치 실행 · 대상 118명</div>
						</div>
						<div class="timeline-item">
							<div class="timeline-label fw-bold text-gray-800 fs-7 w-100px">11:40</div>
							<div class="timeline-badge">
								<i class="ki-duotone ki-wallet text-warning fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
							</div>
							<div class="fw-semibold text-gray-700 ps-3 fs-6">출금 신청 3건 접수</div>
						</div>
						<div class="timeline-item">
							<div class="timeline-label fw-bold text-gray-800 fs-7 w-100px">14:20</div>
							<div class="timeline-badge">
								<i class="ki-duotone ki-information-2 text-danger fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
							</div>
							<div class="fw-semibold text-gray-700 ps-3 fs-6">파싱 경고 2건 · 오류 상세에서 확인 필요</div>
						</div>
					</div>
					<div class="mt-8 d-flex flex-wrap gap-2">
						<a href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-primary">업로드 이력</a>
						<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-warning">출금 목록</a>
						<a href="<?= htmlspecialchars(admin_url('settlement/parse-errors'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-light-danger">파싱 오류</a>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row-->

	<!--begin::Row table-->
	<div class="row g-5 g-xl-10 mb-5 mb-xl-10">
		<div class="col-12">
			<div class="card card-flush">
				<div class="card-header align-items-center py-5 gap-2 gap-md-5">
					<div class="card-title">
						<h3 class="fw-bold m-0">최근 정산 업로드 이력</h3>
						<span class="text-gray-500 fs-7 fw-semibold d-block mt-1">샘플 목록 · 실제 연동 시 DB에서 조회</span>
					</div>
					<div class="card-toolbar">
						<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">새 업로드</a>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered table-row-gray-300 align-middle gs-0 gy-4">
							<thead>
								<tr class="fw-bold text-muted">
									<th class="min-w-120px">일자</th>
									<th class="min-w-100px">플랫폼</th>
									<th class="min-w-120px">파일명</th>
									<th class="min-w-80px text-end">행 수</th>
									<th class="min-w-100px">상태</th>
									<th class="min-w-120px text-end">처리 시각</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><span class="text-gray-800 fw-bold">2026-05-10</span></td>
									<td><span class="badge badge-light-primary">배민</span></td>
									<td class="text-gray-700">baemin_settlement_20260510.xlsx</td>
									<td class="text-end text-gray-800">842</td>
									<td><span class="badge badge-light-success">완료</span></td>
									<td class="text-end text-muted">09:12:08</td>
								</tr>
								<tr>
									<td><span class="text-gray-800 fw-bold">2026-05-10</span></td>
									<td><span class="badge badge-light-success">쿠팡</span></td>
									<td class="text-gray-700">coupang_daily_20260510.xls</td>
									<td class="text-end text-gray-800">631</td>
									<td><span class="badge badge-light-success">완료</span></td>
									<td class="text-end text-muted">08:55:41</td>
								</tr>
								<tr>
									<td><span class="text-gray-800 fw-bold">2026-05-09</span></td>
									<td><span class="badge badge-light-primary">배민</span></td>
									<td class="text-gray-700">baemin_settlement_20260509.xlsx</td>
									<td class="text-end text-gray-800">801</td>
									<td><span class="badge badge-light-warning">경고 2건</span></td>
									<td class="text-end text-muted">10:22:15</td>
								</tr>
								<tr>
									<td><span class="text-gray-800 fw-bold">2026-05-09</span></td>
									<td><span class="badge badge-light-success">쿠팡</span></td>
									<td class="text-gray-700">coupang_daily_20260509.xls</td>
									<td class="text-end text-gray-800">598</td>
									<td><span class="badge badge-light-success">완료</span></td>
									<td class="text-end text-muted">09:01:03</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<!--end::Row-->
<?php require_once INC_PATH . '/app_content_close.php'; ?>


<?php

declare(strict_types=1);

$_adminUser         = admin_user();
$headerAdminLabel   = $_adminUser['name']    ?? '관리자';
$headerAdminRole    = $_adminUser['role']    ?? '';
$headerAdminLoginId = $_adminUser['login_id'] ?? '';
$headerAdminInitial = function_exists('mb_substr')
    ? mb_substr($headerAdminLabel, 0, 1, 'UTF-8')
    : substr($headerAdminLabel, 0, 1);

$headerWithdrawPending = 0;
$headerWithdrawAmount  = 0;
if (admin_is_logged_in()) {
    try {
        require_once INC_PATH . '/Withdrawal.php';
        $headerWithdrawSummary = Withdrawal::summary();
        $headerWithdrawPending = (int) ($headerWithdrawSummary['pending_count'] ?? 0);
        $headerWithdrawAmount  = (int) ($headerWithdrawSummary['pending_amount'] ?? 0);
    } catch (Throwable) {
    }
}
$headerWithdrawTooltip = $headerWithdrawPending > 0
    ? '출금 신청 대기 ' . number_format($headerWithdrawPending) . '건 · ' . number_format($headerWithdrawAmount) . '원'
    : '출금 신청 대기 없음';
?>
							<!--begin::Menu wrapper-->
							<div class="app-header-menu app-header-mobile-drawer align-items-stretch" data-kt-drawer="true" data-kt-drawer-name="app-header-menu" data-kt-drawer-activate="{default: true, lg: false}" data-kt-drawer-overlay="true" data-kt-drawer-width="250px" data-kt-drawer-direction="end" data-kt-drawer-toggle="#kt_app_header_menu_toggle" data-kt-swapper="true" data-kt-swapper-mode="{default: 'append', lg: 'prepend'}" data-kt-swapper-parent="{default: '#kt_app_body', lg: '#kt_app_header_wrapper'}">
								<div class="menu menu-rounded menu-column menu-lg-row my-5 my-lg-0 align-items-stretch fw-semibold px-2 px-lg-0" id="kt_app_header_menu" data-kt-menu="true">
									<div class="menu-item">
										<a class="menu-link<?= nav_active('dashboard') ?>" href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>">
											<span class="menu-title">대시보드</span>
										</a>
									</div>
									<div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start" class="menu-item menu-lg-down-accordion me-lg-1">
										<span class="menu-link<?= nav_header_menu_active('settlement/') ?>">
											<span class="menu-title">정산</span>
											<span class="menu-arrow d-lg-none"></span>
										</span>
										<div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-3 py-lg-4 w-lg-275px">
											<div class="menu-item">
												<a class="menu-link<?= nav_active('settlement/upload') ?>" href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>">
													<span class="menu-icon"><i class="ki-duotone ki-file-up fs-2"><span class="path1"></span><span class="path2"></span></i></span>
													<span class="menu-title">엑셀 업로드</span>
												</a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('settlement/history') ?>" href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>">
													<span class="menu-icon"><i class="ki-duotone ki-calendar fs-2"><span class="path1"></span><span class="path2"></span></i></span>
													<span class="menu-title">업로드 이력</span>
												</a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= (($route ?? '') === 'settlement/fees' || ($route ?? '') === 'settlement/fee-detail') ? ' active' : '' ?>" href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>">
													<span class="menu-icon"><i class="ki-duotone ki-dollar fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span>
													<span class="menu-title">정산 수수료 내역</span>
												</a>
											</div>
										</div>
									</div>
									<div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start" class="menu-item menu-lg-down-accordion me-lg-1">
										<a class="menu-link<?= nav_active('deduction/agency-fee') ?>" href="<?= htmlspecialchars(admin_url('deduction/agency-fee'), ENT_QUOTES, 'UTF-8') ?>">
											<span class="menu-title">대행수수료</span>
										</a>
									</div>
									<div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start" class="menu-item menu-lg-down-accordion me-lg-1">
										<span class="menu-link<?= nav_header_menu_active('withdrawal/') ?>">
											<span class="menu-title">출금</span>
											<span class="menu-arrow d-lg-none"></span>
										</span>
										<div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-3 py-lg-4 w-lg-250px">
											<div class="menu-item">
												<a class="menu-link<?= nav_active('withdrawal/list') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">신청 목록</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('withdrawal/download') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">다운로드</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('withdrawal/complete') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/complete'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">처리 완료</span></a>
											</div>
										</div>
									</div>
									<div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start" class="menu-item menu-lg-down-accordion me-lg-1">
										<span class="menu-link<?= nav_header_menu_active_any(['riders/', 'content/', 'system/']) ?>">
											<span class="menu-title">운영</span>
											<span class="menu-arrow d-lg-none"></span>
										</span>
										<div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-3 py-lg-4 w-lg-250px">
											<div class="menu-item">
												<a class="menu-link<?= (($route ?? '') === 'riders/list' || ($route ?? '') === 'riders/detail') ? ' active' : '' ?>" href="<?= htmlspecialchars(admin_url('riders/list'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">라이더 관리</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('content/notices') ?>" href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">공지 관리</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('content/banners') ?>" href="<?= htmlspecialchars(admin_url('content/banners'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">광고 배너</span></a>
											</div>
											<div class="separator my-2"></div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('system/admins') ?>" href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">관리자·권한</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('system/codes') ?>" href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">코드/마스터</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('system/settlement-excel') ?>" href="<?= htmlspecialchars(admin_url('system/settlement-excel'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">정산 엑셀 암호</span></a>
											</div>
											<div class="menu-item">
												<a class="menu-link<?= nav_active('system/audit') ?>" href="<?= htmlspecialchars(admin_url('system/audit'), ENT_QUOTES, 'UTF-8') ?>"><span class="menu-title">감사 로그</span></a>
											</div>
										</div>
									</div>
								</div>
							</div>
							<!--end::Menu wrapper-->
							<!--begin::Navbar-->
							<div class="app-navbar flex-shrink-0">
								<div class="app-navbar-item ms-1 ms-md-3 d-none d-lg-flex">
									<a href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-flex btn-light-danger align-items-center px-3" data-bs-toggle="tooltip" title="<?= htmlspecialchars($headerWithdrawTooltip, ENT_QUOTES, 'UTF-8') ?>">
										<i class="ki-duotone ki-wallet fs-4 text-danger me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
										<span class="fw-bold">출금 대기</span>
										<span class="badge badge-circle ms-2<?= $headerWithdrawPending > 0 ? ' badge-danger' : ' badge-light-secondary text-gray-600' ?>"><?= number_format($headerWithdrawPending) ?></span>
									</a>
								</div>
								<div class="app-navbar-item ms-1 ms-md-3 d-none d-lg-flex">
									<a href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-flex btn-light-primary align-items-center px-3">
										<i class="ki-duotone ki-file-up fs-4 text-primary me-1"><span class="path1"></span><span class="path2"></span></i>
										<span class="fw-bold">정산 업로드</span>
									</a>
								</div>
								<div class="app-navbar-item ms-1 ms-md-4">
									<a href="#" class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-35px h-35px" data-kt-menu-trigger="{default:'click', lg: 'hover'}" data-kt-menu-attach="parent" data-kt-menu-placement="bottom-end">
										<i class="ki-duotone ki-night-day theme-light-show fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span><span class="path6"></span><span class="path7"></span><span class="path8"></span><span class="path9"></span><span class="path10"></span></i>
										<i class="ki-duotone ki-moon theme-dark-show fs-2"><span class="path1"></span><span class="path2"></span></i>
									</a>
									<div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-title-gray-700 menu-icon-gray-500 menu-active-bg menu-state-color fw-semibold py-4 fs-base w-175px" data-kt-menu="true" data-kt-element="theme-mode-menu">
										<div class="menu-item px-3 my-0">
											<a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="light">
												<span class="menu-icon" data-kt-element="icon"><i class="ki-duotone ki-night-day fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span><span class="path6"></span><span class="path7"></span><span class="path8"></span><span class="path9"></span><span class="path10"></span></i></span>
												<span class="menu-title">밝게</span>
											</a>
										</div>
										<div class="menu-item px-3 my-0">
											<a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="dark">
												<span class="menu-icon" data-kt-element="icon"><i class="ki-duotone ki-moon fs-2"><span class="path1"></span><span class="path2"></span></i></span>
												<span class="menu-title">어둡게</span>
											</a>
										</div>
										<div class="menu-item px-3 my-0">
											<a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="system">
												<span class="menu-icon" data-kt-element="icon"><i class="ki-duotone ki-screen fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
												<span class="menu-title">시스템 설정</span>
											</a>
										</div>
									</div>
								</div>
								<div class="app-navbar-item ms-1 ms-md-4" id="kt_header_user_menu_toggle">
									<div class="cursor-pointer symbol symbol-35px" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-attach="parent" data-kt-menu-placement="bottom-end">
										<div class="symbol-label fs-6 fw-bold bg-primary text-inverse-primary"><?= htmlspecialchars($headerAdminInitial, ENT_QUOTES, 'UTF-8') ?></div>
									</div>
									<div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-color fw-semibold py-4 fs-6 w-275px" data-kt-menu="true">
										<div class="menu-item px-3">
											<div class="menu-content d-flex align-items-center px-3">
												<div class="symbol symbol-50px me-4">
													<div class="symbol-label fs-3 fw-bold bg-light-primary text-primary"><?= htmlspecialchars($headerAdminInitial, ENT_QUOTES, 'UTF-8') ?></div>
												</div>
												<div class="d-flex flex-column">
													<div class="fw-bold text-gray-900 fs-6"><?= htmlspecialchars($headerAdminLabel, ENT_QUOTES, 'UTF-8') ?></div>
													<span class="fw-semibold text-muted fs-7">
														<?= htmlspecialchars(admin_role_label($headerAdminRole), ENT_QUOTES, 'UTF-8') ?>
														<?php if ($headerAdminLoginId !== ''): ?>
															<span class="text-gray-400 ms-1">(<?= htmlspecialchars($headerAdminLoginId, ENT_QUOTES, 'UTF-8') ?>)</span>
														<?php endif; ?>
													</span>
												</div>
											</div>
										</div>
										<div class="separator my-2"></div>
										<div class="menu-item px-5">
											<a href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="menu-link px-5">대시보드</a>
										</div>
										<div class="menu-item px-5">
											<a href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>" class="menu-link px-5">관리자·권한</a>
										</div>
										<div class="menu-item px-5">
											<a href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>" class="menu-link px-5">코드/마스터</a>
										</div>
										<div class="separator my-2"></div>
										<div class="menu-item px-5">
											<a href="<?= htmlspecialchars(admin_logout_url(), ENT_QUOTES, 'UTF-8') ?>" class="menu-link px-5 text-danger">로그아웃</a>
										</div>
									</div>
								</div>
								<div class="app-navbar-item d-lg-none ms-2 me-n2" title="헤더 메뉴">
									<div class="btn btn-flex btn-icon btn-active-color-primary w-30px h-30px" id="kt_app_header_menu_toggle">
										<i class="ki-duotone ki-element-4 fs-1"><span class="path1"></span><span class="path2"></span></i>
									</div>
								</div>
							</div>
							<!--end::Navbar-->

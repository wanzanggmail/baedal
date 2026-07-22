<?php
declare(strict_types=1);
/** @var string $route */
$route = $route ?? '';
?>
<div class="app-sidebar-menu overflow-hidden flex-column-fluid">
							<!--begin::Menu wrapper-->
							<div id="kt_app_sidebar_menu_wrapper" class="app-sidebar-wrapper">
								<!--begin::Scroll wrapper-->
								<div id="kt_app_sidebar_menu_scroll" class="scroll-y my-5 mx-3" data-kt-scroll="true" data-kt-scroll-activate="true" data-kt-scroll-height="auto" data-kt-scroll-dependencies="#kt_app_sidebar_logo, #kt_app_sidebar_footer" data-kt-scroll-wrappers="#kt_app_sidebar_menu" data-kt-scroll-offset="5px" data-kt-scroll-save-state="true">
									<!--begin::Menu-->
									<div class="menu menu-column menu-rounded menu-sub-indention fw-semibold fs-6" id="kt_app_sidebar_menu" data-kt-menu="true" data-kt-menu-expand="false">
										<!--begin:Menu item-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('dashboard') ?>" href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-element-11 fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
														<span class="path3"></span>
														<span class="path4"></span>
													</i>
												</span>
												<span class="menu-title">대시보드</span>
											</a>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('settlement/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-file-up fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
													</i>
												</span>
												<span class="menu-title">정산 업로드</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/upload') ?>" href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">엑셀 업로드</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/history') ?>" href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">업로드 이력</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= (($route ?? '') === 'settlement/fees' || ($route ?? '') === 'settlement/fee-detail') ? ' active' : '' ?>" href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">정산 수수료 내역</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/withholding') ?>" href="<?= htmlspecialchars(admin_url('settlement/withholding'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">원천세 명세</span>
													</a>
												</div>
											</div>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('deduction/agency-fee') ?>" href="<?= htmlspecialchars(admin_url('deduction/agency-fee'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-minus-circle fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
													</i>
												</span>
												<span class="menu-title">선공제(대행 수수료)</span>
											</a>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('deduction/advance') ?>" href="<?= htmlspecialchars(admin_url('deduction/advance'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-dollar fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
														<span class="path3"></span>
													</i>
												</span>
												<span class="menu-title">선지급(대여금)</span>
											</a>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('withdrawal/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-wallet fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
														<span class="path3"></span>
														<span class="path4"></span>
													</i>
												</span>
												<span class="menu-title">출금</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/list') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">출금 신청 목록</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/download') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">다운로드</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/complete') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/complete'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">처리 완료</span>
													</a>
												</div>
												<?php if (admin_org_level() === Org::LEVEL_AGENCY) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/daily-payout') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/daily-payout'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">일일정산 지급</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/agency-payout') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/agency-payout'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">대리점 자체 인출</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/payment-setup') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/payment-setup'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">결제 설정(카드·계좌)</span>
													</a>
												</div>
												<?php endif; ?>
											</div>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('content/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-picture fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
													</i>
												</span>
												<span class="menu-title">콘텐츠</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('content/notices') ?>" href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">공지 관리</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('content/banners') ?>" href="<?= htmlspecialchars(admin_url('content/banners'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">광고 배너</span>
													</a>
												</div>
											</div>
										</div>
										<!--end:Menu item-->
										<!--begin:Menu item-->
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('riders/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-people fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
														<span class="path3"></span>
														<span class="path4"></span>
														<span class="path5"></span>
													</i>
												</span>
												<span class="menu-title">라이더</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= (($route ?? '') === 'riders/list' || ($route ?? '') === 'riders/detail') ? ' active' : '' ?>" href="<?= htmlspecialchars(admin_url('riders/list'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">라이더 관리</span>
													</a>
												</div>
											</div>
										</div>
										<!--end:Menu item-->
										<?php if (admin_can_manage_team()) : ?>
										<!--begin:Menu item-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('system/team') ?>" href="<?= htmlspecialchars(admin_url('system/team'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-shield-tick fs-2"><span class="path1"></span><span class="path2"></span></i>
												</span>
												<span class="menu-title">대표·서브계정</span>
											</a>
										</div>
										<!--end:Menu item-->
										<?php endif; ?>
										<!--begin:Menu item-->
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('system/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-setting-2 fs-2">
														<span class="path1"></span>
														<span class="path2"></span>
													</i>
												</span>
												<span class="menu-title">시스템 관리</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<?php if (admin_can_manage_orgs()) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/orgs') ?>" href="<?= htmlspecialchars(admin_url('system/orgs'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">조직 관리(총판·대리점)</span>
													</a>
												</div>
												<?php endif; ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/admins') ?>" href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">관리자 계정·권한</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/codes') ?>" href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">코드/마스터</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/settlement-excel') ?>" href="<?= htmlspecialchars(admin_url('system/settlement-excel'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">정산 엑셀 암호</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/audit') ?>" href="<?= htmlspecialchars(admin_url('system/audit'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">감사 로그</span>
													</a>
												</div>
												<?php if (admin_has_role('super')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/pg-fee') ?>" href="<?= htmlspecialchars(admin_url('system/pg-fee'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">영업대행수수료 분배</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/manual-adjust') ?>" href="<?= htmlspecialchars(admin_url('system/manual-adjust'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet">
															<span class="bullet bullet-dot"></span>
														</span>
														<span class="menu-title">정산/잔액 수동 조정</span>
													</a>
												</div>
												<?php endif; ?>
											</div>
										</div>
										<!--end:Menu item-->
									</div>
									<!--end::Menu-->
								</div>
								<!--end::Scroll wrapper-->
							</div>
							<!--end::Menu wrapper-->
						</div>

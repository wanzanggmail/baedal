<?php
declare(strict_types=1);
/** @var string $route */
$route = $route ?? '';

/**
 * 메뉴 구조 (2026-08-13 재편 — REF_MENU_STRUCTURE.md)
 *
 *   대시보드 / 매뉴얼
 *   정산        업로드·조회      (settlement/*)
 *   수수료·채권 돈이 어디로 갔나 (settlement/fees·platform-fee, deduction/lease-fees·debts)
 *   지급·출금   실제 지급 작업   (withdrawal/*, promotion)
 *   콘텐츠 / 라이더
 *   설정        조직 단위 설정   (흩어져 있던 5곳을 모음)
 *   시스템 관리 본사 전용
 *
 * ⚠️ 「정산」과 「수수료·채권」은 둘 다 `settlement/`로 시작해서 접두사로 구분되지 않는다.
 *    아코디언 열림 판정은 `nav_accordion_show_any()`에 라우트를 직접 나열한다.
 */
?>
<div class="app-sidebar-menu overflow-hidden flex-column-fluid">
							<!--begin::Menu wrapper-->
							<div id="kt_app_sidebar_menu_wrapper" class="app-sidebar-wrapper">
								<!--begin::Scroll wrapper-->
								<div id="kt_app_sidebar_menu_scroll" class="scroll-y my-5 mx-3" data-kt-scroll="true" data-kt-scroll-activate="true" data-kt-scroll-height="auto" data-kt-scroll-dependencies="#kt_app_sidebar_logo, #kt_app_sidebar_footer" data-kt-scroll-wrappers="#kt_app_sidebar_menu" data-kt-scroll-offset="5px" data-kt-scroll-save-state="true">
									<!--begin::Menu-->
									<div class="menu menu-column menu-rounded menu-sub-indention fw-semibold fs-6" id="kt_app_sidebar_menu" data-kt-menu="true" data-kt-menu-expand="false">

										<!--begin:대시보드-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('dashboard') ?>" href="<?= htmlspecialchars(admin_url('dashboard'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-element-11 fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
												</span>
												<span class="menu-title">대시보드</span>
											</a>
										</div>
										<!--end:대시보드-->

										<!--begin:매뉴얼-->
										<div class="menu-item">
											<a class="menu-link<?= nav_active('docs/manual') ?>" href="<?= htmlspecialchars(admin_url('docs/manual'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-book fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
												</span>
												<span class="menu-title">매뉴얼</span>
											</a>
										</div>
										<!--end:매뉴얼-->

										<!--begin:정산-->
										<?php if (admin_can_access_route('settlement/upload')) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show_any([
										    'settlement/upload', 'settlement/upload-detail', 'settlement/history',
										    'settlement/order-details', 'settlement/fee-report', 'settlement/withholding',
										]) ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-file-up fs-2"><span class="path1"></span><span class="path2"></span></i>
												</span>
												<span class="menu-title">정산</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active_any(['settlement/upload', 'settlement/upload-detail']) ?>" href="<?= htmlspecialchars(admin_url('settlement/upload'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">엑셀 업로드</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/history') ?>" href="<?= htmlspecialchars(admin_url('settlement/history'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">업로드 이력</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/order-details') ?>" href="<?= htmlspecialchars(admin_url('settlement/order-details'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">오더별 상세 내역</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/fee-report') ?>" href="<?= htmlspecialchars(admin_url('settlement/fee-report'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">정산 내역·차감 통합</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/withholding') ?>" href="<?= htmlspecialchars(admin_url('settlement/withholding'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">원천세 명세</span>
													</a>
												</div>
											</div>
										</div>
										<?php endif; ?>
										<!--end:정산-->

										<!--begin:수수료·채권-->
										<?php if (admin_can_access_route('settlement/fees') || admin_can_access_route('deduction/debts')) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show_any([
										    'settlement/fees', 'settlement/fee-detail', 'settlement/platform-fee',
										    'deduction/lease-fees', 'deduction/debts',
										]) ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-chart-pie-simple fs-2"><span class="path1"></span><span class="path2"></span></i>
												</span>
												<span class="menu-title">수수료·채권</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<?php if (admin_can_access_route('settlement/fees')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active_any(['settlement/fees', 'settlement/fee-detail']) ?>" href="<?= htmlspecialchars(admin_url('settlement/fees'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">출금 수수료 내역</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('settlement/platform-fee') ?>" href="<?= htmlspecialchars(admin_url('settlement/platform-fee'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">플랫폼 수수료 내역</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('deduction/lease-fees')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('deduction/lease-fees') ?>" href="<?= htmlspecialchars(admin_url('deduction/lease-fees'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">리스 수수료 배분</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('deduction/debts')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('deduction/debts') ?>" href="<?= htmlspecialchars(admin_url('deduction/debts'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">미수금 원장</span>
													</a>
												</div>
												<?php endif; ?>
											</div>
										</div>
										<?php endif; ?>
										<!--end:수수료·채권-->

										<!--begin:지급·출금-->
										<?php if (admin_can_access_route('withdrawal/list')) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show_any([
										    'withdrawal/list', 'withdrawal/proxy', 'withdrawal/daily-payout', 'withdrawal/agency-payout',
										    'withdrawal/wallet-ledger', 'withdrawal/download', 'withdrawal/complete',
										    'promotion', 'promotion/detail', 'promotion/calculator',
										]) ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-wallet fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
												</span>
												<span class="menu-title">지급·출금</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/list') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/list'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">출금 신청 목록</span>
													</a>
												</div>
												<?php // 화면·API 모두 대리점 전용이라 레벨로 직접 판단한다. admin_can_access_route()는
											      // super를 무조건 통과시켜서, 본사에 "대리점 계정만" 안내만 뜨는 빈 메뉴가 생긴다. ?>
												<?php if (admin_org_level() === Org::LEVEL_AGENCY) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/proxy') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/proxy'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">출금 대행</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/daily-payout') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/daily-payout'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">일일정산 지급</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/agency-payout') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/agency-payout'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">대리점 자체 인출</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('promotion')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active_any(['promotion', 'promotion/detail']) ?>" href="<?= htmlspecialchars(admin_url('promotion'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">프로모션 지급</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('promotion/calculator') ?>" href="<?= htmlspecialchars(admin_url('promotion/calculator'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">프로모션 계산기</span>
													</a>
												</div>
												<?php endif; ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/wallet-ledger') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/wallet-ledger'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">지갑 입출금</span>
													</a>
												</div>
												<?php // 펌뱅킹 즉시이체로 전환된 뒤 남은 구 경로 — 장애 대비 백업이라 [백업]으로 표기하고
												      // 최고관리자에게만 보인다(2026-08-15, auth.php에서 접근도 함께 막음). ?>
												<?php if (admin_has_role('super')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/download') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/download'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title text-muted">[백업] 이체파일 다운로드</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/complete') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/complete'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title text-muted">[백업] 처리 완료</span>
													</a>
												</div>
												<?php endif; ?>
											</div>
										</div>
										<?php endif; ?>
										<!--end:지급·출금-->

										<!--begin:콘텐츠-->
										<?php if (admin_can_access_route('content/notices')) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show('content/') ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-picture fs-2"><span class="path1"></span><span class="path2"></span></i>
												</span>
												<span class="menu-title">콘텐츠</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('content/notices') ?>" href="<?= htmlspecialchars(admin_url('content/notices'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">공지 관리</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('content/banners') ?>" href="<?= htmlspecialchars(admin_url('content/banners'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">광고 배너</span>
													</a>
												</div>
											</div>
										</div>
										<?php endif; ?>
										<!--end:콘텐츠-->

										<!--begin:라이더-->
										<?php if (admin_can_access_route('riders/list')) : ?>
										<div class="menu-item">
											<a class="menu-link<?= nav_active_any(['riders/list', 'riders/detail']) ?>" href="<?= htmlspecialchars(admin_url('riders/list'), ENT_QUOTES, 'UTF-8') ?>">
												<span class="menu-icon">
													<i class="ki-duotone ki-people fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
												</span>
												<span class="menu-title">라이더 관리</span>
											</a>
										</div>
										<?php endif; ?>
										<!--end:라이더-->

										<!--begin:설정 — 흩어져 있던 조직 단위 설정을 한곳에 모음-->
										<?php
										$settingRoutes = array_values(array_filter([
										    'system/team'              => admin_can_access_route('system/team'),
										    'deduction/agency-fee'     => admin_can_access_route('deduction/agency-fee'),
										    'withdrawal/payment-setup' => admin_can_access_route('withdrawal/payment-setup'),
										    'system/settlement-excel'  => admin_can_access_route('system/settlement-excel'),
										]));
										$hasSettings = $settingRoutes !== [];
										?>
										<?php if ($hasSettings) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show_any([
										    'system/team', 'deduction/agency-fee',
										    'withdrawal/payment-setup', 'system/settlement-excel',
										]) ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-setting-3 fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
												</span>
												<span class="menu-title">설정</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<?php if (admin_can_access_route('system/team')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/team') ?>" href="<?= htmlspecialchars(admin_url('system/team'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">조직 정보·계정</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('deduction/agency-fee')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('deduction/agency-fee') ?>" href="<?= htmlspecialchars(admin_url('deduction/agency-fee'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">수수료 설정</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('withdrawal/payment-setup')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/payment-setup') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/payment-setup'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">결제 설정(카드·계좌)</span>
													</a>
												</div>
												<?php endif; ?>
												<?php if (admin_can_access_route('system/settlement-excel')) : ?>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/settlement-excel') ?>" href="<?= htmlspecialchars(admin_url('system/settlement-excel'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">정산 엑셀 암호</span>
													</a>
												</div>
												<?php endif; ?>
											</div>
										</div>
										<?php endif; ?>
										<!--end:설정-->

										<!--begin:시스템 관리(본사 전용)-->
										<?php if (admin_has_role('super')) : ?>
										<div data-kt-menu-trigger="click" class="menu-item menu-accordion<?= nav_accordion_show_any([
										    'system/orgs', 'system/admins', 'system/permissions', 'system/codes',
										    'system/audit', 'withdrawal/settings', 'system/pg-fee', 'system/pg-integration', 'system/pg-logs',
										    'system/firm-integration', 'system/integration-mode', 'system/manual-adjust',
										]) ?>">
											<span class="menu-link">
												<span class="menu-icon">
													<i class="ki-duotone ki-setting-2 fs-2"><span class="path1"></span><span class="path2"></span></i>
												</span>
												<span class="menu-title">시스템 관리</span>
												<span class="menu-arrow"></span>
											</span>
											<div class="menu-sub menu-sub-accordion">
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/orgs') ?>" href="<?= htmlspecialchars(admin_url('system/orgs'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">조직 관리(총판·대리점)</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/admins') ?>" href="<?= htmlspecialchars(admin_url('system/admins'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">관리자 계정·권한</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/permissions') ?>" href="<?= htmlspecialchars(admin_url('system/permissions'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">권한 관리</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('withdrawal/settings') ?>" href="<?= htmlspecialchars(admin_url('withdrawal/settings'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">수수료 설정(관리)</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/pg-fee') ?>" href="<?= htmlspecialchars(admin_url('system/pg-fee'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">수수료 현황</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/pg-integration') ?>" href="<?= htmlspecialchars(admin_url('system/pg-integration'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">PG 연동·결제통지</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/pg-logs') ?>" href="<?= htmlspecialchars(admin_url('system/pg-logs'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">PG 결제 이력</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/firm-integration') ?>" href="<?= htmlspecialchars(admin_url('system/firm-integration'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">펌뱅킹 연동</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/integration-mode') ?>" href="<?= htmlspecialchars(admin_url('system/integration-mode'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">연동 모드</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/manual-adjust') ?>" href="<?= htmlspecialchars(admin_url('system/manual-adjust'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">정산/잔액 수동 조정</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/codes') ?>" href="<?= htmlspecialchars(admin_url('system/codes'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">코드/마스터</span>
													</a>
												</div>
												<div class="menu-item">
													<a class="menu-link<?= nav_active('system/audit') ?>" href="<?= htmlspecialchars(admin_url('system/audit'), ENT_QUOTES, 'UTF-8') ?>">
														<span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
														<span class="menu-title">감사 로그</span>
													</a>
												</div>
											</div>
										</div>
										<?php endif; ?>
										<!--end:시스템 관리-->

									</div>
									<!--end::Menu-->
								</div>
								<!--end::Scroll wrapper-->
							</div>
							<!--end::Menu wrapper-->
						</div>

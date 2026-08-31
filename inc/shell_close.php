						</div>
						<!--end::Content wrapper-->
					</div>
					<!--end::Main-->
				</div>
				<!--end::Wrapper-->
			</div>
			<!--end::Page-->
		</div>
		<!--end::App-->
		<!--begin::Javascript-->
		<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
		<?php // 공용 스크립트가 API 파일 경로를 만들 때 쓴다.
		     //    admin_url() 은 index.php?route=… 라우터 URL 이라 API 파일에 닿지 않는다. ?>
		<script>window.ADMIN_BASE_URL = <?= json_encode(ADMIN_BASE, JSON_UNESCAPED_SLASHES) ?>;</script>
		<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<script src="<?= htmlspecialchars(web_asset('js/admin-datepickers.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<script src="<?= htmlspecialchars(web_asset('js/account-verify.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<script src="<?= htmlspecialchars(web_asset('js/org-scope-picker.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
		<!--end::Javascript-->
	</body>
</html>

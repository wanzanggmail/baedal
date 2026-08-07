<?php

declare(strict_types=1);

?>
		</main>
	</div>
	<?php if (empty($riderMinimalShell)) : ?>
	<?php require INC_PATH . '/rider_tabbar.php'; ?>
	<?php endif; ?>
	<script>var hostUrl = "<?= htmlspecialchars(web_assets_base() . '/', ENT_QUOTES, 'UTF-8') ?>";</script>
	<script src="<?= htmlspecialchars(web_asset('plugins/global/plugins.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
	<script src="<?= htmlspecialchars(web_asset('js/scripts.bundle.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>

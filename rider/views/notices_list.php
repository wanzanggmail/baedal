<?php

declare(strict_types=1);

require_once INC_PATH . '/Notice.php';

$listError = null;
$notices   = [];

try {
    $notices = Notice::listPublishedForRider();
} catch (Throwable $e) {
    $listError = $e->getMessage();
}
?>
<div class="card card-flush shadow-sm">
	<div class="card-header border-0 pt-5">
		<h2 class="card-title fw-bold fs-4">공지</h2>
	</div>
	<div class="card-body pt-0">
		<?php if ($listError !== null) : ?>
		<div class="alert alert-warning mb-0">공지를 불러올 수 없습니다.</div>
		<?php elseif ($notices === []) : ?>
		<p class="text-muted fs-7 mb-0">등록된 공지가 없습니다.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-2">
			<?php foreach ($notices as $n) : ?>
			<a href="<?= htmlspecialchars(rider_notice_detail_url((int) $n['id']), ENT_QUOTES, 'UTF-8') ?>"
				class="d-block border border-gray-200 rounded p-4 text-gray-800 text-hover-primary">
				<div class="d-flex justify-content-between mb-1">
					<span class="badge badge-light-<?= htmlspecialchars($n['category_class'], ENT_QUOTES, 'UTF-8') ?> fs-9"><?= htmlspecialchars($n['category'], ENT_QUOTES, 'UTF-8') ?></span>
					<span class="fs-8 text-muted"><?= htmlspecialchars($n['published_date'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
				</div>
				<div class="fw-bold"><?= htmlspecialchars($n['title'], ENT_QUOTES, 'UTF-8') ?></div>
				<?php if ($n['pinned']) : ?>
				<span class="badge badge-light-success fs-9 mt-2">고정</span>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>

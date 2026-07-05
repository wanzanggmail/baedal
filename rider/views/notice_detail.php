<?php

declare(strict_types=1);

require_once INC_PATH . '/Notice.php';

$noticeId = Notice::parseId($_GET['id'] ?? null);
if ($noticeId === null) {
    header('Location: ' . rider_url('notices'), true, 302);
    exit;
}
$notice = Notice::findForRider($noticeId, rider_current_agency_id());
?>
<div class="card card-flush shadow-sm">
	<div class="card-body">
		<?php if ($notice === null) : ?>
		<div class="alert alert-warning mb-0">공지를 찾을 수 없거나 비공개 상태입니다.</div>
		<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light w-100 mt-6">목록으로</a>
		<?php else : ?>
		<div class="d-flex flex-wrap gap-2 mb-3">
			<span class="badge badge-light-<?= htmlspecialchars($notice['category_class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($notice['category'], ENT_QUOTES, 'UTF-8') ?></span>
			<?php if ($notice['pinned']) : ?>
			<span class="badge badge-light-success">고정</span>
			<?php endif; ?>
			<span class="fs-8 text-muted"><?= htmlspecialchars($notice['published_at'] ?: '', ENT_QUOTES, 'UTF-8') ?></span>
		</div>
		<h2 class="fs-3 fw-bold text-gray-900 mb-4"><?= htmlspecialchars($notice['title'], ENT_QUOTES, 'UTF-8') ?></h2>
		<div class="fs-6 text-gray-800 lh-lg notice-body">
			<?= nl2br(htmlspecialchars($notice['body'], ENT_QUOTES, 'UTF-8')) ?>
		</div>
		<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light w-100 mt-6">목록으로</a>
		<?php endif; ?>
	</div>
</div>

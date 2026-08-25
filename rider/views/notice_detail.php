<?php

declare(strict_types=1);

require_once INC_PATH . '/Notice.php';

$noticeId = Notice::parseId($_GET['id'] ?? null);

// ⚠️ 여기서 `header('Location: …')` 를 부르면 안 된다.
//    이 뷰는 레이아웃(헤더·드로어 메뉴)이 **이미 출력된 뒤** include 되므로
//    "Cannot modify header information — headers already sent" 경고가 화면에
//    그대로 찍힌다. 서버 절대경로까지 노출된다.
//    id 가 없거나 잘못된 경우는 아래 "찾을 수 없음" 화면으로 안내한다(이미 있는 분기다).
$notice = $noticeId === null
    ? null
    : Notice::findForRider($noticeId, rider_current_agency_id());
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
		<?php // 본문은 관리자(CKEditor)가 작성한 HTML이다 — 그대로 렌더링한다(작성 권한은 콘텐츠 쓰기 권한이 있는 관리자로 제한됨). ?>
		<div class="fs-6 text-gray-800 lh-lg notice-body"><?= $notice['body'] ?></div>
		<a href="<?= htmlspecialchars(rider_url('notices'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-light w-100 mt-6">목록으로</a>
		<?php endif; ?>
	</div>
</div>

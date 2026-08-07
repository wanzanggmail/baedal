<?php

declare(strict_types=1);

require_once INC_PATH . '/ManualDocs.php';

$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$q = trim((string) ($_GET['q'] ?? ''));
$results = $q !== '' ? ManualDocs::search($q, 'rider') : [];
$html = ManualDocs::renderHtml('rider');
$pageUrl = rider_url('manual');
?>
<style>
	.manual-body h2 { font-size: 1.15rem; font-weight: 700; margin-top: 1.75rem; padding-top: .5rem; border-top: 1px solid var(--bs-gray-200); }
	.manual-body h2:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
	.manual-body h3 { font-size: 1.02rem; font-weight: 700; margin-top: 1.25rem; }
	.manual-body h4 { font-size: .95rem; font-weight: 700; margin-top: 1rem; }
	.manual-body p, .manual-body li { font-size: .9rem; line-height: 1.65; color: var(--bs-gray-800); }
	.manual-body ul, .manual-body ol { padding-left: 1.25rem; }
	.manual-body code { background: var(--bs-gray-100); border-radius: 4px; padding: 1px 5px; font-size: .85em; }
	.manual-body pre { background: var(--bs-gray-100); border-radius: 8px; padding: .85rem; overflow-x: auto; }
	.manual-body pre code { background: transparent; padding: 0; }
	.manual-body table { width: 100%; border-collapse: collapse; font-size: .82rem; margin: .75rem 0; display: block; overflow-x: auto; }
	.manual-body th, .manual-body td { border: 1px solid var(--bs-gray-300); padding: .4rem .6rem; text-align: left; }
	.manual-body th { background: var(--bs-gray-100); font-weight: 700; }
	.manual-body hr { border-top: 1px solid var(--bs-gray-300); margin: 1.25rem 0; }
</style>

<div class="card card-flush shadow-sm mb-4">
	<div class="card-body py-5">
		<h2 class="card-title fw-bold fs-4 mb-4">이용 안내</h2>
		<form method="get" action="<?= $esc($pageUrl) ?>" class="d-flex gap-2">
			<?php if (defined('RIDER_USE_QUERY_URL') && RIDER_USE_QUERY_URL) : ?>
			<input type="hidden" name="route" value="manual" />
			<?php endif; ?>
			<input type="text" name="q" class="form-control form-control-solid" placeholder="궁금한 내용을 검색해 보세요" value="<?= $esc($q) ?>" />
			<button type="submit" class="btn btn-primary flex-shrink-0">검색</button>
		</form>
	</div>
</div>

<?php if ($q !== '') : ?>
<div class="card card-flush shadow-sm mb-4">
	<div class="card-body py-4">
		<div class="d-flex justify-content-between align-items-center mb-3">
			<span class="fs-7 text-muted">"<?= $esc($q) ?>" 검색 결과 <?= count($results) ?>건</span>
			<a href="<?= $esc($pageUrl) ?>" class="fs-8">전체 내용 보기</a>
		</div>
		<?php if ($results === []) : ?>
		<p class="text-muted fs-7 mb-0">검색 결과가 없습니다. 다른 단어로 시도해 보세요.</p>
		<?php else : ?>
		<div class="d-flex flex-column gap-2">
			<?php foreach ($results as $r) : ?>
			<a href="<?= $esc($pageUrl . '#' . $r['anchor']) ?>" class="d-block border border-gray-200 rounded p-3 text-gray-800 text-hover-primary">
				<div class="fw-bold fs-7 mb-1"><?= $esc((string) $r['title']) ?></div>
				<?php if ($r['snippet'] !== '') : ?>
				<div class="fs-8 text-gray-600"><?= $esc((string) $r['snippet']) ?></div>
				<?php endif; ?>
			</a>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<div class="card card-flush shadow-sm">
	<div class="card-body py-5 manual-body">
		<?= $html !== '' ? $html : '<p class="text-muted mb-0">이용 안내를 불러올 수 없습니다.</p>' ?>
	</div>
</div>

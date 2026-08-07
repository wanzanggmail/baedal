<?php

declare(strict_types=1);

/**
 * 라이더 앱 하단 탭바 — 엄지로 닿는 위치에 핵심 4개 화면을 항상 노출한다.
 * (햄버거 드로어만으로는 모바일에서 이동이 번거로워 2026-08-08 추가)
 *
 * @var string $riderRoute
 */
$riderRoute = $riderRoute ?? '';

/** 탭: [라우트, 라벨, 아이콘, path 개수, 이 탭으로 볼 라우트 접두사들] */
$riderTabs = [
    ['home',              '홈',    'ki-element-11', 4, ['home']],
    ['settlement/fees',   '정산',  'ki-chart-simple', 4, ['settlement/', 'promotions']],
    ['withdrawal/apply',  '출금',  'ki-wallet', 4, ['withdrawal/']],
    ['profile',           '내정보', 'ki-user', 2, ['profile', 'profile/', 'notices']],
];

$riderTabActive = static function (array $prefixes) use ($riderRoute): bool {
    foreach ($prefixes as $p) {
        if ($riderRoute === $p || ($p !== '' && str_ends_with($p, '/') && str_starts_with($riderRoute, $p))) {
            return true;
        }
    }

    return false;
};
?>
<nav class="rider-tabbar" aria-label="주요 메뉴">
	<?php foreach ($riderTabs as [$route, $label, $icon, $paths, $prefixes]) : ?>
	<a href="<?= htmlspecialchars(rider_url($route), ENT_QUOTES, 'UTF-8') ?>"
		class="rider-tabbar-item<?= $riderTabActive($prefixes) ? ' active' : '' ?>">
		<i class="ki-duotone <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"><?php
		    for ($i = 1; $i <= $paths; $i++) {
		        echo '<span class="path' . $i . '"></span>';
		    }
		?></i>
		<span class="rider-tabbar-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
	</a>
	<?php endforeach; ?>
</nav>

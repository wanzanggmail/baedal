<?php

declare(strict_types=1);

/**
 * 배민 피크 구간 판정 자가검사 — DB 없이 돈다.
 *
 *   php tools/test_baemin_peak.php
 *
 * 배민 정산서에는 「피크타임」 열이 없어 **주문시각으로 구간을 유도**한다. 경계(평일 12:59 vs
 * 주말 13:59)를 한 칸만 틀려도 라이더 명세서의 구간 건수가 통째로 밀린다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/XlsxParser.php';

$m = new ReflectionMethod(XlsxParser::class, 'baeminPeak');
$m->setAccessible(true);
$peak = static fn (?string $at, string $run): string => $m->invoke(null, $at, $run);

$WEEKDAY = '2026-09-21';  // 월
$SAT     = '2026-09-26';  // 토
$SUN     = '2026-09-27';  // 일

$cases = [
    // [주문시각, 운행일, 기대 구간, 설명]
    ['2026-09-21 09:00:00', $WEEKDAY, '아침점심피크', '평일 시작 경계'],
    ['2026-09-21 12:59:59', $WEEKDAY, '아침점심피크', '평일 끝 경계'],
    ['2026-09-21 13:00:00', $WEEKDAY, '오후논피크',   '평일 오후 시작'],
    ['2026-09-26 13:00:00', $SAT,     '아침점심피크', '주말은 13시도 아침점심'],
    ['2026-09-26 13:59:59', $SAT,     '아침점심피크', '주말 끝 경계'],
    ['2026-09-27 14:00:00', $SUN,     '오후논피크',   '주말 오후 시작'],
    ['2026-09-21 16:59:59', $WEEKDAY, '오후논피크',   '오후 끝 경계'],
    ['2026-09-21 17:00:00', $WEEKDAY, '저녁피크',     '저녁 시작'],
    ['2026-09-26 19:59:59', $SAT,     '저녁피크',     '저녁은 요일 무관'],
    ['2026-09-21 20:00:00', $WEEKDAY, '심야논피크',   '심야 시작'],
    ['2026-09-21 23:59:59', $WEEKDAY, '심야논피크',   '심야 끝'],
    // 배민 표에 없는 시간대 — 억지로 어딘가에 넣지 않는다
    ['2026-09-21 08:59:59', $WEEKDAY, '',             '09시 이전은 구간 없음'],
    ['2026-09-21 00:00:00', $WEEKDAY, '',             '자정도 구간 없음'],
    ['2026-09-21 06:31:00', $WEEKDAY, '',             '실데이터에 흔한 새벽 주문'],
];

foreach ($cases as [$at, $run, $want, $label]) {
    $got = $peak($at, $run);
    assert($got === $want, sprintf('%s — 기대 "%s" / 실제 "%s" (%s)', $label, $want, $got, $at));
    printf("  [OK] %-22s %s → %s\n", $label, substr($at, 11, 5), $want === '' ? '(구간 없음)' : $want);
}

// 주문시각이 없으면 판정하지 않는다(빈 값이 집계에서 제외된다).
assert($peak(null, $WEEKDAY) === '', '주문시각 없음');
assert($peak('', $WEEKDAY) === '', '빈 문자열');
assert($peak('말도안되는값', $WEEKDAY) === '', '파싱 불가');
echo "  [OK] 주문시각 없음/이상값 — 구간 없음\n\n모두 통과\n";

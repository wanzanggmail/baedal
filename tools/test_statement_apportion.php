<?php

declare(strict_types=1);

/**
 * 명세서 수수료 안분 자가검사 — DB 없이 돈다.
 *
 *   php tools/test_statement_apportion.php
 *
 * 출금 1건의 수수료를 정산일들에 건수대로 나눌 때 **합이 원금과 어긋나면** 명세서의
 * 「차감 후 금액」 합계가 실수령액과 안 맞는다. 잔돈 처리가 그래서 중요하다.
 */

// private 메서드를 시험 대상으로 삼는다 — 로직만 보면 되므로 DB 를 붙이지 않는다.
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/RiderStatement.php';

$m = new ReflectionMethod(RiderStatement::class, 'apportion');
$m->setAccessible(true);
$apportion = static fn (int $amount, array $weights): array => $m->invoke(null, $amount, $weights);

$cases = [
    [600, ['2026-09-23' => 4],                        '한 날짜면 전액'],
    [600, ['09-01' => 2, '09-02' => 2],               '반반'],
    [601, ['09-01' => 1, '09-02' => 1, '09-03' => 1], '나누어 떨어지지 않는 잔돈'],
    [330, ['09-01' => 7, '09-02' => 3],               '가중치가 다른 경우'],
    [0,   ['09-01' => 5],                             '0원'],
];

foreach ($cases as [$amount, $weights, $label]) {
    $got = $apportion($amount, $weights);
    assert(array_sum($got) === $amount, "합계 불일치: {$label}");
    assert(count($got) === count($weights), "날짜 수 불일치: {$label}");
    foreach ($got as $v) {
        assert($v >= 0, "음수 배분: {$label}");
    }
    echo "  [OK] {$label} — " . json_encode($got) . "\n";
}

// 가중치가 없으면 배분하지 않는다(0 으로 나누지 않는다).
assert($apportion(500, []) === [], '빈 가중치');
assert($apportion(500, ['09-01' => 0]) === [], '가중치 합 0');
echo "  [OK] 가중치 없음 — 빈 배열\n\n모두 통과\n";

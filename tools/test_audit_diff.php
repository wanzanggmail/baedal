<?php

declare(strict_types=1);

/**
 * 감사로그 변경 비교 자가검사 — DB 없이 돈다.
 *
 *   php tools/test_audit_diff.php
 *
 * 설정 화면의 «무엇이 무엇으로 바뀌었나» 는 이 비교가 전부다. 0/"0"/false 가 뒤섞여 들어와도
 * «안 바뀐 걸 바뀌었다»고 하면 감사로그가 소음이 된다.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once INC_PATH . '/AuditLog.php';

$labels = ['transfer_fee_payer' => '이체수수료 부담', 'transfer_fee' => '이체수수료', 'memo' => '메모'];

// ① 실제로 바뀐 것만 잡는다
$d = AuditLog::diff(
    ['transfer_fee_payer' => 'rider', 'transfer_fee' => 330, 'memo' => '그대로'],
    ['transfer_fee_payer' => 'agency', 'transfer_fee' => 330, 'memo' => '그대로'],
    $labels
);
assert($d['fields'] === ['transfer_fee_payer'], '바뀐 필드만');
assert($d['text'] === '이체수수료 부담 rider→agency', '요약 문장: ' . $d['text']);

// ② 타입만 다른 같은 값은 변경이 아니다 (폼은 문자열, DB 는 정수로 돌아온다)
$d = AuditLog::diff(['transfer_fee' => 330], ['transfer_fee' => '330'], $labels);
assert($d['fields'] === [], '문자열/정수 혼용');
$d = AuditLog::diff(['on' => false], ['on' => 0], ['on' => '스위치']);
assert($d['fields'] === [], 'false/0 혼용');

// ③ 라벨에 없는 필드는 기록하지 않는다(비밀번호 등이 새어 나가면 안 된다)
$d = AuditLog::diff(['password' => 'a'], ['password' => 'b'], $labels);
assert($d['fields'] === [], '라벨 밖 필드 무시');

// ④ 한쪽에만 있는 값
$d = AuditLog::diff([], ['memo' => '새로 생김'], $labels);
assert($d['text'] === '메모 (없음)→새로 생김', '없던 값: ' . $d['text']);

// ⑤ 켜고 끄는 값은 양쪽 다 남는다
$d = AuditLog::diff(['on' => 1], ['on' => 0], ['on' => '스위치']);
assert($d['before'] === ['스위치' => '1'] && $d['after'] === ['스위치' => '0'], '전/후 값 보존');

echo "모두 통과\n";

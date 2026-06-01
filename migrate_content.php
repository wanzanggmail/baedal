<?php

/**
 * 콘텐츠(공지·광고 배너) 테이블 마이그레이션 + 초기 샘플
 * 실행: php migrate_content.php
 * !! 실행 후 이 파일을 삭제하세요 !!
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$sqlFile = __DIR__ . '/sql/content_tables.sql';
if (!is_file($sqlFile)) {
    echo "ERROR: sql/content_tables.sql 없음\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
$parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
foreach ($parts as $stmt) {
    $stmt = trim(preg_replace('/--[^\r\n]*/', '', $stmt) ?? '');
    if ($stmt === '') {
        continue;
    }
    try {
        db_execute($stmt);
        if (str_contains($stmt, 'content_notices')) {
            echo "OK    content_notices 테이블\n";
        }
        if (str_contains($stmt, 'content_banners')) {
            echo "OK    content_banners 테이블\n";
        }
    } catch (Throwable $e) {
        echo 'ERROR SQL → ' . $e->getMessage() . "\n";
        exit(1);
    }
}

$count = (int) (db_row('SELECT COUNT(*) AS c FROM content_notices')['c'] ?? 0);
if ($count === 0) {
    $adminId = (int) (db_row('SELECT id FROM admins ORDER BY id ASC LIMIT 1')['id'] ?? 0);
    $samples = [
        [
            'nt-demo-001',
            '5월 정산 일정 안내',
            "안녕하세요, 도깨비 라이더님.\n\n5월 정산 지급일은 5월 15일(목) 예정입니다.\n정산 메뉴에서 일별·주간 내역을 확인해 주세요.",
            '안내',
            1,
            'published',
            '2026-05-10 09:00:00',
        ],
        [
            'nt-demo-002',
            '앱 점검 안내 (5/28 02:00~04:00)',
            "시스템 점검으로 해당 시간 동안 일부 기능 이용이 제한될 수 있습니다.",
            '긴급',
            1,
            'published',
            '2026-05-20 18:00:00',
        ],
        [
            'nt-demo-003',
            '여름철 안전운행 수칙',
            "폭염 시 휴식·수분 섭취를 권장합니다.",
            '일반',
            0,
            'published',
            '2026-05-15 11:00:00',
        ],
        [
            'nt-demo-004',
            '프로모션 지급 기준 변경 (초안)',
            "게시 전 초안입니다.",
            '일반',
            0,
            'draft',
            null,
        ],
    ];
    foreach ($samples as [$pid, $title, $body, $cat, $pinned, $status, $pub]) {
        db_insert(
            'INSERT INTO content_notices
                (public_id, title, body, category, pinned, status, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pid,
                $title,
                $body,
                $cat,
                $pinned,
                $status,
                $pub,
                $adminId > 0 ? $adminId : null,
            ]
        );
    }
    echo "OK    샘플 공지 4건 등록\n";
} else {
    echo "SKIP  기존 공지 {$count}건 — 샘플 생략\n";
}

try {
    $bannerCount = (int) (db_row('SELECT COUNT(*) AS c FROM content_banners')['c'] ?? 0);
} catch (Throwable $e) {
    echo 'ERROR content_banners 확인 → ' . $e->getMessage() . "\n";
    exit(1);
}
if ($bannerCount === 0) {
    $adminId = (int) (db_row('SELECT id FROM admins ORDER BY id ASC LIMIT 1')['id'] ?? 0);
    $bannerSamples = [
        [
            'ad-demo-001',
            '제휴 보험 가입 프로모션',
            '라이더 전용 요금 · 한정 기간',
            'https://example.com/ads/insurance-demo',
            '/assets/media/banners/demo-insurance.svg',
            'rider_app',
            10,
            'active',
            '2026-05-01',
            '2026-12-31',
        ],
        [
            'ad-demo-002',
            '배달 장비 할인 스폰서',
            '헬멧·배낭 최대 30%',
            'https://example.com/ads/gear-demo',
            '/assets/media/banners/demo-gear.svg',
            'home_top',
            20,
            'active',
            '2026-04-01',
            null,
        ],
        [
            'ad-demo-003',
            '안전 운행 캠페인',
            '헬멧 인증 포인트 지급',
            '',
            '/assets/media/banners/demo-safety.svg',
            'rider_app',
            15,
            'active',
            '2026-05-01',
            '2026-10-31',
        ],
        [
            'ad-demo-004',
            '5월 정산 · 출금 안내',
            '지급 예정일 5월 15일(목)',
            '',
            '/assets/media/banners/demo-settlement.svg',
            'home_middle',
            25,
            'active',
            '2026-05-01',
            '2026-06-30',
        ],
    ];
    foreach ($bannerSamples as [$pid, $title, $sub, $link, $img, $slot, $ord, $st, $start, $end]) {
        db_insert(
            'INSERT INTO content_banners
                (public_id, title, subtitle, link_url, image_url, slot, sort_order, status, start_at, end_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $pid, $title, $sub, $link, $img, $slot, $ord, $st, $start, $end,
                $adminId > 0 ? $adminId : null,
            ]
        );
    }
    echo "OK    샘플 광고 4건 등록 (데모 배너 SVG)\n";
} else {
    echo "SKIP  기존 광고 {$bannerCount}건 — 샘플 생략\n";
}

echo "\n=====================================\n";
echo "완료. 관리자 > 콘텐츠 > 공지·광고 배너에서 확인하세요.\n";
echo "이 파일(migrate_content.php)을 삭제하세요!\n";
echo "=====================================\n";

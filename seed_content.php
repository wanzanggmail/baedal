<?php

/**
 * 콘텐츠 샘플 데이터 (공지 + 광고 배너 + 데모 이미지)
 * 실행: php seed_content.php
 *       php seed_content.php --fresh   (데모 public_id만 삭제 후 재등록)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$fresh = in_array('--fresh', $argv ?? [], true);

$demoNoticeIds = [
    'nt-demo-001',
    'nt-demo-002',
    'nt-demo-003',
    'nt-demo-004',
];

$demoBannerIds = [
    'ad-demo-001',
    'ad-demo-002',
    'ad-demo-003',
    'ad-demo-004',
];

$bannerDir = ROOT_PATH . '/assets/media/banners';
$bannerFiles = [
    'demo-insurance.svg',
    'demo-gear.svg',
    'demo-safety.svg',
    'demo-settlement.svg',
];

foreach ($bannerFiles as $f) {
    $path = $bannerDir . '/' . $f;
    if (!is_file($path)) {
        echo "WARN  배너 이미지 없음: {$f} (저장소에 파일을 두세요)\n";
    } else {
        echo "OK    이미지 {$f}\n";
    }
}

if ($fresh) {
    $inN = implode(',', array_fill(0, count($demoNoticeIds), '?'));
    db_execute("DELETE FROM content_notices WHERE public_id IN ({$inN})", $demoNoticeIds);
    echo "OK    데모 공지 삭제\n";
    $inB = implode(',', array_fill(0, count($demoBannerIds), '?'));
    db_execute("DELETE FROM content_banners WHERE public_id IN ({$inB})", $demoBannerIds);
    echo "OK    데모 광고 삭제\n";
}

$adminId = (int) (db_row('SELECT id FROM admins ORDER BY id ASC LIMIT 1')['id'] ?? 0);

$notices = [
    [
        'nt-demo-001',
        '5월 정산 일정 안내',
        "안녕하세요, 도깨비 라이더님.\n\n5월 정산 지급일은 5월 15일(목) 예정입니다.\n정산 메뉴에서 일별·주간 내역을 확인해 주세요.\n\n문의: 운영센터 1588-0000 (샘플)",
        '안내',
        1,
        'published',
        '2026-05-10 09:00:00',
    ],
    [
        'nt-demo-002',
        '앱 점검 안내 (5/28 02:00~04:00)',
        "시스템 점검으로 해당 시간 동안 출금 신청·정산 조회가 일시 중단될 수 있습니다.\n점검 종료 후 다시 이용해 주세요.",
        '긴급',
        1,
        'published',
        '2026-05-20 18:00:00',
    ],
    [
        'nt-demo-003',
        '여름철 안전운행 수칙',
        "폭염 시 휴식·수분 섭취를 권장합니다.\n헬멧·반사 장비 착용을 지켜 주세요.",
        '일반',
        0,
        'published',
        '2026-05-15 11:00:00',
    ],
    [
        'nt-demo-004',
        '프로모션 지급 기준 변경 (초안)',
        "내부 검토 중인 문서입니다. 게시 전이며 라이더 앱에는 노출되지 않습니다.",
        '일반',
        0,
        'draft',
        null,
    ],
];

$noticeInserted = 0;
$noticeSkipped  = 0;
foreach ($notices as [$pid, $title, $body, $cat, $pinned, $status, $pub]) {
    if (db_row('SELECT id FROM content_notices WHERE public_id = ?', [$pid])) {
        $noticeSkipped++;
        continue;
    }
    db_insert(
        'INSERT INTO content_notices
            (public_id, title, body, category, pinned, status, published_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$pid, $title, $body, $cat, $pinned, $status, $pub, $adminId > 0 ? $adminId : null]
    );
    $noticeInserted++;
}
echo "OK    공지 +{$noticeInserted} / 생략 {$noticeSkipped}\n";

$banners = [
    [
        'ad-demo-001',
        '제휴 보험 가입 프로모션',
        '라이더 전용 요금 · 5월 한정',
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
        '헬멧·배낭 최대 30% (샘플)',
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

$bannerInserted = 0;
$bannerSkipped  = 0;
foreach ($banners as [$pid, $title, $sub, $link, $img, $slot, $ord, $st, $start, $end]) {
    if (db_row('SELECT id FROM content_banners WHERE public_id = ?', [$pid])) {
        $bannerSkipped++;
        continue;
    }
    db_insert(
        'INSERT INTO content_banners
            (public_id, title, subtitle, link_url, image_url, slot, sort_order, status, start_at, end_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $pid, $title, $sub, $link, $img, $slot, $ord, $st, $start, $end,
            $adminId > 0 ? $adminId : null,
        ]
    );
    $bannerInserted++;
}
echo "OK    광고 +{$bannerInserted} / 생략 {$bannerSkipped}\n";

$nc = (int) (db_row('SELECT COUNT(*) AS c FROM content_notices')['c'] ?? 0);
$bc = (int) (db_row('SELECT COUNT(*) AS c FROM content_banners')['c'] ?? 0);
$ac = (int) (db_row(
    "SELECT COUNT(*) AS c FROM content_banners
     WHERE status = 'active'
       AND (start_at IS NULL OR start_at <= CURDATE())
       AND (end_at IS NULL OR end_at >= CURDATE())"
)['c'] ?? 0);

echo "\n=====================================\n";
echo "공지 총 {$nc}건 · 광고 총 {$bc}건 (집행 중 {$ac}건)\n";
echo "관리자: 콘텐츠 > 공지 / 광고 배너\n";
echo "라이더: 홈 롤링 배너 · 로그인 팝업(고정 공지)\n";
if ($noticeSkipped > 0 || $bannerSkipped > 0) {
    echo "재적용: php seed_content.php --fresh\n";
}
echo "=====================================\n";

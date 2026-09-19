<?php

declare(strict_types=1);

/**
 * 관리자 화면을 **실제 라우터로** 렌더해 보는 CLI 도구 (2026-09-19).
 *
 * 왜 필요한가: 도메인 함수만 테스트하면 화면이 열리는지, 조회 폼이 제대로 돌아오는지를
 * 알 수 없다. 실제로 `광고 클릭 로그` 의 조회 버튼이 대시보드로 튕긴 적이 있다
 * (이 관리자는 route 를 쿼리스트링으로 받는데 GET 폼이 그걸 버려서).
 *
 * 사용법:
 *   php tools/render_admin_page.php <route> [key=value ...]
 *   php tools/render_admin_page.php content/ad-clicks
 *   php tools/render_admin_page.php content/ad-clicks from=2026-09-01 to=2026-09-19
 *
 * 출력: 상태 요약 + 렌더된 HTML 을 tools/_render_out.html 에 저장.
 * 로그인은 **본사 최고관리자 세션을 직접 구성**한다(비밀번호를 쓰지 않는다).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$route = $argv[1] ?? '';
if ($route === '') {
    fwrite(STDERR, "사용법: php tools/render_admin_page.php <route> [key=value ...]\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/admin/index.php';
$_SERVER['HTTP_HOST']      = 'localhost';
$_GET = ['route' => $route];
foreach (array_slice($argv, 2) as $pair) {
    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
    $_GET[$k] = $v;
}
$_SERVER['REQUEST_URI'] = '/admin/index.php?' . http_build_query($_GET);

require_once dirname(__DIR__) . '/inc/bootstrap.php';

$admin = db_row(
    "SELECT a.id, a.login_id, a.name, a.role, a.org_id
     FROM admins a JOIN organizations o ON o.id = a.org_id
     WHERE a.role = 'super' AND o.level = 'admin' AND a.is_active = 1
     ORDER BY a.id LIMIT 1"
);
if ($admin === null) {
    fwrite(STDERR, "본사 최고관리자 계정을 찾지 못했습니다.\n");
    exit(1);
}
$_SESSION['admin_auth']     = true;
$_SESSION['admin_id']       = (int) $admin['id'];
$_SESSION['admin_login_id'] = $admin['login_id'];
$_SESSION['admin_name']     = $admin['name'];
$_SESSION['admin_role']     = $admin['role'];
$_SESSION['admin_org_id']   = (int) $admin['org_id'];

// 요약은 렌더가 끝난 뒤 한 번에 찍는다 — 먼저 출력하면 index.php 의 http_response_code() 가
// "headers already sent" 경고를 낸다.
$summary = sprintf(
    "계정     : %s (org %d)\n라우트   : %s\n접근권한 : %s\n",
    $admin['login_id'],
    (int) $admin['org_id'],
    $route,
    admin_can_access_route($route) ? '허용' : '차단'
);

ob_start();
require dirname(__DIR__) . '/admin/index.php';
$html = ob_get_clean();

echo $summary;

$out = __DIR__ . '/_render_out.html';
file_put_contents($out, $html);

$title = preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m) ? trim(strip_tags($m[1])) : '(없음)';
echo "화면제목 : {$title}\n";
echo "길이     : " . number_format(strlen($html)) . " bytes → {$out}\n";

// 조회 폼이 route 를 잃지 않는지 — 이 관리자에서 가장 흔한 실수다.
if (preg_match_all('/<form[^>]*method="get"[^>]*>(.*?)<\/form>/is', $html, $forms)) {
    foreach ($forms[0] as $i => $form) {
        $hasRoute = str_contains($form, 'name="route"');
        $needs    = defined('ADMIN_USE_QUERY_URL') && ADMIN_USE_QUERY_URL;
        printf("GET 폼#%d : route hidden %s%s\n", $i + 1, $hasRoute ? '있음' : '없음',
            (!$hasRoute && $needs) ? '  ⚠️ 조회 시 대시보드로 튕깁니다' : '');
    }
}

<?php

declare(strict_types=1);

require_once ROOT_PATH . '/vendor/autoload.php';

/**
 * 프로그램 내 매뉴얼 뷰어 (docs/manual/*.md) — 렌더링·목차·검색.
 *
 * docs/manual/*.md은 사람이 직접 관리하는 원본이라 이 클래스는 파일을 그대로 읽어
 * 매 요청마다 파싱한다(파일 6개, 총 수십 KB 수준이라 캐시 없이도 충분히 가볍다).
 * 헤딩 앵커는 마크다운 텍스트 자체가 아니라 **문서 내 등장 순서(sec-1, sec-2…)**로
 * 부여한다 — 한글 제목을 슬러그로 바꾸는 과정에서 생기는 충돌·불일치를 피하기 위함.
 */
final class ManualDocs
{
    private const DIR = '/docs/manual/';

    /** @var array<string, array{file:string, title:string, audience:list<string>}> */
    private const DOCS = [
        'overview'     => ['file' => 'README.md', 'title' => '매뉴얼 소개', 'audience' => ['admin']],
        'admin'        => ['file' => 'ADMIN_MANUAL.md', 'title' => '관리자 매뉴얼', 'audience' => ['admin']],
        'rider'        => ['file' => 'RIDER_MANUAL.md', 'title' => '라이더 매뉴얼', 'audience' => ['admin', 'rider']],
        'workflow'     => ['file' => 'WORKFLOW_REFERENCE.md', 'title' => '업무 흐름 참조서', 'audience' => ['admin']],
        'ops'          => ['file' => 'OPERATIONS_MANUAL.md', 'title' => '설치·운영 매뉴얼', 'audience' => ['admin']],
        'limitations'  => ['file' => 'KNOWN_LIMITATIONS.md', 'title' => '현재 제약 및 주의사항', 'audience' => ['admin']],
    ];

    /** @return list<array{id:string, title:string}> */
    public static function forAudience(string $audience): array
    {
        $out = [];
        foreach (self::DOCS as $id => $d) {
            if (in_array($audience, $d['audience'], true)) {
                $out[] = ['id' => $id, 'title' => $d['title']];
            }
        }

        return $out;
    }

    public static function exists(string $id): bool
    {
        return isset(self::DOCS[$id]);
    }

    public static function allowedFor(string $id, string $audience): bool
    {
        return isset(self::DOCS[$id]) && in_array($audience, self::DOCS[$id]['audience'], true);
    }

    public static function title(string $id): string
    {
        return self::DOCS[$id]['title'] ?? $id;
    }

    private static function rawText(string $id): string
    {
        if (!isset(self::DOCS[$id])) {
            return '';
        }
        $path = ROOT_PATH . self::DIR . self::DOCS[$id]['file'];

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * 원본 마크다운을 헤딩(## 이상) 기준으로 섹션 분할.
     * 각 섹션에 등장 순서 기반 앵커(sec-1, sec-2…)를 부여 — renderHtml()의 <hN id="..."> 부여 순서와 반드시 같아야 한다.
     *
     * @return list<array{anchor:string, level:int, title:string, body:string}>
     */
    public static function sections(string $id): array
    {
        $raw = self::rawText($id);
        if ($raw === '') {
            return [];
        }

        $lines = explode("\n", $raw);
        $sections = [];
        $cur = null;
        $n = 0;

        foreach ($lines as $line) {
            if (preg_match('/^(#{2,4})\s+(.+)$/', $line, $m)) {
                if ($cur !== null) {
                    $sections[] = $cur;
                }
                $n++;
                $cur = [
                    'anchor' => 'sec-' . $n,
                    'level'  => strlen($m[1]),
                    'title'  => trim($m[2]),
                    'body'   => '',
                ];
                continue;
            }
            if ($cur !== null) {
                $cur['body'] .= $line . "\n";
            }
        }
        if ($cur !== null) {
            $sections[] = $cur;
        }

        return $sections;
    }

    /**
     * 마크다운 → HTML. h2~h4에 sections()와 동일한 순서로 id="sec-N"을 부여한다.
     *
     * @param ?callable $linkBuilder 문서 간 상대링크(README.md의 "문서 구성" 등, 예: href="ADMIN_MANUAL.md")를
     *                               앱 라우트로 바꿔주는 콜백 fn(string $targetDocId): string.
     *                               관리자/라이더가 URL 체계(admin_url vs rider_url)가 달라 호출부에서 주입받는다.
     */
    public static function renderHtml(string $id, ?callable $linkBuilder = null): string
    {
        $raw = self::rawText($id);
        if ($raw === '') {
            return '';
        }

        $pd = new \Parsedown();
        $pd->setSafeMode(true); // 문서는 우리가 직접 관리하지만, 그래도 원본 HTML 삽입은 막아둔다
        $html = $pd->text($raw);

        $n = 0;
        $html = preg_replace_callback(
            '/<h([2-4])>/',
            static function (array $m) use (&$n): string {
                $n++;

                return '<h' . $m[1] . ' id="sec-' . $n . '">';
            },
            $html
        );

        if ($linkBuilder !== null) {
            $byFile = [];
            foreach (self::DOCS as $docId => $d) {
                $byFile[$d['file']] = $docId;
            }
            $html = preg_replace_callback(
                '/href="([A-Za-z0-9_]+\.md)(#[^"]*)?"/',
                static function (array $m) use ($byFile, $linkBuilder): string {
                    $target = $byFile[$m[1]] ?? null;
                    if ($target === null) {
                        return $m[0];
                    }

                    return 'href="' . htmlspecialchars($linkBuilder($target), ENT_QUOTES, 'UTF-8') . ($m[2] ?? '') . '"';
                },
                (string) $html
            );
        }

        return (string) $html;
    }

    /**
     * 검색 — 대상 문서군 전체에서 섹션 제목·본문에 검색어가 포함된 섹션을 찾는다.
     * 제목에 일치하면 본문 일치보다 우선 노출.
     *
     * @return list<array{doc_id:string, doc_title:string, anchor:string, title:string, snippet:string, title_hit:bool}>
     */
    public static function search(string $query, string $audience): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $out = [];
        foreach (self::forAudience($audience) as $doc) {
            foreach (self::sections($doc['id']) as $sec) {
                $plain = self::stripMarkdown($sec['body']);
                $titleHit = mb_stripos($sec['title'], $query) !== false;
                $bodyPos  = mb_stripos($plain, $query);
                if (!$titleHit && $bodyPos === false) {
                    continue;
                }

                $out[] = [
                    'doc_id'    => $doc['id'],
                    'doc_title' => $doc['title'],
                    'anchor'    => $sec['anchor'],
                    'title'     => $sec['title'],
                    'snippet'   => self::snippet($plain, $bodyPos !== false ? $bodyPos : 0, $query),
                    'title_hit' => $titleHit,
                ];
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['title_hit'] <=> $a['title_hit']));

        return array_slice($out, 0, 30);
    }

    private static function stripMarkdown(string $s): string
    {
        $s = preg_replace('/```[a-z]*\n?/i', '', $s) ?? $s;
        $s = str_replace('```', '', $s);
        $s = preg_replace('/[`*_#>|-]/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }

    private static function snippet(string $plain, int $pos, string $query): string
    {
        if ($plain === '') {
            return '';
        }
        $radius = 60;
        $start  = max(0, $pos - $radius);
        $len    = mb_strlen($query) + $radius * 2;
        $snip   = mb_substr($plain, $start, $len);
        if ($start > 0) {
            $snip = '…' . $snip;
        }
        if ($start + $len < mb_strlen($plain)) {
            $snip .= '…';
        }

        return $snip;
    }
}

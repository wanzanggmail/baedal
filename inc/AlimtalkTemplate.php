<?php

declare(strict_types=1);

require_once __DIR__ . '/MessagingConfig.php';

/**
 * 알림톡 템플릿 관리(2026-09-03 갑).
 *
 * 알림톡은 아무 문구나 못 보낸다 — 카카오에 **사전 승인된 템플릿**만 발송 가능하고,
 * 본문의 변하는 부분은 `#{키}` 치환변수로 뺀다. 그래서 "어떤 상황(event_key)에 어떤
 * 템플릿 코드를 쓰고, 어떤 변수를 채우는가"를 이 테이블에서 관리한다.
 *
 * 발신 프로필키(채널)는 템플릿마다 다르지 않고 계정 단위라 `messaging_config.alimtalk_channel`
 * 에 둔다 — 화면에서는 같이 보여준다.
 *
 * 채널 정책(`channel_policy`):
 *  - `alimtalk_first` : 알림톡으로 보내고, 카카오 수신불가면 같은 내용을 문자로 대체발송
 *  - `sms_only`       : 처음부터 문자로만(템플릿 승인 없이 쓸 수 있는 안내 등)
 * 문자로 나갈 때 SMS/LMS 는 본문 길이로 자동 판정한다(`MessagingConfig::smsChannelFor`).
 */
final class AlimtalkTemplate
{
    /**
     * 발송 상황(event_key) 정의 — 화면 안내와 기본 템플릿 생성에 쓴다.
     * `vars` 는 그 상황에서 시스템이 채워주는 치환변수다.
     *
     * @var array<string, array{label:string, desc:string, vars:list<string>}>
     */
    public const EVENTS = [
        'settlement_statement' => [
            'label' => '정산 명세서(일정산)',
            'desc'  => '일정산 반영 시 라이더에게 요약 명세서 + 모바일 명세서 링크를 보낸다.',
            'vars'  => ['name', 'period', 'orders', 'amount', 'link'],
        ],
        'withdrawal_done' => [
            'label' => '출금 완료',
            'desc'  => '출금(이체)이 실제로 완료됐을 때.',
            'vars'  => ['name', 'amount', 'bank', 'date'],
        ],
        'withdrawal_failed' => [
            'label' => '출금 실패',
            'desc'  => '이체가 실패해 재시도가 필요할 때.',
            'vars'  => ['name', 'amount', 'reason'],
        ],
        'notice' => [
            'label' => '공지/안내',
            'desc'  => '대리점이 라이더에게 보내는 일반 공지.',
            'vars'  => ['name', 'message'],
        ],
    ];

    /** 치환변수 표기: #{키} */
    private const VAR_PATTERN = '/#\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';

    public static function ready(): bool
    {
        return db_table_exists('alimtalk_templates');
    }

    public static function eventLabel(string $key): string
    {
        return self::EVENTS[$key]['label'] ?? $key;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        if (!self::ready()) {
            return [];
        }

        return db_rows('SELECT * FROM alimtalk_templates ORDER BY id ASC');
    }

    /** @return array<string,mixed>|null */
    public static function forEvent(string $eventKey): ?array
    {
        if (!self::ready()) {
            return null;
        }

        return db_row('SELECT * FROM alimtalk_templates WHERE event_key = ? LIMIT 1', [$eventKey]);
    }

    /**
     * 템플릿 저장(event_key 기준 upsert).
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data, ?int $adminId = null): int
    {
        if (!self::ready()) {
            throw new RuntimeException('alimtalk_templates 테이블이 없습니다. php migrate.php 를 실행하세요.');
        }
        $eventKey = trim((string) ($data['event_key'] ?? ''));
        if (!isset(self::EVENTS[$eventKey])) {
            throw new InvalidArgumentException('알 수 없는 발송 상황입니다.');
        }
        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            throw new InvalidArgumentException('템플릿 본문을 입력하세요.');
        }
        $policy = ((string) ($data['channel_policy'] ?? '')) === 'sms_only' ? 'sms_only' : 'alimtalk_first';
        $code   = mb_substr(trim((string) ($data['template_code'] ?? '')), 0, 60);
        if ($policy === 'alimtalk_first' && $code === '') {
            throw new InvalidArgumentException('알림톡으로 보내려면 승인된 템플릿 코드가 필요합니다. (문자만 쓰려면 채널 정책을 「문자만」으로)');
        }

        $row = [
            mb_substr(trim((string) ($data['name'] ?? self::eventLabel($eventKey))), 0, 80),
            $code,
            mb_substr(trim((string) ($data['title'] ?? '')), 0, 120) ?: null,
            mb_substr($content, 0, 2000),
            mb_substr(implode(',', self::extractVars($content)), 0, 500),
            $policy,
            !empty($data['is_active']) ? 1 : 0,
            ($adminId !== null && $adminId > 0) ? $adminId : null,
        ];

        $existing = self::forEvent($eventKey);
        if ($existing !== null) {
            db_execute(
                'UPDATE alimtalk_templates SET name=?, template_code=?, title=?, content=?, variables=?,
                        channel_policy=?, is_active=?, updated_by=?, updated_at=NOW() WHERE id=?',
                array_merge($row, [(int) $existing['id']])
            );

            return (int) $existing['id'];
        }

        return db_insert(
            'INSERT INTO alimtalk_templates (event_key, name, template_code, title, content, variables,
                    channel_policy, is_active, updated_by, updated_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            array_merge([$eventKey], $row)
        );
    }

    public static function delete(int $id): bool
    {
        return self::ready() && db_execute('DELETE FROM alimtalk_templates WHERE id = ?', [$id]) > 0;
    }

    /**
     * 본문에서 치환변수 키 목록 추출.
     *
     * @return list<string>
     */
    public static function extractVars(string $content): array
    {
        preg_match_all(self::VAR_PATTERN, $content, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * 치환변수를 채워 최종 본문을 만든다. 값이 없는 변수는 빈 문자열로 지운다
     * (알림톡은 미치환 `#{}` 가 남아 있으면 발송사에서 거절한다).
     *
     * @param array<string,scalar|null> $vars
     */
    public static function render(string $content, array $vars): string
    {
        return (string) preg_replace_callback(
            self::VAR_PATTERN,
            static fn (array $m): string => (string) ($vars[$m[1]] ?? ''),
            $content
        );
    }

    /**
     * 상황에 맞는 발송 계획을 만든다 — 템플릿이 없거나 꺼져 있으면 `$fallbackText` 로 문자 발송.
     *
     * @param array<string,scalar|null> $vars
     * @return array{channel:string, content:string, title:?string, template_code:?string}
     */
    public static function plan(string $eventKey, array $vars, string $fallbackText): array
    {
        $tpl = self::forEvent($eventKey);

        // 템플릿 미설정·비활성 → 기존 문구 그대로 문자로(길이에 따라 SMS/LMS)
        if ($tpl === null || (int) $tpl['is_active'] !== 1) {
            return [
                'channel'       => MessagingConfig::smsChannelFor($fallbackText),
                'content'       => $fallbackText,
                'title'         => null,
                'template_code' => null,
            ];
        }

        $content = self::render((string) $tpl['content'], $vars);

        if ((string) $tpl['channel_policy'] === 'sms_only') {
            return [
                'channel'       => MessagingConfig::smsChannelFor($content),
                'content'       => $content,
                'title'         => null,
                'template_code' => null,
            ];
        }

        return [
            'channel'       => 'alimtalk',
            'content'       => $content,
            'title'         => ($tpl['title'] ?? null) !== null ? (string) $tpl['title'] : null,
            'template_code' => (string) $tpl['template_code'],
        ];
    }

    /**
     * 기본 템플릿 생성(없는 상황만). seed/migrate 후 한 번 호출하면 화면에서 바로 손볼 수 있다.
     *
     * @return int 생성된 개수
     */
    public static function seedDefaults(?int $adminId = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $defaults = [
            'settlement_statement' => [
                'title'   => '정산 명세서',
                'content' => "[정산 명세서] #{name}님\n"
                    . "■ 정산일 #{period}\n"
                    . "· 총 오더수 : #{orders}건\n"
                    . "· 정산금액 : #{amount}\n\n"
                    . "▶ 상세 명세서 보기\n#{link}",
            ],
            'withdrawal_done' => [
                'title'   => '출금 완료',
                'content' => "[출금 완료] #{name}님\n"
                    . "· 금액 : #{amount}\n"
                    . "· 입금 : #{bank}\n"
                    . "· 일시 : #{date}",
            ],
            'withdrawal_failed' => [
                'title'   => '출금 실패',
                'content' => "[출금 실패] #{name}님\n"
                    . "· 금액 : #{amount}\n"
                    . "· 사유 : #{reason}\n"
                    . "계좌 정보를 확인 후 다시 신청해 주세요.",
            ],
            'notice' => [
                'title'   => '안내',
                'content' => "[안내] #{name}님\n#{message}",
            ],
        ];

        $made = 0;
        foreach ($defaults as $key => $d) {
            if (self::forEvent($key) !== null) {
                continue;
            }
            $vars = self::extractVars($d['content']);
            db_insert(
                'INSERT INTO alimtalk_templates (event_key, name, template_code, title, content, variables,
                        channel_policy, is_active, updated_by, updated_at, created_at)
                 VALUES (?, ?, \'\', ?, ?, ?, \'alimtalk_first\', 0, ?, NOW(), NOW())',
                [
                    $key,
                    self::eventLabel($key),
                    $d['title'],
                    $d['content'],
                    implode(',', $vars),
                    ($adminId !== null && $adminId > 0) ? $adminId : null,
                ]
            );
            $made++;
        }

        return $made;
    }
}

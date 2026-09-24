<?php

declare(strict_types=1);

require_once __DIR__ . '/RiderDebt.php';
require_once __DIR__ . '/MessageQueue.php';
require_once __DIR__ . '/Org.php';
require_once __DIR__ . '/StatementLink.php';
require_once __DIR__ . '/AlimtalkTemplate.php';

/**
 * 주급 명세서(라이더 정산명세서) 데이터 — **우리가 가진 데이터만** 사용해 실제 명세서 레이아웃을 재현.
 *
 * 참여인정구간 점수 규칙은 구 프로그램(parser.py) 기준을 그대로 옮긴 것 — 요일별 기준치·밤논피크 특례.
 * 기준치가 바뀌면 THRESHOLD / 밤논피크 규칙만 고치면 된다.
 */
final class RiderStatement
{
    /** peak_time(원본) → 표기 버킷 */
    public const BUCKETS = [
        'Breakfast'   => '오전논피크',
        'Lunch_Peak'  => '점심피크',
        'Post_Lunch'  => '오후논피크',
        'Dinner_Peak' => '저녁피크',
        'Post_Dinner' => '밤논피크',
    ];
    /** 정산주간은 수요일 시작 → 요일 컬럼 순서(수목금토일월화). date('w'): 일0~토6 */
    private const WEEK_ORDER = [3 => '수', 4 => '목', 5 => '금', 6 => '토', 0 => '일', 1 => '월', 2 => '화'];
    /** 요일별 기준치(오전/점심/오후/저녁). 평일 vs 주말(토·일). 밤논피크는 특례. */
    private const THRESHOLD = [
        'weekday' => ['Breakfast' => 3, 'Lunch_Peak' => 6, 'Post_Lunch' => 5, 'Dinner_Peak' => 9],
        'weekend' => ['Breakfast' => 4, 'Lunch_Peak' => 9, 'Post_Lunch' => 7, 'Dinner_Peak' => 10],
    ];

    /**
     * 주간 정산 요약 + 일자별 + 공제 분해 + 지원금 + 참여인정구간을 한 번에.
     *
     * @return array<string,mixed>
     */
    public static function build(int $riderId, string $from, string $to): array
    {
        return [
            'summary'       => self::summary($riderId, $from, $to),
            'daily'         => self::daily($riderId, $from, $to),
            'support_rows'  => self::supportRows($riderId, $from, $to),
            'participation' => self::participation($riderId, $from, $to),
        ];
    }

    /**
     * 주간 정산 요약 블록만 계산(알림톡 요약처럼 요약만 필요할 때 참여인정구간·일자별 쿼리 낭비 방지).
     *
     * @return array<string,int>
     */
    public static function summary(int $riderId, string $from, string $to): array
    {
        $fees = self::feesByCode($riderId, $from, $to);
        $get  = static fn (string $c): int => (int) ($fees[$c] ?? 0);

        $cyc = db_row(
            "SELECT COALESCE(SUM(order_count),0) o, COALESCE(SUM(support_amount),0) s,
                    COALESCE(SUM(total_fee_amount),0) f, COALESCE(SUM(net_amount),0) n
               FROM settlement_rider_cycles WHERE rider_id=? AND settlement_date BETWEEN ? AND ?",
            [$riderId, $from, $to]
        ) ?? ['o' => 0, 's' => 0, 'f' => 0, 'n' => 0];

        // 고정차감 = 리스 + 대여금 + (전주차미납: 데이터 없음 → 0). 선지급차감 = 선지급.
        $fixed    = $get('lease') + $get('rental') + $get('loan');
        $advance  = $get('advance');

        // 🔒 대리점 선차감(2026-09-06 갑) — **라이더에게는 보이지 않는다.**
        // 공제 한 줄로 보여주는 대신 정산금액 자체를 그만큼 낮춰, 라이더 눈에는
        // 배달 단가가 처음부터 그 금액이었던 것처럼 보이게 한다
        // (갑: 관리자에서는 1,100원으로 나오는데 라이더한테는 1,000원으로 나오는거야).
        //
        // 총공제에서 빼기만 하면 아래 계산이 전부 따라온다:
        //   정산금액 = 실수령 + 총공제 − 지원금   → 선차감만큼 낮아지고
        //   차감액(잔여 흡수) = 총공제 − 개별항목 → 선차감이 어디에도 안 남는다
        // 그래서 「정산금액 + 지원 − 공제 = 실수령」 균형이 그대로 유지된다.
        $totalFee = (int) $cyc['f'] - $get('agency_prededuct');

        // 개별 표기 공제 항목들.
        $withholding = $get('withholding');
        $employment  = $get('employment_ins');
        $accident    = $get('accident_ins');
        $hourly      = $get('hourly_ins');
        // 구 대행수수료 — 폐지됐지만 과거 데이터에는 남아 있고 total_fee_amount 에 포함돼 있다.
        // 아래 「차감액」(잔여 흡수) 계산에는 이 값만 쓴다.
        $agencyFee   = $get('agency_fee');
        // 출금 시점 수수료(정산수수료·이체수수료) — total_fee_amount 밖이라 따로 뺀다.
        $wd          = self::withdrawFees($riderId, $from, $to);

        // 「차감액」은 **잔여 흡수(catch-all)** — 총공제(total_fee_amount)에서 개별 표기 항목을 뺀
        // 나머지(엑셀 차감내역·수동차감, 그리고 아직 개별 표기하지 않는 새 코드까지)를 담는다.
        // 이렇게 하면 표시 공제 합 == total_fee 가 되어 "정산금액 + 지원 − 공제 = 실수령" 균형이
        // 어떤 fee_code 조합에서도 깨지지 않는다.
        $deduction = $totalFee - ($withholding + $employment + $accident + $hourly + $agencyFee + $advance + $fixed);

        // 정산금액 = 실수령 + 총공제 − 지원금 (PDF 로직: 정산금액 + 지원 − 공제 = 실수령).
        // net_amount 이 gross−fee 와 안 맞는 구 데이터가 있어 도출값으로 항상 균형을 맞춘다.
        $settleAmount = (int) $cyc['n'] + $totalFee - (int) $cyc['s'];

        // 실수령액 = 지갑 적립액 − 출금 때 라이더가 부담한 수수료. 이 값이 **실제 입금액**이다.
        $net = (int) $cyc['n'] - $wd['settle'] - $wd['transfer'];

        return [
            'orders'        => (int) $cyc['o'],
            'settle_amount' => $settleAmount,
            'promo'         => $get('promo1'),
            'promo2'        => $get('promo2'),
            'support'       => (int) $cyc['s'],
            'deduction'     => $deduction,
            'withholding'   => $withholding,
            'employment'    => $employment,
            'accident'      => $accident,
            'hourly_ins'    => $hourly,
            // 표기용 — 구 대행수수료 + 출금 시점 정산수수료. 둘 다 라이더가 낸 「정산수수료」다.
            'agency_fee'    => $agencyFee + $wd['settle'],
            'transfer_fee'  => $wd['transfer'],
            'advance'       => $advance,
            'fixed'         => $fixed,
            'total_fee'     => $totalFee + $wd['settle'] + $wd['transfer'],
            'net'           => $net,
        ];
    }

    /**
     * 카톡(알림톡)·문자용 **요약 명세서 텍스트**. 중요한 항목만 리스트로.
     * 0원 차감 항목은 생략해 짧게 유지한다(지원/프로모션·실수령은 항상 표기).
     */
    public static function compactText(int $riderId, string $from, string $to, string $riderName = ''): string
    {
        $s   = self::summary($riderId, $from, $to);
        $won = static fn (int $v): string => number_format($v) . '원';

        $period = $from === $to ? $from : ($from . ' ~ ' . $to);
        $name   = $riderName !== '' ? $riderName : ('#' . $riderId);

        $lines   = [];
        $lines[] = '[정산 명세서] ' . $name;
        $lines[] = '■ 정산일 ' . $period;
        $lines[] = '━━━━━━━━━━';
        $lines[] = '· 총 오더수 : ' . number_format((int) $s['orders']) . '건';
        $lines[] = '· 정산금액 : ' . $won((int) $s['settle_amount']);
        if ((int) $s['promo'] > 0)   { $lines[] = '· 프로모션 : ' . $won((int) $s['promo']); }
        if ((int) $s['promo2'] > 0)  { $lines[] = '· 프로모션2 : ' . $won((int) $s['promo2']); }
        if ((int) $s['support'] > 0) { $lines[] = '· 지원금 : ' . $won((int) $s['support']); }

        // 차감 항목 — 있는 것만
        $deducts = [
            '차감액'    => (int) $s['deduction'],
            '원천세'    => (int) $s['withholding'],
            '고용보험'  => (int) $s['employment'],
            '산재보험'  => (int) $s['accident'],
            '시간제보험' => (int) $s['hourly_ins'],
            '정산수수료' => (int) $s['agency_fee'],
            '이체수수료' => (int) ($s['transfer_fee'] ?? 0),
            '선지급차감' => (int) $s['advance'],
            '고정차감'  => (int) $s['fixed'],
        ];
        $deductLines = [];
        foreach ($deducts as $label => $amt) {
            if ($amt > 0) {
                $deductLines[] = '· ' . $label . ' : -' . $won($amt);
            }
        }
        if ($deductLines !== []) {
            $lines[] = '─ 차감 ─';
            $lines   = array_merge($lines, $deductLines);
        }
        $lines[] = '━━━━━━━━━━';
        $lines[] = '▶ 실수령액 : ' . $won((int) $s['net']);

        return implode("\n", $lines);
    }

    /**
     * 일정산 업로드 반영 직후 — 대상 라이더에게 요약 명세서를 **알림톡 큐에 적재**한다(2026-09-01 갑).
     * 대리점 설정 `stmt_daily_alimtalk` 가 켜져 있을 때만 동작. 실제 발송은 발송 큐에서 처리.
     *
     * @return array{enabled:bool, queued:int, skipped:int, errors:list<string>}
     */
    public static function enqueueDailyStatements(int $uploadId, int $agencyId, ?int $adminId = null): array
    {
        $out = ['enabled' => false, 'queued' => 0, 'skipped' => 0, 'errors' => []];

        if (!MessageQueue::ready() || !Org::statementFlag($agencyId, 'stmt_daily_alimtalk')) {
            return $out;
        }
        $out['enabled'] = true;

        // 이번 업로드의 일일정산 라이더 중 **아직 명세서 알림톡을 안 보낸** 사이클만.
        // statement_notified_at 으로 재반영(같은 업로드 재적용) 시 중복 발송을 막는다.
        $notifyCol = in_array(
            'statement_notified_at',
            array_column(db_rows('SHOW COLUMNS FROM settlement_rider_cycles'), 'Field'),
            true
        );
        $notNotified = $notifyCol ? ' AND c.statement_notified_at IS NULL' : '';

        $rows = db_rows(
            "SELECT c.rider_id, r.name AS rider_name,
                    MIN(c.settlement_date) AS from_d, MAX(c.settlement_date) AS to_d
               FROM settlement_rider_cycles c
               INNER JOIN riders r ON r.id = c.rider_id
              WHERE c.upload_id = ? AND r.is_daily_settlement = 1{$notNotified}
              GROUP BY c.rider_id, r.name",
            [$uploadId]
        );

        foreach ($rows as $row) {
            $riderId = (int) $row['rider_id'];
            $from    = (string) $row['from_d'];
            $to      = (string) $row['to_d'];
            try {
                $riderName = (string) ($row['rider_name'] ?? '');
                $text      = self::compactText($riderId, $from, $to, $riderName);

                // 모바일 명세서 링크 — 파일 대신 링크로 상세 명세서를 열 수 있게 첨부.
                $linkUrl = '';
                if (StatementLink::ready()) {
                    try {
                        $link    = StatementLink::create($riderId, $from, $to, $adminId);
                        $linkUrl = $link['url'];
                        $text   .= "\n\n▶ 상세 명세서 보기\n" . $linkUrl;
                    } catch (Throwable) {
                        // 링크 생성 실패는 발송 자체를 막지 않는다(텍스트만 발송).
                    }
                }

                // 승인 템플릿이 설정돼 있으면 그걸로(알림톡), 없으면 위 문구 그대로 문자로 나간다.
                $sum  = self::summary($riderId, $from, $to);
                $plan = AlimtalkTemplate::plan('settlement_statement', [
                    'name'   => $riderName,
                    'period' => $from === $to ? $from : ($from . ' ~ ' . $to),
                    'orders' => number_format((int) $sum['orders']),
                    'amount' => number_format((int) $sum['net']) . '원',
                    'link'   => $linkUrl,
                ], $text);

                MessageQueue::enqueueForRider(
                    $riderId,
                    $plan['channel'],
                    $plan['content'],
                    $plan['title'] ?? '정산 명세서',
                    $adminId,
                    $plan['template_code']
                );
                $out['queued']++;

                // 큐 적재 성공한 라이더의 사이클만 발송 표시(다음 재반영에서 제외).
                if ($notifyCol) {
                    db_execute(
                        'UPDATE settlement_rider_cycles SET statement_notified_at = NOW()
                          WHERE upload_id = ? AND rider_id = ? AND statement_notified_at IS NULL',
                        [$uploadId, $riderId]
                    );
                }
            } catch (Throwable $e) {
                $out['skipped']++;
                $out['errors'][] = ((string) ($row['rider_name'] ?? $riderId)) . ': ' . $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * 출금 시점 수수료 — 이 기간의 정산분을 가져간 출금에서 **라이더가 실제로 부담한** 몫.
     *
     * 명세서의 「정산수수료」는 원래 `fee_code='agency_fee'`(폐지된 대행수수료)를 읽고 있어서
     * 늘 0 이었다(2026-09-07 폐지). 지금 라이더가 실제로 내는 정산수수료·이체수수료는
     * **출금 시점**에 `withdrawal_requests` 에 박히므로 그쪽을 읽어야 명세서와 입금액이 맞는다.
     *
     * 귀속 규칙 — 출금 1건의 수수료를 **그 출금이 소진한 정산일들에 배달 건수로 안분**한다.
     * 정산수수료가 배달 건당 단가라 건수 안분이 가장 실제에 가깝다. 기간을 걸쳐 있는 출금은
     * 기간 안의 건수만큼만 이 명세서에 잡힌다. 나머지(최대 몇 원)는 큰 날짜부터 채운다.
     *
     * @return array{settle:int, transfer:int, by_date:array<string,array{settle:int,transfer:int}>}
     */
    private static function withdrawFees(int $riderId, string $from, string $to): array
    {
        // summary() 와 daily() 가 같은 값을 쓴다 — 한 번만 계산한다.
        static $memo = [];
        $key = $riderId . '|' . $from . '|' . $to;
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $out = ['settle' => 0, 'transfer' => 0, 'by_date' => []];
        if (!db_table_exists('withdrawal_request_cycles') || !db_table_exists('withdrawal_requests')) {
            return $memo[$key] = $out;
        }

        // ① 기간 안에서 각 출금이 가져간 정산일·건수
        $inPeriod = [];   // request_id => [date => orders]
        foreach (db_rows(
            "SELECT wrc.request_id rid, src.settlement_date d, COALESCE(SUM(wrc.order_count),0) o
               FROM withdrawal_request_cycles wrc
               JOIN settlement_rider_cycles src ON src.id = wrc.cycle_id
               JOIN withdrawal_requests wr ON wr.id = wrc.request_id
              WHERE src.rider_id = ? AND wr.status <> 'rejected'
                AND src.settlement_date BETWEEN ? AND ?
              GROUP BY wrc.request_id, src.settlement_date
              ORDER BY src.settlement_date ASC",
            [$riderId, $from, $to]
        ) as $r) {
            $inPeriod[(int) $r['rid']][(string) $r['d']] = (int) $r['o'];
        }
        if ($inPeriod === []) {
            return $memo[$key] = $out;
        }

        // ② 그 출금들의 라이더 부담 수수료와 **전체** 소진 건수(기간 밖 포함)
        $ids = array_keys($inPeriod);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_rows(
            "SELECT wr.id,
                    CASE WHEN wr.settle_fee_payer   = 'agency' THEN 0 ELSE wr.withhold_other END        fee,
                    CASE WHEN wr.transfer_fee_payer = 'agency' THEN 0 ELSE wr.withhold_transfer_fee END trans,
                    COALESCE(SUM(wrc.order_count), 0) total_orders
               FROM withdrawal_requests wr
               JOIN withdrawal_request_cycles wrc ON wrc.request_id = wr.id
              WHERE wr.id IN ({$ph})
              GROUP BY wr.id, fee, trans",
            $ids
        ) as $w) {
            $rid    = (int) $w['id'];
            $dates  = $inPeriod[$rid] ?? [];
            $total  = (int) $w['total_orders'];
            $inSum  = array_sum($dates);
            if ($dates === [] || $inSum < 1) {
                continue;
            }
            foreach (['settle' => (int) $w['fee'], 'transfer' => (int) $w['trans']] as $bucket => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                // 기간 밖 건수가 있으면 그만큼은 이 명세서 몫이 아니다.
                $share = $total > 0 ? (int) round($amount * $inSum / $total) : $amount;
                foreach (self::apportion($share, $dates) as $d => $part) {
                    $out['by_date'][$d][$bucket] = ($out['by_date'][$d][$bucket] ?? 0) + $part;
                }
                $out[$bucket] += $share;
            }
        }

        return $memo[$key] = $out;
    }

    /**
     * 금액을 가중치(날짜별 배달 건수)대로 나눈다. **합이 원금과 정확히 같다** — 잔돈은 마지막 몫에.
     *
     * @param array<string,int> $weights 날짜 => 건수
     * @return array<string,int> 날짜 => 금액
     */
    private static function apportion(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($weights === [] || $sum < 1) {
            return [];
        }
        $out  = [];
        $left = $amount;
        $i    = 0;
        $n    = count($weights);
        foreach ($weights as $k => $w) {
            $i++;
            $part  = $i === $n ? $left : (int) floor($amount * $w / $sum);
            $left -= $part;
            $out[$k] = $part;
        }

        return $out;
    }

    /** 기간 공제 항목 합(fee_code => amount). */
    private static function feesByCode(int $riderId, string $from, string $to): array
    {
        $out = [];
        foreach (db_rows(
            "SELECT fi.fee_code code, COALESCE(SUM(fi.amount),0) amt
               FROM settlement_fee_items fi
               JOIN settlement_rider_cycles c ON c.id=fi.cycle_id
              WHERE c.rider_id=? AND c.settlement_date BETWEEN ? AND ?
              GROUP BY fi.fee_code",
            [$riderId, $from, $to]
        ) as $r) {
            $out[(string) $r['code']] = (int) $r['amt'];
        }

        return $out;
    }

    /**
     * 일자별 상세 — 근무일자·오더수·정산금액·정산수수료(대행)·정산예정금액(net)·선지급금·차감후금액.
     *
     * @return list<array<string,int|string>>
     */
    public static function daily(int $riderId, string $from, string $to): array
    {
        // 일자별 대행수수료·선지급 공제
        $feeByDate = [];
        foreach (db_rows(
            "SELECT c.settlement_date d, fi.fee_code code, COALESCE(SUM(fi.amount),0) amt
               FROM settlement_fee_items fi
               JOIN settlement_rider_cycles c ON c.id=fi.cycle_id
              WHERE c.rider_id=? AND c.settlement_date BETWEEN ? AND ?
                AND fi.fee_code IN ('agency_fee','advance','agency_prededuct')
              GROUP BY c.settlement_date, fi.fee_code",
            [$riderId, $from, $to]
        ) as $r) {
            $feeByDate[(string) $r['d']][(string) $r['code']] = (int) $r['amt'];
        }

        // 출금 시점 수수료를 정산일에 안분한 값(요약과 같은 규칙).
        $wdByDate = self::withdrawFees($riderId, $from, $to)['by_date'];

        $rows = [];
        foreach (db_rows(
            "SELECT settlement_date d, SUM(order_count) o, SUM(support_amount) s,
                    SUM(total_fee_amount) f, SUM(net_amount) n
               FROM settlement_rider_cycles WHERE rider_id=? AND settlement_date BETWEEN ? AND ?
              GROUP BY settlement_date ORDER BY settlement_date ASC",
            [$riderId, $from, $to]
        ) as $r) {
            $d        = (string) $r['d'];
            $wdSettle = (int) ($wdByDate[$d]['settle'] ?? 0);
            $wdTrans  = (int) ($wdByDate[$d]['transfer'] ?? 0);
            $agency   = (int) ($feeByDate[$d]['agency_fee'] ?? 0) + $wdSettle;
            $advance = (int) ($feeByDate[$d]['advance'] ?? 0);
            $preded  = (int) ($feeByDate[$d]['agency_prededuct'] ?? 0); // 라이더에게 안 보인다
            $net     = (int) $r['n'];
            $rows[] = [
                'date'    => $d,
                'orders'  => (int) $r['o'],
                // 선차감을 공제에서 빼 정산금액을 낮춘다 — 요약과 같은 규칙(위 summary 주석).
                'gross'   => $net + ((int) $r['f'] - $preded) - (int) $r['s'], // 정산금액(도출) = 순액+공제−지원
                'agency'  => $agency,
                'transfer' => $wdTrans,
                'planned' => $net + $advance, // 선지급 차감 전 예정금액
                'advance' => $advance,
                // 차감 후 = 지갑 적립액 − 출금 때 라이더가 부담한 수수료(요약 실수령액과 합이 맞는다).
                'after'   => $net - $wdSettle - $wdTrans,
            ];
        }

        return $rows;
    }

    /** 추가지원금 행. */
    private static function supportRows(int $riderId, string $from, string $to): array
    {
        if (!db_table_exists('settlement_support_amounts')) {
            return [];
        }

        return db_rows(
            "SELECT settlement_date, order_no, category, amount, assigned_at
               FROM settlement_support_amounts
              WHERE rider_id=? AND settlement_date BETWEEN ? AND ? AND amount<>0
              ORDER BY settlement_date ASC, id ASC LIMIT 300",
            [$riderId, $from, $to]
        );
    }

    /**
     * 참여인정구간 — 버킷×요일 건수 + 버킷별 점수 + 총점.
     *
     * @return array{weekdays:list<string>, grid:array<string,array<string,int>>,
     *               scores:array<string,int>, total:int}
     */
    public static function participation(int $riderId, string $from, string $to): array
    {
        $weekdays = array_values(self::WEEK_ORDER);           // ['수',...'화']

        // (일자, 버킷) 건수 — 근무일자(settlement_date) 기준
        $byDate = [];   // date => bucket(원본) => count
        foreach (db_rows(
            "SELECT settlement_date d, peak_time p, COUNT(*) c
               FROM settlement_order_details
              WHERE rider_id=? AND settlement_date BETWEEN ? AND ? AND peak_time IS NOT NULL AND peak_time<>''
              GROUP BY settlement_date, peak_time",
            [$riderId, $from, $to]
        ) as $r) {
            $byDate[(string) $r['d']][(string) $r['p']] = (int) $r['c'];
        }

        // 버킷×요일 그리드 초기화
        $grid = [];
        foreach (self::BUCKETS as $raw => $label) {
            foreach ($weekdays as $wd) {
                $grid[$label][$wd] = 0;
            }
        }
        // 점수 누적
        $scores = array_fill_keys(array_values(self::BUCKETS), 0);
        $nightTotal = 0; $nightMaxDay = 0;

        foreach ($byDate as $date => $buckets) {
            $w   = (int) date('w', strtotime($date));
            $wd  = self::WEEK_ORDER[$w] ?? null;
            if ($wd === null) { continue; }
            $isWeekend = ($w === 6 || $w === 0);
            $th = $isWeekend ? self::THRESHOLD['weekend'] : self::THRESHOLD['weekday'];

            foreach ($buckets as $raw => $cnt) {
                $label = self::BUCKETS[$raw] ?? null;
                if ($label === null) { continue; }
                $grid[$label][$wd] += $cnt;

                if ($raw === 'Post_Dinner') { // 밤논피크 — 특례(주간 1회 산정)
                    $nightTotal += $cnt;
                    $nightMaxDay = max($nightMaxDay, $cnt);
                } elseif (($th[$raw] ?? PHP_INT_MAX) <= $cnt) { // 오전/점심/오후/저녁 — 일자별 1점
                    $scores[$label] += 1;
                }
            }
        }
        // 밤논피크: 어느 하루라도 20건↑ 2점, 아니면 참여했으면 1점.
        $scores['밤논피크'] = $nightMaxDay >= 20 ? 2 : ($nightTotal > 0 ? 1 : 0);

        return [
            'weekdays' => $weekdays,
            'grid'     => $grid,
            'scores'   => $scores,
            'total'    => array_sum($scores),
        ];
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/RiderDebt.php';
require_once __DIR__ . '/MessageQueue.php';
require_once __DIR__ . '/Org.php';
require_once __DIR__ . '/StatementLink.php';

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
        $totalFee = (int) $cyc['f'];

        // 개별 표기 공제 항목들.
        $withholding = $get('withholding');
        $employment  = $get('employment_ins');
        $accident    = $get('accident_ins');
        $hourly      = $get('hourly_ins');
        $agencyFee   = $get('agency_fee');

        // 「차감액」은 **잔여 흡수(catch-all)** — 총공제(total_fee_amount)에서 개별 표기 항목을 뺀
        // 나머지(엑셀 차감내역·수동차감, 그리고 아직 개별 표기하지 않는 새 코드까지)를 담는다.
        // 이렇게 하면 표시 공제 합 == total_fee 가 되어 "정산금액 + 지원 − 공제 = 실수령" 균형이
        // 어떤 fee_code 조합에서도 깨지지 않는다.
        $deduction = $totalFee - ($withholding + $employment + $accident + $hourly + $agencyFee + $advance + $fixed);

        // 정산금액 = 실수령 + 총공제 − 지원금 (PDF 로직: 정산금액 + 지원 − 공제 = 실수령).
        // net_amount 이 gross−fee 와 안 맞는 구 데이터가 있어 도출값으로 항상 균형을 맞춘다.
        $settleAmount = (int) $cyc['n'] + $totalFee - (int) $cyc['s'];

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
            'agency_fee'    => $agencyFee,
            'advance'       => $advance,
            'fixed'         => $fixed,
            'total_fee'     => $totalFee,
            'net'           => (int) $cyc['n'],
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
                $text = self::compactText($riderId, $from, $to, (string) ($row['rider_name'] ?? ''));
                // 모바일 명세서 링크 — 파일 대신 링크로 상세 명세서를 열 수 있게 첨부.
                if (StatementLink::ready()) {
                    try {
                        $link = StatementLink::create($riderId, $from, $to, $adminId);
                        $text .= "\n\n▶ 상세 명세서 보기\n" . $link['url'];
                    } catch (Throwable) {
                        // 링크 생성 실패는 발송 자체를 막지 않는다(텍스트만 발송).
                    }
                }
                MessageQueue::enqueueForRider($riderId, 'alimtalk', $text, '정산 명세서', $adminId);
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
                AND fi.fee_code IN ('agency_fee','advance')
              GROUP BY c.settlement_date, fi.fee_code",
            [$riderId, $from, $to]
        ) as $r) {
            $feeByDate[(string) $r['d']][(string) $r['code']] = (int) $r['amt'];
        }

        $rows = [];
        foreach (db_rows(
            "SELECT settlement_date d, SUM(order_count) o, SUM(support_amount) s,
                    SUM(total_fee_amount) f, SUM(net_amount) n
               FROM settlement_rider_cycles WHERE rider_id=? AND settlement_date BETWEEN ? AND ?
              GROUP BY settlement_date ORDER BY settlement_date ASC",
            [$riderId, $from, $to]
        ) as $r) {
            $d       = (string) $r['d'];
            $agency  = (int) ($feeByDate[$d]['agency_fee'] ?? 0);
            $advance = (int) ($feeByDate[$d]['advance'] ?? 0);
            $net     = (int) $r['n'];
            $rows[] = [
                'date'    => $d,
                'orders'  => (int) $r['o'],
                'gross'   => $net + (int) $r['f'] - (int) $r['s'], // 정산금액(도출) = 순액+공제−지원
                'agency'  => $agency,
                'planned' => $net + $advance, // 선지급 차감 전 예정금액
                'advance' => $advance,
                'after'   => $net,            // 차감 후(실제 반영)
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

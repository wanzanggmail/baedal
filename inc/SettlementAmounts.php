<?php

declare(strict_types=1);

/**
 * 정산 엑셀 원본 금액 산식.
 *
 * 정산 기준액 = 부가세 제외 공급가액(픽업·배달·할증·프로모 합, 없으면 엑셀 총액).
 * 부가세는 어디에도 넣지 않는다. 보수액(payout)은 저장만 하고 계산에 쓰지 않는다.
 * 엑셀에서 빼는 항목 = 시간제보험 + 차감내역 탭.
 */
final class SettlementAmounts
{
    /** @param array<string, mixed> $row settlement_daily_riders */
    public static function earn(array $row): int
    {
        return (int) ($row['fee_pickup'] ?? 0)
            + (int) ($row['fee_delivery'] ?? 0)
            + (int) ($row['fee_area'] ?? 0)
            + (int) ($row['fee_dist_surge'] ?? 0)
            + (int) ($row['fee_pickup_surge'] ?? 0)
            + (int) ($row['fee_dest_surge'] ?? 0)
            + (int) ($row['fee_weather'] ?? 0)
            + (int) ($row['fee_promo1'] ?? 0)
            + (int) ($row['fee_promo2'] ?? 0)
            + (int) ($row['fee_promo3'] ?? 0)
            + (int) ($row['fee_promo4'] ?? 0);
    }

    /**
     * 부가세 제외 정산액. 쿠팡은 공급가액(구성 합), 배민 등 구성이 없으면 엑셀 총액.
     *
     * @param array<string, mixed> $row
     */
    public static function exVat(array $row): int
    {
        $earn = self::earn($row);
        if ($earn > 0) {
            return $earn;
        }

        return max(0, (int) ($row['gross_amount'] ?? 0));
    }

    /** 대시보드 등 SQL 집계용 — `exVat()` 와 동일. */
    public static function sqlExVatExpr(string $alias = 'sdr'): string
    {
        $a    = $alias !== '' ? $alias . '.' : '';
        $earn = "({$a}fee_pickup + {$a}fee_delivery + {$a}fee_area + {$a}fee_dist_surge"
            . " + {$a}fee_pickup_surge + {$a}fee_dest_surge + {$a}fee_weather"
            . " + {$a}fee_promo1 + {$a}fee_promo2 + {$a}fee_promo3 + {$a}fee_promo4)";

        return "(CASE WHEN {$earn} > 0 THEN {$earn} ELSE {$a}gross_amount END)";
    }

    /**
     * 이 업로드·라이더의 엑셀 차감내역(양수 공제액).
     *
     * @return list<array{fee_code: string, label: string, amount: int, order_date: string, type: string, store_name: string}>
     */
    public static function excelDeductions(int $uploadId, int $riderId, string $nameRaw = ''): array
    {
        if ($uploadId < 1 || !db_table_exists('settlement_weekly_deductions')) {
            return [];
        }

        $parts  = [];
        $params = [$uploadId];
        if ($riderId > 0) {
            $parts[]  = 'rider_id = ?';
            $params[] = $riderId;
        }
        if ($nameRaw !== '') {
            $parts[]  = 'rider_name_raw = ?';
            $params[] = $nameRaw;
        }
        if ($parts === []) {
            return [];
        }

        $rows = db_rows(
            'SELECT deduction_type, store_name, amount, order_date
               FROM settlement_weekly_deductions
              WHERE upload_id = ? AND (' . implode(' OR ', $parts) . ')
              ORDER BY id ASC',
            $params
        );

        $out = [];
        foreach ($rows as $w) {
            $amt = abs((int) ($w['amount'] ?? 0));
            if ($amt <= 0) {
                continue;
            }
            $type  = trim((string) ($w['deduction_type'] ?? ''));
            $store = trim((string) ($w['store_name'] ?? ''));
            $label = $type !== '' ? $type : '차감내역';
            if ($store !== '') {
                $label .= ' · ' . $store;
            }
            $out[] = [
                'fee_code'   => 'excel_deduction',
                'label'      => $label,
                'amount'     => $amt,
                'order_date' => (string) ($w['order_date'] ?? ''),
                'type'       => $type,
                'store_name' => $store,
            ];
        }

        return $out;
    }
}

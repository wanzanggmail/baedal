<?php

declare(strict_types=1);

require_once __DIR__ . '/FirmTransfer.php';
require_once __DIR__ . '/BaumFirmGateway.php';
require_once __DIR__ . '/FirmWebhook.php';

/**
 * 미확정 이체 보정 조회 — 웹훅이 못 온 건을 우리가 먼저 물어본다.
 *
 * 왜 필요한가: 바움은 처리결과 통보를 **1분 간격 최대 10회** 보내고 그만둔다. 그 10분
 * 사이에 우리 서버가 죽어 있었거나 네트워크가 끊겼으면 **결과를 영영 못 받는다.**
 * 그러면 출금이 `transferring` 에 영원히 머물고, 라이더는 재신청도 못 한다
 * (`hasOpenRiderRequest()` 가 진행중으로 본다).
 *
 * 그래서 미확정 행을 `transfer-info` 로 직접 조회해 같은 확정 처리를 태운다.
 * 확정 로직은 **웹훅과 공유한다**(`FirmWebhook::applyResult` 와 같은 경로를 타도록
 * `FirmTransfer::updateStatus()` 의 멱등 보장을 그대로 쓴다) — 두 경로가 서로 다른
 * 처리를 하면 "웹훅으로 확정한 건과 보정으로 확정한 건이 다르게 처리되는" 사고가 난다.
 */
final class FirmReconciler
{
    /**
     * 미확정 건을 조회해 결과를 반영한다.
     *
     * @param int $minAgeMinutes 접수 후 이 시간이 지난 건만 본다(웹훅이 올 시간을 준다)
     * @return array{checked:int, finalized:int, still_pending:int, errors:int, orphans:int, details:list<string>}
     */
    public static function run(int $minAgeMinutes = 5, int $limit = 100): array
    {
        $out = ['checked' => 0, 'finalized' => 0, 'still_pending' => 0, 'errors' => 0, 'orphans' => 0, 'details' => []];

        require_once __DIR__ . '/FirmConfig.php';
        if (!FirmConfig::isReady()) {
            $out['details'][] = '실 연동이 꺼져 있어 조회하지 않았습니다.';

            return $out;
        }

        // 장부에 아예 없는 「접수중」 — 자동 처리하지 않고 사람이 보게 남긴다.
        $orphans = FirmTransfer::orphanTransferring(max(10, $minAgeMinutes));
        if ($orphans !== []) {
            $out['orphans'] = count($orphans);
            $out['details'][] = sprintf(
                '⚠️ 접수 기록이 없는 「접수중」 출금 %d건(#%s) — 바움 관리자에서 실제 이체 여부를 확인하세요.',
                count($orphans),
                implode(', #', array_map(static fn (array $r): string => (string) $r['id'], array_slice($orphans, 0, 10)))
            );
        }

        $rows = FirmTransfer::pending($minAgeMinutes, $limit);
        if ($rows === []) {
            return $out;
        }

        $gw = new BaumFirmGateway();
        foreach ($rows as $tr) {
            $txId = (string) $tr['transaction_id'];
            $out['checked']++;

            try {
                $info = $gw->transferInfo($txId);
            } catch (Throwable $e) {
                $out['errors']++;
                $out['details'][] = $txId . ' — 조회 오류: ' . $e->getMessage();
                continue;
            }

            // 바움이 «그런 거래 없다» 고 하면 **전송되지 않은 것**이다(접수 직전에 터진 경우).
            // 영원히 접수중으로 두면 라이더가 재신청도 못 하므로 실패로 확정해 재시도를 열어 준다.
            if (strtoupper((string) ($info['code'] ?? '')) === 'RESOURCE_NOT_EXISTS') {
                if (FirmTransfer::updateStatus($txId, BaumFirmGateway::ST_FAILED, '바움에 접수 기록 없음(전송되지 않음)')) {
                    $out['finalized']++;
                    $out['details'][] = $txId . ' — 접수 기록 없음 → 실패 처리' . self::apply($tr, BaumFirmGateway::ST_FAILED, '바움에 접수 기록이 없습니다(전송되지 않음)');
                }
                continue;
            }

            if (!$info['ok'] || $info['status'] === '') {
                $out['errors']++;
                $out['details'][] = $txId . ' — 조회 실패: ' . ($info['message'] !== '' ? $info['message'] : '상태 없음');
                FirmTransfer::touchChecked($txId);
                continue;
            }

            $status = strtoupper($info['status']);
            if (!FirmTransfer::isFinal($status)) {
                // 아직 진행 중 — 다음 회차에 다시 본다.
                FirmTransfer::touchChecked($txId);
                $out['still_pending']++;
                continue;
            }

            // 금액이 장부와 다르면 확정하지 않는다 — 웹훅과 같은 기준이어야 한다.
            $infoAmount = (int) ($info['amount'] ?? 0);
            if ($infoAmount > 0 && $infoAmount !== (int) $tr['amount']) {
                $out['errors']++;
                $out['details'][] = sprintf(
                    '%s — 금액 불일치(조회 %s원 / 장부 %s원) · 사람이 확인해야 합니다',
                    $txId,
                    number_format($infoAmount),
                    number_format((int) $tr['amount'])
                );
                FirmTransfer::touchChecked($txId);
                continue;
            }

            $reason  = trim((string) ($info['data']['resultMessage'] ?? ''));
            $changed = FirmTransfer::updateStatus($txId, $status, $status === BaumFirmGateway::ST_SUCCESS ? '' : $reason);
            if (!$changed) {
                // 그 사이 웹훅이 먼저 확정했다 — 정상이다. 두 번 처리하지 않는다.
                $out['details'][] = $txId . ' — 이미 확정됨(웹훅 선처리)';
                continue;
            }

            $out['finalized']++;
            $out['details'][] = $txId . ' — ' . $status . self::apply($tr, $status, $reason);
        }

        return $out;
    }

    /**
     * 확정 결과를 원본 장부에 반영.
     *
     * `FirmWebhook::applyResult()` 와 같은 일을 한다. 한쪽만 고치는 사고를 막으려면
     * 언젠가 한 곳으로 합치는 게 맞지만, 지금은 출금 한 종류뿐이라 짧게 둔다.
     *
     * @param array<string,mixed> $tr
     */
    private static function apply(array $tr, string $status, string $reason): string
    {
        $kind = (string) $tr['kind'];
        if (!in_array($kind, [FirmTransfer::KIND_WITHDRAWAL, FirmTransfer::KIND_AGENCY_PAYOUT, FirmTransfer::KIND_DAILY_PAYOUT], true)) {
            return ' · ' . $kind . ' 후속 처리 미구현';
        }

        require_once __DIR__ . '/Withdrawal.php';
        $refId = (int) $tr['ref_id'];

        if ($status === BaumFirmGateway::ST_SUCCESS) {
            $ok = Withdrawal::finalizeSuccess(
                $refId,
                '펌뱅킹 이체 완료(보정 조회) · 바움P&S · 접수번호 ' . (string) $tr['reception_id']
            );

            return $ok ? ' · 출금 #' . $refId . ' 완료 확정' : ' · 출금 #' . $refId . ' 이미 처리됨';
        }

        $msg = $status === BaumFirmGateway::ST_CANCELLED ? '이체 취소됨' : '이체 실패';
        if ($reason !== '') {
            $msg .= ' — ' . $reason;
        }
        $ok = Withdrawal::markTransferFailed($refId, $msg . ' (보정 조회)');

        return $ok ? ' · 출금 #' . $refId . ' 실패 처리' : ' · 출금 #' . $refId . ' 상태 변화 없음';
    }
}

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
     * @return array{checked:int, finalized:int, still_pending:int, errors:int, details:list<string>}
     */
    public static function run(int $minAgeMinutes = 5, int $limit = 100): array
    {
        $out = ['checked' => 0, 'finalized' => 0, 'still_pending' => 0, 'errors' => 0, 'details' => []];

        require_once __DIR__ . '/FirmConfig.php';
        if (!FirmConfig::isReady()) {
            $out['details'][] = '실 연동이 꺼져 있어 조회하지 않았습니다.';

            return $out;
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
        if ((string) $tr['kind'] !== FirmTransfer::KIND_WITHDRAWAL) {
            return ' · ' . (string) $tr['kind'] . ' 후속 처리 미구현';
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

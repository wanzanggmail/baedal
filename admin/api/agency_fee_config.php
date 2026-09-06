<?php

declare(strict_types=1);

/**
 * 대행수수료 설정 API
 * GET  — 현재 설정 + 본사가 정한 최저금액
 * POST { "action": "save_prededuct", prededuct_fee, [agency_id] }  — 대리점 선차감
 *      { "action": "save_min", min_fee_per_tx_short, min_fee_per_tx_long }  — **본사 전용**
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/AgencyFeeConfig.php';
require_once INC_PATH . '/AuditLog.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '인증이 필요합니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!admin_can_access_route('deduction/agency-fee')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '권한이 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$err = static function (string $msg, int $code = 422): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

// 멀티테넌시: 대리점=자기 설정 / 본사=전역 기본 / 총판=전역 기본을 "조회만"
require_once INC_PATH . '/Org.php';
$level    = admin_org_level();
$isAgency = $level === Org::LEVEL_AGENCY;
$isHq     = $level === Org::LEVEL_ADMIN;
$cfgOrgId = $isAgency ? admin_org_id() : null;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ⚠️ 전역 기본값(org_id NULL)은 **본사만** 바꾼다.
// 총판도 이 화면의 라우트·쓰기 권한을 통과하는데, 저장 대상이 NULL이라 그대로 두면
// 총판이 누른 저장이 **전용 설정이 없는 모든 대리점**의 기본값을 덮어쓴다(테넌시 유출).
if ($method === 'POST' && !$isAgency && !$isHq) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'message' => '총판 계정은 대행수수료 기본값을 변경할 수 없습니다. (조회만 가능 — 전역 기본은 본사, 대리점별 설정은 해당 대리점이 관리)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    try {
        echo json_encode([
            'ok'           => true,
            'config'       => AgencyFeeConfig::get($cfgOrgId),
            'table_ready'  => AgencyFeeConfig::tableReady(),
            'scope'        => $cfgOrgId !== null ? 'agency' : 'global',
            'minimum'      => AgencyFeeConfig::minimums(),
            'min_ready'    => AgencyFeeConfig::minimumReady(),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $err('조회 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

if ($method !== 'POST') {
    $err('허용되지 않은 메서드입니다.', 405);
}

admin_deny_write_json('deduction');

$raw  = file_get_contents('php://input');
$ct   = $_SERVER['CONTENT_TYPE'] ?? '';
$body = str_contains($ct, 'application/json')
    ? (array) json_decode($raw ?: '{}', true)
    : $_POST;

$action = trim((string) ($body['action'] ?? 'save'));

// 최저금액은 **본사만** 정한다. 대리점이 자기 하한을 정하면 하한이 아니게 되고,
// 총판은 위에서 이미 쓰기가 막혀 있다.
if ($action === 'save_min') {
    if (!$isHq) {
        $err('최저금액은 본사만 설정할 수 있습니다.', 403);
    }
    try {
        $r = AgencyFeeConfig::saveMinimums($body);
        AuditLog::record(
            'deduction.agency_fee.min',
            'deduction_global_config',
            sprintf('대행수수료 최저 — 기준 미만 %d원 / 이상 %d원', $r['min']['fee_per_tx_short'], $r['min']['fee_per_tx_long'])
        );
        $msg = '최저금액이 저장되었습니다.';
        if ($r['below'] !== []) {
            $msg .= sprintf(' ⚠️ 이미 최저보다 낮게 설정된 대리점 %d곳이 있습니다(기존 설정은 그대로 두었습니다).', count($r['below']));
        }
        if ($r['global_below']) {
            $msg .= ' ⚠️ 전역 기본값이 최저보다 낮습니다 — 전용 설정이 없는 대리점이 최저를 우회합니다.';
        }
        echo json_encode(['ok' => true, 'message' => $msg, 'minimum' => $r['min'], 'below' => $r['below']], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        $err($e->getMessage(), 422);
    } catch (Throwable $e) {
        $err('저장 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

// 공제 요율(원천세·고용·산재)도 **본사만** 정한다 — 법정요율이라 대리점이 바꿀 값이 아니다.
if ($action === 'save_rates') {
    if (!$isHq) {
        $err('공제 요율은 본사만 설정할 수 있습니다.', 403);
    }
    try {
        $r = AgencyFeeConfig::saveRates($body);
        AuditLog::record(
            'deduction.rates',
            'deduction_global_config',
            sprintf(
                '공제 요율 — 원천세 %.2f%% / 고용 %.2f%% / 산재 %.2f%%',
                $r['rates']['withholding_tax_pct'],
                $r['rates']['employment_ins_pct'],
                $r['rates']['industrial_accident_ins_pct']
            )
        );
        $msg = '공제 요율이 저장되었습니다.';
        if ($r['synced_orgs'] > 0) {
            $msg .= sprintf(' (대리점 전용 설정 %d건도 같은 요율로 맞췄습니다)', $r['synced_orgs']);
        }
        echo json_encode(['ok' => true, 'message' => $msg, 'rates' => $r['rates']], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        $err($e->getMessage(), 422);
    } catch (Throwable $e) {
        $err('저장 실패: ' . $e->getMessage(), 500);
    }
    exit;
}

// 선차감만 저장 — 「수수료 설정(관리)」(본사가 대리점을 골라 여는 화면)에서 온다.
// 대행수수료 입력칸이 없는 화면이라 save 를 쓰면 그 대리점 요율이 조용히 덮인다(savePrededuct 주석 참고).
// 본사는 `agency_id` 로 대상 대리점을 지정할 수 있고, 대리점은 자기 것만 저장한다.
if ($action === 'save_prededuct') {
    $targetOrgId = $cfgOrgId;
    if ($isHq && array_key_exists('agency_id', $body)) {
        $wanted = (int) $body['agency_id'];
        if ($wanted > 0) {
            $org = db_row('SELECT id, name, level FROM organizations WHERE id = ? LIMIT 1', [$wanted]);
            if ($org === null || (string) $org['level'] !== Org::LEVEL_AGENCY) {
                $err('대리점을 찾을 수 없습니다.', 404);
            }
            $targetOrgId = (int) $org['id'];
        } else {
            $targetOrgId = null; // 0 = 전역 기본값
        }
    }

    try {
        $before = AgencyFeeConfig::prededuct($targetOrgId);
        $after  = AgencyFeeConfig::savePrededuct((int) ($body['prededuct_fee'] ?? 0), $targetOrgId);

        $scope = '전역 기본값';
        if ($targetOrgId !== null && $targetOrgId > 0) {
            $o     = db_row('SELECT name FROM organizations WHERE id = ? LIMIT 1', [$targetOrgId]);
            $scope = (string) ($o['name'] ?? ('조직#' . $targetOrgId));
        }
        // 라이더 실수령을 줄이는 값이라 금액 변화를 그대로 남긴다(분쟁 대비).
        AuditLog::record(
            'deduction.prededuct.save',
            'deduction_global_config',
            $before === $after
                ? sprintf('[%s] 선차감 %d원(변경 없음)', $scope, $after)
                : sprintf('[%s] 선차감 %d원 → %d원', $scope, $before, $after)
        );
        echo json_encode(['ok' => true, 'message' => '저장되었습니다.', 'prededuct_fee' => $after], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        $err($e->getMessage(), 422);
    } catch (Throwable $e) {
        $err('저장 실패: ' . $e->getMessage(), 500);
    }
    exit;
}
// 대행수수료 요율 저장(action=save)은 2026-09-07 폐지 — 정산수수료와 통합됐다.
// 이 화면에 남은 저장 액션은 save_min · save_rates · save_prededuct 셋뿐이다.
$err('action=save_min, save_rates, save_prededuct 중 하나여야 합니다. (대행수수료 요율은 폐지되고 정산수수료로 통합됐습니다)', 400);

<?php

declare(strict_types=1);

/**
 * 모바일 명세서 공개 페이지(2026-09-01 갑) — 알림톡 링크로 로그인 없이 여는 라이더 정산 명세서.
 * GET ?t=토큰 (statement_links). 토큰이 없거나 만료면 안내만 표시하고 데이터는 노출하지 않는다.
 *
 * 관리자 화면과 무관한 **자체 완결형 경량 HTML**(Metronic/Bootstrap 미사용) — 폰에서 빠르게 뜨도록.
 */

require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';
require_once INC_PATH . '/StatementLink.php';
require_once INC_PATH . '/RiderStatement.php';

$esc = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$won = static fn ($n): string => number_format((int) $n) . '원';

$token = (string) ($_GET['t'] ?? '');
$link  = StatementLink::resolve($token);

header('Content-Type: text/html; charset=utf-8');
// 개인정보 — 검색엔진 색인·중간 캐시 방지
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');

/** 공통 셸 — $bodyHtml 을 감싸 출력하고 종료. */
$render = static function (string $title, string $bodyHtml) use ($esc): never {
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="robots" content="noindex,nofollow">';
    echo '<title>' . $esc($title) . '</title>';
    echo '<style>' . <<<'CSS'
*{box-sizing:border-box;-webkit-text-size-adjust:100%}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Apple SD Gothic Neo","Malgun Gothic",system-ui,sans-serif;
  background:#f2f4f7;color:#1f2733;line-height:1.5;font-size:15px}
.wrap{max-width:560px;margin:0 auto;padding:16px 14px 48px}
.head{text-align:center;padding:20px 12px 12px}
.head h1{margin:0 0 6px;font-size:1.35rem;letter-spacing:-.02em}
.head .sub{font-size:.82rem;color:#5b6673}
.head .chip{display:inline-block;margin-top:8px;background:#eef2ff;color:#3b4cca;border-radius:999px;padding:3px 12px;font-size:.8rem;font-weight:600}
.card{background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(16,24,40,.06),0 1px 2px rgba(16,24,40,.04);
  padding:16px 14px;margin:12px 0}
.card h2{margin:0 0 10px;font-size:.98rem;font-weight:700;color:#111927;
  display:flex;align-items:center;gap:6px}
.card h2:before{content:"";width:4px;height:15px;background:#3b4cca;border-radius:2px;display:inline-block}
.kv{display:flex;justify-content:space-between;align-items:baseline;padding:7px 2px;border-bottom:1px solid #f0f2f5}
.kv:last-child{border-bottom:0}
.kv .k{color:#5b6673;font-size:.88rem}
.kv .v{font-weight:600;font-variant-numeric:tabular-nums}
.kv.minus .v{color:#c0341d}
.kv.total{margin-top:6px;padding-top:12px;border-top:2px solid #e6e9ef;border-bottom:0}
.kv.total .k{font-weight:700;color:#111927}
.kv.total .v{font-size:1.15rem;color:#111927}
.hl{background:#fff8e6;border-radius:10px;padding:12px 14px;margin-top:8px;text-align:center}
.hl .lbl{font-size:.8rem;color:#7a6a2f}
.hl .amt{font-size:1.5rem;font-weight:800;color:#1f2733;font-variant-numeric:tabular-nums}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -4px}
table{border-collapse:collapse;width:100%;font-size:.8rem;min-width:100%}
th,td{padding:7px 8px;text-align:center;white-space:nowrap;border-bottom:1px solid #eef0f3}
thead th{background:#f7f8fa;color:#5b6673;font-weight:600;position:sticky;top:0}
tbody tr:last-child td{border-bottom:0}
td.num,th.num{font-variant-numeric:tabular-nums;text-align:right}
td.pos{background:#eafaf1;font-weight:700;color:#0f7a44}
td.zero{color:#b6bcc6}
.empty{color:#8a929e;text-align:center;padding:18px 0;font-size:.85rem}
.foot{text-align:center;color:#98a1ad;font-size:.72rem;margin-top:22px;line-height:1.7}
.notice{max-width:420px;margin:14vh auto;padding:28px 22px;background:#fff;border-radius:16px;text-align:center;
  box-shadow:0 2px 10px rgba(16,24,40,.08)}
.notice .ico{font-size:2.4rem}
.notice h1{font-size:1.15rem;margin:10px 0 6px}
.notice p{color:#5b6673;font-size:.9rem;margin:0}
CSS
    . '</style></head><body>' . $bodyHtml . '</body></html>';
    exit;
};

if ($link === null) {
    $render('명세서를 찾을 수 없습니다', '<div class="notice"><div class="ico">🔒</div>'
        . '<h1>명세서를 열 수 없습니다</h1>'
        . '<p>링크가 만료되었거나 올바르지 않습니다.<br>대리점에 문의해 주세요.</p></div>');
}

$riderId = (int) $link['rider_id'];
$from    = (string) $link['period_from'];
$to      = (string) $link['period_to'];

$rider = db_row(
    'SELECT r.name, r.is_daily_settlement, o.name AS agency_name
       FROM riders r LEFT JOIN organizations o ON o.id = r.agency_id
      WHERE r.id = ? LIMIT 1',
    [$riderId]
);
if ($rider === null) {
    $render('명세서를 찾을 수 없습니다', '<div class="notice"><div class="ico">🔒</div>'
        . '<h1>명세서를 열 수 없습니다</h1><p>대상 정보를 찾을 수 없습니다.</p></div>');
}

StatementLink::markViewed($token);

try {
    $st = RiderStatement::build($riderId, $from, $to);
} catch (Throwable $e) {
    error_log('statement public build failed: ' . $e->getMessage());
    $render('명세서를 불러올 수 없습니다', '<div class="notice"><div class="ico">⚠️</div>'
        . '<h1>명세서를 불러올 수 없습니다</h1><p>잠시 후 다시 시도하거나 대리점에 문의해 주세요.</p></div>');
}
$sm = $st['summary'];
$pt = $st['participation'];

// ── 본문 조립 ──────────────────────────────────────────────
ob_start();
?>
<div class="wrap">
	<div class="head">
		<h1>주급 명세서</h1>
		<div class="sub"><?= $esc($rider['name']) ?> · <?= (int) ($rider['is_daily_settlement'] ?? 0) === 1 ? '선정산' : '주정산' ?><?= $rider['agency_name'] ? ' · ' . $esc($rider['agency_name']) : '' ?></div>
		<div class="sub"><?= $esc($from) ?> ~ <?= $esc($to) ?></div>
		<span class="chip">참여인정구간 <?= (int) $pt['total'] ?>구간</span>
	</div>

	<!-- 정산 요약 -->
	<div class="card">
		<h2>정산 요약</h2>
		<div class="kv"><span class="k">총 오더수</span><span class="v"><?= number_format((int) $sm['orders']) ?>건</span></div>
		<div class="kv"><span class="k">정산금액</span><span class="v"><?= $won($sm['settle_amount']) ?></span></div>
		<div class="kv"><span class="k">프로모션</span><span class="v"><?= (int) $sm['promo'] === 0 ? '0원' : $won($sm['promo']) ?></span></div>
		<?php if ((int) $sm['promo2'] > 0) : ?><div class="kv"><span class="k">프로모션2</span><span class="v"><?= $won($sm['promo2']) ?></span></div><?php endif; ?>
		<div class="kv"><span class="k">지원금 합계</span><span class="v"><?= $won($sm['support']) ?></span></div>
	</div>

	<!-- 차감 내역 -->
	<div class="card">
		<h2>차감 내역</h2>
		<?php
		$deducts = [
			'차감액'      => (int) $sm['deduction'],
			'원천세'      => (int) $sm['withholding'],
			'고용보험'    => (int) $sm['employment'],
			'산재보험'    => (int) $sm['accident'],
			'시간제보험'  => (int) $sm['hourly_ins'],
			'정산수수료'  => (int) $sm['agency_fee'],
			'선지급차감'  => (int) $sm['advance'],
			'고정차감'    => (int) $sm['fixed'],
		];
		$anyDeduct = false;
		foreach ($deducts as $label => $amt) :
			if ($amt <= 0) { continue; }
			$anyDeduct = true; ?>
			<div class="kv minus"><span class="k"><?= $esc($label) ?></span><span class="v">-<?= $won($amt) ?></span></div>
		<?php endforeach; ?>
		<?php if (!$anyDeduct) : ?><div class="empty">차감 항목이 없습니다.</div><?php endif; ?>
		<div class="hl"><div class="lbl">실수령액</div><div class="amt"><?= $won($sm['net']) ?></div></div>
	</div>

	<!-- 일자별 상세 -->
	<div class="card">
		<h2>일자별 상세</h2>
		<div class="scroll"><table>
			<thead><tr><th>근무일자</th><th>오더</th><th>정산금액</th><th>수수료</th><th>예정금액</th><th>선지급</th><th>차감후</th></tr></thead>
			<tbody>
			<?php if ($st['daily'] === []) : ?>
				<tr><td colspan="7" class="empty">해당 기간 정산 내역이 없습니다.</td></tr>
			<?php else : foreach ($st['daily'] as $d) : ?>
				<tr>
					<td><?= $esc((string) $d['date']) ?></td>
					<td class="num"><?= number_format((int) $d['orders']) ?></td>
					<td class="num"><?= $won($d['gross']) ?></td>
					<td class="num"><?= $won($d['agency']) ?></td>
					<td class="num"><?= $won($d['planned']) ?></td>
					<td class="num"><?= $won($d['advance']) ?></td>
					<td class="num" style="font-weight:700"><?= $won($d['after']) ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table></div>
	</div>

	<!-- 추가지원금 -->
	<?php if ($st['support_rows'] !== []) : ?>
	<div class="card">
		<h2>추가지원금</h2>
		<div class="scroll"><table>
			<thead><tr><th>주문일자</th><th>ID</th><th>구분</th><th>금액</th></tr></thead>
			<tbody>
			<?php foreach ($st['support_rows'] as $sr) : ?>
				<tr>
					<td><?= $esc((string) ($sr['assigned_at'] ?: $sr['settlement_date'])) ?></td>
					<td><?= $esc((string) ($sr['order_no'] ?? '')) ?></td>
					<td><?= $esc((string) ($sr['category'] ?? '')) ?></td>
					<td class="num"><?= $won($sr['amount']) ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>
	<?php endif; ?>

	<!-- 참여인정구간 -->
	<div class="card">
		<h2>참여인정구간</h2>
		<div class="kv total"><span class="k">총 참여인정구간</span><span class="v"><?= (int) $pt['total'] ?>구간</span></div>
		<?php foreach (RiderStatement::BUCKETS as $lb) : ?>
		<div class="kv"><span class="k"><?= $esc($lb) ?></span><span class="v"><?= (int) ($pt['scores'][$lb] ?? 0) ?></span></div>
		<?php endforeach; ?>
	</div>

	<!-- 요일/구간별 수행 건수 -->
	<div class="card">
		<h2>요일·구간별 수행 건수</h2>
		<div class="scroll"><table>
			<thead><tr><th>구간＼요일</th><?php foreach ($pt['weekdays'] as $wd) : ?><th><?= $esc($wd) ?></th><?php endforeach; ?></tr></thead>
			<tbody>
			<?php foreach (RiderStatement::BUCKETS as $lb) : ?>
				<tr>
					<td style="font-weight:600"><?= $esc($lb) ?></td>
					<?php foreach ($pt['weekdays'] as $wd) : $v = (int) ($pt['grid'][$lb][$wd] ?? 0); ?>
					<td class="<?= $v > 0 ? 'pos' : 'zero' ?>"><?= $v ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	</div>

	<div class="foot">
		본 명세서는 <?= $esc($rider['name']) ?>님 개인용입니다. 링크 공유에 주의하세요.<br>
		문의는 소속 대리점으로 연락해 주세요.
	</div>
</div>
<?php
$body = ob_get_clean();
$render($rider['name'] . '님 정산 명세서', (string) $body);

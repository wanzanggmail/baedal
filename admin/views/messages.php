<?php

declare(strict_types=1);

require_once INC_PATH . '/MessageQueue.php';
require_once INC_PATH . '/MessagingConfig.php';

if (!admin_has_role('super')) {
    require_once INC_PATH . '/app_content_open.php';
    echo '<div class="alert alert-danger p-5">문자·알림톡은 본사 최고관리자만 관리할 수 있습니다.</div>';
    require_once INC_PATH . '/app_content_close.php';

    return;
}

$needsMigrate = !MessageQueue::ready();
$esc  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$apiUrl = ADMIN_BASE . '/api/messages.php';
$counts = $needsMigrate ? [] : MessageQueue::counts();
$rows   = $needsMigrate ? [] : MessageQueue::listQueue([], 200);
$mcfg   = MessagingConfig::get();
$statusBadge = ['queued' => 'warning', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger', 'canceled' => 'secondary'];
?>
<!--begin::Toolbar-->
<div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
	<div id="kt_app_toolbar_container" class="app-container container-xxl d-flex flex-stack">
		<div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
			<h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0">문자·알림톡</h1>
			<ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
				<li class="breadcrumb-item text-muted">시스템 관리</li>
				<li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
				<li class="breadcrumb-item text-gray-900">발송 큐</li>
			</ul>
		</div>
	</div>
</div>
<!--end::Toolbar-->
<?php require_once INC_PATH . '/app_content_open.php'; ?>

	<?php if ($needsMigrate) : ?>
	<div class="alert alert-warning mb-8">서버에서 <code>php migrate.php</code> 를 실행하세요.</div>
	<?php else : ?>

	<div id="msg_toast" class="alert alert-dismissible d-none mb-6" role="alert"><span id="msg_toast_msg"></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>

	<div class="alert bg-light-warning fs-8 p-3 mb-6">🧪 <strong>모의(mock) 발송</strong> — 실제 SMS/알림톡 발송사 계약 전까지 「발송」을 눌러도 실제로 나가지 않고 <strong>발송완료로 기록만</strong> 됩니다. 실 연동 시 발송 로직 한 곳만 교체하면 됩니다.</div>

	<!--begin::알림톡·문자 설정-->
	<div class="card card-flush mb-6">
		<div class="card-header pt-5" data-bs-toggle="collapse" data-bs-target="#msg_cfg_body" role="button" style="cursor:pointer">
			<h3 class="card-title fw-bold">알림톡·문자 설정</h3>
			<div class="card-toolbar"><span class="text-muted fs-8">발신번호·알림톡 채널·명세서 링크</span></div>
		</div>
		<div id="msg_cfg_body" class="collapse">
			<div class="card-body pt-2 fs-7">
				<div class="row g-4">
					<div class="col-md-4">
						<label class="form-label">발신번호</label>
						<input type="tel" class="form-control form-control-solid" id="cfg_sender_phone" value="<?= $esc($mcfg['sender_phone']) ?>" placeholder="028881234" />
						<div class="form-text fs-9">문자·알림톡이 발신되는 번호(발송사에 사전 등록 필요).</div>
					</div>
					<div class="col-md-4">
						<label class="form-label">알림톡 발신 프로필/채널</label>
						<input type="text" class="form-control form-control-solid" id="cfg_alimtalk_channel" value="<?= $esc($mcfg['alimtalk_channel']) ?>" placeholder="@플러스친구ID 또는 발신프로필키" />
						<div class="form-text fs-9">카카오 알림톡 발신 채널(플러스친구) 식별자.</div>
					</div>
					<div class="col-md-4">
						<label class="form-label">명세서 알림톡 템플릿 코드</label>
						<input type="text" class="form-control form-control-solid" id="cfg_statement_template" value="<?= $esc($mcfg['statement_template']) ?>" placeholder="예: STMT_001" />
						<div class="form-text fs-9">정산 명세서 발송에 쓸 사전승인 템플릿 코드.</div>
					</div>
					<div class="col-md-8">
						<label class="form-label">명세서 링크 도메인</label>
						<input type="url" class="form-control form-control-solid" id="cfg_public_base_url" value="<?= $esc($mcfg['public_base_url']) ?>" placeholder="https://oxpay.kr" />
						<div class="form-text fs-9">알림톡 명세서 링크의 기본 주소. 비우면 접속 도메인을 자동 사용합니다.</div>
					</div>
					<div class="col-md-4">
						<label class="form-label">명세서 링크 유효기간(일)</label>
						<input type="number" min="1" max="3650" class="form-control form-control-solid" id="cfg_link_ttl_days" value="<?= (int) $mcfg['link_ttl_days'] ?>" />
						<div class="form-text fs-9">생성된 링크가 만료되기까지의 일수.</div>
					</div>
					<div class="col-12"><div class="separator separator-dashed my-2"></div></div>
					<div class="col-md-3">
						<label class="form-label">알림톡 단가(원)</label>
						<input type="number" min="0" class="form-control form-control-solid" id="cfg_price_alimtalk" value="<?= (int) ($mcfg['price_alimtalk'] ?? 10) ?>" />
					</div>
					<div class="col-md-3">
						<label class="form-label">SMS 단가(원)</label>
						<input type="number" min="0" class="form-control form-control-solid" id="cfg_price_sms" value="<?= (int) ($mcfg['price_sms'] ?? 10) ?>" />
					</div>
					<div class="col-md-3">
						<label class="form-label">LMS 단가(원)</label>
						<input type="number" min="0" class="form-control form-control-solid" id="cfg_price_lms" value="<?= (int) ($mcfg['price_lms'] ?? 50) ?>" />
					</div>
					<div class="col-md-3">
						<label class="form-label">SMS 최대 바이트</label>
						<input type="number" min="1" max="2000" class="form-control form-control-solid" id="cfg_sms_max_bytes" value="<?= (int) ($mcfg['sms_max_bytes'] ?? 90) ?>" />
						<div class="form-text fs-9">초과하면 자동으로 LMS 발송(EUC-KR 기준).</div>
					</div>
					<div class="col-12">
						<div class="alert bg-light-warning fs-9 p-3 mb-0">💰 발송 1건마다 이 금액이 <strong>대리점 지갑 → 본사</strong>로 이체됩니다(지갑 원장 「메시지 발송 요금」). <strong>발송 실패 건은 과금하지 않습니다.</strong> 상황별 템플릿은 <a href="<?= $esc(admin_url('system/alimtalk-templates')) ?>">알림톡 템플릿</a>에서 관리합니다.</div>
					</div>
					<div class="col-12">
						<label class="form-check form-switch form-check-custom form-check-solid align-items-start">
							<input class="form-check-input me-3 mt-1" type="checkbox" id="cfg_alimtalk_fallback_sms" <?= (int) ($mcfg['alimtalk_fallback_sms'] ?? 1) === 1 ? 'checked' : '' ?> />
							<span class="d-flex flex-column">
								<span class="fw-semibold text-gray-800">알림톡 실패 시 <strong>SMS 대체발송</strong></span>
								<span class="text-muted fs-9">카카오톡 미설치·차단·미사용자 등 <strong>수신불가</strong>로 알림톡이 실패하면 같은 내용을 문자(SMS)로 자동 재발송합니다. (템플릿 오류 등 다른 실패는 대체발송하지 않습니다.)</span>
							</span>
						</label>
					</div>
				</div>
				<div class="d-flex justify-content-end mt-4">
					<button type="button" class="btn btn-sm btn-primary" id="cfg_save">설정 저장</button>
				</div>
				<div class="alert bg-light-info fs-9 p-3 mt-4 mb-0">ℹ️ 발송사 로그인 자격증명(ID/비밀번호)은 보안상 여기 저장하지 않습니다 — 실 연동 단계에서 암호화해 별도 저장합니다.</div>
			</div>
		</div>
	</div>
	<!--end::알림톡·문자 설정-->

	<div class="row g-4 g-xl-6">
		<!--begin::작성-->
		<div class="col-xl-4">
			<div class="card card-flush">
				<div class="card-header pt-5"><h3 class="card-title fw-bold">메시지 작성</h3></div>
				<div class="card-body pt-2 fs-7">
					<div class="mb-4">
						<label class="form-label required">채널</label>
						<select class="form-select form-select-solid" id="m_channel">
							<?php foreach (MessageQueue::CHANNELS as $k => $v) : ?><option value="<?= $esc($k) ?>"><?= $esc($v) ?></option><?php endforeach; ?>
						</select>
					</div>
					<div class="row g-3 mb-4">
						<div class="col-7"><label class="form-label required">받는 번호</label><input type="tel" class="form-control form-control-solid" id="m_phone" placeholder="01012345678" /></div>
						<div class="col-5"><label class="form-label">받는 이름</label><input type="text" class="form-control form-control-solid" id="m_name" placeholder="선택" /></div>
					</div>
					<div class="mb-4">
						<label class="form-label">제목/템플릿명 <span class="text-muted fs-9">(선택)</span></label>
						<input type="text" class="form-control form-control-solid" id="m_title" maxlength="120" />
					</div>
					<div class="mb-4">
						<label class="form-label required">내용</label>
						<textarea class="form-control form-control-solid" id="m_content" rows="5" maxlength="2000" placeholder="보낼 내용"></textarea>
					</div>
					<div class="mb-4">
						<label class="form-label">예약 발송 <span class="text-muted fs-9">(비우면 즉시 대기)</span></label>
						<input type="datetime-local" class="form-control form-control-solid" id="m_sched" />
					</div>
					<div class="d-flex gap-2">
						<button type="button" class="btn btn-light-primary flex-grow-1" id="m_enqueue">큐에 추가</button>
						<button type="button" class="btn btn-primary flex-grow-1" id="m_enqueue_send">추가 후 즉시 발송</button>
					</div>
				</div>
			</div>
		</div>
		<!--end::작성-->

		<!--begin::큐 목록-->
		<div class="col-xl-8">
			<div class="card card-flush">
				<div class="card-header pt-5 flex-wrap gap-2">
					<h3 class="card-title fw-bold">발송 큐</h3>
					<div class="card-toolbar gap-2">
						<span class="badge badge-light-warning" id="cnt_queued">대기 <?= (int) ($counts['queued'] ?? 0) ?></span>
						<span class="badge badge-light-success" id="cnt_sent">완료 <?= (int) ($counts['sent'] ?? 0) ?></span>
						<span class="badge badge-light-danger" id="cnt_failed">실패 <?= (int) ($counts['failed'] ?? 0) ?></span>
						<button type="button" class="btn btn-sm btn-primary" id="m_send_all">대기 전체 발송</button>
					</div>
				</div>
				<div class="card-body pt-0">
					<div class="table-responsive">
						<table class="table table-row-bordered align-middle fs-8 gy-2">
							<thead><tr class="fw-bold text-muted"><th>채널</th><th>받는 사람</th><th class="min-w-150px">내용</th><th>상태</th><th>일시</th><th class="text-end">작업</th></tr></thead>
							<tbody id="msg_tbody"><?php require __DIR__ . '/_msg_rows.php'; ?></tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
		<!--end::큐 목록-->
	</div>

	<!--begin::발송 로그-->
	<div class="card card-flush mt-6">
		<div class="card-header pt-5 flex-wrap gap-2">
			<h3 class="card-title fw-bold">발송 로그 <span class="text-muted fs-8 fw-normal ms-2">재발송·자동발송 포함 모든 시도가 남습니다</span></h3>
			<div class="card-toolbar gap-2">
				<span class="badge badge-light-success" id="lcnt_sent">성공 0</span>
				<span class="badge badge-light-danger" id="lcnt_failed">실패 0</span>
			</div>
		</div>
		<div class="card-body pt-2">
			<div class="row g-3 mb-4 align-items-end">
				<div class="col-6 col-md-2"><label class="form-label fs-8 mb-1">시작일</label><input type="date" class="form-control form-control-sm form-control-solid" id="lf_from" /></div>
				<div class="col-6 col-md-2"><label class="form-label fs-8 mb-1">종료일</label><input type="date" class="form-control form-control-sm form-control-solid" id="lf_to" /></div>
				<div class="col-6 col-md-2"><label class="form-label fs-8 mb-1">채널</label><select class="form-select form-select-sm form-select-solid" id="lf_channel"><option value="">전체</option><?php foreach (MessageQueue::CHANNELS as $k => $v) : ?><option value="<?= $esc($k) ?>"><?= $esc($v) ?></option><?php endforeach; ?></select></div>
				<div class="col-6 col-md-2"><label class="form-label fs-8 mb-1">상태</label><select class="form-select form-select-sm form-select-solid" id="lf_status"><option value="">전체</option><option value="sent">성공</option><option value="failed">실패</option></select></div>
				<div class="col-8 col-md-3"><label class="form-label fs-8 mb-1">검색(번호·이름·내용)</label><input type="text" class="form-control form-control-sm form-control-solid" id="lf_q" placeholder="0101234…" /></div>
				<div class="col-4 col-md-1 d-grid"><button type="button" class="btn btn-sm btn-light-primary" id="lf_apply">조회</button></div>
			</div>
			<div class="table-responsive">
				<table class="table table-row-bordered align-middle fs-8 gy-2">
					<thead><tr class="fw-bold text-muted"><th class="min-w-125px">일시</th><th>채널</th><th>받는 사람</th><th class="min-w-150px">내용</th><th>결과</th><th>참조/발송자</th></tr></thead>
					<tbody id="log_tbody"><tr><td colspan="6" class="text-center text-muted py-6">조회를 눌러 주세요.</td></tr></tbody>
				</table>
			</div>
		</div>
	</div>
	<!--end::발송 로그-->

	<script>
	(function () {
		var API = <?= json_encode($apiUrl, JSON_UNESCAPED_UNICODE) ?>;
		var BADGE = <?= json_encode($statusBadge, JSON_UNESCAPED_UNICODE) ?>;
		var toast = document.getElementById('msg_toast'), toastMsg = document.getElementById('msg_toast_msg');
		function showToast(m, ok) { toast.className = 'alert alert-dismissible mb-6 alert-' + (ok ? 'success' : 'danger'); toastMsg.textContent = m; toast.classList.remove('d-none'); }
		function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

		function rowHtml(r) {
			var when = r.status === 'sent' ? (r.sent_at || '') : (r.scheduled_at || r.created_at || '');
			var act = '';
			if (r.status === 'queued' || r.status === 'failed') {
				act += '<button class="btn btn-sm btn-light-primary py-1 px-2 me-1 m-send" data-id="' + r.id + '">발송</button>';
				act += '<button class="btn btn-sm btn-light py-1 px-2 m-cancel" data-id="' + r.id + '">취소</button>';
			}
			var content = esc(r.content).slice(0, 60) + (r.content.length > 60 ? '…' : '');
			var err = r.error ? '<div class="text-danger fs-9">' + esc(r.error) + '</div>' : '';
			var refv = r.provider_ref ? '<div class="text-muted fs-9">' + esc(r.provider_ref) + '</div>' : '';
			var fb = r.fallback_from ? ' <span class="badge badge-light-warning">SMS 대체발송</span>' : '';
			return '<tr>' +
				'<td><span class="badge badge-light-' + (r.channel === 'alimtalk' ? 'info' : 'primary') + '">' + esc(r.channel_label) + '</span>' + fb + '</td>' +
				'<td>' + (r.recipient_name ? esc(r.recipient_name) + '<br>' : '') + '<span class="text-muted">' + esc(r.recipient_phone) + '</span></td>' +
				'<td>' + (r.title ? '<div class="fw-semibold">' + esc(r.title) + '</div>' : '') + content + err + refv + '</td>' +
				'<td><span class="badge badge-light-' + (BADGE[r.status] || 'secondary') + '">' + esc(r.status_label) + '</span></td>' +
				'<td class="text-muted">' + esc(when) + '</td>' +
				'<td class="text-end">' + act + '</td></tr>';
		}
		function apply(d) {
			if (d.counts) {
				document.getElementById('cnt_queued').textContent = '대기 ' + (d.counts.queued || 0);
				document.getElementById('cnt_sent').textContent = '완료 ' + (d.counts.sent || 0);
				document.getElementById('cnt_failed').textContent = '실패 ' + (d.counts.failed || 0);
			}
			if (d.rows) {
				document.getElementById('msg_tbody').innerHTML = d.rows.length
					? d.rows.map(rowHtml).join('')
					: '<tr><td colspan="6" class="text-center text-muted py-6">큐가 비어 있습니다.</td></tr>';
			}
		}
		function post(payload, okMsg) {
			return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) })
				.then(function (r) { return r.json(); })
				.then(function (res) { if (!res.ok) throw new Error(res.message || '실패'); showToast(res.message || okMsg, true); apply(res); return res; })
				.catch(function (e) { showToast(e.message || '실패', false); throw e; });
		}
		function compose() {
			return {
				channel: document.getElementById('m_channel').value,
				recipient_phone: document.getElementById('m_phone').value.trim(),
				recipient_name: document.getElementById('m_name').value.trim(),
				title: document.getElementById('m_title').value.trim(),
				content: document.getElementById('m_content').value,
				scheduled_at: document.getElementById('m_sched').value
			};
		}
		function clearForm() { ['m_phone', 'm_name', 'm_title', 'm_content', 'm_sched'].forEach(function (id) { document.getElementById(id).value = ''; }); }

		document.getElementById('m_enqueue').addEventListener('click', function () {
			post(Object.assign({ action: 'enqueue' }, compose()), '큐에 추가했습니다.').then(clearForm).catch(function () {});
		});
		document.getElementById('m_enqueue_send').addEventListener('click', function () {
			post(Object.assign({ action: 'enqueue' }, compose()), '추가했습니다.').then(function (res) {
				if (res && res.id) { return post({ action: 'send', id: res.id }, '발송했습니다.'); }
			}).then(clearForm).catch(function () {});
		});
		document.getElementById('m_send_all').addEventListener('click', function () {
			if (!confirm('대기 중인 메시지를 모두 발송할까요? (모의 연동)')) return;
			post({ action: 'send_all' }, '발송했습니다.').catch(function () {});
		});
		document.getElementById('msg_tbody').addEventListener('click', function (ev) {
			var s = ev.target.closest('.m-send'), c = ev.target.closest('.m-cancel');
			if (s) { post({ action: 'send', id: Number(s.getAttribute('data-id')) }, '발송했습니다.').catch(function () {}); }
			if (c) { post({ action: 'cancel', id: Number(c.getAttribute('data-id')) }, '취소했습니다.').catch(function () {}); }
		});

		// ── 발송 로그 ──────────────────────────────
		function logRowHtml(r) {
			var badge = r.status === 'sent' ? 'success' : 'danger';
			var content = esc(r.content).slice(0, 50) + (r.content.length > 50 ? '…' : '');
			var reason = r.reason_label ? '<div class="fs-9"><span class="badge badge-light-warning">' + esc(r.reason_label) + '</span></div>' : '';
			var err = r.error ? '<div class="text-danger fs-9">' + esc(r.error) + '</div>' + reason : reason;
			var ref = r.provider_ref ? '<div class="text-muted fs-9">' + esc(r.provider_ref) + '</div>' : '';
			var who = r.sender_name ? '<div class="fs-9">' + esc(r.sender_name) + '</div>' : '';
			return '<tr>' +
				'<td class="text-muted">' + esc(r.attempted_at) + '</td>' +
				'<td><span class="badge badge-light-' + (r.channel === 'alimtalk' ? 'info' : 'primary') + '">' + esc(r.channel_label) + '</span></td>' +
				'<td>' + (r.recipient_name ? esc(r.recipient_name) + '<br>' : '') + '<span class="text-muted">' + esc(r.recipient_phone) + '</span></td>' +
				'<td>' + (r.title ? '<div class="fw-semibold">' + esc(r.title) + '</div>' : '') + content + '</td>' +
				'<td><span class="badge badge-light-' + badge + '">' + esc(r.status_label) + '</span>' + err + '</td>' +
				'<td>' + ref + who + '</td></tr>';
		}
		function loadLogs() {
			var p = new URLSearchParams({ logs: '1' });
			['from', 'to', 'channel', 'status', 'q'].forEach(function (k) {
				var v = document.getElementById('lf_' + k).value.trim();
				if (v) p.set(k, v);
			});
			fetch(API + '?' + p.toString(), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d.ok) throw new Error(d.message || '실패');
					document.getElementById('lcnt_sent').textContent = '성공 ' + (d.log_counts.sent || 0);
					document.getElementById('lcnt_failed').textContent = '실패 ' + (d.log_counts.failed || 0);
					document.getElementById('log_tbody').innerHTML = d.log_rows.length
						? d.log_rows.map(logRowHtml).join('')
						: '<tr><td colspan="6" class="text-center text-muted py-6">로그가 없습니다.</td></tr>';
				})
				.catch(function (e) { showToast(e.message || '로그 조회 실패', false); });
		}
		// ── 알림톡·문자 설정 저장 ──
		var cfgSave = document.getElementById('cfg_save');
		if (cfgSave) {
			cfgSave.addEventListener('click', function () {
				post({
					action: 'save_config',
					sender_phone: document.getElementById('cfg_sender_phone').value.trim(),
					alimtalk_channel: document.getElementById('cfg_alimtalk_channel').value.trim(),
					statement_template: document.getElementById('cfg_statement_template').value.trim(),
					public_base_url: document.getElementById('cfg_public_base_url').value.trim(),
					link_ttl_days: Number(document.getElementById('cfg_link_ttl_days').value || 90),
					alimtalk_fallback_sms: document.getElementById('cfg_alimtalk_fallback_sms').checked ? 1 : 0,
					price_alimtalk: Number(document.getElementById('cfg_price_alimtalk').value || 0),
					price_sms: Number(document.getElementById('cfg_price_sms').value || 0),
					price_lms: Number(document.getElementById('cfg_price_lms').value || 0),
					sms_max_bytes: Number(document.getElementById('cfg_sms_max_bytes').value || 90)
				}, '설정을 저장했습니다.').catch(function () {});
			});
		}

		document.getElementById('lf_apply').addEventListener('click', loadLogs);
		document.getElementById('lf_q').addEventListener('keydown', function (e) { if (e.key === 'Enter') loadLogs(); });
		loadLogs(); // 진입 시 최근 로그 자동 로드
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

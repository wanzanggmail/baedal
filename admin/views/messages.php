<?php

declare(strict_types=1);

require_once INC_PATH . '/MessageQueue.php';

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
			return '<tr>' +
				'<td><span class="badge badge-light-' + (r.channel === 'alimtalk' ? 'info' : 'primary') + '">' + esc(r.channel_label) + '</span></td>' +
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
	})();
	</script>

	<?php endif; ?>

<?php require_once INC_PATH . '/app_content_close.php'; ?>

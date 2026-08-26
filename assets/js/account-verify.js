/**
 * 계좌 예금주 확인 — 계좌를 입력하는 모든 화면이 함께 쓴다.
 *
 * 계좌번호는 한 자리만 틀려도 모르는 사람에게 돈이 가고, 이체는 되돌리기가 매우 어렵다.
 * 등록 시점에 한 번 확인하는 것이 사고를 막는 가장 싼 방법이다.
 *
 * 사용:
 *   AccountVerify.attach({
 *     bank:    'ed_bank',      // 은행 select id
 *     account: 'ed_account',   // 계좌번호 input id
 *     holder:  'ed_holder',    // 예금주 input id (없으면 생략 가능)
 *     button:  'ed_verify',    // 조회 버튼 id
 *     result:  'ed_verify_msg',// 결과를 그릴 요소 id
 *     riderId: 12              // (선택) 확인 기록을 남길 라이더
 *   });
 *
 * 서버가 "확인 불가"(실 연동 꺼짐)를 주면 **막지 않는다** — 연동 전에도 등록은 돼야 한다.
 */
(function (global) {
	'use strict';

	var API = (global.ADMIN_BASE_URL || '') + '/api/account_verify.php';

	function el(id) { return id ? document.getElementById(id) : null; }
	function val(id) { var e = el(id); return e ? (e.value || '').trim() : ''; }

	function paint(box, state, message, holder) {
		if (!box) { return; }
		var cls = 'secondary', icon = '';
		if (state === 'ok') { cls = 'success'; icon = '확인'; }
		else if (state === 'mismatch') { cls = 'warning'; icon = '불일치'; }
		else if (state === 'not_found') { cls = 'danger'; icon = '실패'; }
		else { cls = 'secondary'; icon = '확인 불가'; }

		// 배지와 문구 사이에 공백을 넣는다 — 붙으면 "확인 불가펌뱅킹…" 처럼 읽힌다.
		box.innerHTML = '<span class="badge badge-light-' + cls + ' me-2">' + icon + '</span>'
			+ '<span class="text-gray-700">' + (message || '') + '</span>';
		box.dataset.state = state;
		box.dataset.holder = holder || '';
	}

	function attach(opt) {
		var btn = el(opt.button);
		var box = el(opt.result);
		if (!btn) { return; }

		// 계좌·은행이 바뀌면 이전 확인 결과는 더 이상 유효하지 않다 → 지운다.
		['bank', 'account', 'holder'].forEach(function (k) {
			var e = el(opt[k]);
			if (!e) { return; }
			e.addEventListener('input', function () { if (box) { box.innerHTML = ''; box.dataset.state = ''; } });
			e.addEventListener('change', function () { if (box) { box.innerHTML = ''; box.dataset.state = ''; } });
		});

		btn.addEventListener('click', function () {
			var bank = val(opt.bank), account = val(opt.account), holder = val(opt.holder);
			if (!bank || !account) {
				paint(box, 'not_found', '은행과 계좌번호를 먼저 입력하세요.');
				return;
			}

			btn.disabled = true;
			var old = btn.textContent;
			btn.textContent = '조회 중…';

			fetch(API, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					bank_code: bank,
					account_no: account,
					holder: holder,
					rider_id: opt.riderId || 0
				})
			}).then(function (r) {
				return r.text().then(function (t) {
					try { return JSON.parse(t); }
					catch (e) { throw new Error('서버 응답을 해석할 수 없습니다 (HTTP ' + r.status + ')'); }
				});
			}).then(function (j) {
				paint(box, j.state || 'error', j.message || '', j.holder);

				// 조회된 예금주로 입력칸을 채워 준다 — 사람이 옮겨 적다 틀리는 걸 막는다.
				var hEl = el(opt.holder);
				if (j.holder && hEl && (j.state === 'ok' || j.state === 'mismatch')) {
					if (!hEl.value.trim() || j.state === 'mismatch') {
						if (!hEl.value.trim() || confirm('예금주를 "' + j.holder + '" 로 바꿀까요?')) {
							hEl.value = j.holder;
						}
					}
				}
			}).catch(function (e) {
				paint(box, 'error', e.message || '조회 실패');
			}).finally(function () {
				btn.disabled = false;
				btn.textContent = old;
			});
		});
	}

	/** 저장 직전에 부르면, 확인이 안 된 계좌에 대해 한 번 되묻는다. */
	function confirmUnverified(resultId) {
		var box = el(resultId);
		var st = box ? (box.dataset.state || '') : '';
		if (st === 'ok') { return true; }
		if (st === 'mismatch') {
			return confirm('예금주가 입력한 이름과 다릅니다.\n그래도 저장할까요?');
		}
		return confirm('계좌 확인을 하지 않았습니다.\n계좌번호가 틀리면 다른 사람에게 송금될 수 있습니다.\n\n그래도 저장할까요?');
	}

	global.AccountVerify = { attach: attach, confirmUnverified: confirmUnverified };
})(window);

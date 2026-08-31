/**
 * 출금 신청 달력 — 미출금 정산일을 표시하고, 날짜를 누르면 "그 날짜까지" 출금 범위를 정한다.
 *
 * 사이클 소비는 항상 가장 오래된 미출금분부터라서(age-bucket 요율·잔액 정합성) 임의 날짜
 * 다중선택이 아니라 "선택일까지 누적"으로 동작한다. 선택 즉시 서버에 실지급액을 재계산 요청.
 */
(function () {
	'use strict';

	var DAY_NAMES = ['일', '월', '화', '수', '목', '금', '토'];

	function getData() {
		var raw = window.RIDER_WD_CYCLES;
		return raw && typeof raw === 'object' ? raw : {};
	}

	var store = getData();
	var keys = Object.keys(store).sort();
	var selectedTo = keys.length ? keys[keys.length - 1] : '';

	var initial = selectedTo ? selectedTo.split('-') : [];
	var state = {
		y: initial.length ? parseInt(initial[0], 10) : new Date().getFullYear(),
		m: initial.length ? parseInt(initial[1], 10) - 1 : new Date().getMonth(),
	};

	function pad(n) { return n < 10 ? '0' + n : String(n); }
	function dateKey(y, mo, d) { return y + '-' + pad(mo + 1) + '-' + pad(d); }
	function won(n) { return Number(n || 0).toLocaleString('ko-KR'); }

	function cellText(n) {
		var num = Number(n);
		if (isNaN(num) || num <= 0) return '';
		if (num >= 10000) return Math.round(num / 10000) + '만';
		if (num >= 1000) return Math.round(num / 1000) + '천';
		return num.toLocaleString('ko-KR');
	}

	function renderMonth() {
		var grid = document.getElementById('riderDaysGrid');
		var monthYear = document.getElementById('riderMonthYear');
		if (!grid || !monthYear) return;

		var y = state.y;
		var m = state.m;
		monthYear.textContent = y + '년 ' + (m + 1) + '월';

		var first = new Date(y, m, 1);
		var last = new Date(y, m + 1, 0);
		var startPad = first.getDay();
		var daysInMonth = last.getDate();

		grid.innerHTML = '';
		var i;
		for (i = 0; i < startPad; i++) {
			var empty = document.createElement('div');
			empty.className = 'rider-cal-day rider-cal-empty';
			grid.appendChild(empty);
		}

		for (var d = 1; d <= daysInMonth; d++) {
			var key = dateKey(y, m, d);
			var dow = new Date(y, m, d).getDay();
			var rec = store[key];
			var amount = rec ? Number(rec.amount) : 0;
			var hasData = amount > 0;

			var cell = hasData ? document.createElement('button') : document.createElement('div');
			if (hasData) {
				cell.type = 'button';
				cell.className = 'rider-cal-day rider-cal-day-btn has-delivery';
				cell.setAttribute('aria-label', d + '일까지 출금 선택');
			} else {
				cell.className = 'rider-cal-day';
			}
			if (dow === 0) cell.classList.add('rider-cal-sun');
			if (dow === 6) cell.classList.add('rider-cal-sat');
			// 선택 범위(가장 오래된 미출금분 ~ 선택일) 강조
			if (hasData && selectedTo && key <= selectedTo) {
				cell.classList.add('rider-cal-selected');
			}

			var num = document.createElement('span');
			num.className = 'rider-cal-day-num';
			num.textContent = String(d);
			cell.appendChild(num);

			if (hasData) {
				var meta = document.createElement('span');
				meta.className = 'rider-cal-day-meta';
				meta.textContent = cellText(amount);
				cell.appendChild(meta);
				cell.addEventListener('click', (function (k) {
					return function () { selectTo(k); };
				})(key));
			}
			grid.appendChild(cell);
		}

		var used = startPad + daysInMonth;
		var endPad = (7 - (used % 7)) % 7;
		for (i = 0; i < endPad; i++) {
			var e2 = document.createElement('div');
			e2.className = 'rider-cal-day rider-cal-empty';
			grid.appendChild(e2);
		}
	}

	function setText(id, text) {
		var el = document.getElementById(id);
		if (el) el.textContent = text;
	}

	function selectTo(key) {
		selectedTo = key;
		renderMonth();
		refreshPreview();
	}

	function refreshPreview() {
		var url = window.RIDER_WD_PREVIEW_URL;
		if (!url) return;
		var toInput = document.getElementById('wdToDate');
		if (toInput) toInput.value = selectedTo || '';

		setText('wdPayout', '계산 중…');
		fetch(url + '?to=' + encodeURIComponent(selectedTo || ''), { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d.ok) throw new Error(d.message || '계산 실패');
				setText('wdConsume', '₩ ' + won(d.consume_amount));
				setText('wdFee', '− ₩ ' + won(d.fee_per_tx));
				setText('wdTransferFee', '− ₩ ' + won(d.transfer_fee || 0));
				var tfRow = document.getElementById('wdTransferFeeRow');
				if (tfRow) { tfRow.classList.toggle('d-none', !(d.transfer_fee > 0)); }
				setText('wdPayout', '₩ ' + won(d.payout_amount));
				setText('wdPeriodLabel', d.period_from
					? (d.period_from === d.period_to ? d.period_from : d.period_from + ' ~ ' + d.period_to) + ' (' + d.picked_count + '일)'
					: '선택 없음');

				var detail = '';
				if (d.fee_cycle_based) {
					if (d.fee_short_orders > 0) {
						detail += '최근 ' + d.fee_day_threshold + '일 이내 ' + won(d.fee_short_orders) + '건×' + won(d.fee_rate_short) + '원';
					}
					if (d.fee_long_orders > 0) {
						if (detail) detail += ' · ';
						detail += d.fee_day_threshold + '일 지난 ' + won(d.fee_long_orders) + '건×' + won(d.fee_rate_long) + '원';
					}
				}
				setText('wdFeeDetail', detail);

				var btn = document.getElementById('wdSubmitBtn');
				if (btn) btn.disabled = !d.can_apply || window.RIDER_WD_HAS_OPEN === true;
			})
			.catch(function () {
				setText('wdPayout', '계산 실패');
			});
	}

	function init() {
		var prev = document.getElementById('riderPrevBtn');
		var next = document.getElementById('riderNextBtn');
		if (prev) {
			prev.addEventListener('click', function () {
				state.m -= 1;
				if (state.m < 0) { state.m = 11; state.y -= 1; }
				renderMonth();
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				state.m += 1;
				if (state.m > 11) { state.m = 0; state.y += 1; }
				renderMonth();
			});
		}
		renderMonth();
		refreshPreview();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

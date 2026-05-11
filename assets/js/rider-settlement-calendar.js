/**
 * 정산 달력 — 관리자 데이터 표시, 일자 탭 시 건별(스토어·금액) 상세
 */
(function () {
	'use strict';

	var HOLIDAYS = {
		'2026-01-01': '신정',
		'2026-03-01': '삼일절',
		'2026-05-05': '어린이날',
		'2026-06-06': '현충일',
		'2026-08-15': '광복절',
		'2026-10-03': '개천절',
		'2026-10-09': '한글날',
		'2026-12-25': '성탄절',
	};

	var DAY_NAMES = ['일', '월', '화', '수', '목', '금', '토'];

	var state = {
		y: new Date().getFullYear(),
		m: new Date().getMonth(),
	};

	function getData() {
		var raw = typeof window !== 'undefined' ? window.RIDER_SETTLEMENT_CALENDAR_DATA : null;
		return raw && typeof raw === 'object' ? raw : {};
	}

	function pad(n) {
		return n < 10 ? '0' + n : String(n);
	}

	function dateKey(y, mo, d) {
		return y + '-' + pad(mo + 1) + '-' + pad(d);
	}

	function getItems(rec) {
		if (!rec || typeof rec !== 'object' || !Array.isArray(rec.items)) return [];
		return rec.items;
	}

	function sumItemAmounts(rec) {
		var items = getItems(rec);
		var s = 0;
		for (var i = 0; i < items.length; i++) {
			var a = Number(items[i].amount);
			if (!isNaN(a)) s += a;
		}
		return s;
	}

	function getDelivery(rec) {
		if (!rec || typeof rec !== 'object') return 0;
		var d = Number(rec.delivery);
		if (!isNaN(d) && d > 0) return d;
		var sum = sumItemAmounts(rec);
		if (sum > 0) return sum;
		var a = Number(rec.amount);
		if (!isNaN(a) && a > 0) return a;
		return 0;
	}

	function itemStoreName(it) {
		if (!it || typeof it !== 'object') return '스토어';
		var n = it.store != null ? it.store : it.store_name;
		return n != null && String(n).trim() !== '' ? String(n) : '스토어';
	}

	function cellDeliveryText(n) {
		var num = Number(n);
		if (isNaN(num) || num <= 0) return '';
		if (num >= 100000000) {
			var e = num / 100000000;
			return (Math.round(e * 10) / 10).toString().replace(/\.0$/, '') + '억';
		}
		if (num >= 10000) return Math.round(num / 10000) + '만';
		if (num >= 1000) return Math.round(num / 1000) + '천';
		return num.toLocaleString('ko-KR');
	}

	function closeDetail() {
		var overlay = document.getElementById('riderCalDetailOverlay');
		if (overlay) {
			overlay.classList.remove('rider-cal-open');
			overlay.setAttribute('aria-hidden', 'true');
		}
		document.body.style.overflow = '';
	}

	function openDetail(dateKeyStr, rec) {
		var overlay = document.getElementById('riderCalDetailOverlay');
		if (!overlay) return;

		var parts = dateKeyStr.split('-');
		var y = parseInt(parts[0], 10);
		var mo = parseInt(parts[1], 10) - 1;
		var d = parseInt(parts[2], 10);
		var dt = new Date(y, mo, d);
		var dow = dt.getDay();

		var dateLine = document.getElementById('riderCalDetailDateLine');
		if (dateLine) {
			dateLine.textContent =
				y + '년 ' + (mo + 1) + '월 ' + d + '일 (' + DAY_NAMES[dow] + ')';
		}

		var listEl = document.getElementById('riderCalDetailList');
		var emptyEl = document.getElementById('riderCalDetailEmpty');
		var totalEl = document.getElementById('riderCalDetailTotal');
		var items = getItems(rec);
		var total = getDelivery(rec);

		if (listEl) {
			listEl.innerHTML = '';
			if (items.length) {
				for (var i = 0; i < items.length; i++) {
					var it = items[i];
					var amt = Number(it.amount);
					if (isNaN(amt)) amt = 0;
					var li = document.createElement('li');
					li.className = 'rider-cal-detail-item';
					var name = document.createElement('span');
					name.className = 'rider-cal-detail-store';
					name.textContent = itemStoreName(it);
					var price = document.createElement('span');
					price.className = 'rider-cal-detail-amount';
					price.textContent = amt.toLocaleString('ko-KR') + '원';
					li.appendChild(name);
					li.appendChild(price);
					listEl.appendChild(li);
				}
			}
		}
		if (emptyEl) {
			emptyEl.style.display = items.length ? 'none' : '';
		}
		if (listEl) {
			listEl.style.display = items.length ? '' : 'none';
		}
		if (totalEl) {
			totalEl.textContent = total > 0 ? total.toLocaleString('ko-KR') + '원' : '0원';
		}

		overlay.classList.add('rider-cal-open');
		overlay.setAttribute('aria-hidden', 'false');
		document.body.style.overflow = 'hidden';
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
		var store = getData();

		var today = new Date();
		var isThisMonth = today.getFullYear() === y && today.getMonth() === m;

		grid.innerHTML = '';
		var i;
		for (i = 0; i < startPad; i++) {
			var empty = document.createElement('div');
			empty.className = 'rider-cal-day rider-cal-empty';
			grid.appendChild(empty);
		}

		for (var d = 1; d <= daysInMonth; d++) {
			var key = dateKey(y, m, d);
			var dt = new Date(y, m, d);
			var dow = dt.getDay();
			var rec = store[key];
			var delivery = getDelivery(rec);
			var hasData = delivery > 0;

			var cell = hasData ? document.createElement('button') : document.createElement('div');
			if (hasData) {
				cell.type = 'button';
				cell.className = 'rider-cal-day rider-cal-day-btn';
				cell.setAttribute('aria-label', d + '일 배달 상세 보기');
			} else {
				cell.className = 'rider-cal-day';
				cell.setAttribute('role', 'presentation');
			}

			if (dow === 0) cell.classList.add('rider-cal-sun');
			if (dow === 6) cell.classList.add('rider-cal-sat');
			if (HOLIDAYS[key]) cell.classList.add('rider-cal-holiday');
			if (isThisMonth && d === today.getDate()) cell.classList.add('rider-cal-today');

			var num = document.createElement('span');
			num.className = 'rider-cal-day-num';
			num.textContent = String(d);
			cell.appendChild(num);

			if (hasData) {
				cell.classList.add('has-delivery');
				var meta = document.createElement('span');
				meta.className = 'rider-cal-day-meta';
				meta.textContent = cellDeliveryText(delivery);
				cell.appendChild(meta);
				cell.addEventListener('click', function (k, r) {
					return function () {
						openDetail(k, r);
					};
				}(key, rec));
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

		updateStats(y, m, store);
	}

	function updateStats(y, m, store) {
		var prefix = y + '-' + pad(m + 1) + '-';
		var days = 0;
		var totalDelivery = 0;
		Object.keys(store).forEach(function (k) {
			if (k.indexOf(prefix) !== 0) return;
			var d = getDelivery(store[k]);
			if (d > 0) {
				days += 1;
				totalDelivery += d;
			}
		});

		var elD = document.getElementById('riderTotalWorkDays');
		var elS = document.getElementById('riderTotalDelivery');
		if (elD) elD.textContent = days + '일';
		if (elS) {
			elS.textContent =
				totalDelivery > 0 ? totalDelivery.toLocaleString('ko-KR') + '원' : '0원';
		}
	}

	function init() {
		var prev = document.getElementById('riderPrevBtn');
		var next = document.getElementById('riderNextBtn');
		if (prev) {
			prev.addEventListener('click', function () {
				state.m -= 1;
				if (state.m < 0) {
					state.m = 11;
					state.y -= 1;
				}
				renderMonth();
			});
		}
		if (next) {
			next.addEventListener('click', function () {
				state.m += 1;
				if (state.m > 11) {
					state.m = 0;
					state.y += 1;
				}
				renderMonth();
			});
		}

		var overlay = document.getElementById('riderCalDetailOverlay');
		var closeBtn = document.getElementById('riderCalDetailClose');
		if (closeBtn) closeBtn.addEventListener('click', closeDetail);
		if (overlay) {
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) closeDetail();
			});
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && overlay && overlay.classList.contains('rider-cal-open')) {
				closeDetail();
			}
		});

		renderMonth();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

/**
 * Metronic 번들: flatpickr, daterangepicker(moment), jQuery
 * - [data-kt-daterange="true"] 래퍼: [data-kt-daterange-display] + hidden from/to
 * - [data-kt-flatpickr]: 단일일 (옵션 data-kt-flatpickr-week="true" → 주번호 표시)
 */
(function () {
	'use strict';

	function parseMoment(d) {
		if (!d) {
			return moment().startOf('day');
		}
		var m = moment(d, 'YYYY-MM-DD', true);
		return m.isValid() ? m : moment().startOf('day');
	}

	function initDateranges() {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.daterangepicker === 'undefined' || typeof moment === 'undefined') {
			return;
		}

		document.querySelectorAll('[data-kt-daterange="true"]').forEach(function (wrapper) {
			var display = wrapper.querySelector('[data-kt-daterange-display]');
			var fromIn = wrapper.querySelector('[data-kt-daterange-from]');
			var toIn = wrapper.querySelector('[data-kt-daterange-to]');
			if (!display || !fromIn || !toIn) {
				return;
			}

			var start = parseMoment(fromIn.value);
			var end = parseMoment(toIn.value);
			if (end.isBefore(start)) {
				end = start.clone();
			}

			function updateDisplay() {
				display.value = start.format('YYYY-MM-DD') + ' ~ ' + end.format('YYYY-MM-DD');
			}

			updateDisplay();

			var $d = jQuery(display);
			$d.daterangepicker(
				{
					startDate: start,
					endDate: end,
					autoUpdateInput: false,
					alwaysShowCalendars: true,
					opens: 'left',
					locale: {
						format: 'YYYY-MM-DD',
						separator: ' ~ ',
						applyLabel: '적용',
						cancelLabel: '취소',
						daysOfWeek: ['일', '월', '화', '수', '목', '금', '토'],
						monthNames: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
					},
				},
				function (s, e) {
					fromIn.value = s.format('YYYY-MM-DD');
					toIn.value = e.format('YYYY-MM-DD');
					start = s;
					end = e;
					updateDisplay();
				}
			);

			$d.on('cancel.daterangepicker', function () {
				updateDisplay();
			});
		});
	}

	/**
	 * 대시보드 기간 선택 트리거 — Metronic 데모의 data-kt-daterangepicker와 같은 스타일이지만
	 * scripts.bundle.js의 전역 자동초기화(영문 라벨·최근 30일 고정)와 겹치지 않도록
	 * 별도 속성(data-kt-dashboard-range)을 쓰고 여기서 직접 초기화한다.
	 * 적용 시 서버 URL(from/to 쿼리)로 이동해 대시보드를 다시 그린다.
	 */
	function initDashboardRange() {
		if (typeof jQuery === 'undefined' || typeof jQuery.fn.daterangepicker === 'undefined' || typeof moment === 'undefined') {
			return;
		}

		document.querySelectorAll('[data-kt-dashboard-range="true"]').forEach(function (el) {
			if (el.getAttribute('data-kt-initialized') === '1') {
				return;
			}

			var display = el.querySelector('[data-kt-dashboard-range-display]');
			var baseUrl = el.getAttribute('data-kt-dashboard-range-url') || '';
			var start = parseMoment(el.getAttribute('data-kt-dashboard-range-from'));
			var end = parseMoment(el.getAttribute('data-kt-dashboard-range-to'));
			if (end.isBefore(start)) {
				end = start.clone();
			}

			function updateDisplay(s, e) {
				if (!display) return;
				display.textContent = s.isSame(e, 'day')
					? s.format('YYYY-MM-DD')
					: s.format('YYYY-MM-DD') + ' ~ ' + e.format('YYYY-MM-DD');
			}
			updateDisplay(start, end);

			var today = moment().startOf('day');

			jQuery(el).daterangepicker(
				{
					startDate: start,
					endDate: end,
					opens: 'left',
					autoUpdateInput: false,
					alwaysShowCalendars: true,
					ranges: {
						오늘: [today.clone(), today.clone()],
						어제: [today.clone().subtract(1, 'days'), today.clone().subtract(1, 'days')],
						'최근 7일': [today.clone().subtract(6, 'days'), today.clone()],
						'최근 30일': [today.clone().subtract(29, 'days'), today.clone()],
						'이번 주': [today.clone().startOf('isoWeek'), today.clone()],
						'이번 달': [today.clone().startOf('month'), today.clone()],
						'지난 달': [
							today.clone().subtract(1, 'month').startOf('month'),
							today.clone().subtract(1, 'month').endOf('month'),
						],
					},
					locale: {
						format: 'YYYY-MM-DD',
						separator: ' ~ ',
						applyLabel: '적용',
						cancelLabel: '취소',
						customRangeLabel: '직접 선택',
						daysOfWeek: ['일', '월', '화', '수', '목', '금', '토'],
						monthNames: ['1월', '2월', '3월', '4월', '5월', '6월', '7월', '8월', '9월', '10월', '11월', '12월'],
					},
				},
				function (s, e) {
					updateDisplay(s, e);
					if (!baseUrl) return;
					var sep = baseUrl.indexOf('?') !== -1 ? '&' : '?';
					window.location.href = baseUrl + sep + 'from=' + s.format('YYYY-MM-DD') + '&to=' + e.format('YYYY-MM-DD');
				}
			);

			el.setAttribute('data-kt-initialized', '1');
		});
	}

	function initFlatpickrs() {
		if (typeof window.flatpickr === 'undefined') {
			return;
		}

		var useJq = typeof jQuery !== 'undefined' && typeof jQuery.fn.flatpickr !== 'undefined';

		document.querySelectorAll('[data-kt-flatpickr]').forEach(function (el) {
			var opts = {
				dateFormat: 'Y-m-d',
				allowInput: true,
				disableMobile: true,
			};
			if (el.getAttribute('data-kt-flatpickr-week') === 'true') {
				opts.weekNumbers = true;
			}
			if (useJq) {
				jQuery(el).flatpickr(opts);
			} else {
				flatpickr(el, opts);
			}
		});
	}

	function run() {
		initDateranges();
		initDashboardRange();
		initFlatpickrs();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();

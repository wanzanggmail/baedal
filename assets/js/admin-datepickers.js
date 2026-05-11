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
		initFlatpickrs();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}
})();

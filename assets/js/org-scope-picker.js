/**
 * 「총판 → 대리점」 연동 선택기 — 관리자 화면 공용.
 *
 * inc/org_scope_picker.php 가 그린 마크업을 자동으로 살린다. 한 페이지에 여러 개
 * 있어도 각각 붙는다(대리점 select 의 data-osp-dist 로 짝을 찾는다).
 *
 * 동작(원형: debt_list.php):
 *   - 총판을 골라야 대리점을 고를 수 있다(미선택 시 비활성).
 *   - 대리점 select2 는 커스텀 matcher 로 **고른 총판 소속만** 남긴다.
 *   - 총판을 바꾸면, 다른 총판 소속이던 대리점 선택은 초기화한다.
 *
 * ⚠️ select2 의 'change' 는 jQuery 이벤트라 네이티브 addEventListener 로는 못 받는다
 *    (debt_list 에서 실측). jQuery.on() 으로 바인딩한다.
 */
(function () {
	'use strict';

	function initAgency(agencyEl) {
		if (typeof jQuery === 'undefined' || !agencyEl) { return; }
		var distEl = document.getElementById(agencyEl.getAttribute('data-osp-dist') || '');
		if (!distEl) { return; }

		var $agency  = jQuery(agencyEl);
		var allowAll = agencyEl.getAttribute('data-osp-all') === '1';
		// 본사·전역 같은 특수 옵션(data-parent 없음)이 있으면 총판 미선택이어도 고를 수 있어야 한다.
		var hasExtra = agencyEl.getAttribute('data-osp-hasextra') === '1';

		function applyMatcher() {
			var distVal = distEl.value;
			// 총판을 안 골라도, 특수 옵션이 있으면 활성(그 옵션을 골라야 하니까).
			agencyEl.disabled = (distVal === '' && !hasExtra);
			if ($agency.hasClass('select2-hidden-accessible')) { $agency.select2('destroy'); }
			var cfg = {
				placeholder: (distVal === '' && !hasExtra) ? '총판을 먼저 선택하세요' : (allowAll ? '전체' : '대리점 선택'),
				allowClear: allowAll,
				matcher: function (params, data) {
					var opt = data.element;
					var isExtra = opt && !opt.hasAttribute('data-parent');
					if (distVal === '') {
						// 총판 미선택: 특수 옵션은 늘 보이고, 대리점은 전체 허용 화면일 때만.
						if (isExtra) { return matchTerm(data, params); }
						return allowAll ? matchTerm(data, params) : null;
					}
					// 총판 선택됨: 특수 옵션은 숨기고, 그 총판 소속 대리점만.
					if (isExtra) { return null; }
					if (opt.getAttribute('data-parent') !== distVal) { return null; }
					return matchTerm(data, params);
				}
			};
			// 모달 안이면 드롭다운을 모달에 붙여야 잘리지 않는다.
			var dd = agencyEl.getAttribute('data-osp-ddparent');
			if (dd) { cfg.dropdownParent = jQuery(dd); }
			$agency.select2(cfg);
		}

		function matchTerm(data, params) {
			if (!params.term) { return data; }
			return (data.text || '').toLowerCase().indexOf(params.term.toLowerCase()) > -1 ? data : null;
		}

		jQuery(distEl).on('change', function () {
			// 대리점 선택이 새 총판 소속이 아니면 초기화 — 다른 총판 대리점이 조회되는 걸 막는다.
			// 단, 특수 옵션(본사·전역 등, data-parent 없음)은 총판 미선택 상태의 것이라 건드리지 않는다.
			var sel = agencyEl.options[agencyEl.selectedIndex];
			var selParent = sel ? sel.getAttribute('data-parent') : null;
			if (selParent !== null && (distEl.value === '' || selParent !== distEl.value)) {
				jQuery(agencyEl).val('').trigger('change');
			}
			applyMatcher();
		});

		// 대상 선택기(withdrawal_settings·payment_setup): 대리점을 고르면 폼 자동 제출.
		if (agencyEl.getAttribute('data-osp-submit') === '1') {
			jQuery(agencyEl).on('change', function () {
				var form = agencyEl.closest('form');
				if (form && agencyEl.value !== '') { form.submit(); }
			});
		}

		applyMatcher();
	}

	function init() {
		var seen = {};
		document.querySelectorAll('select[data-osp-dist]').forEach(function (agencyEl) {
			if (seen[agencyEl.id]) { return; }
			seen[agencyEl.id] = 1;
			initAgency(agencyEl);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

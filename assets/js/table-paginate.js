/**
 * 가벼운 클라이언트 페이징 — DataTables 없이 그 느낌(번호 버튼·페이지당 건수·
 * "총 N건 중 X~Y" 표시)만 재현. 서버는 필터(기간·검색어)에 맞는 결과를 이미 다
 * 내려주고, 여기서는 그 결과를 새로고침 없이 페이지로만 나눠 보여준다.
 *
 * 사용법: initTablePaginate(document.getElementById('someTable'), { pageSize: 30 })
 *         검색창까지 원하면 { search: true, searchPlaceholder: '이름·코드 검색' }
 *         카드 헤더 등 **이미 있는 입력칸**에 붙이려면 { searchInput: '#myInput' }
 *         (조직 관리처럼 검색창이 표 위에 있는 화면과 위치를 맞추기 위한 것)
 * 대상 테이블은 <tbody> 안에 실제 데이터 행만 있어야 한다(빈 상태 안내행 등은
 * data-tp-skip 속성을 붙이면 페이징 대상에서 제외된다).
 */
(function (global) {
	'use strict';

	function initTablePaginate(table, opts) {
		if (!table) return null;
		opts = opts || {};
		var pageSizes = opts.pageSizes || [20, 30, 50, 100];
		var pageSize = opts.pageSize || pageSizes[1] || 30;
		var labelUnit = opts.unit || '건';

		var tbody = table.tBodies[0];
		if (!tbody) return null;
		var allRows = Array.prototype.slice.call(tbody.rows).filter(function (r) {
			return !r.hasAttribute('data-tp-skip');
		});
		// 검색은 화면에서만 거른다 — allRows 는 원본, visibleRows 는 검색을 통과한 것.
		// 페이지 계산은 항상 visibleRows 기준이어야 "총 N건"이 검색 결과와 맞는다.
		var visibleRows = allRows;
		var total = visibleRows.length;
		var page = 1;
		var query = '';

		var wrap = document.createElement('div');
		wrap.className = 'd-flex flex-wrap justify-content-between align-items-center gap-3 mt-6 tp-wrap';

		var infoEl = document.createElement('span');
		infoEl.className = 'text-muted fs-7 tp-info';

		var sizeWrap = document.createElement('div');
		sizeWrap.className = 'd-flex align-items-center gap-2';
		var sizeLabel = document.createElement('span');
		sizeLabel.className = 'text-muted fs-7';
		sizeLabel.textContent = '페이지당';
		var sizeSelect = document.createElement('select');
		sizeSelect.className = 'form-select form-select-sm form-select-solid w-auto';
		pageSizes.forEach(function (n) {
			var o = document.createElement('option');
			o.value = String(n);
			o.textContent = n + '개';
			if (n === pageSize) o.selected = true;
			sizeSelect.appendChild(o);
		});

		var navEl = document.createElement('ul');
		navEl.className = 'pagination pagination-sm mb-0 tp-nav';

		var leftGroup = document.createElement('div');
		leftGroup.className = 'd-flex align-items-center gap-4';
		leftGroup.appendChild(infoEl);
		sizeWrap.appendChild(sizeLabel);
		sizeWrap.appendChild(sizeSelect);

		var searchInput = null;
		if (opts.searchInput) {
			// 화면에 이미 있는 입력칸을 그대로 쓴다 — 페이저 안에 또 만들면 검색창이 두 개가 된다.
			searchInput = typeof opts.searchInput === 'string'
				? document.querySelector(opts.searchInput)
				: opts.searchInput;
		} else if (opts.search) {
			searchInput = document.createElement('input');
			searchInput.type = 'search';
			searchInput.className = 'form-control form-control-sm form-control-solid w-200px';
			searchInput.placeholder = opts.searchPlaceholder || '검색';
			searchInput.setAttribute('aria-label', '표 검색');
			leftGroup.appendChild(searchInput);
		}

		leftGroup.appendChild(sizeWrap);

		wrap.appendChild(leftGroup);
		wrap.appendChild(navEl);
		table.parentNode.insertBefore(wrap, table.nextSibling);

		function totalPages() {
			return Math.max(1, Math.ceil(total / pageSize));
		}

		function pageButton(label, targetPage, opts2) {
			opts2 = opts2 || {};
			var li = document.createElement('li');
			li.className = 'page-item' + (opts2.active ? ' active' : '') + (opts2.disabled ? ' disabled' : '');
			var a = document.createElement('a');
			a.href = '#';
			a.className = 'page-link';
			a.textContent = label;
			if (!opts2.disabled && !opts2.active) {
				a.addEventListener('click', function (e) {
					e.preventDefault();
					page = targetPage;
					render();
				});
			} else {
				a.addEventListener('click', function (e) { e.preventDefault(); });
			}
			li.appendChild(a);
			return li;
		}

		function render() {
			var tp = totalPages();
			if (page > tp) page = tp;
			if (page < 1) page = 1;

			var start = (page - 1) * pageSize;
			var end = Math.min(start + pageSize, total);

			// 검색에서 떨어진 행은 아예 숨기고, 통과한 행만 페이지로 나눈다.
			allRows.forEach(function (r) { r.style.display = 'none'; });
			visibleRows.forEach(function (r, i) {
				if (i >= start && i < end) { r.style.display = ''; }
			});

			infoEl.textContent = total > 0
				? '총 ' + total.toLocaleString('ko-KR') + labelUnit + ' 중 ' + (start + 1).toLocaleString('ko-KR') + '~' + end.toLocaleString('ko-KR')
				: '결과 없음';

			navEl.innerHTML = '';
			if (tp <= 1) return;

			navEl.appendChild(pageButton('‹', page - 1, { disabled: page <= 1 }));

			var windowSize = 5;
			var from = Math.max(1, page - Math.floor(windowSize / 2));
			var to = Math.min(tp, from + windowSize - 1);
			from = Math.max(1, to - windowSize + 1);

			if (from > 1) {
				navEl.appendChild(pageButton('1', 1));
				if (from > 2) navEl.appendChild(pageButton('…', page, { disabled: true }));
			}
			for (var p = from; p <= to; p++) {
				navEl.appendChild(pageButton(String(p), p, { active: p === page }));
			}
			if (to < tp) {
				if (to < tp - 1) navEl.appendChild(pageButton('…', page, { disabled: true }));
				navEl.appendChild(pageButton(String(tp), tp));
			}

			navEl.appendChild(pageButton('›', page + 1, { disabled: page >= tp }));
		}

		/** 행 전체 텍스트에서 찾는다 — 어느 칸에 있든 걸리는 게 표 검색의 기대 동작이다. */
		function applySearch() {
			if (query === '') {
				visibleRows = allRows;
			} else {
				visibleRows = allRows.filter(function (r) {
					if (!r.hasAttribute('data-tp-text')) {
						// 매 입력마다 textContent 를 다시 읽으면 행이 많을 때 느리다 — 한 번만 만들어 캐시한다.
						r.setAttribute('data-tp-text', (r.textContent || '').replace(/\s+/g, ' ').toLowerCase());
					}
					return r.getAttribute('data-tp-text').indexOf(query) !== -1;
				});
			}
			total = visibleRows.length;
			page = 1;
			render();
		}

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				query = (searchInput.value || '').trim().toLowerCase();
				applySearch();
			});
		}

		sizeSelect.addEventListener('change', function () {
			pageSize = parseInt(sizeSelect.value, 10) || pageSize;
			page = 1;
			render();
		});

		render();

		return {
			refresh: function () {
				allRows = Array.prototype.slice.call(tbody.rows).filter(function (r) {
					return !r.hasAttribute('data-tp-skip');
				});
				// 행이 갈렸으니 캐시한 검색용 텍스트도 버린다.
				allRows.forEach(function (r) { r.removeAttribute('data-tp-text'); });
				applySearch();
			},
		};
	}

	global.initTablePaginate = initTablePaginate;
})(window);

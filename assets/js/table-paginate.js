/**
 * 가벼운 클라이언트 페이징 — DataTables 없이 그 느낌(번호 버튼·페이지당 건수·
 * "총 N건 중 X~Y" 표시)만 재현. 서버는 필터(기간·검색어)에 맞는 결과를 이미 다
 * 내려주고, 여기서는 그 결과를 새로고침 없이 페이지로만 나눠 보여준다.
 *
 * 사용법: initTablePaginate(document.getElementById('someTable'), { pageSize: 30 })
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
		var total = allRows.length;
		var page = 1;

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

			allRows.forEach(function (r, i) {
				r.style.display = (i >= start && i < end) ? '' : 'none';
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
				total = allRows.length;
				page = 1;
				render();
			},
		};
	}

	global.initTablePaginate = initTablePaginate;
})(window);

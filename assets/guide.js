(() => {
	'use strict';

	const selector = '[data-bsc-tv-guide]';
	const guides = new WeakMap();

	function showDay(content, preferred) {
		const select = content.querySelector('[data-guide-select]');
		if (!select) return;
		if (preferred && Array.from(select.options).some(option => option.value === preferred)) {
			select.value = preferred;
		}

		const days = content.querySelectorAll('[data-guide-day]');
		for (const day of days) {
			day.hidden = day.dataset.guideDay !== select.value;
			day.querySelector('[data-guide-date]').hidden = days.length > 1;
		}
		content.querySelector('[data-guide-navigation]').hidden = days.length === 1;
		content.querySelector('[data-guide-step="-1"]').disabled = select.selectedIndex === 0;
		content.querySelector('[data-guide-step="1"]').disabled = select.selectedIndex === select.length - 1;
	}

	function hideBrokenImage(event) {
		if (event.target instanceof HTMLImageElement) event.target.hidden = true;
	}

	function init(root) {
		if (guides.has(root)) return guides.get(root);
		const content = root.querySelector('[data-guide-content]');
		if (!content) return null;
		const state = { content, loading: false, lastHtml: '' };
		guides.set(root, state);
		root.addEventListener('error', hideBrokenImage, true);
		for (const image of content.querySelectorAll('img')) {
			if (image.complete && !image.naturalWidth) image.hidden = true;
		}
		showDay(content);
		return state;
	}

	async function refresh(root) {
		const state = init(root);
		if (!state || state.loading || !root.dataset.bscTvGuide) return;
		const { content } = state;
		const url = new URL(root.dataset.bscTvGuide, document.baseURI);
		if (url.origin !== location.origin) return;

		state.loading = true;
		try {
			const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
			if (!response.ok) return;
			const data = await response.json();
			if (typeof data.html !== 'string' || state.lastHtml === data.html || content.contains(document.activeElement)) return;
			const date = content.querySelector('[data-guide-select]')?.value;
			const previousGrid = content.querySelector('[data-guide-day]:not([hidden]) .bsc-guide__scroll');
			const scroll = previousGrid?.scrollLeft || 0;
			content.innerHTML = data.html;
			showDay(content, date);
			const grid = content.querySelector('[data-guide-day]:not([hidden]) .bsc-guide__scroll');
			if (grid && grid.closest('[data-guide-day]').dataset.guideDay === date) grid.scrollLeft = scroll;
			state.lastHtml = data.html;
		} catch {
			// Keep the visible schedule until WordPress is available again.
		} finally {
			state.loading = false;
		}
	}

	function refreshAll() {
		if (!document.hidden) document.querySelectorAll(selector).forEach(refresh);
	}

	document.addEventListener('change', event => {
		if (event.target.matches('[data-guide-select]')) showDay(event.target.closest('[data-guide-content]'));
	});

	document.addEventListener('click', event => {
		const button = event.target.closest('[data-guide-step]');
		if (!button || button.disabled) return;
		const content = button.closest('[data-guide-content]');
		const select = content.querySelector('[data-guide-select]');
		select.selectedIndex += Number(button.dataset.guideStep);
		showDay(content);
		if (button.disabled) select.focus();
	});

	function start() {
		document.querySelectorAll(selector).forEach(init);
		window.elementorFrontend?.hooks?.addAction('frontend/element_ready/bsc-tv-guide.default', scope => {
			scope[0]?.querySelectorAll(selector).forEach(init);
		});
		refreshAll();
		window.setInterval(refreshAll, 60000);
		document.addEventListener('visibilitychange', refreshAll);
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
})();

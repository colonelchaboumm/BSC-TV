(() => {
	'use strict';

	const selector = '[data-bsc-tv-guide]';
	const guides = new WeakMap();

	function showWeeks({ content, weeksVisible }) {
		const weeks = content.querySelectorAll('[data-guide-week]');
		weeks.forEach((week, index) => { week.hidden = index >= weeksVisible; });
		const button = content.querySelector('[data-guide-more]');
		if (!button) return;
		const days = weeks[weeksVisible]?.querySelectorAll('[data-guide-day]').length || 0;
		button.hidden = days === 0;
		button.textContent = days === 1 ? 'Afficher le jour suivant' : `Afficher les ${days} jours suivants`;
	}

	function hideBrokenImage(event) {
		if (event.target instanceof HTMLImageElement) event.target.hidden = true;
	}

	function init(root) {
		if (guides.has(root)) return guides.get(root);
		const content = root.querySelector('[data-guide-content]');
		if (!content) return null;
		const state = { content, loading: false, lastHtml: '', weeksVisible: 1 };
		guides.set(root, state);
		root.addEventListener('error', hideBrokenImage, true);
		content.addEventListener('click', event => {
			if (!event.target.closest('[data-guide-more]')) return;
			const nextDate = content.querySelector('[data-guide-week][hidden] [data-guide-date]');
			if (!nextDate) return;
			state.weeksVisible += 1;
			showWeeks(state);
			nextDate.focus();
		});
		for (const image of content.querySelectorAll('img')) {
			if (image.complete && !image.naturalWidth) image.hidden = true;
		}
		showWeeks(state);
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
			if (typeof data.html !== 'string' || state.lastHtml === data.html) return;
			const focused = document.activeElement;
			if (content.contains(focused) && !focused.matches('[data-guide-date]')) return;
			const focusedDate = focused?.closest('[data-guide-day]')?.dataset.guideDay;
			const scrolls = new Map(Array.from(content.querySelectorAll('[data-guide-day]'), day => [
				day.dataset.guideDay, day.querySelector('.bsc-guide__scroll')?.scrollLeft || 0,
			]));
			content.innerHTML = data.html;
			showWeeks(state);
			for (const day of content.querySelectorAll('[data-guide-day]')) {
				const grid = day.querySelector('.bsc-guide__scroll');
				if (grid) grid.scrollLeft = scrolls.get(day.dataset.guideDay) || 0;
				if (day.dataset.guideDay === focusedDate) day.querySelector('[data-guide-date]').focus({ preventScroll: true });
			}
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

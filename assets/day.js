(() => {
	'use strict';
	const instances = new WeakMap();
	class DayList {
		constructor(root) {
			this.root = root;
			this.content = root.querySelector('[data-day-content]');
			this.status = root.querySelector('[data-day-status]');
			this.more = root.querySelector('[data-day-more]');
			if (!this.content || !this.status) return;
			this.page = 1;
			this.date = root.dataset.dayDate || '';
			this.visible = !window.IntersectionObserver;
			this.disposed = false;
			this.lastRequest = -Infinity;
			try {
				this.url = new URL(root.dataset.bscDay, document.baseURI);
				if (this.url.origin !== new URL(document.baseURI).origin || !['http:', 'https:'].includes(this.url.protocol) || !(/\/bsc-tv\/v1\/day\/?$/.test(this.url.pathname) || this.url.searchParams.get('rest_route') === '/bsc-tv/v1/day')) return;
			} catch { return; }
			this.loadMore = () => this.refresh(true);
			this.more?.addEventListener('click', this.loadMore);
			this.visibility = () => {
				if (!this.root.isConnected) { this.dispose(); return; }
				if (document.hidden) window.clearTimeout(this.timer);
				else if (this.visible) this.refresh();
			};
			document.addEventListener('visibilitychange', this.visibility);
			if (window.IntersectionObserver) {
				this.observer = new window.IntersectionObserver(entries => {
					if (!this.root.isConnected) { this.dispose(); return; }
					this.visible = entries.some(entry => entry.isIntersecting);
					if (this.visible) this.refresh(); else window.clearTimeout(this.timer);
				}, { rootMargin: '200px' });
				this.observer.observe(root);
			} else this.refresh();
		}
		schedule(seconds) {
			window.clearTimeout(this.timer);
			if (!this.disposed && this.visible && !document.hidden) this.timer = window.setTimeout(() => this.refresh(), Math.max(5, Math.min(60, Number(seconds) || 60)) * 1000);
		}
		async refresh(expand = false) {
			if (!this.root.isConnected) { this.dispose(); return; }
			if (this.disposed || this.busy || (!this.visible && !expand) || document.hidden) return;
			if (!expand && performance.now() - this.lastRequest < 5000) { this.schedule(5); return; }
			this.busy = true;
			if (this.more) this.more.disabled = true;
			this.lastRequest = performance.now();
			this.controller = new AbortController();
			this.content.setAttribute('aria-busy', 'true');
			let next = 60;
			try {
				const url = new URL(this.url);
				url.searchParams.set('page', String(this.page + (expand ? 1 : 0)));
				if (this.date) url.searchParams.set('date', this.date);
				const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', signal: this.controller.signal });
				if (!response.ok) throw new Error('Unavailable');
				const data = await response.json();
				if (typeof data.html !== 'string' || !Number.isInteger(data.page) || data.page < 1 || data.page > 500 || typeof data.has_more !== 'boolean' || !/^\d{4}-\d{2}-\d{2}$/.test(data.date)) throw new Error('Invalid response');
				if (this.disposed || !this.root.isConnected) return;
				// This fragment is generated and escaped by the same-origin day-view.php.
				// Do not replace a keyboard user's focused link during an update.
				if (!this.content.contains(document.activeElement)) {
					if (this.content.innerHTML !== data.html) this.content.innerHTML = data.html;
					this.page = data.page;
					this.date = data.date;
					if (this.more) this.more.hidden = !data.has_more;
				}
				this.status.textContent = expand ? (data.has_more ? 'Programmes supplémentaires affichés.' : 'Tous les programmes disponibles de la journée sont affichés.') : '';
				// Keep keyboard focus reachable when the final load hides its button.
				if (expand && this.more?.hidden && document.activeElement === this.more) { this.status.tabIndex = -1; this.status.focus(); }
				next = data.poll_after;
			} catch (error) {
				if (!this.disposed && error.name !== 'AbortError') this.status.textContent = 'Actualisation indisponible. Le programme affiché est conservé.';
			} finally {
				this.busy = false;
				if (this.more) this.more.disabled = false;
				this.content.setAttribute('aria-busy', 'false');
				this.schedule(next);
			}
		}
		dispose() {
			this.disposed = true;
			window.clearTimeout(this.timer);
			this.controller?.abort();
			this.observer?.disconnect();
			document.removeEventListener('visibilitychange', this.visibility);
			this.more?.removeEventListener('click', this.loadMore);
		}
	}
	function init(scope = document) {
		for (const root of scope.querySelectorAll('[data-bsc-day]')) if (!instances.has(root)) instances.set(root, new DayList(root));
	}
	let bound = false;
	function bindElementor() {
		if (bound || !window.elementorFrontend?.hooks) return;
		window.elementorFrontend.hooks.addAction('frontend/element_ready/bsc-tv-day.default', scope => init(scope[0] || document));
		bound = true;
	}
	function start() {
		init();
		bindElementor();
		window.jQuery?.(window).on('elementor/frontend/init', bindElementor);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})();

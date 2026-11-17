(() => {
	const pollingInterval = 60_000;
	const dropdownSelector = '.bsc-tv-badge:not(.bsc-tv-badge--inline)';
	const hoverTimers = new WeakMap();
	const openedByHover = new WeakSet();
	const hideBrokenImage = (event) => {
		const image = event.target;
		if (image instanceof HTMLImageElement && image.closest('[data-bsc-tv]')) {
			if (image.classList.contains('bsc-tv-card__media')) {
				image.closest('.bsc-tv-card')?.classList.remove('bsc-tv-card--image');
			}
			image.hidden = true;
		}
	};
	document.addEventListener('error', hideBrokenImage, true);
	for (const image of document.querySelectorAll('[data-bsc-tv] img')) {
		if (image.complete && image.naturalWidth === 0) hideBrokenImage({ target: image });
	}

	const positionPanel = (badge) => {
		if (!badge.open || badge.classList.contains('bsc-tv-badge--inline')) return;
		const panel = badge.querySelector('.bsc-tv-badge__panel');
		const trigger = badge.querySelector('.bsc-tv-badge__trigger');
		const bounds = trigger.getBoundingClientRect();
		const viewport = document.documentElement;
		const below = viewport.clientHeight - bounds.bottom - 20;
		const above = bounds.top - 20;
		const useAbove = below < Math.min(240, panel.scrollHeight) && above > below;
		badge.dataset.bscTvPlacement = useAbove ? 'above' : 'below';
		panel.style.setProperty('--bsc-tv-badge-panel-available', `${Math.max(80, useAbove ? above : below)}px`);

		panel.style.removeProperty('--bsc-tv-badge-panel-shift');
		const box = panel.getBoundingClientRect();
		const shift = Math.max(12 - box.left, Math.min(0, viewport.clientWidth - 12 - box.right));
		panel.style.setProperty('--bsc-tv-badge-panel-shift', `${shift}px`);
	};

	const closeBadge = (badge, restoreFocus) => {
		clearTimeout(hoverTimers.get(badge));
		openedByHover.delete(badge);
		badge.open = false;
		if (restoreFocus) badge.querySelector('.bsc-tv-badge__trigger').focus();
	};

	document.addEventListener('toggle', ({ target }) => {
		if (!target.matches?.(dropdownSelector)) return;
		if (target.open) positionPanel(target);
		else openedByHover.delete(target);
	}, true);

	document.addEventListener('pointerover', (event) => {
		if (event.pointerType !== 'mouse') return;
		const badge = event.target.closest?.(dropdownSelector);
		if (!badge || badge.contains(event.relatedTarget)) return;
		clearTimeout(hoverTimers.get(badge));
		if (badge.open || !event.target.closest('.bsc-tv-badge__trigger')) return;
		hoverTimers.set(badge, setTimeout(() => {
			if (badge.open || !badge.isConnected) return;
			openedByHover.add(badge);
			badge.open = true;
		}, 250));
	});

	document.addEventListener('pointerout', (event) => {
		if (event.pointerType !== 'mouse') return;
		const badge = event.target.closest?.(dropdownSelector);
		if (!badge || badge.contains(event.relatedTarget)) return;
		clearTimeout(hoverTimers.get(badge));
		if (!badge.open) return;
		hoverTimers.set(badge, setTimeout(() => {
			if (!badge.querySelector(':focus-visible')) closeBadge(badge, false);
		}, 500));
	});

	document.addEventListener('focusout', (event) => {
		const badge = event.target.closest?.(dropdownSelector);
		if (badge?.open && !badge.contains(event.relatedTarget)) closeBadge(badge, false);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape' || event.defaultPrevented) return;
		const badge = event.target.closest?.('.bsc-tv-badge[open]');
		if (badge) {
			event.preventDefault();
			closeBadge(badge, true);
			return;
		}
		for (const dropdown of document.querySelectorAll(`${dropdownSelector}[open]`)) {
			event.preventDefault();
			closeBadge(dropdown, false);
		}
	});
	document.addEventListener('click', (event) => {
		const { target } = event;
		const trigger = target.closest?.('.bsc-tv-badge__trigger');
		const clicked = trigger?.closest(dropdownSelector);
		const mouseClick = event.pointerType ? event.pointerType === 'mouse' : event.detail > 0;
		if (clicked && mouseClick && openedByHover.has(clicked)) {
			// The first click confirms an already visible menu. A second click toggles it.
			event.preventDefault();
			openedByHover.delete(clicked);
		}
		for (const badge of document.querySelectorAll(`${dropdownSelector}[open]`)) {
			if (!badge.contains(target)) closeBadge(badge, false);
		}
	});
	window.addEventListener('resize', () => {
		for (const badge of document.querySelectorAll('.bsc-tv-badge[open]')) positionPanel(badge);
	});

	const updateBadge = (widget, highlight) => {
		const badge = widget.querySelector('.bsc-tv-badge');
		if (!badge || !highlight || !['live', 'upcoming', 'empty'].includes(highlight.state)) return;
		if (![highlight.title, highlight.datetime, highlight.date].every((value) => typeof value === 'string')) return;
		const status = badge.querySelector('.bsc-tv-badge__status');
		const label = status.querySelector('.bsc-tv-badge__label');
		const labels = { live: status.dataset.bscTvLiveLabel, upcoming: status.dataset.bscTvUpcomingLabel, empty: status.dataset.bscTvEmptyLabel };
		widget.dataset.bscTvState = highlight.state;
		if (label.textContent !== labels[highlight.state]) label.textContent = labels[highlight.state];
		const title = badge.querySelector('.bsc-tv-badge__title');
		const date = badge.querySelector('.bsc-tv-badge__date');
		if (title) {
			if (title.textContent !== highlight.title) title.textContent = highlight.title;
			title.hidden = !highlight.title;
		}
		if (date) {
			if (date.textContent !== highlight.date) date.textContent = highlight.date;
			if (highlight.datetime) date.dateTime = highlight.datetime;
			else date.removeAttribute('datetime');
			date.hidden = !highlight.date;
		}
		const details = badge.querySelector('.bsc-tv-badge__details');
		if (details) details.hidden = !(title && !title.hidden) && !(date && !date.hidden);
		positionPanel(badge);
	};

	const tick = async () => {
		if (document.visibilityState === 'hidden') return;
		const groups = new Map();
		for (const widget of document.querySelectorAll('[data-bsc-tv]')) {
			const url = widget.dataset.bscTv;
			if (!groups.has(url)) groups.set(url, []);
			groups.get(url).push(widget);
		}
		await Promise.allSettled(Array.from(groups, async ([url, widgets]) => {
			const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
			if (!response.ok) return;
			const data = await response.json();
			if (typeof data.html !== 'string' || !Number.isInteger(data.live)) return;
			for (const widget of widgets) {
				const programme = widget.querySelector('.bsc-tv-programme');
				if (!programme) continue;
				if (!programme.contains(document.activeElement) && widget.bscTvMarkup !== data.html) {
					programme.innerHTML = data.html;
					widget.bscTvMarkup = data.html;
				}
				updateBadge(widget, data.highlight);
				if (Number(widget.dataset.live) !== data.live) {
					widget.dataset.live = String(data.live);
					widget.querySelector('.bsc-tv-announcement').textContent = data.live ? `${data.live} émission${data.live > 1 ? 's' : ''} en direct` : 'Aucune émission en direct';
				}
			}
		}));
	};
	let refreshing = false;
	const refresh = async () => {
		if (refreshing) return;
		refreshing = true;
		try {
			await tick();
		} finally {
			refreshing = false;
		}
	};
	window.setInterval(refresh, pollingInterval);
	document.addEventListener('visibilitychange', refresh);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', refresh, { once: true });
	} else {
		refresh();
	}
})();

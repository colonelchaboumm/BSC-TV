(() => {
	const pollingInterval = 60_000;
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
				if (!programme || programme.contains(document.activeElement)) continue;
				if (widget.bscTvMarkup !== data.html) {
					programme.innerHTML = data.html;
					widget.bscTvMarkup = data.html;
				}
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

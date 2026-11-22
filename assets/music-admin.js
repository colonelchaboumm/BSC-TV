(() => {
	'use strict';
	for (const media of document.querySelectorAll('[data-music-media]')) {
		const input = media.querySelector('input');
		media.querySelector('[data-media-choose]').addEventListener('click', () => {
			const frame = wp.media({ title: 'Choisir un fichier BSC', library: { type: media.dataset.type }, multiple: false });
			frame.on('select', () => {
				const file = frame.state().get('selection').first().toJSON();
				input.value = file.id; media.dataset.url = file.url;
				media.querySelector('[data-media-name]').textContent = file.title || file.filename;
			}); frame.open();
		});
		media.querySelector('[data-media-clear]').addEventListener('click', () => { input.value = '0'; media.dataset.url = ''; media.querySelector('[data-media-name]').textContent = 'Aucun fichier'; });
	}
	let timer;
	const screen = document.querySelector('[data-music-preview-screen]');
	for (const button of document.querySelectorAll('[data-music-preview]')) button.addEventListener('click', () => {
		clearTimeout(timer);
		const form = button.closest('form'); const phase = button.dataset.musicPreview;
		const value = name => form.elements.namedItem(`music[${name}]`)?.value;
		const identity = form.querySelector(`[name="music[${phase}_video]"]`)?.closest('[data-music-media]');
		const logo = form.querySelector('[name="music[logo]"]')?.closest('[data-music-media]');
		screen.replaceChildren(); screen.hidden = false; screen.style.backgroundColor = value('background'); screen.style.color = value('color');
		if (identity?.dataset.url) {
			const video = document.createElement('video'); video.src = identity.dataset.url; video.muted = !form.elements.namedItem('music[sound]')?.checked; video.playsInline = true; video.controls = true; screen.append(video); video.play().catch(() => {});
		} else {
			if (logo?.dataset.url) { const image = document.createElement('img'); image.src = logo.dataset.url; image.alt = 'Logo officiel BSC'; screen.append(image); }
			const checks = form.querySelector('input[type="checkbox"][name="music[show_text]"]');
			if (checks?.checked) { const text = document.createElement('p'); text.textContent = value(`${phase}_text`); screen.append(text); }
			screen.dataset.animation = value('animation');
		}
		timer = setTimeout(() => { screen.querySelector('video')?.pause(); screen.hidden = true; }, Math.max(1, Math.min(30, Number(value(phase)) || 3)) * 1000);
	});
	for (const button of document.querySelectorAll('[data-music-delete]')) button.addEventListener('click', event => { if (!window.confirm('Mettre ce clip à la corbeille ?')) event.preventDefault(); });
})();

(() => {
	'use strict';
	for (const form of document.querySelectorAll('[data-music-playlist]')) {
		const message = form.querySelector('[data-playlist-status]');
		const start = form.querySelector('[data-playlist-start]');
		const resume = form.querySelector('[data-playlist-resume]');
		const progress = form.querySelector('[data-playlist-progress]');
		const library = form.querySelector('[data-playlist-library]');
		let job = null; let busy = false;
		try { const saved = JSON.parse(form.dataset.job || '{}'); if (saved.token) job = saved; } catch { /* An empty form starts without a saved import. */ }
		const render = error => {
			resume.hidden = busy || !job?.token || job.done;
			start.disabled = busy;
			for (const input of [form.elements.namedItem('playlist_url'), form.elements.namedItem('playlist_category')]) input.disabled = busy;
			if (!job?.token) { message.textContent = error || (busy ? 'Préparation de l’import…' : ''); return; }
			const counts = `${job.created} ajouté(s), ${job.assigned} catégorie(s) ajoutée(s), ${job.duplicates} déjà présent(s), ${job.unavailable} indisponible(s).`;
			message.textContent = `${job.done ? (job.limited ? 'Limite atteinte.' : 'Import terminé.') : busy ? 'Import en cours…' : 'Import en attente : vous pouvez le reprendre.'} ${counts} ${error || job.error || job.warning || ''}`;
			progress.hidden = false; progress.max = Math.max(1, job.total || job.processed || 1); progress.value = Math.min(progress.max, job.processed);
			library.hidden = !job.done;
			if (job.done) { const url = new URL(form.dataset.library, document.baseURI); url.searchParams.set('music_imported', job.token); url.hash = 'music-library'; library.href = url.href; }
		};
		const request = async fields => {
			const url = new URL(form.dataset.endpoint, document.baseURI);
			if (url.origin !== location.origin) throw new Error('Adresse d’import invalide.');
			const body = new URLSearchParams({ action: 'bsc_tv_music_playlist', nonce: form.dataset.nonce, ...fields });
			const controller = new AbortController(); const timeout = window.setTimeout(() => controller.abort(), 30000);
			try {
				const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
				let result;
				try { result = await response.json(); } catch { throw new Error('Réponse WordPress illisible. Rechargez la page pour reprendre l’import.'); }
				if (!response.ok || !result.success) { if (result.data?.job) job = result.data.job; throw new Error(result.data?.message || 'Import indisponible. Réessayez ou reconnectez-vous à WordPress.'); }
				return result.data;
			} finally { window.clearTimeout(timeout); }
		};
		const run = async fresh => {
			if (busy) return;
			busy = true; render();
			try {
				if (fresh) job = await request({ operation: 'start', url: form.elements.namedItem('playlist_url').value, category: form.elements.namedItem('playlist_category').value });
				while (job?.token && !job.done) {
					render(); const next = await request({ operation: 'step', token: job.token, step: String(job.step) });
					if (!next.done && next.step <= job.step) throw new Error('La progression n’a pas avancé. Rechargez la page pour reprendre l’import.');
					job = next;
				}
				busy = false; render();
			} catch (error) { busy = false; render(error.name === 'AbortError' ? 'La requête a expiré. Reprenez l’import ; les doublons sont évités.' : error instanceof TypeError ? 'Connexion interrompue. Vous pouvez reprendre l’import.' : error.message); }
		};
		form.addEventListener('submit', event => { event.preventDefault(); run(true); });
		resume.addEventListener('click', () => {
			if (job?.url) form.elements.namedItem('playlist_url').value = job.url;
			if (job?.category) form.elements.namedItem('playlist_category').value = job.category;
			run(false);
		});
		render();
	}
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
		const bundled = form.querySelector('input[type="checkbox"][name="music[bundled_animation]"]')?.checked;
		const animationURL = identity?.dataset.url || (bundled ? identity?.dataset.defaultUrl : '');
		screen.replaceChildren(); screen.hidden = false; screen.style.backgroundColor = value('background'); screen.style.color = value('color');
		if (animationURL) {
			const video = document.createElement('video'); video.src = animationURL; video.muted = !form.elements.namedItem('music[sound]')?.checked; video.playsInline = true; video.controls = true; screen.append(video); video.play().catch(() => {});
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

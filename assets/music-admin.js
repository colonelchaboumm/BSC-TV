(() => {
	'use strict';
	for (const form of document.querySelectorAll('[data-music-bulk]')) {
		const scope = form.elements.namedItem('bulk[scope]');
		const operation = form.elements.namedItem('bulk[operation]');
		const validation = form.querySelector('[data-bulk-validation]');
		const summary = form.querySelector('[data-bulk-summary]');
		const submit = form.querySelector('[data-bulk-submit]');
		const boxes = [...document.querySelectorAll('[data-music-selection]')].filter(input => input.form === form);
		const selectPage = document.querySelector('[data-music-select-page]');
		const render = () => {
			const count = boxes.filter(input => input.checked).length;
			const all = scope.value === 'all';
			const approving = operation.value === 'approve';
			validation.hidden = !approving;
			for (const field of validation.querySelectorAll('input, textarea')) {
				field.disabled = !approving;
				field.required = approving && field.name !== 'bulk[activate]';
			}
			summary.textContent = all ? `Toute la bibliothèque : jusqu’à ${form.dataset.total} clips, toutes les pages. Le filtre de catégorie s’applique.` : `${count} clip(s) coché(s) sur cette page. Le filtre de catégorie s’applique.`;
			submit.disabled = all ? Number(form.dataset.total) < 1 : count === 0;
			submit.textContent = all ? 'Appliquer à toute la bibliothèque' : 'Appliquer à la sélection';
			if (selectPage) { selectPage.checked = boxes.length > 0 && count === boxes.length; selectPage.indeterminate = count > 0 && count < boxes.length; }
		};
		for (const input of [scope, operation, ...boxes]) input.addEventListener('change', render);
		selectPage?.addEventListener('change', () => { for (const input of boxes) input.checked = selectPage.checked; render(); });
		form.addEventListener('submit', () => { submit.disabled = true; submit.textContent = 'Traitement du lot…'; });
		render();
	}
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
			const body = new URLSearchParams({ action: 'bsc_tv_music_playlist', nonce: form.dataset.nonce, station: form.dataset.station || 'main', ...fields });
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
	const mediaDuration = file => {
		const raw = file.length ?? file.fileLength;
		const length = typeof raw === 'number' ? raw : typeof raw === 'string' ? raw.split(':').reduce((total, part) => total * 60 + (Number(part) || 0), 0) : 0;
		return length > 0 && length <= 120 ? Math.ceil(length) + (Number.isInteger(length) ? 1 : 0) : 0;
	};
	for (const library of document.querySelectorAll('[data-music-animations]')) {
		const list = library.querySelector('[data-animation-list]');
		const status = library.querySelector('[data-animation-status]');
		list.addEventListener('click', event => { const button = event.target.closest('[data-animation-remove]'); if (button) { button.closest('[data-animation-item]').remove(); status.textContent = 'Animation retirée de la sélection. Enregistrez les réglages pour mettre à jour les futurs interludes.'; } });
		library.querySelector('[data-animation-add]').addEventListener('click', () => {
			const frame = wp.media({ title: 'Ajouter des animations BSC', library: { type: 'video' }, multiple: true });
			frame.on('select', () => {
				const files = frame.state().get('selection').toJSON(); let added = 0;
				for (const file of files) {
					if (list.querySelector(`[data-id="${Number(file.id)}"]`) || list.children.length >= 50) continue;
					const row = document.createElement('li'); row.dataset.animationItem = ''; row.dataset.id = String(Number(file.id)); row.dataset.url = file.url || ''; row.dataset.duration = String(mediaDuration(file));
					const input = document.createElement('input'); input.type = 'hidden'; input.name = 'music[animation_ids][]'; input.value = row.dataset.id;
					const name = document.createElement('span'); name.textContent = `${file.title || file.filename || 'Animation BSC'} · ${Number(row.dataset.duration) ? `${row.dataset.duration} s réservées` : 'durée à vérifier après enregistrement'}`;
					const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button'; remove.dataset.animationRemove = ''; remove.textContent = 'Retirer';
					row.append(input, name, remove); list.append(row); added++;
				}
				status.textContent = `${added} animation(s) ajoutée(s). ${list.children.length >= 50 ? 'Limite de 50 vidéos personnalisées atteinte. ' : ''}Enregistrez les réglages pour les utiliser dans les futurs interludes.`;
			}); frame.open();
		});
	}
	for (const media of document.querySelectorAll('[data-music-media]')) {
		const input = media.querySelector('input');
		media.querySelector('[data-media-choose]').addEventListener('click', () => {
			const frame = wp.media({ title: 'Choisir un fichier BSC', library: { type: media.dataset.type }, multiple: false });
			frame.on('select', () => {
				const file = frame.state().get('selection').first().toJSON();
				input.value = file.id; media.dataset.url = file.url; media.dataset.duration = String(mediaDuration(file));
				media.querySelector('[data-media-name]').textContent = file.title || file.filename;
			}); frame.open();
		});
		media.querySelector('[data-media-clear]').addEventListener('click', () => { input.value = '0'; media.dataset.url = ''; media.dataset.duration = '0'; media.querySelector('[data-media-name]').textContent = 'Aucun fichier'; });
	}
	let timer;
	const screen = document.querySelector('[data-music-preview-screen]');
	for (const button of document.querySelectorAll('[data-music-preview]')) button.addEventListener('click', () => {
		clearTimeout(timer);
		for (const media of screen.querySelectorAll('video, audio')) media.pause();
		const form = button.closest('form'); const phase = button.dataset.musicPreview;
		const value = name => form.elements.namedItem(`music[${name}]`)?.value;
		const identity = form.querySelector(`[name="music[${phase}_video]"]`)?.closest('[data-music-media]');
		const logo = form.querySelector('[name="music[logo]"]')?.closest('[data-music-media]');
		const bundled = form.querySelector('input[type="checkbox"][name="music[bundled_animation]"]')?.checked;
		const library = form.querySelector('[data-music-animations]');
		const choices = library ? [...library.querySelectorAll('[data-animation-item]'), ...(bundled ? [...library.querySelectorAll('[data-bundled-animation]')] : [])].filter(node => node.dataset.url && Number(node.dataset.duration) > 0) : [];
		const choice = choices.length ? choices[Math.floor(Math.random() * choices.length)] : null;
		const animationURL = identity?.dataset.url || choice?.dataset.url || '';
		const duration = Number(identity?.dataset.url ? identity.dataset.duration : choice?.dataset.duration) || Math.max(1, Math.min(30, Number(value(phase)) || 3));
		const soundtrack = form.querySelector('input[type="checkbox"][name="music[animation_audio_enabled]"]')?.checked;
		const audioURL = soundtrack ? form.querySelector('[name="music[animation_audio_id]"]')?.closest('[data-music-media]')?.dataset.url || form.querySelector('[data-animation-audio-default]')?.dataset.url : '';
		screen.replaceChildren(); screen.hidden = false; screen.style.backgroundColor = value('background'); screen.style.color = value('color');
		if (animationURL) {
			const video = document.createElement('video'); video.src = animationURL; video.muted = !!audioURL || !form.elements.namedItem('music[sound]')?.checked; video.playsInline = true; video.controls = true; screen.append(video); video.play().catch(() => {});
			if (audioURL) { const audio = document.createElement('audio'); audio.src = audioURL; audio.loop = true; audio.controls = true; audio.volume = Math.max(0, Math.min(100, Number(value('animation_audio_volume')) || 0)) / 100; screen.append(audio); audio.play().catch(() => {}); }
		} else {
			if (logo?.dataset.url) { const image = document.createElement('img'); image.src = logo.dataset.url; image.alt = 'Logo officiel BSC'; screen.append(image); }
			const checks = form.querySelector('input[type="checkbox"][name="music[show_text]"]');
			if (checks?.checked) { const text = document.createElement('p'); text.textContent = value(`${phase}_text`); screen.append(text); }
			screen.dataset.animation = value('animation');
		}
		timer = setTimeout(() => { for (const media of screen.querySelectorAll('video, audio')) media.pause(); screen.hidden = true; }, duration * 1000);
	});
	for (const button of document.querySelectorAll('[data-music-delete]')) button.addEventListener('click', event => { if (!window.confirm('Mettre ce clip à la corbeille ?')) event.preventDefault(); });
})();

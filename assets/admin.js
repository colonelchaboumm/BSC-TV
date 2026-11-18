(() => {
	const input = document.querySelector('#bsc-tv-bdd-search');
	const submit = document.querySelector('#bsc-tv-bdd-submit');
	const results = document.querySelector('#bsc-tv-bdd-results');
	const status = document.querySelector('#bsc-tv-bdd-status');
	const field = document.querySelector('#bsc-tv-bdd_id');
	const current = document.querySelector('#bsc-tv-bdd-current');
	const clear = document.querySelector('#bsc-tv-bdd-clear');
	if (!field || !current || !clear) return;

	const preview = (message) => {
		current.textContent = message;
		clear.parentElement.hidden = !field.value;
	};
	field.addEventListener('input', () => {
		preview(field.value ? 'Enregistrez pour vérifier et associer cette fiche.' : 'Aucune fiche sélectionnée.');
	});
	clear.addEventListener('click', () => {
		field.value = '';
		if (results) results.value = '';
		preview('La fiche sera retirée après enregistrement.');
		(input || field).focus();
	});
	if (!input || !results || !status) return;

	let timer;
	let controller;
	async function search(q, focusResults) {
		const request = new AbortController();
		controller = request;
		status.textContent = 'Recherche en cours…';
		try {
			const response = await fetch(input.dataset.url, {
				method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: request.signal,
				body: new URLSearchParams({ action: 'bsc_tv_find_bdd', nonce: input.dataset.nonce, q }),
			});
			const data = await response.json();
			if (request.signal.aborted) return;
			if (!response.ok || !data.success || !Array.isArray(data.data)) {
				status.textContent = data?.data?.message || 'Recherche indisponible. Réessayez ou rechargez la page.';
				return;
			}
			results.replaceChildren(new Option('Choisir une fiche', ''));
			for (const row of data.data) {
				results.add(new Option(`${row.name}${row.type ? ` · ${row.type}` : ''} (#${row.id})`, row.id));
			}
			results.hidden = data.data.length === 0;
			status.textContent = results.hidden ? 'Aucune fiche publiée trouvée pour ce nom.' : 'Choisissez une fiche dans la liste, puis enregistrez.';
			if (!results.hidden && focusResults && [input, submit].includes(document.activeElement)) results.focus();
		} catch {
			if (!request.signal.aborted) status.textContent = 'Recherche indisponible. Réessayez ou rechargez la page.';
		}
	}

	function queueSearch(immediate = false) {
		window.clearTimeout(timer);
		controller?.abort();
		results.hidden = true;
		status.textContent = '';
		const q = input.value.trim();
		if (Array.from(q).length < 2) {
			if (q || immediate) status.textContent = 'Saisissez au moins deux caractères du nom.';
			return;
		}
		if (immediate) search(q, true);
		else timer = window.setTimeout(() => search(q, false), 250);
	}

	input.addEventListener('input', () => queueSearch());
	input.addEventListener('keydown', event => {
		if (event.key !== 'Enter' || event.isComposing) return;
		event.preventDefault();
		queueSearch(true);
	});
	submit?.addEventListener('click', () => queueSearch(true));
	results.addEventListener('change', () => {
		if (!results.value) return;
		field.value = results.value;
		preview(`${results.selectedOptions[0].textContent} sera associée après enregistrement.`);
	});
})();

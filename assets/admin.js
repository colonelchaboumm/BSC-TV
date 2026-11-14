(() => {
	const input = document.querySelector('#bsc-tv-bdd-search');
	const results = document.querySelector('#bsc-tv-bdd-results');
	const status = document.querySelector('#bsc-tv-bdd-status');
	if (!input || !results || !status) return;

	let timer;
	let controller;
	input.addEventListener('input', () => {
		window.clearTimeout(timer);
		controller?.abort();
		results.hidden = true;
		status.textContent = '';
		const q = input.value.trim();
		if (q.length < 2) return;
		timer = window.setTimeout(async () => {
			controller = new AbortController();
			const url = new URL(input.dataset.url);
			url.search = new URLSearchParams({ action: 'bsc_tv_find_bdd', nonce: input.dataset.nonce, q });
			try {
				const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
				const data = await response.json();
				if (!data.success) throw new Error('Recherche indisponible');
				results.replaceChildren(new Option('Choisir une fiche', ''));
				for (const row of data.data) {
					results.add(new Option(`${row.name}${row.type ? ` · ${row.type}` : ''} (#${row.id})`, row.id));
				}
				results.hidden = !data.data.length;
				status.textContent = data.data.length ? `${data.data.length} fiche(s) trouvée(s).` : 'Aucune fiche trouvée.';
			} catch (error) {
				if (error.name !== 'AbortError') status.textContent = 'Recherche indisponible.';
			}
		}, 250);
	});
	results.addEventListener('change', () => {
		if (results.value) document.querySelector('#bsc-tv-bdd_id').value = results.value;
	});
})();

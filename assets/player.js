(() => {
	'use strict';

	const instances = new WeakMap();
	const sdks = new Map();
	let automaticPlayerClaimed = false;
	let playerNumber = 0;
	const safeURL = value => {
		try {
			const url = new URL(value);
			return ['https:', 'http:'].includes(url.protocol) ? url.href : '';
		} catch { return ''; }
	};
	const time = (value, zone) => {
		const options = { hour: '2-digit', minute: '2-digit' };
		try { return new Intl.DateTimeFormat(document.documentElement.lang || 'fr', { ...options, timeZone: zone || 'UTC' }).format(new Date(value * 1000)); }
		catch { return new Intl.DateTimeFormat(document.documentElement.lang || 'fr', options).format(new Date(value * 1000)); }
	};

	function sdk(platform) {
		if (platform === 'youtube' && window.YT?.Player) return Promise.resolve();
		if (platform === 'twitch' && window.Twitch?.Player) return Promise.resolve();
		if (sdks.has(platform)) return sdks.get(platform);
		const promise = new Promise((resolve, reject) => {
			const script = document.createElement('script');
			script.async = true;
			const timeout = window.setTimeout(() => reject(new Error('Le lecteur officiel ne répond pas.')), 15000);
			const ready = () => { window.clearTimeout(timeout); resolve(); };
			script.onerror = () => { window.clearTimeout(timeout); script.remove(); reject(new Error('Le lecteur officiel ne peut pas être chargé.')); };
			if (platform === 'youtube') {
				const previous = window.onYouTubeIframeAPIReady;
				window.onYouTubeIframeAPIReady = () => {
					try { if (typeof previous === 'function') previous(); } finally { ready(); }
				};
				script.src = 'https://www.youtube.com/iframe_api';
			} else {
				script.src = 'https://player.twitch.tv/js/embed/v1.js';
				script.onload = () => window.Twitch?.Player ? ready() : reject(new Error('Lecteur Twitch indisponible.'));
			}
			document.head.append(script);
		}).catch(error => { sdks.delete(platform); throw error; });
		sdks.set(platform, promise);
		return promise;
	}

	class Television {
		constructor(root) {
			this.root = root;
			this.mount = root.querySelector('[data-player-mount]');
			this.screen = root.querySelector('.bsc-player__screen');
			this.message = root.querySelector('[data-player-message]');
			this.button = root.querySelector('[data-player-join]');
			this.transition = root.querySelector('[data-player-transition]');
			this.transitionVideo = root.querySelector('[data-player-transition-video]');
			this.data = null;
			this.clock = 0;
			this.joined = false;
			this.ready = false;
			this.generation = 0;
			this.token = '';
			this.failedToken = '';
			this.player = null;
			this.loading = false;
			this.timer = null;
			this.readyTimer = null;
			this.lastSeek = 0;
			this.transitionAttempt = 0;
			this.button.addEventListener('click', () => this.join());
			root.querySelector('[data-player-avatar]')?.addEventListener('error', event => { event.target.hidden = true; });
			root.querySelector('[data-player-poster]')?.addEventListener('error', event => { event.target.hidden = true; });
			this.transitionVideo?.addEventListener('error', () => { this.transitionBroken = true; this.stopTransition(); });
			this.visibility = () => {
				if (document.hidden) this.pauseTransition();
				else { this.claimAutoplay(); if (this.waitingForPlayback && this.transition?.hidden) this.showTransition(); this.request(); }
			};
			document.addEventListener('visibilitychange', this.visibility);
			this.pulse = window.setInterval(() => this.tick(), 1000);
			this.observer = new IntersectionObserver(entries => {
				this.inView = entries.some(entry => entry.intersectionRatio >= .5);
				if (this.claimAutoplay()) this.request();
			}, { threshold: .5 });
			this.observer.observe(root);
			this.request();
		}

		now() { return this.clock + performance.now() / 1000; }
		text(selector, value) { const node = this.root.querySelector(selector); if (node) node.textContent = value || ''; }
		status(message) { this.message.textContent = message; }
		claimAutoplay() {
			if (!this.root.isConnected || this.joined || !this.inView || this.root.dataset.autoplay !== '1' || automaticPlayerClaimed || document.hidden) return false;
			automaticPlayerClaimed = true;
			this.joined = true;
			return true;
		}

		startTransition() {
			if (!this.transition || !this.transitionVideo || !this.joined || this.transitionBroken) return;
			this.waitingForPlayback = true;
			this.transitionDeadline = performance.now() + Math.max(5, Math.min(60, Number(this.transition.dataset.timeout) || 20)) * 1000;
			try { this.transitionVideo.currentTime = 0; } catch { /* The local clip may not yet be loaded. */ }
			this.showTransition();
		}
		showTransition() {
			if (!this.waitingForPlayback || !this.transition || !this.transitionVideo || document.hidden) return;
			const remaining = this.transitionDeadline - performance.now();
			const url = safeURL(this.transitionVideo.dataset.src);
			if (remaining <= 0 || !url) { this.stopTransition(); return; }
			window.clearTimeout(this.transitionTimer);
			const attempt = ++this.transitionAttempt;
			this.transition.hidden = false;
			this.transitionTimer = window.setTimeout(() => { if (attempt === this.transitionAttempt) this.stopTransition(); }, remaining);
			// Reduced motion uses the clip's static poster, without downloading the MP4.
			this.transitionVideo.hidden = !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
			if (this.transitionVideo.hidden) return;
			this.transitionVideo.muted = true;
			this.transitionVideo.defaultMuted = true;
			if (this.transitionVideo.getAttribute('src') !== url) this.transitionVideo.src = url;
			try { this.transitionVideo.play()?.catch(() => { if (attempt === this.transitionAttempt) this.stopTransition(); }); } catch { this.stopTransition(); }
		}
		pauseTransition() {
			this.transitionAttempt++;
			window.clearTimeout(this.transitionTimer);
			if (this.transition) this.transition.hidden = true;
			try { this.transitionVideo?.pause(); } catch { /* A disabled animation has no media element. */ }
		}
		stopTransition() { this.waitingForPlayback = false; this.pauseTransition(); }
		playing() { this.stopTransition(); this.status(''); }
		ended(message) { this.startTransition(); this.status(message); this.request(); }

		async request() {
			if (!this.root.isConnected) { this.dispose(); return; }
			if (this.loading || document.hidden) return;
			const endpoint = new URL(this.root.dataset.bscPlayer, document.baseURI);
			if (endpoint.origin !== location.origin) return;
			this.loading = true;
			window.clearTimeout(this.timer);
			const began = performance.now();
			const controller = new AbortController();
			const timeout = window.setTimeout(() => controller.abort(), 12000);
			try {
				const response = await fetch(endpoint, { cache: 'no-store', credentials: 'same-origin', signal: controller.signal });
				if (!response.ok) throw new Error('Programme temporairement indisponible.');
				const data = await response.json();
				if (!Number.isFinite(data.server_time) || !Array.isArray(data.next)) throw new Error('Programme invalide.');
				this.clock = data.server_time + (performance.now() - began) / 2000 - performance.now() / 1000;
				this.data = data;
				this.render();
			} catch {
				this.status('Connexion au programme interrompue. Nouvelle tentative automatique.');
			} finally {
				window.clearTimeout(timeout);
				this.loading = false;
				const boundary = this.data?.current?.end || this.data?.next?.[0]?.start || 0;
				const wait = boundary ? Math.max(5, Math.min(60, boundary - this.now() + .5)) : 60;
				this.timer = window.setTimeout(() => this.request(), wait * 1000);
			}
		}

		render() {
			const current = this.data.enabled ? this.data.current : null;
			this.root.querySelector('[data-player-meta]').hidden = !current;
			this.button.disabled = !current;
			for (const badge of this.root.querySelectorAll('[data-player-badge]')) {
				const name = badge.dataset.playerBadge;
				badge.hidden = !current || !(name === 'on_air' || (name === 'replay' ? !current.live && !current.premiere : current[name]));
			}
			const source = this.root.querySelector('[data-player-source]');
			const sourceURL = current ? safeURL(current.url) : '';
			source.hidden = !sourceURL;
			if (sourceURL) source.href = sourceURL; else source.removeAttribute('href');
			this.text('[data-player-title]', current?.title);
			this.text('[data-player-creator]', current?.creator);
			this.text('[data-player-times]', current ? `${current.live ? 'En direct depuis' : 'À l’antenne'} ${time(current.start, this.data.timezone)}${current.end ? ` – ${time(current.end, this.data.timezone)}` : ''}` : '');
			const avatar = this.root.querySelector('[data-player-avatar]');
			if (avatar) {
				const url = current ? safeURL(current.avatar) : '';
				if (!url) { avatar.hidden = true; avatar.removeAttribute('src'); }
				else if (avatar.getAttribute('src') !== url) { avatar.hidden = false; avatar.src = url; }
			}
			const profile = this.root.querySelector('[data-player-profile]');
			const poster = this.root.querySelector('[data-player-poster]');
			if (poster) {
				const url = current ? safeURL(current.image) : '';
				if (!url) { poster.hidden = true; poster.removeAttribute('src'); }
				else if (poster.getAttribute('src') !== url) { poster.hidden = false; poster.src = url; }
			}
			if (profile) {
				const url = current ? safeURL(current.profile_url) : '';
				profile.hidden = !url;
				if (url) profile.href = url; else profile.removeAttribute('href');
			}
			const next = this.data.next[0];
			const nextBox = this.root.querySelector('[data-player-next]');
			if (nextBox) {
				nextBox.hidden = !next || !this.data.enabled;
				this.text('[data-player-next-title]', next?.title);
				this.text('[data-player-next-time]', next ? (next.after_live ? 'Après le direct' : time(next.start, this.data.timezone)) : '');
			}
			if (!current) {
				const gap = this.data.enabled && next ? `gap:${next.key}/${next.start}` : '';
				if (this.token !== gap || this.player || this.starting) { this.destroy(); this.token = gap; if (gap) this.startTransition(); }
				this.tick();
				this.status(!this.data.enabled ? 'La chaîne BSC TV est actuellement désactivée.' : next ? `Prochaine émission à ${time(next.start, this.data.timezone)}.` : 'Aucune émission disponible pour le moment.');
				return;
			}
			const token = `${current.key}/${current.start}/${current.end}`;
			if (token !== this.token) {
				this.destroy();
				this.token = token;
				this.failedToken = '';
				this.status('Rejoignez le programme en cours avec le bouton ci-dessus.');
			}
			this.tick();
			if (current.end && this.now() >= current.end) return;
			if (this.joined && !this.player && this.failedToken !== token && !this.starting) this.load(current, token);
		}

		async join() {
			automaticPlayerClaimed = true;
			this.joined = true;
			if (this.failedToken && this.failedToken === this.token) this.destroy();
			this.failedToken = '';
			if (this.player && this.ready) {
				this.seek(true);
				this.play();
				return;
			}
			await this.request();
			if (this.data?.current && !this.player && !this.starting) this.load(this.data.current, this.token);
		}

		async load(current, token) {
			const platform = current.resource?.platform;
			const id = current.resource?.id;
			if (!(platform === 'youtube' && /^[\w-]{11}$/.test(id)) && !(platform === 'twitch' && (current.live ? /^[a-z0-9_]{2,25}$/i.test(id) : /^v\d{1,20}$/.test(id)))) return;
			if (platform === 'twitch' && this.screen.clientWidth < 400) {
				this.failedToken = token;
				this.status('Ce lecteur Twitch nécessite au moins 400 px de largeur. Utilisez le lien vers Twitch ou agrandissez le lecteur.');
				return;
			}
			if (platform === 'youtube' && this.screen.clientWidth < 200) {
				this.failedToken = token;
				this.status('Agrandissez le lecteur ou ouvrez la vidéo sur YouTube.');
				return;
			}
			this.starting = true;
			this.startTransition();
			const generation = ++this.generation;
			this.screen.dataset.platform = platform;
			try {
				await sdk(platform);
				if (generation !== this.generation || token !== this.token || !this.root.isConnected) return;
				const mount = document.createElement('div');
				mount.id = `bsc-tv-official-player-${++playerNumber}`;
				this.mount.replaceChildren(mount);
				const valid = () => generation === this.generation && token === this.token;
				const ready = () => {
					if (!valid()) return;
					window.clearTimeout(this.readyTimer);
					this.ready = true;
					const iframe = this.mount.querySelector('iframe');
					if (iframe) { iframe.title = `${current.title} · ${platform}`; iframe.referrerPolicy = 'strict-origin-when-cross-origin'; }
					this.seek(true);
					this.play();
				};
				if (platform === 'youtube') {
					this.player = new window.YT.Player(mount.id, {
						width: '100%', height: '100%', videoId: id,
						playerVars: { autoplay: 0, controls: 1, playsinline: 1, fs: 1, origin: location.origin, start: Math.floor(this.position()) },
						events: {
							onReady: () => { if (!valid()) return; if (this.root.dataset.muted === '1') this.player.mute(); else this.player.unMute(); ready(); },
							onStateChange: event => {
								if (!valid()) return;
								if (event.data === window.YT.PlayerState.PLAYING) this.playing();
								if (event.data === window.YT.PlayerState.ENDED) this.ended('Cette vidéo est terminée. Actualisation du programme…');
							},
							onAutoplayBlocked: () => { if (valid()) { this.stopTransition(); this.status('Votre navigateur demande un clic sur « Rejoindre le direct TV » pour démarrer.'); } },
							onError: event => { if (valid()) this.fail(`Cette vidéo ne peut pas être lue ici (YouTube ${event.data}). Utilisez le lien vers YouTube ou réessayez. Le prochain programme démarrera à son horaire.`); },
						},
					});
				} else {
					this.player = new window.Twitch.Player(mount.id, {
						width: '100%', height: '100%', parent: [location.hostname], autoplay: false,
						muted: this.root.dataset.muted === '1', ...(current.live ? { channel: id } : { video: id, time: `${Math.floor(this.position())}s` }),
					});
					this.player.addEventListener(window.Twitch.Player.READY, ready);
					this.player.addEventListener(window.Twitch.Player.PLAYING, () => { if (valid()) this.playing(); });
					this.player.addEventListener(window.Twitch.Player.PLAYBACK_BLOCKED, () => { if (valid()) { this.stopTransition(); this.status('Cliquez sur « Rejoindre le direct TV » ou sur Lecture dans Twitch pour démarrer.'); } });
					this.player.addEventListener(window.Twitch.Player.OFFLINE, () => { if (valid()) this.ended('Ce direct est terminé ou indisponible. BSC TV vérifie son état.'); });
					this.player.addEventListener(window.Twitch.Player.ENDED, () => { if (valid()) this.ended('Vidéo terminée. Actualisation du programme…'); });
				}
				this.readyTimer = window.setTimeout(() => {
					if (valid() && !this.ready) this.fail('Le lecteur officiel ne répond pas. Réessayez ou utilisez le lien vers la plateforme.');
				}, 20000);
			} catch (error) {
				if (generation === this.generation) this.fail(error.message);
			} finally {
				if (generation === this.generation) this.starting = false;
			}
		}

		position() {
			const current = this.data?.current;
			return current && !current.live ? Math.max(0, Math.min(current.end - current.start - 1, this.now() - current.start)) : 0;
		}

		play() {
			if (!this.ready || !this.player) return;
			try {
				if (this.data.current.resource.platform === 'youtube') this.player.playVideo();
				else this.player.play();
			} catch { this.status('Utilisez les commandes du lecteur officiel pour démarrer.'); }
		}

		seek(force = false) {
			if (!this.ready || !this.player || !this.data?.current || this.data.current.live || this.failedToken === this.token) return;
			const youtube = this.data.current.resource.platform === 'youtube';
			try {
				// Limit positioning requests. The provider retains control of advertisements,
				// buffering, seek availability and playback delays.
				const playing = youtube ? this.player.getPlayerState() === window.YT.PlayerState.PLAYING : !this.player.isPaused();
				if (!force && (!playing || performance.now() - this.lastSeek < 60000)) return;
				const actual = this.player.getCurrentTime();
				const target = this.position();
				if (force || Math.abs(actual - target) > this.data.tolerance) {
					if (youtube) this.player.seekTo(target, true); else this.player.seek(target);
					this.lastSeek = performance.now();
				}
			} catch { /* A platform may temporarily refuse positioning. */ }
		}

		tick() {
			if (!this.root.isConnected) { this.dispose(); return; }
			const current = this.data?.current;
			const progress = this.root.querySelector('[data-player-progress]');
			if (progress) {
				progress.hidden = !current || current.live;
				if (current && !current.live) progress.value = Math.min(100, Math.max(0, 100 * (this.now() - current.start) / (current.end - current.start)));
			}
			if (document.hidden || !current) return;
			if (current.end && this.now() >= current.end) {
				const boundary = `${current.key}/${current.start}/${current.end}`;
				if (boundary !== this.boundaryToken) {
					this.boundaryToken = boundary;
					this.destroy();
					this.startTransition();
					this.status('Synchronisation avec le programme suivant…');
					this.request();
				}
			} else if (Math.floor(performance.now() / 1000) % 10 === 0) this.seek();
		}

		fail(message) { window.clearTimeout(this.readyTimer); this.stopTransition(); this.failedToken = this.token; this.status(message); }
		destroy() {
			this.stopTransition();
			window.clearTimeout(this.readyTimer);
			this.generation += 1;
			try {
				if (this.player?.destroy) this.player.destroy();
				else this.player?.pause();
			} catch { /* The official iframe may already have been removed. */ }
			this.mount.replaceChildren();
			this.player = null;
			this.ready = false;
			this.starting = false;
		}
		dispose() {
			window.clearTimeout(this.timer);
			window.clearInterval(this.pulse);
			document.removeEventListener('visibilitychange', this.visibility);
			this.observer.disconnect();
			this.destroy();
		}
	}

	function init(scope = document) {
		for (const root of scope.querySelectorAll('[data-bsc-player]')) {
			if (!instances.has(root)) instances.set(root, new Television(root));
		}
	}
	let elementorBound = false;
	function bindElementor() {
		if (elementorBound || !window.elementorFrontend?.hooks) return;
		window.elementorFrontend.hooks.addAction('frontend/element_ready/bsc-tv-player.default', scope => init(scope[0] || document));
		elementorBound = true;
	}
	function start() {
		init();
		bindElementor();
		window.jQuery?.(window).on('elementor/frontend/init', bindElementor);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
	else start();
})();

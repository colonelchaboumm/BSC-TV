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
			this.transition = root.querySelector('[data-player-transition]');
			this.transitionVideo = root.querySelector('[data-player-transition-video]');
			this.musicScreen = root.querySelector('[data-music-screen]');
			this.musicVideo = root.querySelector('[data-music-video]');
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
			this.lastSeek = -Infinity;
			this.volume = 100;
			this.muted = root.dataset.muted === '1';
			this.transitionAttempt = 0;
			root.querySelector('[data-player-pause]')?.addEventListener('click', () => {
				if (!this.data?.enabled || !this.data.current) return;
				if (!this.ready || !this.player || this.failedToken === this.token) return this.join();
				if (this.data.current.resource.platform !== 'youtube') return;
				if (this.player.getPlayerState() === window.YT.PlayerState.PLAYING) this.player.pauseVideo();
				else { this.seek(true); this.play(); }
				this.controls();
			});
			root.querySelector('[data-player-mute]')?.addEventListener('click', () => { this.audioCustomized = true; this.muted = !this.muted; this.audio(); });
			root.querySelector('[data-player-volume]')?.addEventListener('input', event => {
				this.audioCustomized = true;
				this.volume = Math.max(0, Math.min(100, Number(event.target.value) || 0));
				this.muted = this.volume === 0;
				this.audio();
			});
			root.querySelector('[data-player-fullscreen]')?.addEventListener('click', async () => {
				try {
					if (document.fullscreenElement === root) await document.exitFullscreen();
					else await root.requestFullscreen();
				} catch { this.status('Le plein écran est indisponible dans ce navigateur.'); }
			});
			this.fullscreen = () => this.controls();
			document.addEventListener('fullscreenchange', this.fullscreen);
			root.querySelector('[data-player-avatar]')?.addEventListener('error', event => { event.target.hidden = true; });
			root.querySelector('[data-player-poster]')?.addEventListener('error', event => { event.target.hidden = true; });
			root.querySelector('[data-music-logo]')?.addEventListener('error', event => { event.target.hidden = true; });
			this.transitionVideo?.addEventListener('error', () => { this.transitionBroken = true; this.stopTransition(); });
			this.musicVideo?.addEventListener('loadedmetadata', () => this.musicVideoPosition());
			this.musicVideo?.addEventListener('error', () => { this.musicVideo.hidden = true; });
			this.visibility = () => {
				if (document.hidden) { this.pauseTransition(); this.musicVideo?.pause(); }
				else { this.claimAutoplay(); if (this.waitingForPlayback && this.transition?.hidden) this.showTransition(); if (this.data?.current?.resource?.platform === 'identity') this.showMusic(this.data.current); this.request(); }
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
			if (this.data?.current?.music) return;
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
		musicVideoPosition() {
			const current = this.data?.current;
			if (!this.musicVideo || current?.resource?.platform !== 'identity' || this.musicScreen?.hidden || current.end <= this.now()) return;
			try {
				const duration = this.musicVideo.duration;
				this.musicVideo.currentTime = Math.max(0, Math.min(this.position(), Number.isFinite(duration) ? Math.max(0, duration - .1) : this.position()));
			} catch { /* An unloaded local animation may not yet accept a position. */ }
		}
		showMusic(current) {
			if (!this.musicScreen || current.end <= this.now()) return;
			this.stopTransition();
			this.mount.hidden = true;
			this.musicScreen.hidden = false;
			const identity = current.identity || {};
			this.musicScreen.style.setProperty('--bsc-music-default-background', /^#[0-9a-f]{6}$/i.test(identity.background) ? identity.background : '#111116');
			this.musicScreen.style.setProperty('--bsc-music-default-color', /^#[0-9a-f]{6}$/i.test(identity.color) ? identity.color : '#ffffff');
			this.musicScreen.dataset.animation = ['fade', 'slide', 'none'].includes(identity.animation) ? identity.animation : 'fade';
			const content = this.root.querySelector('[data-music-identity]');
			content.style.animationDelay = `-${Math.max(0, this.position())}s`;
			const logo = this.root.querySelector('[data-music-logo]');
			const logoURL = safeURL(identity.logo);
			logo.hidden = !logoURL;
			if (logoURL) logo.src = logoURL; else logo.removeAttribute('src');
			const text = this.root.querySelector('[data-music-text]');
			text.hidden = !identity.show_text;
			text.textContent = current.music_phase === 'entry' ? identity.entry_text || '' : identity.exit_text || '';
			const videoURL = safeURL(current.music_phase === 'entry' ? identity.entry_video : identity.exit_video);
			const reduced = !!window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
			this.musicVideo.hidden = !videoURL || reduced;
			if (!videoURL || reduced || document.hidden || !this.joined) { this.musicVideo.pause(); return; }
			this.musicVideo.muted = !identity.sound || this.muted;
			this.musicVideo.loop = !!current.standby;
			if (this.musicVideo.getAttribute('src') !== videoURL) this.musicVideo.src = videoURL;
			this.musicVideoPosition();
			const generation = this.generation;
			try { this.musicVideo.play()?.catch(() => { if (generation === this.generation) this.musicVideo.hidden = true; }); } catch { this.musicVideo.hidden = true; }
		}
		stopMusic() {
			if (this.musicScreen) this.musicScreen.hidden = true;
			if (this.musicVideo) { this.musicVideo.pause(); this.musicVideo.loop = false; this.musicVideo.removeAttribute('src'); this.musicVideo.load?.(); }
			this.mount.hidden = false;
		}
		playing() { this.rejoinAfterSeek = false; this.seek(false, true); this.controls(); this.stopTransition(); this.status(''); }
		ended(message) {
			// Seeking to the end of a replay must not skip the shared programme.
			try {
				if (!this.data?.current?.live && this.now() < this.data.current.end && this.player.getCurrentTime() > this.position() + 3) {
					this.rejoinAfterSeek = true;
					this.rejoin();
					return;
				}
			} catch { /* An unavailable player follows the normal end fallback. */ }
			this.startTransition(); this.status(message); this.request();
		}

		audio() {
			if (!this.ready || this.data?.current?.resource.platform !== 'youtube') return;
			try {
				if (!this.muted && this.volume === 0) this.volume = 100;
				this.player.setVolume?.(this.volume);
				if (this.muted) this.player.mute(); else this.player.unMute();
			} catch { /* Volume remains subject to the browser and official player. */ }
			this.controls();
		}

		control(selector, label, state) {
			const button = this.root.querySelector(selector);
			if (!button) return;
			button.setAttribute('aria-label', label);
			button.setAttribute('title', label);
			button.dataset.state = state;
		}

		controls() {
			const current = this.data?.enabled ? this.data.current : null;
			const platform = current?.resource?.platform;
			const available = this.ready && !!this.player && this.failedToken !== this.token && ['youtube', 'twitch'].includes(platform);
			const canStart = !!current && ['youtube', 'twitch', 'identity'].includes(platform);
			const showStart = canStart && !(platform === 'identity' && this.joined);
			const toolbar = this.root.querySelector('[data-player-toolbar]');
			if (toolbar) toolbar.hidden = !this.root.isConnected || !(available || showStart || safeURL(current?.url));
			const fullscreen = this.root.querySelector('[data-player-fullscreen]');
			if (fullscreen) {
				fullscreen.hidden = !available || !document.fullscreenEnabled || !this.root.requestFullscreen;
				const expanded = document.fullscreenElement === this.root;
				this.control('[data-player-fullscreen]', expanded ? 'Quitter le plein écran' : 'Plein écran', expanded ? 'collapse' : 'expand');
			}
			const controls = this.root.querySelector('[data-player-controls]');
			const mute = this.root.querySelector('[data-player-mute]');
			if (mute) mute.hidden = !available || platform !== 'youtube';
			const volumeControl = this.root.querySelector('[data-player-volume-control]');
			if (volumeControl) volumeControl.hidden = !available || platform !== 'youtube';
			if (!controls) return;
			controls.hidden = !this.root.isConnected || !showStart || (platform === 'twitch' && available);
			if (controls.hidden) return;
			const pause = this.root.querySelector('[data-player-pause]');
			if (pause) pause.disabled = !available && this.failedToken !== this.token && (this.starting || !!this.player);
			if (!available) { this.control('[data-player-pause]', 'Lire le direct', 'play'); return; }
			try {
				const playing = this.player.getPlayerState() === window.YT.PlayerState.PLAYING;
				this.control('[data-player-pause]', playing ? 'Pause' : 'Reprendre le direct', playing ? 'pause' : 'play');
				this.control('[data-player-mute]', this.muted ? 'Activer le son' : 'Couper le son', this.muted ? 'muted' : 'sound');
				mute.setAttribute('aria-pressed', String(this.muted));
				this.root.querySelector('[data-player-volume]').value = this.volume;
			} catch { /* SDK state may be unavailable during replacement. */ }
		}

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
				const remaining = boundary - this.now();
				const minimum = (this.data?.current?.music || this.data?.current?.standby) && remaining > 0 ? .5 : 5;
				const polling = Math.max(1, Math.min(60, Number(this.data?.poll_after) || 60));
				const wait = boundary ? Math.max(minimum, Math.min(polling, remaining + .1)) : polling;
				this.timer = window.setTimeout(() => this.request(), wait * 1000);
			}
		}

		render() {
			const current = this.data.enabled ? this.data.current : null;
			this.root.querySelector('[data-player-meta]').hidden = !current;
			for (const badge of this.root.querySelectorAll('[data-player-badge]')) {
				const name = badge.dataset.playerBadge;
				badge.hidden = !current || !(name === 'on_air' || (name === 'replay' ? !current.live && !current.premiere && !current.music && !current.standby : current[name]));
			}
			const source = this.root.querySelector('[data-player-source]');
			const sourceURL = current ? safeURL(current.url) : '';
			source.hidden = !sourceURL;
			if (sourceURL) source.href = sourceURL; else source.removeAttribute('href');
			const provider = current?.resource?.platform;
			const providerName = provider === 'youtube' ? 'YouTube' : provider === 'twitch' ? 'Twitch' : '';
			source.setAttribute('aria-label', `Regarder sur la plateforme et soutenir le créateur${providerName ? ` (${providerName}, nouvel onglet)` : ''}`);
			for (const icon of this.root.querySelectorAll('[data-player-provider]')) {
				if (sourceURL && icon.dataset.playerProvider === provider) icon.removeAttribute('hidden'); else icon.setAttribute('hidden', '');
			}
			const musicMeta = !current?.music || this.root.dataset.musicMeta !== '0';
			this.text('[data-player-title]', musicMeta ? current?.title : '');
			this.text('[data-player-creator]', musicMeta ? current?.creator : '');
			this.text('[data-player-times]', current?.standby ? `En attente du direct prévu à ${time(current.expected_start, this.data.timezone)}` : current ? `${current.live ? 'En direct depuis' : 'À l’antenne'} ${time(current.start, this.data.timezone)}${current.end ? ` – ${time(current.end, this.data.timezone)}` : ''}` : '');
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
				this.status('');
			}
			this.controls();
			this.tick();
			if (current.end && this.now() >= current.end) return;
			if ((current.music || current.standby) && current.resource?.platform === 'identity') { this.showMusic(current); this.status(''); return; }
			if (this.joined && !this.player && this.failedToken !== token && !this.starting) this.load(current, token);
		}

		async join() {
			automaticPlayerClaimed = true;
			this.joined = true;
			this.controls();
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
			this.controls();
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
					if (!this.audioCustomized) this.muted = this.root.dataset.muted === '1';
					this.audio();
					this.controls();
					this.play();
				};
				if (platform === 'youtube') {
					this.player = new window.YT.Player(mount.id, {
						width: '100%', height: '100%', videoId: id,
						playerVars: { autoplay: 0, controls: 0, disablekb: 1, playsinline: 1, fs: 1, origin: location.origin, start: Math.floor(this.position()) },
						events: {
							onReady: ready,
							onStateChange: event => {
								if (!valid()) return;
								if (event.data === window.YT.PlayerState.PLAYING) this.playing();
								if (event.data === window.YT.PlayerState.ENDED) this.ended('Cette vidéo est terminée. Actualisation du programme…');
								this.controls();
							},
							onPlaybackRateChange: event => {
								if (valid() && !current.live && event.data !== 1) {
									try { this.player.setPlaybackRate(1); this.seek(false, true); } catch { /* The provider may refuse a rate change. */ }
								}
							},
							onAutoplayBlocked: () => { if (valid()) { this.stopTransition(); this.status(''); this.controls(); } },
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
					this.player.addEventListener(window.Twitch.Player.SEEK, () => {
						if (valid() && !current.live && !this.seeking) { this.pendingSeek = true; this.seek(false, true); }
					});
					this.player.addEventListener(window.Twitch.Player.PLAYBACK_BLOCKED, () => { if (valid()) { this.stopTransition(); this.status(''); this.controls(); } });
					this.player.addEventListener(window.Twitch.Player.OFFLINE, () => { if (valid()) this.ended('Ce direct est terminé ou indisponible. BSC TV vérifie son état.'); });
					this.player.addEventListener(window.Twitch.Player.ENDED, () => { if (valid()) this.ended('Vidéo terminée. Actualisation du programme…'); });
				}
				this.readyTimer = window.setTimeout(() => {
					if (valid() && !this.ready) this.fail('Le lecteur officiel ne répond pas. Réessayez ou utilisez le lien vers la plateforme.');
				}, 20000);
			} catch (error) {
				if (generation === this.generation) this.fail(error.message);
			} finally {
				if (generation === this.generation) { this.starting = false; this.controls(); }
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

		seek(force = false, interaction = false) {
			if (!this.ready || !this.player || !this.data?.current || this.data.current.live || this.failedToken === this.token || this.seeking || this.now() >= this.data.current.end) return false;
			const youtube = this.data.current.resource.platform === 'youtube';
			try {
				// Official SDK calls only; never cover or intercept the provider's controls.
				// Set the cooldown before seeking: Twitch also emits SEEK for our own calls.
				const playing = youtube ? this.player.getPlayerState() === window.YT.PlayerState.PLAYING : !this.player.isPaused();
				if (!force && ((!interaction && !playing) || performance.now() - this.lastSeek < 2000)) return false;
				const actual = this.player.getCurrentTime();
				const target = this.position();
				if (!force && !Number.isFinite(actual)) return false;
				if (force || Math.abs(actual - target) > 3) {
					this.lastSeek = performance.now();
					this.seeking = true;
					if (youtube) this.player.seekTo(target, true); else this.player.seek(target);
					this.pendingSeek = false;
					return true;
				}
				this.pendingSeek = false;
			} catch { /* A platform may temporarily refuse positioning. */ }
			finally { this.seeking = false; }
			return false;
		}
		rejoin() { if (this.rejoinAfterSeek && this.seek(false, true)) { this.rejoinAfterSeek = false; this.play(); } }

		tick() {
			if (!this.root.isConnected) { this.dispose(); return; }
			const current = this.data?.current;
			const progress = this.root.querySelector('[data-player-progress]');
			if (progress) {
				progress.hidden = !current || current.live || current.standby;
				if (current && !current.live && !current.standby) progress.value = Math.min(100, Math.max(0, 100 * (this.now() - current.start) / (current.end - current.start)));
			}
			if (document.hidden || !current) return;
			this.controls();
			if (current.end && this.now() >= current.end) {
				const boundary = `${current.key}/${current.start}/${current.end}`;
				if (boundary !== this.boundaryToken) {
					this.boundaryToken = boundary;
					if (current.standby) { this.request(); return; }
					this.destroy();
					this.startTransition();
					this.status('Synchronisation avec le programme suivant…');
					this.request();
				}
			} else if (this.rejoinAfterSeek) this.rejoin();
			else this.seek(false, this.pendingSeek);
		}

		fail(message) { window.clearTimeout(this.readyTimer); this.stopTransition(); this.failedToken = this.token; this.controls(); this.status(message); }
		destroy() {
			this.stopTransition();
			this.stopMusic();
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
			this.lastSeek = -Infinity;
			this.rejoinAfterSeek = false;
			this.pendingSeek = false;
			this.controls();
		}
		dispose() {
			window.clearTimeout(this.timer);
			window.clearInterval(this.pulse);
			document.removeEventListener('visibilitychange', this.visibility);
			document.removeEventListener('fullscreenchange', this.fullscreen);
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

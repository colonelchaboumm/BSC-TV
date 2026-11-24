// SDK contract tests: run the shipped browser code with public SDK/DOM doubles.
// These verify behavior, not a promise of actual ad or autoplay behavior on a platform.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Element {
  constructor() { this.dataset = {}; this.hidden = false; this.listeners = new Map(); this.attributes = new Map(); this.textContent = ''; this.isConnected = true; this.clientWidth = 960; this.children = []; }
  addEventListener(name, callback) { this.listeners.set(name, callback); }
  removeEventListener(name) { this.listeners.delete(name); }
  setAttribute(name, value) { this.attributes.set(name, value); }
  getAttribute(name) { return this.attributes.get(name) ?? null; }
  removeAttribute(name) { this.attributes.delete(name); }
  set src(value) { this.attributes.set('src', value); }
  get src() { return this.attributes.get('src'); }
  replaceChildren(...children) { this.children = children; }
  append(...children) { this.children.push(...children); }
  remove() { this.isConnected = false; }
  play() { this.playCalls = (this.playCalls || 0) + 1; this.paused = false; return rejectClip ? Promise.reject(new Error('Clip blocked')) : Promise.resolve(); }
  pause() { this.paused = true; }
  async fire(name) { return this.listeners.get(name)?.({ target: this }); }
  querySelector(selector) { return selector === 'iframe' ? null : this.nodes?.get(selector) || null; }
  querySelectorAll(selector) { return selector === '[data-player-badge]' ? this.badges : []; }
}
const root = new Element();
root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: '0', muted: '1' };
const selectors = ['[data-player-mount]', '.bsc-player__screen', '[data-player-transition]', '[data-player-transition-video]', '[data-player-message]', '[data-player-pause]', '[data-player-controls]', '[data-player-mute]', '[data-player-volume]', '[data-player-volume-control]', '[data-player-toolbar]', '[data-player-meta]', '[data-player-source]', '[data-player-title]', '[data-player-creator]', '[data-player-times]', '[data-player-avatar]', '[data-player-poster]', '[data-player-profile]', '[data-player-next]', '[data-player-next-title]', '[data-player-next-time]', '[data-player-progress]'];
root.nodes = new Map(selectors.map(selector => [selector, new Element()]));
root.nodes.set('[data-player-publication]', new Element());
const transition = root.nodes.get('[data-player-transition]');
const clip = root.nodes.get('[data-player-transition-video]');
transition.hidden = true; transition.dataset.timeout = '20';
let loadingAnimations = ['https://bsc.example/bsc-tv/assets/media/ident-circus.mp4', 'https://bsc.example/bsc-tv/assets/media/ident-moon.mp4'];
clip.dataset.src = loadingAnimations[0]; clip.dataset.sources = JSON.stringify(loadingAnimations); clip.dataset.fixed = '0';
root.badges = ['on_air', 'live', 'new', 'premiere', 'replay'].map(name => { const badge = new Element(); badge.dataset.playerBadge = name; return badge; });
const logo = new Element();
logo.src = 'https://bsc.example/bsc-fixed-logo.png';
const document = new Element();
document.documentElement = { lang: 'fr' };
document.readyState = 'complete';
document.hidden = false;
document.baseURI = 'https://bsc.example/live/';
document.head = new Element();
document.querySelectorAll = selector => selector === '[data-bsc-player]' ? [root] : [];
document.createElement = () => new Element();
let clockMs = 0;
const epoch = 1805806800;
const intervals = [];
const timeouts = new Map();
let timeoutID = 0;
let blocked = false;
let holdYouTube = true;
let holdTwitch = false;
let reducedMotion = false;
let rejectClip = false;
const youtubePlayers = [];
const twitchPlayers = [];
const snapshot = current => ({ enabled: true, server_time: epoch + clockMs / 1000, timezone: 'Europe/Paris', tolerance: 8, next: [], current, loading_animations: [...loadingAnimations] });
const publicationEpoch = Date.parse('2026-11-20T23:30:00Z') / 1000;
const programme = (key, platform, id, changes = {}) => ({ key, resource: { platform, id }, title: 'Émission A', creator: 'Créateur A', avatar: 'https://bsc.example/avatar-a.png', image: 'https://bsc.example/show-a.jpg', profile_url: 'https://bsc.example/createur-a/', url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', live: false, new: true, premiere: false, published_at: publicationEpoch, start: epoch - 900, end: epoch + 2700, ...changes });
let response = snapshot(programme('youtube:first', 'youtube', 'M7lc1UVf-VE'));
let fetchCount = 0;
class YouTubePlayer {
  constructor(id, options) { this.options = options; this.position = 0; this.seeks = []; this.destroyed = false; this.state = 2; youtubePlayers.push(this); queueMicrotask(() => options.events.onReady()); }
  seekTo(value) { this.position = value; this.seeks.push(value); }
  playVideo() { if (blocked) this.options.events.onAutoplayBlocked(); else if (!holdYouTube) { this.state = 1; this.options.events.onStateChange({ data: 1 }); } }
  pauseVideo() { this.state = 2; this.options.events.onStateChange({ data: 2 }); }
  getPlayerState() { return this.state; }
  getCurrentTime() { return this.position; }
  mute() { this.muted = true; }
  unMute() { this.muted = false; this.unmuted = true; }
  destroy() { this.destroyed = true; }
}
class TwitchPlayer {
  static READY = 'ready'; static PLAYING = 'playing'; static PLAYBACK_BLOCKED = 'blocked'; static OFFLINE = 'offline'; static ENDED = 'ended';
  constructor(id, options) { this.options = options; this.events = new Map(); this.position = 0; this.seeks = []; twitchPlayers.push(this); }
  addEventListener(name, callback) { this.events.set(name, callback); if (name === 'ready') queueMicrotask(callback); }
  play() { if (!holdTwitch) this.events.get('playing')?.(); }
  pause() { this.paused = true; }
  isPaused() { return !!this.paused; }
  seek(value) { this.seeks.push(value); this.position = value; }
  getCurrentTime() { return this.position; }
}
const context = {
  document, location: { origin: 'https://bsc.example', hostname: 'bsc.example' }, performance: { now: () => clockMs },
  URL, Intl, Date, Error, AbortController, console, queueMicrotask,
  IntersectionObserver: class { observe() {} disconnect() {} },
  fetch: async (url, options) => { fetchCount++; assert.equal(url.origin, 'https://bsc.example'); assert.equal(options.cache, 'no-store'); return { ok: true, json: async () => structuredClone(response) }; },
};
context.window = { YT: { Player: YouTubePlayer, PlayerState: { PLAYING: 1, ENDED: 0 } }, Twitch: { Player: TwitchPlayer }, matchMedia: () => ({ matches: reducedMotion }), setInterval: callback => { intervals.push(callback); return intervals.length; }, clearInterval() {}, setTimeout: (callback, delay) => { timeouts.set(++timeoutID, { callback, delay }); return timeoutID; }, clearTimeout: id => timeouts.delete(id) };
vm.runInNewContext(await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8'), context);
const settle = async () => { for (let n = 0; n < 4; n++) await new Promise(resolve => setImmediate(resolve)); };
const check = (value, label) => { assert.ok(value, label); checks++; };
let checks = 0;
await settle();
check(youtubePlayers.length === 0 && document.head.children.length === 0, 'No platform SDK or iframe before joining');
check(transition.hidden && !clip.src, 'The transition neither plays nor downloads before joining');
check(root.nodes.get('[data-player-title]').textContent === 'Émission A', 'Metadata is populated without video playback');
const publication = root.nodes.get('[data-player-publication]');
check(!publication.hidden && publication.textContent === 'Parue le 21/11/2026', 'Replay publication uses the original date in the site timezone, including a date crossing midnight');
check(publication.getAttribute('datetime') === '2026-11-20T23:30:00.000Z', 'Publication has a machine-readable date separate from the scheduled replay time');
const poster = root.nodes.get('[data-player-poster]');
check(!poster.hidden && poster.src === 'https://bsc.example/show-a.jpg', 'The programme image appears before joining without loading the video SDK');
await root.nodes.get('[data-player-pause]').fire('click');
await settle();
const first = youtubePlayers[0];
check(first.seeks[0] === 900 && first.muted, 'Late join seeks to server time and respects muted setting');
check(!transition.hidden && clip.muted && clip.defaultMuted && clip.playCalls === 1, 'The bundled clip starts silently and stays visible after READY while the programme is not yet playing');
const firstIntro = clip.src;
check(loadingAnimations.includes(firstIntro) && !firstIntro.endsWith('/transition.mp4'), 'Loading chooses Lune or Manège instead of the old logo film');
await document.fire('visibilitychange'); await settle();
check(clip.src === firstIntro && clip.playCalls === 1, 'Polling does not redraw or restart an ongoing loading animation');
holdYouTube = false; first.playVideo();
check(transition.hidden && clip.paused, 'YouTube PLAYING immediately hides and pauses the transition');
first.options.events.onStateChange({ data: 3 });
check(transition.hidden, 'Buffering an already started programme does not cover its picture or advertisements');
check(first.options.playerVars.controls === 0 && first.options.playerVars.disablekb === 1 && first.options.playerVars.origin === 'https://bsc.example', 'Documented YouTube parameters remove the timeline and seeking shortcuts while retaining host identity');
blocked = true; first.state = 2;
await root.nodes.get('[data-player-pause]').fire('click');
check(root.nodes.get('[data-player-message]').textContent === '' && root.nodes.get('[data-player-pause]').dataset.state === 'play', 'Autoplay blocking keeps the play icon available without the removed banner');
blocked = false;
await root.nodes.get('[data-player-pause]').fire('click');
check(root.nodes.get('[data-player-message]').textContent === '', 'User gesture can resume the official player');
clockMs = 70000;
first.position = 10;
intervals[0]();
check(first.seeks.at(-1) === 970, 'Clock drift beyond tolerance is corrected with official seek');
first.options.events.onError({ data: 150 });
check(root.nodes.get('[data-player-message]').textContent.includes('150'), 'Unavailable video is reported with a source link');
const playersBeforeRetry = youtubePlayers.length;
await root.nodes.get('[data-player-pause]').fire('click');
await settle();
check(youtubePlayers.length === playersBeforeRetry + 1 && first.destroyed, 'Retry recreates a failed official player');
clockMs = 2800000;
root.dataset.muted = '0';
response = snapshot(programme('youtube:second', 'youtube', 'aqz-KE-bpKQ', { title: 'Émission B', creator: 'Créateur B', published_at: 0, avatar: 'https://bsc.example/avatar-b.png', image: 'https://bsc.example/show-b.jpg', start: epoch + 2700, end: epoch + 6300 }));
await document.fire('visibilitychange'); await settle();
check(youtubePlayers.at(-1).seeks[0] === 100, 'Next scheduled programme starts at the shared offset');
check(publication.hidden && publication.textContent === '' && !publication.getAttribute('datetime'), 'A programme with no publication date clears the previous date instead of showing an import date or 1970');
check(root.nodes.get('[data-player-avatar]').src.endsWith('avatar-b.png') && root.nodes.get('[data-player-title]').textContent === 'Émission B', 'Avatar and title change with the programme');
check(youtubePlayers.at(-1).unmuted && !youtubePlayers.at(-1).muted, 'YouTube is explicitly unmuted when the default sound setting is active');
check(poster.src === 'https://bsc.example/show-b.jpg', 'The poster follows the next programme instead of retaining the previous image');
await poster.fire('error');
await document.fire('visibilitychange'); await settle();
check(poster.hidden, 'An unavailable image stays hidden on subsequent refreshes');
holdTwitch = true;
response = snapshot(programme('live:twitch:one', 'twitch', 'beerstream', { live: true, new: false, image: 'javascript:alert(1)', start: epoch + 2600, end: 0 }));
await document.fire('visibilitychange'); await settle();
check(twitchPlayers.length === 1 && twitchPlayers[0].seeks.length === 0, 'Real live embeds a channel and never seeks');
check(publication.hidden && publication.textContent === '', 'A true live hides the replay publication date even when its source has a publication timestamp');
check(!transition.hidden, 'Twitch READY alone does not end the transition');
first.options.events.onStateChange({ data: 1 });
check(!transition.hidden, 'A stale callback from the previous programme cannot hide the next transition');
twitchPlayers[0].events.get('playing')(); holdTwitch = false;
check(transition.hidden && clip.paused, 'Twitch PLAYING releases its picture and stops the local clip');
check(Array.from(twitchPlayers[0].options.parent).join() === 'bsc.example' && twitchPlayers[0].options.channel === 'beerstream', 'Twitch embed uses current parent and channel');
check(twitchPlayers[0].options.muted === false, 'The sound-on default is also passed explicitly to Twitch');
check(poster.hidden && !poster.src, 'Unsafe poster URLs are removed from the screen');
check(!root.badges.find(node => node.dataset.playerBadge === 'live').hidden, 'LIVE badge follows server confirmation');
check(root.nodes.get('[data-player-progress]').hidden, 'A true live does not show a fictitious VOD progress');
response = snapshot(programme('youtube:second', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 2700, end: epoch + 6300 }));
await document.fire('visibilitychange'); await settle();
check(twitchPlayers[0].paused && youtubePlayers.at(-1).seeks[0] === 100, 'Return from live restores server-provided replay position');
check(!poster.hidden && poster.src === 'https://bsc.example/show-a.jpg', 'A valid programme image returns after a source without a usable image');
root.nodes.get('.bsc-player__screen').clientWidth = 360;
response = snapshot(programme('live:twitch:small', 'twitch', 'beerstream', { live: true, start: epoch, end: 0 }));
await document.fire('visibilitychange'); await settle();
check(twitchPlayers.length === 1 && root.nodes.get('[data-player-message]').textContent.includes('400 px'), 'Small mobile layout respects Twitch minimum width');
check(logo.src === 'https://bsc.example/bsc-fixed-logo.png', 'Channel branding remains fixed across creators');
root.nodes.get('.bsc-player__screen').clientWidth = 960;
holdYouTube = true; reducedMotion = true;
const priorClipPlays = clip.playCalls;
response = snapshot(programme('youtube:reduced', 'youtube', 'M7lc1UVf-VE', { start: epoch + 2700, end: epoch + 6300 }));
await document.fire('visibilitychange'); await settle();
check(!transition.hidden && clip.hidden && clip.playCalls === priorClipPlays, 'Reduced-motion preference uses the still frame without starting the animation');
const reducedIntro = clip.dataset.src;
document.hidden = true; await document.fire('visibilitychange');
check(transition.hidden && clip.paused, 'A hidden tab pauses and hides the local animation');
document.hidden = false; await document.fire('visibilitychange'); await settle();
check(!transition.hidden && clip.playCalls === priorClipPlays, 'Returning to a waiting programme restores the static transition');
check(clip.dataset.src === reducedIntro, 'Returning from a hidden tab retains the chosen source');
clockMs = 6300000; reducedMotion = false;
const endedPlayer = youtubePlayers.at(-1);
response = snapshot(programme('youtube:boundary', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
intervals[0]();
check(endedPlayer.destroyed && !transition.hidden, 'The clock boundary stops the old programme and displays the transition immediately');
await settle();
check(!transition.hidden && youtubePlayers.at(-1).seeks[0] === 0, 'The next programme loads at the shared offset while its picture is still starting');
holdYouTube = false; youtubePlayers.at(-1).playVideo();
const gapNext = programme('youtube:after-gap', 'youtube', 'M7lc1UVf-VE', { start: epoch + 6400, end: epoch + 10000 });
response = { ...snapshot(null), next: [gapNext] };
await document.fire('visibilitychange'); await settle();
check(!transition.hidden, 'A gap before the next scheduled programme can display the transition');
const gapPlayCount = clip.playCalls;
await document.fire('visibilitychange'); await settle();
check(clip.playCalls === gapPlayCount, 'Repeated snapshots of the same gap do not restart the animation');
const watchdog = [...timeouts.values()].find(timer => timer.delay === 20000);
clockMs += 21000; watchdog.callback();
check(transition.hidden && clip.paused, 'The watchdog restores the player area instead of looping indefinitely');
await document.fire('visibilitychange'); await settle();
check(transition.hidden && clip.playCalls === gapPlayCount, 'A timed-out gap does not restart the animation on every refresh');
blocked = true;
response = snapshot(programme('youtube:blocked', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(transition.hidden && root.nodes.get('[data-player-message]').textContent === '' && !root.nodes.get('[data-player-controls]').hidden, 'Blocked autoplay removes the transition and banner while keeping the play icon visible');
blocked = false; holdYouTube = true; rejectClip = true;
response = snapshot(programme('youtube:clip-blocked', 'youtube', 'M7lc1UVf-VE', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(transition.hidden && youtubePlayers.at(-1).options.videoId === 'M7lc1UVf-VE', 'A rejected local clip never prevents the programme player from loading');
rejectClip = false;
response = snapshot(programme('youtube:failure', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(!transition.hidden, 'A new programme can show the animation after a prior local play rejection');
youtubePlayers.at(-1).options.events.onError({ data: 150 });
check(transition.hidden, 'A platform error removes the transition and exposes its source link');
response = snapshot(programme('youtube:media-error', 'youtube', 'M7lc1UVf-VE', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
await clip.fire('error');
check(transition.hidden && clip.paused, 'A broken animation source is hidden independently of the programme');
const brokenIntro = clip.src;
loadingAnimations.push('https://bsc.example/new-bsc-film.mp4');
response = snapshot(programme('youtube:new-library', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(!transition.hidden && clip.src !== brokenIntro, 'A broken film does not disable the other loading animations');
const newFilm = 'https://bsc.example/newest-bsc-film.mp4';
loadingAnimations = [newFilm, 'javascript:alert(1)', 'data:video/mp4;base64,bad'];
response = snapshot(programme('youtube:new-film', 'youtube', 'M7lc1UVf-VE', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(clip.src === newFilm, 'A newly added film is read from the next local snapshot without reloading the page; unsafe URLs are ignored');
loadingAnimations = [newFilm, newFilm, 'https://bsc.example/second-new-film.mp4'];
response = snapshot(programme('youtube:another-film', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(clip.src === loadingAnimations[2], 'Consecutive loading sequences avoid repeating the same film, even with duplicate references');
clip.dataset.fixed = '1'; clip.dataset.sources = JSON.stringify(['https://bsc.example/fixed.webm']);
response = snapshot(programme('youtube:fixed-film', 'youtube', 'M7lc1UVf-VE', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(clip.src === 'https://bsc.example/fixed.webm', 'An explicitly fixed Elementor clip remains fixed despite library updates');
clip.dataset.fixed = '0'; clip.dataset.sources = '{broken-json'; clip.dataset.src = 'https://bsc.example/legacy-safe.mp4';
loadingAnimations = [];
response = snapshot(programme('youtube:legacy-film', 'youtube', 'aqz-KE-bpKQ', { start: epoch + 6300, end: epoch + 9900 }));
await document.fire('visibilitychange'); await settle();
check(clip.src === 'https://bsc.example/legacy-safe.mp4', 'An older cached markup remains playable when optional library data is absent or malformed');
response = { ...snapshot(null), enabled: false };
await document.fire('visibilitychange'); await settle();
check(publication.hidden && publication.textContent === '' && !publication.getAttribute('datetime'), 'Disabling the antenna clears any publication metadata');
check(root.nodes.get('[data-player-meta]').hidden && root.nodes.get('[data-player-controls]').hidden, 'Disabling the antenna stops playback and hides old metadata and playback controls');
check(poster.hidden && !poster.src, 'Disabling the antenna also removes its old poster');
root.isConnected = false; intervals[0]();
check(fetchCount > 0 && timeouts.size === 0, 'Detached Elementor widget releases its refresh timer');
console.log(`OK ${checks} browser SDK contract checks (simulated YouTube/Twitch).`);

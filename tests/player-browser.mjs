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
  async fire(name) { return this.listeners.get(name)?.({ target: this }); }
  querySelector(selector) { return selector === 'iframe' ? null : this.nodes?.get(selector) || null; }
  querySelectorAll(selector) { return selector === '[data-player-badge]' ? this.badges : []; }
}
const root = new Element();
root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: '0', muted: '1' };
const selectors = ['[data-player-mount]', '.bsc-player__screen', '[data-player-message]', '[data-player-join]', '[data-player-meta]', '[data-player-source]', '[data-player-title]', '[data-player-creator]', '[data-player-times]', '[data-player-avatar]', '[data-player-poster]', '[data-player-profile]', '[data-player-next]', '[data-player-next-title]', '[data-player-next-time]', '[data-player-progress]'];
root.nodes = new Map(selectors.map(selector => [selector, new Element()]));
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
const youtubePlayers = [];
const twitchPlayers = [];
const snapshot = current => ({ enabled: true, server_time: epoch + clockMs / 1000, timezone: 'Europe/Paris', tolerance: 8, next: [], current });
const programme = (key, platform, id, changes = {}) => ({ key, resource: { platform, id }, title: 'Émission A', creator: 'Créateur A', avatar: 'https://bsc.example/avatar-a.png', image: 'https://bsc.example/show-a.jpg', profile_url: 'https://bsc.example/createur-a/', url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', live: false, new: true, premiere: false, start: epoch - 900, end: epoch + 2700, ...changes });
let response = snapshot(programme('youtube:first', 'youtube', 'M7lc1UVf-VE'));
let fetchCount = 0;
class YouTubePlayer {
  constructor(id, options) { this.options = options; this.position = 0; this.seeks = []; this.destroyed = false; this.state = 2; youtubePlayers.push(this); queueMicrotask(() => options.events.onReady()); }
  seekTo(value) { this.position = value; this.seeks.push(value); }
  playVideo() { if (blocked) this.options.events.onAutoplayBlocked(); else { this.state = 1; this.options.events.onStateChange({ data: 1 }); } }
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
  play() { this.events.get('playing')?.(); }
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
context.window = { YT: { Player: YouTubePlayer, PlayerState: { PLAYING: 1, ENDED: 0 } }, Twitch: { Player: TwitchPlayer }, setInterval: callback => { intervals.push(callback); return intervals.length; }, clearInterval() {}, setTimeout: callback => { timeouts.set(++timeoutID, callback); return timeoutID; }, clearTimeout: id => timeouts.delete(id) };
vm.runInNewContext(await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8'), context);
const settle = async () => { for (let n = 0; n < 4; n++) await new Promise(resolve => setImmediate(resolve)); };
const check = (value, label) => { assert.ok(value, label); checks++; };
let checks = 0;
await settle();
check(youtubePlayers.length === 0 && document.head.children.length === 0, 'No platform SDK or iframe before joining');
check(root.nodes.get('[data-player-title]').textContent === 'Émission A', 'Metadata is populated without video playback');
const poster = root.nodes.get('[data-player-poster]');
check(!poster.hidden && poster.src === 'https://bsc.example/show-a.jpg', 'The programme image appears before joining without loading the video SDK');
await root.nodes.get('[data-player-join]').fire('click');
await settle();
const first = youtubePlayers[0];
check(first.seeks[0] === 900 && first.muted, 'Late join seeks to server time and respects muted setting');
check(first.options.playerVars.controls === 1 && first.options.playerVars.origin === 'https://bsc.example', 'Official controls and host identity retained');
blocked = true;
await root.nodes.get('[data-player-join]').fire('click');
check(root.nodes.get('[data-player-message]').textContent.includes('navigateur'), 'Autoplay blocking gives actionable join message');
blocked = false;
await root.nodes.get('[data-player-join]').fire('click');
check(root.nodes.get('[data-player-message]').textContent === '', 'User gesture can resume the official player');
clockMs = 70000;
first.position = 10;
intervals[0]();
check(first.seeks.at(-1) === 970, 'Clock drift beyond tolerance is corrected with official seek');
first.options.events.onError({ data: 150 });
check(root.nodes.get('[data-player-message]').textContent.includes('150'), 'Unavailable video is reported with a source link');
const playersBeforeRetry = youtubePlayers.length;
await root.nodes.get('[data-player-join]').fire('click');
await settle();
check(youtubePlayers.length === playersBeforeRetry + 1 && first.destroyed, 'Retry recreates a failed official player');
clockMs = 2800000;
root.dataset.muted = '0';
response = snapshot(programme('youtube:second', 'youtube', 'aqz-KE-bpKQ', { title: 'Émission B', creator: 'Créateur B', avatar: 'https://bsc.example/avatar-b.png', image: 'https://bsc.example/show-b.jpg', start: epoch + 2700, end: epoch + 6300 }));
await document.fire('visibilitychange'); await settle();
check(youtubePlayers.at(-1).seeks[0] === 100, 'Next scheduled programme starts at the shared offset');
check(root.nodes.get('[data-player-avatar]').src.endsWith('avatar-b.png') && root.nodes.get('[data-player-title]').textContent === 'Émission B', 'Avatar and title change with the programme');
check(youtubePlayers.at(-1).unmuted && !youtubePlayers.at(-1).muted, 'YouTube is explicitly unmuted when the default sound setting is active');
check(poster.src === 'https://bsc.example/show-b.jpg', 'The poster follows the next programme instead of retaining the previous image');
await poster.fire('error');
await document.fire('visibilitychange'); await settle();
check(poster.hidden, 'An unavailable image stays hidden on subsequent refreshes');
response = snapshot(programme('live:twitch:one', 'twitch', 'beerstream', { live: true, new: false, image: 'javascript:alert(1)', start: epoch + 2600, end: 0 }));
await document.fire('visibilitychange'); await settle();
check(twitchPlayers.length === 1 && twitchPlayers[0].seeks.length === 0, 'Real live embeds a channel and never seeks');
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
response = { ...snapshot(null), enabled: false };
await document.fire('visibilitychange'); await settle();
check(root.nodes.get('[data-player-meta]').hidden && root.nodes.get('[data-player-join]').disabled, 'Disabling the antenna stops playback and hides old metadata');
check(poster.hidden && !poster.src, 'Disabling the antenna also removes its old poster');
root.isConnected = false; intervals[0]();
check(fetchCount > 0 && timeouts.size === 0, 'Detached Elementor widget releases its refresh timer');
console.log(`OK ${checks} browser SDK contract checks (simulated YouTube/Twitch).`);

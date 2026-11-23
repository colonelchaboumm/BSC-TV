// Public player SDK contracts for persisted music phases, without contacting YouTube.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
class Element {
  constructor() { this.nodes = new Map(); this.events = new Map(); this.attrs = new Map(); this.dataset = {}; this.style = { setProperty: (key, value) => { this.style[key] = value; } }; this.hidden = false; this.isConnected = true; this.clientWidth = 960; this.children = []; this.duration = 10; }
  querySelector(selector) { return this.nodes.get(selector) || null; }
  querySelectorAll(selector) { return selector === '[data-player-badge]' ? this.badges || [] : []; }
  addEventListener(name, fn) { this.events.set(name, fn); } removeEventListener(name) { this.events.delete(name); }
  fire(name) { return this.events.get(name)?.({ target: this }); }
  getAttribute(name) { return this.attrs.get(name); } setAttribute(name, value) { this.attrs.set(name, value); } removeAttribute(name) { this.attrs.delete(name); }
  set src(value) { this.attrs.set('src', value); } get src() { return this.attrs.get('src'); }
  replaceChildren(...children) { this.children = children; } append(...children) { this.children.push(...children); }
  pause() { this.paused = true; } load() { this.loaded = true; }
  play() { this.paused = false; this.plays = (this.plays || 0) + 1; return new Promise((resolve, reject) => { this.reject = reject; }); }
}
const root = new Element(); root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: '0', muted: '0', musicMeta: '1' };
for (const selector of ['[data-player-mount]', '.bsc-player__screen', '[data-player-message]', '[data-player-pause]', '[data-player-controls]', '[data-player-mute]', '[data-player-volume]', '[data-player-volume-control]', '[data-player-toolbar]', '[data-player-meta]', '[data-player-source]', '[data-player-title]', '[data-player-creator]', '[data-player-profile]', '[data-player-avatar]', '[data-player-progress]', '[data-music-screen]', '[data-music-video]', '[data-music-identity]', '[data-music-logo]', '[data-music-text]']) root.nodes.set(selector, new Element());
root.badges = ['on_air', 'music', 'live', 'replay'].map(name => { const badge = new Element(); badge.dataset.playerBadge = name; return badge; });
const node = selector => root.querySelector(selector);
const document = new Element(); Object.assign(document, { readyState: 'complete', hidden: false, documentElement: { lang: 'fr' }, baseURI: 'https://bsc.example/live/', head: new Element() });
document.querySelectorAll = selector => selector === '[data-bsc-player]' ? [root] : []; document.createElement = () => new Element();
const identity = { logo: '', entry_video: '', exit_video: '', background: '#111116', color: '#ffffff', animation: 'fade', sound: false, show_text: true, entry_text: 'BSC Music Break', exit_text: 'Back to BSC TV' };
let clock = 0; let reduced = false; let requests = 0; let nextTimer = 0;
const players = []; const timers = new Map(); const pulses = [];
const programme = changes => ({ key: 'music:1:aaaaaaaaaaa', resource: { platform: 'identity', id: 'aaaaaaaaaaa' }, title: 'Official clip', creator: 'Artist', avatar: '', profile_url: '', url: '', start: 999, end: 1002, live: false, music: true, music_phase: 'entry', identity: { ...identity }, ...changes });
let current = programme();
class YouTube {
  constructor(id, options) { this.options = options; this.state = 2; players.push(this); queueMicrotask(() => options.events.onReady()); }
  getPlayerState() { return this.state; } getCurrentTime() { return this.position; } seekTo(value) { this.position = value; }
  mute() {} unMute() {} setVolume() {}
  playVideo() { this.state = 1; this.options.events.onStateChange({ data: 1 }); }
  destroy() { this.destroyed = true; }
}
const window = { YT: { Player: YouTube, PlayerState: { PLAYING: 1, ENDED: 0 } }, matchMedia: () => ({ matches: reduced }), setTimeout: (fn, delay) => { timers.set(++nextTimer, { fn, delay }); return nextTimer; }, clearTimeout: id => timers.delete(id), setInterval: fn => { pulses.push(fn); return pulses.length; }, clearInterval() {} };
vm.runInNewContext(await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8'), { document, window, URL, Intl, Date, Error, AbortController, performance: { now: () => clock }, location: { origin: 'https://bsc.example', hostname: 'bsc.example' }, IntersectionObserver: class { observe() {} disconnect() {} }, fetch: async () => { requests++; return { ok: true, json: async () => ({ enabled: true, server_time: 1000 + clock / 1000, timezone: 'Europe/Paris', poll_after: current.live || current.standby ? 5 : 60, next: [], current: structuredClone(current) }) }; } });
const flush = async () => { for (let n = 0; n < 30; n++) await Promise.resolve(); };
const refresh = async () => { document.fire('visibilitychange'); await flush(); };
let checks = 0; const check = (condition, message) => { assert.ok(condition, message); checks++; };
await flush();
check(!node('[data-music-screen]').hidden && node('[data-player-mount]').hidden, 'Arriving during the persisted entry shows a distinct identity screen');
check(players.length === 0 && node('[data-player-mount]').children.length === 0, 'Identity never covers an existing YouTube iframe');
check(node('[data-music-logo]').hidden && node('[data-music-text]').textContent === 'BSC Music Break', 'No configured logo uses neutral identity and optional text, not an invented mark');
check(node('[data-music-identity]').style.animationDelay === '-1s', 'Late entry joins the CSS animation offset rather than restarting its clock');
check([...timers.values()].some(timer => timer.delay < 5000 && timer.delay >= 500), 'A three-second phase schedules its next read before the old five-second minimum');
await node('[data-player-pause]').fire('click'); await flush();
current.identity.entry_video = 'https://bsc.example/official-ident.mp4'; current.identity.sound = true; await refresh();
const video = node('[data-music-video]');
check(video.src.endsWith('official-ident.mp4') && !video.muted && video.currentTime === 1, 'An administrator-supplied ident uses the shared offset and requested audio after joining');
const rejectOldIdent = video.reject;
reduced = true; await refresh();
check(video.hidden && video.paused, 'Reduced motion pauses custom animation and retains a static identity');
reduced = false; await refresh(); document.hidden = true; document.fire('visibilitychange');
check(video.paused, 'Background tabs pause their local ident video');
document.hidden = false;
clock = 12000; current = programme({ resource: { platform: 'youtube', id: 'aaaaaaaaaaa' }, start: 1002, end: 1242, music_phase: 'clip', identity: null, url: 'https://www.youtube.com/watch?v=aaaaaaaaaaa' }); await refresh();
const clip = players.at(-1);
check(clip.position === 10, 'A visitor arriving ten seconds into the official clip seeks to the shared offset');
check(node('[data-music-screen]').hidden && !node('[data-player-mount]').hidden && video.paused && !video.src, 'The identity screen and media are removed before YouTube takes over');
check(!root.badges.find(badge => badge.dataset.playerBadge === 'music').hidden && root.badges.find(badge => badge.dataset.playerBadge === 'live').hidden && root.badges.find(badge => badge.dataset.playerBadge === 'replay').hidden, 'MUSIC BREAK is distinct from real live and replay badges');
check(node('[data-player-profile]').hidden && node('[data-player-avatar]').hidden, 'No invented BDD creator profile or avatar accompanies the clip');
root.dataset.musicMeta = '0'; await refresh();
check(node('[data-player-title]').textContent === '' && node('[data-player-creator]').textContent === '', 'Elementor can hide the clip title and artist independently');
root.dataset.musicMeta = '1'; await refresh();
const beforeError = requests; clip.options.events.onError({ data: 150 });
check(requests === beforeError && !node('[data-player-source]').hidden, 'An unavailable embedded clip leaves its official link without changing the shared schedule');
clock = 243000; current = programme({ start: 1242, end: 1245, music_phase: 'exit' }); await refresh();
check(clip.destroyed && !node('[data-music-screen]').hidden && node('[data-player-mount]').children.length === 0, 'The exit phase destroys the official clip before displaying an ident');
check(node('[data-music-text]').textContent === 'Back to BSC TV' && node('[data-music-identity]').style.animationDelay === '-1s', 'Late exit arrival uses its own persisted phase and text');
rejectOldIdent(new Error('Old media request rejected')); await flush();
check(!node('[data-music-screen]').hidden, 'A stale ident failure cannot remove a newer transition screen');
clip.options.events.onStateChange({ data: 1 });
check(!node('[data-music-screen]').hidden, 'A stale YouTube PLAYING event cannot cancel the exit phase');
clock = 245000; current = programme({ key: 'youtube:M7lc1UVf-VE', resource: { platform: 'youtube', id: 'M7lc1UVf-VE' }, title: 'Next beer programme', creator: 'Beer creator', start: 1245, end: 4845, music: false, music_phase: undefined, url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE' }); await refresh();
check(players.length === 2 && node('[data-music-screen]').hidden && node('[data-player-title]').textContent === 'Next beer programme', 'The existing player automatically returns to the next scheduled beer show');
check(root.badges.find(badge => badge.dataset.playerBadge === 'music').hidden, 'The music badge disappears on a normal programme');
const background = structuredClone(current);
current = programme({ key: 'waiting:brew:1245', music: false, standby: true, expected_start: 1300, start: 1245, end: 1256, identity: { ...identity, entry_video: 'https://bsc.example/circus.mp4' } }); await refresh();
check(players.at(-1).destroyed && !node('[data-music-screen]').hidden && video.loop, 'A pre-live reservation destroys the replay and loops BSC video while waiting');
check(root.badges.find(badge => badge.dataset.playerBadge === 'live').hidden && root.badges.find(badge => badge.dataset.playerBadge === 'replay').hidden, 'Waiting is neither a confirmed live nor a replay badge');
check(node('[data-player-source]').hidden && node('[data-player-progress]').hidden, 'Waiting has no fake source link or programme seek progress');
check([...timers.values()].some(timer => timer.delay === 5000), 'Waiting checks the local programme every five seconds');
clock = 256000; current = programme({ key: 'waiting:brew:1256', music: false, standby: true, expected_start: 1300, start: 1256, end: 1267, identity: { ...identity, entry_video: 'https://bsc.example/moon.mp4' } }); await refresh();
check(video.src.endsWith('moon.mp4') && video.loop && players.length === 2, 'The following standby identity changes without creating a platform player');
clock = 300000; current = programme({ key: 'live:youtube:aaaaaaaaaaa', resource: { platform: 'youtube', id: 'aaaaaaaaaaa' }, start: 1300, end: 0, live: true, music: false, music_phase: undefined, identity: null }); await refresh();
check(players.length === 3 && node('[data-music-screen]').hidden, 'Confirmed live replaces waiting with the official player');
const actualLive = players.at(-1); clock = 1200000; await refresh();
check(players.at(-1) === actualLive && !actualLive.destroyed, 'A live without an end deadline is never restarted or cut at an estimated boundary');
current = background; await refresh();
check(actualLive.destroyed && players.length === 4 && players.at(-1).position === 955, 'After actual live ends, the replay joins the wall-clock offset instead of the paused offset');
check(!video.loop && node('[data-music-screen]').hidden, 'Standby loop does not remain visible over the resumed official video');
root.isConnected = false; pulses[0]();
check(video.paused && node('[data-music-screen]').hidden && document.events.size === 0, 'Widget teardown cleans up identity media and listeners');
console.log(`OK ${checks} music phase browser SDK checks (simulated YouTube).`);

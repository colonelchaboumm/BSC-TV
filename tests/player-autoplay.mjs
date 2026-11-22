// Visibility, browser policy and single-player autoplay contracts; no real SDK calls.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const code = await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8');
class Node {
  constructor() { this.nodes = new Map(); this.events = new Map(); this.attributes = new Map(); this.dataset = {}; this.isConnected = true; this.hidden = false; this.clientWidth = 960; this.textContent = ''; }
  querySelector(selector) { return this.nodes.get(selector) || null; }
  querySelectorAll() { return []; }
  getAttribute(name) { return this.attributes.get(name); }
  removeAttribute(name) { this.attributes.delete(name); }
  set src(value) { this.attributes.set('src', value); }
  setAttribute(name, value) { this.attributes.set(name, value); }
  replaceChildren() {}
  append() {}
  addEventListener(name, callback) { if (!this.events.has(name)) this.events.set(name, new Set()); this.events.get(name).add(callback); }
  removeEventListener(name, callback) { this.events.get(name)?.delete(callback); }
  async fire(name) { for (const callback of this.events.get(name) || []) await callback({ target: this }); }
}
function environment(modes = ['1'], hidden = false, blocked = false) {
  const roots = modes.map(mode => {
    const root = new Node(); root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: mode, muted: '0' };
    for (const selector of ['[data-player-mount]', '.bsc-player__screen', '[data-player-message]', '[data-player-pause]', '[data-player-controls]', '[data-player-mute]', '[data-player-volume]', '[data-player-volume-control]', '[data-player-toolbar]', '[data-player-meta]', '[data-player-source]']) root.nodes.set(selector, new Node());
    return root;
  });
  const document = new Node(); document.documentElement = { lang: 'fr' }; document.baseURI = 'https://bsc.example/le-live/'; document.readyState = 'complete'; document.hidden = hidden; document.head = new Node();
  document.querySelectorAll = selector => selector === '[data-bsc-player]' ? roots : [];
  document.createElement = () => new Node();
  const observers = []; const players = []; const timers = new Map(); const pulses = [];
  let nextTimer = 0; let requests = 0;
  const state = { blocked };
  class Observer {
    constructor(callback) { this.callback = callback; observers.push(this); }
    observe() {} disconnect() {}
    visible(ratio) { this.callback([{ intersectionRatio: ratio }]); }
  }
  class Player {
    constructor(id, options) { this.options = options; players.push(this); queueMicrotask(() => options.events.onReady()); }
    mute() { this.muted = true; } unMute() { this.muted = false; }
    seekTo(value) { this.position = value; } getCurrentTime() { return this.position; }
    getPlayerState() { return this.playing ? 1 : 2; }
    playVideo() { if (state.blocked) this.options.events.onAutoplayBlocked(); else { this.playing = true; this.options.events.onStateChange({ data: 1 }); } }
    destroy() { this.destroyed = true; }
  }
  const window = { YT: { Player, PlayerState: { PLAYING: 1, ENDED: 0 } }, setTimeout(callback, delay) { timers.set(++nextTimer, { callback, delay }); return nextTimer; }, clearTimeout(id) { timers.delete(id); }, setInterval(callback) { pulses.push(callback); return pulses.length; }, clearInterval() {} };
  const context = { document, window, IntersectionObserver: Observer, location: { origin: 'https://bsc.example', hostname: 'bsc.example' }, performance: { now: () => 0 }, URL, Intl, Date, Error, AbortController,
    async fetch() { requests++; return { ok: true, async json() { return { enabled: true, server_time: 1000, timezone: 'Europe/Paris', tolerance: 8, next: [], current: { key: 'youtube:current', resource: { platform: 'youtube', id: 'M7lc1UVf-VE' }, title: 'Le live', start: 900, end: 2000, live: false, url: 'https://youtu.be/M7lc1UVf-VE' } }; } }; }
  };
  vm.runInNewContext(code, context);
  return { roots, document, observers, players, state, timers, pulses, requests: () => requests };
}
const flush = async () => { for (let i = 0; i < 24; i++) await Promise.resolve(); };
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
const visible = environment(['1', '1']); await flush();
check(visible.players.length === 0, 'Automatic startup waits until the player is actually visible');
visible.observers[0].visible(.4); await flush();
check(visible.players.length === 0, 'A player less than half visible does not automatically start');
visible.observers[0].visible(.5); await flush();
check(visible.players.length === 1 && visible.players[0].playing && visible.players[0].position === 100, 'A visible player automatically starts at the shared programme offset');
check(visible.players[0].muted === false, 'Automatic startup keeps the requested sound-on setting');
visible.observers[1].visible(1); await flush();
check(visible.players.length === 1, 'Only one player per page starts automatically');
const requests = visible.requests(); visible.observers[0].visible(1); await flush();
check(visible.requests() === requests, 'Repeated observer events do not launch duplicate automatic requests');
const background = environment(['1'], true); background.observers[0].visible(1); await flush();
check(background.requests() === 0 && background.players.length === 0, 'A background tab does not load or start the programme');
background.document.hidden = false; await background.document.fire('visibilitychange'); await flush();
check(background.players.length === 1 && background.players[0].playing, 'Returning to a visible background-loaded page can claim automatic playback');
const manual = environment(['0', '1']); manual.observers[0].visible(1); await flush();
check(manual.players.length === 0, 'Explicit click mode remains silent even when the widget is visible');
await manual.roots[0].querySelector('[data-player-pause]').fire('click'); await flush();
manual.observers[1].visible(1); await flush();
check(manual.players.length === 1, 'A manual start prevents another widget from automatically adding a second soundtrack');
const denied = environment(['1'], false, true); denied.observers[0].visible(1); await flush();
check(denied.players.length === 1 && !denied.players[0].playing && denied.roots[0].querySelector('[data-player-message]').textContent === '' && !denied.roots[0].querySelector('[data-player-controls]').hidden && denied.roots[0].querySelector('[data-player-pause]').dataset.state === 'play', 'Browser blocking leaves the single play icon available without the removed message');
denied.state.blocked = false; await denied.roots[0].querySelector('[data-player-pause]').fire('click'); await flush();
check(denied.players[0].playing && denied.players.length === 1, 'The existing play/pause icon resumes the official player without a separate start button');
const pending = environment(['1']); pending.observers[0].visible(1); await flush();
check(pending.players.length === 1 && pending.players[0].playing, 'Visibility detected during the initial data request still results in automatic playback');
for (const scene of [visible, background, manual, denied, pending]) { for (const root of scene.roots) root.isConnected = false; for (const pulse of scene.pulses) pulse(); }
check([visible, background, manual, denied, pending].every(scene => scene.timers.size === 0), 'Removing automatically started widgets cleans up their pending timers');
console.log(`OK ${checks} autoplay browser checks.`);

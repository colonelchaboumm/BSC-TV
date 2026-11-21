// Exercise the shipped player against public SDK doubles, never real platform credentials.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Element {
  constructor() { this.dataset = {}; this.nodes = new Map(); this.events = new Map(); this.attrs = new Map(); this.isConnected = true; this.hidden = false; this.clientWidth = 960; }
  querySelector(selector) { return this.nodes.get(selector) || null; }
  querySelectorAll() { return []; }
  addEventListener(name, callback) { if (!this.events.has(name)) this.events.set(name, new Set()); this.events.get(name).add(callback); }
  removeEventListener(name, callback) { this.events.get(name)?.delete(callback); }
  async fire(name) { for (const callback of this.events.get(name) || []) await callback({ target: this }); }
  setAttribute(name, value) { this.attrs.set(name, value); }
  getAttribute(name) { return this.attrs.get(name); }
  removeAttribute(name) { this.attrs.delete(name); }
  replaceChildren() {}
  append() {}
}
const root = new Element();
root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: '0', muted: '0' };
for (const selector of ['[data-player-mount]', '.bsc-player__screen', '[data-player-message]', '[data-player-join]', '[data-player-meta]', '[data-player-source]', '[data-player-controls]', '[data-player-pause]', '[data-player-mute]', '[data-player-volume]', '[data-player-fullscreen]']) root.nodes.set(selector, new Element());
const node = selector => root.querySelector(`[data-player-${selector}]`);
const document = new Element();
Object.assign(document, { readyState: 'complete', hidden: false, fullscreenEnabled: true, documentElement: { lang: 'fr' }, baseURI: 'https://bsc.example/le-live/', head: new Element() });
document.querySelectorAll = selector => selector === '[data-bsc-player]' ? [root] : [];
document.createElement = () => new Element();
root.requestFullscreen = async () => { document.fullscreenElement = root; await document.fire('fullscreenchange'); };
document.exitFullscreen = async () => { document.fullscreenElement = null; await document.fire('fullscreenchange'); };
let clock = 0;
let requests = 0;
const pulses = [];
const players = [];
const replay = (platform = 'youtube', changes = {}) => ({ key: `${platform}:one`, resource: { platform, id: platform === 'youtube' ? 'M7lc1UVf-VE' : 'v123456' }, title: 'Émission', start: 900, end: 2000, live: false, url: 'https://bsc.example/source', ...changes });
let current = replay();
class YouTube {
  constructor(id, options) { this.options = options; this.seeks = []; this.state = 2; players.push(this); queueMicrotask(() => options.events.onReady()); }
  seekTo(position) { this.seeks.push(position); this.position = position; if (this.throwSeek) throw new Error('Temporarily unavailable'); }
  getCurrentTime() { return this.position; }
  getPlayerState() { return this.state; }
  playVideo() { this.state = 1; this.options.events.onStateChange({ data: 1 }); }
  pauseVideo() { this.state = 2; this.options.events.onStateChange({ data: 2 }); }
  setPlaybackRate(rate) { this.rate = rate; this.options.events.onPlaybackRateChange({ data: rate }); }
  setVolume(volume) { this.volume = volume; }
  mute() { this.muted = true; } unMute() { this.muted = false; }
  destroy() { this.destroyed = true; }
}
class Twitch {
  static READY = 'ready'; static PLAYING = 'playing'; static SEEK = 'seek'; static ENDED = 'ended'; static OFFLINE = 'offline'; static PLAYBACK_BLOCKED = 'blocked';
  constructor(id, options) { this.options = options; this.events = new Map(); this.seeks = []; this.position = 0; players.push(this); }
  addEventListener(name, callback) { this.events.set(name, callback); if (name === 'ready') queueMicrotask(callback); }
  seek(position) { this.seeks.push(position); if (this.throwSeek) throw new Error('Temporarily unavailable'); this.position = position; this.events.get('seek')(); }
  getCurrentTime() { return this.position; }
  play() { this.paused = false; this.events.get('playing')(); }
  pause() { this.paused = true; } isPaused() { return !!this.paused; }
}
const window = { YT: { Player: YouTube, PlayerState: { PLAYING: 1, ENDED: 0 } }, Twitch: { Player: Twitch }, setInterval: callback => { pulses.push(callback); return pulses.length; }, clearInterval() {}, setTimeout: () => 1, clearTimeout() {} };
vm.runInNewContext(await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8'), {
  document, window, URL, Intl, Date, Error, AbortController,
  IntersectionObserver: class { observe() {} disconnect() {} },
  location: { origin: 'https://bsc.example', hostname: 'bsc.example' }, performance: { now: () => clock },
  fetch: async () => { requests++; return { ok: true, json: async () => ({ enabled: true, server_time: 1000 + clock / 1000, timezone: 'Europe/Paris', next: [], current: structuredClone(current) }) }; },
});
const flush = async () => { for (let n = 0; n < 24; n++) await Promise.resolve(); };
const tick = value => { clock = value; pulses[0](); };
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
await flush(); await node('join').fire('click'); await flush();
let player = players.at(-1);
check(player.position === 100 && player.state === 1, 'Initial join works before the SDK exposes its current time');
check(player.options.playerVars.controls === 0 && player.options.playerVars.disablekb === 1, 'YouTube timeline and keyboard seeking are disabled using documented options');
check(!node('controls').hidden && !player.muted && player.volume === 100, 'Sound and accessible external controls remain available');
const initialRequests = requests;
player.position = 104; tick(4000); player.position = 800; tick(5000);
check(player.position === 105, 'Seeking forward is corrected at the next local pulse');
player.position = 1; tick(6000);
check(player.position === 1, 'Rapid repeated jumps respect the two-second SDK cooldown');
tick(7000);
check(player.position === 107, 'A backward jump is corrected without a page refresh');
let count = player.seeks.length; player.position = 106; tick(8000);
check(player.seeks.length === count, 'Small decoder/keyframe jitter does not cause a seek loop');
await node('pause').fire('click'); count = player.seeks.length; tick(30000);
check(player.state === 2 && player.seeks.length === count, 'A voluntary pause stays paused without repeated positioning');
await node('pause').fire('click');
check(player.state === 1 && player.position === 130, 'Resume rejoins the antenna instead of the paused offset');
clock = 34000; player.position = 5; player.playVideo();
check(player.position === 134, 'A resume initiated inside the provider also rejoins the antenna');
player.setPlaybackRate(2);
check(player.rate === 1, 'YouTube replay speed is restored to normal without recursion');
await node('mute').fire('click');
check(player.muted && node('mute').getAttribute('aria-pressed') === 'true', 'Mute works without restoring the timeline');
node('volume').value = '35'; await node('volume').fire('input');
check(!player.muted && player.volume === 35, 'Volume can be changed and unmutes the programme');
await node('fullscreen').fire('click');
check(document.fullscreenElement === root && node('fullscreen').textContent.includes('Quitter'), 'Fullscreen includes the external controls');
await node('fullscreen').fire('click');
check(!document.fullscreenElement, 'Fullscreen can be exited from the same control');
clock = 38000; player.position = 1100; player.state = 0; player.options.events.onStateChange({ data: 0 });
check(player.position === 138 && player.state === 1, 'Seeking to the end does not prematurely finish the programme');
check(requests === initialRequests, 'Seeking, speed correction and resume make no HTTP or import requests');
clock = 39000; player.position = 1100; player.state = 0; player.options.events.onStateChange({ data: 0 }); tick(40000);
check(player.position === 140 && player.state === 1, 'An end event within cooldown is recovered on a later pulse');
player.position = NaN; count = player.seeks.length; tick(44000);
check(player.seeks.length === count, 'Unavailable SDK positions are not passed back as seek requests');
player.position = 0; player.throwSeek = true; tick(46000); player.throwSeek = false; player.position = 0; tick(48000);
check(player.position === 148, 'A temporarily rejected seek does not leave the reentrancy guard locked');

const old = player;
current = replay('youtube', { key: 'youtube:next', start: 1030, end: 2100 });
await document.fire('visibilitychange'); await flush(); player = players.at(-1);
check(old.destroyed && player.position === 18 && player.volume === 35, 'A programme change uses its own offset and preserves chosen audio');
count = player.seeks.length; old.options.events.onStateChange({ data: 0 }); old.options.events.onPlaybackRateChange({ data: 2 });
check(player.seeks.length === count && player.rate === undefined, 'Stale callbacks cannot change the new programme');

current = replay('twitch'); await document.fire('visibilitychange'); await flush(); player = players.at(-1);
check(player.position === 148 && player.seeks.length === 1, 'Initial Twitch seek does not recurse through its own SEEK event');
check(node('controls').hidden && player.options.controls === undefined, 'Twitch retains its official interface without unsupported hiding parameters');
clock = 52000; player.position = 1000; player.events.get('seek')();
check(player.position === 152 && player.seeks.length === 2, 'Twitch SEEK immediately rejects a forward jump');
player.pause(); clock = 53000; player.position = 0; player.events.get('seek')(); tick(54000);
check(player.position === 154 && player.paused, 'A seek while paused survives cooldown and is corrected without forcing playback');
clock = 58000; player.position = 0; player.throwSeek = true; player.events.get('seek')();
check(player.position === 0 && player.paused, 'A provider refusal while paused does not force playback');
player.throwSeek = false; tick(60000);
check(player.position === 160 && player.paused, 'A refused paused seek is retried when the provider becomes available');
count = player.seeks.length; tick(80000);
check(player.seeks.length === count, 'A paused Twitch replay does not continually seek');
player.play();
check(player.position === 180, 'Resuming Twitch rejoins the current broadcast offset');
clock = 84000; player.position = 1000; player.events.get('ended')();
check(player.position === 184 && !player.paused, 'Twitch cannot skip the remaining slot by seeking to the end');

current = replay('twitch', { key: 'live:twitch', resource: { platform: 'twitch', id: 'beerstream' }, live: true, end: 0 });
await document.fire('visibilitychange'); await flush(); player = players.at(-1); tick(90000); player.events.get('seek')(); player.play();
check(player.seeks.length === 0, 'Real Twitch live streams never receive VOD positioning commands');
current = replay('youtube', { key: 'live:youtube', live: true, end: 0 });
await document.fire('visibilitychange'); await flush(); player = players.at(-1); tick(95000); player.options.events.onPlaybackRateChange({ data: 2 });
check(player.seeks.length === 0 && player.rate === undefined, 'Real YouTube live streams keep their provider-managed timeline');
check(player.options.playerVars.controls === 0 && !node('controls').hidden, 'YouTube DVR navigation stays hidden while audio and fullscreen remain usable');
root.isConnected = false; tick(96000);
check(node('controls').hidden && document.events.get('fullscreenchange').size === 0, 'Removing the widget hides its controls and removes document listeners');
console.log(`OK ${checks} linear playback SDK contract checks (simulated YouTube/Twitch).`);

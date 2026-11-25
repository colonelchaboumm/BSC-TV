// Exercise the public player through standby, films, provider playback and teardown.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Element {
  constructor() { this.nodes = new Map(); this.events = new Map(); this.attrs = new Map(); this.dataset = {}; this.style = { setProperty() {} }; this.hidden = false; this.isConnected = true; this.clientWidth = 960; this.children = []; this.paused = true; this.duration = 180; this.currentTime = 0; }
  querySelector(s) { return this.nodes.get(s) || null; }
  querySelectorAll(s) { return s === '[data-player-badge]' ? [] : []; }
  addEventListener(n, f) { this.events.set(n, f); } removeEventListener(n) { this.events.delete(n); }
  fire(n) { return this.events.get(n)?.({ target: this }); }
  getAttribute(n) { return this.attrs.get(n); } setAttribute(n, v) { this.attrs.set(n, v); } removeAttribute(n) { this.attrs.delete(n); }
  set src(v) { this.attrs.set('src', v); this.sources = (this.sources || 0) + 1; } get src() { return this.attrs.get('src'); }
  replaceChildren(...c) { this.children = c; } append(...c) { this.children.push(...c); }
  pause() { this.paused = true; } load() { this.loads = (this.loads || 0) + 1; }
  play() {
    this.plays = (this.plays || 0) + 1;
    if (this.blocked) return Promise.reject(new Error('Autoplay requires a gesture'));
    this.paused = false;
    if (this.defer) return new Promise((resolve, reject) => { this.reject = reject; });
    return Promise.resolve();
  }
}
const root = new Element(); root.dataset = { bscPlayer: 'https://bsc.example/wp-json/bsc-tv/v1/player', autoplay: '0', muted: '0' };
for (const s of ['[data-player-mount]', '.bsc-player__screen', '[data-player-message]', '[data-player-pause]', '[data-player-controls]', '[data-player-mute]', '[data-player-volume]', '[data-player-volume-control]', '[data-player-toolbar]', '[data-player-meta]', '[data-player-source]', '[data-player-title]', '[data-player-creator]', '[data-player-profile]', '[data-player-avatar]', '[data-player-progress]', '[data-music-screen]', '[data-music-video]', '[data-music-identity]', '[data-music-logo]', '[data-music-text]', '[data-animation-audio]', '[data-player-transition]', '[data-player-transition-video]']) root.nodes.set(s, new Element());
const node = s => root.querySelector(s);
const audio = node('[data-animation-audio]'); audio.dataset = { src: 'https://bsc.example/bed.mp3', volume: '100' };
const film = node('[data-music-video]');
node('[data-player-transition-video]').dataset = { src: 'https://bsc.example/moon.mp4', sources: '["https://bsc.example/moon.mp4"]' };
node('[data-player-transition]').hidden = true;
const document = new Element(); Object.assign(document, { readyState: 'complete', hidden: false, documentElement: { lang: 'fr' }, baseURI: 'https://bsc.example/live/', head: new Element() });
document.querySelectorAll = s => s === '[data-bsc-player]' ? [root] : []; document.createElement = () => new Element();
let clock = 0; let reduced = false; let soundtrack = { url: 'https://bsc.example/bed.mp3', volume: 60 }; let holdProvider = false;
const players = []; const pulses = []; const timers = new Map(); let timer = 0;
const identity = { logo: '', entry_video: 'https://bsc.example/circus.mp4', exit_video: 'https://bsc.example/moon.mp4', background: '#111116', color: '#ffffff', sound: true, show_text: false, standby_key: 'waiting:brew:1020', standby_start: 980 };
const waiting = (changes = {}) => ({ key: 'waiting:brew:980', resource: { platform: 'identity', id: 'waiting' }, standby: true, music: false, live: false, expected_start: 1020, start: 999, end: 1010, title: 'Upcoming beer live', identity: { ...identity }, music_phase: 'entry', ...changes });
let current = waiting();
class Provider {
  constructor(id, options) { this.options = options; this.state = 2; players.push(this); queueMicrotask(() => options.events.onReady()); }
  getPlayerState() { return this.state; } getCurrentTime() { return 0; } seekTo() {} mute() {} unMute() {} setVolume() {}
  playVideo() { if (!holdProvider) { this.state = 1; this.options.events.onStateChange({ data: 1 }); } } destroy() { this.destroyed = true; }
}
const window = { YT: { Player: Provider, PlayerState: { PLAYING: 1, ENDED: 0 } }, matchMedia: () => ({ matches: reduced }), setTimeout: (fn, delay) => { timers.set(++timer, { fn, delay }); return timer; }, clearTimeout: id => timers.delete(id), setInterval: fn => { pulses.push(fn); return pulses.length; }, clearInterval() {} };
vm.runInNewContext(await fs.readFile(new URL('../assets/player.js', import.meta.url), 'utf8'), { document, window, URL, Intl, Date, Error, AbortController, performance: { now: () => clock }, location: { origin: 'https://bsc.example', hostname: 'bsc.example' }, IntersectionObserver: class { observe() {} disconnect() {} }, fetch: async () => ({ ok: true, json: async () => ({ enabled: true, server_time: 1000 + clock / 1000, timezone: 'Europe/Paris', next: [], current: structuredClone(current), animation_soundtrack: structuredClone(soundtrack) }) }) });
const flush = async () => { for (let n = 0; n < 35; n++) await Promise.resolve(); };
const refresh = async () => { document.fire('visibilitychange'); await flush(); };
let checks = 0; const check = (v, label) => { assert.ok(v, label); checks++; };
await flush();
check(!audio.src && !audio.plays, 'A visitor who has not joined does not download or play the MP3');
await node('[data-player-pause]').fire('click'); await flush();
check(audio.src === soundtrack.url && audio.loop && !audio.paused && !audio.muted, 'Joining standby starts the requested local track in a loop with sound');
check(audio.currentTime === 20 && audio.volume === .6, 'A late standby arrival joins the shared musical offset and configured gain');
check(film.muted, 'The supplied soundtrack replaces the original film audio instead of mixing both');
check(!node('[data-player-mute]').hidden && !node('[data-player-volume-control]').hidden && node('[data-player-pause]').hidden, 'Standby exposes sound controls without pretending the programme can be paused');
const plays = audio.plays; const loads = audio.loads || 0;
audio.currentTime = 31; clock = 11000; current = waiting({ key: 'waiting:brew:1010', start: 1010, end: 1021, identity: { ...identity, entry_video: 'https://bsc.example/moon.mp4' } }); await refresh();
check(film.src.endsWith('moon.mp4') && audio.plays === plays && audio.currentTime === 31 && (audio.loads || 0) === loads, 'Changing standby film does not stop, reload, seek or restart its audio');
await refresh();
check(audio.plays === plays && audio.currentTime === 31, 'Polling the same phase does not restart the soundtrack');
node('[data-player-volume]').value = '40'; node('[data-player-volume]').fire('input');
check(Math.abs(audio.volume - .24) < .000001, 'Viewer volume multiplies the administrator gain');
node('[data-player-mute]').fire('click'); check(audio.muted, 'Mute applies during the standby animation');
node('[data-player-mute]').fire('click'); check(!audio.muted, 'The viewer can restore the animation sound');
document.hidden = true; document.fire('visibilitychange'); check(audio.paused && film.paused, 'A background tab pauses film and soundtrack together');
clock = 201000; document.hidden = false; current = waiting({ key: 'waiting:brew:1198', start: 1198, end: 1209 }); await refresh();
check(!audio.paused && audio.currentTime === 41, 'Returning joins the original standby bed modulo its duration, across complete loops');
soundtrack = { url: 'https://bsc.example/new-bed.mp3', volume: 50 }; await refresh();
check(audio.src === soundtrack.url && audio.volume === .2, 'An administrator replacement reaches an open player through existing polling');
audio.blocked = true; current = waiting({ key: 'entry:clip', standby: false, music: true, start: 1200, end: 1211 }); await refresh();
check(node('[data-player-mute]').getAttribute('aria-label') === 'Activer le son' && !film.hidden, 'A denied audio autoplay leaves the film visible and exposes a sound retry');
const deniedPlays = audio.plays; await refresh(); check(audio.plays === deniedPlays, 'Autoplay denial does not retry the MP3 on each poll');
audio.blocked = false; node('[data-player-mute]').fire('click'); await flush();
check(!audio.paused && !audio.muted && node('[data-player-mute]').getAttribute('aria-label') === 'Couper le son', 'A user sound gesture retries blocked playback');
reduced = true; await refresh(); check(audio.paused && !audio.src && film.hidden, 'Reduced motion does not play a soundtrack behind an invisible animation');
reduced = false; await refresh();
soundtrack = { url: '', volume: 100 }; await refresh(); check(!audio.src && audio.paused && !film.muted, 'Disabling the bed stops it and restores the separately configured original film sound');
soundtrack = { url: 'javascript:alert(1)', volume: 100 }; await refresh(); check(!audio.src, 'An invalid public audio URL is rejected');
soundtrack = { url: 'https://bsc.example/bed.mp3', volume: 60 }; await refresh();
audio.fire('error'); const errorPlays = audio.plays; await refresh();
check(audio.paused && !audio.src && audio.plays === errorPlays && !film.hidden, 'A failed MP3 is not retried endlessly and does not remove the animation');
soundtrack = { url: 'https://bsc.example/recovered.mp3', volume: 100 }; await refresh();
check(!audio.paused && audio.src === soundtrack.url, 'A new valid audio source recovers after a previous file failure');
audio.defer = true; current = waiting({ key: 'entry:another', standby: false, music: true, start: 1200, end: 1211 }); await refresh(); const staleRejection = audio.reject;
holdProvider = true; current = { key: 'live:youtube:aaaaaaaaaaa', resource: { platform: 'youtube', id: 'aaaaaaaaaaa' }, live: true, start: 1200, end: 0, title: 'Confirmed live', url: 'https://youtu.be/aaaaaaaaaaa' }; await refresh();
check(!audio.paused && !node('[data-player-transition]').hidden, 'The soundtrack may accompany the loading animation until the provider starts');
holdProvider = false; players.at(-1).playVideo(); await flush();
check(audio.paused && !audio.src && node('[data-player-transition]').hidden, 'Confirmed provider PLAYING immediately stops and unloads local music');
staleRejection(new Error('Old promise')); await flush();
check(audio.paused && !audio.src && node('[data-player-mute]').getAttribute('aria-label') === 'Couper le son', 'A stale rejection cannot restart music or change live sound controls');
audio.defer = false; current = waiting({ key: 'exit:clip', standby: false, music: true, music_phase: 'exit', start: 1200, end: 1211 }); await refresh();
check(!audio.paused && film.src.endsWith('moon.mp4'), 'The exit animation also uses the local soundtrack');
current = { key: 'music:youtube:bbbbbbbbbbb', resource: { platform: 'youtube', id: 'bbbbbbbbbbb' }, music: true, music_phase: 'clip', start: 1200, end: 1500, title: 'Official musical interlude', url: 'https://youtu.be/bbbbbbbbbbb' }; await refresh();
check(audio.paused && !audio.src && !node('[data-player-mount]').hidden, 'The local bed never overlaps an official musical clip');
class Twitch {
  static READY = 'ready'; static PLAYING = 'playing'; static SEEK = 'seek'; static PLAYBACK_BLOCKED = 'blocked'; static OFFLINE = 'offline'; static ENDED = 'ended';
  constructor() { this.events = new Map(); players.push(this); queueMicrotask(() => this.events.get('ready')()); }
  addEventListener(n, f) { this.events.set(n, f); } play() { if (!holdProvider) this.events.get('playing')(); } destroy() { this.destroyed = true; }
}
window.Twitch = { Player: Twitch }; holdProvider = true;
current = { key: 'live:twitch:beerstream', resource: { platform: 'twitch', id: 'beerstream' }, live: true, start: 1200, end: 0, title: 'Twitch live', url: 'https://twitch.tv/beerstream' }; await refresh();
check(!audio.paused && !node('[data-player-controls]').hidden && !node('[data-player-mute]').hidden, 'A Twitch loading animation retains accessible local music controls after SDK READY');
node('[data-player-mute]').fire('click'); check(audio.muted, 'Mute works while a Twitch stream is still loading');
node('[data-player-mute]').fire('click'); check(!audio.muted, 'Sound can be restored while Twitch loads');
holdProvider = false; players.at(-1).play(); await flush();
check(audio.paused && !audio.src, 'Twitch PLAYING immediately stops the local bed too');
current = waiting({ key: 'waiting:final', start: 1200, end: 1211 }); await refresh();
root.isConnected = false; pulses[0]();
check(audio.paused && !audio.src && document.events.size === 0, 'Removing an Elementor widget stops and unloads the local audio');
console.log(`OK ${checks} animation soundtrack lifecycle checks.`);

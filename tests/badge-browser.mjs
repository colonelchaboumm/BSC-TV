// Public badge refresh and dropdown regressions with DOM/network doubles.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Element {
  constructor(role = '') { this.role = role; this.dataset = {}; this.nodes = new Map(); this.hidden = false; this.textContent = ''; this.innerHTML = ''; this.isConnected = true; this.style = { setProperty() {}, removeProperty() {} }; this.classList = { contains: name => name === 'bsc-tv-badge--inline' && !!this.inline, remove() {} }; }
  querySelector(selector) { return selector === ':focus-visible' ? (this.contains(document.activeElement) ? document.activeElement : null) : this.nodes.get(selector) || null; }
  contains(element) { for (let node = element; node; node = node.parent) if (node === this) return true; return false; }
  closest(selector) {
    for (let node = this; node; node = node.parent) {
      if (selector === '.bsc-tv-badge__trigger' && node.role === 'trigger') return node;
      if (selector.startsWith('.bsc-tv-badge') && !selector.startsWith('.bsc-tv-badge__') && node.role === 'badge' && (!selector.includes('[open]') || node.open) && (!selector.includes(':not') || !node.inline)) return node;
    }
    return null;
  }
  matches(selector) { return this.closest(selector) === this; }
  removeAttribute(name) { delete this[name]; }
  getBoundingClientRect() { return { top: 50, bottom: 94, left: 20, right: 380 }; }
  focus() { document.activeElement = this; }
}
function widget(url = 'https://bsc.example/wp-json/bsc-tv/v1/programme?format=badge') {
  const root = new Element('widget'); root.dataset = { bscTv: url, live: '0' };
  const badge = new Element('badge'); badge.parent = root; badge.open = false;
  const trigger = new Element('trigger'); trigger.parent = badge;
  const panel = new Element(); panel.parent = badge; panel.scrollHeight = 250;
  const programme = new Element(); programme.parent = panel; programme.innerHTML = 'Initial programme';
  const status = new Element(); status.parent = trigger; status.dataset = { bscTvLiveLabel: 'LIVE', bscTvUpcomingLabel: 'À venir', bscTvEmptyLabel: 'BSC TV' };
  const label = new Element(); status.nodes.set('.bsc-tv-badge__label', label);
  const title = new Element(); const date = new Element(); const details = new Element();
  const watch = new Element(); watch.parent = panel; watch.href = 'https://brewerssocialclub.com/le-live/';
  root.nodes = new Map([['.bsc-tv-badge', badge], ['.bsc-tv-programme', programme], ['.bsc-tv-announcement', new Element()], ['.bsc-tv-watch', watch]]);
  badge.nodes = new Map([['.bsc-tv-badge__trigger', trigger], ['.bsc-tv-badge__panel', panel], ['.bsc-tv-badge__status', status], ['.bsc-tv-badge__title', title], ['.bsc-tv-badge__date', date], ['.bsc-tv-badge__details', details]]);
  return root;
}
const first = widget(); const second = widget(); const widgets = [first, second];
const events = new Map();
const document = {
  readyState: 'complete', visibilityState: 'visible', activeElement: null,
  documentElement: { clientHeight: 800, clientWidth: 1024 },
  addEventListener(name, callback) { if (!events.has(name)) events.set(name, []); events.get(name).push(callback); },
  querySelectorAll(selector) {
    if (selector === '[data-bsc-tv]') return widgets;
    if (selector.includes('[open]')) return widgets.map(root => root.querySelector('.bsc-tv-badge')).filter(badge => badge?.open);
    return [];
  }
};
const timers = new Map(); let timerId = 0; let clock = 0; const calls = [];
const window = { addEventListener() {}, setTimeout(callback, delay) { timers.set(++timerId, { callback, at: clock + delay, delay }); return timerId; }, clearTimeout(id) { timers.delete(id); } };
const result = (title, state = 'live', extra = {}) => ({ html: `<p>${title}</p>`, live: state === 'live' ? 1 : 0, highlight: { title, state, datetime: '2026-11-21T18:00:00+01:00', date: '18:00 – 19:00' }, poll_after: 10, announcement: `À l’antenne sur BSC TV : ${title}`, ...extra });
let response = result('Programme A'); let frResponse = result('Émission française'); let offline = false;
const context = { document, window, HTMLImageElement: class {}, setTimeout: window.setTimeout, clearTimeout: window.clearTimeout,
  async fetch(url, options) { calls.push({ url, options }); if (offline) throw new Error('Offline'); return { ok: true, async json() { return structuredClone(url.includes('station=fr') ? frResponse : response); } }; }
};
let checks = 0;
const check = (value, label) => { assert.ok(value, label); checks++; };
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
const fire = async (name, event = {}) => { for (const callback of events.get(name) || []) callback(event); await flush(); };
const advance = async milliseconds => { clock += milliseconds; for (const [id, timer] of [...timers]) if (timer.at <= clock) { timers.delete(id); timer.callback(); } await flush(); };
vm.runInNewContext(await fs.readFile(new URL('../assets/programme.js', import.meta.url), 'utf8'), context);
await flush();
const badge = first.querySelector('.bsc-tv-badge'); const title = badge.querySelector('.bsc-tv-badge__title'); const panel = first.querySelector('.bsc-tv-programme'); const watch = first.querySelector('.bsc-tv-watch');
check(calls.length === 1 && second.querySelector('.bsc-tv-programme').innerHTML === response.html, 'Two badges with the same configuration share one refresh request');
check(calls[0].options.cache === 'no-store' && calls[0].options.credentials === 'same-origin', 'The badge requests fresh local programme data');
check(title.textContent === 'Programme A' && first.dataset.bscTvState === 'live', 'The current programme title and LIVE state populate the trigger');
check([...timers.values()].some(timer => timer.delay === 10000), 'The refresh delay follows the next server transition');
badge.open = true;
response = result('Programme B'); await advance(10000);
check(title.textContent === 'Programme B' && panel.innerHTML === response.html, 'A programme change updates the trigger and dropdown together');
check(badge.open && first.querySelector('.bsc-tv-watch') === watch && watch.href === 'https://brewerssocialclub.com/le-live/', 'Refreshing preserves the open dropdown and its persistent watch link');
check(first.querySelector('.bsc-tv-announcement').textContent.includes('Programme B'), 'The accessible announcement identifies the new broadcast');
const retained = panel.innerHTML; offline = true; await advance(10000);
check(panel.innerHTML === retained && title.textContent === 'Programme B', 'A network error preserves the current visible programme');
offline = false; document.visibilityState = 'hidden'; const count = calls.length;
await fire('visibilitychange'); await advance(60000);
check(calls.length === count, 'A hidden tab performs no badge refresh requests');
document.visibilityState = 'visible'; response = result('Programme C'); await fire('visibilitychange');
check(title.textContent === 'Programme C', 'Returning to the page immediately refreshes the current programme');
document.activeElement = { parent: panel }; response = result('Programme D'); await advance(10000);
check(panel.innerHTML.includes('Programme C') && title.textContent === 'Programme D', 'A focused programme link is preserved while the compact trigger can still update');
document.activeElement = null; await advance(10000);
check(panel.innerHTML === response.html, 'The full dropdown resumes updating after its focused link is left');
response = result('Prochaine émission', 'upcoming', { announcement: undefined }); await advance(10000);
check(first.dataset.bscTvState === 'upcoming' && badge.querySelector('.bsc-tv-badge__status').querySelector('.bsc-tv-badge__label').textContent === 'À venir', 'An unavailable antenna falls back to the upcoming label without a stale LIVE state');
response = result('', 'empty', { highlight: { title: '', state: 'empty', datetime: '', date: '' }, announcement: undefined }); await advance(10000);
check(title.hidden && badge.querySelector('.bsc-tv-badge__date').hidden && badge.querySelector('.bsc-tv-badge__details').hidden, 'An empty programme removes old trigger details');
const validMarkup = panel.innerHTML; response = { html: 123, live: 1 }; await advance(10000);
check(panel.innerHTML === validMarkup, 'Malformed AJAX data does not replace the last valid programme');
let prevented = false;
await fire('click', { target: watch, pointerType: 'mouse', detail: 1, preventDefault() { prevented = true; } });
check(!prevented && badge.open, 'The watch link remains a normal accessible link inside the open menu');
await fire('keydown', { key: 'Escape', defaultPrevented: false, target: badge.querySelector('.bsc-tv-badge__trigger'), preventDefault() { prevented = true; } });
check(!badge.open && document.activeElement === badge.querySelector('.bsc-tv-badge__trigger'), 'Escape still closes the dropdown and restores focus to its trigger');
await fire('pointerover', { pointerType: 'mouse', target: badge.querySelector('.bsc-tv-badge__trigger'), relatedTarget: null });
await advance(250);
check(badge.open, 'Mouse hover still opens the dropdown');
prevented = false;
await fire('click', { target: badge.querySelector('.bsc-tv-badge__trigger'), pointerType: 'mouse', detail: 1, preventDefault() { prevented = true; } });
check(prevented, 'The first mouse click confirms a menu opened by hover');
await fire('click', { target: new Element(), pointerType: 'mouse', detail: 1, preventDefault() {} });
check(!badge.open, 'Clicking outside still closes the dropdown');
const third = widget('https://bsc.example/wp-json/bsc-tv/v1/programme?format=badge&player=0'); widgets.push(third);
response = result('Autre configuration', 'upcoming'); const before = calls.length; await fire('visibilitychange');
check(calls.length === before + 2 && third.querySelector('.bsc-tv-badge').querySelector('.bsc-tv-badge__title').textContent === 'Autre configuration', 'Different badge configurations retain separate refresh groups');
const plain = widget('https://bsc.example/wp-json/bsc-tv/v1/programme?format=menu'); plain.nodes.delete('.bsc-tv-badge'); widgets.push(plain);
await fire('visibilitychange');
check(plain.querySelector('.bsc-tv-programme').innerHTML === response.html, 'The existing programme and mega-menu refresh paths remain compatible');
response = result('Programme World indépendant');
const french = widget('https://bsc.example/wp-json/bsc-tv/v1/programme?format=badge&station=fr');
widgets.push(french); await fire('visibilitychange');
const frBadge = french.querySelector('.bsc-tv-badge'); const frTitle = frBadge.querySelector('.bsc-tv-badge__title');
check(frTitle.textContent === 'Émission française' && title.textContent === response.highlight.title, 'World and France receive their own station-specific programme responses');
frBadge.open = true; frResponse = result('Émission française suivante'); await advance(10000);
check(frTitle.textContent === 'Émission française suivante' && title.textContent === response.highlight.title && frBadge.open, 'A French transition preserves its dropdown and does not overwrite the World title');
frResponse = result('À venir en France', 'upcoming'); await advance(10000);
check(french.dataset.bscTvState === 'upcoming' && first.dataset.bscTvState !== 'upcoming', 'Each station keeps an independent LIVE status');
const logo = new Element('logo'); logo.parent = first; badge.open = true; prevented = false;
await fire('click', { target: logo, pointerType: 'mouse', detail: 1, preventDefault() { prevented = true; } });
check(!prevented, 'The logo beside the summary remains a normal navigation link');
console.log(`OK ${checks} badge browser checks.`);

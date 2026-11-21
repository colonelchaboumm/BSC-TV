// Run the shipped list updater with DOM/network doubles; no browser or platform APIs.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';

class Element {
  constructor() { this.dataset = {}; this.innerHTML = ''; this.textContent = ''; this.attributes = {}; this.isConnected = true; this.events = new Map(); }
  querySelector(selector) { return this.nodes?.[selector] ?? null; }
  setAttribute(name, value) { this.attributes[name] = value; }
  contains(element) { return element?.parent === this; }
  addEventListener(name, callback) { this.events.set(name, callback); }
  removeEventListener(name) { this.events.delete(name); }
  click() { this.events.get('click')?.(); }
  focus() { document.activeElement = this; }
}
function root(url = 'https://bsc.example/wp-json/bsc-tv/v1/day') {
  const value = new Element();
  value.dataset.bscDay = url;
  value.dataset.dayDate = '2026-11-21';
  value.nodes = { '[data-day-content]': new Element(), '[data-day-status]': new Element(), '[data-day-more]': new Element() };
  value.nodes['[data-day-content]'].innerHTML = 'Initial programme';
  return value;
}
const first = root();
const roots = [first];
const events = new Map();
const document = {
  readyState: 'complete', hidden: false, activeElement: null, baseURI: 'https://bsc.example/le-live/',
  querySelectorAll: selector => selector === '[data-bsc-day]' ? roots : [],
  addEventListener(name, callback) { if (!events.has(name)) events.set(name, new Set()); events.get(name).add(callback); },
  removeEventListener(name, callback) { events.get(name)?.delete(callback); }
};
const observers = [];
class Observer {
  constructor(callback) { this.callback = callback; observers.push(this); }
  observe(value) { this.root = value; }
  disconnect() { this.disconnected = true; }
  visible(value = true) { this.callback([{ isIntersecting: value }]); }
}
const hooks = new Map();
const timers = new Map();
const calls = [];
let timerId = 0;
let clock = 0;
let response = { html: '<p>Programme du jour</p>', poll_after: 10 };
let failed = false;
let fixtureDate = '2026-11-21';
const window = {
  IntersectionObserver: Observer,
  elementorFrontend: { hooks: { addAction(name, callback) { hooks.set(name, callback); } } },
  setTimeout(callback, delay) { timers.set(++timerId, { callback, at: clock + delay }); return timerId; },
  clearTimeout(id) { timers.delete(id); }
};
const context = {
  window, document, URL, AbortController, performance: { now: () => clock },
  async fetch(url, options) {
    calls.push({ url, options });
    if (failed) throw new Error('Offline');
    const query = new URL(url).searchParams;
    const page = query.get('date') && query.get('date') !== fixtureDate ? 1 : Number(query.get('page'));
    return { ok: true, async json() { return { page, date: fixtureDate, has_more: true, ...(query.get('show_images') === 'no' ? { html: '<p>Compact programme</p>', poll_after: 60 } : response) }; } };
  }
};
let checks = 0;
function check(value, label) { assert.ok(value, label); checks += 1; }
async function flush() { for (let i = 0; i < 12; i += 1) await Promise.resolve(); }
async function advance(milliseconds) {
  clock += milliseconds;
  const due = [...timers].filter(([, timer]) => timer.at <= clock);
  for (const [id, timer] of due) { timers.delete(id); timer.callback(); }
  await flush();
}
function visibility() { for (const callback of [...(events.get('visibilitychange') ?? [])]) callback(); }
await vm.runInNewContext(await fs.readFile(new URL('../assets/day.js', import.meta.url), 'utf8'), context);
await flush();
check(calls.length === 0, 'Offscreen daily lists do not poll');
observers[0].visible();
await flush();
check(first.nodes['[data-day-content]'].innerHTML === response.html, 'The current server fragment replaces stale initial content');
check(calls[0].options.cache === 'no-store' && calls[0].options.credentials === 'same-origin', 'Refresh requests are uncached and same-origin');
const init = hooks.get('frontend/element_ready/bsc-tv-day.default');
init([{ querySelectorAll: () => [first] }]);
await flush();
check(observers.length === 1 && calls.length === 1, 'Repeated Elementor initialization does not create duplicate timers');
document.hidden = true;
visibility();
await advance(15000);
check(calls.length === 1, 'A hidden browser tab stops polling');
response = { html: '<p>Le lendemain · nouveau programme</p>', poll_after: 10 };
document.hidden = false;
visibility();
await flush();
check(first.nodes['[data-day-content]'].innerHTML === response.html, 'Returning to the page refreshes the date and programmes after midnight');
failed = true;
const retained = first.nodes['[data-day-content]'].innerHTML;
await advance(10000);
check(first.nodes['[data-day-content]'].innerHTML === retained, 'A network failure preserves the displayed programme');
check(first.nodes['[data-day-status]'].textContent.includes('conservé'), 'A readable status reports a failed refresh');
failed = false;
response = { html: '<p>Nouveau programme en cours</p>', poll_after: 30 };
document.activeElement = { parent: first.nodes['[data-day-content]'] };
await advance(60000);
check(first.nodes['[data-day-content]'].innerHTML === retained, 'Updating does not destroy a keyboard user’s focused programme link');
document.activeElement = null;
await advance(30000);
check(first.nodes['[data-day-content]'].innerHTML === response.html, 'Content resumes updating after the focused link is left');
const more = first.nodes['[data-day-more]'];
response = { html: '<p>Les dix prochaines émissions</p>', poll_after: 30 };
const beforeMore = calls.length;
more.click();
more.click();
await flush();
check(calls.length === beforeMore + 1 && new URL(calls.at(-1).url).searchParams.get('page') === '2', 'A double click loads exactly one extra page immediately through AJAX');
check(first.nodes['[data-day-content]'].innerHTML === response.html && !more.disabled, 'AJAX replaces the cumulative list and re-enables its button');
await advance(30000);
check(new URL(calls.at(-1).url).searchParams.get('page') === '2', 'Automatic refresh preserves the visitor’s expanded page');
failed = true;
more.click();
await flush();
check(!more.disabled && first.nodes['[data-day-content]'].innerHTML === response.html, 'A failed expansion retains rows and permits retrying');
failed = false;
response = { html: '<p>Toute la journée</p>', has_more: false, poll_after: 30 };
document.activeElement = more;
more.click();
await flush();
check(new URL(calls.at(-1).url).searchParams.get('page') === '3' && more.hidden && document.activeElement === first.nodes['[data-day-status]'], 'Retry uses the same next page and the exhausted button hands focus to the status');
document.activeElement = null;
fixtureDate = '2026-11-22';
response = { html: '<p>Nouvelle journée · cinq prochaines émissions</p>', poll_after: 30 };
await advance(30000);
check(!more.hidden && first.nodes['[data-day-content]'].innerHTML === response.html, 'Midnight restores the initial page and load-more availability');
await advance(30000);
check(new URL(calls.at(-1).url).searchParams.get('page') === '1' && new URL(calls.at(-1).url).searchParams.get('date') === fixtureDate, 'Polling uses the new day and resets the previously expanded page');
const beforeRapid = calls.length;
observers[0].visible(false);
observers[0].visible();
await flush();
check(calls.length === beforeRapid, 'Rapid visibility changes respect the minimum request interval');
const second = root('https://bsc.example/wp-json/bsc-tv/v1/day?show_images=no');
init([{ querySelectorAll: () => [second] }]);
observers[1].visible();
await flush();
check(second.nodes['[data-day-content]'].innerHTML.includes('Compact') && first.nodes['[data-day-content]'].innerHTML === response.html, 'Multiple list widgets retain independent display settings and content');
first.isConnected = false;
observers[0].visible(false);
check(observers[0].disconnected && events.get('visibilitychange').size === 1, 'Removing an Elementor widget cleans up its observer and visibility listener');
const beforeBad = calls.length;
const external = root('https://unrelated.example/wp-json/bsc-tv/v1/day');
init([{ querySelectorAll: () => [external] }]);
await flush();
check(calls.length === beforeBad && observers.length === 2, 'A widget cannot fetch a fragment from another origin');
const plain = root('https://bsc.example/?rest_route=/bsc-tv/v1/day');
init([{ querySelectorAll: () => [plain] }]);
observers[2].visible();
await flush();
check(calls.at(-1).url.includes('rest_route='), 'WordPress plain permalink REST URLs are supported');
const beforeInvalid = plain.nodes['[data-day-content]'].innerHTML;
response = { html: 123, poll_after: 0 };
await advance(60000);
check(plain.nodes['[data-day-content]'].innerHTML === beforeInvalid && plain.nodes['[data-day-status]'].textContent, 'Malformed responses preserve the last valid programme');
const wrongRoute = root('https://bsc.example/wp-json/bsc-tv/v1/import-daily');
const beforeWrong = calls.length;
init([{ querySelectorAll: () => [wrongRoute] }]);
await flush();
check(calls.length === beforeWrong, 'The updater only calls the public day endpoint');
delete window.IntersectionObserver;
response = { html: '<p>Fallback programme</p>', poll_after: 60 };
const fallback = root();
init([{ querySelectorAll: () => [fallback] }]);
await flush();
check(fallback.nodes['[data-day-content]'].innerHTML === response.html, 'Browsers without IntersectionObserver still refresh the list');
check(calls.every(call => new URL(call.url).origin === 'https://bsc.example'), 'The widget never calls a video platform or remote scheduler');
console.log(`OK ${checks} daily-list browser checks.`);

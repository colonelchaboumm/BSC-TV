// Exercise the shipped playlist UI with bounded AJAX doubles; no real API access.
import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const code = await fs.readFile(new URL('../assets/music-admin.js', import.meta.url), 'utf8');
class Element {
  constructor() { this.events = new Map(); this.nodes = new Map(); this.dataset = {}; this.hidden = false; this.disabled = false; this.value = ''; this.textContent = ''; }
  querySelector(selector) { return this.nodes.get(selector) || null; }
  addEventListener(name, fn) { this.events.set(name, fn); }
  fire(name) { this.events.get(name)?.({ preventDefault() {} }); }
}
const playlistURL = 'https://www.youtube.com/playlist?list=PLvLT_VV4Sfx3mwWY9T3Zn5OLLhVo-f7Vo';
const job = changes => ({ token: 'fixture-token', step: 0, done: false, created: 0, assigned: 0, duplicates: 0, unavailable: 0, processed: 0, total: 0, warning: '', ...changes });
const success = data => ({ ok: true, json: async () => ({ success: true, data }) });
function environment(responses, saved = null, endpoint = 'https://bsc.example/wp-admin/admin-ajax.php') {
  const form = new Element();
  form.dataset = { endpoint, nonce: 'fixture-admin-nonce', library: 'https://bsc.example/wp-admin/admin.php?page=bsc-tv-music', job: JSON.stringify(saved || {}) };
  const url = new Element(); url.value = playlistURL; const category = new Element(); category.value = 'morning';
  form.elements = { namedItem: name => name === 'playlist_url' ? url : category };
  for (const name of ['status', 'start', 'resume', 'progress', 'library']) form.nodes.set(`[data-playlist-${name}]`, new Element());
  const calls = []; const timers = new Map(); let sequence = 0;
  const document = { baseURI: 'https://bsc.example/wp-admin/admin.php?page=bsc-tv-music', querySelectorAll: selector => selector === '[data-music-playlist]' ? [form] : [], querySelector: () => null };
  vm.runInNewContext(code, {
    document, URL, URLSearchParams, AbortController, Error, TypeError, location: { origin: 'https://bsc.example' },
    window: { setTimeout: fn => { timers.set(++sequence, fn); return sequence; }, clearTimeout: id => timers.delete(id) },
    fetch: async (target, options) => { calls.push({ target, options, fields: Object.fromEntries(options.body), disabled: url.disabled && category.disabled }); const next = responses.shift(); if (typeof next === 'function') return await next(); if (!next) throw new Error('Unexpected extra AJAX request'); return next; },
  });
  return { form, url, category, calls, timers, node: name => form.querySelector(`[data-playlist-${name}]`) };
}
const flush = async () => { for (let n = 0; n < 50; n++) await Promise.resolve(); };
let checks = 0; const check = (condition, message) => { assert.ok(condition, message); checks++; };
const normal = environment([success(job()), success(job({ step: 1, created: 50, processed: 50, total: 52 })), success(job({ step: 2, done: true, created: 52, processed: 52, total: 52 }))]);
check(normal.calls.length === 0, 'Opening music settings never imports a playlist automatically');
normal.form.fire('submit'); normal.form.fire('submit'); await flush();
check(normal.calls.length === 3, 'One click runs start and successive pages, while a double click cannot start another import');
check(normal.calls[0].fields.url === playlistURL && normal.calls[0].fields.category === 'morning' && normal.calls[0].fields.nonce === 'fixture-admin-nonce', 'The pasted URL and selected category are sent with the administrator nonce');
check(normal.calls.every(call => call.fields.action === 'bsc_tv_music_playlist' && call.options.credentials === 'same-origin' && call.options.method === 'POST' && call.disabled), 'All AJAX mutations use the same-origin endpoint and lock the import form while running');
check(normal.calls[1].fields.step === '0' && normal.calls[2].fields.step === '1', 'Requests follow the server-saved step rather than inventing page tokens');
check(normal.node('status').textContent.includes('52 ajouté(s)') && normal.node('status').textContent.includes('Import terminé'), 'Completion reports actual imported counts');
check(normal.node('progress').max === 52 && normal.node('progress').value === 52 && normal.node('resume').hidden && !normal.node('start').disabled, 'Progress and buttons return to a completed usable state');
check(!normal.node('library').hidden && normal.node('library').href.includes('music_imported=fixture-token') && normal.node('library').href.endsWith('#music-library'), 'A completion link refreshes and opens the clip library');
check(normal.timers.size === 0, 'Finished requests leave no timeout timers running');
const failed = environment([success(job()), { ok: false, json: async () => ({ success: false, data: { message: 'API temporairement indisponible', job: job() } }) }, success(job({ step: 1, done: true, created: 1, processed: 1 }))]);
failed.form.fire('submit'); await flush();
check(!failed.node('resume').hidden && failed.node('status').textContent.includes('API temporairement'), 'API failure exposes a manual resume action with saved progress');
failed.node('resume').fire('click'); await flush();
check(failed.calls.length === 3 && failed.calls[2].fields.operation === 'step' && failed.calls[2].fields.step === '0', 'Resume retries the saved step without creating another job');
const reloaded = environment([success(job({ step: 2, done: true, created: 5, processed: 5 }))], job({ step: 1, created: 4, processed: 4 }));
check(reloaded.calls.length === 0 && !reloaded.node('resume').hidden, 'An unfinished import survives page reload without silently resuming network work');
reloaded.node('resume').fire('click'); await flush();
check(reloaded.calls[0].fields.step === '1' && reloaded.node('status').textContent.includes('5 ajouté(s)'), 'Reloaded progress continues at its persisted position');
const expired = environment([{ ok: false, json: async () => ({ success: false, data: { message: 'Accès refusé ou session expirée.' } }) }]);
expired.form.fire('submit'); await flush();
check(expired.calls.length === 1 && !expired.node('start').disabled && expired.node('status').textContent.includes('session expirée'), 'Expired permissions stop without an automatic retry loop');
const crossOrigin = environment([], null, 'https://evil.example/import'); crossOrigin.form.fire('submit'); await flush();
check(crossOrigin.calls.length === 0 && crossOrigin.node('status').textContent.includes('invalide'), 'The browser refuses cross-origin mutation endpoints');
const stuck = environment([success(job()), success(job())]); stuck.form.fire('submit'); await flush();
check(stuck.calls.length === 2 && !stuck.node('resume').hidden && stuck.node('status').textContent.includes('progression'), 'A non-advancing server response cannot create an endless polling loop');
const malformed = environment([{ ok: true, json: async () => { throw new SyntaxError('Unexpected HTML'); } }]); malformed.form.fire('submit'); await flush();
check(malformed.node('status').textContent.includes('Réponse WordPress illisible') && !malformed.node('status').textContent.includes('SyntaxError'), 'Unexpected WordPress output produces an actionable message');
const timeout = environment([success(job()), () => Promise.reject(Object.assign(new Error('Timeout'), { name: 'AbortError' }))]); timeout.form.fire('submit'); await flush();
check(!timeout.node('resume').hidden && timeout.node('status').textContent.includes('expiré') && timeout.timers.size === 0, 'Timed-out imports keep resumable state and clean up timeout handlers');
const capped = environment([success(job()), success(job({ step: 1, done: true, limited: true, created: 20, processed: 50, total: 100, warning: 'Bibliothèque pleine.' }))]); capped.form.fire('submit'); await flush();
check(capped.node('status').textContent.includes('Limite atteinte') && capped.node('status').textContent.includes('Bibliothèque pleine') && capped.node('progress').value === 50, 'Capacity stops are visible and do not pretend the whole playlist was imported');
console.log(`OK ${checks} music playlist AJAX browser checks (simulated API).`);

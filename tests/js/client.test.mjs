// Runs resources/js/client.js in a simulated browser: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../resources/js/client.js', import.meta.url), 'utf8');
const ORIGIN = 'https://app.test';
const ENDPOINT = `${ORIGIN}/performance-tracking/api/client`;
const TIME_ORIGIN = 1_700_000_000_000;

function browser({ attrs = {}, beacon = true, drift = 0, stored = {} } = {}) {
  let clock = 0; // ms since timeOrigin
  let timers = [];
  let nextTimer = 1;
  const resources = [];
  const beacons = [];
  const fallbackPosts = [];
  const storage = new Map(Object.entries(stored));
  let observer = null;
  const responses = []; // queued for the stub fetch

  class FakeXHR {
    constructor() { this.listeners = []; this.headers = {}; }
    open(method, url) { this.url = url; }
    send() {}
    addEventListener(type, fn) { if (type === 'loadend') this.listeners.push(fn); }
    getResponseHeader(name) { return this.headers[name.toLowerCase()] ?? null; }
    respond(header) { this.headers['x-request-timing'] = header; this.listeners.forEach((fn) => fn()); }
  }

  const ctx = {
    URL, Blob, JSON, Math, Promise,
    location: { href: `${ORIGIN}/page`, origin: ORIGIN },
    document: {
      currentScript: { getAttribute: (n) => ({ 'data-endpoint': ENDPOINT, ...attrs })[n] ?? null },
      readyState: 'complete',
      addEventListener() {},
    },
    performance: {
      timeOrigin: TIME_ORIGIN,
      now: () => clock,
      getEntriesByName: (name) => resources.filter((e) => e.name === name),
      getEntriesByType: (type) => (type === 'navigation' ? [{ fetchStart: 5, requestStart: 20, responseEnd: 120 }] : []),
    },
    PerformanceObserver: class { constructor(cb) { observer = cb; } observe() {} },
    sessionStorage: { getItem: (k) => storage.get(k) ?? null, setItem: (k, v) => storage.set(k, v) },
    navigator: { sendBeacon: (url, blob) => { beacons.push(blob); return beacon; } },
    fetch: (url, init) => {
      if (url === ENDPOINT) { fallbackPosts.push(init); return Promise.resolve({}); }
      const header = responses.shift();
      return Promise.resolve({ headers: { get: (n) => (n === 'x-request-timing' ? header : null) } });
    },
    XMLHttpRequest: FakeXHR,
    setTimeout: (fn, ms) => { const id = nextTimer++; timers.push({ id, fn, at: clock + ms }); return id; },
    clearTimeout: (id) => { timers = timers.filter((t) => t.id !== id); },
    addEventListener() {},
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  // The wall clock follows the page clock, optionally off by `drift` ms.
  vm.runInContext(`Date.now = () => ${TIME_ORIGIN} + performance.now() + ${drift};`, ctx);
  vm.runInContext(SOURCE, ctx);

  return {
    window: ctx,
    advance(ms) {
      clock += ms;
      const due = timers.filter((t) => t.at <= clock);
      timers = timers.filter((t) => t.at > clock);
      due.forEach((t) => t.fn());
    },
    at: (ms) => { clock = ms; },
    resource: (entry) => resources.push(entry),
    observe: (entries) => observer && observer({ getEntries: () => entries }),
    respondWith: (header) => responses.push(header),
    storage,
    fallbackPosts,
    async reports() {
      ctx.RequestTiming.flush();
      const all = [];
      for (const blob of beacons.splice(0)) all.push(...JSON.parse(await blob.text()).timings);
      for (const post of fallbackPosts.splice(0)) all.push(...JSON.parse(post.body).timings);
      return all;
    },
  };
}

const header = (id, t1, t2, node = 'a') => `id=${id};t1=${t1};t2=${t2};n=${node}`;
const tick = () => new Promise((r) => setImmediate(r));

test('a fetch is reported from its Resource Timing entry', async () => {
  const b = browser();
  b.at(100);
  const url = `${ORIGIN}/api/orders`;
  // Server clock 1000 ms ahead; request leaves at 110 (after 10 ms connect),
  // arrives at 140, server works 50 ms, response is done at 200.
  b.resource({ name: url, startTime: 100, requestStart: 110, responseEnd: 200 });
  b.respondWith(header('11111111-1111-4111-8111-111111111111', TIME_ORIGIN + 1140, TIME_ORIGIN + 1190));

  await b.window.fetch(url);
  await tick();

  const [report] = await b.reports();
  assert.equal(report.id, '11111111-1111-4111-8111-111111111111');
  assert.equal(report.t0, TIME_ORIGIN + 110);
  assert.equal(report.t3, TIME_ORIGIN + 200);
  assert.equal(report.connect_ms, 10);
  assert.equal(report.headers_only, false);
  // ((t1-t0)+(t2-t3))/2 = (1030 + 990) / 2: the true 1000 plus half the
  // difference between the legs (30 - 10) / 2, the method's known error.
  assert.equal(report.offset_ms, 1010);
});

test('without a Resource Timing entry a fetch falls back to headers-only timestamps', async () => {
  const b = browser();
  b.at(100);
  b.respondWith(header('22222222-2222-4222-8222-222222222222', TIME_ORIGIN + 120, TIME_ORIGIN + 150));

  await b.window.fetch('/api/slow');
  b.advance(60);
  await tick();
  assert.deepEqual(await b.reports(), [], 'waits for an entry first');

  b.advance(5000);
  const [report] = await b.reports();
  assert.equal(report.t0, TIME_ORIGIN + 100);
  assert.equal(report.headers_only, true);
  assert.equal(report.connect_ms, null);
});

test('an entry delivered later by the observer is used', async () => {
  const b = browser();
  b.at(100);
  const url = `${ORIGIN}/api/late`;
  b.respondWith(header('33333333-3333-4333-8333-333333333333', TIME_ORIGIN + 130, TIME_ORIGIN + 160));

  await b.window.fetch(url);
  await tick();
  b.observe([{ name: url, startTime: 101, requestStart: 105, responseEnd: 190 }]);

  const [report] = await b.reports();
  assert.equal(report.t3, TIME_ORIGIN + 190);
  assert.equal(report.headers_only, false);
});

test('clock offsets are kept per server node', async () => {
  const b = browser();
  const send = async (id, node, skew, at) => {
    b.at(at);
    const url = `${ORIGIN}/api/${id}`;
    // 10 ms each way, 20 ms of server work; the node's clock is `skew` ahead.
    b.resource({ name: url, startTime: at, requestStart: at, responseEnd: at + 40 });
    b.respondWith(header(id, TIME_ORIGIN + at + 10 + skew, TIME_ORIGIN + at + 30 + skew, node));
    await b.window.fetch(url);
    await tick();
  };

  await send('44444444-4444-4444-8444-444444444444', 'a', 0, 100);
  await send('55555555-5555-4555-8555-555555555555', 'b', 25, 1000);

  const [first, second] = await b.reports();
  assert.equal(first.offset_ms, 0);
  assert.equal(second.offset_ms, 25);
  assert.deepEqual(Object.keys(JSON.parse(b.storage.get('performance-tracking:offsets'))).sort(), [`${ORIGIN}|a`, `${ORIGIN}|b`]);
});

test('a reused XHR reports each request once', async () => {
  const b = browser();
  const xhr = new b.window.XMLHttpRequest();

  b.at(100);
  xhr.open('GET', '/api/one');
  xhr.send();
  b.at(150);
  xhr.respond(header('66666666-6666-4666-8666-666666666666', TIME_ORIGIN + 110, TIME_ORIGIN + 120));

  b.at(200);
  xhr.open('GET', '/api/two');
  xhr.send();
  b.at(260);
  xhr.respond(header('77777777-7777-4777-8777-777777777777', TIME_ORIGIN + 210, TIME_ORIGIN + 220));

  b.advance(5000);
  const reports = await b.reports();
  assert.deepEqual(reports.map((r) => [r.id.slice(0, 1), r.t0 - TIME_ORIGIN, r.headers_only]), [['6', 100, false], ['7', 200, false]]);
});

test('a calculating mark expires and measure() never leaks it', async () => {
  const b = browser();
  const id = (n) => `${n}${n}${n}${n}${n}${n}${n}${n}-0000-4000-8000-000000000000`;
  const fetchAt = async (at, n) => {
    b.at(at);
    b.respondWith(header(id(n), TIME_ORIGIN + at + 5, TIME_ORIGIN + at + 10));
    await b.window.fetch('/api/x');
    await tick();
  };

  b.at(0);
  b.window.RequestTiming.markCalculating();
  await fetchAt(30, 1);                       // 30 ms after the mark: counted

  b.at(100);
  b.window.RequestTiming.markCalculating();
  await fetchAt(100 + 61_000, 2);             // over a minute later: stale

  b.at(200_000);
  await b.window.RequestTiming.measure(() => Promise.resolve('no request'));
  await tick();
  await fetchAt(200_500, 3);                  // after measure() settled: not counted

  b.advance(10_000);
  const reports = await b.reports();
  assert.deepEqual(reports.map((r) => r.calc_ms), [30, null, null]);
});

test('offsets are not used when the page clock has drifted from the wall clock', async () => {
  const b = browser({ drift: 5000 });
  b.at(100);
  const url = `${ORIGIN}/api/drift`;
  b.resource({ name: url, startTime: 100, requestStart: 100, responseEnd: 140 });
  b.respondWith(header('88888888-8888-4888-8888-888888888888', TIME_ORIGIN + 110, TIME_ORIGIN + 130));

  await b.window.fetch(url);
  await tick();

  const [report] = await b.reports();
  assert.equal(report.offset_ms, null);
  assert.equal(b.storage.get('performance-tracking:offsets'), undefined);
});

test('the page load is reported with the page node offset', async () => {
  const pageId = '99999999-9999-4999-8999-999999999999';
  // Measured by an earlier page of the visit, against node web-1.
  const b = browser({
    attrs: { 'data-request-id': pageId, 'data-node': 'web-1' },
    stored: { 'performance-tracking:offsets': JSON.stringify({ [`${ORIGIN}|web-1`]: { offset: 42, rtt: 5, at: TIME_ORIGIN } }) },
  });

  b.advance(1);
  const [report] = await b.reports();
  assert.equal(report.id, pageId);
  assert.equal(report.t0, TIME_ORIGIN + 20);
  assert.equal(report.t3, TIME_ORIGIN + 120);
  assert.equal(report.connect_ms, 15);
  assert.equal(report.offset_ms, 42);
});

test('without sendBeacon the fallback is a simple request (no CORS preflight)', async () => {
  const b = browser({ beacon: false });
  b.at(100);
  b.respondWith(header('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', TIME_ORIGIN + 110, TIME_ORIGIN + 120));
  await b.window.fetch('/api/x');
  b.advance(5000);

  b.window.RequestTiming.flush();
  assert.equal(b.fallbackPosts.length, 1);
  assert.equal(b.fallbackPosts[0].headers['Content-Type'], 'text/plain;charset=UTF-8');
  assert.equal(b.fallbackPosts[0].keepalive, true);
});

/*!
 * performance-tracking-times client
 *
 * Wraps fetch and XMLHttpRequest. For every response that carries an
 * X-Request-Timing header it records when the request left (t0) and when the
 * response finished arriving (t3), using the Resource Timing API when it is
 * available, and reports them back so the server can split the network time
 * into its outbound and return legs. When loaded through the Blade tag, it
 * also reports the page load itself (Navigation Timing).
 *
 *   RequestTiming.measure(() => { const body = build(); return fetch(url, { body }) })
 *     -> the time spent before the request is sent is recorded as "calculating".
 */
(function () {
  'use strict';

  if (typeof window === 'undefined' || window.RequestTiming) return;

  var HEADER = 'x-request-timing';
  var STORE_KEY = 'performance-tracking:offsets';
  var OFFSET_TTL_MS = 10 * 60 * 1000;
  var CALC_TTL_MS = 60 * 1000;        // an older calculating mark is stale
  var MAX_DRIFT_MS = 1000;            // monotonic vs wall clock disagreement we tolerate
  var MATCH_BEFORE_MS = 50;           // Resource Timing entry may start this much before the call...
  var MATCH_AFTER_MS = 1000;          // ...or this much after it
  var ENTRY_WAIT_MS = 5000;           // then fall back to our own timestamps
  var FLUSH_DELAY_MS = 2000;
  var FLUSH_SIZE = 20;
  var BATCH_SIZE = 50;                // the server accepts at most 50 per report

  var script = document.currentScript;
  var endpoint = (window.RequestTimingConfig && window.RequestTimingConfig.endpoint) ||
    (script && script.getAttribute('data-endpoint'));

  if (!endpoint) return;

  var hasPerf = !!(window.performance && performance.now && performance.timeOrigin);
  var origFetch = window.fetch;
  var endpointUrl = absolute(endpoint);
  var queue = [];
  var pending = [];
  var flushTimer = null;
  var calcStart = null;
  var used = {};
  var usedKeys = [];
  var offsets = loadOffsets();

  function now() {
    return hasPerf ? performance.timeOrigin + performance.now() : Date.now();
  }

  // performance.now() can stand still while the machine sleeps, leaving
  // timeOrigin + now() behind the wall clock. Offsets measured against such
  // a clock are not stored or reused.
  function clockOk() {
    return !hasPerf || Math.abs(Date.now() - now()) < MAX_DRIFT_MS;
  }

  function absolute(url) {
    try { return new URL(url, location.href).href; } catch (e) { return String(url); }
  }

  function parse(value) {
    if (!value) return null;
    var out = {};
    value.split(';').forEach(function (part) {
      var i = part.indexOf('=');
      if (i > 0) out[part.slice(0, i).trim()] = part.slice(i + 1).trim();
    });
    return out.id ? { id: out.id, t1: parseFloat(out.t1), t2: parseFloat(out.t2), node: out.n || '' } : null;
  }

  // Each server behind a load balancer has its own clock, so offsets are
  // kept per origin and node.
  function offsetKey(url, node) {
    var origin;
    try { origin = new URL(url, location.href).origin; } catch (e) { origin = location.origin; }
    return origin + '|' + (node || '');
  }

  function loadOffsets() {
    try {
      var stored = JSON.parse(sessionStorage.getItem(STORE_KEY)) || {};
      var fresh = {};
      for (var key in stored) {
        if (stored[key] && Math.abs(Date.now() - stored[key].at) < OFFSET_TTL_MS) fresh[key] = stored[key];
      }
      return fresh;
    } catch (e) { /* storage unavailable */ }
    return {};
  }

  // Keep the offset measured on the fastest exchange: the less time spent
  // on the wire, the less room for the two legs to differ. `at` is wall
  // clock time, so the TTL holds across pages and sleep.
  function updateOffset(key, t0, t1, t2, t3) {
    if (!clockOk()) return;
    var rtt = (t3 - t0) - (t2 - t1);
    if (!(rtt >= 0)) return;
    var best = offsets[key];
    var wall = Date.now();
    if (!best || rtt <= best.rtt || wall - best.at > OFFSET_TTL_MS) {
      offsets[key] = { offset: ((t1 - t0) + (t2 - t3)) / 2, rtt: rtt, at: wall };
      try { sessionStorage.setItem(STORE_KEY, JSON.stringify(offsets)); } catch (e) { /* ignore */ }
    }
  }

  function offsetFor(key) {
    var o = offsets[key];
    return o && clockOk() && Date.now() - o.at < OFFSET_TTL_MS ? round(o.offset) : null;
  }

  function begin(url) {
    var meta = { url: absolute(url), calledAt: now(), calc: null };
    if (calcStart !== null) {
      var elapsed = meta.calledAt - calcStart;
      if (elapsed >= 0 && elapsed <= CALC_TTL_MS) meta.calc = elapsed;
      calcStart = null;
    }
    return meta;
  }

  // complete: whether "now" is when the whole response arrived (XHR
  // loadend) or only its headers (fetch resolving).
  function arrived(meta, header, complete) {
    var server = parse(header);
    if (!server || meta.url === endpointUrl) return;
    meta.server = server;
    meta.arrivedAt = now();
    meta.complete = complete;

    var entry = findEntry(meta, hasPerf && performance.getEntriesByName ? performance.getEntriesByName(meta.url, 'resource') : []);
    if (entry) return finish(meta, entry);

    pending.push(meta);
    // Fall back to our own timestamps if no resource entry shows up.
    meta.timer = setTimeout(function () { take(meta); finish(meta, null); }, ENTRY_WAIT_MS);
  }

  function findEntry(meta, entries) {
    if (!entries || !entries.length) return null;
    var start = meta.calledAt - performance.timeOrigin;
    var match = null;
    for (var i = 0; i < entries.length; i++) {
      var e = entries[i];
      if (e.name !== meta.url || e.responseEnd === 0 || used[e.name + '@' + e.startTime]) continue;
      if (e.startTime < start - MATCH_BEFORE_MS || e.startTime > start + MATCH_AFTER_MS) continue;
      if (!match || Math.abs(e.startTime - start) < Math.abs(match.startTime - start)) match = e;
    }
    if (match) {
      used[match.name + '@' + match.startTime] = true;
      usedKeys.push(match.name + '@' + match.startTime);
      if (usedKeys.length > 500) delete used[usedKeys.shift()];
    }
    return match;
  }

  function take(meta) {
    var i = pending.indexOf(meta);
    if (i >= 0) pending.splice(i, 1);
    clearTimeout(meta.timer);
  }

  function finish(meta, entry) {
    var t0 = meta.calledAt;
    var t3 = meta.arrivedAt;
    var connect = null;
    var headersOnly = !meta.complete;

    if (entry) {
      var o = performance.timeOrigin;
      // requestStart is 0 for cross-origin responses without Timing-Allow-Origin.
      // Everything between the call and the first byte sent (browser queue,
      // DNS, TCP, TLS) is reported as "connect".
      if (entry.requestStart > 0) {
        t0 = o + entry.requestStart;
        connect = Math.max(0, t0 - meta.calledAt);
      }
      t3 = o + entry.responseEnd;
      headersOnly = false;
    }

    var s = meta.server;
    var key = offsetKey(meta.url, s.node);
    updateOffset(key, t0, s.t1, s.t2, t3);

    queue.push({
      id: s.id,
      t0: round(t0),
      t3: round(t3),
      calc_ms: meta.calc === null ? null : round(meta.calc),
      connect_ms: connect === null ? null : round(connect),
      offset_ms: offsetFor(key),
      headers_only: headersOnly
    });
    schedule();
  }

  function round(n) { return Math.round(n * 1000) / 1000; }

  function schedule() {
    if (queue.length >= FLUSH_SIZE) return flush();
    if (!flushTimer) flushTimer = setTimeout(flush, FLUSH_DELAY_MS);
  }

  function flush() {
    clearTimeout(flushTimer);
    flushTimer = null;
    if (!queue.length) return;
    var body = JSON.stringify({ timings: queue.splice(0, BATCH_SIZE) });
    // text/plain keeps the request "simple": no CORS preflight, for the
    // beacon and for the fetch fallback alike.
    var sent = navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
    if (!sent && origFetch) {
      origFetch.call(window, endpoint, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })
        .catch(function () { /* ignore */ });
    }
    if (queue.length) schedule();
  }

  if (hasPerf && window.PerformanceObserver) {
    try {
      new PerformanceObserver(function (list) {
        if (!pending.length) return;
        var entries = list.getEntries();
        pending.slice().forEach(function (meta) {
          var entry = findEntry(meta, entries);
          if (entry) { take(meta); finish(meta, entry); }
        });
      }).observe({ type: 'resource', buffered: false });
    } catch (e) { /* unsupported */ }
  }

  if (origFetch) {
    window.fetch = function (input, init) {
      var url = typeof input === 'string' || input instanceof URL ? input : input && input.url;
      var meta = begin(url);
      return origFetch.apply(this, arguments).then(function (res) {
        try { arrived(meta, res.headers.get(HEADER), false); } catch (e) { /* never break the app */ }
        return res;
      });
    };
  }

  if (window.XMLHttpRequest) {
    var open = XMLHttpRequest.prototype.open;
    var send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__perfUrl = url;
      return open.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
      var xhr = this;
      if (xhr.__perfUrl !== undefined) {
        // One listener per object, reading the current send's meta: an XHR
        // reused for several requests must not report the earlier ones again.
        xhr.__perfMeta = begin(xhr.__perfUrl);
        if (!xhr.__perfListening) {
          xhr.__perfListening = true;
          xhr.addEventListener('loadend', function () {
            var meta = xhr.__perfMeta;
            xhr.__perfMeta = null;
            if (!meta) return;
            try { arrived(meta, xhr.getResponseHeader(HEADER), true); } catch (e) { /* never break the app */ }
          });
        }
      }
      return send.apply(this, arguments);
    };
  }

  // The page itself. A server-rendered page has no response header the
  // script can read, so the Blade tag carries the page's timing id, and the
  // Navigation Timing entry says when its request left and its HTML arrived.
  var pageId = script && script.getAttribute('data-request-id');
  var pageNode = script && script.getAttribute('data-node');
  if (pageId && hasPerf && performance.getEntriesByType) {
    var reportPage = function () {
      var nav = performance.getEntriesByType('navigation')[0];
      if (!nav || !(nav.requestStart > 0) || !(nav.responseEnd > 0)) return;
      var o = performance.timeOrigin;
      queue.push({
        id: pageId,
        t0: round(o + nav.requestStart),
        t3: round(o + nav.responseEnd),
        calc_ms: null,
        connect_ms: round(Math.max(0, nav.requestStart - nav.fetchStart)),
        offset_ms: offsetFor(offsetKey(location.href, pageNode)),
        headers_only: false
      });
      schedule();
    };
    if (document.readyState === 'complete') setTimeout(reportPage, 0);
    else window.addEventListener('load', function () { setTimeout(reportPage, 0); });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);

  window.RequestTiming = {
    /** Marks the start of client-side work; the next request (within a minute) records it as "calculating". */
    markCalculating: function () { calcStart = now(); },
    /**
     * Runs fn and records the time until the first request it sends as
     * "calculating". The mark is dropped once fn (or the promise it returns)
     * settles, so it never leaks to an unrelated later request.
     */
    measure: function (fn) {
      var mark = calcStart = now();
      var clear = function () { if (calcStart === mark) calcStart = null; };
      var result;
      try { result = fn(); } catch (e) { clear(); throw e; }
      if (result && typeof result.then === 'function') result.then(clear, clear);
      else clear();
      return result;
    },
    flush: flush
  };
})();

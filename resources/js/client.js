/*!
 * performance-tracking-times client
 *
 * Wraps fetch and XMLHttpRequest. For every response that carries an
 * X-Request-Timing header it records when the request left (t0) and when the
 * response finished arriving (t3), using the Resource Timing API when it is
 * available, and reports them back so the server can split the network time
 * into its outbound and return legs.
 *
 *   RequestTiming.measure(() => { const body = build(); return fetch(url, { body }) })
 *     -> the time spent before the request is sent is recorded as "calculating".
 */
(function () {
  'use strict';

  if (typeof window === 'undefined' || window.RequestTiming) return;

  var HEADER = 'x-request-timing';
  var STORE_KEY = 'performance-tracking:offset';
  var OFFSET_TTL_MS = 10 * 60 * 1000;
  var script = document.currentScript;
  var endpoint = (window.RequestTimingConfig && window.RequestTimingConfig.endpoint) ||
    (script && script.getAttribute('data-endpoint'));

  if (!endpoint) return;

  var hasPerf = !!(window.performance && performance.now && performance.timeOrigin);
  var origFetch = window.fetch;
  var queue = [];
  var pending = [];
  var flushTimer = null;
  var calcStart = null;
  var used = {};
  var usedKeys = [];
  var best = loadOffset();

  function now() {
    return hasPerf ? performance.timeOrigin + performance.now() : Date.now();
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
    return out.id ? { id: out.id, t1: parseFloat(out.t1), t2: parseFloat(out.t2) } : null;
  }

  function loadOffset() {
    try {
      var o = JSON.parse(sessionStorage.getItem(STORE_KEY));
      if (o && now() - o.at < OFFSET_TTL_MS) return o;
    } catch (e) { /* storage unavailable */ }
    return null;
  }

  // Keep the offset measured on the fastest exchange: the less time spent
  // on the wire, the less room for the two legs to differ.
  function updateOffset(t0, t1, t2, t3) {
    var rtt = (t3 - t0) - (t2 - t1);
    if (rtt < 0) return;
    if (!best || rtt <= best.rtt || now() - best.at > OFFSET_TTL_MS) {
      best = { offset: ((t1 - t0) + (t2 - t3)) / 2, rtt: rtt, at: now() };
      try { sessionStorage.setItem(STORE_KEY, JSON.stringify(best)); } catch (e) { /* ignore */ }
    }
  }

  function begin(url) {
    var meta = { url: absolute(url), calledAt: now(), calc: null };
    if (calcStart !== null) {
      meta.calc = Math.max(0, meta.calledAt - calcStart);
      calcStart = null;
    }
    return meta;
  }

  function arrived(meta, header, complete) {
    var server = parse(header);
    if (!server || meta.url === absolute(endpoint)) return;
    meta.server = server;
    meta.arrivedAt = now();
    meta.complete = complete;

    var entry = findEntry(meta, hasPerf ? performance.getEntriesByName(meta.url, 'resource') : []);
    if (entry) return finish(meta, entry);

    pending.push(meta);
    // Fall back to our own timestamps if no resource entry shows up.
    meta.timer = setTimeout(function () { take(meta); finish(meta, null); }, 5000);
  }

  function findEntry(meta, entries) {
    if (!entries.length) return null;
    var start = meta.calledAt - performance.timeOrigin;
    var match = null;
    for (var i = 0; i < entries.length; i++) {
      var e = entries[i];
      if (e.name !== meta.url || e.responseEnd === 0 || used[e.name + '@' + e.startTime]) continue;
      if (e.startTime < start - 50 || e.startTime > start + 1000) continue;
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
    }

    var s = meta.server;
    updateOffset(t0, s.t1, s.t2, t3);

    queue.push({
      id: s.id,
      t0: round(t0),
      t3: round(t3),
      calc_ms: meta.calc === null ? null : round(meta.calc),
      connect_ms: connect === null ? null : round(connect),
      offset_ms: best ? round(best.offset) : null
    });
    schedule();
  }

  function round(n) { return Math.round(n * 1000) / 1000; }

  function schedule() {
    if (queue.length >= 20) return flush();
    if (!flushTimer) flushTimer = setTimeout(flush, 2000);
  }

  function flush() {
    clearTimeout(flushTimer);
    flushTimer = null;
    if (!queue.length) return;
    var body = JSON.stringify({ timings: queue.splice(0, 50) });
    // text/plain keeps sendBeacon free of a CORS preflight.
    var sent = navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'text/plain' }));
    if (!sent && origFetch) {
      origFetch.call(window, endpoint, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'application/json' } })
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
        var meta = begin(xhr.__perfUrl);
        xhr.addEventListener('loadend', function () {
          try { arrived(meta, xhr.getResponseHeader(HEADER), true); } catch (e) { /* never break the app */ }
        });
      }
      return send.apply(this, arguments);
    };
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);

  window.RequestTiming = {
    /** Marks the start of client-side work; the next request records it as "calculating". */
    markCalculating: function () { calcStart = now(); },
    /** Runs fn and records the time until the first request it sends as "calculating". */
    measure: function (fn) { calcStart = now(); return fn(); },
    flush: flush
  };
})();

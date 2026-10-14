<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Request timings')</title>
    <style>
        :root {
            color-scheme: light;
            --page: #f9f9f7;
            --surface: #fcfcfb;
            --surface-2: #f3f2ee;
            --ink: #0b0b0b;
            --ink-2: #52514e;
            --muted: #898781;
            --grid: #e1e0d9;
            --axis: #c3c2b7;
            --border: rgba(11, 11, 11, 0.10);
            --accent: #2a78d6;
            --kind-network: #2a78d6;
            --kind-calc: #eb6834;
            --kind-delay: #1baf7a;
            --kind-service: #eda100;
            --status-good: #006300;
            --status-warning: #8a5a00;
            --status-critical: #d03b3b;
        }
        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                color-scheme: dark;
                --page: #0d0d0d;
                --surface: #1a1a19;
                --surface-2: #232321;
                --ink: #ffffff;
                --ink-2: #c3c2b7;
                --muted: #898781;
                --grid: #2c2c2a;
                --axis: #383835;
                --border: rgba(255, 255, 255, 0.10);
                --accent: #3987e5;
                --kind-network: #3987e5;
                --kind-calc: #d95926;
                --kind-delay: #199e70;
                --kind-service: #c98500;
                --status-good: #0ca30c;
                --status-warning: #fab219;
                --status-critical: #e66767;
            }
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #0d0d0d;
            --surface: #1a1a19;
            --surface-2: #232321;
            --ink: #ffffff;
            --ink-2: #c3c2b7;
            --muted: #898781;
            --grid: #2c2c2a;
            --axis: #383835;
            --border: rgba(255, 255, 255, 0.10);
            --accent: #3987e5;
            --kind-network: #3987e5;
            --kind-calc: #d95926;
            --kind-delay: #199e70;
            --kind-service: #c98500;
            --status-good: #0ca30c;
            --status-warning: #fab219;
            --status-critical: #e66767;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--page);
            color: var(--ink);
            font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 64px; }
        header.top { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        header.top h1 { font-size: 20px; margin: 0; font-weight: 600; }
        .muted { color: var(--muted); }
        .ink-2 { color: var(--ink-2); }
        .num { font-variant-numeric: tabular-nums; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
        .card + .card, .stack-gap > * + * { margin-top: 16px; }
        h2 { font-size: 15px; font-weight: 600; margin: 0 0 12px; }

        /* Stat tiles */
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; }
        .tiles > .card { margin-top: 0; } /* not the stacked-card spacing: tiles sit side by side */
        .tile .label { color: var(--ink-2); font-size: 13px; }
        .tile .value { font-size: 24px; font-weight: 600; margin-top: 2px; }
        .tile { display: flex; flex-direction: column; gap: 8px; }
        .pct { display: flex; gap: 20px; margin: 0; }
        .pct div { display: grid; gap: 1px; }
        .pct dt { font-size: 12px; color: var(--muted); }
        .pct dd { margin: 0; font-size: 22px; font-weight: 600; line-height: 1.15; white-space: nowrap; }
        .tail { align-self: flex-start; font-size: 12px; padding: 2px 9px; border-radius: 6px; border: 1px solid transparent; }
        .tail b { font-weight: 700; }
        .tail-steady { color: var(--status-good); background: color-mix(in srgb, var(--status-good) 12%, transparent); border-color: color-mix(in srgb, var(--status-good) 30%, transparent); }
        .tail-uneven { color: var(--status-warning); background: color-mix(in srgb, var(--status-warning) 14%, transparent); border-color: color-mix(in srgb, var(--status-warning) 35%, transparent); }
        .tail-long { color: var(--status-critical); background: color-mix(in srgb, var(--status-critical) 12%, transparent); border-color: color-mix(in srgb, var(--status-critical) 30%, transparent); }
        .tail-none { color: var(--muted); border-color: var(--border); }

        /* Stacked bar: 2px surface gap between segments, rounded outer ends */
        .bar { display: flex; gap: 2px; height: 12px; min-width: 2px; }
        .bar.lg { height: 28px; }
        .bar .seg { min-width: 2px; height: 100%; }
        .bar .seg:first-child { border-radius: 4px 0 0 4px; }
        .bar .seg:last-child { border-radius: 0 4px 4px 0; }
        .bar .seg:only-child { border-radius: 4px; }
        .k-network { background: var(--kind-network); }
        .k-calc { background: var(--kind-calc); }
        .k-delay { background: var(--kind-delay); }
        .k-service { background: var(--kind-service); }

        .legend { display: flex; flex-wrap: wrap; gap: 6px 18px; margin-top: 12px; color: var(--ink-2); font-size: 13px; }
        .legend .sw { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; vertical-align: -1px; }
        .legend b { color: var(--ink); font-weight: 600; margin-left: 4px; }

        /* Tables */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--grid); vertical-align: middle; white-space: nowrap; }
        th { font-weight: 500; color: var(--ink-2); font-size: 13px; }
        tbody tr:hover { background: var(--surface-2); }
        td.right, th.right { text-align: right; }
        td.path { max-width: 380px; overflow: hidden; text-overflow: ellipsis; }

        .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid var(--border); }
        .s-2 { color: var(--status-good); }
        .s-3 { color: var(--ink-2); }
        .s-4 { color: var(--status-warning); }
        .s-5 { color: var(--status-critical); }

        form.filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        form.filters input[type=search], form.filters select {
            background: var(--surface); color: var(--ink); border: 1px solid var(--border);
            border-radius: 8px; padding: 6px 10px; font: inherit;
        }
        form.filters input[type=search] { flex: 1 1 220px; min-width: 0; }
        button, .btn { background: var(--surface-2); color: var(--ink); border: 1px solid var(--border); border-radius: 8px; padding: 6px 12px; font: inherit; cursor: pointer; }

        nav.pager { display: flex; gap: 12px; justify-content: flex-end; align-items: center; margin-top: 12px; }
        .h2-note { font-size: 13px; font-weight: 400; margin-left: 6px; }

        /* Live updates */
        .live-bar { display: flex; align-items: center; gap: 8px; }
        .actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .actions form { margin: 0; }
        .live { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--ink-2); font-variant-numeric: tabular-nums; }
        .live .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--status-good); }
        .live[data-state="paused"] .dot, .live[data-state="done"] .dot { background: var(--muted); }
        .live[data-state="error"] .dot { background: var(--status-warning); }
        .live[data-state="off"] .dot { background: var(--status-critical); }
        .btn-sm { font-size: 12px; padding: 3px 10px; }
        tr.is-new td { animation: pt-new 2.4s ease-out; }
        @keyframes pt-new { from { background: color-mix(in srgb, var(--accent) 18%, transparent); } to { background: transparent; } }
        @media (prefers-reduced-motion: no-preference) {
            .live[data-state="live"] .dot { animation: pt-pulse 2s ease-in-out infinite; }
            @keyframes pt-pulse { 50% { opacity: .35; } }
        }
        @media (prefers-reduced-motion: reduce) {
            tr.is-new td { animation: none; background: color-mix(in srgb, var(--accent) 10%, transparent); }
        }
        button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        #tip {
            position: fixed; pointer-events: none; z-index: 10; display: none; max-width: 420px;
            background: var(--surface); color: var(--ink); border: 1px solid var(--border);
            border-radius: 8px; padding: 8px 10px; font-size: 13px; box-shadow: 0 6px 24px rgba(0,0,0,.18);
            white-space: pre-wrap; word-break: break-word;
        }
        #tip strong { display: block; }

        @media (max-width: 640px) {
            .tile .value { font-size: 20px; }
            .pct dd { font-size: 19px; }
            .hide-sm { display: none; }
        }
    </style>
    @stack('head')
</head>
<body>
<div class="wrap">
    @yield('content')
</div>
<div id="tip" role="tooltip"></div>
<script>
    (function () {
        var tip = document.getElementById('tip');
        document.addEventListener('mousemove', function (e) {
            var el = e.target.closest && e.target.closest('[data-tip]');
            if (!el) { tip.style.display = 'none'; return; }
            tip.innerHTML = '';
            var title = document.createElement('strong');
            title.textContent = el.getAttribute('data-tip');
            tip.appendChild(title);
            if (el.getAttribute('data-tip-detail')) tip.appendChild(document.createTextNode(el.getAttribute('data-tip-detail')));
            tip.style.display = 'block';
            var x = Math.min(e.clientX + 14, window.innerWidth - tip.offsetWidth - 8);
            var y = e.clientY + 16 + tip.offsetHeight > window.innerHeight ? e.clientY - tip.offsetHeight - 10 : e.clientY + 16;
            tip.style.left = Math.max(8, x) + 'px';
            tip.style.top = y + 'px';
        });
    })();

    // Live updates: the element with data-live names a URL that answers
    // {html: {elementId: html}, done: bool}. Polled while the tab is visible.
    (function () {
        var root = document.querySelector('[data-live]');
        if (!root || !window.fetch) return;

        var url = root.getAttribute('data-live');
        var every = Math.max(2, Number(root.getAttribute('data-live-every')) || 5) * 1000;
        var doneLabel = root.getAttribute('data-live-done') || 'Up to date';
        var status = document.getElementById('live-status');
        var toggle = document.getElementById('live-toggle');
        var KEY = 'performance-tracking:live';
        var paused = false;
        var stopped = false;
        var failures = 0;
        var timer = null;
        var busy = false;

        try { paused = localStorage.getItem(KEY) === 'paused'; } catch (e) { /* storage unavailable */ }

        function show(state, text) {
            if (!status) return;
            status.setAttribute('data-state', state);
            status.querySelector('.t').textContent = text;
        }

        function schedule(ms) {
            clearTimeout(timer);
            timer = null;
            if (!paused && !stopped && !document.hidden) timer = setTimeout(poll, ms);
        }

        // Rows carry data-row-id; ids not on the page before are highlighted.
        function apply(html) {
            Object.keys(html).forEach(function (id) {
                var el = document.getElementById(id);
                if (!el) return;
                var seen = {};
                el.querySelectorAll('[data-row-id]').forEach(function (n) { seen[n.getAttribute('data-row-id')] = true; });
                el.innerHTML = html[id];
                el.querySelectorAll('[data-row-id]').forEach(function (n) {
                    if (!seen[n.getAttribute('data-row-id')]) n.classList.add('is-new');
                });
            });
        }

        function poll() {
            if (busy || paused || stopped) return;
            busy = true;
            fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (response) {
                    if (response.status === 401 || response.status === 403 || response.status === 419) {
                        stopped = true;
                        throw new Error('signed out');
                    }
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    busy = false;
                    failures = 0;
                    apply(data.html || {});
                    if (data.done) {
                        stopped = true;
                        show('done', doneLabel);
                        if (toggle) toggle.hidden = true;
                        return;
                    }
                    show('live', 'Live · updated ' + new Date().toLocaleTimeString());
                    schedule(every);
                })
                .catch(function () {
                    busy = false;
                    if (stopped) {
                        show('off', 'Signed out. Reload the page.');
                        if (toggle) toggle.hidden = true;
                        return;
                    }
                    failures++;
                    show('error', 'Connection lost. Retrying…');
                    schedule(Math.min(60000, every * Math.pow(2, failures)));
                });
        }

        function setPaused(value, initial) {
            paused = value;
            if (toggle) {
                toggle.textContent = paused ? 'Resume' : 'Pause';
                toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
            }
            if (paused) {
                schedule(0);
                show('paused', 'Paused');
            } else if (initial) {
                schedule(every); // the page itself is fresh
            } else {
                poll();
            }
        }

        if (toggle) toggle.addEventListener('click', function () {
            setPaused(!paused, false);
            try { localStorage.setItem(KEY, paused ? 'paused' : 'live'); } catch (e) { /* ignore */ }
        });

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) schedule(0);
            else if (!paused && !stopped) poll();
        });

        setPaused(paused, true);
    })();
</script>
</body>
</html>

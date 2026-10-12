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
        .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
        .tile .label { color: var(--ink-2); font-size: 13px; }
        .tile .value { font-size: 24px; font-weight: 600; margin-top: 2px; }

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

        #tip {
            position: fixed; pointer-events: none; z-index: 10; display: none; max-width: 420px;
            background: var(--surface); color: var(--ink); border: 1px solid var(--border);
            border-radius: 8px; padding: 8px 10px; font-size: 13px; box-shadow: 0 6px 24px rgba(0,0,0,.18);
            white-space: pre-wrap; word-break: break-word;
        }
        #tip strong { display: block; }

        @media (max-width: 640px) {
            .tile .value { font-size: 20px; }
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
</script>
</body>
</html>

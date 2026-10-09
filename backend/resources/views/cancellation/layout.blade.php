<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $shopName }}</title>
    {{--
        Visual reference: docs/design/3.png. Same tokens as
        frontend/src/styles/barber-theme.css, repeated here because a page
        whose URL carries a signature loads no external font, stylesheet or
        script (see CancellationPageHeaders).
    --}}
    <style>
        :root {
            --black: #1c1a17; --brass: #b08d57; --cream: #f3ead9; --maroon: #6e1423; --maroon-dark: #4c0e18;
            --surface: #fbf6ec; --surface-raised: #fffdf8; --surface-muted: #f1e8d6; --surface-danger: #f6dfdc; --surface-success: #dcebd6;
            --border-soft: #e2d5bc; --border-strong: #b9a98c; --text-muted: #5f574b; --text-on-dark: #fffaf0;
            --danger: #a3172c; --success: #2f6b3a;
            --font-display: 'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif;
            --font-body: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --shadow-soft: 0 1px 2px rgba(28, 26, 23, .06), 0 6px 18px rgba(28, 26, 23, .07);
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(circle at 15% 0%, rgba(176, 141, 87, .1), transparent 45%), var(--cream); color: var(--black); font-family: var(--font-body); line-height: 1.5; }
        .page-header { border-bottom: 1px solid rgba(176, 141, 87, .45); }
        .page-header-inner { display: flex; align-items: center; gap: 1rem; max-width: 60rem; margin: 0 auto; padding: .9rem 1.25rem; }
        .brand { display: inline-flex; align-items: center; gap: .65rem; font-family: var(--font-display); font-size: 1.7rem; font-weight: 700; line-height: 1.1; }
        main { max-width: 40rem; margin: 2.25rem auto; padding: 0 1rem; }
        .card { padding: 2rem 2.25rem; text-align: center; background: var(--surface-raised); border: 1px solid var(--border-soft); border-radius: 10px; box-shadow: var(--shadow-soft); }
        .status-icon { display: inline-grid; place-items: center; width: 4.5rem; height: 4.5rem; margin-bottom: .75rem; border-radius: 50%; background: var(--surface-muted); color: var(--black); }
        .status-icon--danger { background: var(--surface-danger); color: var(--maroon); }
        .status-icon--success { background: var(--surface-success); color: var(--success); }
        h1 { margin: 0; font-family: var(--font-display); font-size: clamp(1.7rem, 5vw, 2.3rem); line-height: 1.15; letter-spacing: -.01em; }
        .lead { margin: .35rem 0 1.25rem; color: var(--text-muted); font-size: 1.05rem; }
        .summary { display: grid; gap: .9rem; margin: 0 0 1rem; padding: 1.1rem 1.25rem; text-align: left; background: var(--surface); border: 1px solid var(--border-soft); border-radius: 10px; }
        .summary-row { display: flex; align-items: center; gap: .9rem; }
        .summary-icon { display: inline-grid; place-items: center; flex-shrink: 0; width: 2.75rem; height: 2.75rem; border-radius: 50%; background: var(--surface-muted); }
        .summary-row dl, .summary-row dd { margin: 0; }
        .summary-main { font-family: var(--font-display); font-size: 1.12rem; font-weight: 700; overflow-wrap: anywhere; }
        .summary-sub { color: var(--text-muted); font-size: .92rem; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .reference { margin: 0 0 1.5rem; color: var(--text-muted); font-size: .88rem; }
        .reference code { font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace; overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; margin: 0; }
        .actions > * { flex: 1 1 13rem; }
        .actions form { margin: 0; display: flex; }
        .button { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 2.9rem; padding: .6rem 1.4rem; font-family: var(--font-display); font-size: 1.08rem; font-weight: 700; line-height: 1.2; text-decoration: none; border-radius: 8px; cursor: pointer; transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
        .button--primary { background: var(--maroon); color: var(--text-on-dark); border: 1px solid var(--maroon-dark); box-shadow: 0 2px 6px rgba(76, 14, 24, .25); }
        .button--primary:hover { background: var(--maroon-dark); }
        .button--secondary { background: var(--surface-raised); color: var(--black); border: 1px solid var(--border-strong); }
        .button--secondary:hover { border-color: var(--maroon); color: var(--maroon-dark); }
        .button--danger { background: var(--surface-raised); color: var(--danger); border: 1px solid var(--danger); }
        .button--danger:hover { background: var(--danger); color: var(--text-on-dark); }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--brass); outline-offset: 2px; }
        .actions--single { max-width: 22rem; margin: 0 auto; }
        footer { text-align: center; font-size: .85rem; color: var(--text-muted); margin: 0 1rem 2rem; }
        @media (max-width: 560px) {
            .brand { font-size: 1.35rem; }
            main { margin-top: 1.25rem; padding: 0 .75rem; }
            .card { padding: 1.5rem 1rem; }
            .status-icon { width: 3.75rem; height: 3.75rem; }
            .actions { flex-direction: column; }
            .actions > * { flex-basis: auto; }
        }
        @media (prefers-reduced-motion: reduce) { .button { transition: none; } }
    </style>
</head>
<body>
    <header class="page-header">
        <div class="page-header-inner">
            <span class="brand">@include('cancellation.icon', ['name' => 'pole', 'size' => 40]){{ $shopName }}</span>
        </div>
    </header>
    <main>
        <div class="card">
            @yield('content')
        </div>
    </main>
    @if ($shopPhone)
        <footer>{{ __('booking.mail.contact', ['phone' => $shopPhone]) }}</footer>
    @endif
</body>
</html>

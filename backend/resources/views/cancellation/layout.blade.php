<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $shopName }}</title>
    {{--
        Same identity as the public pages: ink header with the barber-pole
        band, cream paper, maroon actions, brass details. The values repeat
        frontend/src/styles/tokens.css because a page whose URL carries a
        signature loads no external font, stylesheet or script (see
        CancellationPageHeaders) — keep both in step.
    --}}
    <style>
        :root {
            --black: #1c1a17; --brass: #b08d57; --brass-deep: #7a5a2c; --brass-light: #d9c39c; --cream: #f4ecdc; --maroon: #6e1423; --maroon-dark: #4c0e18;
            --surface: #faf4e8; --surface-raised: #fffdf7; --surface-muted: #efe5d2; --surface-danger: #f7e3e0; --surface-success: #e3efdc;
            --border-soft: #e2d5bc; --border-control: #9c8a63; --border-on-dark: rgba(176, 141, 87, .45);
            --text-muted: #5b5246; --text-on-dark: #fffaf0; --text-on-dark-muted: #cdbfa6;
            --danger: #a3172c; --success: #2f6b3a;
            --font-display: 'Iowan Old Style', 'Palatino Linotype', Palatino, 'Book Antiqua', Georgia, serif;
            --font-body: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            --font-mono: ui-monospace, 'Cascadia Mono', Consolas, monospace;
            --shadow-soft: 0 1px 2px rgba(28, 26, 23, .05), 0 4px 14px rgba(28, 26, 23, .06);
            --shadow-action: 0 2px 5px rgba(76, 14, 24, .22);
            color-scheme: light;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { display: flex; flex-direction: column; margin: 0; min-height: 100vh; min-height: 100dvh; background: var(--cream); color: var(--black); font-family: var(--font-body); line-height: 1.55; }
        .page-header { color: var(--text-on-dark); background: var(--black); border-bottom: 1px solid var(--brass); }
        .pole-stripe { height: 5px; background: repeating-linear-gradient(-45deg, var(--maroon) 0 10px, var(--cream) 10px 20px, var(--black) 20px 30px, var(--cream) 30px 40px); }
        .page-header-inner { display: flex; align-items: center; max-width: 72rem; margin: 0 auto; padding: .75rem clamp(1rem, 4vw, 2rem); }
        .brand { display: inline-flex; align-items: center; gap: .75rem; min-height: 2.75rem; font-family: var(--font-display); font-size: 1.6rem; font-weight: 700; line-height: 1.1; color: var(--text-on-dark); text-decoration: none; overflow-wrap: anywhere; }
        .brand svg { flex-shrink: 0; color: var(--brass); }
        main { flex: 1; width: 100%; max-width: 40rem; margin: 3rem auto; padding: 0 clamp(.75rem, 4vw, 1rem); }
        .card { padding: 2rem 2.25rem; text-align: center; background: var(--surface-raised); border: 1px solid var(--border-soft); border-top: 3px double var(--brass); border-radius: 8px; box-shadow: var(--shadow-soft); }
        .status-icon { display: inline-grid; place-items: center; width: 4.5rem; height: 4.5rem; margin-bottom: .75rem; color: var(--black); background: var(--surface-muted); border: 1px solid var(--border-soft); border-radius: 50%; }
        .status-icon--danger { color: var(--maroon); background: var(--surface-danger); border-color: #e6c1bb; }
        .status-icon--success { color: var(--success); background: var(--surface-success); border-color: #c4dbb9; }
        h1 { margin: 0; font-family: var(--font-display); font-size: clamp(1.75rem, 5vw, 2.4rem); font-weight: 700; line-height: 1.15; letter-spacing: -.01em; text-wrap: balance; }
        .lead { max-width: 34rem; margin: .5rem auto 1.5rem; color: var(--text-muted); font-size: 1.05rem; }
        .summary { display: grid; gap: 1rem; margin: 0 0 1rem; padding: 1.1rem 1.25rem; text-align: left; background: var(--surface); border: 1px solid var(--border-soft); border-radius: 8px; }
        .summary-row { display: flex; align-items: center; gap: .9rem; }
        .summary-icon { display: inline-grid; place-items: center; flex-shrink: 0; width: 2.75rem; height: 2.75rem; background: var(--surface-muted); border: 1px solid var(--border-soft); border-radius: 50%; }
        .summary-row dl, .summary-row dd { margin: 0; min-width: 0; }
        .summary-main { font-family: var(--font-display); font-size: 1.125rem; font-weight: 700; overflow-wrap: anywhere; }
        .summary-sub { color: var(--text-muted); font-size: .875rem; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .reference { margin: 0 0 1.5rem; color: var(--text-muted); font-size: .875rem; }
        .reference code { font-family: var(--font-mono); overflow-wrap: anywhere; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: .75rem; margin: 0; }
        .actions > * { flex: 1 1 13rem; }
        .actions form { display: flex; margin: 0; }
        .button { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 2.75rem; padding: .55rem 1.25rem; font-family: var(--font-display); font-size: 1.05rem; font-weight: 700; line-height: 1.2; text-decoration: none; border: 1px solid transparent; border-radius: 6px; cursor: pointer; transition: background-color .12s ease, border-color .12s ease, color .12s ease; }
        .button--primary { color: var(--text-on-dark); background: var(--maroon); border-color: var(--maroon-dark); box-shadow: var(--shadow-action); }
        .button--primary:hover { background: var(--maroon-dark); }
        .button--secondary { color: var(--black); background: var(--surface-raised); border-color: var(--border-control); }
        .button--secondary:hover { color: var(--maroon-dark); border-color: var(--maroon); }
        .button--danger { color: var(--danger); background: var(--surface-raised); border-color: var(--danger); }
        .button--danger:hover { color: var(--text-on-dark); background: var(--danger); }
        .button:active { transform: translateY(1px); }
        a:focus-visible, button:focus-visible { outline: 3px solid var(--brass-deep); outline-offset: 2px; }
        .page-header a:focus-visible { outline-color: var(--brass); }
        .actions--single { max-width: 22rem; margin: 0 auto; }
        footer { color: var(--text-on-dark-muted); background: var(--black); border-top: 3px double var(--brass); }
        .footer-inner { max-width: 72rem; margin: 0 auto; padding: 1.25rem clamp(1rem, 4vw, 2rem); font-size: .875rem; }
        @media (max-width: 559px) {
            .brand { font-size: 1.25rem; }
            main { margin-top: 1.25rem; }
            .card { padding: 1.5rem 1rem; }
            .status-icon { width: 3.75rem; height: 3.75rem; }
            .actions { flex-direction: column; }
            .actions > * { flex-basis: auto; }
        }
        @media (prefers-reduced-motion: reduce) { .button { transition: none; } .button:active { transform: none; } }
    </style>
</head>
<body>
    <header class="page-header">
        <div class="pole-stripe" aria-hidden="true"></div>
        <div class="page-header-inner">
            <a class="brand" href="/">@include('cancellation.icon', ['name' => 'scissors', 'size' => 24]){{ $shopName }}</a>
        </div>
    </header>
    <main>
        <div class="card">
            @yield('content')
        </div>
    </main>
    @if ($shopPhone)
        <footer><div class="footer-inner">{{ __('booking.mail.contact', ['phone' => $shopPhone]) }}</div></footer>
    @endif
</body>
</html>

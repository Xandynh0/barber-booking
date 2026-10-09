<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ $shopName }}</title>
    {{-- Same "old barber" palette as the frontend; no external fonts or scripts on a page whose URL carries a signature. --}}
    <style>
        :root { --black: #1c1a17; --brass: #b08d57; --cream: #f3ead9; --cream-dark: #e8dcc3; --maroon: #6e1423; --maroon-dark: #4c0e18; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--cream); color: var(--black); font-family: Georgia, 'Times New Roman', serif; line-height: 1.5; }
        header { background: var(--black); color: var(--cream); border-bottom: 4px solid var(--brass); padding: 1rem; text-align: center; letter-spacing: .08em; text-transform: uppercase; font-size: .95rem; }
        main { max-width: 32rem; margin: 2rem auto; padding: 0 1rem; }
        .card { background: #fffaf0; border: 1px solid var(--cream-dark); border-top: 4px solid var(--maroon); border-radius: 4px; padding: 1.5rem; }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; color: var(--maroon-dark); }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; margin: 1rem 0 1.5rem; }
        dt { font-weight: bold; }
        dd { margin: 0; }
        .notice { padding: .75rem 1rem; border-left: 4px solid var(--brass); background: var(--cream); margin: 1rem 0; }
        .notice--success { border-left-color: #2f6b3a; }
        button { width: 100%; padding: .85rem 1rem; border: 0; border-radius: 4px; background: var(--maroon); color: var(--cream); font: inherit; font-size: 1rem; cursor: pointer; }
        button:hover, button:focus-visible { background: var(--maroon-dark); outline: 2px solid var(--brass); outline-offset: 2px; }
        footer { text-align: center; font-size: .85rem; color: #5a5347; margin: 2rem 0; }
    </style>
</head>
<body>
    <header>{{ $shopName }}</header>
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

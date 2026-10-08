<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document['title'] }} | BuyTheWay</title>
    @vite(['resources/js/shared/cookie-consent.js'])
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #17324d; background: #f5f8fb; }
        body { margin: 0; }
        header { background: #fff; border-bottom: 1px solid #dce5ed; }
        nav, main { width: min(900px, calc(100% - 2rem)); margin: 0 auto; }
        nav { display: flex; align-items: center; gap: 1.25rem; min-height: 68px; }
        nav a { color: #0f766e; text-decoration: none; font-weight: 650; }
        nav a:first-child { margin-right: auto; font-size: 1.1rem; }
        button { padding: .7rem 1rem; border: 1px solid #0f766e; border-radius: 8px; background: #0f766e; color: #fff; font: inherit; cursor: pointer; }
        button:focus-visible { outline: 2px solid #0f766e; outline-offset: 3px; }
        main { margin-block: 2rem 4rem; background: #fff; padding: clamp(1.25rem, 4vw, 3rem); border: 1px solid #dce5ed; border-radius: 18px; box-sizing: border-box; box-shadow: 0 12px 35px rgba(26, 52, 77, .06); }
        h1 { color: #102a43; margin-top: 0; }
        h2 { margin-top: 2rem; color: #153e5c; font-size: 1.15rem; }
        p, li { line-height: 1.72; }
        li + li { margin-top: .45rem; }
        footer { text-align: center; padding: 1rem 1rem 3rem; color: #60758a; }
        @media (max-width: 620px) { nav { flex-wrap: wrap; padding-block: .75rem; } main { margin-top: 1rem; } }
    </style>
</head>
<body>
<header>
    <nav aria-label="Legal navigation">
        <a href="/">BuyTheWay</a>
        <a href="/privacy">{{ __('legal.nav.privacy') }}</a>
        <a href="/terms">{{ __('legal.nav.terms') }}</a>
        <a href="/cookies">{{ __('legal.nav.cookies') }}</a>
    </nav>
</header>
<main>
    <h1>{{ $document['title'] }}</h1>
    @isset($document['last_updated'])
        <p>{{ $document['last_updated'] }}</p>
    @endisset
    @isset($document['body'])
        <p>{{ $document['body'] }}</p>
    @endisset
    @isset($document['buttons'])
        <button type="button" onclick="window.dispatchEvent(new Event('btw:open-cookie-preferences'))">{{ __('legal.cookies.preferences') }}</button>
    @endisset
    @foreach($document['sections'] ?? [] as $section)
        <section>
            <h2>{{ $section['heading'] }}</h2>
            @foreach($section['paragraphs'] as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
            @if(count($section['items']))
                <ul>
                    @foreach($section['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</main>
<footer>&copy; {{ now()->year }} BuyTheWay</footer>
</body>
</html>

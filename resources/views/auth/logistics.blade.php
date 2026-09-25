<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BuyTheWay Logistics — Sign In</title>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}" />

    <!-- Supabase JS. `defer` lets the browser parse/paint the page while
         this downloads instead of blocking on it; it still runs before the
         Vite module bundle below (both are executed in document order
         relative to each other, right before DOMContentLoaded), so
         window.supabase is guaranteed ready when app-logistics.js needs it. -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2" defer></script>

    <!-- Pass config from Laravel -->
    <script>
        window.CONFIG = {
            SUPABASE_URL: '{{ $config['supabase_url'] }}',
            SUPABASE_ANON_KEY: '{{ $config['supabase_anon_key'] }}',
            GOOGLE_OAUTH_BASE: '{{ $config['google_oauth_base'] }}',
        };
    </script>

    <!-- Tailwind and Vue are bundled through Vite (see resources/css/app.css
         and the "vue" import in app-logistics.js) — no separate CDN scripts
         needed for either; loading them again here would just duplicate
         work and slow the page down. -->
    @vite(['resources/js/app-logistics.js'])
</head>
<body>
    <div id="auth-app"></div>
</body>
</html>

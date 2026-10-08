<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" href="/images/BuyTheWayLogo.ico?v=3" sizes="any">
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Sign in · BuyTheWay</title>



    <!-- Pass config from Laravel -->
    <script>
        window.CONFIG = {
            GOOGLE_OAUTH_BASE: '{{ $config['google_oauth_base'] }}',
            LEGAL_TERMS_URL: @json(route('legal.terms')),
            LEGAL_PRIVACY_URL: @json(route('legal.privacy')),
        };
    </script>

    <!-- Tailwind and Vue are bundled through Vite (see resources/css/app.css
         and the "vue" import in app.js) — no separate CDN scripts needed
         for either; loading them again here would just duplicate work and
         slow the page down. -->
    @vite(['resources/js/app.js'])
</head>
<body>
    <div id="auth-app"></div>
</body>
</html>

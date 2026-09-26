<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>BuyTheWay — Sign Up</title>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}" />


    <!-- Pass config from Laravel -->
    <script>
        window.CONFIG = {
            GOOGLE_OAUTH_BASE: '{{ $config['google_oauth_base'] }}',
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
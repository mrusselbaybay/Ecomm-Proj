<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <meta name="referrer" content="no-referrer" />
    <title>Join your team — BuyTheWay Logistics</title>
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2" defer></script>
    <script>
        window.CONFIG = {
            SUPABASE_URL: '{{ $config['supabase_url'] }}',
            SUPABASE_ANON_KEY: '{{ $config['supabase_anon_key'] }}',
        };
    </script>
    @vite(['resources/js/invite/accept-invite.js'])
</head>
<body class="bg-slate-50">
    <div id="invite-app"></div>
</body>
</html>

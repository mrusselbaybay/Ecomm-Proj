<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>BuyTheWay — Shop local sellers</title>
    <meta name="theme-color" content="#1d6b52">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Geist:wght@400..700&display=swap" rel="stylesheet">

    <!-- Supabase client (UMD build) — must load before the Vite bundle.
         Used to read the signed-in buyer's session and forward it as a
         Bearer token to our own API (checkout, orders) — see
         resources/js/buyer/composables/useBuyerSession.js. Product
         browsing itself does not require a session. -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

    @vite([
        'resources/css/app.css',
        'resources/css/buyer/layout.css',
        'resources/js/buyer/buyer.js'
    ])
</head>

<body>
    <div id="buyer-app"></div>
</body>
</html>
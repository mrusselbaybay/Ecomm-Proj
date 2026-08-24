<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>NEXMART - Buyer</title>

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
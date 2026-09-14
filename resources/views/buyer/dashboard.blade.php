<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>BuyTheWay - Buyer</title>

    <!-- Supabase client (UMD build). `defer` unblocks HTML parsing/painting
         while it downloads, and lets it fetch in parallel with the Vite
         bundle below instead of forcing that download to wait — it still
         executes before the Vite module script either way (both run in
         document order right before DOMContentLoaded), so window.supabase
         is ready when the app needs it. Same fix already applied to
         admin.blade.php; this was the one page type it hadn't reached yet.
         Used to read the signed-in buyer's session and forward it as a
         Bearer token to our own API (checkout, orders) — see
         resources/js/buyer/composables/useBuyerSession.js. Product
         browsing itself does not require a session. -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2" defer></script>

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Seller Center — BuyTheWay</title>

    <!-- Supabase client (UMD build). `defer` unblocks parsing/painting while
         it downloads; it still executes before the Vite module bundle below
         (both run in document order right before DOMContentLoaded), so
         window.supabase is ready when the seller app needs it. -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2" defer></script>

    @vite('resources/js/seller/seller.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>

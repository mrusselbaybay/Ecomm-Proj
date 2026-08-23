<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Seller Center — NEXMART</title>

    <!-- Supabase client (UMD build) — must load before the Vite bundle -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

    @vite(['resources/css/seller/layout.css', 'resources/js/seller/seller.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
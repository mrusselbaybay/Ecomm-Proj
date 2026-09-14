<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="BuyTheWay is an online marketplace for everyday essentials and new discoveries, from different sellers in one place.">

    <title>BuyTheWay — Good finds, along the way</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    <!-- Supabase client (UMD build) — used only to read the signed-in
         session and to sign out from the header account menu; this page
         has no login form of its own (see /login, /signup). -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>

    @vite(['resources/css/home/layout.css', 'resources/js/home/home.js'])
</head>
<body>
    <div id="home-app"></div>
</body>
</html>

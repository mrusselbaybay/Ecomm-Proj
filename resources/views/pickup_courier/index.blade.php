<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Work - NEXMART</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- Supabase CDN - MUST BE BEFORE VITE -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    
    @vite(['resources/css/app.css', 'resources/js/pickup_courier/pickup_courier.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
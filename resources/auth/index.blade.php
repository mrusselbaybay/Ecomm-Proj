<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NEXMART — Sign Up</title>
    
    <!-- External CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="{{ asset('css/style.css') }}" />
    
    <!-- Supabase JS -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    
    <!-- Vue.js -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    
    <!-- Pass Supabase config from Laravel -->
    <script>
        window.CONFIG = {
            SUPABASE_URL: '{{ $config['supabase_url'] }}',
            SUPABASE_ANON_KEY: '{{ $config['supabase_anon_key'] }}',
        };
    </script>
</head>
<body>
    <div id="app"></div>
    
    <!-- Our Application JS -->
    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
<!-- resources/views/admin.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NEXMART — Admin</title>

    <!-- Supabase JS. `defer` unblocks parsing/painting while it downloads;
         it still executes before the Vite module bundle below (both run
         in document order right before DOMContentLoaded), so
         window.supabase is ready when the admin app needs it. -->
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2" defer></script>

    <!-- Tailwind and Vue are bundled through Vite (see admin.js, which now
         imports resources/css/app.css and 'vue' from npm) — the old
         cdn.tailwindcss.com / unpkg.com/vue scripts duplicated that work in
         the browser on every load and are no longer needed. -->
    @vite('resources/js/admin/admin.js')
</head>
<body>
    <div id="app"></div>
</body>
</html>
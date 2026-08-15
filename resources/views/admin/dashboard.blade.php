<!-- resources/views/admin.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NEXMART — Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@700;800&display=swap');
        body { font-family: 'Inter', system-ui, sans-serif; background: #fafafc; margin: 0; }
        .display-font { font-family: 'Playfair Display', serif; }
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #ea580c;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div id="app"></div>
    
    @vite('resources/js/admin/admin.js')
</body>
</html>
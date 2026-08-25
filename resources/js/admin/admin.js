// resources/js/admin/admin.js
// app.css compiles Tailwind through Vite (see @import 'tailwindcss' inside
// it) — this replaces the old cdn.tailwindcss.com <script> in admin.blade.php,
// which shipped the full runtime JIT compiler and recompiled every utility
// class in the browser on every load instead of using a prebuilt stylesheet.
import '../../css/app.css';
import '../../css/admin/layout.css';
import { createApp } from 'vue';
import AdminLayout from './components/AdminLayout.vue';

// Mount the admin app
createApp(AdminLayout).mount('#app');

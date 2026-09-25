// Logistics admin panel (the /admin SPA scoped to logistics accounts).
// CSS is imported statically here, same as admin/admin.js: these files are
// also Vite entries, so dynamic import() of them yields no stylesheet.
import '../../css/app.css';
import '../../css/admin/layout.css';
import { createApp } from 'vue';
import AdminLayout from '../admin/components/AdminLayout.vue';
import { setAdminScope } from '../admin/composables/useAdmin';

export default function mount(el) {
    setAdminScope('logistics');
    createApp(AdminLayout).mount(el);
}

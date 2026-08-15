// resources/js/admin/admin.js
import { createApp, ref, computed, onMounted } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';

// Import components
import AdminLayout from './layouts/AdminLayout.vue';
import Dashboard from './pages/Dashboard.vue';
import Registrations from './pages/Registrations.vue';
import Users from './pages/Users.vue';
import Compliance from './pages/Compliance.vue';
import Complaints from './pages/Complaints.vue';
import Commission from './pages/Commission.vue';
import Reports from './pages/Reports.vue';
import Settings from './pages/Settings.vue';
import Chat from './pages/Chat.vue';
import Profile from './pages/Profile.vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// Create router
const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/admin/dashboard', component: Dashboard, meta: { title: 'Dashboard', icon: 'grid' } },
        { path: '/admin/registrations', component: Registrations, meta: { title: 'Account Registrations', icon: 'userCheck' } },
        { path: '/admin/users', component: Users, meta: { title: 'User Accounts', icon: 'users' } },
        { path: '/admin/compliance', component: Compliance, meta: { title: 'Seller Compliance', icon: 'shield' } },
        { path: '/admin/complaints', component: Complaints, meta: { title: 'Complaints & Disputes', icon: 'alert' } },
        { path: '/admin/commission', component: Commission, meta: { title: 'Commission (10%)', icon: 'percent' } },
        { path: '/admin/reports', component: Reports, meta: { title: 'Generate Reports', icon: 'file' } },
        { path: '/admin/settings', component: Settings, meta: { title: 'Platform Settings', icon: 'settings' } },
        { path: '/admin/chat', component: Chat, meta: { title: 'Chat / Messaging', icon: 'chat' } },
        { path: '/admin/profile', component: Profile, meta: { title: 'Account Management', icon: 'userCog' } },
        { path: '/', redirect: '/admin/dashboard' }
    ]
});

// Navigation guard
router.beforeEach(async (to, from, next) => {
    const { data: { user } } = await supabase.auth.getUser();
    if (!user) {
        window.location.href = '/';
        return;
    }
    
    const { data: profile } = await supabase
        .from('profiles')
        .select('role')
        .eq('id', user.id)
        .single();
    
    if (!profile || profile.role !== 'admin') {
        window.location.href = '/';
        return;
    }
    
    next();
});

// Create app
const app = createApp({
    setup() {
        const adminProfile = ref({ name: 'Admin User', email: '', role: 'Platform Administrator' });
        const pendingCount = ref(0);
        const isLoading = ref(true);
        const isAuthenticated = ref(false);
        const isAdmin = ref(false);

        const navItems = computed(() => [
            { id: 'dashboard', label: 'View Dashboard', path: '/admin/dashboard', icon: 'grid' },
            { id: 'registrations', label: 'Account Registrations', path: '/admin/registrations', icon: 'userCheck', badge: pendingCount.value || null },
            { id: 'users', label: 'User Accounts', path: '/admin/users', icon: 'users' },
            { id: 'compliance', label: 'Seller Compliance', path: '/admin/compliance', icon: 'shield' },
            { id: 'complaints', label: 'Complaints & Disputes', path: '/admin/complaints', icon: 'alert' },
            { id: 'commission', label: 'Commission (10%)', path: '/admin/commission', icon: 'percent' },
            { id: 'reports', label: 'Generate Reports', path: '/admin/reports', icon: 'file' },
            { id: 'settings', label: 'Platform Settings', path: '/admin/settings', icon: 'settings' },
            { id: 'chat', label: 'Chat / Messaging', path: '/admin/chat', icon: 'chat' },
            { id: 'profile', label: 'Account Management', path: '/admin/profile', icon: 'userCog' },
        ]);

        async function checkAuth() {
            isLoading.value = true;
            try {
                const { data: { user } } = await supabase.auth.getUser();
                if (!user) { window.location.href = '/'; return; }

                const { data: profile } = await supabase
                    .from('profiles')
                    .select('role, first_name, last_name, email')
                    .eq('id', user.id)
                    .single();

                if (!profile || profile.role !== 'admin') { window.location.href = '/'; return; }

                isAuthenticated.value = true;
                isAdmin.value = true;
                adminProfile.value = {
                    name: `${profile.first_name || 'Admin'} ${profile.last_name || 'User'}`,
                    email: profile.email || user.email,
                    role: 'Platform Administrator',
                };

                // Get pending count
                const { count } = await supabase
                    .from('profiles')
                    .select('*', { count: 'exact', head: true })
                    .in('role', ['buyer', 'seller', 'courier'])
                    .eq('status', 'pending');
                pendingCount.value = count || 0;

            } catch (error) {
                console.error('Auth error:', error);
                window.location.href = '/';
            } finally {
                isLoading.value = false;
            }
        }

        async function logout() {
            await supabase.auth.signOut();
            window.location.href = '/';
        }

        onMounted(checkAuth);

        return {
            adminProfile,
            pendingCount,
            isLoading,
            isAuthenticated,
            isAdmin,
            navItems,
            logout
        };
    },
    render() {
        if (this.isLoading) {
            return h('div', { class: 'min-h-screen flex items-center justify-center' }, [
                h('div', { class: 'text-center' }, [
                    h('div', { class: 'loading-spinner mx-auto mb-4' }),
                    h('p', { class: 'text-slate-500' }, 'Loading admin panel...')
                ])
            ]);
        }

        if (!this.isAuthenticated || !this.isAdmin) {
            return h('div', { class: 'min-h-screen flex items-center justify-center' }, [
                h('div', { class: 'text-center' }, [
                    h('h1', { class: 'text-2xl font-bold text-red-600 mb-2' }, 'Access Denied'),
                    h('p', { class: 'text-slate-500 mb-4' }, 'You do not have permission to view this page.'),
                    h('a', { href: '/', class: 'text-orange-600 hover:underline' }, 'Return to Home')
                ])
            ]);
        }

        return h(AdminLayout, {
            adminProfile: this.adminProfile,
            pendingCount: this.pendingCount,
            navItems: this.navItems,
            onLogout: this.logout
        }, {
            default: () => h('router-view')
        });
    }
});

app.use(router);
app.mount('#app');
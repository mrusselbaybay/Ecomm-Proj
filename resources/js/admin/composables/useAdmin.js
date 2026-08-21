// resources/js/admin/composables/useAdmin.js
import { ref } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

// Regular client for normal operations (RLS-protected, safe for the browser)
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// NOTE: There is intentionally no client-side "supabaseAdmin" / service-role
// client here. The service role key bypasses RLS entirely, so it must never
// ship to the browser. Any action that needs elevated privileges (approving
// registrations, updating account status, etc.) should call the existing
// Laravel endpoints instead, e.g.:
//   GET  /api/admin/registrations
//   POST /api/admin/registrations/{profile}/approve
//   POST /api/admin/registrations/{profile}/reject
//   GET  /api/admin/accounts
//   PUT  /api/admin/accounts/{profile}/status
// (see App\Http\Controllers\Admin\AccountRegistrationController and
// App\Http\Controllers\Admin\UserAccountController)

export function useAdmin() {
    const isLoading = ref(true);
    const isAuthenticated = ref(false);
    const isAdmin = ref(false);
    const adminUser = ref(null);
    const adminProfile = ref({
        name: 'Admin User',
        email: '',
        role: 'Platform Administrator',
    });
    const pendingCount = ref(0);
    const registrations = ref([]);
    const accounts = ref([]);
    const stats = ref([
        { label: 'Total Users', value: '0', delta: 'Loading...' },
        { label: 'Active Sellers', value: '0', delta: 'Loading...' },
        {
            label: 'Pending Registrations',
            value: '0',
            delta: 'Awaiting review',
        },
        { label: 'Open Complaints', value: '0', delta: 'No open complaints' },
    ]);
    const notifications = ref([]);

    async function checkAuth() {
        isLoading.value = true;

        try {
            const {
                data: { user },
                error,
            } = await supabase.auth.getUser();

            if (error || !user) {
                window.location.href = '/';

                return;
            }

            const { data: profile, error: profileError } = await supabase
                .from('profiles')
                .select('role, first_name, last_name, email')
                .eq('id', user.id)
                .single();

            if (profileError || !profile || profile.role !== 'admin') {
                window.location.href = '/';

                return;
            }

            isAuthenticated.value = true;
            isAdmin.value = true;
            adminUser.value = user;
            adminProfile.value = {
                name: `${profile.first_name || 'Admin'} ${profile.last_name || 'User'}`,
                email: profile.email || user.email,
                role: 'Platform Administrator',
            };
        } catch (error) {
            console.error('Auth error:', error);
            window.location.href = '/';
        } finally {
            isLoading.value = false;
        }
    }

    async function adminFetch(url, options = {}) {
        const {
            data: { session },
            error: sessionError,
        } = await supabase.auth.getSession();

        if (sessionError || !session?.access_token) {
            throw new Error(
                'Your admin session has expired. Please sign in again.',
            );
        }

        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('Authorization', `Bearer ${session.access_token}`);

        const response = await fetch(url, {
            ...options,
            headers,
        });

        if (response.status === 401) {
            window.location.href = '/login';
            throw new Error('Your admin session has expired.');
        }

        if (response.status === 403) {
            throw new Error(
                'You do not have permission to perform this action.',
            );
        }

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'The admin request failed.');
        }

        return response;
    }

    async function loadStats() {
        try {
            const response = await adminFetch('/api/admin/dashboard/stats');
            const data = await response.json();

            stats.value = [
                {
                    label: 'Total Users',
                    value: data.total_users,
                    delta: 'All registered accounts',
                },
                {
                    label: 'Active Sellers',
                    value: data.active_sellers,
                    delta: 'Approved and active',
                },
                {
                    label: 'Pending Registrations',
                    value: data.pending_registrations,
                    delta: 'Awaiting review',
                },
                {
                    label: 'Open Complaints',
                    value: data.open_complaints,
                    delta: 'Awaiting resolution',
                },
            ];

            pendingCount.value = data.pending_registrations;
        } catch (error) {
            console.error('Error loading admin dashboard stats:', error);
        }
    }

    async function loadNotifications() {
        try {
            const response = await adminFetch(
                '/api/admin/dashboard/notifications',
            );
            notifications.value = await response.json();
        } catch (error) {
            console.error('Error loading admin notifications:', error);
            notifications.value = [];
        }
    }

    async function confirmLogout() {
        try {
            await supabase.auth.signOut();
            window.location.href = '/';
        } catch (error) {
            console.error('Logout error:', error);
        }
    }

    function statusBadgeClass(status) {
        const s = status?.toLowerCase() || '';

        if (['active', 'approved', 'resolved', 'clear'].includes(s)) {
            return 'badge-green';
        }

        if (['pending', 'in review', 'warning', 'suspended'].includes(s)) {
            return 'badge-amber';
        }

        if (['deactivated', 'escalated', 'rejected'].includes(s)) {
            return 'badge-red';
        }

        return 'badge-slate';
    }

    function formatDate(date) {
        if (!date) {
            return 'N/A';
        }

        const d = new Date(date);

        return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    }

    return {
        isLoading,
        isAuthenticated,
        isAdmin,
        adminUser,
        adminProfile,
        pendingCount,
        registrations,
        accounts,
        stats,
        notifications,
        checkAuth,
        loadStats,
        loadNotifications,
        confirmLogout,
        statusBadgeClass,
        formatDate,
        adminFetch,
        supabase,
    };
}

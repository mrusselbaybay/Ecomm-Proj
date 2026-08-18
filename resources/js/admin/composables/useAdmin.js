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
    { label: 'Pending Registrations', value: '0', delta: 'Awaiting review' },
    { label: 'Open Complaints', value: '0', delta: 'No open complaints' },
  ]);
  const notifications = ref([]);

  async function checkAuth() {
    isLoading.value = true;
    try {
      const { data: { user }, error } = await supabase.auth.getUser();
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

  async function loadStats() {
    try {
      const { data: profiles, error } = await supabase
        .from('profiles')
        .select('role, account_status, status');

      if (error) throw error;

      const totalUsers = profiles?.length || 0;
      const activeSellers = profiles?.filter(p => p.role === 'seller' && p.account_status === 'active').length || 0;
      const pendingRegistrations = profiles?.filter(p => ['buyer', 'seller', 'courier'].includes(p.role) && p.status === 'pending').length || 0;

      stats.value = [
        { label: 'Total Users', value: totalUsers, delta: 'All time' },
        { label: 'Active Sellers', value: activeSellers, delta: 'Active sellers' },
        { label: 'Pending Registrations', value: pendingRegistrations, delta: 'Awaiting review' },
        { label: 'Open Complaints', value: '0', delta: 'No open complaints' },
      ];

      pendingCount.value = pendingRegistrations;

    } catch (error) {
      console.error('Error loading stats:', error);
    }
  }

  async function loadNotifications() {
    notifications.value = [
      { text: 'Welcome to the admin panel!', time: 'Just now' },
      { text: 'Review pending registrations to get started.', time: 'Just now' },
    ];
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
    if (['active', 'approved', 'resolved', 'clear'].includes(s)) return 'badge-green';
    if (['pending', 'in review', 'warning', 'suspended'].includes(s)) return 'badge-amber';
    if (['deactivated', 'escalated', 'rejected'].includes(s)) return 'badge-red';
    return 'badge-slate';
  }

  function formatDate(date) {
    if (!date) return 'N/A';
    const d = new Date(date);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
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
    supabase,
  };
}
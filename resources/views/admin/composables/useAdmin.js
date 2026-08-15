// resources/js/admin/composables/useAdmin.js
import { ref } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;
const supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// Get CSRF token for Laravel
function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

export function useAdmin() {
    const registrations = ref([]);
    const users = ref([]);
    const loading = ref(false);
    const error = ref(null);

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

    // ---------- Account Registrations ----------
    async function loadRegistrations(search = '', role = '', status = '') {
        loading.value = true;
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (role) params.append('role', role);
            if (status) params.append('status', status);
            
            const response = await fetch(`/api/admin/registrations?${params.toString()}`);
            const data = await response.json();
            
            if (!response.ok) throw new Error(data.message || 'Failed to load registrations');
            
            registrations.value = data.data || [];
        } catch (err) {
            error.value = err.message;
            console.error('Error loading registrations:', err);
        } finally {
            loading.value = false;
        }
    }

    async function approveUser(user) {
        if (!user) return;
        const userName = user.full_name || user.name || user.email || 'this user';
        if (!confirm(`Approve ${userName}?`)) return;
        
        try {
            const response = await fetch(`/api/admin/registrations/${user.id}/approve`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            });
            
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Failed to approve user');
            
            alert(`✅ ${userName} approved successfully!`);
            await loadRegistrations();
        } catch (err) {
            console.error('Error approving user:', err);
            alert('Failed to approve user: ' + err.message);
        }
    }

    async function rejectUser(user, reason = '') {
        if (!user) return;
        const userName = user.full_name || user.name || user.email || 'this user';
        if (!reason && !confirm(`Reject ${userName}?`)) return;
        
        try {
            const response = await fetch(`/api/admin/registrations/${user.id}/reject`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ reason })
            });
            
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Failed to reject user');
            
            alert(`❌ ${userName} rejected.`);
            await loadRegistrations();
        } catch (err) {
            console.error('Error rejecting user:', err);
            alert('Failed to reject user: ' + err.message);
        }
    }

    function viewRegistration(user) {
        const userName = user.full_name || user.name || user.email || 'this user';
        alert(`📄 Viewing documents for ${userName}\n\nThis would open a modal with:\n- Valid ID\n- Business Permit (if seller)\n- OR/CR (if courier)\n- Driver's License (if courier/driver)\n\nStatus: ${user.status || 'N/A'}`);
    }

    // ---------- User Accounts ----------
    async function loadUsers(search = '', role = '', status = '') {
        loading.value = true;
        try {
            const params = new URLSearchParams();
            if (search) params.append('search', search);
            if (role) params.append('role', role);
            if (status) params.append('status', status);
            
            const response = await fetch(`/api/admin/accounts?${params.toString()}`);
            const data = await response.json();
            
            if (!response.ok) throw new Error(data.message || 'Failed to load users');
            
            users.value = data.data || [];
        } catch (err) {
            error.value = err.message;
            console.error('Error loading users:', err);
        } finally {
            loading.value = false;
        }
    }

    async function updateUserStatus(user, status) {
        if (!user) return;
        
        const statusLabels = {
            active: 'activate',
            suspended: 'suspend',
            deactivated: 'deactivate'
        };
        
        const userName = user.full_name || user.name || user.email || 'this user';
        if (!confirm(`Are you sure you want to ${statusLabels[status] || status} ${userName}?`)) return;
        
        try {
            const response = await fetch(`/api/admin/accounts/${user.id}/status`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({ status })
            });
            
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Failed to update user status');
            
            alert(`✅ ${userName} has been ${statusLabels[status] || status}d.`);
            await loadUsers();
        } catch (err) {
            console.error('Error updating user status:', err);
            alert('Failed to update user status: ' + err.message);
        }
    }

    return {
        registrations,
        users,
        loading,
        error,
        statusBadgeClass,
        formatDate,
        loadRegistrations,
        loadUsers,
        approveUser,
        rejectUser,
        viewRegistration,
        updateUserStatus
    };
}
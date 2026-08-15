<!-- resources/js/admin/components/Users.vue -->
<template>
  <div>
    <p class="text-sm text-slate-500 mb-4">View and manage all user accounts. You can approve/reject pending users, and activate/suspend/deactivate approved users.</p>
    
    <!-- Summary Cards -->
    <div class="grid grid-cols-5 gap-4 mb-6">
      <div class="stat-card">
        <p class="field-label">Total Users</p>
        <p class="text-2xl font-bold text-slate-900">{{ totalUsers }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Pending</p>
        <p class="text-2xl font-bold text-orange-600">{{ pendingCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Active</p>
        <p class="text-2xl font-bold text-green-600">{{ activeCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Suspended</p>
        <p class="text-2xl font-bold text-amber-600">{{ suspendedCount }}</p>
      </div>
      <div class="stat-card">
        <p class="field-label">Deactivated</p>
        <p class="text-2xl font-bold text-red-600">{{ deactivatedCount }}</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="flex gap-4 mb-4 flex-wrap">
      <input type="text" v-model="search" placeholder="Search users..." class="field-input w-64" @input="loadData" />
      <select v-model="roleFilter" class="field-input w-40" @change="loadData">
        <option value="">All Roles</option>
        <option value="buyer">Buyer</option>
        <option value="seller">Seller</option>
        <option value="courier">Courier</option>
        <option value="admin">Admin</option>
      </select>
      <select v-model="statusFilter" class="field-input w-40" @change="loadData">
        <option value="">All Status</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="rejected">Rejected</option>
      </select>
      <select v-model="accountFilter" class="field-input w-40" @change="loadData">
        <option value="">All Account Status</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="deactivated">Deactivated</option>
      </select>
      <button @click="loadData" class="btn-gradient text-white px-4 py-2 rounded-lg">Filter</button>
    </div>

    <!-- Users Table -->
    <div class="card overflow-hidden">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Approval Status</th>
            <th>Acct. Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="6" class="text-center py-4 text-slate-500">Loading...</td>
          </tr>
          <tr v-else-if="accounts.length === 0">
            <td colspan="6" class="text-center py-4 text-slate-500">No users found.</td>
          </tr>
          <tr v-for="user in accounts" :key="user.id">
            <td class="font-medium text-slate-800">{{ user.full_name || user.first_name || user.email }}</td>
            <td>{{ user.email }}</td>
            <td>
              <span class="role-badge" :class="{
                'bg-purple-100 text-purple-700': user.role === 'admin',
                'bg-blue-100 text-blue-700': user.role === 'seller',
                'bg-green-100 text-green-700': user.role === 'courier',
                'bg-gray-100 text-gray-700': user.role === 'buyer'
              }">
                {{ user.role }}
              </span>
            </td>
            <td>
              <span class="status-dot" :class="user.status"></span>
              <span class="badge" :class="approvalBadgeClass(user.status)">
                {{ user.status }}
              </span>
              <div v-if="user.status === 'rejected' && user.rejection_reason" class="text-xs text-red-500 mt-1">
                <span class="cursor-pointer hover:underline" @click="showRejectionReason(user)">
                  View reason →
                </span>
              </div>
            </td>
            <td>
              <span class="status-dot" :class="user.account_status || 'pending'"></span>
              <span class="badge" :class="accountBadgeClass(user.account_status || 'pending')">
                {{ user.account_status || 'pending' }}
              </span>
            </td>
            <td>
              <div class="flex gap-2 flex-wrap">
                <!-- Approval Actions (for pending users) -->
                <template v-if="user.status === 'pending'">
                  <button @click="approveUser(user)" class="btn-sm-gradient">Approve</button>
                  <button @click="openRejectModal(user)" class="btn-danger-outline">Reject</button>
                </template>
                
                <!-- Re-approve Action (for rejected users) -->
                <template v-if="user.status === 'rejected'">
                  <button @click="reapproveUser(user)" class="btn-sm-gradient">Re-approve</button>
                </template>
                
                <!-- Account Actions (only for approved users) -->
                <template v-if="user.status === 'approved'">
                  <button 
                    @click="updateAccountStatus(user, 'active')" 
                    class="btn-outline btn-active" 
                    :disabled="user.account_status === 'active'">
                    Activate
                  </button>
                  <button 
                    @click="updateAccountStatus(user, 'suspended')" 
                    class="btn-outline btn-suspend" 
                    :disabled="user.account_status === 'suspended'">
                    Suspend
                  </button>
                  <button 
                    @click="updateAccountStatus(user, 'deactivated')" 
                    class="btn-danger-outline" 
                    :disabled="user.account_status === 'deactivated'">
                    Deactivate
                  </button>
                </template>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Rejection Modal -->
    <div v-if="showRejectModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="card p-6 w-96 max-h-[90vh] overflow-y-auto">
        <h3 class="font-bold text-slate-900 mb-2">Reject Application</h3>
        <p class="text-sm text-slate-500 mb-4">
          Please select a reason for rejecting <strong>{{ rejectUserData?.full_name || rejectUserData?.first_name || rejectUserData?.email }}</strong>'s application:
        </p>
        
        <div class="space-y-3">
          <div 
            v-for="reason in rejectionReasons" 
            :key="reason.value"
            class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer hover:bg-slate-50 transition-colors"
            :class="{ 'border-orange-500 bg-orange-50': selectedReason === reason.value }"
            @click="selectedReason = reason.value"
          >
            <input 
              type="radio" 
              :value="reason.value" 
              v-model="selectedReason" 
              class="mt-1"
            />
            <div>
              <p class="text-sm font-medium text-slate-800">{{ reason.label }}</p>
              <p class="text-xs text-slate-500">{{ reason.description }}</p>
            </div>
          </div>
          
          <!-- Others option with text box -->
          <div class="flex items-start gap-3 p-3 border rounded-lg" :class="{ 'border-orange-500 bg-orange-50': selectedReason === 'others' }">
            <input 
              type="radio" 
              value="others" 
              v-model="selectedReason" 
              class="mt-1"
            />
            <div class="flex-1">
              <p class="text-sm font-medium text-slate-800">Others</p>
              <textarea 
                v-if="selectedReason === 'others'"
                v-model="customReason"
                placeholder="Please specify the reason for rejection..."
                class="field-input mt-2"
                rows="3"
              ></textarea>
            </div>
          </div>
        </div>
        
        <div class="flex gap-2 mt-4">
          <button @click="closeRejectModal" class="btn-outline flex-1 py-2">Cancel</button>
          <button 
            @click="submitRejection" 
            class="btn-danger-outline flex-1 py-2"
            :disabled="!selectedReason || (selectedReason === 'others' && !customReason.trim())"
          >
            Reject
          </button>
        </div>
      </div>
    </div>

    <!-- Rejection Reason View Modal -->
    <div v-if="showReasonModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="card p-6 w-96">
        <h3 class="font-bold text-slate-900 mb-2">Rejection Reason</h3>
        <p class="text-sm text-slate-500 mb-4">
          Reason for rejecting <strong>{{ reasonUser?.full_name || reasonUser?.first_name || reasonUser?.email }}</strong>:
        </p>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
          <p class="text-sm text-red-700">{{ reasonUser?.rejection_reason }}</p>
        </div>
        <div class="flex gap-2 mt-4">
          <button @click="showReasonModal = false" class="btn-outline flex-1 py-2">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { 
  accounts, 
  pendingCount,
  supabase 
} = useAdmin();

const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');
const accountFilter = ref('');
const loading = ref(false);

// Rejection modal
const showRejectModal = ref(false);
const rejectUserData = ref(null);
const selectedReason = ref('');
const customReason = ref('');

// View reason modal
const showReasonModal = ref(false);
const reasonUser = ref(null);

// Rejection reasons
const rejectionReasons = [
  {
    value: 'invalid_information',
    label: 'Invalid or incomplete information',
    description: 'The submitted details are missing, incorrect, or don\'t match the requirements.'
  },
  {
    value: 'invalid_id',
    label: 'Invalid identification',
    description: 'The uploaded ID or supporting document is unclear, expired, or cannot be verified.'
  },
  {
    value: 'not_eligible',
    label: 'Does not meet eligibility requirements',
    description: 'The user does not qualify for the service or organization.'
  },
  {
    value: 'fraudulent',
    label: 'Suspicious or fraudulent information',
    description: 'The submitted information appears fake, inconsistent, or potentially fraudulent.'
  }
];

// Computed counts
const totalUsers = computed(() => accounts.value.length);
const activeCount = computed(() => accounts.value.filter(u => u.account_status === 'active').length);
const suspendedCount = computed(() => accounts.value.filter(u => u.account_status === 'suspended').length);
const deactivatedCount = computed(() => accounts.value.filter(u => u.account_status === 'deactivated').length);

// Badge classes
function approvalBadgeClass(status) {
  const s = status?.toLowerCase() || '';
  if (s === 'approved') return 'badge-green';
  if (s === 'pending') return 'badge-amber';
  if (s === 'rejected') return 'badge-red';
  return 'badge-slate';
}

function accountBadgeClass(status) {
  const s = status?.toLowerCase() || '';
  if (s === 'active') return 'badge-green';
  if (s === 'suspended') return 'badge-amber';
  if (s === 'deactivated') return 'badge-red';
  return 'badge-slate';
}

async function loadData() {
  loading.value = true;
  try {
    let query = supabase
      .from('profiles')
      .select('*')
      .order('created_at', { ascending: false });

    if (search.value) {
      query = query.or(`first_name.ilike.%${search.value}%,last_name.ilike.%${search.value}%,email.ilike.%${search.value}%`);
    }
    if (roleFilter.value) query = query.eq('role', roleFilter.value);
    if (statusFilter.value) query = query.eq('status', statusFilter.value);
    if (accountFilter.value) query = query.eq('account_status', accountFilter.value);

    const { data, error } = await query;
    if (error) throw error;
    accounts.value = data || [];

    // Update pending count
    pendingCount.value = accounts.value.filter(u => u.status === 'pending').length;

  } catch (error) {
    console.error('Error loading accounts:', error);
  } finally {
    loading.value = false;
  }
}

// Helper function to send email via Laravel
async function sendEmail(endpoint, data) {
  try {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken
      },
      body: JSON.stringify(data)
    });
    
    const result = await response.json();
    if (!response.ok) {
      console.error('Email error:', result);
      return false;
    }
    return true;
  } catch (error) {
    console.error('Failed to send email:', error);
    return false;
  }
}

// ============================================================
// APPROVAL ACTIONS
// ============================================================

// Approve user with email notification
// In Users.vue - Updated approveUser function
async function approveUser(user) {
  if (!confirm(`Approve ${user.full_name || user.first_name || user.email}?`)) return;
  
  try {
    // 1. Auto-confirm email in auth.users (using admin API)
    const { error: confirmError } = await supabase.auth.admin.updateUserById(
      user.id,
      { email_confirm: true }
    );

    if (confirmError) {
      console.warn('Could not auto-confirm email:', confirmError);
      // Continue anyway - email might already be confirmed
    }

    // 2. Update profile status to approved and active
    const { error } = await supabase
      .from('profiles')
      .update({ 
        status: 'approved',
        account_status: 'active'
      })
      .eq('id', user.id);

    if (error) throw error;

    // 3. Send approval email
    await sendEmail('/api/admin/notify-approval', {
      email: user.email,
      name: user.full_name || user.first_name,
      user_id: user.id
    });

    alert(`✅ ${user.full_name || user.first_name || user.email} approved! An email notification has been sent.`);
    await loadData();
  } catch (error) {
    console.error('Error approving user:', error);
    alert('Failed to approve user: ' + error.message);
  }
}

// Re-approve a rejected user
async function reapproveUser(user) {
  if (!confirm(`Re-approve ${user.full_name || user.first_name || user.email}? This will allow them to login again.`)) return;
  
  try {
    const { error } = await supabase
      .from('profiles')
      .update({ 
        status: 'approved',
        account_status: 'active',
        rejection_reason: null
      })
      .eq('id', user.id);

    if (error) throw error;

    // Send re-approval email
    await sendEmail('/api/admin/notify-approval', {
      email: user.email,
      name: user.full_name || user.first_name,
      user_id: user.id
    });

    alert(`✅ ${user.full_name || user.first_name || user.email} re-approved! An email notification has been sent.`);
    await loadData();
  } catch (error) {
    console.error('Error re-approving user:', error);
    alert('Failed to re-approve user: ' + error.message);
  }
}

// Open rejection modal
function openRejectModal(user) {
  rejectUserData.value = user;
  selectedReason.value = '';
  customReason.value = '';
  showRejectModal.value = true;
}

function closeRejectModal() {
  showRejectModal.value = false;
  rejectUserData.value = null;
  selectedReason.value = '';
  customReason.value = '';
}

// Submit rejection with email notification
async function submitRejection() {
  if (!selectedReason.value) {
    alert('Please select a reason for rejection.');
    return;
  }

  // Get the rejection message
  let rejectionMessage = '';
  if (selectedReason.value === 'others') {
    rejectionMessage = customReason.value.trim();
    if (!rejectionMessage) {
      alert('Please specify the reason for rejection.');
      return;
    }
  } else {
    const reason = rejectionReasons.find(r => r.value === selectedReason.value);
    rejectionMessage = reason ? `${reason.label} — ${reason.description}` : selectedReason.value;
  }

  try {
    const { error } = await supabase
      .from('profiles')
      .update({ 
        status: 'rejected',
        account_status: 'deactivated',
        rejection_reason: rejectionMessage
      })
      .eq('id', rejectUserData.value.id);

    if (error) throw error;

    // Send rejection email
    await sendEmail('/api/admin/notify-rejection', {
      email: rejectUserData.value.email,
      name: rejectUserData.value.full_name || rejectUserData.value.first_name,
      reason: rejectionMessage
    });

    alert(`❌ ${rejectUserData.value.full_name || rejectUserData.value.first_name || rejectUserData.value.email} rejected! An email notification has been sent.`);
    closeRejectModal();
    await loadData();
  } catch (error) {
    console.error('Error rejecting user:', error);
    alert('Failed to reject user: ' + error.message);
  }
}

// ============================================================
// ACCOUNT STATUS ACTIONS
// ============================================================

async function updateAccountStatus(user, status) {
  const labels = { 
    active: 'activate', 
    suspended: 'suspend', 
    deactivated: 'deactivate' 
  };
  
  const confirmMsg = {
    active: `Are you sure you want to activate ${user.full_name || user.first_name || user.email}?`,
    suspended: `Are you sure you want to suspend ${user.full_name || user.first_name || user.email}? This will prevent them from logging in.`,
    deactivated: `Are you sure you want to deactivate ${user.full_name || user.first_name || user.email}? This is permanent.`
  };
  
  if (!confirm(confirmMsg[status])) return;
  
  try {
    const { error } = await supabase
      .from('profiles')
      .update({ account_status: status })
      .eq('id', user.id);

    if (error) throw error;

    // Send status change email
    await sendEmail('/api/admin/notify-status-change', {
      email: user.email,
      name: user.full_name || user.first_name,
      status: status
    });

    alert(`✅ ${user.full_name || user.first_name || user.email} has been ${labels[status]}d. An email notification has been sent.`);
    await loadData();
  } catch (error) {
    console.error('Error updating status:', error);
    alert('Failed to update status: ' + error.message);
  }
}

// ============================================================
// VIEW REJECTION REASON
// ============================================================

function showRejectionReason(user) {
  reasonUser.value = user;
  showReasonModal.value = true;
}

// ============================================================
// LIFECYCLE
// ============================================================

onMounted(() => {
  loadData();
});
</script>

<style scoped>
.card {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
}
.stat-card {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  padding: 1rem 1.15rem;
}
.field-input {
  width: 100%;
  padding: 0.5rem 0.7rem;
  font-size: 0.85rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  background: white;
}
.field-input:focus {
  outline: none;
  border-color: #ea580c;
  box-shadow: 0 0 0 3px rgba(234,88,12,0.12);
}
.field-label {
  font-size: 0.65rem;
  font-weight: 700;
  color: #64748b;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  display: block;
  margin-bottom: 0.25rem;
}
.btn-gradient { 
  background: linear-gradient(90deg, #ea580c, #f59e0b); 
  color: white; 
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  font-weight: 600;
}
.btn-gradient:hover { filter: brightness(1.05); }
.btn-sm-gradient {
  background: linear-gradient(90deg, #ea580c, #f59e0b);
  color: white;
  font-weight: 600;
  font-size: 0.75rem;
  padding: 0.35rem 0.75rem;
  border-radius: 0.4rem;
}
.btn-sm-gradient:hover { filter: brightness(1.05); }
.btn-outline {
  border: 1px solid #d1d5db;
  color: #334155;
  font-weight: 600;
  font-size: 0.75rem;
  padding: 0.35rem 0.75rem;
  border-radius: 0.4rem;
  transition: background 0.15s ease;
}
.btn-outline:hover:not(:disabled) { background: #f8fafc; }
.btn-outline:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-active:hover:not(:disabled) { background: #dcfce7; border-color: #22c55e; color: #15803d; }
.btn-suspend:hover:not(:disabled) { background: #fef3c7; border-color: #f59e0b; color: #b45309; }
.btn-danger-outline {
  border: 1px solid #fecaca;
  color: #b91c1c;
  font-weight: 600;
  font-size: 0.75rem;
  padding: 0.35rem 0.75rem;
  border-radius: 0.4rem;
  background: white;
}
.btn-danger-outline:hover:not(:disabled) { background: #fef2f2; }
.btn-danger-outline:disabled { opacity: 0.5; cursor: not-allowed; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
.admin-table th {
  text-align: left;
  font-size: 0.65rem;
  font-weight: 700;
  color: #64748b;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  padding: 0.6rem 0.75rem;
  border-bottom: 1px solid #e5e7eb;
}
.admin-table td {
  padding: 0.65rem 0.75rem;
  border-bottom: 1px solid #f1f5f9;
  color: #334155;
  vertical-align: middle;
}
.admin-table tr:hover td { background: #fafafa; }
.badge {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.02em;
}
.badge-green { background: #dcfce7; color: #15803d; }
.badge-amber { background: #fef3c7; color: #b45309; }
.badge-red { background: #fee2e2; color: #b91c1c; }
.badge-slate { background: #f1f5f9; color: #475569; }
.role-badge {
  display: inline-block;
  padding: 0.15rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}
.status-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  margin-right: 6px;
}
.status-dot.active { background: #22c55e; }
.status-dot.suspended { background: #f59e0b; }
.status-dot.deactivated { background: #ef4444; }
.status-dot.pending { background: #f59e0b; }
.status-dot.approved { background: #22c55e; }
.status-dot.rejected { background: #ef4444; }
</style>
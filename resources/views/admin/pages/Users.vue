<!-- resources/js/admin/pages/Users.vue -->
<template>
  <div>
    <p class="text-sm text-slate-500 mb-4">View user profiles and activate, suspend, or deactivate accounts.</p>
    
    <!-- Filters -->
    <div class="flex gap-4 mb-4 flex-wrap">
      <input type="text" v-model="search" placeholder="Search users..." class="field-input w-64" />
      <select v-model="roleFilter" class="field-input w-40">
        <option value="">All Roles</option>
        <option value="buyer">Buyer</option>
        <option value="seller">Seller</option>
        <option value="courier">Courier</option>
        <option value="admin">Admin</option>
      </select>
      <select v-model="statusFilter" class="field-input w-40">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="deactivated">Deactivated</option>
      </select>
      <button @click="loadData" class="btn-gradient text-white px-4 py-2 rounded-lg">Filter</button>
    </div>

    <div class="card overflow-hidden">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="5" class="text-center py-4 text-slate-500">Loading...</td>
          </tr>
          <tr v-else-if="users.length === 0">
            <td colspan="5" class="text-center py-4 text-slate-500">No users found.</td>
          </tr>
          <tr v-for="(user, idx) in users" :key="idx">
            <td class="font-medium text-slate-800">{{ user.full_name || user.name }}</td>
            <td>{{ user.email }}</td>
            <td>{{ user.role }}</td>
            <td>
              <span class="status-dot" :class="user.account_status || user.status"></span>
              <span class="badge" :class="statusBadgeClass(user.account_status || user.status)">{{ user.account_status || user.status }}</span>
            </td>
            <td>
              <div class="flex gap-2">
                <button @click="updateStatus(user, 'active')" class="btn-outline" :disabled="user.account_status === 'active'">Activate</button>
                <button @click="updateStatus(user, 'suspended')" class="btn-outline" :disabled="user.account_status === 'suspended'">Suspend</button>
                <button @click="updateStatus(user, 'deactivated')" class="btn-danger-outline" :disabled="user.account_status === 'deactivated'">Deactivate</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { 
  users, 
  loading, 
  loadUsers, 
  updateUserStatus,
  statusBadgeClass
} = useAdmin();

const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');

const loadData = () => {
  loadUsers(search.value, roleFilter.value, statusFilter.value);
};

const updateStatus = async (user, status) => {
  await updateUserStatus(user, status);
  loadData();
};

onMounted(() => {
  loadData();
});
</script>

<style scoped>
/* Same styles as Registrations.vue */
.card {
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
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
.btn-gradient { background: linear-gradient(90deg, #ea580c, #f59e0b); color: white; }
.btn-gradient:hover { filter: brightness(1.05); }
.btn-outline {
  border: 1px solid #d1d5db;
  color: #334155;
  font-weight: 600;
  font-size: 0.75rem;
  padding: 0.35rem 0.75rem;
  border-radius: 0.4rem;
}
.btn-outline:hover { background: #f8fafc; }
.btn-danger-outline {
  border: 1px solid #fecaca;
  color: #b91c1c;
  font-weight: 600;
  font-size: 0.75rem;
  padding: 0.35rem 0.75rem;
  border-radius: 0.4rem;
  background: white;
}
.btn-danger-outline:hover { background: #fef2f2; }
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
</style>
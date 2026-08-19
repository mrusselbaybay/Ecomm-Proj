<!-- resources/js/admin/pages/Registrations.vue -->
<template>
    <div>
        <p class="mb-4 text-sm text-slate-500">
            Review submitted information and requirements, then approve or
            disapprove. The applicant is notified of the decision by email.
        </p>

        <!-- Filters -->
        <div class="mb-4 flex flex-wrap gap-4">
            <input
                type="text"
                v-model="search"
                placeholder="Search applicants..."
                class="field-input w-64"
            />
            <select v-model="roleFilter" class="field-input w-40">
                <option value="">All Roles</option>
                <option value="buyer">Buyer</option>
                <option value="seller">Seller</option>
                <option value="courier">Courier</option>
            </select>
            <select v-model="statusFilter" class="field-input w-40">
                <option value="">All Status</option>
                <option value="pending">Pending</option>
                <option value="rejected">Rejected</option>
            </select>
            <button
                @click="loadData"
                class="btn-gradient rounded-lg px-4 py-2 text-white"
            >
                Filter
            </button>
        </div>

        <div class="card overflow-hidden">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Role</th>
                        <th>Email</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="6" class="py-4 text-center text-slate-500">
                            Loading...
                        </td>
                    </tr>
                    <tr v-else-if="registrations.length === 0">
                        <td colspan="6" class="py-4 text-center text-slate-500">
                            No registrations found.
                        </td>
                    </tr>
                    <tr v-for="(user, idx) in registrations" :key="idx">
                        <td class="font-medium text-slate-800">
                            {{ user.full_name || user.name }}
                        </td>
                        <td>{{ user.role }}</td>
                        <td>{{ user.email }}</td>
                        <td>{{ formatDate(user.created_at) }}</td>
                        <td>
                            <span
                                class="status-dot"
                                :class="user.status.toLowerCase()"
                            ></span>
                            <span
                                class="badge"
                                :class="statusBadgeClass(user.status)"
                                >{{ user.status }}</span
                            >
                        </td>
                        <td>
                            <div class="flex gap-2">
                                <button
                                    @click="approveUser(user)"
                                    class="btn-sm-gradient"
                                    :disabled="user.status === 'approved'"
                                >
                                    Approve
                                </button>
                                <button
                                    @click="rejectUser(user)"
                                    class="btn-danger-outline"
                                    :disabled="user.status === 'rejected'"
                                >
                                    Reject
                                </button>
                                <button
                                    @click="viewRegistration(user)"
                                    class="btn-outline"
                                >
                                    View Docs
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Rejection Modal -->
        <div
            v-if="showRejectModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="card w-96 p-6">
                <h3 class="mb-2 font-bold text-slate-900">
                    Reject Application
                </h3>
                <p class="mb-4 text-sm text-slate-500">
                    Please provide a reason for rejecting
                    {{ userToReject?.full_name || userToReject?.name }}'s
                    application.
                </p>
                <div>
                    <label class="field-label"
                        >Reason <span class="text-orange-500">*</span></label
                    >
                    <textarea
                        v-model="rejectReason"
                        rows="3"
                        placeholder="Explain why this application is being rejected..."
                        class="field-input"
                    ></textarea>
                </div>
                <div class="mt-4 flex gap-2">
                    <button
                        @click="showRejectModal = false"
                        class="btn-outline flex-1 py-2"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitRejection"
                        class="btn-danger-outline flex-1 py-2"
                    >
                        Reject
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const {
    registrations,
    loading,
    loadRegistrations,
    approveUser,
    rejectUser,
    viewRegistration,
    statusBadgeClass,
    formatDate,
} = useAdmin();

const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');
const showRejectModal = ref(false);
const userToReject = ref(null);
const rejectReason = ref('');

const loadData = () => {
    loadRegistrations(search.value, roleFilter.value, statusFilter.value);
};

const submitRejection = async () => {
    if (!rejectReason.value.trim()) {
        alert('Please provide a reason for rejection.');

        return;
    }

    await rejectUser(userToReject.value, rejectReason.value);
    showRejectModal.value = false;
    userToReject.value = null;
    rejectReason.value = '';
    loadData();
};

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
    box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.12);
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
}
.btn-gradient:hover {
    filter: brightness(1.05);
}
.btn-sm-gradient {
    background: linear-gradient(90deg, #ea580c, #f59e0b);
    color: white;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: 0.4rem;
}
.btn-danger-outline {
    border: 1px solid #fecaca;
    color: #b91c1c;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: 0.4rem;
    background: white;
}
.btn-outline {
    border: 1px solid #d1d5db;
    color: #334155;
    font-weight: 600;
    font-size: 0.75rem;
    padding: 0.35rem 0.75rem;
    border-radius: 0.4rem;
}
.admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
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
.admin-table tr:hover td {
    background: #fafafa;
}
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.55rem;
    border-radius: 9999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.02em;
}
.badge-green {
    background: #dcfce7;
    color: #15803d;
}
.badge-amber {
    background: #fef3c7;
    color: #b45309;
}
.badge-red {
    background: #fee2e2;
    color: #b91c1c;
}
.status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
}
.status-dot.pending {
    background: #f59e0b;
}
.status-dot.approved {
    background: #22c55e;
}
.status-dot.rejected {
    background: #ef4444;
}
</style>

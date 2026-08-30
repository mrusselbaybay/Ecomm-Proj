<!-- resources/js/admin/components/Registrations.vue -->
<template>
    <div>
        <p class="mb-4 text-sm text-slate-500">
            Review submitted information and requirements, then approve or
            disapprove. The applicant is notified of the decision by email.
        </p>

        <div class="mb-4 flex flex-wrap gap-4">
            <input
                type="text"
                v-model="search"
                placeholder="Search applicants..."
                class="field-input w-64"
                @input="loadData"
            />
            <select
                v-model="roleFilter"
                class="field-input w-40"
                @change="loadData"
            >
                <option value="">All Roles</option>
                <option value="buyer">Buyer</option>
                <option value="seller">Seller</option>
                <option value="courier">Courier</option>
                <option value="driver">Driver</option>
            </select>
            <select
                v-model="statusFilter"
                class="field-input w-40"
                @change="loadData"
            >
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
                            No pending registrations found.
                        </td>
                    </tr>
                    <tr v-for="(user, idx) in registrations" :key="idx">
                        <td class="font-medium text-slate-800">
                            {{
                                user.full_name || user.first_name || user.email
                            }}
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
                                    @click="openRejectModal(user)"
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

        <div
            v-if="pagination.last_page > 1"
            class="mt-4 flex items-center justify-between text-sm text-slate-500"
        >
            <span>
                Page {{ pagination.current_page }} of {{ pagination.last_page }}
            </span>
            <div class="flex gap-2">
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === 1"
                    @click="loadData(pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadData(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>

        <!-- Rejection Modal -->
        <div
            v-if="showRejectModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        >
            <div class="card max-h-[90vh] w-96 overflow-y-auto p-6">
                <h3 class="mb-2 font-bold text-slate-900">
                    Reject Application
                </h3>
                <p class="mb-4 text-sm text-slate-500">
                    Please select a reason for rejecting
                    <strong>{{
                        rejectUserData?.full_name ||
                        rejectUserData?.first_name ||
                        rejectUserData?.email
                    }}</strong
                    >'s application:
                </p>

                <div class="space-y-3">
                    <div
                        v-for="reason in rejectionReasons"
                        :key="reason.value"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition-colors hover:bg-slate-50"
                        :class="{
                            'border-orange-500 bg-orange-50':
                                selectedReason === reason.value,
                        }"
                        @click="selectedReason = reason.value"
                    >
                        <input
                            type="radio"
                            :value="reason.value"
                            v-model="selectedReason"
                            class="mt-1"
                        />
                        <div>
                            <p class="text-sm font-medium text-slate-800">
                                {{ reason.label }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ reason.description }}
                            </p>
                        </div>
                    </div>

                    <!-- Others option with text box -->
                    <div
                        class="flex items-start gap-3 rounded-lg border border-slate-200 p-3"
                        :class="{
                            'border-orange-500 bg-orange-50':
                                selectedReason === 'others',
                        }"
                    >
                        <input
                            type="radio"
                            value="others"
                            v-model="selectedReason"
                            class="mt-1"
                        />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-slate-800">
                                Others
                            </p>
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

                <div class="mt-4 flex gap-2">
                    <button
                        @click="closeRejectModal"
                        class="btn-outline flex-1 py-2"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitRejection"
                        class="btn-danger-outline flex-1 py-2"
                        :disabled="!canReject"
                    >
                        Reject
                    </button>
                </div>
            </div>
        </div>

        <!-- Approve/Reject Result Modal -->
        <div
            v-if="showResultModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="closeResultModal"
        >
            <div class="card w-96 p-6 text-center">
                <div
                    class="result-icon"
                    :class="resultModalIsError ? 'result-icon-error' : 'result-icon-success'"
                >
                    {{ resultModalIsError ? '!' : '✓' }}
                </div>
                <h3 class="mb-2 font-bold text-slate-900">
                    {{ resultModalIsError ? 'Something went wrong' : 'Done' }}
                </h3>
                <p class="mb-5 text-sm text-slate-600">
                    {{ resultModalMessage }}
                </p>
                <button
                    @click="closeResultModal"
                    class="btn-gradient w-full py-2"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onActivated, onBeforeUnmount, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const {
    registrations,
    pendingCount,
    statusBadgeClass,
    formatDate,
    adminFetch,
    supabase,
} = useAdmin();

const search = ref('');
const roleFilter = ref('');
const statusFilter = ref('');
const loading = ref(false);
const showRejectModal = ref(false);
const rejectUserData = ref(null);
const selectedReason = ref('');
const customReason = ref('');
const showResultModal = ref(false);
const resultModalMessage = ref('');
const resultModalIsError = ref(false);
const pagination = ref({
    current_page: 1,
    last_page: 1,
});
let searchTimer;

const rejectionReasons = [
    {
        value: 'invalid_information',
        label: 'Invalid or incomplete information',
        description:
            "The submitted details are missing, incorrect, or don't match the requirements.",
    },
    {
        value: 'invalid_id',
        label: 'Invalid identification',
        description:
            'The uploaded ID or supporting document is unclear, expired, or cannot be verified.',
    },
    {
        value: 'not_eligible',
        label: 'Does not meet eligibility requirements',
        description: 'The user does not qualify for the platform.',
    },
    {
        value: 'fraudulent',
        label: 'Suspicious or fraudulent information',
        description:
            'The submitted information appears fraudulent or misleading.',
    },
];

const canReject = computed(
    () =>
        selectedReason.value &&
        (selectedReason.value !== 'others' || customReason.value.trim()),
);

async function loadData(page = 1) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(async () => {
        loading.value = true;

        try {
            const params = new URLSearchParams({ page: String(page) });

            if (search.value.trim()) {
                params.set('search', search.value.trim());
            }

            if (roleFilter.value) {
                params.set('role', roleFilter.value);
            }

            if (statusFilter.value) {
                params.set('status', statusFilter.value);
            }

            const response = await adminFetch(
                `/api/admin/registrations?${params.toString()}`,
            );
            const payload = await response.json();

            registrations.value = payload.applications.data;
            pendingCount.value = payload.counts.pending;
            pagination.value = {
                current_page: payload.applications.current_page,
                last_page: payload.applications.last_page,
            };
        } catch (error) {
            window.alert(error.message);
        } finally {
            loading.value = false;
        }
    }, 250);
}

function showResult(message, isError = false) {
    resultModalMessage.value = message;
    resultModalIsError.value = isError;
    showResultModal.value = true;
}

function closeResultModal() {
    showResultModal.value = false;
    resultModalMessage.value = '';
    resultModalIsError.value = false;
}

async function approveUser(user) {
    if (!window.confirm(`Approve ${user.full_name || user.email}?`)) {
        return;
    }

    try {
        const response = await adminFetch(
            `/api/admin/registrations/${user.id}/approve`,
            { method: 'POST' },
        );
        const payload = await response.json();

        showResult(payload.message);
        await loadData(pagination.value.current_page);
    } catch (error) {
        showResult(`Failed to approve application: ${error.message}`, true);
    }
}

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

async function submitRejection() {
    if (!canReject.value) {
        return;
    }

    const reasonDefinition = rejectionReasons.find(
        (reason) => reason.value === selectedReason.value,
    );
    const reason =
        selectedReason.value === 'others'
            ? customReason.value.trim()
            : `${reasonDefinition.label} — ${reasonDefinition.description}`;

    try {
        const response = await adminFetch(
            `/api/admin/registrations/${rejectUserData.value.id}/reject`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason }),
            },
        );
        const payload = await response.json();

        closeRejectModal();
        showResult(payload.message);
        await loadData(pagination.value.current_page);
    } catch (error) {
        showResult(`Failed to reject application: ${error.message}`, true);
    }
}

async function viewRegistration(user) {
    if (!user.documents?.length) {
        window.alert('This applicant has not submitted any documents.');

        return;
    }

    try {
        const { data, error } = await supabase.storage
            .from('documents')
            .createSignedUrl(user.documents[0].storage_path, 300);

        if (error) {
            throw error;
        }

        window.open(data.signedUrl, '_blank', 'noopener,noreferrer');

        if (user.documents.length > 1) {
            window.alert(
                `Opened the first of ${user.documents.length} submitted documents.`,
            );
        }
    } catch (error) {
        window.alert(`Unable to open document: ${error.message}`);
    }
}

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated (not onMounted) fires both on first visit and every time the
// admin returns to this tab — reloading the current page/filters instead of
// leaving whatever list was showing when they left (which may since have
// been approved/rejected by someone else).
onActivated(() => loadData(pagination.value.current_page));

onBeforeUnmount(() => clearTimeout(searchTimer));
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
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-weight: 600;
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
.result-icon {
    width: 3.5rem;
    height: 3.5rem;
    margin: 0 auto 1rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
}
.result-icon-success {
    background: #dcfce7;
    color: #15803d;
}
.result-icon-error {
    background: #fee2e2;
    color: #b91c1c;
}
</style>

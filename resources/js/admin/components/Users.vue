<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">User accounts</h2>
                <p class="mt-1 text-sm text-slate-500">
                    View approved user profiles and control account access.
                </p>
            </div>
            <button class="btn-primary" @click="openStaffModal">
                Add platform admin
            </button>
        </div>

        <div
            v-if="message"
            class="rounded-lg border px-4 py-3 text-sm"
            :class="
                messageType === 'error'
                    ? 'border-red-200 bg-red-50 text-red-700'
                    : 'border-emerald-200 bg-emerald-50 text-emerald-700'
            "
        >
            {{ message }}
        </div>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article
                v-for="item in summaryCards"
                :key="item.label"
                class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
            >
                <p
                    class="text-xs font-semibold tracking-wide text-slate-500 uppercase"
                >
                    {{ item.label }}
                </p>
                <p class="mt-2 text-2xl font-bold text-slate-900">
                    {{ item.value }}
                </p>
            </article>
        </section>

        <div class="flex flex-wrap gap-3">
            <input
                v-model="search"
                type="search"
                class="field-input w-64"
                placeholder="Search name, email, or phone..."
                @input="scheduleLoad"
            />
            <select
                v-model="roleFilter"
                class="field-input w-44"
                @change="loadAccounts(1)"
            >
                <option value="">All roles</option>
                <option value="buyer">Buyer</option>
                <option value="seller">Seller</option>
                <option value="courier">Courier</option>
                <option value="driver">Driver</option>
                <option value="logistics">Logistics owner</option>
                <option value="logistics_admin">Logistics admin</option>
                <option value="admin">Administrator</option>
            </select>
            <select
                v-model="accountStatusFilter"
                class="field-input w-44"
                @change="loadAccounts(1)"
            >
                <option value="">All account statuses</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="deactivated">Deactivated</option>
            </select>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white"
        >
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Account status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="5" class="py-8 text-center text-slate-500">
                            Loading accounts...
                        </td>
                    </tr>
                    <tr v-else-if="accounts.length === 0">
                        <td colspan="5" class="py-8 text-center text-slate-500">
                            No approved accounts match these filters.
                        </td>
                    </tr>
                    <tr v-for="account in accounts" :key="account.id">
                        <td>
                            <p class="font-semibold text-slate-800">
                                {{ account.full_name }}
                            </p>
                            <p class="text-xs text-slate-500">
                                {{ account.email }}
                            </p>
                        </td>
                        <td class="capitalize">
                            {{ formatRole(account.role) }}
                        </td>
                        <td>
                            <span
                                class="badge capitalize"
                                :class="
                                    statusBadgeClass(account.account_status)
                                "
                            >
                                {{ account.account_status }}
                            </span>
                        </td>
                        <td>{{ formatDate(account.created_at) }}</td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    class="btn-outline"
                                    @click="viewAccount(account)"
                                >
                                    View profile
                                </button>
                                <button
                                    v-if="account.account_status !== 'active'"
                                    class="btn-success"
                                    :disabled="account.id === currentAdminId"
                                    @click="
                                        openStatusModal(account, 'activate')
                                    "
                                >
                                    Activate
                                </button>
                                <button
                                    v-if="
                                        account.account_status !== 'suspended'
                                    "
                                    class="btn-warning"
                                    :disabled="account.id === currentAdminId"
                                    @click="openStatusModal(account, 'suspend')"
                                >
                                    Suspend
                                </button>
                                <button
                                    v-if="
                                        account.account_status !== 'deactivated'
                                    "
                                    class="btn-danger"
                                    :disabled="account.id === currentAdminId"
                                    @click="
                                        openStatusModal(account, 'deactivate')
                                    "
                                >
                                    Deactivate
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.last_page > 1"
            class="flex items-center justify-between text-sm text-slate-500"
        >
            <span>
                Page {{ pagination.current_page }} of {{ pagination.last_page }}
            </span>
            <div class="flex gap-2">
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === 1"
                    @click="loadAccounts(pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadAccounts(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>

        <div
            v-if="selectedAccount"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="selectedAccount = null"
        >
            <div
                class="modal-card max-h-[90vh] w-full max-w-3xl overflow-y-auto"
            >
                <div class="flex items-start justify-between border-b p-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            {{ selectedAccount.full_name }}
                        </h3>
                        <p class="text-sm text-slate-500 capitalize">
                            {{ formatRole(selectedAccount.role) }}
                        </p>
                    </div>
                    <button class="btn-outline" @click="selectedAccount = null">
                        Close
                    </button>
                </div>

                <div
                    v-if="profileLoading"
                    class="p-8 text-center text-slate-500"
                >
                    Loading profile...
                </div>
                <div v-else class="space-y-6 p-5">
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="field-label">Email</dt>
                            <dd>{{ selectedAccount.email }}</dd>
                        </div>
                        <div>
                            <dt class="field-label">Phone</dt>
                            <dd>
                                {{
                                    selectedAccount.contact_no || 'Not provided'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="field-label">Birthday</dt>
                            <dd>{{ formatDate(selectedAccount.birthday) }}</dd>
                        </div>
                        <div>
                            <dt class="field-label">Address</dt>
                            <dd>
                                {{
                                    selectedAccount.address?.full_address ||
                                    'Not provided'
                                }}
                            </dd>
                        </div>
                        <div v-if="selectedAccount.seller_detail">
                            <dt class="field-label">Seller business</dt>
                            <dd>
                                {{
                                    selectedAccount.seller_detail.business_name
                                }}
                                —
                                {{
                                    selectedAccount.seller_detail
                                        .line_of_business
                                }}
                            </dd>
                        </div>
                        <div v-if="selectedAccount.courier_detail">
                            <dt class="field-label">Courier vehicle</dt>
                            <dd>
                                {{ selectedAccount.courier_detail.vehicle }}
                                ({{
                                    selectedAccount.courier_detail.plate_number
                                }})
                            </dd>
                        </div>
                        <div v-if="selectedAccount.driver_detail">
                            <dt class="field-label">Driver details</dt>
                            <dd>
                                {{ selectedAccount.driver_detail.vehicle }}
                                ({{
                                    selectedAccount.driver_detail.plate_number
                                }}) · License
                                {{
                                    selectedAccount.driver_detail
                                        .license_number || 'not provided'
                                }}
                            </dd>
                        </div>
                    </dl>

                    <section>
                        <h4 class="font-bold text-slate-900">Status history</h4>
                        <p
                            v-if="!selectedAccount.status_history?.length"
                            class="mt-2 text-sm text-slate-500"
                        >
                            No status changes recorded.
                        </p>
                        <div v-else class="mt-3 space-y-2">
                            <article
                                v-for="entry in selectedAccount.status_history"
                                :key="entry.id"
                                class="rounded-lg border border-slate-200 p-3 text-sm"
                            >
                                <p
                                    class="font-semibold text-slate-800 capitalize"
                                >
                                    {{ entry.old_status || 'Unknown' }} →
                                    {{ entry.new_status }}
                                </p>
                                <p class="mt-1 text-slate-600">
                                    {{ entry.reason || 'No reason provided' }}
                                </p>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ entry.changed_by || 'Administrator' }} ·
                                    {{ formatDate(entry.created_at) }}
                                </p>
                            </article>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <div
            v-if="statusAccount"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeStatusModal"
        >
            <div class="modal-card w-full max-w-md p-6">
                <h3 class="text-lg font-bold text-slate-900 capitalize">
                    {{ statusAction }} account
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    Update {{ statusAccount.full_name }}'s account access.
                </p>

                <div v-if="statusAction !== 'activate'" class="mt-4">
                    <label class="field-label" for="status-reason">
                        Reason
                    </label>
                    <textarea
                        id="status-reason"
                        v-model="statusReason"
                        rows="4"
                        class="field-input"
                        placeholder="Provide a reason for this action..."
                    ></textarea>
                    <p class="mt-1 text-xs text-slate-400">
                        At least 5 characters. This is recorded and included in
                        the email notification.
                    </p>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <button class="btn-outline" @click="closeStatusModal">
                        Cancel
                    </button>
                    <button
                        class="btn-primary"
                        :disabled="!canSubmitStatus || savingStatus"
                        @click="submitStatusChange"
                    >
                        {{ savingStatus ? 'Saving...' : 'Confirm' }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="showStaffModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeStaffModal"
        >
            <form
                class="modal-card max-h-[90vh] w-full max-w-lg overflow-y-auto p-6"
                @submit.prevent="createStaffAccount"
            >
                <h3 class="text-lg font-bold text-slate-900">
                    Add platform admin
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    Create a new platform administrator account.
                </p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="staff-first-name">
                            First name
                        </label>
                        <input
                            id="staff-first-name"
                            v-model.trim="staffForm.first_name"
                            class="field-input"
                            required
                        />
                    </div>
                    <div>
                        <label class="field-label" for="staff-last-name">
                            Last name
                        </label>
                        <input
                            id="staff-last-name"
                            v-model.trim="staffForm.last_name"
                            class="field-input"
                            required
                        />
                    </div>
                    <div>
                        <label class="field-label" for="staff-middle-initial">
                            Middle initial
                        </label>
                        <input
                            id="staff-middle-initial"
                            v-model.trim="staffForm.middle_initial"
                            class="field-input"
                            maxlength="2"
                        />
                    </div>
                    <div>
                        <label class="field-label" for="staff-email"
                            >Email</label
                        >
                        <input
                            id="staff-email"
                            v-model.trim="staffForm.email"
                            type="email"
                            class="field-input"
                            required
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <label class="field-label" for="staff-password">
                            Temporary password
                        </label>
                        <div class="flex gap-2">
                            <input
                                id="staff-password"
                                v-model="staffForm.password"
                                class="field-input"
                                minlength="12"
                                required
                            />
                            <button
                                type="button"
                                class="btn-outline"
                                @click="generateStaffPassword"
                            >
                                Generate
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-slate-400">
                            Credentials are emailed after account creation.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button
                        type="button"
                        class="btn-outline"
                        @click="closeStaffModal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn-primary"
                        :disabled="creatingStaff"
                    >
                        {{
                            creatingStaff
                                ? 'Creating...'
                                : 'Create platform admin'
                        }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { computed, onActivated, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { accounts, statusBadgeClass, formatDate, adminFetch, supabase } =
    useAdmin();

const search = ref('');
const roleFilter = ref('');
const accountStatusFilter = ref('');
const loading = ref(false);
const profileLoading = ref(false);
const savingStatus = ref(false);
const creatingStaff = ref(false);
const showStaffModal = ref(false);
const currentAdminId = ref(null);
const selectedAccount = ref(null);
const statusAccount = ref(null);
const statusAction = ref('');
const statusReason = ref('');
const message = ref('');
const messageType = ref('success');
const summary = ref({
    total: 0,
    active: 0,
    suspended: 0,
    deactivated: 0,
});
const pagination = ref({
    current_page: 1,
    last_page: 1,
});
const staffForm = ref({
    role: 'admin',
    first_name: '',
    last_name: '',
    middle_initial: '',
    email: '',
    password: '',
});

let searchTimer;

const summaryCards = computed(() => [
    { label: 'Total approved', value: summary.value.total },
    { label: 'Active', value: summary.value.active },
    { label: 'Suspended', value: summary.value.suspended },
    { label: 'Deactivated', value: summary.value.deactivated },
]);

const canSubmitStatus = computed(
    () =>
        statusAction.value === 'activate' ||
        statusReason.value.trim().length >= 5,
);

function formatRole(role) {
    return role?.replaceAll('_', ' ') || 'Unknown';
}

function showMessage(text, type = 'success') {
    message.value = text;
    messageType.value = type;
}

function resetStaffForm() {
    staffForm.value = {
        first_name: '',
        last_name: '',
        middle_initial: '',
        email: '',
        password: '',
    };
}

function openStaffModal() {
    resetStaffForm();
    generateStaffPassword();
    showStaffModal.value = true;
}

function closeStaffModal() {
    showStaffModal.value = false;
}

function generateStaffPassword() {
    const characters =
        'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    const randomValues = new Uint32Array(16);
    crypto.getRandomValues(randomValues);

    staffForm.value.password = Array.from(
        randomValues,
        (value) => characters[value % characters.length],
    ).join('');
}

async function createStaffAccount() {
    creatingStaff.value = true;

    try {
        const response = await adminFetch('/api/admin/staff', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(staffForm.value),
        });
        const payload = await response.json();

        closeStaffModal();
        showMessage(payload.message);
        await loadAccounts(1);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        creatingStaff.value = false;
    }
}

function scheduleLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadAccounts(1), 350);
}

async function loadAccounts(page = 1) {
    loading.value = true;

    try {
        const params = new URLSearchParams({ page: String(page) });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (roleFilter.value) {
            params.set('role', roleFilter.value);
        }

        if (accountStatusFilter.value) {
            params.set('account_status', accountStatusFilter.value);
        }

        const response = await adminFetch(
            `/api/admin/accounts?${params.toString()}`,
        );
        const payload = await response.json();

        accounts.value = payload.accounts.data;
        summary.value = payload.summary;
        pagination.value = {
            current_page: payload.accounts.current_page,
            last_page: payload.accounts.last_page,
        };
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        loading.value = false;
    }
}

async function viewAccount(account) {
    selectedAccount.value = account;
    profileLoading.value = true;

    try {
        const response = await adminFetch(`/api/admin/accounts/${account.id}`);
        const payload = await response.json();

        selectedAccount.value = payload.account;
    } catch (error) {
        selectedAccount.value = null;
        showMessage(error.message, 'error');
    } finally {
        profileLoading.value = false;
    }
}

function openStatusModal(account, action) {
    statusAccount.value = account;
    statusAction.value = action;
    statusReason.value = '';
}

function closeStatusModal() {
    statusAccount.value = null;
    statusAction.value = '';
    statusReason.value = '';
}

async function submitStatusChange() {
    if (!canSubmitStatus.value) {
        return;
    }

    savingStatus.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/accounts/${statusAccount.value.id}/status`,
            {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: statusAction.value,
                    reason: statusReason.value.trim() || null,
                }),
            },
        );
        const payload = await response.json();

        showMessage(payload.message);
        closeStatusModal();
        selectedAccount.value = null;
        await loadAccounts(pagination.value.current_page);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        savingStatus.value = false;
    }
}

// currentAdminId never changes for the life of this session, so it only
// needs fetching once — unlike the account list below, it doesn't belong in
// onActivated.
onMounted(async () => {
    const {
        data: { user },
    } = await supabase.auth.getUser();

    currentAdminId.value = user?.id || null;
});

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated fires both on first visit and every time the admin returns to
// this tab — reloading the current page/filters so account status changes
// made elsewhere (or by another admin) show up instead of a stale list.
onActivated(() => loadAccounts(pagination.value.current_page));

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<style scoped>
.field-input {
    width: 100%;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    background: white;
    padding: 0.55rem 0.75rem;
    font-size: 0.85rem;
}
.field-input:focus {
    border-color: #0d9488;
    outline: none;
    box-shadow: 0 0 0 3px rgb(13 148 136 / 12%);
}
.field-label {
    display: block;
    margin-bottom: 0.25rem;
    color: #64748b;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.admin-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.admin-table th {
    border-bottom: 1px solid #e5e7eb;
    padding: 0.7rem 0.75rem;
    color: #64748b;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-align: left;
    text-transform: uppercase;
}
.admin-table td {
    border-bottom: 1px solid #f1f5f9;
    padding: 0.75rem;
    color: #334155;
}
.badge {
    display: inline-flex;
    border-radius: 9999px;
    padding: 0.15rem 0.55rem;
    font-size: 0.68rem;
    font-weight: 700;
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
.modal-card {
    border-radius: 0.85rem;
    background: white;
    box-shadow: 0 24px 64px rgb(15 23 42 / 25%);
}
.btn-outline,
.btn-success,
.btn-warning,
.btn-danger,
.btn-primary {
    border-radius: 0.4rem;
    padding: 0.4rem 0.7rem;
    font-size: 0.75rem;
    font-weight: 600;
}
.btn-outline {
    border: 1px solid #d1d5db;
    background: white;
    color: #334155;
}
.btn-success,
.btn-primary {
    background: #0d9488;
    color: white;
}
.btn-warning {
    border: 1px solid #fcd34d;
    background: #fffbeb;
    color: #a16207;
}
.btn-danger {
    border: 1px solid #fecaca;
    background: #fef2f2;
    color: #b91c1c;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>

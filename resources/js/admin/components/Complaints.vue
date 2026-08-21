<template>
    <div class="space-y-5">
        <div>
            <h2 class="text-xl font-bold text-slate-900">
                Complaints &amp; disputes
            </h2>
            <p class="mt-1 text-sm text-slate-500">
                Review evidence, coordinate involved users, and record case
                resolutions.
            </p>
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
                class="field-input w-72"
                placeholder="Search cases, users, or order number..."
                @input="scheduleLoad"
            />
            <select
                v-model="statusFilter"
                class="field-input w-48"
                @change="loadComplaints(1)"
            >
                <option value="">All case statuses</option>
                <option value="pending">Pending</option>
                <option value="under_review">Under review</option>
                <option value="awaiting_response">Awaiting response</option>
                <option value="resolved">Resolved</option>
                <option value="dismissed">Dismissed</option>
            </select>
            <select
                v-model="priorityFilter"
                class="field-input w-40"
                @change="loadComplaints(1)"
            >
                <option value="">All priorities</option>
                <option value="urgent">Urgent</option>
                <option value="high">High</option>
                <option value="normal">Normal</option>
                <option value="low">Low</option>
            </select>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-slate-200 bg-white"
        >
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Case</th>
                        <th>Complainant</th>
                        <th>Against</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Assigned to</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            Loading complaints...
                        </td>
                    </tr>
                    <tr v-else-if="complaints.length === 0">
                        <td colspan="7" class="py-8 text-center text-slate-500">
                            No complaints match these filters.
                        </td>
                    </tr>
                    <tr v-for="complaint in complaints" :key="complaint.id">
                        <td>
                            <p class="max-w-xs font-semibold text-slate-800">
                                {{ complaint.subject }}
                            </p>
                            <p class="text-xs text-slate-500 capitalize">
                                {{ complaint.type
                                }}<span v-if="complaint.order">
                                    · {{ complaint.order.order_number }}</span
                                >
                            </p>
                        </td>
                        <td>
                            <p class="font-medium text-slate-700">
                                {{ complaint.complainant?.full_name }}
                            </p>
                            <p class="text-xs text-slate-500 capitalize">
                                {{ complaint.complainant?.role }}
                            </p>
                        </td>
                        <td>
                            {{ complaint.respondent?.full_name || 'Platform' }}
                        </td>
                        <td>
                            <span
                                class="badge"
                                :class="statusClass(complaint.status)"
                                >{{ label(complaint.status) }}</span
                            >
                        </td>
                        <td>
                            <span
                                class="badge"
                                :class="priorityClass(complaint.priority)"
                                >{{ label(complaint.priority) }}</span
                            >
                        </td>
                        <td>
                            {{
                                complaint.assigned_admin?.full_name ||
                                'Unassigned'
                            }}
                        </td>
                        <td>
                            <button
                                class="btn-outline"
                                @click="openComplaint(complaint)"
                            >
                                Review case
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="pagination.last_page > 1"
            class="flex items-center justify-between text-sm text-slate-500"
        >
            <span
                >Page {{ pagination.current_page }} of
                {{ pagination.last_page }}</span
            >
            <div class="flex gap-2">
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === 1"
                    @click="loadComplaints(pagination.current_page - 1)"
                >
                    Previous
                </button>
                <button
                    class="btn-outline"
                    :disabled="pagination.current_page === pagination.last_page"
                    @click="loadComplaints(pagination.current_page + 1)"
                >
                    Next
                </button>
            </div>
        </div>

        <div
            v-if="selectedComplaint"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            @click.self="closeComplaint"
        >
            <div
                class="modal-card max-h-[92vh] w-full max-w-4xl overflow-y-auto"
            >
                <div class="flex items-start justify-between border-b p-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900">
                            {{ selectedComplaint.subject }}
                        </h3>
                        <p class="text-sm text-slate-500">
                            Opened
                            {{ formatDate(selectedComplaint.created_at) }}
                        </p>
                    </div>
                    <button class="btn-outline" @click="closeComplaint">
                        Close
                    </button>
                </div>

                <div
                    v-if="detailLoading"
                    class="p-10 text-center text-slate-500"
                >
                    Loading case details...
                </div>
                <div v-else class="grid gap-6 p-5 lg:grid-cols-[1.15fr_0.85fr]">
                    <div class="space-y-5">
                        <section class="case-section">
                            <h4 class="section-title">Complaint details</h4>
                            <p
                                class="mt-2 text-sm whitespace-pre-wrap text-slate-700"
                            >
                                {{ selectedComplaint.description }}
                            </p>
                        </section>

                        <section class="case-section">
                            <h4 class="section-title">Involved parties</h4>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div class="party-card">
                                    <p class="field-label">Complainant</p>
                                    <p class="font-semibold">
                                        {{
                                            selectedComplaint.complainant
                                                ?.full_name
                                        }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{
                                            selectedComplaint.complainant?.email
                                        }}
                                    </p>
                                </div>
                                <div class="party-card">
                                    <p class="field-label">Respondent</p>
                                    <p class="font-semibold">
                                        {{
                                            selectedComplaint.respondent
                                                ?.full_name ||
                                            'NEXMART platform'
                                        }}
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        {{
                                            selectedComplaint.respondent
                                                ?.email ||
                                            'No individual respondent'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section class="case-section">
                            <h4 class="section-title">Supporting evidence</h4>
                            <p
                                v-if="!selectedComplaint.evidence?.length"
                                class="mt-2 text-sm text-slate-500"
                            >
                                No evidence was attached.
                            </p>
                            <div v-else class="mt-3 space-y-2">
                                <a
                                    v-for="(
                                        item, index
                                    ) in selectedComplaint.evidence"
                                    :key="`${item}-${index}`"
                                    :href="safeEvidenceUrl(item)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block rounded-lg border border-slate-200 p-3 text-sm text-teal-700 hover:bg-teal-50"
                                    >Evidence {{ index + 1 }}</a
                                >
                            </div>
                        </section>

                        <section class="case-section">
                            <h4 class="section-title">Case history</h4>
                            <p
                                v-if="!selectedComplaint.updates?.length"
                                class="mt-2 text-sm text-slate-500"
                            >
                                No investigation updates yet.
                            </p>
                            <div v-else class="mt-3 space-y-2">
                                <article
                                    v-for="update in selectedComplaint.updates"
                                    :key="update.id"
                                    class="rounded-lg border border-slate-200 p-3 text-sm"
                                >
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-2"
                                    >
                                        <p class="font-semibold text-slate-800">
                                            {{ label(update.old_status) }} →
                                            {{ label(update.new_status) }}
                                        </p>
                                        <span
                                            v-if="update.is_internal"
                                            class="badge badge-slate"
                                            >Internal</span
                                        >
                                    </div>
                                    <p
                                        class="mt-1 whitespace-pre-wrap text-slate-600"
                                    >
                                        {{ update.notes }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ update.admin || 'Administrator' }} ·
                                        {{ formatDate(update.created_at) }}
                                    </p>
                                </article>
                            </div>
                        </section>
                    </div>

                    <form
                        class="case-section h-fit space-y-4"
                        @submit.prevent="saveUpdate"
                    >
                        <h4 class="section-title">Update case</h4>
                        <div>
                            <label class="field-label" for="complaint-status"
                                >Status</label
                            ><select
                                id="complaint-status"
                                v-model="form.status"
                                class="field-input"
                            >
                                <option
                                    v-for="status in availableStatuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ label(status) }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="complaint-priority"
                                >Priority</label
                            ><select
                                id="complaint-priority"
                                v-model="form.priority"
                                class="field-input"
                            >
                                <option
                                    v-for="priority in priorities"
                                    :key="priority"
                                    :value="priority"
                                >
                                    {{ label(priority) }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="complaint-admin"
                                >Assigned administrator</label
                            ><select
                                id="complaint-admin"
                                v-model="form.assigned_admin_id"
                                class="field-input"
                            >
                                <option value="">Unassigned</option>
                                <option
                                    v-for="admin in admins"
                                    :key="admin.id"
                                    :value="admin.id"
                                >
                                    {{ admin.full_name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="complaint-notes"
                                >Investigation notes</label
                            ><textarea
                                id="complaint-notes"
                                v-model="form.notes"
                                class="field-input"
                                rows="5"
                                required
                                minlength="5"
                                placeholder="Record findings, communication, or next steps..."
                            ></textarea>
                        </div>
                        <div v-if="form.status === 'resolved'">
                            <label
                                class="field-label"
                                for="complaint-resolution"
                                >Resolution</label
                            ><textarea
                                id="complaint-resolution"
                                v-model="form.resolution"
                                class="field-input"
                                rows="4"
                                required
                                minlength="5"
                                placeholder="Describe the final resolution..."
                            ></textarea>
                        </div>
                        <label
                            class="flex items-start gap-2 text-sm text-slate-600"
                            ><input
                                v-model="form.is_internal"
                                type="checkbox"
                                class="mt-1"
                            /><span
                                >Internal note only—do not email the involved
                                users.</span
                            ></label
                        >
                        <button
                            class="btn-primary w-full"
                            type="submit"
                            :disabled="saving"
                        >
                            {{
                                saving ? 'Saving update...' : 'Save case update'
                            }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useAdmin } from '../composables/useAdmin';

const { adminFetch } = useAdmin();
const complaints = ref([]);
const selectedComplaint = ref(null);
const admins = ref([]);
const loading = ref(false);
const detailLoading = ref(false);
const saving = ref(false);
const search = ref('');
const statusFilter = ref('');
const priorityFilter = ref('');
const message = ref('');
const messageType = ref('success');
const summary = ref({ open: 0, pending: 0, under_review: 0, resolved: 0 });
const pagination = ref({ current_page: 1, last_page: 1 });
const priorities = ['low', 'normal', 'high', 'urgent'];
const transitions = {
    pending: ['pending', 'under_review', 'dismissed'],
    under_review: [
        'under_review',
        'awaiting_response',
        'resolved',
        'dismissed',
    ],
    awaiting_response: [
        'awaiting_response',
        'under_review',
        'resolved',
        'dismissed',
    ],
    resolved: ['resolved', 'under_review'],
    dismissed: ['dismissed', 'under_review'],
};
const form = reactive({
    status: 'pending',
    priority: 'normal',
    assigned_admin_id: '',
    notes: '',
    resolution: '',
    is_internal: false,
});
let searchTimer;

const summaryCards = computed(() => [
    { label: 'Open cases', value: summary.value.open },
    { label: 'Pending', value: summary.value.pending },
    { label: 'In review', value: summary.value.under_review },
    { label: 'Resolved', value: summary.value.resolved },
]);
const availableStatuses = computed(
    () => transitions[selectedComplaint.value?.status] || [],
);

function label(value) {
    if (!value) {
        return 'None';
    }

    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(status) {
    if (status === 'resolved') {
        return 'badge-green';
    }

    if (status === 'dismissed') {
        return 'badge-slate';
    }

    if (status === 'pending') {
        return 'badge-amber';
    }

    return 'badge-blue';
}

function priorityClass(priority) {
    if (priority === 'urgent') {
        return 'badge-red';
    }

    if (priority === 'high') {
        return 'badge-amber';
    }

    return 'badge-slate';
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : 'N/A';
}

function safeEvidenceUrl(value) {
    return /^https?:\/\//i.test(value) ? value : '#';
}

function showMessage(text, type = 'success') {
    message.value = text;
    messageType.value = type;
}

function scheduleLoad() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadComplaints(1), 350);
}

async function loadComplaints(page = 1) {
    loading.value = true;

    try {
        const params = new URLSearchParams({ page: String(page) });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (statusFilter.value) {
            params.set('status', statusFilter.value);
        }

        if (priorityFilter.value) {
            params.set('priority', priorityFilter.value);
        }

        const response = await adminFetch(`/api/admin/complaints?${params}`);
        const payload = await response.json();
        complaints.value = payload.complaints.data;
        summary.value = payload.summary;
        pagination.value = {
            current_page: payload.complaints.current_page,
            last_page: payload.complaints.last_page,
        };
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        loading.value = false;
    }
}

async function openComplaint(complaint) {
    selectedComplaint.value = complaint;
    detailLoading.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/complaints/${complaint.id}`,
        );
        const payload = await response.json();
        selectedComplaint.value = payload.complaint;
        admins.value = payload.admins;
        Object.assign(form, {
            status: payload.complaint.status,
            priority: payload.complaint.priority,
            assigned_admin_id: payload.complaint.assigned_admin?.id || '',
            notes: '',
            resolution: payload.complaint.resolution || '',
            is_internal: false,
        });
    } catch (error) {
        selectedComplaint.value = null;
        showMessage(error.message, 'error');
    } finally {
        detailLoading.value = false;
    }
}

function closeComplaint() {
    selectedComplaint.value = null;
}

async function saveUpdate() {
    saving.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/complaints/${selectedComplaint.value.id}`,
            {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...form,
                    assigned_admin_id: form.assigned_admin_id || null,
                    resolution: form.resolution || null,
                }),
            },
        );
        const payload = await response.json();
        const complaintId = selectedComplaint.value.id;
        closeComplaint();
        showMessage(payload.message);
        await loadComplaints(pagination.value.current_page);
        const refreshed = complaints.value.find(
            (item) => item.id === complaintId,
        );

        if (refreshed) {
            await openComplaint(refreshed);
        }
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        saving.value = false;
    }
}

onMounted(() => loadComplaints());
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
    vertical-align: top;
}
.badge {
    display: inline-flex;
    border-radius: 9999px;
    padding: 0.2rem 0.55rem;
    font-size: 0.7rem;
    font-weight: 700;
}
.badge-green {
    background: #dcfce7;
    color: #15803d;
}
.badge-amber {
    background: #fef3c7;
    color: #a16207;
}
.badge-red {
    background: #fee2e2;
    color: #b91c1c;
}
.badge-blue {
    background: #dbeafe;
    color: #1d4ed8;
}
.badge-slate {
    background: #f1f5f9;
    color: #475569;
}
.modal-card {
    border-radius: 0.85rem;
    background: white;
    box-shadow: 0 24px 64px rgb(15 23 42 / 25%);
}
.case-section {
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    background: white;
    padding: 1rem;
}
.section-title {
    font-weight: 700;
    color: #0f172a;
}
.party-card {
    border-radius: 0.5rem;
    background: #f8fafc;
    padding: 0.75rem;
    color: #334155;
}
.btn-outline,
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
.btn-primary {
    background: #0d9488;
    color: white;
}
button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
</style>

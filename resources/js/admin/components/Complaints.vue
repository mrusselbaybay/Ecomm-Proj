<template>
    <div class="space-y-5">
        <Transition name="admin-toast">
            <div v-if="toastMessage" class="admin-success-toast" role="status" aria-live="polite">
                <span class="toast-check" aria-hidden="true">✓</span>{{ toastMessage }}
            </div>
        </Transition>
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
                        <th>Reporter</th>
                        <th>Against</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <SkeletonRows v-if="loading && !hasLoadedOnce" :columns="6" :rows="5" />
                    <tr v-else-if="complaints.length === 0">
                        <td colspan="6" class="py-8 text-center text-slate-500">
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
                            <p class="font-medium text-slate-700" :title="complaint.complainant?.current_name || undefined">
                                {{ complaint.complainant?.full_name || 'Deleted user' }}
                            </p>
                        </td>
                        <td>
                            <template v-if="complaint.target">
                                {{ { product: 'Product', store: 'Shop', conversation: 'Conversation' }[complaint.target.type] || 'Reported entity' }}:
                                {{ complaint.target.name }}
                            </template>
                            <template v-else>
                                {{ complaint.respondent?.full_name || 'Platform' }}
                            </template>
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
                class="modal-card admin-modal-scroll max-h-[92vh] w-full max-w-4xl overflow-y-auto"
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
                    <button class="btn-outline" :disabled="saving || complianceBusy" @click="closeComplaint()">
                        Close
                    </button>
                </div>

                <div
                    v-if="detailLoading"
                    class="grid gap-6 p-5 lg:grid-cols-[1.15fr_0.85fr]"
                    aria-hidden="true"
                >
                    <div :class="wizardStep === 4 ? 'contents' : 'space-y-5'">
                        <section v-for="n in 3" :key="n" class="case-section">
                            <div class="skeleton skeleton-text" style="width: 35%; height: 1rem"></div>
                            <div class="skeleton skeleton-text" style="width: 100%; margin-top: 0.75rem"></div>
                            <div class="skeleton skeleton-text" style="width: 90%"></div>
                            <div class="skeleton skeleton-text" style="width: 70%"></div>
                        </section>
                    </div>
                    <div class="case-section space-y-4">
                        <div class="skeleton skeleton-text" style="width: 40%; height: 1rem"></div>
                        <div v-for="n in 4" :key="n">
                            <div class="skeleton skeleton-text" style="width: 30%; height: 0.6rem"></div>
                            <div class="skeleton" style="width: 100%; height: 2.2rem; margin-top: 0.4rem"></div>
                        </div>
                    </div>
                </div>
                <div v-else class="grid gap-6 p-5" :class="wizardStep === 4 ? 'lg:grid-cols-[1.15fr_0.85fr]' : 'grid-cols-1'">
                    <nav class="col-span-full flex flex-wrap gap-2" aria-label="Case review steps">
                        <button v-for="(step, index) in wizardSteps" :key="step" type="button" class="rounded-full border px-3 py-2 text-sm font-medium" :class="wizardStep === index + 1 ? 'border-teal-700 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-600'" :aria-current="wizardStep === index + 1 ? 'step' : undefined" @click="wizardStep = index + 1">{{ index + 1 }}. {{ step }}</button>
                    </nav>
                    <div class="space-y-5">
                        <section v-if="wizardStep === 1" class="case-section summary-card">
                            <h4 class="section-title">Complaint details</h4>
                            <div class="summary-grid">
                                <div v-if="selectedComplaint.target">
                                    <p class="field-label">Reported {{ selectedComplaint.target.type }}</p>
                                    <div class="summary-target">{{ selectedComplaint.target.name }}</div>
                                </div>
                                <div>
                                    <p class="field-label">Violation reason</p>
                                    <p class="text-sm font-medium text-slate-800">{{ selectedComplaint.report_reason_label || label(selectedComplaint.type) }}</p>
                                </div>
                            </div>
                            <p class="field-label mt-4">Reporter&rsquo;s comment</p>
                            <p
                                class="mt-2 text-sm whitespace-pre-wrap text-slate-700"
                            >
                                {{ reportComment(selectedComplaint.description) || selectedComplaint.description }}
                            </p>
                            <p class="mt-3 text-sm text-slate-500">{{ label(selectedComplaint.status) }} · {{ label(selectedComplaint.priority) }}</p>
                            <p v-if="selectedComplaint.urgent_review_due_at" class="mt-1 text-sm font-medium text-red-700">Urgent review target: {{ formatDate(selectedComplaint.urgent_review_due_at) }}</p>
                        </section>

                        <section v-if="wizardStep === 3" class="case-section">
                            <h4 class="section-title">Case entities</h4>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div class="party-card">
                                    <p class="field-label">Reporter</p>
                                    <p class="font-semibold" :title="selectedComplaint.complainant?.current_name || undefined">{{ selectedComplaint.complainant?.full_name || 'Deleted user' }}</p>
                                    <p v-if="selectedComplaint.reporter_dismissed_count >= 5" class="mt-2 text-xs text-amber-700">Repeated dismissed reports; this report&rsquo;s priority was reduced.</p>
                                </div>
                                <div class="party-card">
                                    <p class="field-label">{{ selectedComplaint.target?.type === 'conversation' ? 'Reported buyer' : selectedComplaint.target ? 'Seller' : 'Respondent' }}</p>
                                    <p class="font-semibold">
                                        {{ selectedComplaint.target?.seller_name || selectedComplaint.respondent?.full_name || 'BuyTheWay platform' }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section v-if="wizardStep === 3 && selectedComplaint.target" class="case-section">
                            <h4 class="section-title">Reported {{ selectedComplaint.target.type }}</h4>
                            <p class="mt-2 font-semibold text-slate-800" :title="selectedComplaint.target.current_name || undefined">{{ selectedComplaint.target.name }}</p>
                            <p v-if="selectedComplaint.target.type === 'product'" class="mt-1 text-sm text-slate-500">Listing {{ selectedComplaint.target.status }}</p>
                            <p v-else class="mt-1 text-sm text-slate-500">Seller account {{ selectedComplaint.target.account_status }}</p>
                            <p v-if="selectedComplaint.target.report_hold" class="mt-2 text-sm font-semibold text-amber-700">
                                Temporarily hidden from discovery while this urgent report is reviewed.
                            </p>
                            <p v-if="selectedComplaint.report_reason_label" class="mt-2 text-sm text-slate-600">
                                Reason: {{ selectedComplaint.report_reason_label }}
                            </p>
                        </section>

                        <section v-if="wizardStep === 3 && selectedComplaint.report_history?.length" class="case-section">
                            <h4 class="section-title">Report history</h4>
                            <ul class="mt-3 space-y-2">
                                <li v-for="report in selectedComplaint.report_history" :key="report.id" class="rounded-lg border border-slate-200 p-3 text-sm">
                                    <div class="flex justify-between gap-3">
                                        <strong>{{ report.reason }}</strong>
                                        <span class="badge" :class="priorityClass(report.priority)">{{ label(report.priority) }}</span>
                                    </div>
                                    <p class="mt-1 text-slate-500">{{ label(report.status) }} · {{ report.reporter || 'Deleted user' }} · {{ formatDate(report.created_at) }}</p>
                                </li>
                            </ul>
                        </section>

                        <section v-if="wizardStep === 2" class="case-section">
                            <h4 class="section-title">Supporting evidence</h4>
                            <p
                                v-if="!selectedComplaint.evidence?.length"
                                class="mt-2 text-sm text-slate-500"
                            >
                                No evidence was attached.
                            </p>
                            <div v-else class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                <button v-for="(item, index) in selectedComplaint.evidence" :key="`${item.url}-${index}`" type="button" class="evidence-thumb" @click="openEvidence(item)">
                                    <img v-if="item.kind === 'image'" :src="item.url" :alt="`Evidence ${index + 1}`" loading="lazy">
                                    <span v-else class="evidence-video"><span aria-hidden="true">▶</span><small>Video {{ index + 1 }}</small></span>
                                    <span class="evidence-label">{{ item.name || `Evidence ${index + 1}` }}</span>
                                </button>
                            </div>
                        </section>

                        <section v-if="wizardStep === 4" class="case-section col-span-full order-3">
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
                        v-if="wizardStep === 4 && !selectedTerminal"
                        class="case-section h-fit space-y-4 order-1"
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
                        <div v-if="form.status === 'dismissed'">
                            <label class="field-label" for="complaint-dismissal-reason">Dismissal reason</label>
                            <select id="complaint-dismissal-reason" v-model="form.dismissal_reason" class="field-input" required>
                                <option value="">Choose a reason</option>
                                <option value="no_violation">No policy violation found</option>
                                <option value="duplicate">Duplicate report</option>
                                <option value="insufficient_evidence">Insufficient evidence</option>
                                <option value="out_of_scope">Outside platform scope</option>
                                <option value="other">Other</option>
                            </select>
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

                    <p v-if="wizardStep === 4 && selectedTerminal" class="case-section h-fit text-sm text-slate-600 order-1">This case is {{ label(selectedComplaint.status).toLowerCase() }} and locked. Reopening requires a separate logged action.</p>

                    <section v-if="wizardStep === 4 && !selectedTerminal && ['product', 'store'].includes(selectedComplaint.target?.type)" class="case-section mt-4 space-y-4 order-2">
                        <h4 class="section-title">Compliance action</h4>
                        <p class="text-sm text-slate-500">Apply a warning, listing action, or seller suspension. Every action is recorded in compliance history.</p>
                        <label class="field-label" for="complaint-compliance-action">Action</label>
                        <select id="complaint-compliance-action" v-model="complianceAction" class="field-input">
                            <option value="">Choose an action</option>
                            <template v-if="selectedComplaint.target.type === 'product'">
                                <option value="warn">Warn seller</option>
                                <option value="hide">Temporarily hide product</option>
                                <option value="unhide">Restore product visibility</option>
                                <option value="remove">Remove product</option>
                                <option value="suspend">Suspend seller</option>
                            </template>
                            <template v-else>
                                <option value="warn">Warn seller</option>
                                <option value="suspend">Suspend seller</option>
                                <option value="reinstate">Reinstate seller</option>
                            </template>
                        </select>
                        <label class="field-label" for="complaint-compliance-reason">Action reason</label>
                        <textarea id="complaint-compliance-reason" v-model="complianceReason" class="field-input" rows="3" maxlength="1000" placeholder="Record why this action is needed."></textarea>
                        <button class="btn-danger w-full" type="button" :disabled="complianceBusy || !complianceAction" @click="applyComplianceAction">
                            {{ complianceBusy ? 'Applying…' : 'Apply compliance action' }}
                        </button>
                    </section>
                    <div class="col-span-full flex justify-between gap-3 border-t border-slate-200 pt-4">
                        <button type="button" class="btn-outline" :disabled="wizardStep === 1 || saving || complianceBusy" @click="wizardStep = Math.max(1, wizardStep - 1)">Back</button>
                        <span class="self-center text-sm text-slate-500">Step {{ wizardStep }} of {{ wizardSteps.length }}</span>
                        <button v-if="wizardStep < wizardSteps.length" type="button" class="btn-primary" @click="wizardStep = Math.min(wizardSteps.length, wizardStep + 1)">Next</button>
                        <span v-else class="w-[88px]"></span>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="activeEvidence" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/85 p-4" role="dialog" aria-modal="true" aria-label="Evidence preview" @click.self="activeEvidence = null" @keydown.esc="activeEvidence = null">
            <button type="button" class="evidence-close" aria-label="Close preview" @click="activeEvidence = null">×</button>
            <img v-if="activeEvidence.kind === 'image'" :src="activeEvidence.url" alt="Report evidence" class="evidence-expanded-image">
            <video v-else :src="activeEvidence.url" controls autoplay class="evidence-expanded-video">Video playback is not supported by this browser.</video>
        </div>
        <div v-if="confirmation" class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-description" @click.self="finishConfirmation(false)" @keydown.esc="finishConfirmation(false)">
            <section class="confirm-card">
                <div class="confirm-icon" :class="confirmation.tone === 'danger' ? 'confirm-icon-danger' : 'confirm-icon-safe'">
                    <svg v-if="confirmation.tone === 'danger'" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 19a1.4 1.4 0 0 0 1.2 2.1h16a1.4 1.4 0 0 0 1.2-2.1L12 3Z"/><path d="M12 9v5m0 3h.01"/></svg>
                    <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9"/><path d="m8 12 2.5 2.5L21 4"/></svg>
                </div>
                <h3 id="confirm-title" class="confirm-title">{{ confirmation.title }}</h3>
                <p id="confirm-description" class="confirm-description">{{ confirmation.message }}</p>
                <div class="confirm-actions">
                    <button type="button" class="confirm-cancel" @click="finishConfirmation(false)">{{ confirmation.cancelLabel || 'Cancel' }}</button>
                    <button type="button" class="confirm-submit" :class="confirmation.tone === 'danger' ? 'confirm-submit-danger' : ''" @click="finishConfirmation(true)">{{ confirmation.confirmLabel || 'Confirm' }}</button>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, onActivated, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useAdmin } from '../composables/useAdmin';
import SkeletonRows from './SkeletonRows.vue';

const { adminFetch } = useAdmin();
const complaints = ref([]);
const selectedComplaint = ref(null);
const activeEvidence = ref(null);
const confirmation = ref(null);
const toastMessage = ref('');
const wizardStep = ref(1);
const wizardSteps = ['Report summary', 'Evidence', 'Reported entities', 'Decision'];
const loading = ref(false);
// This component stays kept-alive across tab switches, so onActivated below
// reruns loadComplaints() on every revisit, not just the first. Without
// this flag, the skeleton would wipe out rows that are already loaded and
// on screen just because a background refresh set "loading" true again —
// gating it on "loading && !hasLoadedOnce" instead lets that refresh
// update the table in place once it resolves.
const hasLoadedOnce = ref(false);
const detailLoading = ref(false);
const saving = ref(false);
const complianceBusy = ref(false);
const complianceAction = ref('');
const complianceReason = ref('');
const search = ref('');
const statusFilter = ref('');
const priorityFilter = ref('');
const message = ref('');
const messageType = ref('success');
const summary = ref({ open: 0, pending: 0, under_review: 0, resolved: 0 });
const pagination = ref({ current_page: 1, last_page: 1 });
const priorities = ['low', 'normal', 'high', 'urgent'];
const transitions = {
    pending: ['pending', 'under_review', 'resolved', 'dismissed'],
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
    resolved: [],
    dismissed: [],
};
const form = reactive({
    status: 'pending',
    priority: 'normal',
    notes: '',
    resolution: '',
    dismissal_reason: '',
    is_internal: false,
});
let searchTimer;
let toastTimer;
let confirmationResolver = null;

const summaryCards = computed(() => [
    { label: 'Open cases', value: summary.value.open },
    { label: 'Pending', value: summary.value.pending },
    { label: 'In review', value: summary.value.under_review },
    { label: 'Resolved', value: summary.value.resolved },
]);
const availableStatuses = computed(
    () => transitions[selectedComplaint.value?.status] || [],
);
const selectedTerminal = computed(() => ['resolved', 'dismissed'].includes(selectedComplaint.value?.status));
const decisionStarted = computed(() => Boolean(
    form.notes.trim()
    || form.resolution.trim()
    || form.dismissal_reason
    || complianceAction.value
    || complianceReason.value.trim()
    || (selectedComplaint.value && (form.status !== selectedComplaint.value.status || form.priority !== selectedComplaint.value.priority)),
));

function complaintDraftKey(id) {
    return `admin:complaint-draft:${id}`;
}

watch(() => selectedComplaint.value?.id && JSON.stringify({
    step: wizardStep.value,
    form,
    complianceAction: complianceAction.value,
    complianceReason: complianceReason.value,
}), () => {
    if (!selectedComplaint.value || !decisionStarted.value) return;
    try {
        localStorage.setItem(complaintDraftKey(selectedComplaint.value.id), JSON.stringify({
            step: wizardStep.value,
            form: { ...form },
            complianceAction: complianceAction.value,
            complianceReason: complianceReason.value,
        }));
    } catch {
        // The review still works when browser storage is unavailable.
    }
}, { flush: 'post' });

function guardWizardRefresh(event) {
    if (!selectedComplaint.value || !decisionStarted.value) return;
    event.preventDefault();
    event.returnValue = '';
}

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

function reportComment(description = '') {
    return description.split(/\n\nReporter details:\s*/i).at(-1)?.trim() || '';
}

function openEvidence(item) {
    if (item.url && item.url !== '#') activeEvidence.value = item;
}

function showMessage(text, type = 'success') {
    if (type === 'success') {
        toastMessage.value = text;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastMessage.value = ''; }, 3500);
        message.value = '';
        return;
    }
    message.value = text;
    messageType.value = type;
}

function askConfirmation(options) {
    confirmation.value = options;
    return new Promise((resolve) => { confirmationResolver = resolve; });
}

function finishConfirmation(confirmed) {
    confirmation.value = null;
    confirmationResolver?.(confirmed);
    confirmationResolver = null;
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
        hasLoadedOnce.value = true;
    }
}

async function openComplaint(complaint) {
    selectedComplaint.value = complaint;
    wizardStep.value = 1;
    detailLoading.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/complaints/${complaint.id}`,
        );
        const payload = await response.json();
        const evidence = await Promise.all((payload.complaint.evidence || []).map(async (item) => {
            const path = typeof item === 'string' ? item : item.path || item.url || '';
            const name = path.split('/').at(-1) || '';
            const kind = /\.(png|jpe?g|webp|gif|bmp|avif)$/i.test(name) ? 'image' : 'video';
            if (/^https?:\/\//i.test(path)) return { url: path, kind, name };

            try {
                const params = new URLSearchParams({ path });
                const linkResponse = await adminFetch(`/api/storage/report-evidence/signed-url?${params}`);
                const linkPayload = await linkResponse.json();
                return { url: linkPayload.signedUrl || '#', kind, name };
            } catch {
                return { url: '#', kind, name };
            }
        }));
        selectedComplaint.value = { ...payload.complaint, evidence };
        complianceAction.value = '';
        complianceReason.value = payload.complaint.report_reason_label
            ? `Reported for ${payload.complaint.report_reason_label}.`
            : '';
        Object.assign(form, {
            status: payload.complaint.status,
            priority: payload.complaint.priority,
            notes: '',
            resolution: payload.complaint.resolution || '',
            dismissal_reason: payload.complaint.dismissal_reason || '',
            is_internal: false,
        });
        try {
            const savedDraft = JSON.parse(localStorage.getItem(complaintDraftKey(complaint.id)) || 'null');
            if (savedDraft?.form && !['resolved', 'dismissed'].includes(payload.complaint.status)) {
                Object.assign(form, savedDraft.form);
                wizardStep.value = Math.min(4, Math.max(1, Number(savedDraft.step) || 1));
                complianceAction.value = savedDraft.complianceAction || '';
                complianceReason.value = savedDraft.complianceReason || '';
            }
        } catch {
            // Ignore malformed or unavailable browser drafts.
        }
    } catch (error) {
        selectedComplaint.value = null;
        showMessage(error.message, 'error');
    } finally {
        detailLoading.value = false;
    }
}

async function applyComplianceAction() {
    const target = selectedComplaint.value?.target;
    if (!target || !complianceAction.value || complianceBusy.value) return;

    const action = complianceAction.value;
    const reason = complianceReason.value.trim() || `Report review: ${selectedComplaint.value.report_reason_label || selectedComplaint.value.subject}`;
    const actionLabel = label(action).toLowerCase();
    const confirmed = await askConfirmation({
        title: 'Confirm compliance action',
        message: `Apply ${actionLabel} to ${target.type === 'store' ? 'shop' : 'product'} “${target.name}”? This action will be recorded in compliance history.`,
        confirmLabel: 'Apply action',
        tone: ['remove', 'suspend'].includes(action) ? 'danger' : 'safe',
    });
    if (!confirmed) return;

    complianceBusy.value = true;
    try {
        const path = target.type === 'product'
            ? `/api/admin/compliance/products/${encodeURIComponent(target.id)}/actions`
            : `/api/admin/compliance/stores/${encodeURIComponent(target.id)}/actions`;
        const response = await adminFetch(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, reason }),
        });
        const payload = await response.json();
        showMessage(payload.message);
        closeComplaint(true);
        await loadComplaints(pagination.value.current_page);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        complianceBusy.value = false;
    }
}

async function closeComplaint(force = false) {
    if (!force && (saving.value || complianceBusy.value)) return;
    if (!force && decisionStarted.value) {
        const confirmed = await askConfirmation({
            title: 'Keep this draft?',
            message: 'Your changes are saved as a draft in this browser. Close the review and return to it later?',
            confirmLabel: 'Close and keep draft',
            tone: 'safe',
        });
        if (!confirmed) return;
    }
    selectedComplaint.value = null;
}

async function saveUpdate() {
    if (['resolved', 'dismissed'].includes(form.status)) {
        const confirmed = await askConfirmation({
            title: form.status === 'resolved' ? 'Resolve this case?' : 'Dismiss this case?',
            message: form.status === 'resolved'
                ? 'This decision closes and locks the case. Make sure the resolution and investigation notes are complete.'
                : 'This decision closes and locks the case. The selected dismissal reason will be recorded.',
            confirmLabel: form.status === 'resolved' ? 'Resolve case' : 'Dismiss case',
            tone: form.status === 'resolved' ? 'safe' : 'danger',
        });
        if (!confirmed) return;
    }
    saving.value = true;

    try {
        const response = await adminFetch(
            `/api/admin/complaints/${selectedComplaint.value.id}`,
            {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...form,
                    resolution: form.resolution || null,
                    dismissal_reason: form.dismissal_reason || null,
                }),
            },
        );
        const payload = await response.json();
        try {
            localStorage.removeItem(complaintDraftKey(selectedComplaint.value.id));
        } catch {
            // The server update is authoritative even if local cleanup fails.
        }
        closeComplaint(true);
        showMessage(payload.message);
        await loadComplaints(pagination.value.current_page);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        saving.value = false;
    }
}

// This component is kept alive by AdminLayout's <KeepAlive>, so
// onActivated (not onMounted) fires both on first visit and every time the
// admin returns to this tab — reloading the current page/filters instead of
// showing a case list that may have since changed (new complaint, another
// admin's update, etc.).
onActivated(() => loadComplaints(pagination.value.current_page));
window.addEventListener('beforeunload', guardWizardRefresh);
onBeforeUnmount(() => {
    clearTimeout(searchTimer);
    window.removeEventListener('beforeunload', guardWizardRefresh);
});
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
.admin-success-toast {
    position: fixed;
    z-index: 90;
    top: 1.25rem;
    right: 1.25rem;
    display: flex;
    max-width: min(26rem, calc(100vw - 2rem));
    align-items: center;
    gap: 0.7rem;
    border: 1px solid #a7f3d0;
    border-radius: 0.85rem;
    background: #fff;
    padding: 0.85rem 1rem;
    color: #134e4a;
    box-shadow: 0 16px 40px rgb(15 23 42 / 18%);
    font-size: 0.88rem;
    font-weight: 600;
}
.toast-check {
    display: grid;
    width: 1.75rem;
    height: 1.75rem;
    flex: none;
    place-items: center;
    border-radius: 50%;
    background: #ccfbf1;
    color: #0f766e;
    font-size: 1.1rem;
}
.admin-toast-enter-active,
.admin-toast-leave-active { transition: opacity 180ms ease, transform 180ms ease; }
.admin-toast-enter-from,
.admin-toast-leave-to { opacity: 0; transform: translateY(-0.5rem); }
.confirm-card {
    width: min(100%, 26rem);
    border: 1px solid rgb(226 232 240 / 90%);
    border-radius: 1.15rem;
    background: #fff;
    padding: 1.6rem;
    box-shadow: 0 28px 80px rgb(2 6 23 / 30%);
    animation: confirm-enter 160ms ease-out;
}
.confirm-icon {
    display: grid;
    width: 3.25rem;
    height: 3.25rem;
    place-items: center;
    border-radius: 1rem;
}
.confirm-icon svg { width: 1.5rem; height: 1.5rem; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.confirm-icon-safe { background: #ccfbf1; color: #0f766e; }
.confirm-icon-danger { background: #fee2e2; color: #b91c1c; }
.confirm-title { margin-top: 1.1rem; color: #0f172a; font-size: 1.2rem; font-weight: 750; letter-spacing: -0.02em; }
.confirm-description { margin-top: 0.5rem; color: #64748b; font-size: 0.9rem; line-height: 1.55; }
.confirm-actions { display: flex; justify-content: flex-end; gap: 0.65rem; margin-top: 1.5rem; }
.confirm-cancel,
.confirm-submit { min-height: 2.5rem; border-radius: 0.65rem; padding: 0.6rem 0.9rem; font-size: 0.82rem; font-weight: 700; transition: background 140ms ease, transform 140ms ease; }
.confirm-cancel { border: 1px solid #e2e8f0; color: #475569; }
.confirm-cancel:hover { background: #f8fafc; }
.confirm-submit { background: #0f766e; color: #fff; }
.confirm-submit:hover { background: #115e59; }
.confirm-submit-danger { background: #b91c1c; }
.confirm-submit-danger:hover { background: #991b1b; }
.confirm-submit:active,
.confirm-cancel:active { transform: scale(0.98); }
@keyframes confirm-enter { from { opacity: 0; transform: translateY(0.4rem) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
.admin-modal-scroll,
.admin-modal-scroll * {
    scrollbar-width: none;
}
.admin-modal-scroll::-webkit-scrollbar,
.admin-modal-scroll *::-webkit-scrollbar {
    display: none;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}
.summary-target {
    display: flex;
    min-height: 2.75rem;
    align-items: center;
    gap: 0.65rem;
    border-radius: 0.55rem;
    background: #f8fafc;
    padding: 0.45rem;
    font-size: 0.9rem;
    font-weight: 600;
}
.summary-target-image {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.35rem;
    object-fit: cover;
}
.summary-comment {
    max-height: 7rem;
    overflow: auto;
    border-radius: 0.5rem;
    background: #f8fafc;
    padding: 0.75rem;
    color: #475569;
    font-size: 0.82rem;
    line-height: 1.45;
    white-space: pre-wrap;
    scrollbar-width: none;
}
.summary-comment::-webkit-scrollbar { display: none; }
.evidence-thumb {
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 0.65rem;
    background: #fff;
    text-align: left;
}
.evidence-thumb img,
.evidence-video {
    display: flex;
    width: 100%;
    height: 8rem;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    object-fit: cover;
}
.evidence-video { flex-direction: column; gap: 0.4rem; color: #475569; }
.evidence-label {
    display: block;
    overflow: hidden;
    padding: 0.5rem;
    color: #475569;
    font-size: 0.72rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.evidence-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    color: #fff;
    font-size: 2rem;
}
.evidence-expanded-image,
.evidence-expanded-video {
    max-height: 90vh;
    max-width: 94vw;
    object-fit: contain;
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

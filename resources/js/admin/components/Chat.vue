<script setup>
import { computed, onActivated, onMounted, ref } from 'vue';
import { customerServiceApi } from '../../shared/customerServiceApi';
import { useAdmin } from '../composables/useAdmin';

const { adminUser } = useAdmin();
const tickets = ref([]);
const selectedTicket = ref(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const filter = ref('');
const replyBody = ref('');
const noteBody = ref('');
const resolutionSummary = ref('');

const isAssignedToMe = computed(
    () => selectedTicket.value?.assignedAdminId === adminUser.value?.id,
);

async function loadTickets() {
    isLoading.value = true;
    errorMessage.value = '';
    try {
        const query = filter.value
            ? `?status=${encodeURIComponent(filter.value)}`
            : '';
        const response = await customerServiceApi(
            `/api/admin/customer-service/tickets${query}`,
        );
        tickets.value = response.data || [];
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        isLoading.value = false;
    }
}

async function openTicket(ticket) {
    errorMessage.value = '';
    try {
        const response = await customerServiceApi(
            `/api/admin/customer-service/tickets/${ticket.id}`,
        );
        selectedTicket.value = response.data;
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function claimTicket() {
    await runAction('assignment', { method: 'PATCH' });
}

async function updateStatus(status) {
    await runAction('status', {
        method: 'PATCH',
        body: JSON.stringify({ status }),
    });
}

async function sendReply() {
    const body = replyBody.value.trim();
    if (!body) return;
    await runAction('reply', {
        method: 'POST',
        body: JSON.stringify({ body }),
    });
    replyBody.value = '';
}

async function addNote() {
    const body = noteBody.value.trim();
    if (!body) return;
    await runAction('internal-notes', {
        method: 'POST',
        body: JSON.stringify({ body }),
    });
    noteBody.value = '';
}

async function resolveTicket() {
    const summary = resolutionSummary.value.trim();
    if (!summary) return;
    await runAction('resolve', {
        method: 'POST',
        body: JSON.stringify({ resolution_summary: summary }),
    });
    resolutionSummary.value = '';
}

async function runAction(action, options) {
    if (!selectedTicket.value) return;

    isSaving.value = true;
    errorMessage.value = '';
    try {
        await customerServiceApi(
            `/api/admin/customer-service/tickets/${selectedTicket.value.id}/${action}`,
            options,
        );
        await openTicket(selectedTicket.value);
        await loadTickets();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        isSaving.value = false;
    }
}

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

onMounted(loadTickets);
onActivated(loadTickets);
</script>

<template>
    <div class="admin-cs">
        <header class="admin-cs-header">
            <div>
                <span>PLATFORM SUPPORT</span>
                <h2>Customer Service Management</h2>
                <p>
                    Users submit tickets here; this is not a public Admin inbox.
                </p>
            </div>
            <div class="admin-cs-filters">
                <select v-model="filter" @change="loadTickets">
                    <option value="">All available tickets</option>
                    <option value="submitted">Unassigned</option>
                    <option value="open">Open</option>
                    <option value="waiting_for_customer">
                        Waiting for customer
                    </option>
                    <option value="escalated">Escalated</option>
                    <option value="resolved">Resolved</option>
                </select>
                <button type="button" @click="loadTickets">Refresh</button>
            </div>
        </header>

        <p v-if="errorMessage" class="admin-cs-error">{{ errorMessage }}</p>
        <div v-if="isLoading" class="admin-cs-empty">
            Loading support queue…
        </div>

        <div v-else class="admin-cs-grid">
            <aside class="admin-cs-list">
                <button
                    v-for="ticket in tickets"
                    :key="ticket.id"
                    type="button"
                    :class="{ active: selectedTicket?.id === ticket.id }"
                    @click="openTicket(ticket)"
                >
                    <span class="ticket-line"
                        ><strong>{{ ticket.ticketNumber }}</strong
                        ><em>{{ ticket.status }}</em></span
                    >
                    <b>{{ ticket.subject }}</b>
                    <small
                        >{{ ticket.creator.role }} · {{ ticket.category }} ·
                        {{ formatDate(ticket.updatedAt) }}</small
                    >
                    <small v-if="!ticket.assignedAdminId" class="unassigned"
                        >Unassigned</small
                    >
                </button>
                <p v-if="!tickets.length" class="admin-cs-empty">
                    No tickets are available in this queue.
                </p>
            </aside>

            <section class="admin-cs-detail">
                <template v-if="selectedTicket">
                    <div class="ticket-detail-head">
                        <div>
                            <span>{{ selectedTicket.ticketNumber }}</span>
                            <h3>{{ selectedTicket.subject }}</h3>
                            <p>
                                {{ selectedTicket.creator.name }} ·
                                {{ selectedTicket.creator.role }}
                            </p>
                        </div>
                        <em>{{ selectedTicket.status }}</em>
                    </div>

                    <button
                        v-if="!selectedTicket.assignedAdminId"
                        class="primary"
                        type="button"
                        :disabled="isSaving"
                        @click="claimTicket"
                    >
                        Claim ticket
                    </button>

                    <div class="admin-cs-messages">
                        <article
                            v-for="message in selectedTicket.messages"
                            :key="message.id"
                            :class="
                                message.senderRole === 'admin'
                                    ? 'official'
                                    : 'customer'
                            "
                        >
                            <strong>{{ message.displayName }}</strong>
                            <p>{{ message.body }}</p>
                            <time>{{ formatDate(message.createdAt) }}</time>
                        </article>
                    </div>

                    <template
                        v-if="
                            isAssignedToMe &&
                            !['resolved', 'closed'].includes(
                                selectedTicket.status,
                            )
                        "
                    >
                        <div class="admin-cs-controls">
                            <button type="button" @click="updateStatus('open')">
                                Open
                            </button>
                            <button
                                type="button"
                                @click="updateStatus('waiting_for_customer')"
                            >
                                Request information
                            </button>
                            <button
                                type="button"
                                @click="updateStatus('escalated')"
                            >
                                Escalate
                            </button>
                        </div>
                        <form
                            class="admin-cs-compose"
                            @submit.prevent="sendReply"
                        >
                            <textarea
                                v-model="replyBody"
                                maxlength="4000"
                                required
                                placeholder="Customer-visible reply as Platform Customer Service"
                            ></textarea>
                            <button class="primary" :disabled="isSaving">
                                Send official reply
                            </button>
                        </form>
                        <form
                            class="admin-cs-compose note"
                            @submit.prevent="addNote"
                        >
                            <textarea
                                v-model="noteBody"
                                maxlength="4000"
                                required
                                placeholder="Private internal note — never visible to the customer"
                            ></textarea>
                            <button :disabled="isSaving">
                                Add internal note
                            </button>
                        </form>
                        <form
                            class="admin-cs-compose resolution"
                            @submit.prevent="resolveTicket"
                        >
                            <textarea
                                v-model="resolutionSummary"
                                maxlength="4000"
                                required
                                placeholder="Customer-facing resolution summary"
                            ></textarea>
                            <button :disabled="isSaving">Resolve ticket</button>
                        </form>
                    </template>

                    <div
                        v-if="selectedTicket.internalNotes?.length"
                        class="admin-cs-notes"
                    >
                        <h4>Internal notes</h4>
                        <article
                            v-for="note in selectedTicket.internalNotes"
                            :key="note.id"
                        >
                            <p>{{ note.body }}</p>
                            <small
                                >{{ note.adminName }} ·
                                {{ formatDate(note.createdAt) }}</small
                            >
                        </article>
                    </div>
                </template>
                <p v-else class="admin-cs-empty">
                    Select a ticket to review its public timeline and internal
                    record.
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.admin-cs {
    color: #172033;
}
.admin-cs-header,
.admin-cs-filters,
.ticket-line,
.ticket-detail-head,
.admin-cs-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.8rem;
}
.admin-cs-header {
    margin-bottom: 1rem;
}
.admin-cs-header span,
.ticket-detail-head span {
    color: #0d9488;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.1em;
}
.admin-cs-header h2,
.ticket-detail-head h3 {
    margin: 0.2rem 0;
}
.admin-cs-header p,
.ticket-detail-head p {
    margin: 0;
    color: #64748b;
}
button,
select,
textarea {
    font: inherit;
}
button,
select {
    padding: 0.6rem 0.8rem;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    background: #fff;
    cursor: pointer;
}
.primary {
    background: #0d9488;
    border-color: #0d9488;
    color: #fff;
    font-weight: 800;
}
.admin-cs-error {
    padding: 0.8rem;
    border-radius: 9px;
    background: #fef2f2;
    color: #b91c1c;
}
.admin-cs-grid {
    display: grid;
    grid-template-columns: minmax(250px, 32%) 1fr;
    min-height: 620px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #fff;
}
.admin-cs-list {
    max-height: 720px;
    overflow-y: auto;
    border-right: 1px solid #e2e8f0;
    background: #f8fafc;
}
.admin-cs-list > button {
    display: grid;
    gap: 0.4rem;
    width: 100%;
    padding: 1rem;
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    border-radius: 0;
    background: transparent;
    text-align: left;
}
.admin-cs-list > button:hover,
.admin-cs-list > button.active {
    background: #e9f8f6;
}
.admin-cs-list em,
.ticket-detail-head em {
    padding: 0.22rem 0.48rem;
    border-radius: 999px;
    background: #e2e8f0;
    font-size: 0.7rem;
    font-style: normal;
}
.admin-cs-list small {
    color: #64748b;
}
.admin-cs-list .unassigned {
    width: fit-content;
    padding: 0.15rem 0.35rem;
    border-radius: 4px;
    background: #fff3cd;
    color: #815b00;
    font-weight: 800;
}
.admin-cs-detail {
    min-width: 0;
    padding: 1rem;
}
.admin-cs-messages {
    max-height: 340px;
    min-height: 180px;
    overflow-y: auto;
    margin: 1rem 0;
    padding: 0.8rem;
    border-radius: 12px;
    background: #f8fafc;
}
.admin-cs-messages article {
    width: fit-content;
    max-width: 78%;
    margin: 0.6rem 0;
    padding: 0.7rem 0.85rem;
    border-radius: 12px;
    overflow-wrap: anywhere;
}
.admin-cs-messages article.official {
    margin-left: auto;
    background: #0d9488;
    color: #fff;
}
.admin-cs-messages article.customer {
    border: 1px solid #e2e8f0;
    background: #fff;
}
.admin-cs-messages p {
    margin: 0.3rem 0;
    white-space: pre-wrap;
}
.admin-cs-messages time {
    font-size: 0.68rem;
    opacity: 0.72;
}
.admin-cs-controls {
    justify-content: flex-start;
    flex-wrap: wrap;
}
.admin-cs-compose {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: end;
    gap: 0.6rem;
    margin-top: 0.7rem;
}
.admin-cs-compose textarea {
    min-height: 64px;
    padding: 0.7rem;
    border: 1px solid #cbd5e1;
    border-radius: 9px;
    resize: vertical;
}
.admin-cs-compose.note {
    padding-top: 0.7rem;
    border-top: 1px dashed #cbd5e1;
}
.admin-cs-compose.note button {
    background: #334155;
    color: #fff;
}
.admin-cs-compose.resolution button {
    background: #166534;
    color: #fff;
}
.admin-cs-notes {
    margin-top: 1rem;
    padding: 0.8rem;
    border-radius: 10px;
    background: #fff8e7;
}
.admin-cs-notes article {
    padding: 0.5rem 0;
    border-top: 1px solid #f1dfb5;
}
.admin-cs-notes p {
    margin: 0 0 0.2rem;
}
.admin-cs-empty {
    padding: 3rem 1rem;
    text-align: center;
    color: #64748b;
}
@media (max-width: 850px) {
    .admin-cs-header,
    .admin-cs-grid {
        display: grid;
        grid-template-columns: 1fr;
    }
    .admin-cs-list {
        max-height: 260px;
        border-right: 0;
        border-bottom: 1px solid #e2e8f0;
    }
    .admin-cs-compose {
        grid-template-columns: 1fr;
    }
}
</style>

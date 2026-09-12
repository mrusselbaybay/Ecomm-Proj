<script setup>
import { computed, onMounted, ref } from 'vue';
import { customerServiceApi } from './customerServiceApi';

const props = defineProps({
    modal: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const faqs = ref([]);
const categories = ref([]);
const tickets = ref([]);
const selectedTicket = ref(null);
const faqSearch = ref('');
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref('');
const successMessage = ref('');
const replyBody = ref('');
const form = ref({ category: 'account', subject: '', description: '' });

const filteredFaqs = computed(() => {
    const search = faqSearch.value.trim().toLowerCase();
    if (!search) return faqs.value;

    return faqs.value.filter((faq) =>
        `${faq.question} ${faq.answer} ${faq.category}`
            .toLowerCase()
            .includes(search),
    );
});

const canReply = computed(
    () =>
        selectedTicket.value &&
        !['resolved', 'closed'].includes(selectedTicket.value.status),
);

async function loadPage() {
    isLoading.value = true;
    errorMessage.value = '';

    try {
        const [faqResponse, categoryResponse, ticketResponse] =
            await Promise.all([
                customerServiceApi('/api/customer-service/faqs'),
                customerServiceApi('/api/customer-service/categories'),
                customerServiceApi('/api/customer-service/tickets'),
            ]);
        faqs.value = faqResponse.data || [];
        categories.value = categoryResponse.data || [];
        tickets.value = ticketResponse.data || [];

        if (selectedTicket.value) {
            const current = tickets.value.find(
                (ticket) => ticket.id === selectedTicket.value.id,
            );
            if (current) await openTicket(current);
        }
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
            `/api/customer-service/tickets/${ticket.id}`,
        );
        selectedTicket.value = response.data;
    } catch (error) {
        errorMessage.value = error.message;
    }
}

async function createTicket() {
    isSaving.value = true;
    errorMessage.value = '';
    successMessage.value = '';

    try {
        const response = await customerServiceApi(
            '/api/customer-service/tickets',
            {
                method: 'POST',
                body: JSON.stringify(form.value),
            },
        );
        tickets.value.unshift(response.data);
        selectedTicket.value = response.data;
        form.value = { category: 'account', subject: '', description: '' };
        successMessage.value = `${response.data.ticketNumber} was submitted to Platform Customer Service.`;
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        isSaving.value = false;
    }
}

async function sendReply() {
    const body = replyBody.value.trim();
    if (!body || !selectedTicket.value) return;

    isSaving.value = true;
    errorMessage.value = '';
    try {
        await customerServiceApi(
            `/api/customer-service/tickets/${selectedTicket.value.id}/messages`,
            {
                method: 'POST',
                body: JSON.stringify({ body }),
            },
        );
        replyBody.value = '';
        await openTicket(selectedTicket.value);
        await refreshTicketList();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        isSaving.value = false;
    }
}

async function closeTicket() {
    await runTicketAction('close');
}

async function reopenTicket() {
    await runTicketAction('reopen');
}

async function runTicketAction(action) {
    if (!selectedTicket.value) return;

    isSaving.value = true;
    errorMessage.value = '';
    try {
        const response = await customerServiceApi(
            `/api/customer-service/tickets/${selectedTicket.value.id}/${action}`,
            { method: 'POST' },
        );
        selectedTicket.value = { ...selectedTicket.value, ...response.data };
        await refreshTicketList();
    } catch (error) {
        errorMessage.value = error.message;
    } finally {
        isSaving.value = false;
    }
}

async function refreshTicketList() {
    const response = await customerServiceApi('/api/customer-service/tickets');
    tickets.value = response.data || [];
}

function formatDate(value) {
    if (!value) return '';
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

onMounted(loadPage);
</script>

<template>
    <div
        class="cs-page"
        :class="{ 'cs-modal-shell': props.modal }"
        @click.self="emit('close')"
    >
        <section class="cs-surface" aria-label="Customer Service">
            <header class="cs-header">
                <div>
                    <span class="cs-eyebrow">NEXMART HELP CENTER</span>
                    <h1>Customer Service</h1>
                    <p>
                        Get official platform support without contacting an
                        Admin account directly.
                    </p>
                </div>
                <button
                    v-if="props.modal"
                    type="button"
                    class="cs-close"
                    aria-label="Close Customer Service"
                    @click="emit('close')"
                >
                    ×
                </button>
            </header>

            <p v-if="errorMessage" class="cs-alert cs-error">
                {{ errorMessage }}
            </p>
            <p v-if="successMessage" class="cs-alert cs-success">
                {{ successMessage }}
            </p>

            <div v-if="isLoading" class="cs-loading">
                Loading Customer Service…
            </div>

            <template v-else>
                <div class="cs-help-grid">
                    <section class="cs-card">
                        <div class="cs-card-heading">
                            <div>
                                <span class="cs-kicker">QUICK HELP</span>
                                <h2>Frequently asked questions</h2>
                            </div>
                        </div>
                        <input
                            v-model="faqSearch"
                            class="cs-input"
                            type="search"
                            placeholder="Search help topics"
                        />
                        <div class="cs-faq-list">
                            <details
                                v-for="faq in filteredFaqs"
                                :key="faq.id"
                                class="cs-faq"
                            >
                                <summary>{{ faq.question }}</summary>
                                <p>{{ faq.answer }}</p>
                            </details>
                            <p v-if="!filteredFaqs.length" class="cs-empty">
                                No FAQ matched. You can still create a ticket.
                            </p>
                        </div>
                    </section>

                    <section class="cs-card">
                        <div class="cs-card-heading">
                            <div>
                                <span class="cs-kicker">NEED MORE HELP?</span>
                                <h2>Create a support ticket</h2>
                            </div>
                        </div>
                        <form class="cs-form" @submit.prevent="createTicket">
                            <label
                                >Concern category
                                <select
                                    v-model="form.category"
                                    class="cs-input"
                                    required
                                >
                                    <option
                                        v-for="category in categories"
                                        :key="category.value"
                                        :value="category.value"
                                    >
                                        {{ category.label }}
                                    </option>
                                </select>
                            </label>
                            <label
                                >Subject
                                <input
                                    v-model="form.subject"
                                    class="cs-input"
                                    maxlength="160"
                                    required
                                    placeholder="Short summary of the concern"
                                />
                            </label>
                            <label
                                >Description
                                <textarea
                                    v-model="form.description"
                                    class="cs-input cs-textarea"
                                    maxlength="6000"
                                    required
                                    placeholder="Tell Platform Customer Service what happened"
                                ></textarea>
                            </label>
                            <button
                                class="cs-primary"
                                type="submit"
                                :disabled="isSaving"
                            >
                                {{
                                    isSaving
                                        ? 'Submitting…'
                                        : 'Create Support Ticket'
                                }}
                            </button>
                        </form>
                    </section>
                </div>

                <section class="cs-card cs-tickets-card">
                    <div class="cs-card-heading">
                        <div>
                            <span class="cs-kicker">YOUR CASES</span>
                            <h2>My tickets</h2>
                        </div>
                        <button
                            type="button"
                            class="cs-secondary"
                            @click="loadPage"
                        >
                            Refresh
                        </button>
                    </div>

                    <div class="cs-workspace">
                        <div class="cs-ticket-list">
                            <button
                                v-for="ticket in tickets"
                                :key="ticket.id"
                                type="button"
                                class="cs-ticket"
                                :class="{
                                    active: selectedTicket?.id === ticket.id,
                                }"
                                @click="openTicket(ticket)"
                            >
                                <span class="cs-ticket-top"
                                    ><strong>{{ ticket.ticketNumber }}</strong
                                    ><span
                                        :class="`cs-status status-${ticket.status}`"
                                        >{{ ticket.statusLabel }}</span
                                    ></span
                                >
                                <span class="cs-ticket-subject">{{
                                    ticket.subject
                                }}</span>
                                <span class="cs-ticket-meta"
                                    >{{ ticket.categoryLabel }} ·
                                    {{ formatDate(ticket.lastUpdatedAt) }}</span
                                >
                                <span
                                    v-if="ticket.actionNeeded"
                                    class="cs-action-needed"
                                    >Action needed</span
                                >
                            </button>
                            <p v-if="!tickets.length" class="cs-empty">
                                You have no support tickets yet.
                            </p>
                        </div>

                        <div class="cs-thread">
                            <template v-if="selectedTicket">
                                <div class="cs-thread-head">
                                    <div>
                                        <span class="cs-kicker">{{
                                            selectedTicket.ticketNumber
                                        }}</span>
                                        <h3>{{ selectedTicket.subject }}</h3>
                                    </div>
                                    <span
                                        :class="`cs-status status-${selectedTicket.status}`"
                                        >{{ selectedTicket.statusLabel }}</span
                                    >
                                </div>
                                <div
                                    v-if="selectedTicket.order"
                                    class="cs-context"
                                >
                                    Order
                                    {{ selectedTicket.order.orderNumber }} ·
                                    {{ selectedTicket.order.status }}
                                </div>
                                <div class="cs-message-list">
                                    <article
                                        v-for="message in selectedTicket.messages"
                                        :key="message.id"
                                        class="cs-message"
                                        :class="
                                            message.sender.type === 'customer'
                                                ? 'mine'
                                                : 'official'
                                        "
                                    >
                                        <strong>{{
                                            message.sender.name
                                        }}</strong>
                                        <p>{{ message.body }}</p>
                                        <time>{{
                                            formatDate(message.createdAt)
                                        }}</time>
                                    </article>
                                </div>
                                <div
                                    v-if="selectedTicket.resolutionSummary"
                                    class="cs-resolution"
                                >
                                    <strong>Official resolution</strong>
                                    <p>
                                        {{ selectedTicket.resolutionSummary }}
                                    </p>
                                </div>
                                <form
                                    v-if="canReply"
                                    class="cs-reply"
                                    @submit.prevent="sendReply"
                                >
                                    <textarea
                                        v-model="replyBody"
                                        class="cs-input"
                                        maxlength="4000"
                                        required
                                        placeholder="Reply to Platform Customer Service"
                                    ></textarea>
                                    <button
                                        class="cs-primary"
                                        :disabled="isSaving"
                                    >
                                        Send reply
                                    </button>
                                </form>
                                <div class="cs-thread-actions">
                                    <button
                                        v-if="
                                            selectedTicket.status === 'resolved'
                                        "
                                        type="button"
                                        class="cs-secondary"
                                        :disabled="isSaving"
                                        @click="closeTicket"
                                    >
                                        Close ticket
                                    </button>
                                    <button
                                        v-if="
                                            ['resolved', 'closed'].includes(
                                                selectedTicket.status,
                                            )
                                        "
                                        type="button"
                                        class="cs-secondary"
                                        :disabled="isSaving"
                                        @click="reopenTicket"
                                    >
                                        Reopen ticket
                                    </button>
                                </div>
                            </template>
                            <div v-else class="cs-empty cs-empty-thread">
                                Select a ticket to view its Customer Service
                                timeline.
                            </div>
                        </div>
                    </div>
                </section>
            </template>
        </section>
    </div>
</template>

<style scoped>
.cs-page {
    width: 100%;
    color: #172033;
    font-family: inherit;
}
.cs-modal-shell {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.55);
}
.cs-surface {
    width: min(1180px, 100%);
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    padding: 1.5rem;
    border: 1px solid #dbe5e8;
    border-radius: 24px;
    background: #f7fafb;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.2);
}
.cs-header,
.cs-card-heading,
.cs-ticket-top,
.cs-thread-head,
.cs-thread-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.cs-header {
    margin-bottom: 1.25rem;
}
.cs-header h1,
.cs-card h2,
.cs-thread h3 {
    margin: 0.2rem 0;
    color: #102a2d;
}
.cs-header h1 {
    font-size: clamp(1.55rem, 3vw, 2.25rem);
}
.cs-header p,
.cs-faq p,
.cs-message p,
.cs-resolution p {
    margin: 0.3rem 0 0;
    line-height: 1.55;
}
.cs-eyebrow,
.cs-kicker {
    color: #0f8b8d;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.12em;
}
.cs-close {
    border: 0;
    background: #e7eff0;
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 50%;
    font-size: 1.5rem;
    cursor: pointer;
}
.cs-help-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}
.cs-card {
    padding: 1.15rem;
    border: 1px solid #dfe8ea;
    border-radius: 18px;
    background: #fff;
}
.cs-card-heading {
    margin-bottom: 0.9rem;
}
.cs-card h2 {
    font-size: 1.05rem;
}
.cs-input {
    width: 100%;
    box-sizing: border-box;
    padding: 0.72rem 0.85rem;
    border: 1px solid #ccdadd;
    border-radius: 11px;
    background: #fff;
    color: #172033;
    font: inherit;
}
.cs-input:focus {
    outline: 3px solid rgba(15, 139, 141, 0.13);
    border-color: #0f8b8d;
}
.cs-textarea {
    min-height: 7rem;
    resize: vertical;
}
.cs-form {
    display: grid;
    gap: 0.75rem;
}
.cs-form label {
    display: grid;
    gap: 0.32rem;
    color: #425466;
    font-size: 0.78rem;
    font-weight: 700;
}
.cs-primary,
.cs-secondary {
    border-radius: 10px;
    padding: 0.68rem 1rem;
    font: inherit;
    font-weight: 800;
    cursor: pointer;
}
.cs-primary {
    border: 1px solid #0f8b8d;
    background: #0f8b8d;
    color: #fff;
}
.cs-secondary {
    border: 1px solid #c9d8da;
    background: #fff;
    color: #31565a;
}
button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.cs-faq-list {
    max-height: 19rem;
    overflow-y: auto;
    margin-top: 0.7rem;
}
.cs-faq {
    border-bottom: 1px solid #edf2f3;
    padding: 0.75rem 0.15rem;
}
.cs-faq summary {
    cursor: pointer;
    font-weight: 750;
}
.cs-faq p {
    color: #5e6f7c;
    font-size: 0.85rem;
}
.cs-alert {
    padding: 0.8rem 1rem;
    border-radius: 11px;
    margin: 0 0 1rem;
}
.cs-error {
    background: #fff0f0;
    color: #a32d2d;
}
.cs-success {
    background: #e9faf3;
    color: #176b50;
}
.cs-loading,
.cs-empty {
    padding: 2rem;
    text-align: center;
    color: #71808d;
}
.cs-workspace {
    display: grid;
    grid-template-columns: minmax(250px, 34%) 1fr;
    min-height: 420px;
    border: 1px solid #e1e9eb;
    border-radius: 14px;
    overflow: hidden;
}
.cs-ticket-list {
    max-height: 560px;
    overflow-y: auto;
    border-right: 1px solid #e1e9eb;
    background: #f9fbfb;
}
.cs-ticket {
    display: grid;
    gap: 0.35rem;
    width: 100%;
    padding: 0.9rem;
    border: 0;
    border-bottom: 1px solid #e6edef;
    background: transparent;
    text-align: left;
    cursor: pointer;
}
.cs-ticket:hover,
.cs-ticket.active {
    background: #eaf7f5;
}
.cs-ticket-subject {
    font-weight: 750;
    color: #24383b;
}
.cs-ticket-meta {
    color: #71808d;
    font-size: 0.73rem;
}
.cs-status {
    display: inline-flex;
    padding: 0.25rem 0.5rem;
    border-radius: 999px;
    background: #e8eef0;
    color: #4b6266;
    font-size: 0.68rem;
    font-weight: 800;
    white-space: nowrap;
}
.status-waiting_for_customer,
.cs-action-needed {
    background: #fff4d6;
    color: #8a5a00;
}
.status-resolved {
    background: #e5f7ed;
    color: #176b42;
}
.status-escalated {
    background: #fbe9ee;
    color: #a02648;
}
.cs-action-needed {
    width: fit-content;
    padding: 0.2rem 0.42rem;
    border-radius: 5px;
    font-size: 0.68rem;
    font-weight: 800;
}
.cs-thread {
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 1rem;
}
.cs-context,
.cs-resolution {
    margin: 0.6rem 0;
    padding: 0.75rem;
    border-radius: 10px;
    background: #f0f8f7;
    color: #31565a;
}
.cs-message-list {
    flex: 1;
    min-height: 230px;
    max-height: 390px;
    overflow-y: auto;
    padding: 0.5rem;
    background: #f7f9fa;
    border-radius: 12px;
}
.cs-message {
    width: fit-content;
    max-width: 78%;
    margin: 0.6rem 0;
    padding: 0.7rem 0.85rem;
    border-radius: 13px;
    overflow-wrap: anywhere;
}
.cs-message.mine {
    margin-left: auto;
    background: #0f8b8d;
    color: #fff;
}
.cs-message.official {
    background: #fff;
    border: 1px solid #dfe8ea;
}
.cs-message strong,
.cs-message time {
    display: block;
    font-size: 0.68rem;
}
.cs-message time {
    margin-top: 0.35rem;
    opacity: 0.72;
}
.cs-reply {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: end;
    gap: 0.6rem;
    margin-top: 0.8rem;
}
.cs-reply textarea {
    min-height: 3rem;
    max-height: 9rem;
    resize: vertical;
}
.cs-thread-actions {
    justify-content: flex-end;
    margin-top: 0.6rem;
}
.cs-empty-thread {
    margin: auto;
}
@media (max-width: 760px) {
    .cs-surface {
        padding: 1rem;
        border-radius: 16px;
    }
    .cs-help-grid,
    .cs-workspace {
        grid-template-columns: 1fr;
    }
    .cs-ticket-list {
        max-height: 260px;
        border-right: 0;
        border-bottom: 1px solid #e1e9eb;
    }
    .cs-reply {
        grid-template-columns: 1fr;
    }
}
</style>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { apiRequest } from '../accountApi';
import { createClient } from '../backendClient';

const props = defineProps({ mode: { type: String, default: 'courier' } });
window.__btwSupabase ??= createClient();
const client = window.__btwSupabase;
const companyMode = computed(() => props.mode === 'logistics');
const base = computed(() =>
    companyMode.value ? '/api/logistics/earnings' : '/api/courier',
);
const tab = ref(companyMode.value ? 'couriers' : 'ledger');
const tabs = computed(() =>
    companyMode.value
        ? ['couriers', 'ledger', 'payouts', 'settings']
        : ['ledger', 'payouts', 'tasks'],
);
const rows = ref([]);
const summary = ref({});
const settings = ref({});
const courier = ref('');
const status = ref('');
const page = ref(1);
const lastPage = ref(1);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const canManage = ref(false);
const dialog = ref('');
const form = ref({});
const selected = ref(null);
const statementPanel = ref(null);
const modal = ref(null);
let loadGeneration = 0;
watch(dialog, async (value) => {
    if (value) {
        await nextTick();
        modal.value?.showModal();
    }
});
const labels = {
    couriers: 'Couriers',
    ledger: 'Earnings ledger',
    payouts: 'Payouts & payslips',
    settings: 'Company settings',
    tasks: 'Assigned tasks',
};
const money = (value = 0) => {
    const n = Math.abs(Number(value));

    return `${Number(value) < 0 ? '−' : ''}₱${Math.trunc(n / 100).toLocaleString('en-PH')}.${String(n % 100).padStart(2, '0')}`;
};
const cents = (value) => {
    if (!/^\d+(\.\d{1,2})?$/.test(String(value))) {
throw new Error('Enter an amount with at most two decimal places.');
}

    const [whole, decimal = ''] = String(value).split('.');

    return Number(whole) * 100 + Number(decimal.padEnd(2, '0'));
};
const decimal = (value) =>
    `${Math.trunc(Number(value) / 100)}.${String(Number(value) % 100).padStart(2, '0')}`;
const date = (value) =>
    value
        ? new Date(value).toLocaleDateString('en-PH', {
              timeZone: 'Asia/Manila',
              year: 'numeric',
              month: 'short',
              day: 'numeric',
          })
        : '—';
const human = (value) => String(value || '').replaceAll('_', ' ');

async function request(path, options) {
    const result = await apiRequest(client, path, options);

    if (result.error) {
throw result.error;
}

    return { data: result.data };
}
async function load() {
    const generation = ++loadGeneration;
    loading.value = true;
    error.value = '';

    try {
        if (tab.value === 'settings') {
            const payload = await request(`${base.value}/settings`);

            if (generation !== loadGeneration) {
return;
}

            settings.value = {
                ...payload.data,
                rate: decimal(payload.data.courier_share_bps),
                minimum:
                    payload.data.early_cashout_minimum_cents == null
                        ? ''
                        : decimal(payload.data.early_cashout_minimum_cents),
                reason: '',
            };
        } else if (tab.value === 'tasks') {
            const payload = await request('/api/driver/deliveries');

            if (generation !== loadGeneration) {
return;
}

            rows.value = payload.data;
            lastPage.value = 1;
        } else {
            const path = companyMode.value
                ? `${base.value}${tab.value === 'ledger' ? '' : `/${tab.value}`}`
                : `${base.value}/${tab.value === 'ledger' ? 'earnings' : 'payouts'}`;
            const query = new URLSearchParams({ page: String(page.value) });

            if (courier.value && companyMode.value && tab.value !== 'couriers') {
query.set('courier_id', courier.value);
}

            if (status.value && tab.value === 'ledger') {
query.set('status', status.value);
}

            const payload = await request(`${path}?${query}`);

            if (generation !== loadGeneration) {
return;
}

            rows.value = payload.data.data;
            lastPage.value = payload.data.last_page;

            if (tab.value === 'couriers') {
canManage.value = payload.data.can_manage;
}
        }

        if (!companyMode.value) {
            const payload = await request(`${base.value}/earnings/summary`);

            if (generation === loadGeneration) {
summary.value = payload.data;
}
        }
    } catch (e) {
        if (generation === loadGeneration) {
error.value = e.message;
}
    } finally {
        if (generation === loadGeneration) {
loading.value = false;
}
    }
}
function changeTab(value) {
    tab.value = value;
    rows.value = [];
    page.value = 1;
    selected.value = null;
    status.value = '';
    load();
}
function inspectCourier(row) {
    courier.value = row.id;
    changeTab('ledger');
}
function openForm(type, id = courier.value) {
    const today = new Date();
    const end = today.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' });
    today.setDate(today.getDate() - 6);
    form.value = {
        courier_id: id,
        type: 'bonus',
        amount: '',
        reason: '',
        parcel_id: '',
        reference: '',
        period_start: today.toLocaleDateString('en-CA', {
            timeZone: 'Asia/Manila',
        }),
        period_end: end,
    };
    dialog.value = type;
}
async function mutate(operation, success) {
    if (busy.value) {
return;
}

    busy.value = true;
    error.value = '';
    notice.value = '';

    try {
        await operation();
        notice.value = success;
        dialog.value = '';
        selected.value = null;
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
async function submit() {
    const f = form.value;
    await mutate(async () => {
        if (dialog.value === 'adjustment') {
await request(`${base.value}/adjustments`, {
                method: 'POST',
                body: {
                    courier_id: f.courier_id,
                    type: f.type,
                    amount_cents: cents(f.amount),
                    reason: f.reason,
                    parcel_id: f.parcel_id || null,
                },
            });
}

        if (dialog.value === 'remittance') {
await request(`${base.value}/cod-remittances`, {
                method: 'POST',
                body: {
                    courier_id: f.courier_id,
                    amount_cents: cents(f.amount),
                    reference: f.reference,
                },
            });
}

        if (dialog.value === 'statement') {
await request(`${base.value}/payouts`, {
                method: 'POST',
                body: {
                    courier_id: f.courier_id,
                    period_start: f.period_start,
                    period_end: f.period_end,
                },
            });
}

        if (dialog.value === 'pay') {
await request(`${base.value}/payouts/${f.id}/pay`, {
                method: 'POST',
                body: { reference: f.reference },
            });
}
    }, 'Saved successfully.');
}
async function saveSettings() {
    await mutate(
        () =>
            request(`${base.value}/settings`, {
                method: 'PUT',
                body: {
                    courier_share_bps: cents(settings.value.rate),
                    pickup_weight: settings.value.pickup_weight,
                    transfer_weight: settings.value.transfer_weight,
                    delivery_weight: settings.value.delivery_weight,
                    cod_overdue_days: settings.value.cod_overdue_days,
                    early_cashout_minimum_cents:
                        settings.value.minimum === ''
                            ? null
                            : cents(settings.value.minimum),
                    reason: settings.value.reason,
                },
            }),
        'Company settings saved.',
    );
}
async function inspectPayout(row) {
    busy.value = true;

    try {
        selected.value = (
            await request(`${base.value}/payouts/${row.id}`)
        ).data;
        await nextTick();
        statementPanel.value?.focus({ preventScroll: true });
        statementPanel.value?.scrollIntoView({ block: 'start' });
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}
function action(value) {
    if (value === 'pay') {
        form.value = { id: selected.value.id, reference: '' };
        dialog.value = 'pay';

        return;
    }

    mutate(
        () =>
            request(`${base.value}/payouts/${selected.value.id}/${value}`, {
                method: 'POST',
            }),
        value === 'approve' ? 'Statement approved.' : 'Statement cancelled.',
    );
}
onMounted(load);
</script>

<template>
    <section class="earnings-page" :aria-busy="loading">
        <header class="earnings-heading">
            <div>
                <h1>
                    {{ companyMode ? 'Earnings & Payouts' : 'Your earnings' }}
                </h1>
                <p>
                    {{
                        companyMode
                            ? 'Review courier earnings, reconcile COD, and prepare weekly payments.'
                            : 'Your company pays for completed tasks. Follow each earning from pending to paid.'
                    }}
                </p>
            </div>
            <button class="quiet" :disabled="loading || busy" @click="load">
                ↻ Refresh
            </button>
        </header>
        <div v-if="error" class="message error" role="alert">
            {{ error }}
            <button :disabled="loading || busy" @click="load">Retry</button>
        </div>
        <p v-if="notice" class="message success" role="status">{{ notice }}</p>
        <div v-if="!companyMode" class="balances">
            <article
                v-for="[key, title] in [
                    ['pending_cents', 'Pending'],
                    ['available_cents', 'Available earnings'],
                    ['paid_cents', 'Paid'],
                    ['cod_owed_cents', 'COD to hand over'],
                ]"
                :key="key"
            >
                <span>{{ title }}</span
                ><strong>{{ money(summary[key]) }}</strong>
            </article>
        </div>
        <div v-if="!companyMode" class="cashout">
            <div>
                <strong
                    >After adjustments & COD:
                    {{ money(summary.net_cents) }}</strong
                >
                <p v-if="summary.early_cashout_minimum_cents == null">
                    Early cash-out not enabled by your company
                </p>
                <p v-else>
                    Early cash-out minimum:
                    {{ money(summary.early_cashout_minimum_cents) }}
                </p>
            </div>
            <button
                v-if="summary.early_cashout_minimum_cents != null"
                class="primary"
                :disabled="
                    busy ||
                    summary.net_cents < summary.early_cashout_minimum_cents
                "
                @click="
                    mutate(
                        () =>
                            request(`${base}/earnings/early-cashout`, {
                                method: 'POST',
                            }),
                        'Cash-out requested. Your company will review the statement.',
                    )
                "
            >
                Request early cash-out
            </button>
        </div>
        <nav class="earnings-tabs" aria-label="Earnings views">
            <button
                v-for="item in tabs"
                :key="item"
                :aria-current="tab === item ? 'page' : undefined"
                :class="{ active: tab === item }"
                @click="changeTab(item)"
            >
                {{ labels[item] }}
            </button>
        </nav>
        <div
            v-if="companyMode && courier && tab !== 'settings'"
            class="toolbar"
        >
            <span>Showing selected courier</span
            ><button
                class="quiet"
                @click="
                    courier = '';
                    page = 1;
                    load();
                "
            >
                Clear filter</button
            ><template v-if="canManage"
                ><button class="quiet" @click="openForm('adjustment')">
                    ＋ Adjustment</button
                ><button class="quiet" @click="openForm('remittance')">
                    Record COD handover</button
                ><button class="primary" @click="openForm('statement')">
                    Prepare statement
                </button></template
            >
        </div>
        <div v-if="tab === 'ledger'" class="toolbar">
            <label
                >Status
                <select
                    v-model="status"
                    @change="
                        page = 1;
                        load();
                    "
                >
                    <option value="">All statuses</option>
                    <option
                        v-for="item in ['pending', 'available', 'paid', 'void']"
                        :key="item"
                    >
                        {{ item }}
                    </option>
                </select></label
            ><small
                >Task amounts stay provisional until settlement confirms the
                route.</small
            >
        </div>
        <div v-if="loading" class="skeletons" aria-label="Loading earnings">
            <div v-for="n in 5" :key="n" class="skeleton"></div>
        </div>
        <form
            v-else-if="tab === 'settings'"
            class="settings-form"
            @submit.prevent="saveSettings"
        >
            <h2>How your couriers earn</h2>
            <p>
                Saved changes apply to new tasks. Existing earnings keep their
                original rate and weights.
            </p>
            <label
                >Courier share (%)<input
                    v-model="settings.rate"
                    inputmode="decimal"
                    required
            /></label>
            <fieldset>
                <legend>Task weights · must total 100%</legend>
                <div class="weight-fields">
                    <label
                        >Pickup (%)<input
                            v-model.number="settings.pickup_weight"
                            type="number"
                            min="0"
                            max="100"
                            required /></label
                    ><label
                        >Hub transfer (%)<input
                            v-model.number="settings.transfer_weight"
                            type="number"
                            min="0"
                            max="100"
                            required /></label
                    ><label
                        >Delivery (%)<input
                            v-model.number="settings.delivery_weight"
                            type="number"
                            min="0"
                            max="100"
                            required
                    /></label>
                </div>
                <small
                    >Only tasks performed in your company’s leg count. Their
                    weights scale to 100%.</small
                >
            </fieldset>
            <label
                >COD overdue after (days)<input
                    v-model.number="settings.cod_overdue_days"
                    type="number"
                    min="1"
                    max="365"
                    required
            /></label>
            <label
                >Early cash-out minimum (₱)<input
                    v-model="settings.minimum"
                    inputmode="decimal"
                    placeholder="500.00"
                /><small
                    >Leave empty to disable. Suggested minimum: ₱500.</small
                ></label
            >
            <label
                >Reason for change<textarea
                    v-model="settings.reason"
                    required
                    maxlength="1000"
                ></textarea></label
            ><button v-if="canManage" class="primary" :disabled="busy">
                Save settings
            </button>
            <p v-else>
                Only the company owner or admin can change these settings.
            </p>
        </form>
        <div v-else-if="!rows.length && !error" class="empty">
            <svg
                width="36"
                height="36"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5" />
            </svg>
            <h2>
                {{
                    tab === 'couriers'
                        ? 'No couriers yet'
                        : tab === 'tasks'
                          ? 'No assigned tasks'
                          : 'No records yet'
                }}
            </h2>
            <p>
                {{
                    tab === 'couriers'
                        ? 'Accepted couriers will appear here.'
                        : tab === 'payouts'
                          ? 'Weekly statements appear here once your company prepares them.'
                          : 'Completed tasks appear in the ledger automatically.'
                }}
            </p>
        </div>
        <div v-else-if="tab === 'tasks'" class="tasks">
            <article v-for="row in rows" :key="row.id">
                <div>
                    <strong>#{{ row.order_number }}</strong
                    ><span class="badge">{{ human(row.status) }}</span>
                </div>
                <p>{{ row.pickup_label }} → {{ row.dropoff_label }}</p>
                <strong class="task-pay"
                    >You’ll earn {{ money(row.earning_preview_cents) }}</strong
                ><small
                    >{{
                        row.earning_is_estimate
                            ? 'Estimated until the final route is confirmed.'
                            : 'For this task.'
                    }}
                    Complete the task in your courier app.</small
                >
            </article>
        </div>
        <div v-else-if="tab !== 'settings'" class="table-scroll">
            <table>
                <thead>
                    <tr v-if="tab === 'couriers'">
                        <th>Courier</th>
                        <th>Pending</th>
                        <th>Available</th>
                        <th>COD owed</th>
                        <th></th>
                    </tr>
                    <tr v-else-if="tab === 'ledger'">
                        <th>Task / reason</th>
                        <th>Order</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Amount</th>
                    </tr>
                    <tr v-else>
                        <th>Courier / period</th>
                        <th>Status</th>
                        <th>COD offset</th>
                        <th>Net payout</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <template v-if="tab === 'couriers'"
                            ><td>
                                <strong
                                    >{{ row.first_name }}
                                    {{ row.last_name }}</strong
                                >
                            </td>
                            <td>{{ money(row.pending_cents) }}</td>
                            <td>{{ money(row.available_cents) }}</td>
                            <td>{{ money(row.cod_owed_cents) }}</td>
                            <td>
                                <button
                                    class="quiet"
                                    @click="inspectCourier(row)"
                                >
                                    View earnings
                                </button>
                            </td></template
                        ><template v-else-if="tab === 'ledger'"
                            ><td>
                                <strong>{{ human(row.type) }}</strong
                                ><small v-if="row.reason">{{
                                    row.reason
                                }}</small
                                ><small v-if="row.direction === 'return'"
                                    >Approved return</small
                                >
                            </td>
                            <td>{{ row.order?.order_number || '—' }}</td>
                            <td>{{ date(row.created_at) }}</td>
                            <td>
                                <span class="badge" :class="row.status">{{
                                    row.status
                                }}</span>
                            </td>
                            <td class="amount">
                                {{ money(row.amount_cents)
                                }}<small
                                    v-if="
                                        row.remaining_cents &&
                                        row.remaining_cents !== row.amount_cents
                                    "
                                    >Remaining:
                                    {{ money(row.remaining_cents) }}</small
                                >
                            </td></template
                        ><template v-else
                            ><td>
                                <strong
                                    >{{ row.courier?.first_name }}
                                    {{ row.courier?.last_name }}</strong
                                ><small
                                    >{{ date(row.period_start) }} –
                                    {{ date(row.period_end) }}</small
                                >
                            </td>
                            <td>
                                <span class="badge" :class="row.status">{{
                                    row.status
                                }}</span>
                            </td>
                            <td>{{ money(row.cod_offset_cents) }}</td>
                            <td class="amount">{{ money(row.net_cents) }}</td>
                            <td>
                                <button
                                    class="quiet"
                                    :disabled="busy"
                                    @click="inspectPayout(row)"
                                >
                                    View statement
                                </button>
                            </td></template
                        >
                    </tr>
                </tbody>
            </table>
        </div>
        <footer v-if="lastPage > 1 && !loading" class="pagination">
            <button
                class="quiet"
                :disabled="page === 1"
                @click="
                    page--;
                    load();
                "
            >
                Previous</button
            ><span>Page {{ page }} of {{ lastPage }}</span
            ><button
                class="quiet"
                :disabled="page >= lastPage"
                @click="
                    page++;
                    load();
                "
            >
                Next
            </button>
        </footer>
        <section
            v-if="selected"
            ref="statementPanel"
            tabindex="-1"
            class="statement"
            aria-label="Payout statement"
        >
            <header>
                <div>
                    <h2>Payout statement</h2>
                    <p>
                        {{ selected.courier?.first_name }}
                        {{ selected.courier?.last_name }} ·
                        {{ date(selected.period_start) }} –
                        {{ date(selected.period_end) }}
                    </p>
                </div>
                <button class="quiet" @click="selected = null">Close</button>
            </header>
            <ul>
                <li v-for="line in selected.lines" :key="line.id">
                    <span
                        >{{ human(line.earning.type)
                        }}<small>{{
                            line.earning.reason ||
                            line.earning.order?.order_number
                        }}</small></span
                    ><strong>{{ money(line.amount_cents) }}</strong>
                </li>
                <li>
                    <span>COD settled by this payout</span
                    ><strong>−{{ money(selected.cod_offset_cents) }}</strong>
                </li>
                <li class="net">
                    <span>Net payout</span
                    ><strong>{{ money(selected.net_cents) }}</strong>
                </li>
            </ul>
            <p v-if="selected.reference">
                Payment reference: {{ selected.reference }} ·
                {{ date(selected.paid_at) }}
            </p>
            <p>Any unpaid deduction or COD balance carries forward.</p>
            <div
                v-if="
                    companyMode &&
                    canManage &&
                    ['draft', 'approved'].includes(selected.status)
                "
                class="toolbar"
            >
                <button
                    class="primary"
                    :disabled="busy"
                    @click="
                        action(selected.status === 'draft' ? 'approve' : 'pay')
                    "
                >
                    {{
                        selected.status === 'draft'
                            ? 'Approve statement'
                            : 'Mark paid'
                    }}</button
                ><button
                    class="quiet"
                    :disabled="busy"
                    @click="action('cancel')"
                >
                    Cancel statement
                </button>
            </div>
        </section>
        <Teleport to="body"
            ><dialog
                v-if="dialog"
                ref="modal"
                class="earnings-overlay"
                @cancel.prevent="!busy && (dialog = '')"
            >
                <section
                    class="earnings-dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="earnings-form-title"
                >
                    <h2 id="earnings-form-title">
                        {{
                            {
                                adjustment: 'Record adjustment',
                                remittance: 'Record COD handover',
                                statement: 'Prepare weekly statement',
                                pay: 'Mark payout paid',
                            }[dialog]
                        }}
                    </h2>
                    <p v-if="error" class="message error" role="alert">
                        {{ error }}
                    </p>
                    <form @submit.prevent="submit">
                        <template v-if="dialog === 'adjustment'"
                            ><label
                                >Entry type<select v-model="form.type">
                                    <option value="bonus">Bonus</option>
                                    <option value="deduction">Deduction</option>
                                    <option value="tip">
                                        Tip · 100% to courier
                                    </option>
                                </select></label
                            ><label
                                >Reason<textarea
                                    v-model="form.reason"
                                    required
                                    maxlength="1000"
                                    autofocus
                                ></textarea></label
                            ><label
                                >Parcel assignment ID (optional)<input
                                    v-model="
                                        form.parcel_id
                                    " /></label></template
                        ><label
                            v-if="['adjustment', 'remittance'].includes(dialog)"
                            >Amount (₱)<input
                                v-model="form.amount"
                                inputmode="decimal"
                                required /></label
                        ><template v-if="dialog === 'statement'"
                            ><label
                                >Week starts<input
                                    v-model="form.period_start"
                                    type="date"
                                    required /></label
                            ><label
                                >Week ends<input
                                    v-model="form.period_end"
                                    type="date"
                                    required
                            /></label>
                            <p>
                                Includes available earnings through this date
                                and any carried deductions.
                            </p></template
                        ><label v-if="['remittance', 'pay'].includes(dialog)"
                            >Reference number<input
                                v-model="form.reference"
                                required
                                maxlength="255"
                                autofocus
                        /></label>
                        <div class="toolbar">
                            <button class="primary" :disabled="busy">
                                {{ busy ? 'Saving…' : 'Save' }}</button
                            ><button
                                type="button"
                                class="quiet"
                                :disabled="busy"
                                @click="
                                    dialog = '';
                                    error = '';
                                "
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                </section>
            </dialog></Teleport
        >
    </section>
</template>

<style scoped>
.earnings-page {
    max-width: 1200px;
    margin: auto;
    color: #183b3b;
    font-family: inherit;
}
.earnings-heading,
.statement header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 20px;
    margin-bottom: 24px;
}
h1 {
    font-size: 28px;
    line-height: 1.2;
    font-weight: 750;
    letter-spacing: -0.7px;
}
h2 {
    font-size: 19px;
    font-weight: 700;
}
p,
small {
    color: #607578;
    line-height: 1.6;
}
small {
    display: block;
    font-size: 12px;
}
button,
input,
select,
textarea {
    font: inherit;
}
button {
    cursor: pointer;
    min-height: 44px;
    border-radius: 8px;
    padding: 9px 14px;
    font-weight: 600;
    font-size: 13px;
}
button:disabled {
    opacity: 0.5;
    cursor: default;
}
button:focus-visible,
input:focus-visible,
select:focus-visible,
textarea:focus-visible {
    outline: 3px solid #53b8b4;
    outline-offset: 2px;
}
.primary {
    background: #0b6963;
    color: white;
    border: 1px solid #0b6963;
}
.quiet {
    background: white;
    border: 1px solid #d5e4e3;
    color: #235c59;
}
.balances {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    background: white;
    border: 1px solid #d5e4e3;
    border-radius: 12px;
    margin-bottom: 16px;
}
.balances article {
    padding: 22px;
    border-right: 1px solid #e7efee;
}
.balances article:last-child {
    border: 0;
}
.balances span {
    font-size: 13px;
    color: #607578;
}
.balances strong {
    display: block;
    font-size: 26px;
    letter-spacing: -0.6px;
    font-variant-numeric: tabular-nums;
}
.cashout {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: center;
    background: #eaf6f3;
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 24px;
}
.earnings-tabs {
    display: flex;
    border-bottom: 1px solid #d5e4e3;
    gap: 12px;
    overflow: auto;
    margin-bottom: 20px;
}
.earnings-tabs button {
    border: 0;
    background: none;
    white-space: nowrap;
    border-radius: 0;
    border-bottom: 3px solid transparent;
    color: #607578;
    padding: 14px 10px;
}
.earnings-tabs button.active {
    border-color: #0b6963;
    color: #0b6963;
}
.toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.toolbar small {
    margin-left: auto;
}
.message {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.error {
    background: #fff1f1;
    color: #9d3030;
}
.success {
    background: #eaf6f3;
    color: #0b6963;
}
.message button {
    background: none;
    text-decoration: underline;
    border: 0;
}
.table-scroll {
    overflow-x: auto;
    background: white;
    border: 1px solid #d5e4e3;
    border-radius: 10px;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    text-align: left;
}
th {
    font-size: 12px;
    color: #607578;
    font-weight: 600;
    background: #f4f8f7;
}
th,
td {
    padding: 16px;
    border-bottom: 1px solid #e7efee;
    white-space: nowrap;
}
td:first-child {
    white-space: normal;
    min-width: 170px;
}
tbody tr:last-child td {
    border-bottom: 0;
}
.amount {
    font-weight: 650;
    font-variant-numeric: tabular-nums;
}
.badge {
    display: inline-block;
    border-radius: 5px;
    padding: 4px 8px;
    background: #edf2f2;
    color: #496466;
    font-size: 12px;
}
.badge.available,
.badge.approved {
    background: #e1f3ed;
    color: #216d50;
}
.badge.paid {
    background: #e6effc;
    color: #285c94;
}
.badge.void,
.badge.cancelled {
    background: #faeeee;
    color: #9d3030;
}
.empty {
    text-align: center;
    padding: 54px 20px;
    background: white;
    border: 1px dashed #c6d9d6;
    border-radius: 10px;
}
.empty svg {
    margin: 0 auto 12px;
    color: #6e918d;
}
.pagination {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 14px;
    margin-top: 16px;
    font-size: 13px;
}
.settings-form {
    max-width: 660px;
    background: white;
    border: 1px solid #d5e4e3;
    border-radius: 12px;
    padding: 24px;
}
label {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
}
form label {
    margin: 16px 0;
}
input,
select,
textarea {
    padding: 10px 12px;
    border: 1px solid #c6d9d6;
    border-radius: 7px;
    background: white;
    color: #183b3b;
    min-height: 44px;
    width: 100%;
}
textarea {
    min-height: 82px;
    resize: vertical;
}
fieldset {
    border: 1px solid #d5e4e3;
    padding: 12px;
    border-radius: 8px;
    margin: 20px 0;
}
legend {
    font-size: 13px;
    padding: 0 5px;
}
.weight-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}
.statement {
    margin-top: 24px;
    background: white;
    border: 1px solid #a8ccc5;
    border-radius: 12px;
    padding: 24px;
}
.statement ul {
    list-style: none;
    padding: 0;
}
.statement li {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #e7efee;
    gap: 20px;
}
.statement .net {
    font-size: 20px;
}
.skeletons {
    display: grid;
    gap: 12px;
}
.skeleton {
    height: 62px;
    background: #e5edeb;
    border-radius: 8px;
    animation: pulse 1.4s ease-in-out infinite;
}
.tasks {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 16px;
}
.tasks article {
    padding: 20px;
    background: white;
    border: 1px solid #d5e4e3;
    border-radius: 10px;
}
.tasks article > div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}
.task-pay {
    display: block;
    color: #0b6963;
    margin-top: 16px;
    font-size: 19px;
}
.earnings-overlay {
    border: 0;
    padding: 0;
    background: transparent;
    max-width: calc(100vw - 32px);
    max-height: 90vh;
    margin: auto;
}
.earnings-overlay::backdrop {
    background: #12353280;
}
.earnings-dialog {
    box-sizing: border-box;
}
.earnings-dialog {
    width: min(100%, 500px);
    max-height: 90vh;
    overflow: auto;
    background: white;
    border-radius: 14px;
    padding: 24px;
    color: #183b3b;
}
@keyframes pulse {
    50% {
        opacity: 0.55;
    }
}
@media (prefers-reduced-motion: reduce) {
    .skeleton {
        animation: none;
    }
}
@media (max-width: 640px) {
    .balances {
        grid-template-columns: repeat(2, 1fr);
    }
    .balances article {
        padding: 16px;
    }
    .balances strong {
        font-size: 22px;
    }
    .earnings-heading {
        gap: 12px;
    }
    h1 {
        font-size: 23px;
    }
    .cashout {
        align-items: start;
        flex-direction: column;
    }
    .toolbar small {
        margin-left: 0;
    }
    .weight-fields {
        grid-template-columns: 1fr;
    }
    th,
    td {
        padding: 12px;
    }
    .statement {
        padding: 16px;
    }
}
</style>

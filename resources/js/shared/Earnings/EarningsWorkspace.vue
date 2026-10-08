<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { apiRequest } from '../accountApi';
import { createClient } from '../backendClient';
import EarningsIcon from './EarningsIcon.vue';

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
const earlyCashoutEnabled = ref(false);
const courier = ref('');
const selectedCourier = ref(null);
const selectedBalance = ref({});
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
const parcelPage = ref(1);
const parcelHasMore = ref(false);
const parcelPickerOpen = ref(false);
const chosenParcel = ref(null);
const parcelResults = ref([]);
const parcelSearching = ref(false);
const parcelSearchError = ref('');
let parcelSearchGeneration = 0;
let loadGeneration = 0;
watch(dialog, async (value) => {
    if (value) {
        await nextTick();
        modal.value?.showModal();
    }
});
async function loadParcels(pageNumber = 1) {
    const generation = ++parcelSearchGeneration;
    parcelSearching.value = true;
    parcelSearchError.value = '';

    try {
        const query = new URLSearchParams({
            courier_id: form.value.courier_id,
            page: String(pageNumber),
        });
        const payload = await request(`${base.value}/parcels?${query}`);

        if (generation !== parcelSearchGeneration) {
            return;
        }

        parcelResults.value = payload.data.items;
        parcelPage.value = payload.data.page;
        parcelHasMore.value = payload.data.has_more;
    } catch (e) {
        if (generation === parcelSearchGeneration) {
            parcelSearchError.value = e.message;
        }
    } finally {
        if (generation === parcelSearchGeneration) {
            parcelSearching.value = false;
        }
    }
}
function chooseParcel(item) {
    chosenParcel.value = item;
    form.value.parcel_id = item?.id || '';
    parcelPickerOpen.value = false;
}
watch(dialog, (value) => {
    if (!value) {
        parcelSearchGeneration++;
    }
});
function numericInput(event, target, key) {
    const raw = event.target.value.replace(/[^0-9.]/g, '');
    const [whole, ...fraction] = raw.split('.');
    const value =
        whole + (fraction.length ? '.' + fraction.join('').slice(0, 2) : '');
    event.target.value = value;
    target[key] = value;
}
function numericKey(event) {
    if (
        !event.ctrlKey &&
        !event.metaKey &&
        event.key.length === 1 &&
        !/[0-9.]/.test(event.key)
    ) {
        event.preventDefault();
    }
}
function updateWeek() {
    const end = Date.parse(`${form.value.period_end}T00:00:00Z`);

    if (Number.isFinite(end)) {
        form.value.period_start = new Date(end - 6 * 86400000)
            .toISOString()
            .slice(0, 10);
    }
}
const courierName = computed(() =>
    selectedCourier.value
        ? `${selectedCourier.value.first_name} ${selectedCourier.value.last_name}`.trim()
        : 'Selected courier',
);
const weightTotal = computed(() =>
    ['pickup_weight', 'transfer_weight', 'delivery_weight'].reduce(
        (total, key) => total + Number(settings.value[key] || 0),
        0,
    ),
);
const manilaDateFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});
const todayManila = () => {
    const parts = Object.fromEntries(
        manilaDateFormatter
            .formatToParts(new Date())
            .map(({ type, value }) => [type, value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}`;
};
const statementDatesValid = computed(() => {
    const start = Date.parse(`${form.value.period_start}T00:00:00Z`);
    const end = Date.parse(`${form.value.period_end}T00:00:00Z`);

    return (
        Number.isFinite(start) &&
        Number.isFinite(end) &&
        (end - start) / 86400000 === 6 &&
        form.value.period_end <= todayManila()
    );
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

            canManage.value = payload.data.can_manage;
            earlyCashoutEnabled.value =
                payload.data.early_cashout_minimum_cents != null;
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

            if (
                courier.value &&
                companyMode.value &&
                tab.value !== 'couriers'
            ) {
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

            if (
                companyMode.value &&
                courier.value &&
                tab.value !== 'couriers'
            ) {
                const balance = await request(
                    `${base.value}/couriers/${courier.value}/summary`,
                );

                if (generation !== loadGeneration) {
                    return;
                }

                selectedBalance.value = balance.data;
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
    selectedCourier.value = row;
    selectedBalance.value = {
        pending_cents: row.pending_cents,
        available_cents: row.available_cents,
        cod_owed_cents: row.cod_owed_cents,
    };
    changeTab('ledger');
}
function clearCourier() {
    courier.value = '';
    selectedCourier.value = null;
    selectedBalance.value = {};
    page.value = 1;
    load();
}
function openForm(type, id = courier.value) {
    const end = todayManila();
    const start = new Date(Date.parse(`${end}T00:00:00Z`) - 6 * 86400000)
        .toISOString()
        .slice(0, 10);
    form.value = {
        courier_id: id,
        type: 'bonus',
        amount:
            type === 'remittance' && selectedBalance.value.cod_owed_cents
                ? decimal(selectedBalance.value.cod_owed_cents)
                : '',
        reason: '',
        parcel_id: '',
        reference: '',
        period_start: start,
        period_end: end,
    };
    chosenParcel.value = null;
    parcelPickerOpen.value = false;
    parcelResults.value = [];
    parcelSearchError.value = '';
    error.value = '';
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
    const kind = dialog.value;
    let handoverReference = '';

    if (['adjustment', 'remittance'].includes(kind)) {
        const amount = Number(f.amount);

        if (
            !Number.isFinite(amount) ||
            amount <= 0 ||
            (kind === 'remittance' &&
                Math.round(amount * 100) > selectedBalance.value.cod_owed_cents)
        ) {
            error.value =
                'Enter a positive amount that does not exceed the COD owed.';

            return;
        }
    }

    await mutate(
        async () => {
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
                const receipt = await request(`${base.value}/cod-remittances`, {
                    method: 'POST',
                    body: {
                        courier_id: f.courier_id,
                        amount_cents: cents(f.amount),
                    },
                });
                handoverReference = receipt.data.reference;
            }

            if (kind === 'statement') {
                await request(`${base.value}/payouts`, {
                    method: 'POST',
                    body: {
                        courier_id: f.courier_id,
                        period_start: f.period_start,
                        period_end: f.period_end,
                    },
                });
                tab.value = 'payouts';
                rows.value = [];
                page.value = 1;
            }

            if (dialog.value === 'pay') {
                await request(`${base.value}/payouts/${f.id}/pay`, {
                    method: 'POST',
                    body: { reference: f.reference },
                });
            }
        },
        {
            adjustment: 'Adjustment saved.',
            remittance:
                'COD handover recorded. The courier balance is updated.',
            statement: 'Statement prepared. Review it below.',
            pay: 'Payout marked paid.',
        }[kind],
    );

    if (handoverReference && !error.value) {
        notice.value = `COD handover recorded. Reference: ${handoverReference}`;
    }
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
                        !earlyCashoutEnabled.value ||
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
    <section
        class="earnings-page"
        :class="{ 'logistics-earnings': companyMode }"
        :aria-busy="loading"
    >
        <header class="earnings-heading">
            <div>
                <h1>
                    {{ companyMode ? 'Courier finances' : 'Your earnings' }}
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
                <EarningsIcon v-if="companyMode" :name="item" />{{
                    labels[item]
                }}
            </button>
        </nav>
        <div
            v-if="companyMode && courier && ['ledger', 'payouts'].includes(tab)"
            class="courier-toolbar"
        >
            <div class="courier-toolbar-copy">
                <span class="courier-avatar" aria-hidden="true">{{
                    courierName
                        .split(' ')
                        .map((part) => part[0])
                        .slice(0, 2)
                        .join('')
                }}</span
                ><small>Selected courier</small>
                <strong>{{ courierName }}</strong>
                <span
                    >COD awaiting handover:
                    <b>{{ money(selectedBalance.cod_owed_cents) }}</b></span
                >
            </div>
            <div class="courier-toolbar-actions">
                <button
                    class="quiet"
                    :disabled="loading || busy"
                    @click="clearCourier"
                >
                    Clear selection
                </button>
                <template v-if="canManage">
                    <button
                        class="quiet"
                        :disabled="loading || busy"
                        @click="openForm('adjustment')"
                    >
                        <EarningsIcon name="adjustment" /> Adjustment
                    </button>
                    <button
                        class="quiet"
                        :disabled="
                            loading || busy || !selectedBalance.cod_owed_cents
                        "
                        @click="openForm('remittance')"
                    >
                        <EarningsIcon name="remittance" /> Record handover
                    </button>
                    <button
                        class="primary"
                        :disabled="loading || busy"
                        @click="openForm('statement')"
                    >
                        <EarningsIcon name="statement" /> Prepare statement
                    </button>
                </template>
            </div>
        </div>
        <div
            v-if="companyMode && courier && ['ledger', 'payouts'].includes(tab)"
            class="courier-figures"
        >
            <div>
                <span>Available earnings</span
                ><strong>{{ money(selectedBalance.available_cents) }}</strong
                ><small>Ready to include in a statement</small>
            </div>
            <div>
                <span>COD awaiting handover</span
                ><strong>{{ money(selectedBalance.cod_owed_cents) }}</strong
                ><small>Cash still held by the courier</small>
            </div>
            <div>
                <span>Pending earnings</span
                ><strong>{{ money(selectedBalance.pending_cents || 0) }}</strong
                ><small>Awaiting task settlement</small>
            </div>
        </div>
        <div v-if="companyMode && tab !== 'settings'" class="view-heading">
            <div>
                <h2>
                    {{
                        {
                            couriers: 'Your couriers',
                            ledger: 'Earnings activity',
                            payouts: 'Statements & payouts',
                        }[tab]
                    }}
                </h2>
                <p>
                    {{
                        {
                            couriers:
                                'Choose a courier to manage adjustments, cash handovers, and payouts.',
                            ledger: 'A record of completed tasks, bonuses, tips, and deductions.',
                            payouts:
                                'Review each statement before approving and recording payment.',
                        }[tab]
                    }}
                </p>
            </div>
            <span v-if="!loading" class="view-count"
                >{{ rows.length }} on this page</span
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
            <div class="settings-heading">
                <span class="heading-symbol"
                    ><EarningsIcon name="settings"
                /></span>
                <div>
                    <h2>Earnings policy</h2>
                    <small>Company settings</small>
                </div>
            </div>
            <p class="section-hint">
                Changes apply to new tasks. Existing earnings keep their
                original calculation.
            </p>
            <div class="settings-grid">
                <section>
                    <h3><EarningsIcon name="couriers" /> Courier pay</h3>
                    <small
                        >Set the share of company shipping earnings paid to
                        couriers.</small
                    >
                    <label
                        >Courier share (%)<input
                            v-model="settings.rate"
                            inputmode="decimal"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            :disabled="!canManage"
                            required
                    /></label>
                    <div class="share-preview">
                        <span
                            >For every &#8369;100 your company earns from
                            shipping</span
                        ><strong>{{
                            money(Math.round(Number(settings.rate || 0) * 100))
                        }}</strong
                        ><small>goes to the courier task pool</small>
                    </div>
                    <fieldset>
                        <legend>Task weights</legend>
                        <small
                            >Total:
                            <strong
                                :class="{
                                    'invalid-total': weightTotal !== 100,
                                }"
                                >{{ weightTotal }}%</strong
                            >
                            of 100%.</small
                        >
                        <div class="weight-fields">
                            <label
                                >Pickup (%)<input
                                    v-model.number="settings.pickup_weight"
                                    type="number"
                                    min="0"
                                    max="100"
                                    :disabled="!canManage"
                                    required /></label
                            ><label
                                >Hub transfer (%)<input
                                    v-model.number="settings.transfer_weight"
                                    type="number"
                                    min="0"
                                    max="100"
                                    :disabled="!canManage"
                                    required /></label
                            ><label
                                >Delivery (%)<input
                                    v-model.number="settings.delivery_weight"
                                    type="number"
                                    min="0"
                                    max="100"
                                    :disabled="!canManage"
                                    required
                            /></label>
                        </div>
                        <small
                            >Only tasks performed in your company’s leg count.
                            Their weights scale to 100%.</small
                        >
                    </fieldset>
                </section>
                <section>
                    <h3><EarningsIcon name="remittance" /> Cash management</h3>
                    <small
                        >Control cash handover deadlines and early payout
                        eligibility.</small
                    >
                    <label
                        >COD overdue after (days)<input
                            v-model.number="settings.cod_overdue_days"
                            type="number"
                            min="1"
                            max="365"
                            :disabled="!canManage"
                            required
                        /><small
                            >Overdue COD must be handed over before
                            payout.</small
                        ></label
                    >
                    <label class="toggle-label"
                        ><input
                            v-model="earlyCashoutEnabled"
                            type="checkbox"
                            :disabled="!canManage || busy"
                        />Allow early cash-out</label
                    >
                    <label v-if="earlyCashoutEnabled"
                        >Early cash-out minimum (₱)<input
                            v-model="settings.minimum"
                            required
                            inputmode="decimal"
                            type="number"
                            min="0.01"
                            step="0.01"
                            :disabled="!canManage"
                            placeholder="500.00"
                        /><small
                            >Leave empty to disable early cash-out.</small
                        ></label
                    >
                </section>
            </div>
            <label v-if="canManage"
                >Reason for change<textarea
                    v-model="settings.reason"
                    required
                    maxlength="1000"
                ></textarea></label
            ><button
                v-if="canManage"
                class="primary"
                :disabled="busy || weightTotal !== 100"
            >
                {{ busy ? 'Saving...' : 'Save settings' }}
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
                                <div class="courier-cell">
                                    <span
                                        v-if="companyMode"
                                        class="courier-avatar"
                                        aria-hidden="true"
                                        >{{
                                            (row.first_name?.[0] || '') +
                                            (row.last_name?.[0] || '')
                                        }}</span
                                    ><strong
                                        >{{ row.first_name }}
                                        {{ row.last_name }}</strong
                                    >
                                </div>
                            </td>
                            <td>{{ money(row.pending_cents) }}</td>
                            <td class="amount available-amount">
                                {{ money(row.available_cents) }}
                            </td>
                            <td class="amount">
                                {{ money(row.cod_owed_cents)
                                }}<small v-if="row.cod_owed_cents > 0"
                                    >Awaiting handover</small
                                >
                            </td>
                            <td>
                                <button
                                    class="quiet row-action"
                                    @click="inspectCourier(row)"
                                >
                                    Manage <EarningsIcon name="chevron" />
                                </button></td></template
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
                :class="{ 'logistics-dialog': companyMode }"
                @cancel.prevent="!busy && (dialog = '')"
            >
                <section
                    class="earnings-dialog"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="earnings-form-title"
                >
                    <div v-if="companyMode" class="modal-topline">
                        <span class="heading-symbol"
                            ><EarningsIcon :name="dialog" /></span
                        ><button
                            class="icon-button"
                            type="button"
                            :disabled="busy"
                            aria-label="Close dialog"
                            @click="
                                dialog = '';
                                error = '';
                            "
                        >
                            <EarningsIcon name="close" />
                        </button>
                    </div>
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
                    <p v-if="dialog !== 'pay'" class="dialog-intro">
                        <template v-if="dialog === 'adjustment'"
                            >Add a bonus, deduction, or tip for this
                            courier.</template
                        >
                        <template v-else-if="dialog === 'remittance'"
                            >Confirm the cash your company physically received.
                            This reduces COD owed here and in the courier
                            app.</template
                        >
                        <template v-else
                            >Prepare a seven-day statement from available
                            earnings and carried balances.</template
                        >
                    </p>
                    <div v-if="dialog !== 'pay'" class="courier-context">
                        <span>Courier</span><strong>{{ courierName }}</strong>
                        <small v-if="dialog === 'remittance'"
                            >Currently owed:
                            {{ money(selectedBalance.cod_owed_cents) }}</small
                        >
                        <small v-if="dialog === 'statement'"
                            >Available now:
                            {{ money(selectedBalance.available_cents) }} · COD
                            owed:
                            {{ money(selectedBalance.cod_owed_cents) }}</small
                        >
                    </div>
                    <p v-if="error" class="message error" role="alert">
                        {{ error }}
                    </p>
                    <form @submit.prevent="submit">
                        <template v-if="dialog === 'adjustment'">
                            <div class="parcel-field">
                                <label
                                    >Parcel
                                    <span class="optional"
                                        >Optional</span
                                    ></label
                                >
                                <button
                                    type="button"
                                    class="parcel-trigger quiet"
                                    @click="
                                        parcelPickerOpen = !parcelPickerOpen;
                                        parcelPickerOpen && loadParcels();
                                    "
                                >
                                    <span>{{
                                        chosenParcel?.order?.order_number ||
                                        'Select a parcel'
                                    }}</span
                                    ><EarningsIcon name="parcel" />
                                </button>
                                <small v-if="chosenParcel"
                                    >{{ chosenParcel.order?.tracking_number }}
                                    <button
                                        type="button"
                                        class="text-action"
                                        @click="chooseParcel(null)"
                                    >
                                        Remove
                                    </button></small
                                >
                                <small v-else
                                    >Leave empty for a company-wide bonus or
                                    deduction.</small
                                >
                                <div
                                    v-if="parcelPickerOpen"
                                    class="parcel-picker"
                                >
                                    <small v-if="parcelSearching" role="status"
                                        >Loading parcels...</small
                                    >
                                    <div
                                        v-else-if="parcelSearchError"
                                        role="alert"
                                    >
                                        {{ parcelSearchError }}
                                        <button
                                            type="button"
                                            class="quiet"
                                            @click="loadParcels(parcelPage)"
                                        >
                                            Retry
                                        </button>
                                    </div>
                                    <template v-else>
                                        <div class="parcel-results">
                                            <button
                                                v-for="item in parcelResults"
                                                :key="item.id"
                                                type="button"
                                                @click="chooseParcel(item)"
                                            >
                                                <strong>{{
                                                    item.order?.order_number
                                                }}</strong
                                                ><small>{{
                                                    item.order
                                                        ?.tracking_number ||
                                                    'No tracking number'
                                                }}</small>
                                            </button>
                                        </div>
                                        <small v-if="!parcelResults.length"
                                            >No parcels available for this
                                            courier.</small
                                        >
                                        <div class="picker-pagination">
                                            <button
                                                type="button"
                                                class="quiet"
                                                :disabled="parcelPage === 1"
                                                @click="
                                                    loadParcels(parcelPage - 1)
                                                "
                                            >
                                                Previous</button
                                            ><small>Page {{ parcelPage }}</small
                                            ><button
                                                type="button"
                                                class="quiet"
                                                :disabled="!parcelHasMore"
                                                @click="
                                                    loadParcels(parcelPage + 1)
                                                "
                                            >
                                                Next
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                            <label
                                >Type<select v-model="form.type">
                                    <option value="bonus">Bonus</option>
                                    <option value="deduction">Deduction</option>
                                    <option value="tip">
                                        Tip · 100% to courier
                                    </option>
                                </select></label
                            >
                        </template>
                        <label
                            v-if="['adjustment', 'remittance'].includes(dialog)"
                            class="amount-field"
                            >Amount (₱)<input
                                :value="form.amount"
                                @input="numericInput($event, form, 'amount')"
                                @keydown="numericKey"
                                type="text"
                                pattern="[0-9]+([.][0-9]{1,2})?"
                                inputmode="decimal"
                                min="0.01"
                                step="0.01"
                                :max="
                                    dialog === 'remittance'
                                        ? decimal(
                                              selectedBalance.cod_owed_cents ||
                                                  0,
                                          )
                                        : undefined
                                "
                                required
                        /></label>
                        <label v-if="dialog === 'adjustment'"
                            >Reason<textarea
                                v-model="form.reason"
                                required
                                maxlength="1000"
                                placeholder="Why is this adjustment needed?"
                            ></textarea>
                        </label>
                        <template v-if="dialog === 'statement'">
                            <div class="statement-steps">
                                <span class="active">1. Prepare</span
                                ><span>2. Review & approve</span
                                ><span>3. Pay</span>
                            </div>
                            <div class="date-fields">
                                <label
                                    >Week starts<input
                                        v-model="form.period_start"
                                        readonly
                                        type="date"
                                        :max="form.period_end"
                                        required
                                /></label>
                                <label
                                    >Week ends<input
                                        v-model="form.period_end"
                                        @change="updateWeek"
                                        type="date"
                                        :max="todayManila()"
                                        required
                                /></label>
                            </div>
                            <small
                                :class="{ 'field-error': !statementDatesValid }"
                                >Select the week ending date; the seven-day
                                period is filled automatically. Available
                                earnings through the end date and carried
                                deductions are included.</small
                            >
                        </template>
                        <p
                            v-if="dialog === 'remittance'"
                            class="reference-note"
                        >
                            <span aria-hidden="true">#</span> A unique handover
                            reference is generated when you confirm receipt.
                        </p>
                        <label v-if="dialog === 'pay'" class="payment-reference"
                            >Reference number<input
                                v-model="form.reference"
                                required
                                maxlength="255"
                                autocomplete="off"
                                placeholder="Receipt or transaction reference"
                        /></label>
                        <div class="dialog-actions">
                            <button
                                class="primary"
                                :disabled="
                                    busy ||
                                    (dialog === 'statement' &&
                                        !statementDatesValid) ||
                                    (dialog === 'remittance' &&
                                        !selectedBalance.cod_owed_cents)
                                "
                            >
                                {{
                                    busy
                                        ? 'Saving…'
                                        : {
                                              adjustment: 'Save adjustment',
                                              remittance:
                                                  'Confirm cash received',
                                              statement: 'Prepare statement',
                                              pay: 'Mark paid',
                                          }[dialog]
                                }}
                            </button>
                            <button
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
.courier-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    padding: 16px 18px;
    margin-bottom: 18px;
    background: #eaf6f3;
    border-radius: 10px;
}
.courier-toolbar-copy,
.courier-toolbar-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.courier-toolbar-copy strong {
    font-size: 17px;
}
.courier-toolbar-copy span {
    font-size: 13px;
    font-variant-numeric: tabular-nums;
}
.courier-toolbar-actions {
    gap: 8px;
}
.section-hint,
.dialog-intro {
    font-size: 12px;
    margin: 0 0 16px;
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
    max-width: 100%;
    background: white;
    border: 1px solid #d5e4e3;
    border-radius: 12px;
    padding: 24px;
}
.settings-form h3 {
    font-size: 14px;
    margin: 24px 0 8px;
    padding-top: 18px;
    border-top: 1px solid #e7efee;
}
.invalid-total,
.field-error {
    color: #a33333;
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
.earnings-dialog h2 {
    margin: 0 0 6px;
}
.courier-context {
    display: grid;
    gap: 2px;
    padding: 12px 14px;
    background: #f0f7f5;
    border-radius: 8px;
    font-size: 12px;
}
.courier-context strong {
    font-size: 15px;
}
.parcel-field {
    margin-top: 18px;
}
.parcel-field > small {
    margin: 4px 0 8px;
}
.parcel-results {
    max-height: 190px;
    overflow-y: auto;
    border: 1px solid #c6d9d6;
    border-radius: 7px;
    margin-top: 6px;
}
.parcel-results button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    width: 100%;
    text-align: left;
    border: 0;
    border-radius: 0;
    background: white;
    border-bottom: 1px solid #e7efee;
}
.parcel-results button:hover {
    background: #eaf6f3;
}
.parcel-results button:last-child {
    border-bottom: 0;
}
.parcel-selection {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid #a8ccc5;
    border-radius: 7px;
    margin-top: 8px;
}
.parcel-selection span {
    display: grid;
}
.date-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.dialog-actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
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
    .courier-toolbar-copy {
        align-items: start;
        flex-direction: column;
        gap: 2px;
    }
    .courier-toolbar-actions,
    .courier-toolbar-actions button {
        width: 100%;
    }
    .date-fields {
        grid-template-columns: 1fr;
        gap: 0;
    }
    th,
    td {
        padding: 12px;
    }
    .statement {
        padding: 16px;
    }
}
.settings-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 36px;
}
.settings-grid input {
    max-width: 180px;
}
.settings-form > label {
    max-width: 560px;
}
.amount-field {
    max-width: 210px;
}
.amount-field input {
    font-variant-numeric: tabular-nums;
    font-size: 18px;
    font-weight: 650;
}
.parcel-trigger {
    display: flex;
    justify-content: space-between;
    width: 100%;
    margin: 8px 0;
    text-align: left;
}
.optional {
    font-weight: 400;
    color: #607578;
    font-size: 12px;
}
.parcel-picker {
    padding: 12px;
    background: #f5f9f8;
    border-radius: 8px;
    margin-top: 10px;
}
.picker-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
}
.text-action {
    border: 0;
    background: none;
    color: #0b6963;
    padding: 0 8px;
    min-height: 32px;
    font-size: 12px;
}
.reference-note {
    background: #f4f8f7;
    border-radius: 8px;
    padding: 12px;
    font-size: 12px;
}
.reference-note span {
    font-size: 18px;
    margin-right: 8px;
}
.statement-steps {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    font-size: 11px;
    color: #607578;
    margin: 20px 0 6px;
}
.statement-steps .active {
    color: #0b6963;
    font-weight: 700;
}
.earnings-overlay {
    width: min(540px, calc(100vw - 32px));
}
.earnings-dialog {
    width: 100%;
}
.dialog-actions {
    border-top: 1px solid #e7efee;
    padding-top: 18px;
    justify-content: flex-end;
}
button {
    transition:
        background-color 150ms,
        border-color 150ms;
}
.quiet:hover:not(:disabled) {
    background: #f0f7f5;
    border-color: #a8ccc5;
}
.primary:hover:not(:disabled) {
    background: #085750;
}
@media (max-width: 640px) {
    .settings-grid {
        grid-template-columns: 1fr;
        gap: 4px;
    }
    .settings-form {
        padding: 18px;
    }
}
.toggle-label {
    flex-direction: row;
    align-items: center;
    gap: 10px;
}
.toggle-label input {
    width: 18px;
    min-height: 18px;
    accent-color: #0b6963;
}
/* Logistics finance workspace */
.logistics-earnings {
    --finance-ink: #203632;
    --finance-muted: #71817b;
    --finance-line: #e5ebe7;
    --finance-accent: #146653;
    max-width: 1240px;
    padding: 12px 8px 32px;
    color: var(--finance-ink);
}
.logistics-earnings .earnings-heading {
    align-items: center;
    margin-bottom: 30px;
}
.logistics-earnings h1 {
    font-size: 32px;
    font-weight: 650;
    letter-spacing: -1.2px;
    margin: 0 0 9px;
}
.logistics-earnings .earnings-heading p {
    font-size: 13px;
    margin: 0;
    max-width: 65ch;
}
.logistics-earnings button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-weight: 600;
}
.logistics-earnings button svg {
    flex-shrink: 0;
    width: 17px;
    height: 17px;
}
.logistics-earnings .earnings-heading > button {
    background: transparent;
    border-color: var(--finance-line);
}
.logistics-earnings .earnings-tabs {
    display: flex;
    width: fit-content;
    max-width: 100%;
    padding: 5px;
    gap: 4px;
    background: #eaf0ed;
    border: 0;
    border-radius: 12px;
    margin-bottom: 30px;
}
.logistics-earnings .earnings-tabs button {
    border: 0;
    border-radius: 8px;
    padding: 10px 16px;
    min-height: 40px;
    color: #697c72;
    font-size: 12px;
}
.logistics-earnings .earnings-tabs button.active {
    background: white;
    color: #175944;
    box-shadow: 0 1px 3px #203c3012;
}
.logistics-earnings .view-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin: 24px 0 18px;
}
.logistics-earnings .view-heading h2 {
    font-size: 19px;
    font-weight: 650;
    letter-spacing: -0.4px;
    margin: 0 0 5px;
}
.logistics-earnings .view-heading p {
    font-size: 12px;
    margin: 0;
}
.view-count {
    font-size: 11px;
    color: #71817b;
    white-space: nowrap;
}
.logistics-earnings .table-scroll {
    border: 1px solid var(--finance-line);
    border-radius: 14px;
    background: white;
}
.logistics-earnings table {
    font-size: 13px;
}
.logistics-earnings th {
    background: #f7f9f8;
    padding: 15px 22px;
    font-size: 11px;
    color: #75867d;
    border-color: var(--finance-line);
}
.logistics-earnings td {
    padding: 22px;
    border-color: #eef2ef;
}
.logistics-earnings tbody tr {
    transition: background-color 140ms;
}
.logistics-earnings tbody tr:hover {
    background: #fafcfb;
}
.logistics-earnings td:not(:first-child) {
    font-variant-numeric: tabular-nums;
}
.logistics-earnings td strong {
    font-weight: 600;
}
.logistics-earnings td small {
    font-size: 10px;
    margin-top: 4px;
}
.logistics-earnings .available-amount {
    color: #19644e;
}
.logistics-earnings .row-action {
    padding: 7px 10px;
    min-height: 36px;
    border-color: transparent;
    background: transparent;
    font-size: 12px;
}
.courier-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.courier-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 12px;
    background: #edf3ef;
    color: #48725e;
    font-size: 12px;
    font-weight: 650;
}
.logistics-earnings .courier-toolbar {
    background: white;
    border: 1px solid var(--finance-line);
    border-radius: 14px 14px 0 0;
    margin: 0;
    padding: 20px 24px;
}
.logistics-earnings .courier-toolbar-copy {
    display: grid;
    grid-template-columns: 42px auto;
    column-gap: 12px;
    row-gap: 2px;
}
.logistics-earnings .courier-toolbar-copy .courier-avatar {
    grid-column: 1;
    grid-row: 1 / 3;
}
.logistics-earnings .courier-toolbar-copy > small {
    grid-column: 2;
    font-size: 10px;
}
.logistics-earnings .courier-toolbar-copy > strong {
    grid-column: 2;
    font-size: 17px;
    letter-spacing: -0.3px;
}
.logistics-earnings .courier-toolbar-copy > span:not(.courier-avatar) {
    display: none;
}
.logistics-earnings .courier-toolbar-actions {
    gap: 8px;
}
.logistics-earnings .courier-toolbar-actions button {
    font-size: 11px;
    min-height: 38px;
    padding: 8px 12px;
}
.logistics-earnings .courier-toolbar-actions > button:first-child {
    border-color: transparent;
    color: #758279;
}
.courier-figures {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    background: white;
    border: 1px solid #e5ebe7;
    border-top: 0;
    border-radius: 0 0 14px 14px;
    margin-bottom: 30px;
    padding: 24px 0;
}
.courier-figures > div {
    padding: 0 26px;
    border-right: 1px solid #e8eeea;
}
.courier-figures > div:last-child {
    border: 0;
}
.courier-figures span {
    color: #748378;
    font-size: 11px;
}
.courier-figures strong {
    display: block;
    font-size: 29px;
    font-weight: 600;
    letter-spacing: -1px;
    font-variant-numeric: tabular-nums;
    margin: 5px 0;
}
.courier-figures > div:first-child strong {
    color: #146653;
}
.courier-figures small {
    font-size: 10px;
}
.logistics-earnings .toolbar {
    justify-content: space-between;
    margin: 20px 0;
}
.logistics-earnings .toolbar label {
    flex-direction: row;
    align-items: center;
    gap: 12px;
    color: #748378;
    font-size: 11px;
}
.logistics-earnings .toolbar select {
    min-height: 36px;
    font-size: 12px;
    width: 160px;
    background: white;
}
.logistics-earnings .badge {
    font-size: 10px;
    padding: 5px 9px;
    border-radius: 5px;
    text-transform: capitalize;
}
.logistics-earnings .settings-form {
    padding: 30px;
    border-color: var(--finance-line);
    border-radius: 16px;
}
.settings-heading {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-bottom: 14px;
}
.heading-symbol {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 13px;
    background: #eaf3ee;
    color: #146653;
}
.settings-heading h2 {
    margin: 0;
    font-size: 21px;
    font-weight: 650;
    letter-spacing: -0.6px;
}
.settings-heading small {
    font-size: 11px;
}
.logistics-earnings .settings-grid {
    gap: 0;
    grid-template-columns: 1.15fr 1fr;
    margin: 28px 0;
    border-top: 1px solid var(--finance-line);
    border-bottom: 1px solid var(--finance-line);
}
.logistics-earnings .settings-grid > section {
    padding: 24px 30px 24px 0;
}
.logistics-earnings .settings-grid > section + section {
    border-left: 1px solid var(--finance-line);
    padding: 24px 0 24px 30px;
}
.logistics-earnings .settings-form h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0 0 8px;
    border: 0;
    padding: 0;
    font-size: 14px;
}
.logistics-earnings .settings-form h3 svg {
    width: 17px;
    height: 17px;
    color: #718d7d;
}
.logistics-earnings .settings-form input {
    min-height: 42px;
    background: #fafcfb;
    border-color: #dce6df;
    border-radius: 8px;
}
.logistics-earnings .settings-form label {
    font-size: 12px;
}
.logistics-earnings .settings-form fieldset {
    border: 0;
    border-top: 1px solid #e5ebe7;
    border-radius: 0;
    padding: 18px 0 0;
    margin-top: 24px;
}
.logistics-earnings .settings-form legend {
    padding: 0 10px 0 0;
    font-size: 12px;
    font-weight: 600;
}
.logistics-earnings .settings-form small {
    font-size: 11px;
    font-weight: 400;
}
.share-preview {
    padding: 16px 18px;
    border-radius: 10px;
    background: #f1f6f3;
    margin: 18px 0;
}
.share-preview span {
    font-size: 11px;
    color: #748378;
}
.share-preview strong {
    display: block;
    color: #146653;
    font-size: 27px;
    font-weight: 600;
    letter-spacing: -0.8px;
    margin: 4px 0;
}
.logistics-earnings .settings-form > label textarea {
    min-height: 68px;
    font-size: 12px;
}
.logistics-earnings .settings-form > .primary {
    margin-top: 8px;
}
.logistics-dialog {
    width: min(520px, calc(100vw - 32px));
    max-height: 92dvh;
    border-radius: 20px;
    box-shadow: 0 30px 90px #0b2b2833;
}
.logistics-dialog::backdrop {
    background: #15352e66;
    backdrop-filter: blur(5px);
}
.logistics-dialog .earnings-dialog {
    padding: 28px;
    max-height: 92dvh;
    border-radius: 20px;
}
.modal-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}
.icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    min-height: 34px;
    padding: 6px;
    background: transparent;
    border: 0;
    color: #829087;
}
.logistics-dialog h2 {
    font-size: 24px;
    letter-spacing: -0.8px;
    font-weight: 650;
}
.logistics-dialog .dialog-intro {
    font-size: 12px;
    line-height: 1.7;
    margin: 7px 0 20px;
    max-width: 55ch;
}
.logistics-dialog .courier-context {
    background: #f6f8f7;
    border: 1px solid #e8eeea;
    border-radius: 10px;
    padding: 13px 16px;
    gap: 3px;
}
.logistics-dialog .courier-context > span {
    font-size: 10px;
    color: #748378;
}
.logistics-dialog .courier-context strong {
    font-size: 14px;
    font-weight: 600;
}
.logistics-dialog label {
    font-size: 12px;
    font-weight: 550;
}
.logistics-dialog input,
.logistics-dialog select,
.logistics-dialog textarea {
    border-color: #dce5df;
    background: #fcfdfc;
    border-radius: 8px;
    font-size: 13px;
}
.logistics-dialog .amount-field {
    max-width: 200px;
}
.logistics-dialog .amount-field input {
    font-size: 25px;
    font-weight: 550;
    letter-spacing: -0.7px;
    padding: 10px 14px;
    height: 52px;
}
.logistics-dialog .parcel-trigger {
    min-height: 46px;
    border-color: #dce5df;
    font-weight: 500;
    font-size: 13px;
}
.logistics-dialog .parcel-field > label {
    display: flex;
    flex-direction: row;
    gap: 6px;
    margin-bottom: 0;
}
.logistics-dialog .parcel-field small {
    font-size: 11px;
}
.logistics-dialog .parcel-picker {
    border: 1px solid #dce5df;
    background: #f8faf9;
    padding: 8px;
}
.logistics-dialog .parcel-results {
    margin: 0;
    border: 0;
}
.logistics-dialog .parcel-results button {
    font-size: 12px;
    padding: 12px;
}
.logistics-dialog .statement-steps {
    border-bottom: 1px solid #e7ede9;
    padding: 0 0 16px;
    gap: 18px;
    margin-top: 24px;
}
.logistics-dialog .date-fields {
    gap: 16px;
}
.logistics-dialog input[readonly] {
    background: #f3f6f4;
    color: #748378;
    border-color: transparent;
}
.logistics-dialog .reference-note {
    font-size: 11px;
    background: #f6f8f7;
    color: #748378;
    line-height: 1.7;
}
.logistics-dialog .dialog-actions {
    flex-direction: row-reverse;
    justify-content: flex-start;
    gap: 10px;
    margin-top: 26px;
    padding-top: 20px;
}
.logistics-dialog .dialog-actions button {
    font-size: 12px;
    padding: 10px 16px;
}
.logistics-dialog .dialog-actions .quiet {
    border-color: transparent;
}
@media (max-width: 800px) {
    .logistics-earnings .courier-toolbar {
        padding: 18px;
    }
    .logistics-earnings .courier-toolbar-actions {
        width: 100%;
    }
    .courier-figures > div {
        padding: 0 16px;
    }
    .courier-figures strong {
        font-size: 23px;
    }
    .logistics-earnings .settings-grid {
        grid-template-columns: 1fr;
    }
    .logistics-earnings .settings-grid > section {
        padding: 22px 0;
    }
    .logistics-earnings .settings-grid > section + section {
        padding: 22px 0;
        border-left: 0;
        border-top: 1px solid #e5ebe7;
    }
}
@media (max-width: 540px) {
    .logistics-earnings {
        padding: 4px 0 24px;
    }
    .logistics-earnings h1 {
        font-size: 26px;
    }
    .logistics-earnings .earnings-heading {
        align-items: flex-start;
        gap: 12px;
    }
    .logistics-earnings .earnings-heading > button {
        font-size: 0;
        padding: 10px;
    }
    .logistics-earnings .earnings-tabs {
        width: 100%;
    }
    .logistics-earnings .earnings-tabs button {
        padding: 9px 12px;
    }
    .logistics-earnings .earnings-tabs svg {
        display: none;
    }
    .logistics-earnings .courier-toolbar-actions button {
        width: auto;
        flex: 1 1 auto;
    }
    .courier-figures {
        grid-template-columns: 1fr;
        padding: 0 18px;
    }
    .courier-figures > div {
        border-right: 0;
        border-bottom: 1px solid #edf1ee;
        padding: 15px 0;
    }
    .courier-figures strong {
        font-size: 26px;
    }
    .view-count {
        display: none;
    }
    .logistics-earnings .settings-form {
        padding: 20px;
    }
    .logistics-dialog .earnings-dialog {
        padding: 22px;
    }
    .logistics-dialog .statement-steps {
        gap: 10px;
        font-size: 10px;
    }
}
</style>

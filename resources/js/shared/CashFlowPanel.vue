<!--
    Mock-escrow cash flow for the signed-in party (seller, logistics company
    or platform). Self-contained styles so it drops into any portal.
    Backed by App\Http\Controllers\CashFlowController.
-->
<template>
    <section class="cf-card" aria-labelledby="cf-title">
        <header class="cf-head">
            <div>
                <h3 id="cf-title" class="cf-title">{{ title }}</h3>
                <p class="cf-sub">{{ description }}</p>
            </div>
            <button type="button" class="cf-icon-btn" :disabled="loading" aria-label="Refresh cash flow" @click="load">
                <svg :class="{ 'cf-spin': loading }" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8" /><path d="M21 3v5h-5" />
                </svg>
            </button>
        </header>

        <div v-if="error" class="cf-error">
            <span>{{ error }}</span>
            <button type="button" class="cf-link" @click="load">Retry</button>
        </div>

        <template v-else>
            <div class="cf-stats">
                <div v-for="stat in stats" :key="stat.label" class="cf-stat">
                    <span class="cf-stat-label">{{ stat.label }}</span>
                    <span v-if="loading && !data" class="cf-skel cf-skel-num" />
                    <strong v-else class="cf-stat-value" :class="stat.tone">{{ stat.value }}</strong>
                </div>
            </div>

            <div class="cf-list-head">Recent movements</div>

            <ul v-if="loading && !data" class="cf-list">
                <li v-for="n in 3" :key="n" class="cf-row"><span class="cf-skel cf-skel-line" /></li>
            </ul>

            <p v-else-if="!data?.entries?.length" class="cf-empty">
                No payouts yet. Funds appear here once a buyer confirms receipt (or 7 days after delivery).
            </p>

            <ul v-else class="cf-list">
                <li v-for="entry in data.entries" :key="entry.id" class="cf-row">
                    <span class="cf-dot" :class="entry.direction === 'in' ? 'cf-in' : 'cf-out'" aria-hidden="true">
                        {{ entry.direction === 'in' ? '↓' : '↑' }}
                    </span>
                    <div class="cf-row-main">
                        <strong>{{ entry.type === 'release' ? 'Escrow released' : 'Refund clawback' }}</strong>
                        <span class="cf-muted">#{{ entry.order_number }} · {{ legLabel(entry.account) }} · {{ formatDate(entry.created_at) }}</span>
                    </div>
                    <strong :class="entry.direction === 'in' ? 'cf-pos' : 'cf-neg'">
                        {{ entry.direction === 'in' ? '+' : '−' }}{{ peso(entry.amount) }}
                    </strong>
                </li>
            </ul>
        </template>
    </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { apiRequest } from './accountApi';
import { createClient } from './backendClient';

const props = defineProps({
    endpoint: { type: String, required: true },
    title: { type: String, default: 'Cash flow' },
    description: { type: String, default: 'Payouts released from escrow and refund clawbacks.' }
});

const data = ref(null);
const loading = ref(false);
const error = ref('');

const LEG_LABELS = {
    seller: 'Goods',
    origin_logistics: 'Origin leg',
    leg_logistics: 'Linehaul leg',
    last_mile_logistics: 'Last-mile leg',
    platform: 'Commission'
};

const pesoFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

function peso(value) {
    return pesoFormat.format(Number(value || 0));
}

function legLabel(account) {
    return LEG_LABELS[account] || account;
}

function formatDate(value) {
    return value ? new Date(value.replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) : '';
}

const stats = computed(() => {
    const d = data.value;
    const net = Number(d?.net || 0);

    return [
        { label: 'Released to you', value: peso(d?.released), tone: '' },
        { label: 'Refund clawbacks', value: peso(d?.clawed_back), tone: Number(d?.clawed_back) > 0 ? 'cf-neg' : '' },
        { label: 'Net balance', value: peso(net), tone: net < 0 ? 'cf-neg' : 'cf-pos' },
        { label: 'Orders', value: String(d?.orders_count ?? 0), tone: '' }
    ];
});

async function load() {
    loading.value = true;
    error.value = '';

    const { data: payload, error: err } = await apiRequest(createClient(), props.endpoint);

    if (err) {
        error.value = err.message || 'Could not load cash flow.';
    } else {
        data.value = payload;
    }

    loading.value = false;
}

onMounted(load);
</script>

<style scoped>
.cf-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
.cf-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
.cf-title { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
.cf-sub { font-size: 13px; color: #64748b; margin: 2px 0 0; }
.cf-icon-btn { width: 36px; height: 36px; display: grid; place-items: center; border-radius: 10px; border: 1px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; flex-shrink: 0; }
.cf-icon-btn:hover:not(:disabled) { background: #f8fafc; }
.cf-icon-btn:disabled { opacity: .6; cursor: wait; }
.cf-spin { animation: cf-spin 1s linear infinite; }
@keyframes cf-spin { to { transform: rotate(360deg); } }
.cf-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
@media (max-width: 720px) { .cf-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.cf-stat { background: #f8fafc; border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.cf-stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
.cf-stat-value { font-size: 20px; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }
.cf-pos { color: #0d9488; }
.cf-neg { color: #dc2626; }
.cf-list-head { font-size: 12px; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: .04em; }
.cf-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
.cf-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid #f1f5f9; font-size: 14px; }
.cf-row:first-child { border-top: 0; }
.cf-row-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.cf-row-main strong { color: #0f172a; font-weight: 600; }
.cf-muted { font-size: 12px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cf-dot { width: 30px; height: 30px; border-radius: 999px; display: grid; place-items: center; font-weight: 700; flex-shrink: 0; }
.cf-in { background: #ccfbf1; color: #0f766e; }
.cf-out { background: #fee2e2; color: #b91c1c; }
.cf-empty { font-size: 13px; color: #64748b; margin: 0; }
.cf-error { display: flex; justify-content: space-between; gap: 12px; padding: 12px 14px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
.cf-link { background: none; border: 0; color: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
.cf-skel { display: block; background: linear-gradient(90deg, #f1f5f9, #e2e8f0, #f1f5f9); background-size: 200% 100%; animation: cf-shimmer 1.2s infinite; border-radius: 6px; }
.cf-skel-num { height: 24px; width: 70%; }
.cf-skel-line { height: 30px; width: 100%; }
@keyframes cf-shimmer { to { background-position: -200% 0; } }
</style>

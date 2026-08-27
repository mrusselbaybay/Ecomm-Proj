// resources/js/seller/composables/useReports.js
//
// ---------------------------------------------------------------
// Backed by the Laravel Seller Report API (routes/seller.php +
// App\Http\Controllers\Seller\SellerReportController) — real,
// seller-scoped aggregate data. See that controller's docblock for
// the metric formulas and eligible-order-status definitions; this
// file does no calculation of its own, only fetching/state.
//
// Every request is authenticated by forwarding the current Supabase
// access token as a Bearer header, same as every other seller
// composable (useOrders.js, useFeedback.js, useMessaging.js).
// ---------------------------------------------------------------

import { ref, computed } from 'vue';
import { getSupabase } from './useSeller';

function todayStr(d = new Date()) {
    return d.toISOString().slice(0, 10);
}
function daysAgoStr(n, from = new Date()) {
    const d = new Date(from);
    d.setDate(d.getDate() - n);
    return todayStr(d);
}
function startOfMonthStr(d = new Date()) {
    return todayStr(new Date(d.getFullYear(), d.getMonth(), 1));
}
function lastMonthRange(d = new Date()) {
    const start = new Date(d.getFullYear(), d.getMonth() - 1, 1);
    const end = new Date(d.getFullYear(), d.getMonth(), 0);
    return { from: todayStr(start), to: todayStr(end) };
}

// Every option here must produce a { from, to } the backend will accept
// as-is (Y-m-d, from <= to) — see SellerReportController::resolveRange().
const PRESETS = {
    today: () => ({ from: todayStr(), to: todayStr() }),
    last7: () => ({ from: daysAgoStr(6), to: todayStr() }),
    last30: () => ({ from: daysAgoStr(29), to: todayStr() }),
    thisMonth: () => ({ from: startOfMonthStr(), to: todayStr() }),
    lastMonth: () => lastMonthRange(),
};

const preset = ref('last30');
const customFrom = ref('');
const customTo = ref('');
const range = ref(PRESETS.last30());

const summary = ref(null);
const isLoadingSummary = ref(false);
const summaryError = ref('');

const revenueTrend = ref(null);
const granularity = ref(null); // server-resolved; null until first load
const isLoadingRevenueTrend = ref(false);
const revenueTrendError = ref('');

const orderBreakdown = ref(null);
const isLoadingOrderBreakdown = ref(false);
const orderBreakdownError = ref('');

const topProducts = ref([]);
const topProductsSort = ref('revenue');
const isLoadingTopProducts = ref(false);
const topProductsError = ref('');

const isExporting = ref(false);
const exportError = ref('');

let controllers = { summary: null, revenueTrend: null, orderBreakdown: null, topProducts: null };

async function authHeaders() {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Not signed in.');
    }

    return {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

// Aborts any in-flight request of the same `key` before starting a new
// one, so rapidly changing the date range (e.g. dragging a custom-range
// picker) can't leave an older, slower response overwriting a newer one.
async function apiFetch(path, key) {
    controllers[key]?.abort();
    const controller = new AbortController();
    controllers[key] = controller;

    const headers = await authHeaders();
    let response;

    try {
        response = await fetch(`/api/seller${path}`, { headers, signal: controller.signal });
    } catch (err) {
        if (err.name === 'AbortError') return { aborted: true };
        throw err;
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const err = new Error(body.message || 'Request failed.');
        err.status = response.status;
        throw err;
    }

    return { data: body.data, meta: body.meta, aborted: false };
}

function rangeQuery(extra = '') {
    const params = new URLSearchParams({ from: range.value.from, to: range.value.to });
    return extra ? `${params.toString()}&${extra}` : params.toString();
}

async function loadSummary() {
    isLoadingSummary.value = true;
    summaryError.value = '';

    try {
        const res = await apiFetch(`/reports/summary?${rangeQuery()}`, 'summary');
        if (res.aborted) return;
        summary.value = res.data;
    } catch (err) {
        console.error('Error loading report summary:', err);
        summaryError.value = err?.message || 'Could not load your performance summary.';
    } finally {
        isLoadingSummary.value = false;
    }
}

async function loadRevenueTrend(requestedGranularity = null) {
    isLoadingRevenueTrend.value = true;
    revenueTrendError.value = '';

    try {
        const extra = requestedGranularity ? `granularity=${requestedGranularity}` : '';
        const res = await apiFetch(`/reports/revenue-trend?${rangeQuery(extra)}`, 'revenueTrend');
        if (res.aborted) return;
        revenueTrend.value = res.data;
        granularity.value = res.data.granularity;
    } catch (err) {
        console.error('Error loading revenue trend:', err);
        revenueTrendError.value = err?.message || 'Could not load the revenue chart.';
    } finally {
        isLoadingRevenueTrend.value = false;
    }
}

async function loadOrderBreakdown() {
    isLoadingOrderBreakdown.value = true;
    orderBreakdownError.value = '';

    try {
        const res = await apiFetch(`/reports/order-breakdown?${rangeQuery()}`, 'orderBreakdown');
        if (res.aborted) return;
        orderBreakdown.value = res.data;
    } catch (err) {
        console.error('Error loading order breakdown:', err);
        orderBreakdownError.value = err?.message || 'Could not load the order breakdown.';
    } finally {
        isLoadingOrderBreakdown.value = false;
    }
}

async function loadTopProducts(sort = null) {
    if (sort) topProductsSort.value = sort;
    isLoadingTopProducts.value = true;
    topProductsError.value = '';

    try {
        const res = await apiFetch(`/reports/top-products?${rangeQuery(`sort=${topProductsSort.value}`)}`, 'topProducts');
        if (res.aborted) return;
        topProducts.value = res.data;
    } catch (err) {
        console.error('Error loading top products:', err);
        topProductsError.value = err?.message || 'Could not load top products.';
    } finally {
        isLoadingTopProducts.value = false;
    }
}

// All four panels share one date range, so they're always refetched
// together — keeps the dashboard internally consistent (the chart, the
// KPI row, and the product table can never disagree about which period
// they're each describing).
function loadAll() {
    loadSummary();
    loadRevenueTrend();
    loadOrderBreakdown();
    loadTopProducts();
}

function applyPreset(name) {
    if (!PRESETS[name]) return;
    preset.value = name;
    range.value = PRESETS[name]();
    syncUrl();
    loadAll();
}

// Rejects an end date before the start date at the UI layer too (the
// backend already rejects it, but catching it here means the seller
// never fires a request that's guaranteed to 422).
function applyCustomRange(from, to) {
    if (!from || !to || to < from) {
        return false;
    }
    preset.value = 'custom';
    customFrom.value = from;
    customTo.value = to;
    range.value = { from, to };
    syncUrl();
    loadAll();
    return true;
}

function resetFilters() {
    applyPreset('last30');
}

// Keeps the date range in the URL (replaceState, not pushState — a
// filter change shouldn't pile up back-button entries) so a reload or
// a shared link reproduces the same view. Only touches the querystring;
// SellerLayout owns the path itself.
function syncUrl() {
    const url = new URL(window.location.href);
    url.searchParams.set('from', range.value.from);
    url.searchParams.set('to', range.value.to);
    url.searchParams.set('preset', preset.value);
    window.history.replaceState(window.history.state, '', url);
}

// Reads a date range back out of the URL on mount (see syncUrl above).
// Falls back to the last30 default when absent/invalid rather than
// erroring — an old or hand-edited link shouldn't break the page.
function initFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const from = params.get('from');
    const to = params.get('to');
    const urlPreset = params.get('preset');

    if (from && to && to >= from) {
        range.value = { from, to };
        preset.value = urlPreset === 'custom' ? 'custom' : (PRESETS[urlPreset] ? urlPreset : 'custom');
        if (preset.value === 'custom') {
            customFrom.value = from;
            customTo.value = to;
        }
    }
}

async function exportCsv() {
    if (isExporting.value) return;
    isExporting.value = true;
    exportError.value = '';

    try {
        const headers = await authHeaders();
        const response = await fetch(`/api/seller/reports/export?${rangeQuery()}`, { headers });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Export failed.');
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/);
        const filename = match ? match[1] : `nexmart-seller-report-${range.value.from}-to-${range.value.to}.csv`;

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Error exporting report:', err);
        exportError.value = err?.message || 'Export failed. Please try again.';
    } finally {
        isExporting.value = false;
    }
}

const rangeLabel = computed(() => {
    const { from, to } = range.value;
    if (!from || !to) return '';
    const fmt = (s) => new Date(`${s}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    return from === to ? fmt(from) : `${fmt(from)} – ${fmt(to)}`;
});

export function useReports() {
    return {
        preset,
        customFrom,
        customTo,
        range,
        rangeLabel,
        applyPreset,
        applyCustomRange,
        resetFilters,
        initFromUrl,

        summary,
        isLoadingSummary,
        summaryError,
        loadSummary,

        revenueTrend,
        granularity,
        isLoadingRevenueTrend,
        revenueTrendError,
        loadRevenueTrend,

        orderBreakdown,
        isLoadingOrderBreakdown,
        orderBreakdownError,
        loadOrderBreakdown,

        topProducts,
        topProductsSort,
        isLoadingTopProducts,
        topProductsError,
        loadTopProducts,

        loadAll,

        isExporting,
        exportError,
        exportCsv,
    };
}
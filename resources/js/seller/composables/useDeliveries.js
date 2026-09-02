// resources/js/seller/composables/useDeliveries.js
//
// ---------------------------------------------------------------
// Backed by the Laravel Seller Delivery API (routes/seller.php +
// App\Http\Controllers\Seller\SellerDeliveryController) — real,
// seller-scoped, server-side paginated data. See that controller's
// docblock for what's supported vs. deliberately not (no proof of
// delivery, buyer confirmation, or returns tables exist).
//
// Marking an order Delivered is NOT handled here — Delivery.vue calls
// useOrders().deliverOrder() directly (the existing endpoint), so
// there's exactly one place in the app that performs that transition.
// ---------------------------------------------------------------

import { ref, computed } from 'vue';
import { getSupabase } from './useSeller';

const deliveries = ref([]);
const deliveriesMeta = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 10,
    total: 0,
    statusCounts: { all: 0, inTransit: 0, delivered: 0, issues: 0 },
});
const isLoadingDeliveries = ref(false);
const deliveriesError = ref('');

const summary = ref(null);
const isLoadingSummary = ref(false);
const summaryError = ref('');

const isExporting = ref(false);
const exportError = ref('');

const filters = ref({
    search: '',
    status: 'all',
    sort: 'updated_desc',
    from: '',
    to: '',
    page: 1,
});

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

let listController = null;

async function apiFetch(path, { signal } = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api/seller${path}`, { headers, signal });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const err = new Error(body.message || 'Request failed.');
        err.status = response.status;
        throw err;
    }

    return body;
}

function buildQuery(extra = {}) {
    const f = filters.value;
    const params = new URLSearchParams();
    if (f.search) params.set('search', f.search);
    if (f.status && f.status !== 'all') params.set('status', f.status);
    if (f.sort) params.set('sort', f.sort);
    if (f.from) params.set('from', f.from);
    if (f.to) params.set('to', f.to);
    if (f.page) params.set('page', f.page);
    Object.entries(extra).forEach(([k, v]) => params.set(k, v));
    return params.toString();
}

async function loadDeliveries() {
    listController?.abort();
    const controller = new AbortController();
    listController = controller;

    isLoadingDeliveries.value = true;
    deliveriesError.value = '';

    try {
        const body = await apiFetch(`/deliveries?${buildQuery()}`, { signal: controller.signal });
        deliveries.value = body.data;
        deliveriesMeta.value = body.meta;
    } catch (err) {
        if (err.name === 'AbortError') return;
        console.error('Error loading deliveries:', err);
        deliveriesError.value = err?.message || 'Could not load deliveries.';
        deliveries.value = [];
    } finally {
        isLoadingDeliveries.value = false;
    }
}

async function loadSummary() {
    isLoadingSummary.value = true;
    summaryError.value = '';

    try {
        const body = await apiFetch('/deliveries/summary');
        summary.value = body.data;
    } catch (err) {
        console.error('Error loading delivery summary:', err);
        summaryError.value = err?.message || 'Could not load the delivery summary.';
    } finally {
        isLoadingSummary.value = false;
    }
}

let searchDebounce = null;
function setSearch(value) {
    filters.value.search = value;
    filters.value.page = 1;
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        syncUrl();
        loadDeliveries();
    }, 350);
}

function setFilter(patch) {
    Object.assign(filters.value, patch);
    if (!('page' in patch)) {
        filters.value.page = 1;
    }
    syncUrl();
    loadDeliveries();
}

function resetFilters() {
    filters.value = { search: '', status: 'all', sort: 'updated_desc', from: '', to: '', page: 1 };
    syncUrl();
    loadDeliveries();
}

// See useReports.js's syncUrl/initFromUrl for the same pattern —
// replaceState (not pushState) so filtering doesn't pile up
// back-button entries, only touches the querystring.
function syncUrl() {
    const url = new URL(window.location.href);
    const f = filters.value;
    const set = (k, v) => (v ? url.searchParams.set(k, v) : url.searchParams.delete(k));
    set('search', f.search);
    set('status', f.status !== 'all' ? f.status : '');
    set('sort', f.sort !== 'updated_desc' ? f.sort : '');
    set('from', f.from);
    set('to', f.to);
    set('page', f.page > 1 ? f.page : '');
    window.history.replaceState(window.history.state, '', url);
}

function initFromUrl() {
    const params = new URLSearchParams(window.location.search);
    filters.value = {
        search: params.get('search') || '',
        status: params.get('status') || 'all',
        sort: params.get('sort') || 'updated_desc',
        from: params.get('from') || '',
        to: params.get('to') || '',
        page: Number(params.get('page')) || 1,
    };
}

async function exportCsv() {
    if (isExporting.value) return;
    isExporting.value = true;
    exportError.value = '';

    try {
        const headers = await authHeaders();
        const response = await fetch(`/api/seller/deliveries/export?${buildQuery()}`, { headers });

        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            throw new Error(body.message || 'Export failed.');
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/);
        const filename = match ? match[1] : `nexmart-deliveries-${new Date().toISOString().slice(0, 10)}.csv`;

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Error exporting deliveries:', err);
        exportError.value = err?.message || 'Export failed. Please try again.';
    } finally {
        isExporting.value = false;
    }
}

const hasActiveFilters = computed(
    () => !!(filters.value.search || filters.value.status !== 'all' || filters.value.from || filters.value.to),
);

export function useDeliveries() {
    return {
        deliveries,
        deliveriesMeta,
        isLoadingDeliveries,
        deliveriesError,
        loadDeliveries,

        summary,
        isLoadingSummary,
        summaryError,
        loadSummary,

        filters,
        hasActiveFilters,
        setSearch,
        setFilter,
        resetFilters,
        initFromUrl,

        isExporting,
        exportError,
        exportCsv,
    };
}
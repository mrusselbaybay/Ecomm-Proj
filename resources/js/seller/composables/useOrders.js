// resources/js/seller/composables/useOrders.js
//
// ---------------------------------------------------------------
// Backed by the Laravel Seller Order API (routes/seller.php +
// App\Http\Controllers\Seller\SellerOrderController), not Supabase
// directly — order status changes are validated and audited
// server-side. Every request is authenticated by forwarding the
// current Supabase access token as a Bearer header; the same pattern
// the rest of the seller SPA uses for Supabase itself, just aimed at
// our own API instead.
// ---------------------------------------------------------------

import { ref, computed } from 'vue';
import { getSupabase } from './useSeller';

const orders = ref([]);
const ordersMeta = ref({ statusCounts: {} });
const isLoadingOrders = ref(false);
const loadError = ref('');
const isUpdatingStatus = ref(false);
const updateError = ref('');

// Shared with SellerLayout.vue, which renders the search box and the
// Filter popover (Order status/Payment/Date range/Sort) in the page
// header for the orders section — same reasoning as useSellerProducts'
// searchQuery: one real control, not a page-local one plus a decorative
// duplicate in the shared header.
const DEFAULT_ORDER_FILTERS = {
    search: '',
    status: '',
    payment_status: '',
    date_from: '',
    date_to: '',
    sort: 'newest',
};
const orderFilters = ref({ ...DEFAULT_ORDER_FILTERS });

function resetOrderFilters() {
    orderFilters.value = { ...DEFAULT_ORDER_FILTERS };
}

// Stored status value -> seller-facing label (mirrors Order::STATUS_LABELS).
// 'New' shows as "Pending", 'In Transit' as "Shipped".
const STATUS_LABELS = {
    New: 'Pending',
    Confirmed: 'Confirmed',
    Processing: 'Processing',
    Packed: 'Packed',
    'Ready for Pickup': 'Ready for Pickup',
    'In Transit': 'Shipped',
    Delivered: 'Delivered',
    Cancelled: 'Cancelled',
    Rejected: 'Rejected',
};

function statusLabel(status) {
    return STATUS_LABELS[status] || status || '—';
}

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
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

async function apiFetch(path, options = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api/seller${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Request failed.');
    }

    return body.data;
}

// Dashboard, Orders, and PrepareOrders all call loadOrders() on mount —
// without this, navigating between them re-fetched the seller's entire
// order history (this endpoint is unpaginated by default) every single
// time. ORDERS_CACHE_TTL_MS is short (orders change often — new orders,
// status updates) rather than useSellerProducts' longer TTL.
const ORDERS_CACHE_TTL_MS = 30 * 1000;
let ordersRequest = null;
let ordersRequestKey = null;
let ordersLoadedAt = 0;
let ordersLoadedKey = null;

async function loadOrders(params = {}, { force = false } = {}) {
    const qs = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    ).toString();

    // Same request (same filters/params) already in flight — join it
    // instead of firing a second, identical fetch.
    if (ordersRequest && ordersRequestKey === qs) {
        return ordersRequest;
    }

    const cacheIsFresh =
        ordersLoadedKey === qs && Date.now() - ordersLoadedAt < ORDERS_CACHE_TTL_MS;

    if (!force && cacheIsFresh) {
        isLoadingOrders.value = false;

        return;
    }

    isLoadingOrders.value = true;
    loadError.value = '';
    ordersRequestKey = qs;

    const request = (async () => {
        try {
            const headers = await authHeaders();
            const response = await fetch(`/api/seller/orders${qs ? `?${qs}` : ''}`, { headers });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(body.message || 'Request failed.');
            }

            orders.value = Array.isArray(body.data) ? body.data : [];
            ordersMeta.value = body.meta || { statusCounts: {} };
            ordersLoadedAt = Date.now();
            ordersLoadedKey = qs;
        } catch (err) {
            console.error('Error loading seller orders:', err);
            loadError.value =
                err?.message || 'Something went wrong while loading your orders.';
            orders.value = [];
        } finally {
            isLoadingOrders.value = false;

            if (ordersRequest === request) {
                ordersRequest = null;
                ordersRequestKey = null;
            }
        }
    })();

    ordersRequest = request;

    return request;
}

// Fetches a single order with its full detail (address, shipping,
// timeline). Always hits the API rather than reading the summary list
// in memory, since the list response omits those detail-only fields.
//
// Returns { order, notFound, error } so callers can tell a genuine 404
// ("this order doesn't exist") apart from a transient failure
// (network / 500) — the two want different UI (a plain empty state vs.
// a retry prompt).
async function getOrderById(id) {
    // A leading "#" in the display id is a URL fragment once it hits the
    // address bar — strip it before it becomes the request path.
    const clean = String(id).replace(/^#/, '');

    try {
        const headers = await authHeaders();
        const response = await fetch(
            `/api/seller/orders/${encodeURIComponent(clean)}`,
            { headers },
        );
        const body = await response.json().catch(() => ({}));

        if (response.status === 404) {
            return { order: null, notFound: true, error: '' };
        }

        if (!response.ok) {
            throw new Error(body.message || `Request failed (${response.status}).`);
        }

        return { order: body.data ?? null, notFound: false, error: '' };
    } catch (err) {
        console.error('Error loading order:', err);

        return {
            order: null,
            notFound: false,
            error: err?.message || 'Could not load this order.',
        };
    }
}

// Just the tracking payload (journey) — polled by the Order Details map
// while an order is in transit, so we don't refetch the whole order.
async function getOrderTracking(id) {
    try {
        return await apiFetch(`/orders/${encodeURIComponent(id)}/tracking`);
    } catch (err) {
        console.error('Error loading tracking:', err);

        return null;
    }
}

function replaceInList(updatedOrder) {
    const idx = orders.value.findIndex((o) => o.id === updatedOrder.id);

    if (idx !== -1) {
        // Keep the list entry's shape (it doesn't carry address/timeline).
        orders.value[idx] = { ...orders.value[idx], ...updatedOrder };
    }
}

async function updateOrderStatus(id, status, extra = {}) {
    isUpdatingStatus.value = true;
    updateError.value = '';

    try {
        const updated = await apiFetch(
            `/orders/${encodeURIComponent(id)}/status`,
            {
                method: 'PUT',
                body: JSON.stringify({ status, ...extra }),
            },
        );
        replaceInList(updated);

        return updated;
    } catch (err) {
        console.error('Error updating order status:', err);
        updateError.value =
            err?.message || 'Could not update the order status.';

        throw err;
    } finally {
        isUpdatingStatus.value = false;
    }
}

// New → Confirmed is the seller's "accept". (New → Processing stays valid
// on the backend for older callers, but the granular flow starts here.)
function confirmOrder(id) {
    return updateOrderStatus(id, 'Confirmed').catch(() => null);
}

function acceptOrder(id) {
    return updateOrderStatus(id, 'Confirmed').catch(() => null);
}

function startProcessing(id) {
    return updateOrderStatus(id, 'Processing').catch(() => null);
}

function markPacked(id) {
    return updateOrderStatus(id, 'Packed').catch(() => null);
}

function markReadyForPickup(id) {
    return updateOrderStatus(id, 'Ready for Pickup').catch(() => null);
}

// Reject / cancel both require a real reason (backend enforces min:3).
function rejectOrder(id, reason) {
    return updateOrderStatus(id, 'Rejected', { reason });
}

function cancelOrder(id, reason) {
    return updateOrderStatus(id, 'Cancelled', { reason });
}

function shipOrder(id, extra = {}) {
    return updateOrderStatus(id, 'In Transit', extra).catch(() => null);
}

// There is deliberately no deliverOrder() — a seller can no longer set
// 'Delivered' at all (see Order::SELLER_SETTABLE_STATUSES /
// SellerOrderController::updateStatus, which now rejects it). It's set
// automatically instead: app/Console/Commands/AutoDeliverStaleOrders.php
// (In Transit 7+ days with no confirmation), or eventually a buyer-side
// confirmation.

function statusBadgeClass(status) {
    const map = {
        New: 'badge-sky',
        Confirmed: 'badge-sky',
        Processing: 'badge-amber',
        Packed: 'badge-amber',
        'Ready for Pickup': 'badge-amber',
        'In Transit': 'badge-sky',
        Delivered: 'badge-emerald',
        Cancelled: 'badge-red',
        Rejected: 'badge-red',
    };

    return map[status] || 'badge-slate';
}

function formatCurrency(value) {
    return `₱${Number(value ?? 0).toFixed(2)}`;
}

const newOrdersCount = computed(
    () => orders.value.filter((o) => o.status === 'New').length,
);

export function useOrders() {
    return {
        orders,
        ordersMeta,
        isLoadingOrders,
        loadError,
        isUpdatingStatus,
        updateError,
        newOrdersCount,
        orderFilters,
        resetOrderFilters,
        STATUS_LABELS,
        loadOrders,
        getOrderById,
        getOrderTracking,
        updateOrderStatus,
        statusBadgeClass,
        statusLabel,
        formatCurrency,
        confirmOrder,
        acceptOrder,
        startProcessing,
        markPacked,
        markReadyForPickup,
        rejectOrder,
        cancelOrder,
        shipOrder,
    };
}
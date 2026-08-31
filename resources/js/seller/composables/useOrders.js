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

async function loadOrders(params = {}) {
    isLoadingOrders.value = true;
    loadError.value = '';

    const qs = new URLSearchParams(
        Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    ).toString();

    try {
        const headers = await authHeaders();
        const response = await fetch(`/api/seller/orders${qs ? `?${qs}` : ''}`, { headers });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.message || 'Request failed.');
        }

        orders.value = Array.isArray(body.data) ? body.data : [];
        ordersMeta.value = body.meta || { statusCounts: {} };
    } catch (err) {
        console.error('Error loading seller orders:', err);
        loadError.value =
            err?.message || 'Something went wrong while loading your orders.';
        orders.value = [];
    } finally {
        isLoadingOrders.value = false;
    }
}

// Fetches a single order with its full detail (address, shipping,
// timeline). Always hits the API rather than reading the summary list
// in memory, since the list response omits those detail-only fields.
async function getOrderById(id) {
    try {
        return await apiFetch(`/orders/${encodeURIComponent(id)}`);
    } catch (err) {
        console.error('Error loading order:', err);

        return null;
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

function deliverOrder(id) {
    return updateOrderStatus(id, 'Delivered').catch(() => null);
}

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
        deliverOrder,
    };
}
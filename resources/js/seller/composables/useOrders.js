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
const isLoadingOrders = ref(false);
const loadError = ref('');
const isUpdatingStatus = ref(false);
const updateError = ref('');

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

async function loadOrders() {
    isLoadingOrders.value = true;
    loadError.value = '';

    try {
        orders.value = await apiFetch('/orders');
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

function acceptOrder(id) {
    return updateOrderStatus(id, 'Processing').catch(() => null);
}

function rejectOrder(id, reason = 'Rejected by seller') {
    return updateOrderStatus(id, 'Cancelled', { reason }).catch(() => null);
}

function cancelOrder(id, reason = 'Cancelled by seller') {
    return updateOrderStatus(id, 'Cancelled', { reason }).catch(() => null);
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
        Processing: 'badge-amber',
        'In Transit': 'badge-sky',
        Delivered: 'badge-emerald',
        Cancelled: 'badge-red',
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
        isLoadingOrders,
        loadError,
        isUpdatingStatus,
        updateError,
        newOrdersCount,
        loadOrders,
        getOrderById,
        statusBadgeClass,
        formatCurrency,
        acceptOrder,
        rejectOrder,
        cancelOrder,
        shipOrder,
        deliverOrder,
    };
}
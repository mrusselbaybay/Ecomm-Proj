// Buyer return/refund requests for the signed-in seller
// (Seller\RefundRequestController). Module-level state so the header
// badge and the drawer share one list and one pending count.
import { ref } from 'vue';
import { apiRequest } from '../../shared/accountApi';
import { createClient } from '../../shared/backendClient';

const requests = ref([]);
const counts = ref({ pending: 0, approved: 0, rejected: 0 });
const status = ref('pending');
const isLoading = ref(false);
const loadError = ref('');

let loadToken = 0;

function call(path, options) {
    return apiRequest(createClient(), `/api/seller/refund-requests${path}`, options);
}

async function load(nextStatus = status.value) {
    status.value = nextStatus;
    const token = ++loadToken;
    isLoading.value = true;
    loadError.value = '';

    const { data, error } = await apiRequest(createClient(), `/api/seller/refund-requests?status=${nextStatus}`);

    // A newer tab switch superseded this request.
    if (token !== loadToken) {
        return;
    }

    if (error) {
        loadError.value = error.message || 'Could not load refund requests.';
    } else {
        requests.value = data.items;
        counts.value = data.counts;
    }

    isLoading.value = false;
}

// Lightweight: only refreshes the badge count.
async function refreshCount() {
    const { data, error } = await apiRequest(createClient(), '/api/seller/refund-requests?status=pending');

    if (!error && data?.counts) {
        counts.value = data.counts;
    }
}

function settle(id, updated) {
    const before = requests.value.find(r => r.id === id);

    if (before?.status === 'pending' && updated.status !== 'pending') {
        counts.value = {
            ...counts.value,
            pending: Math.max(0, counts.value.pending - 1),
            [updated.status]: (counts.value[updated.status] || 0) + 1
        };
    }

    // Leaves the Pending tab once decided.
    requests.value = status.value === 'pending'
        ? requests.value.filter(r => r.id !== id)
        : requests.value.map(r => (r.id === id ? updated : r));
}

async function approve(id) {
    const { data, error } = await call(`/${id}/approve`, { method: 'POST', body: {} });

    if (error) {
        return { error: error.message };
    }

    settle(id, data);

    return { data };
}

async function reject(id, note) {
    const { data, error } = await call(`/${id}/reject`, { method: 'POST', body: { note } });

    if (error) {
        return { error: error.message };
    }

    settle(id, data);

    return { data };
}

async function approveAll() {
    const { data, error } = await call('/approve-all', { method: 'POST', body: {} });

    if (error) {
        return { error: error.message };
    }

    await load('pending');

    return { data };
}

export function useRefundRequests() {
    return {
        requests,
        counts,
        status,
        isLoading,
        loadError,
        load,
        refreshCount,
        approve,
        reject,
        approveAll
    };
}

// resources/js/seller/composables/useSellerNotifications.js
//
// Seller notification inbox — powers the header bell + unread indicator.
// Backed by App\Http\Controllers\Seller\SellerNotificationController.
// Same Bearer-token pattern as useOrders.js.

import { computed, ref } from 'vue';
import { getSupabase } from './useSeller';

const items = ref([]);
const unreadCount = ref(0);
const isLoading = ref(false);
const loadError = ref('');

const POLL_MS = 30000;
let pollTimer = null;

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

    return body;
}

async function loadNotifications({ filter = 'all' } = {}) {
    isLoading.value = true;
    loadError.value = '';

    try {
        const body = await apiFetch(`/notifications?filter=${encodeURIComponent(filter)}`);

        items.value = body.data ?? [];
        unreadCount.value = body.meta?.unread ?? 0;
    } catch (err) {
        console.error('Error loading seller notifications:', err);
        loadError.value = err?.message || 'Could not load notifications.';
    } finally {
        isLoading.value = false;
    }
}

async function refreshUnreadCount() {
    try {
        const body = await apiFetch('/notifications/unread-count');

        unreadCount.value = body.data?.count ?? 0;
    } catch {
        // Silent — this powers a badge that polls constantly.
    }
}

async function markRead(id) {
    const item = items.value.find((n) => n.id === id);

    if (item && !item.read) {
        item.read = true;
        unreadCount.value = Math.max(0, unreadCount.value - 1);
    }

    try {
        await apiFetch(`/notifications/${encodeURIComponent(id)}/read`, { method: 'PUT' });
    } catch {
        // Non-critical — the badge will self-correct on the next poll.
    }
}

async function markAllRead() {
    items.value = items.value.map((n) => ({ ...n, read: true }));
    unreadCount.value = 0;

    try {
        await apiFetch('/notifications/read-all', { method: 'PUT' });
    } catch {
        refreshUnreadCount();
    }
}

function startPolling() {
    if (pollTimer) {
        return;
    }

    refreshUnreadCount();
    pollTimer = setInterval(refreshUnreadCount, POLL_MS);
}

function stopPolling() {
    clearInterval(pollTimer);
    pollTimer = null;
}

const hasUnread = computed(() => unreadCount.value > 0);

export function useSellerNotifications() {
    return {
        items,
        unreadCount,
        hasUnread,
        isLoading,
        loadError,
        loadNotifications,
        refreshUnreadCount,
        markRead,
        markAllRead,
        startPolling,
        stopPolling,
    };
}

<!-- resources/js/seller/components/Orders.vue -->
<!--
  Seller Order Management.

  Real data via useOrders() -> Laravel SellerOrderController. Summary
  cards + filter bar + a paginated table (desktop) / card list (mobile) +
  a live detail preview drawer. Every status action is workflow-aware:
  the buttons come straight from order.nextStatuses / order.canCancel
  (Order::ALLOWED_TRANSITIONS on the server), so an invalid transition
  can't be offered, and the API still rejects one if forced.
-->
<template>
    <div class="order-page order-page--v2">
        <!-- New-order alert -->
        <div v-if="newCount > 0" class="order-alert" role="status">
            <div class="order-alert-left">
                <span class="order-alert-dot" aria-hidden="true"></span>
                <p>
                    {{ newCount }} new order{{ newCount === 1 ? '' : 's' }}
                    awaiting your review.
                </p>
            </div>
            <button
                type="button"
                class="btn-primary btn-sm"
                @click="filters.status = 'New'"
            >
                Review new
            </button>
        </div>

        <OrderSummaryCards
            :counts="statusCounts"
            :active-status="filters.status"
            @select="onSummarySelect"
        />

        <OrderFilters v-model="filters" @reset="resetFilters" />

        <div class="order-results-layout">
            <!-- Results -->
            <section class="order-results card" aria-label="Order list">
                <div class="order-results-head">
                    <h3>Purchase Orders</h3>
                    <span class="order-results-count">{{ totalLabel }}</span>
                </div>

                <!-- Error -->
                <div
                    v-if="loadError && !isLoadingOrders"
                    class="order-state order-state--error"
                >
                    <p class="order-state-title">Couldn't load your orders</p>
                    <p class="order-state-text">{{ loadError }}</p>
                    <button type="button" class="btn-outline" @click="reload">
                        Try again
                    </button>
                </div>

                <!-- Skeleton (first load) -->
                <div
                    v-else-if="isLoadingOrders && !orders.length"
                    class="order-skel-list"
                    aria-hidden="true"
                >
                    <div v-for="n in 6" :key="n" class="order-skel-row">
                        <span class="order-skel-bar" style="width: 20%"></span>
                        <span class="order-skel-bar" style="width: 26%"></span>
                        <span class="order-skel-bar" style="width: 14%"></span>
                        <span class="order-skel-bar" style="width: 12%"></span>
                        <span class="order-skel-bar order-skel-pill" style="width: 5rem"></span>
                    </div>
                </div>

                <!-- Empty -->
                <div
                    v-else-if="!orders.length"
                    class="order-state order-state--empty"
                >
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <rect x="4" y="4" width="16" height="17" rx="2" />
                        <path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5" />
                    </svg>
                    <p class="order-state-title">No orders match these filters</p>
                    <p class="order-state-text">
                        Try widening the date range or clearing a filter.
                    </p>
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="btn-outline"
                        @click="resetFilters"
                    >
                        Reset filters
                    </button>
                </div>

                <template v-else>
                    <!-- Desktop table -->
                    <div class="order-table-wrap">
                        <table class="order-table order-table--full">
                            <thead>
                                <tr>
                                    <th scope="col">Order</th>
                                    <th scope="col">Buyer</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Products</th>
                                    <th scope="col" class="text-right">Total</th>
                                    <th scope="col">Payment</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Delivery</th>
                                    <th scope="col" class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="order in orders"
                                    :key="order.id"
                                    class="order-row"
                                    :class="{ active: order.id === selectedOrderId }"
                                    tabindex="0"
                                    role="button"
                                    :aria-pressed="order.id === selectedOrderId"
                                    :aria-label="`Preview order ${order.id}`"
                                    @click="selectOrder(order.id)"
                                    @keyup.enter="selectOrder(order.id)"
                                    @keyup.space.prevent="selectOrder(order.id)"
                                >
                                    <td class="order-id">
                                        <button
                                            type="button"
                                            class="order-id-link"
                                            @click.stop="openDetails(order.id)"
                                        >
                                            {{ order.id }}
                                        </button>
                                    </td>
                                    <td class="customer">{{ order.customer || '—' }}</td>
                                    <td class="order-date">{{ order.date || '—' }}</td>
                                    <td class="order-products">{{ productSummary(order) }}</td>
                                    <td class="amount text-right">{{ formatCurrency(order.total) }}</td>
                                    <td>
                                        <span class="badge" :class="paymentBadgeClass(order.paymentStatus)">
                                            {{ order.paymentStatus || 'Unpaid' }}
                                        </span>
                                    </td>
                                    <td>
                                        <OrderStatusBadge :status="order.status" size="sm" />
                                        <span v-if="order.returnStatus" class="order-return-flag">
                                            {{ returnLabel(order.returnStatus) }}
                                        </span>
                                    </td>
                                    <td class="order-delivery">{{ deliveryMethod(order) }}</td>
                                    <td class="text-right">
                                        <div class="order-row-actions">
                                            <button
                                                v-if="primaryNextAction(order)"
                                                type="button"
                                                class="btn-primary btn-sm"
                                                :disabled="actionBusyId === order.id"
                                                @click.stop="runPrimary(order)"
                                            >
                                                {{ actionBusyId === order.id ? '…' : primaryNextAction(order).label }}
                                            </button>
                                            <button
                                                type="button"
                                                class="btn-outline btn-sm"
                                                @click.stop="openDetails(order.id)"
                                            >
                                                View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile cards -->
                    <div class="order-cards">
                        <OrderCard
                            v-for="order in orders"
                            :key="order.id"
                            :order="order"
                            :primary-action="primaryNextAction(order)"
                            :busy="actionBusyId === order.id"
                            :selected="order.id === selectedOrderId"
                            @select="selectOrder(order.id)"
                            @open="openDetails(order.id)"
                            @action="(s) => runStatus(order, s)"
                        />
                    </div>

                    <!-- Pagination -->
                    <div v-if="lastPage > 1" class="order-pagination">
                        <button
                            type="button"
                            class="btn-outline btn-sm"
                            :disabled="page <= 1"
                            @click="goTo(page - 1)"
                        >
                            Previous
                        </button>
                        <div class="order-pagination-pages">
                            <button
                                v-for="p in pageWindow"
                                :key="p"
                                type="button"
                                class="order-page-btn"
                                :class="{ active: p === page }"
                                @click="goTo(p)"
                            >
                                {{ p }}
                            </button>
                        </div>
                        <button
                            type="button"
                            class="btn-outline btn-sm"
                            :disabled="page >= lastPage"
                            @click="goTo(page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </template>
            </section>

            <!-- Preview drawer -->
            <aside class="order-preview card" aria-label="Order preview">
                <div v-if="!selectedOrderId" class="order-preview-empty">
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <rect x="4" y="4" width="16" height="17" rx="2" />
                        <path d="M9 2h6v3H9zM8 10h8M8 14h8M8 18h5" />
                    </svg>
                    <p>Select an order to preview it here.</p>
                </div>

                <div v-else-if="isLoadingPreview && !selectedDetail" class="order-preview-skel" aria-hidden="true">
                    <span class="order-skel-bar" style="width: 45%; height: 1rem"></span>
                    <span class="order-skel-bar" style="width: 70%"></span>
                    <span class="order-skel-bar" style="width: 60%"></span>
                    <span class="order-skel-bar" style="width: 80%"></span>
                    <span class="order-skel-bar" style="width: 50%"></span>
                </div>

                <div v-else-if="previewError" class="order-preview-empty">
                    <svg
                        class="icon-lg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        aria-hidden="true"
                    >
                        <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                        <path d="M12 9v4M12 17h.01" />
                    </svg>
                    <p>{{ previewError }}</p>
                    <button type="button" class="btn-outline btn-sm" @click="retryPreview">
                        Retry
                    </button>
                </div>

                <template v-else-if="selectedDetail">
                    <header class="order-preview-head">
                        <div>
                            <div class="order-preview-title-row">
                                <h3>Order {{ selectedDetail.id }}</h3>
                                <OrderStatusBadge :status="selectedDetail.status" size="sm" />
                            </div>
                            <p class="order-preview-sub">
                                Placed {{ selectedDetail.date }} · {{ selectedDetail.time }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="btn-outline btn-sm"
                            @click="openDetails(selectedDetail.id)"
                        >
                            Full details
                        </button>
                    </header>

                    <div class="order-preview-body">
                        <section>
                            <h4 class="order-section-label">Buyer</h4>
                            <p class="order-address-name">{{ selectedDetail.customer }}</p>
                            <p class="order-address-text">{{ selectedDetail.email || 'No email on file' }}</p>
                            <p v-if="selectedDetail.phone" class="order-address-text">{{ selectedDetail.phone }}</p>
                        </section>

                        <section>
                            <h4 class="order-section-label">Delivery address</h4>
                            <p class="order-address-name">{{ selectedDetail.address?.recipient || selectedDetail.customer }}</p>
                            <p class="order-address-text">{{ formatAddress(selectedDetail.address) }}</p>
                            <p class="order-address-text">
                                {{ selectedDetail.shipping?.method || deliveryMethod(selectedDetail) }}
                            </p>
                        </section>

                        <section>
                            <h4 class="order-section-label">
                                Items ({{ selectedDetail.items.length }})
                            </h4>
                            <div
                                v-for="(item, idx) in selectedDetail.items"
                                :key="idx"
                                class="order-preview-item"
                            >
                                <div class="order-item-thumb">
                                    <img v-if="item.image" :src="item.image" :alt="item.name" />
                                    <svg
                                        v-else
                                        width="20"
                                        height="20"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.6"
                                        aria-hidden="true"
                                    >
                                        <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" />
                                        <path d="M3 8l9 5 9-5M12 13v8" />
                                    </svg>
                                </div>
                                <div class="order-preview-item-info">
                                    <p class="order-item-name">{{ item.name }}</p>
                                    <p class="order-item-meta">
                                        Qty {{ item.qty }}
                                        <template v-if="item.variant"> · {{ item.variant }}</template>
                                    </p>
                                </div>
                                <p class="order-item-price">
                                    {{ formatCurrency(item.subtotal ?? item.price * item.qty) }}
                                </p>
                            </div>
                        </section>

                        <section>
                            <h4 class="order-section-label">Payment summary</h4>
                            <div class="order-payment-row">
                                <span>Item subtotal</span><strong>{{ formatCurrency(selectedDetail.subtotal) }}</strong>
                            </div>
                            <div v-if="selectedDetail.discount" class="order-payment-row">
                                <span>Discount</span>
                                <strong class="order-discount-value">-{{ formatCurrency(selectedDetail.discount) }}</strong>
                            </div>
                            <div class="order-payment-row">
                                <span>Shipping</span>
                                <strong :class="{ 'order-shipping-free': !selectedDetail.shippingFee }">
                                    {{ selectedDetail.shippingFee ? formatCurrency(selectedDetail.shippingFee) : 'Free' }}
                                </strong>
                            </div>
                            <div v-if="selectedDetail.tax" class="order-payment-row">
                                <span>Tax</span><strong>{{ formatCurrency(selectedDetail.tax) }}</strong>
                            </div>
                            <div class="order-payment-row order-payment-total">
                                <span>Total</span><strong>{{ formatCurrency(selectedDetail.total) }}</strong>
                            </div>
                            <p class="order-preview-payment-meta">
                                {{ selectedDetail.paymentMethod || 'Payment method unknown' }} ·
                                {{ selectedDetail.paymentStatus || 'Unpaid' }}
                            </p>
                        </section>

                        <section v-if="previewTimeline.length">
                            <h4 class="order-section-label">Recent activity</h4>
                            <ul class="order-preview-timeline">
                                <li v-for="(step, idx) in previewTimeline" :key="idx">
                                    <span class="order-preview-timeline-dot" aria-hidden="true"></span>
                                    <span>
                                        <strong>{{ step.label }}</strong>
                                        <span class="order-preview-timeline-time">{{ step.time }}</span>
                                    </span>
                                </li>
                            </ul>
                        </section>
                    </div>

                    <footer class="order-preview-actions">
                        <button
                            v-if="selectedDetail.canCancel"
                            type="button"
                            class="btn-text-danger"
                            :disabled="actionBusyId === selectedDetail.id"
                            @click="askDestructive('Rejected')"
                        >
                            Reject
                        </button>
                        <div class="order-preview-actions-right">
                            <button
                                v-if="selectedDetail.canCancel"
                                type="button"
                                class="btn-outline btn-sm"
                                :disabled="actionBusyId === selectedDetail.id"
                                @click="askDestructive('Cancelled')"
                            >
                                Cancel
                            </button>
                            <button
                                v-if="primaryNextAction(selectedDetail)"
                                type="button"
                                class="btn-primary btn-sm"
                                :disabled="actionBusyId === selectedDetail.id"
                                @click="runPrimary(selectedDetail)"
                            >
                                {{ actionBusyId === selectedDetail.id ? 'Working…' : primaryNextAction(selectedDetail).label }}
                            </button>
                        </div>
                    </footer>
                </template>
            </aside>
        </div>

        <transition name="order-toast">
            <div
                v-if="toast"
                class="order-toast"
                :class="`order-toast--${toast.type}`"
                role="status"
                aria-live="polite"
            >
                {{ toast.msg }}
            </div>
        </transition>

        <ConfirmActionDialog
            :open="dialog.open"
            :title="dialog.title"
            :message="dialog.message"
            :confirm-label="dialog.confirmLabel"
            tone="danger"
            requires-reason
            :reason-label="dialog.reasonLabel"
            reason-placeholder="e.g. item out of stock, buyer asked to cancel, address unreachable"
            :busy="actionBusyId === dialog.orderId"
            @confirm="onDialogConfirm"
            @cancel="dialog.open = false"
        />
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useOrders } from '../composables/useOrders';
import ConfirmActionDialog from './orders/ConfirmActionDialog.vue';
import OrderCard from './orders/OrderCard.vue';
import OrderFilters from './orders/OrderFilters.vue';
import OrderStatusBadge from './orders/OrderStatusBadge.vue';
import OrderSummaryCards from './orders/OrderSummaryCards.vue';

const props = defineProps({
    // Real status string deep-linked from Reports.vue's order-breakdown.
    statusFilter: { type: String, default: null },
});

const {
    orders,
    ordersMeta,
    isLoadingOrders,
    loadError,
    updateError,
    loadOrders,
    getOrderById,
    updateOrderStatus,
    formatCurrency,
} = useOrders();

const PER_PAGE = 12;

const DEFAULT_FILTERS = {
    search: '',
    status: '',
    payment_status: '',
    date_from: '',
    date_to: '',
    sort: 'newest',
};

const filters = ref({ ...DEFAULT_FILTERS });
const page = ref(1);

const selectedOrderId = ref(null);
const selectedDetail = ref(null);
const isLoadingPreview = ref(false);
const previewError = ref('');
const detailCache = new Map();

const actionBusyId = ref(null);
const toast = ref(null);
let toastTimer = null;

const dialog = reactive({
    open: false,
    orderId: null,
    target: null, // 'Cancelled' | 'Rejected'
    title: '',
    message: '',
    confirmLabel: '',
    reasonLabel: '',
});

// ---- derived ------------------------------------------------------------

const statusCounts = computed(() => ordersMeta.value?.statusCounts || {});
const newCount = computed(() => Number(statusCounts.value.New || 0));
const lastPage = computed(() => Number(ordersMeta.value?.lastPage || 1));
const total = computed(() => Number(ordersMeta.value?.total ?? orders.value.length));

const totalLabel = computed(() => {
    const t = total.value;

    return `${t} order${t === 1 ? '' : 's'}`;
});

const hasActiveFilters = computed(() => {
    const f = filters.value;

    return (
        !!f.search ||
        !!f.status ||
        !!f.payment_status ||
        !!f.date_from ||
        !!f.date_to ||
        f.sort !== 'newest'
    );
});

const pageWindow = computed(() => {
    const last = lastPage.value;
    const cur = page.value;
    const start = Math.max(1, Math.min(cur - 2, last - 4));
    const end = Math.min(last, start + 4);
    const out = [];

    for (let p = start; p <= end; p++) {
        out.push(p);
    }

    return out;
});

const previewTimeline = computed(() => {
    const list = selectedDetail.value?.timeline || [];

    return [...list].reverse().slice(0, 4);
});

// ---- display helpers --------------------------------------------------

const SELLER_STEP_VERBS = {
    Confirmed: 'Confirm order',
    Processing: 'Start processing',
    Packed: 'Mark packed',
    'Ready for Pickup': 'Mark ready',
};

function primaryNextAction(order) {
    const step = (order?.nextStatuses || []).find((s) => SELLER_STEP_VERBS[s.value]);

    return step ? { value: step.value, label: SELLER_STEP_VERBS[step.value] } : null;
}

function productSummary(order) {
    const items = order.items || [];

    if (!items.length) {
        return 'No items';
    }

    const first = items[0].name || 'Item';

    return items.length === 1 ? first : `${first} +${items.length - 1} more`;
}

function deliveryMethod(order) {
    return order.shippingService || order.shippingCarrier || 'Standard delivery';
}

function paymentBadgeClass(status) {
    const map = { Paid: 'badge-emerald', Refunded: 'badge-slate', Unpaid: 'badge-amber' };

    return map[status] || 'badge-amber';
}

const RETURN_LABELS = {
    requested: 'Return requested',
    approved: 'Return approved',
    returned: 'Returned',
    rejected: 'Return rejected',
};

function returnLabel(key) {
    return RETURN_LABELS[key] || '';
}

function formatAddress(addr) {
    if (!addr) {
        return '—';
    }

    return [
        addr.street,
        [addr.barangay, addr.municipality].filter(Boolean).join(', '),
        addr.province,
        addr.country,
    ]
        .filter(Boolean)
        .join(', ');
}

// ---- data loading ---------------------------------------------------------

async function reload() {
    await loadOrders({ ...filters.value, page: page.value, per_page: PER_PAGE });

    if (
        selectedOrderId.value &&
        !orders.value.some((o) => o.id === selectedOrderId.value)
    ) {
        selectedOrderId.value = null;
        selectedDetail.value = null;
    }
}

function goTo(p) {
    if (p < 1 || p > lastPage.value || p === page.value) {
        return;
    }

    page.value = p;
}

function resetFilters() {
    filters.value = { ...DEFAULT_FILTERS };
}

function onSummarySelect(status) {
    filters.value = { ...filters.value, status };
}

// Filters change -> back to page 1 (or reload directly if already there).
watch(
    filters,
    () => {
        if (page.value !== 1) {
            page.value = 1;
        } else {
            reload();
        }
    },
    { deep: true },
);

watch(page, reload);

watch(
    () => props.statusFilter,
    (status) => {
        if (status) {
            filters.value = { ...filters.value, status };
        }
    },
    { immediate: true },
);

onMounted(reload);

// ---- preview -----------------------------------------------------------

async function selectOrder(id) {
    selectedOrderId.value = id;
    previewError.value = '';

    if (detailCache.has(id)) {
        selectedDetail.value = detailCache.get(id);

        return;
    }

    isLoadingPreview.value = true;
    selectedDetail.value = null;

    const { order: detail, error } = await getOrderById(id);

    // A slow response for a since-changed selection is stale — ignore it.
    if (selectedOrderId.value !== id) {
        isLoadingPreview.value = false;

        return;
    }

    if (detail) {
        detailCache.set(id, detail);
        selectedDetail.value = detail;
    } else {
        previewError.value = error || 'This order could not be found.';
    }

    isLoadingPreview.value = false;
}

function retryPreview() {
    if (selectedOrderId.value) {
        selectOrder(selectedOrderId.value);
    }
}

function openDetails(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'orderDetails', orderId: id },
        }),
    );
}

// ---- status actions --------------------------------------------------

function flash(msg, type = 'success') {
    clearTimeout(toastTimer);
    toast.value = { msg, type };
    toastTimer = setTimeout(() => {
        toast.value = null;
    }, 3800);
}

function applyUpdated(updated) {
    if (!updated) {
        return;
    }

    detailCache.set(updated.id, updated);

    if (selectedOrderId.value === updated.id) {
        selectedDetail.value = updated;
    }
}

async function runStatus(order, statusValue) {
    if (actionBusyId.value) {
        return;
    }

    actionBusyId.value = order.id;

    try {
        const updated = await updateOrderStatus(order.id, statusValue);
        applyUpdated(updated);
        flash(`Order ${order.id} updated.`);
        await reload();
    } catch {
        flash(updateError.value || 'Could not update the order.', 'error');
    } finally {
        actionBusyId.value = null;
    }
}

function runPrimary(order) {
    const action = primaryNextAction(order);

    if (action) {
        runStatus(order, action.value);
    }
}

function askDestructive(target) {
    const order = selectedDetail.value;

    if (!order) {
        return;
    }

    const isReject = target === 'Rejected';

    dialog.orderId = order.id;
    dialog.target = target;
    dialog.title = isReject ? `Reject order ${order.id}?` : `Cancel order ${order.id}?`;
    dialog.message = isReject
        ? 'The buyer is notified, any reserved stock is released, and the order is closed. This cannot be undone.'
        : 'The buyer is notified, any reserved stock is released, and the order is closed. This cannot be undone.';
    dialog.confirmLabel = isReject ? 'Reject order' : 'Cancel order';
    dialog.reasonLabel = isReject ? 'Reason for rejecting' : 'Reason for cancelling';
    dialog.open = true;
}

async function onDialogConfirm(reason) {
    const id = dialog.orderId;
    const target = dialog.target;

    if (!id || !target || actionBusyId.value) {
        return;
    }

    actionBusyId.value = id;

    try {
        const updated = await updateOrderStatus(id, target, { reason });
        applyUpdated(updated);
        dialog.open = false;
        flash(`Order ${id} ${target === 'Rejected' ? 'rejected' : 'cancelled'}.`);
        await reload();
    } catch {
        flash(updateError.value || 'Could not update the order.', 'error');
    } finally {
        actionBusyId.value = null;
    }
}
</script>

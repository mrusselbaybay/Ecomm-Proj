<!-- resources/js/seller/components/OrderDetails.vue -->
<template>
    <div class="order-detail-page">
        <!-- Loading -->
        <div v-if="isLoading" class="order-detail-skel" aria-hidden="true">
            <div class="order-detail-skel-head">
                <span class="order-skel-bar" style="width: 12rem; height: 1.4rem"></span>
                <span class="order-skel-bar order-skel-pill" style="width: 6rem"></span>
            </div>
            <div class="order-detail-skel-grid">
                <div class="card order-detail-skel-card">
                    <span class="order-skel-bar" style="width: 40%"></span>
                    <span class="order-skel-bar" style="width: 80%"></span>
                    <span class="order-skel-bar" style="width: 65%"></span>
                    <span class="order-skel-bar" style="width: 75%"></span>
                </div>
                <div class="card order-detail-skel-card">
                    <span class="order-skel-bar" style="width: 50%"></span>
                    <span class="order-skel-bar" style="width: 70%"></span>
                    <span class="order-skel-bar" style="width: 60%"></span>
                </div>
            </div>
        </div>

        <!-- Load failed (network / server error) -->
        <div v-else-if="loadFailed" class="card order-not-found">
            <div class="empty-state">
                <svg
                    class="icon-lg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                >
                    <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                    <path d="M12 9v4M12 17h.01" />
                </svg>
                <p>Couldn't load this order.</p>
                <p class="empty-hint">
                    Something went wrong reaching the server. Check your
                    connection and try again.
                </p>
                <div class="order-detail-error-actions">
                    <button type="button" class="btn-outline" @click="loadOrder">
                        Retry
                    </button>
                    <button type="button" class="btn-primary" @click="backToOrders">
                        Back to Orders
                    </button>
                </div>
            </div>
        </div>

        <!-- Not found -->
        <div v-else-if="!order" class="card order-not-found">
            <div class="empty-state">
                <svg
                    class="icon-lg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                >
                    <circle cx="12" cy="12" r="9" />
                    <path d="m9 9 6 6M15 9l-6 6" />
                </svg>
                <p>Order not found.</p>
                <p class="empty-hint">
                    This order may have been removed, or the link is incorrect.
                </p>
                <button
                    class="btn-primary"
                    style="margin-top: 1rem"
                    @click="backToOrders"
                >
                    Back to Orders
                </button>
            </div>
        </div>

        <!-- Loaded -->
        <template v-else>
            <!-- Header -->
            <header class="od-header">
                <div class="od-header-left">
                    <button
                        type="button"
                        class="od-back-btn"
                        aria-label="Back to Orders"
                        @click="backToOrders"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M15 5 8 12l7 7" />
                        </svg>
                    </button>
                    <div>
                        <div class="order-detail-title-row">
                            <h2 class="order-detail-page-title">
                                Order {{ order.id }}
                            </h2>
                            <OrderStatusBadge :status="order.status" />
                            <span v-if="returnLabel" class="order-return-flag">{{ returnLabel }}</span>
                        </div>
                        <p class="order-detail-page-sub">
                            Placed on {{ order.date }} at {{ order.time }} · {{ order.customer }}
                        </p>
                    </div>
                </div>

                <div class="order-detail-page-actions">
                    <button class="btn-outline" @click="printPackingSlip">
                        <svg
                            class="icon"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                        >
                            <path d="M3 5h14v11H3z" />
                            <path d="M7 3h6v3H7zM6 9h8M6 12h5" />
                        </svg>
                        Packing Slip
                    </button>
                    <button class="btn-outline" @click="printInvoice">
                        <svg
                            class="icon"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                        >
                            <path
                                d="M6 2.5h8l4 4v11a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15z"
                            />
                        </svg>
                        Print Invoice
                    </button>
                    <button
                        v-if="order.shipping.trackingNumber"
                        class="btn-outline"
                        @click="trackPackage"
                    >
                        <svg
                            class="icon"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                        >
                            <rect x="1" y="5" width="12" height="9" rx="1" />
                            <path d="M13 8h3l3 3v3h-6z" />
                        </svg>
                        Track Package
                    </button>
                    <button class="btn-primary" @click="contactBuyer">
                        <svg
                            class="icon"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                        >
                            <rect x="2" y="4" width="16" height="12" rx="2" />
                            <path d="m3 5 7 6 7-6" />
                        </svg>
                        Contact Buyer
                    </button>
                </div>
            </header>

            <div class="order-detail-page-grid">
                <!-- Left column -->
                <div class="order-detail-page-left">
                    <!-- Fulfilment progress -->
                    <div v-if="!isCancelledOrRejected" class="card od-progress-card">
                        <p v-if="shipFromLine" class="od-progress-top">{{ shipFromLine }}</p>
                        <div class="od-steps">
                            <span
                                v-for="(step, idx) in progressSteps"
                                :key="step.label"
                                class="od-step"
                                :class="{ done: step.done, active: step.active }"
                            >
                                <span class="od-step-ic">
                                    <svg v-if="step.done" width="10" height="10" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="3">
                                        <path d="M4 10l4 4 8-8" />
                                    </svg>
                                    <template v-else>{{ idx + 1 }}</template>
                                </span>
                                {{ step.label }}
                            </span>
                        </div>
                        <div class="od-progress-bar"><span :style="{ width: progressWidth }"></span></div>
                    </div>
                    <div v-else class="card od-cancel-banner">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M9 9l6 6M15 9l-6 6" />
                        </svg>
                        <div>
                            <p class="od-cancel-title">
                                Order {{ order.status === 'Rejected' ? 'rejected' : 'cancelled' }}
                            </p>
                            <p v-if="order.cancellation?.reason" class="od-cancel-reason">
                                {{ order.cancellation.reason }}
                            </p>
                        </div>
                    </div>

                    <!-- Primary actions: destructive on the left, forward workflow on the right -->
                    <div
                        v-if="canCancel || nextStatusButtons.length"
                        class="od-actions-row"
                    >
                        <div class="od-actions-row-left">
                            <button
                                v-if="canCancel"
                                type="button"
                                class="od-text-danger"
                                :disabled="isBusy"
                                @click="handleReject"
                            >
                                {{ busyKey === 'Rejected' ? 'Rejecting…' : 'Reject' }}
                            </button>
                            <button
                                v-if="canCancel"
                                type="button"
                                class="btn-danger-soft"
                                :disabled="isBusy"
                                @click="handleCancel"
                            >
                                {{ busyKey === 'Cancelled' ? 'Cancelling…' : 'Cancel Order' }}
                            </button>
                        </div>
                        <button
                            v-for="s in nextStatusButtons"
                            :key="s.value"
                            type="button"
                            class="btn-primary"
                            :disabled="isBusy"
                            @click="moveTo(s.value)"
                        >
                            {{ busyKey === s.value ? 'Working…' : nextActionLabel(s) }}
                            <svg
                                width="14"
                                height="14"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                aria-hidden="true"
                            >
                                <path d="M5 12h14M12 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>

                    <!-- Products -->
                    <div class="card od-card">
                        <h3 class="order-section-label">
                            Products ({{ order.items.length }})
                        </h3>
                        <div
                            class="od-product-row"
                            v-for="(item, idx) in order.items"
                            :key="idx"
                        >
                            <span class="od-p-thumb">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.name"
                                    style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit"
                                />
                                <svg
                                    v-else
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                >
                                    <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" />
                                    <path d="M3 8l9 5 9-5M12 13v8" />
                                </svg>
                            </span>
                            <div class="od-p-info">
                                <p class="od-p-name">{{ item.name }}</p>
                                <p class="od-p-meta">
                                    <span v-if="item.sku">SKU {{ item.sku }}</span
                                    ><span v-if="item.variant"> · {{ item.variant }}</span
                                    ><span> · Qty {{ item.qty }}</span>
                                </p>
                            </div>
                            <span class="od-p-price">
                                {{ formatCurrency(item.subtotal ?? item.price * item.qty) }}
                            </span>
                        </div>
                    </div>

                    <!-- Payment -->
                    <div class="card od-card">
                        <div class="od-section-head">
                            <h3 class="order-section-label" style="margin: 0">
                                Payment Details
                            </h3>
                            <span class="badge" :class="paymentBadgeClass">
                                {{ order.paymentStatus || 'Unknown' }}
                            </span>
                        </div>
                        <div class="order-payment-row">
                            <span>Payment Method</span
                            ><strong>{{ order.paymentMethod || 'Not on file' }}</strong>
                        </div>
                        <div class="order-payment-row">
                            <span>Subtotal</span
                            ><strong>{{ formatCurrency(order.subtotal) }}</strong>
                        </div>
                        <div class="order-payment-row">
                            <span>Shipping Fee</span>
                            <strong
                                :class="{
                                    'order-shipping-free': !order.shippingFee,
                                }"
                            >
                                {{
                                    order.shippingFee
                                        ? formatCurrency(order.shippingFee)
                                        : 'Free'
                                }}
                            </strong>
                        </div>
                        <div class="order-payment-row" v-if="order.tax">
                            <span>Tax</span
                            ><strong>{{ formatCurrency(order.tax) }}</strong>
                        </div>
                        <div class="order-payment-row" v-if="order.discount">
                            <span>Discount</span
                            ><strong class="order-discount-value"
                                >-{{ formatCurrency(order.discount) }}</strong
                            >
                        </div>
                        <div class="order-payment-row order-payment-total">
                            <span>Total Paid</span
                            ><strong>{{ formatCurrency(order.total) }}</strong>
                        </div>
                    </div>
                </div>

                <!-- Right column -->
                <div class="order-detail-page-right">
                    <!-- Customer -->
                    <div class="card od-card">
                        <h3 class="order-section-label">Customer</h3>
                        <div class="order-customer-row">
                            <div class="order-customer-avatar od-cust-avatar-lg">
                                {{ customerInitials }}
                            </div>
                            <div>
                                <p class="order-customer-name">{{ order.customer }}</p>
                                <p class="order-customer-email">
                                    {{ order.email || 'No email on file' }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="od-chat-btn"
                                aria-label="Message customer"
                                @click="contactBuyer"
                            >
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z" />
                                </svg>
                            </button>
                        </div>
                        <div v-if="order.email || order.phone" class="od-contact-chips">
                            <span v-if="order.email" class="od-contact-chip">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" />
                                </svg>
                                {{ order.email }}
                            </span>
                            <span v-if="order.phone" class="od-contact-chip">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z" />
                                </svg>
                                {{ order.phone }}
                            </span>
                        </div>
                    </div>

                    <!-- Address + Shipping -->
                    <div class="card od-card">
                        <h3 class="order-section-label">
                            Delivery Address
                        </h3>
                        <div class="order-info-icon-row">
                            <div class="order-info-icon sky">
                                <svg
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                >
                                    <path
                                        d="M12 22s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z"
                                    />
                                    <circle cx="12" cy="10" r="2.5" />
                                </svg>
                            </div>
                            <div>
                                <p class="order-address-name">
                                    {{ order.address.recipient }}
                                </p>
                                <p class="order-address-text">
                                    {{ formatAddress(order.address) }}
                                </p>
                                <p
                                    class="order-address-phone"
                                    v-if="order.phone"
                                >
                                    Phone: {{ order.phone }}
                                </p>
                            </div>
                        </div>
                        <div class="od-divider"></div>
                        <h3 class="order-section-label" style="margin-top: 1.1rem">Shipping Method</h3>
                        <div class="order-info-icon-row">
                            <div class="order-info-icon emerald">
                                <svg
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                >
                                    <path
                                        d="M13 2 3 14h7l-1 8 10-12h-7l1-8Z"
                                    />
                                </svg>
                            </div>
                            <div>
                                <p class="order-address-name">
                                    {{
                                        order.shipping.method ||
                                        'Not specified'
                                    }}
                                </p>
                                <p
                                    class="order-address-text"
                                    v-if="
                                        order.shipping.handlingTime ||
                                        order.shipping.carrier ||
                                        order.shipping.service
                                    "
                                >
                                    <template
                                        v-if="order.shipping.handlingTime"
                                        >Handling Time:
                                        {{ order.shipping.handlingTime
                                        }}<br
                                    /></template>
                                    <template v-if="order.shipping.carrier"
                                        >Carrier: {{ order.shipping.carrier
                                        }}<br
                                    /></template>
                                    <template v-if="order.shipping.service"
                                        >Service:
                                        {{
                                            order.shipping.service
                                        }}</template
                                    >
                                </p>
                                <a
                                    v-if="order.shipping.trackingNumber"
                                    href="#"
                                    class="order-tracking-link"
                                    @click.prevent="trackPackage"
                                    >#{{ order.shipping.trackingNumber }}</a
                                >
                                <p v-else class="order-address-sub">
                                    No tracking number yet
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Parcel tracking map (kept as a light "island" card since
                         it renders real map tiles/markers that assume a light
                         base — see OrderJourneyMap's own scoped styles) -->
                    <div
                        v-if="order.journey"
                        ref="journeyCard"
                        class="card od-card order-journey-card"
                    >
                        <OrderJourneyMap :journey="order.journey" />
                    </div>

                    <!-- Timeline -->
                    <div class="card od-card">
                        <h3 class="order-section-label">Order Progression</h3>
                        <div class="timeline timeline-reverse">
                            <div
                                v-for="(step, idx) in reversedTimeline"
                                :key="idx"
                                class="timeline-item"
                            >
                                <div class="timeline-dot-wrap">
                                    <span
                                        class="timeline-dot"
                                        :class="{ pending: !step.done }"
                                    ></span>
                                    <span
                                        v-if="idx < reversedTimeline.length - 1"
                                        class="timeline-line"
                                    ></span>
                                </div>
                                <div>
                                    <p
                                        class="timeline-text"
                                        :class="{ muted: !step.done }"
                                    >
                                        {{ step.label }}
                                    </p>
                                    <p
                                        class="timeline-time"
                                        :class="{ italic: !step.done }"
                                    >
                                        {{ step.time }}
                                    </p>
                                    <p
                                        v-if="step.detail"
                                        class="timeline-detail"
                                    >
                                        {{ step.detail }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="order-detail-page-footer">
                <a
                    href="#"
                    class="order-back-link"
                    @click.prevent="backToOrders"
                >
                    <svg
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path d="M19 12H5M12 19l-7-7 7-7" />
                    </svg>
                    Back to All Orders
                </a>
            </div>
        </template>

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
            :busy="isBusy"
            @confirm="onDialogConfirm"
            @cancel="dialog.open = false"
        />
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import OrderJourneyMap from '../../shared/OrderJourneyMap.vue';
import { useOrders } from '../composables/useOrders';
import ConfirmActionDialog from './orders/ConfirmActionDialog.vue';
import OrderStatusBadge from './orders/OrderStatusBadge.vue';

const props = defineProps({
    orderId: { type: String, default: null },
});

const {
    getOrderById,
    getOrderTracking,
    updateOrderStatus,
    updateError,
    formatCurrency,
    cancelOrder,
    rejectOrder,
} = useOrders();

// Which action is in flight ('' when idle). A status value for a
// forward step, or 'Cancelled' / 'Rejected'. Used to show a per-button
// spinner and to block duplicate submits.
const busyKey = ref('');
const isBusy = computed(() => busyKey.value !== '');

const toast = ref(null);
let toastTimer = null;

function flash(msg, type = 'success') {
    clearTimeout(toastTimer);
    toast.value = { msg, type };
    toastTimer = setTimeout(() => {
        toast.value = null;
    }, 3800);
}

const dialog = reactive({
    open: false,
    target: '', // 'Cancelled' | 'Rejected'
    title: '',
    message: '',
    confirmLabel: '',
    reasonLabel: '',
});

// Order Details only ever offers "Confirm Order" — the whole packing
// pipeline after that (Processing -> Packed -> Ready for Pickup) lives
// in Order Preparation now, not as quick-jump buttons here, so there's
// one real place a seller drives that flow from instead of two
// competing ones. Once an order is actually Confirmed (or further
// along), the server's nextStatuses for it no longer includes
// 'Confirmed', so this naturally disappears on its own — no separate
// "already confirmed" check needed.
const nextStatusButtons = computed(() =>
    (order.value?.nextStatuses || []).filter((s) => s.value === 'Confirmed'),
);

function nextActionLabel() {
    return 'Confirm Order';
}

async function moveTo(statusValue) {
    if (!order.value || isBusy.value) {
        return;
    }

    busyKey.value = statusValue;

    try {
        const updated = await updateOrderStatus(order.value.id, statusValue);

        if (updated) {
            order.value = updated;

            if (statusValue === 'Confirmed') {
                flash('Order confirmed — opening Order Preparation…');
                goTo('prepareOrders', updated.id);

                return;
            }

            flash(`Order marked "${updated.statusLabel || statusValue}".`);
        }
    } catch {
        flash(updateError.value || 'Could not update the order status.', 'error');
    } finally {
        busyKey.value = '';
    }
}

// Cancel / Reject open a confirmation dialog (consequence spelled out,
// typed reason required) instead of a bare window.prompt.
function askDestructive(target) {
    if (!order.value) {
        return;
    }

    const isReject = target === 'Rejected';

    dialog.target = target;
    dialog.title = isReject
        ? `Reject order ${order.value.id}?`
        : `Cancel order ${order.value.id}?`;
    dialog.message =
        'The buyer is notified, any reserved stock is released, and the order is closed. This cannot be undone.';
    dialog.confirmLabel = isReject ? 'Reject order' : 'Cancel order';
    dialog.reasonLabel = isReject ? 'Reason for rejecting' : 'Reason for cancelling';
    dialog.open = true;
}

function handleReject() {
    askDestructive('Rejected');
}

async function onDialogConfirm(reason) {
    if (!order.value || isBusy.value) {
        return;
    }

    const target = dialog.target;
    busyKey.value = target;

    try {
        const fn = target === 'Rejected' ? rejectOrder : cancelOrder;
        const updated = await fn(order.value.id, reason);

        if (updated) {
            order.value = updated;
            dialog.open = false;
            flash(`Order ${target === 'Rejected' ? 'rejected' : 'cancelled'}.`);
        }
    } catch {
        flash(updateError.value || 'Could not update the order.', 'error');
    } finally {
        busyKey.value = '';
    }
}

const isLoading = ref(true);
const order = ref(null);
// true only when the fetch actually failed (network / 500), as opposed
// to the order genuinely not existing (404) — the two states show
// different UI.
const loadFailed = ref(false);
const journeyCard = ref(null);

async function loadOrder() {
    isLoading.value = true;
    loadFailed.value = false;

    if (!props.orderId) {
        order.value = null;
        isLoading.value = false;

        return;
    }

    const { order: fetched, error } = await getOrderById(props.orderId);

    // Once an order is Processing, there's nothing left to do on this
    // page — Order Details' only real action is "Confirm Order" (see
    // nextStatusButtons below), which no longer even shows once the
    // order has moved past Confirmed, and the whole packing flow lives
    // on Prepare Orders now. Rather than land the seller on a page with
    // no action left, send them straight to where the real work is —
    // the same place Confirm Order itself already redirects to.
    if (fetched?.status === 'Processing') {
        goTo('prepareOrders', fetched.id);

        return;
    }

    order.value = fetched;
    loadFailed.value = !fetched && !!error;
    isLoading.value = false;
}

/*
| Live tracking poll — while the order is in transit, refresh just the
| journey payload every 12s so OrderJourneyMap animates the courier as new
| GPS pings land (real ones, or `tracking:simulate` ones). Stops as soon
| as the order leaves "In Transit" or the page unmounts.
*/
const TRACKING_POLL_MS = 12000;
let trackingTimer = null;

async function pollTracking() {
    if (!order.value || !props.orderId) {
        return;
    }

    const res = await getOrderTracking(props.orderId);

    if (res && order.value) {
        order.value.journey = res;
    }
}

function stopTrackingPoll() {
    clearInterval(trackingTimer);
    trackingTimer = null;
}

function syncTrackingPoll(status) {
    if (status === 'In Transit') {
        if (!trackingTimer) {
            pollTracking();
            trackingTimer = setInterval(pollTracking, TRACKING_POLL_MS);
        }
    } else {
        stopTrackingPoll();
    }
}

watch(() => order.value?.status, syncTrackingPoll);

onMounted(loadOrder);
onBeforeUnmount(stopTrackingPoll);

const customerInitials = computed(() => {
    if (!order.value?.customer) {
        return '?';
    }

    return order.value.customer
        .split(' ')
        .map((p) => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
});

const reversedTimeline = computed(() =>
    order.value ? [...order.value.timeline].reverse() : [],
);

// Cancel / reject is only offered while the order is still Pending or
// Confirmed (server enforces Order::SELLER_CANCELLABLE_FROM).
const canCancel = computed(() => order.value?.canCancel === true);

const RETURN_LABELS = {
    requested: 'Return requested',
    approved: 'Return approved',
    returned: 'Returned / refunded',
    rejected: 'Return rejected',
};
const returnLabel = computed(() => RETURN_LABELS[order.value?.returnStatus] || '');

/*
| Fulfilment stepper — groups the real Order::STATUSES into 4 stages so
| the top-of-page progress bar reads at a glance. Cancelled/Rejected are
| shown as a banner instead (see isCancelledOrRejected) since they don't
| fit a "how far along" story. Widths are fixed visual increments, not a
| measured metric — nothing here is fabricated data, just a coarser view
| of the same real order.status the badge and timeline already show.
*/
const PROGRESS_STAGE_LABELS = ['Review order', 'Preparing order', 'Shipping', 'Delivered'];
const PROGRESS_STAGE_STATUSES = [
    ['New'],
    ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup'],
    ['In Transit'],
    ['Delivered'],
];
const PROGRESS_WIDTHS = ['12%', '45%', '78%', '100%'];

const isCancelledOrRejected = computed(() =>
    ['Cancelled', 'Rejected'].includes(order.value?.status),
);

const progressStageIndex = computed(() => {
    if (!order.value || isCancelledOrRejected.value) {
        return -1;
    }

    return PROGRESS_STAGE_STATUSES.findIndex((group) => group.includes(order.value.status));
});

const progressSteps = computed(() =>
    PROGRESS_STAGE_LABELS.map((label, idx) => ({
        label,
        done: idx < progressStageIndex.value,
        active: idx === progressStageIndex.value,
    })),
);

const progressWidth = computed(
    () => PROGRESS_WIDTHS[Math.max(progressStageIndex.value, 0)] || '0%',
);

// "Ships from {store} · {city, province}" — real seller identity, same
// fields the printable receipt header already uses.
const shipFromLine = computed(() => {
    const seller = order.value?.seller;

    if (!seller?.name) {
        return '';
    }

    const place = [seller.city, seller.province].filter(Boolean).join(', ');

    return place ? `Ships from ${seller.name} · ${place}` : `Ships from ${seller.name}`;
});

// payment_status is a fixed DB enum: Unpaid | Paid | Refunded.
const PAYMENT_BADGE_CLASS = {
    Paid: 'badge-emerald',
    Unpaid: 'badge-slate',
    Refunded: 'badge-red',
};
const paymentBadgeClass = computed(
    () => PAYMENT_BADGE_CLASS[order.value?.paymentStatus] || 'badge-slate',
);

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

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);
}

function receiptRow(label, value, opts = {}) {
    const cls = opts.strong ? ' class="r-strong"' : '';

    return `<div class="r-row"${cls}><span>${esc(label)}</span><span>${esc(value)}</span></div>`;
}

// Builds a standalone, receipt-style invoice document. Rendered into a
// hidden iframe and printed on its own, so none of the app's chrome,
// cards or the tracking map end up on the page — see printInvoice().
function buildReceiptHtml(o) {
    const seller = o.seller || {};
    const addr = o.address || {};
    const shipTo = [
        addr.street,
        [addr.barangay, addr.municipality].filter(Boolean).join(', '),
        [addr.province, addr.country].filter(Boolean).join(', '),
    ].filter(Boolean);

    const items = (o.items || []).map((it) => {
        const line = Number(it.qty) * Number(it.price);
        const sub = [it.variant, it.sku ? `SKU ${it.sku}` : null].filter(Boolean).join(' · ');

        return `
            <div class="r-item">
                <div class="r-item-name">${esc(it.name)}</div>
                ${sub ? `<div class="r-item-sub">${esc(sub)}</div>` : ''}
                <div class="r-row">
                    <span>${esc(it.qty)} × ${esc(formatCurrency(it.price))}</span>
                    <span>${esc(formatCurrency(line))}</span>
                </div>
            </div>`;
    }).join('');

    const totals = [
        receiptRow('Subtotal', formatCurrency(o.subtotal)),
        receiptRow('Shipping', formatCurrency(o.shippingFee)),
        Number(o.tax) > 0 ? receiptRow('Tax', formatCurrency(o.tax)) : '',
        Number(o.discount) > 0 ? receiptRow('Discount', `- ${formatCurrency(o.discount)}`) : '',
        receiptRow('Total', formatCurrency(o.total), { strong: true }),
    ].join('');

    const trackingBlock = o.shipping?.trackingNumber
        ? `<div class="r-block">
               ${receiptRow('Carrier', o.shipping.carrier || o.shippingCarrier || '—')}
               ${receiptRow('Tracking #', o.shipping.trackingNumber)}
           </div>`
        : '';

    return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Receipt ${esc(o.id)}</title>
<style>
    @page { margin: 12mm; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font: 12px/1.5 "SF Mono", "Roboto Mono", Menlo, Consolas, monospace;
        color: #111;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .r-sheet { width: 340px; margin: 0 auto; padding: 8px 0; }
    .r-center { text-align: center; }
    .r-store { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
    .r-store-sub { color: #555; font-size: 11px; margin-top: 2px; }
    .r-kind { margin-top: 6px; font-size: 11px; letter-spacing: 2px; color: #555; }
    .r-hr { border: 0; border-top: 1px dashed #999; margin: 10px 0; }
    .r-row { display: flex; justify-content: space-between; gap: 12px; }
    .r-row > span:last-child { text-align: right; white-space: nowrap; }
    .r-strong { font-weight: 700; font-size: 13px; margin-top: 4px; }
    .r-label { color: #555; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
    .r-block { margin: 8px 0; }
    .r-block + .r-block { margin-top: 10px; }
    .r-lines { white-space: pre-wrap; word-break: break-word; }
    .r-item { margin: 8px 0; }
    .r-item-name { font-weight: 600; }
    .r-item-sub { color: #666; font-size: 10.5px; }
    .r-foot { margin-top: 14px; text-align: center; color: #555; font-size: 10.5px; }
</style>
</head>
<body>
<div class="r-sheet">
    <div class="r-center">
        <div class="r-store">${esc(seller.name || 'Store')}</div>
        ${(seller.city || seller.province)
            ? `<div class="r-store-sub">${esc([seller.city, seller.province].filter(Boolean).join(', '))}</div>`
            : ''}
        <div class="r-kind">SALES RECEIPT</div>
    </div>

    <hr class="r-hr">

    ${receiptRow('Order', o.id)}
    ${receiptRow('Placed', `${o.date || ''} ${o.time || ''}`.trim())}
    ${receiptRow('Status', o.status || '—')}
    ${receiptRow('Payment', `${o.paymentMethod || '—'} (${o.paymentStatus || '—'})`)}

    <hr class="r-hr">

    <div class="r-block">
        <div class="r-label">Bill to</div>
        <div class="r-lines">${esc(o.customer || '—')}${o.email ? `\n${esc(o.email)}` : ''}${o.phone ? `\n${esc(o.phone)}` : ''}</div>
    </div>
    ${shipTo.length ? `<div class="r-block">
        <div class="r-label">Ship to</div>
        <div class="r-lines">${esc(addr.recipient || o.customer || '')}\n${shipTo.map(esc).join('\n')}</div>
    </div>` : ''}

    <hr class="r-hr">

    ${items || '<div class="r-item-sub">No items on this order.</div>'}

    <hr class="r-hr">

    ${totals}

    ${trackingBlock ? `<hr class="r-hr">${trackingBlock}` : ''}

    <div class="r-foot">
        Thank you!<br>
        Printed ${esc(new Date().toLocaleString())}
    </div>
</div>
</body>
</html>`;
}

function printInvoice() {
    if (!order.value) {
        return;
    }

    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const cleanup = () => frame.remove();

    frame.onload = () => {
        const win = frame.contentWindow;

        win.onafterprint = cleanup;
        win.focus();
        win.print();
        // Fallback for browsers that don't fire onafterprint.
        setTimeout(cleanup, 60000);
    };

    const doc = frame.contentWindow.document;
    doc.open();
    doc.write(buildReceiptHtml(order.value));
    doc.close();
}

/*
| Packing slip — for the person physically packing the box. Order number,
| store, ship-to, and the item/variant/SKU/qty checklist. NO payment
| method, NO payment status, NO buyer email — only what's needed to
| fulfil (spec).
*/
function buildPackingSlipHtml(o) {
    const seller = o.seller || {};
    const addr = o.address || {};
    const shipTo = [
        addr.street,
        [addr.barangay, addr.municipality].filter(Boolean).join(', '),
        [addr.province, addr.country].filter(Boolean).join(', '),
    ].filter(Boolean);

    const rows = (o.items || [])
        .map(
            (it) => `
        <tr>
            <td class="p-chk">&#9744;</td>
            <td>
                <div class="p-name">${esc(it.name)}</div>
                <div class="p-sub">${esc(
                    [it.variant, it.variantSku || it.sku ? `SKU ${it.variantSku || it.sku}` : '']
                        .filter(Boolean)
                        .join(' · '),
                )}</div>
            </td>
            <td class="p-qty">&times;${esc(it.qty)}</td>
        </tr>`,
        )
        .join('');

    return `<!doctype html><html><head><meta charset="utf-8">
<title>Packing slip ${esc(o.id)}</title>
<style>
    @page { margin: 14mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font: 13px/1.5 -apple-system, "Segoe UI", Roboto, sans-serif; color: #111; }
    .p-sheet { max-width: 620px; margin: 0 auto; }
    .p-head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 10px; }
    .p-store { font-size: 18px; font-weight: 700; }
    .p-store-sub { color: #555; font-size: 12px; }
    .p-title { font-size: 12px; letter-spacing: 2px; color: #555; text-transform: uppercase; text-align: right; }
    .p-order { font-size: 16px; font-weight: 700; text-align: right; }
    .p-grid { display: flex; gap: 32px; margin: 16px 0; }
    .p-label { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #666; margin-bottom: 3px; }
    .p-lines { white-space: pre-wrap; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th { text-align: left; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #666; border-bottom: 1px solid #ccc; padding: 6px 4px; }
    td { padding: 8px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
    .p-chk { font-size: 16px; width: 24px; }
    .p-name { font-weight: 600; }
    .p-sub { color: #666; font-size: 11px; }
    .p-qty { font-weight: 700; text-align: right; white-space: nowrap; }
    .p-notes { margin-top: 22px; }
    .p-notes-box { border: 1px dashed #999; border-radius: 6px; height: 70px; margin-top: 4px; }
    .p-foot { margin-top: 20px; font-size: 10px; color: #777; }
</style></head><body>
<div class="p-sheet">
    <div class="p-head">
        <div>
            <div class="p-store">${esc(seller.name || 'Store')}</div>
            ${
                seller.city || seller.province
                    ? `<div class="p-store-sub">${esc([seller.city, seller.province].filter(Boolean).join(', '))}</div>`
                    : ''
            }
        </div>
        <div>
            <div class="p-title">Packing Slip</div>
            <div class="p-order">${esc(o.id)}</div>
            <div class="p-store-sub">${esc(`${o.date || ''} ${o.time || ''}`.trim())}</div>
        </div>
    </div>

    <div class="p-grid">
        <div>
            <div class="p-label">Ship to</div>
            <div class="p-lines">${esc(addr.recipient || o.customer || '')}\n${shipTo.map(esc).join('\n')}</div>
        </div>
        <div>
            <div class="p-label">Contact</div>
            <div class="p-lines">${esc(o.phone || '—')}</div>
        </div>
    </div>

    <table>
        <thead><tr><th></th><th>Item</th><th style="text-align:right">Qty</th></tr></thead>
        <tbody>${rows || '<tr><td></td><td class="p-sub">No items.</td><td></td></tr>'}</tbody>
    </table>

    <div class="p-notes">
        <div class="p-label">Packing notes</div>
        <div class="p-notes-box"></div>
    </div>

    <div class="p-foot">Printed ${esc(new Date().toLocaleString())} · Fulfilment document — not a receipt.</div>
</div>
</body></html>`;
}

function printHtml(html) {
    const frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(frame);

    const cleanup = () => frame.remove();

    frame.onload = () => {
        const win = frame.contentWindow;

        win.onafterprint = cleanup;
        win.focus();
        win.print();
        setTimeout(cleanup, 60000);
    };

    const doc = frame.contentWindow.document;

    doc.open();
    doc.write(html);
    doc.close();
}

function printPackingSlip() {
    if (order.value) {
        printHtml(buildPackingSlipHtml(order.value));
    }
}

async function trackPackage() {
    // Scroll the parcel tracking map (OrderJourneyMap) into view.
    await nextTick();
    journeyCard.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function contactBuyer() {
    goTo('messages');
}

function handleCancel() {
    askDestructive('Cancelled');
}

function goTo(section, orderId) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', { detail: { section, orderId } }),
    );
}

function backToOrders() {
    goTo('orders');
}
</script>
<!-- resources/js/seller/components/OrderDetails.vue -->
<template>
    <div class="order-detail-page">
        <!-- Loading -->
        <div v-if="isLoading" class="order-detail-loading">
            <div class="loading-spinner"></div>
            <p>Loading order…</p>
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
            <header class="order-detail-page-header">
                <div>
                    <div class="order-detail-title-row">
                        <h2 class="order-detail-page-title">
                            Order {{ order.id }}
                        </h2>
                        <span
                            class="badge"
                            :class="statusBadgeClass(order.status)"
                            >{{ order.statusLabel || statusLabel(order.status) }}</span
                        >
                    </div>
                    <p class="order-detail-page-sub">
                        Placed on {{ order.date }} at {{ order.time }}
                    </p>
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
                    <!-- Order Information -->
                    <div class="card order-detail-page-card">
                        <h3 class="order-section-label">Order Information</h3>
                        <div class="order-info-grid">
                            <div>
                                <p class="order-info-label">Customer</p>
                                <div class="order-customer-row">
                                    <div class="order-customer-avatar">
                                        {{ customerInitials }}
                                    </div>
                                    <div>
                                        <p class="order-customer-name">
                                            {{ order.customer }}
                                        </p>
                                        <p class="order-customer-email">
                                            {{
                                                order.email ||
                                                'No email on file'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <p class="order-info-label">Placed On</p>
                                <p class="order-info-value">{{ order.date }}</p>
                                <p class="order-info-sub">{{ order.time }}</p>
                            </div>
                            <div>
                                <p class="order-info-label">Payment Method</p>
                                <p class="order-info-value">
                                    {{ order.paymentMethod || 'Not on file' }}
                                </p>
                                <p class="order-info-sub">
                                    {{ order.paymentStatus || '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Items -->
                    <div class="card order-detail-page-card order-items-card">
                        <h3 class="order-section-label">
                            Items List ({{ order.items.length }})
                        </h3>
                        <div
                            class="order-item-card order-item-card-lg"
                            v-for="(item, idx) in order.items"
                            :key="idx"
                        >
                            <div class="order-item-thumb order-item-thumb-lg">
                                <img
                                    v-if="item.image"
                                    :src="item.image"
                                    :alt="item.name"
                                    style="width: 100%; height: 100%; object-fit: cover; border-radius: inherit"
                                />
                                <svg
                                    v-else
                                    width="26"
                                    height="26"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.6"
                                >
                                    <path d="M21 8 12 3 3 8v8l9 5 9-5V8Z" />
                                    <path d="M3 8l9 5 9-5M12 13v8" />
                                </svg>
                            </div>
                            <div class="order-item-info">
                                <div class="order-item-info-top">
                                    <div>
                                        <p class="order-item-name-lg">
                                            {{ item.name }}
                                        </p>
                                        <p class="order-item-category">
                                            {{
                                                item.category
                                                    ? `Category: ${item.category}`
                                                    : ''
                                            }}
                                        </p>
                                        <div class="order-item-meta-row">
                                            <span v-if="item.variant"
                                                >Variant:
                                                <strong>{{
                                                    item.variant
                                                }}</strong></span
                                            >
                                            <span v-if="item.sku"
                                                >SKU:
                                                <strong>{{
                                                    item.sku
                                                }}</strong></span
                                            >
                                        </div>
                                    </div>
                                    <div class="order-item-price-block">
                                        <p class="order-item-price-label">
                                            Unit price
                                        </p>
                                        <p class="order-item-price-lg">
                                            {{ formatCurrency(item.price) }}
                                        </p>
                                        <p class="order-item-qty">
                                            Qty: {{ item.qty }}
                                        </p>
                                        <p
                                            v-if="item.subtotal != null"
                                            class="order-item-qty"
                                            style="font-weight: 700; color: #0f172a"
                                        >
                                            Subtotal: {{ formatCurrency(item.subtotal) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Address + Shipping -->
                    <div class="order-detail-grid">
                        <div class="card order-detail-page-card">
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
                        </div>
                        <div class="card order-detail-page-card">
                            <h3 class="order-section-label">Shipping Method</h3>
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
                    </div>
                </div>

                <!-- Right column -->
                <div class="order-detail-page-right">
                    <!-- Payment -->
                    <div class="card order-detail-page-card order-payment-card">
                        <h3 class="order-section-label">Payment Breakdown</h3>
                        <div class="order-payment-row">
                            <span>Order Subtotal</span
                            ><strong>{{
                                formatCurrency(order.subtotal)
                            }}</strong>
                        </div>
                        <div class="order-payment-row">
                            <span>Shipping</span>
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
                        <div class="order-payment-footer">
                            <p>
                                Payment status:
                                {{ order.paymentStatus || 'Unknown' }}
                            </p>
                        </div>
                    </div>

                    <!-- Parcel tracking map -->
                    <div
                        v-if="order.journey"
                        ref="journeyCard"
                        class="card order-detail-page-card"
                    >
                        <OrderJourneyMap :journey="order.journey" />
                    </div>

                    <!-- Timeline -->
                    <div class="card order-detail-page-card">
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

            <!-- Footer actions -->
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
                <div class="order-detail-page-footer-right">
                    <button
                        v-if="canCancel"
                        class="btn-outline"
                        :disabled="actionBusy"
                        @click="handleReject"
                    >
                        Reject
                    </button>
                    <button
                        v-if="canCancel"
                        class="btn-danger-soft"
                        :disabled="actionBusy"
                        @click="handleCancel"
                    >
                        Cancel Order
                    </button>
                    <button
                        v-for="s in nextStatusButtons"
                        :key="s.value"
                        class="btn-primary"
                        :disabled="actionBusy"
                        @click="moveTo(s.value)"
                    >
                        {{ nextActionLabel(s) }}
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import OrderJourneyMap from '../../shared/OrderJourneyMap.vue';
import { useOrders } from '../composables/useOrders';

const props = defineProps({
    orderId: { type: String, default: null },
});

const {
    getOrderById,
    getOrderTracking,
    updateOrderStatus,
    statusBadgeClass,
    statusLabel,
    formatCurrency,
    cancelOrder,
    rejectOrder,
} = useOrders();

const actionBusy = ref(false);

// The seller's next moves come straight from the server (order.nextStatuses
// = Order::ALLOWED_TRANSITIONS from the current status). Shipping/Delivery
// are hidden here — those are logistics' to set.
const nextStatusButtons = computed(() =>
    (order.value?.nextStatuses || []).filter(
        (s) => !['Cancelled', 'Rejected', 'In Transit', 'Delivered'].includes(s.value),
    ),
);

const NEXT_ACTION_VERBS = {
    Confirmed: 'Confirm Order',
    Processing: 'Start Processing',
    Packed: 'Mark Packed',
    'Ready for Pickup': 'Mark Ready for Pickup',
};

function nextActionLabel(s) {
    return NEXT_ACTION_VERBS[s.value] || `Move to ${s.label}`;
}

async function moveTo(statusValue) {
    if (!order.value || actionBusy.value) {
        return;
    }

    actionBusy.value = true;

    try {
        const updated = await updateOrderStatus(order.value.id, statusValue);

        if (updated) {
            order.value = updated;
        }
    } catch {
        // updateError is surfaced by the composable; the order stays put.
    } finally {
        actionBusy.value = false;
    }
}

async function handleReject() {
    if (!order.value) {
        return;
    }

    const reason = window.prompt(
        'Why are you rejecting this order? (unavailable stock, invalid order info, unable to fulfil, …)',
    );

    if (!reason || reason.trim().length < 3) {
        return;
    }

    actionBusy.value = true;

    try {
        const updated = await rejectOrder(order.value.id, reason.trim());

        if (updated) {
            order.value = updated;
        }
    } catch {
        // handled by composable
    } finally {
        actionBusy.value = false;
    }
}

const isLoading = ref(true);
const order = ref(null);
const journeyCard = ref(null);

async function loadOrder() {
    isLoading.value = true;
    order.value = props.orderId ? await getOrderById(props.orderId) : null;
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

async function handleCancel() {
    if (!order.value) {
        return;
    }

    const reason = window.prompt(
        `Cancel order ${order.value.id}? Enter a reason (this is recorded and shown to the buyer).`,
    );

    if (!reason || reason.trim().length < 3) {
        return;
    }

    actionBusy.value = true;

    try {
        const updated = await cancelOrder(order.value.id, reason.trim());

        if (updated) {
            order.value = updated;
        }
    } catch {
        // handled by composable
    } finally {
        actionBusy.value = false;
    }
}

function goTo(section) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', { detail: { section } }),
    );
}

function backToOrders() {
    goTo('orders');
}
</script>
<!-- resources/js/seller/components/PrepareOrders.vue -->
<template>
    <div v-if="isLoading" class="prep-skeleton" aria-busy="true" aria-label="Loading order">
        <header class="prep-skeleton-header">
            <div style="flex: 1">
                <div class="prep-skeleton-line" style="width: 40%; height: 1.1rem; margin-bottom: 0.6rem"></div>
                <div class="prep-skeleton-line" style="width: 25%; height: 0.7rem"></div>
            </div>
            <div class="prep-skeleton-block" style="width: 150px; height: 2.5rem"></div>
        </header>

        <div class="prep-grid">
            <div class="prep-col">
                <div class="card">
                    <div class="prep-skeleton-line" style="width: 35%; height: 1rem; margin-bottom: 1.25rem"></div>
                    <div v-for="n in 3" :key="n" class="prep-skeleton-item-row">
                        <div class="prep-skeleton-block" style="width: 20px; height: 20px; flex-shrink: 0"></div>
                        <div style="flex: 1">
                            <div class="prep-skeleton-line" style="width: 60%; margin-bottom: 0.5rem"></div>
                            <div class="prep-skeleton-line" style="width: 30%; height: 0.6rem"></div>
                        </div>
                        <div class="prep-skeleton-block" style="width: 70px; height: 1.5rem"></div>
                    </div>
                </div>
            </div>

            <div class="prep-col">
                <div class="card">
                    <div class="prep-skeleton-line" style="width: 55%; height: 1rem; margin-bottom: 1.25rem"></div>
                    <div class="prep-skeleton-block" style="height: 2.5rem; margin-bottom: 1rem"></div>
                    <div class="prep-skeleton-block" style="height: 2.5rem; margin-bottom: 1rem"></div>
                    <div class="prep-skeleton-block" style="height: 2.5rem"></div>
                </div>
            </div>
        </div>
    </div>

    <div v-else-if="!props.orderId" class="card">
        <div class="prep-card-head" style="border-bottom: none">
            <div>
                <h3>Prepare Shipment</h3>
                <p class="prep-card-sub">
                    Choose an order to pack and dispatch.
                </p>
            </div>
        </div>

        <div v-if="isLoadingOrders" class="placeholder-page">
            <div class="loading-spinner"></div>
            <p style="margin-top: 1rem">Loading your orders…</p>
        </div>

        <div v-else-if="preparableOrders.length === 0" class="placeholder-page">
            <div class="icon-wrap">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                </svg>
            </div>
            <h3>Nothing to prepare right now</h3>
            <p>
                Orders show up here once you've accepted them and they're
                waiting to be packed and shipped.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goTo('orders')">
                Go to Orders
            </button>
        </div>

        <div v-else class="prep-picker-list">
            <button
                v-for="o in preparableOrders"
                :key="o.id"
                class="prep-picker-row"
                @click="selectOrder(o.id)"
            >
                <div>
                    <p class="prep-picker-id">{{ o.id }}</p>
                    <p class="prep-picker-meta">{{ o.customer }} — {{ o.items.length }} item(s) — {{ formatCurrency(o.total) }}</p>
                </div>
                <span class="badge" :class="statusBadgeClass(o.status)">{{ o.status }}</span>
            </button>
        </div>
    </div>

    <div v-else-if="!order" class="card">
        <div class="placeholder-page">
            <div class="icon-wrap">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M9.5 9.5 14.5 14.5M14.5 9.5 9.5 14.5" />
                </svg>
            </div>
            <h3>Order not found</h3>
            <p>
                That order couldn't be loaded. It may have been removed, or
                the link may be out of date.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goTo('orders')">
                Go to Orders
            </button>
        </div>
    </div>

    <div v-else-if="!canPrepare" class="card">
        <div class="placeholder-page">
            <div class="icon-wrap" style="background: #fffbeb; color: #d97706">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 9v4M12 17h.01" />
                    <circle cx="12" cy="12" r="9" />
                </svg>
            </div>
            <h3>{{ order.id }} isn't ready to prepare</h3>
            <p>
                This order is currently
                <span class="badge" :class="statusBadgeClass(order.status)">{{ order.status }}</span>
                — shipments can only be prepared for orders you've already
                accepted.
            </p>
            <button class="btn-outline" style="margin-top: 1.25rem" @click="goToOrderDetails">
                View Order Details
            </button>
        </div>
    </div>

    <div v-else>
        <!-- ================================================================
         HEADER
         ================================================================ -->
        <header class="prep-header">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="prep-title">Prepare Shipment</h2>
                    <span class="badge" :class="statusBadgeClass(order.status)">{{ statusLabel(order.status) }}</span>
                </div>
                <nav class="prep-breadcrumb">
                    <a href="#" @click.prevent="goTo('orders')">Orders</a>
                    <span>/</span>
                    <a href="#" @click.prevent="goToOrderDetails">{{ order.id }}</a>
                    <span>/</span>
                    <span>Prepare Shipment</span>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <button class="btn-outline" @click="saveDraft">
                    {{ draftSavedAt ? 'Draft Saved ✓' : 'Save Draft' }}
                </button>
                <button
                    class="btn-primary"
                    :disabled="!canDispatch"
                    :title="dispatchDisabledReason"
                    @click="confirmDispatch"
                >
                    {{ isUpdatingStatus ? 'Dispatching…' : 'Confirm Dispatch' }}
                </button>
            </div>
        </header>

        <p v-if="updateError" class="save-msg error" style="margin-bottom: 1rem">
            {{ updateError }}
        </p>

        <!-- ================================================================
         WORKFLOW GRID
         ================================================================ -->
        <div class="prep-grid">
            <!-- LEFT: items + packing tips -->
            <div class="prep-col">
                <div class="card">
                    <div class="prep-card-head">
                        <div class="flex items-center gap-3">
                            <div class="prep-icon-badge">
                                <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                    <path d="M10 10v7.5" />
                                </svg>
                            </div>
                            <h3>Items to Pack</h3>
                        </div>
                        <span class="prep-progress-label">{{ packedCount }} / {{ totalItems }} packed</span>
                    </div>

                    <div class="prep-progress-track">
                        <div class="prep-progress-fill" :style="{ width: packProgressPct + '%' }"></div>
                    </div>

                    <div class="prep-item-list">
                        <div
                            v-for="(item, idx) in order.items"
                            :key="idx"
                            class="prep-item-row"
                            :class="{ packed: packedState[idx] }"
                        >
                            <div class="prep-item-icon">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                                    <path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5Z" />
                                    <path d="M3 6.5V14l7 3.5 7-3.5V6.5" />
                                </svg>
                            </div>
                            <div class="prep-item-info">
                                <p v-if="item.sku" class="prep-item-sku">SKU: {{ item.sku }}</p>
                                <h4 class="prep-item-name">{{ item.name }}</h4>
                                <p v-if="item.variant" class="prep-item-variant">{{ item.variant }}</p>
                            </div>
                            <div class="prep-item-actions">
                                <span class="prep-item-qty">Qty: {{ item.qty }}</span>
                                <label class="prep-packed-toggle">
                                    <input
                                        type="checkbox"
                                        :checked="!!packedState[idx]"
                                        @change="togglePacked(idx)"
                                    />
                                    <span>{{ packedState[idx] ? 'Packed' : 'Pending' }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="prep-card-head" style="border-bottom: none; padding-bottom: 0">
                        <h3>Packing Tips</h3>
                    </div>
                    <div class="prep-tips-grid">
                        <div class="prep-tip">
                            <span class="prep-tip-num">1</span>
                            <p>Use a box sized close to the items — extra empty space means more movement in transit.</p>
                        </div>
                        <div class="prep-tip">
                            <span class="prep-tip-num">2</span>
                            <p>Wrap fragile or electronic items individually before boxing them together.</p>
                        </div>
                        <div class="prep-tip">
                            <span class="prep-tip-num">3</span>
                            <p>Double-check quantities against this checklist before sealing the box.</p>
                        </div>
                        <div class="prep-tip">
                            <span class="prep-tip-num">4</span>
                            <p>Seal all edges securely and keep the tracking number visible on the label.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: shipping configuration -->
            <div class="prep-col">
                <div class="card">
                    <div class="prep-card-head">
                        <div>
                            <h3>Shipping Configuration</h3>
                            <p class="prep-card-sub">Enter the package and courier details for this shipment.</p>
                        </div>
                    </div>

                    <div class="prep-form">
                        <div class="sheet-field-row">
                            <div>
                                <label class="field-label">Package Weight (kg) <span class="prep-required">*</span></label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    class="field-input"
                                    v-model.number="packageWeight"
                                    placeholder="0.00"
                                    @keydown="blockNonNumericKey"
                                    @paste="sanitizeNumericPaste"
                                />
                            </div>
                            <div>
                                <label class="field-label">Dimensions L×W×H (cm) <span class="prep-required">*</span></label>
                                <div class="prep-dims-row">
                                    <input
                                        type="number"
                                        min="0"
                                        inputmode="decimal"
                                        class="field-input"
                                        v-model.number="packageDims.l"
                                        placeholder="L"
                                        @keydown="blockNonNumericKey"
                                        @paste="sanitizeNumericPaste"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        inputmode="decimal"
                                        class="field-input"
                                        v-model.number="packageDims.w"
                                        placeholder="W"
                                        @keydown="blockNonNumericKey"
                                        @paste="sanitizeNumericPaste"
                                    />
                                    <input
                                        type="number"
                                        min="0"
                                        inputmode="decimal"
                                        class="field-input"
                                        v-model.number="packageDims.h"
                                        placeholder="H"
                                        @keydown="blockNonNumericKey"
                                        @paste="sanitizeNumericPaste"
                                    />
                                </div>
                            </div>
                        </div>

                        <div class="sheet-field-row">
                            <div>
                                <label class="field-label">Courier / Carrier <span class="prep-required">*</span></label>
                                <select class="field-input" v-model="shippingCarrier">
                                    <option value="" disabled>
                                        {{ isLoadingLogisticsCompanies ? 'Loading couriers…' : 'Select a courier' }}
                                    </option>
                                    <option v-for="company in logisticsCompanies" :key="company.id" :value="company.name">
                                        {{ company.name }}
                                    </option>
                                    <!-- Keeps a draft's/order's previously saved carrier selectable even if
                                         it's since dropped off the active-companies list. -->
                                    <option
                                        v-if="shippingCarrier && !logisticsCompanies.some((c) => c.name === shippingCarrier)"
                                        :value="shippingCarrier"
                                    >
                                        {{ shippingCarrier }}
                                    </option>
                                </select>
                                <p v-if="!isLoadingLogisticsCompanies && logisticsCompanies.length === 0" class="field-hint">
                                    No active logistics partners on file yet.
                                </p>
                            </div>
                            <div>
                                <label class="field-label">Service Tier</label>
                                <select class="field-input" v-model="shippingService">
                                    <option value="Standard">Standard</option>
                                    <option value="Express">Express</option>
                                    <option value="Same-Day">Same-Day</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="field-label">Tracking Number</label>
                            <input
                                type="text"
                                class="field-input prep-tracking-input"
                                :value="trackingNumber || (isPreppingDispatch ? 'Generating…' : 'Assigned on dispatch')"
                                readonly
                            />
                            <p class="field-hint">
                                Generated automatically — this is what buyers
                                will see to track their order.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="prep-card-head" style="border-bottom: none">
                        <h3>Ready to Dispatch?</h3>
                    </div>
                    <p class="prep-dispatch-note">
                        Confirming dispatch moves this order to
                        <strong>In Transit</strong> and shares the tracking
                        details above with the buyer.
                    </p>
                    <button
                        class="btn-primary prep-dispatch-btn"
                        :disabled="!canDispatch"
                        :title="dispatchDisabledReason"
                        @click="confirmDispatch"
                    >
                        {{ isUpdatingStatus ? 'Dispatching…' : 'Confirm Dispatch' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
         DISPATCH REVIEW — the last look before the parcel goes out
         ================================================================ -->
        <div
            v-if="reviewOpen"
            class="modal-overlay"
            @click.self="reviewOpen = false"
        >
            <div class="modal-panel prep-review-panel">
                <div class="modal-header">
                    <h3>Review delivery details</h3>
                    <button class="modal-close" aria-label="Close" @click="reviewOpen = false">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>
                <p class="modal-desc">
                    Confirm everything below is correct. Dispatching moves
                    {{ order.id }} to <strong>In Transit</strong> and can't be
                    undone.
                </p>

                <div class="prep-review-section prep-review-section--qr">
                    <span class="prep-review-label">Parcel confirmation code</span>
                    <p class="prep-review-sub">
                        The courier scans this at pickup and again on delivery.
                        Print it and attach it to the parcel.
                    </p>
                    <div v-if="isPreppingDispatch" class="prep-review-qr-loading">
                        <div class="loading-spinner"></div>
                    </div>
                    <div
                        v-else-if="reviewQrPayload"
                        ref="reviewPosterRef"
                        class="prep-qr-poster prep-review-qr"
                    >
                        <p class="prep-qr-poster-shop">
                            {{ order.seller?.name || 'Store' }}
                        </p>
                        <p class="prep-qr-poster-order">Order {{ order.id }}</p>
                        <ParcelQrCode :value="reviewQrPayload" :size="176" />
                        <p v-if="trackingNumber" class="prep-qr-poster-track">
                            Tracking: {{ trackingNumber }}
                        </p>
                    </div>
                    <p v-else class="prep-review-sub">
                        The code isn't ready yet — you can still dispatch and
                        print it from the order afterwards.
                    </p>
                </div>

                <div class="prep-review-section">
                    <span class="prep-review-label">Shop</span>
                    <p class="prep-review-value">{{ order.seller?.name || 'Store' }}</p>
                    <p v-if="shopLocation" class="prep-review-sub">{{ shopLocation }}</p>
                </div>

                <div class="prep-review-section">
                    <span class="prep-review-label">Items</span>
                    <ul class="prep-review-items">
                        <li v-for="(item, idx) in order.items" :key="idx">
                            <span>{{ item.name }}<template v-if="item.variant"> · {{ item.variant }}</template></span>
                            <span class="prep-review-qty">× {{ item.qty }}</span>
                        </li>
                    </ul>
                </div>

                <div class="prep-review-section">
                    <span class="prep-review-label">Shipping configuration</span>
                    <dl class="prep-review-grid">
                        <div>
                            <dt>Weight</dt>
                            <dd>{{ packageWeight ? `${packageWeight} kg` : '—' }}</dd>
                        </div>
                        <div>
                            <dt>Dimensions</dt>
                            <dd>{{ dimensionsLabel }}</dd>
                        </div>
                        <div>
                            <dt>Courier</dt>
                            <dd>{{ shippingCarrier || '—' }}</dd>
                        </div>
                        <div>
                            <dt>Service tier</dt>
                            <dd>{{ shippingService || '—' }}</dd>
                        </div>
                        <div class="prep-review-grid-wide">
                            <dt>Tracking number</dt>
                            <dd>{{ trackingNumber || '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <p v-if="!allPacked" class="prep-dispatch-warning">
                    {{ totalItems - packedCount }} item(s) still marked as pending.
                </p>
                <p v-if="updateError" class="save-msg error">{{ updateError }}</p>

                <div class="modal-actions">
                    <button class="btn-outline" @click="reviewOpen = false">Back</button>
                    <button class="btn-outline" @click="printDeliveryDetails">
                        Print
                    </button>
                    <button
                        class="btn-primary"
                        :disabled="isUpdatingStatus"
                        @click="doDispatch"
                    >
                        {{ isUpdatingStatus ? 'Dispatching…' : 'Confirm & Dispatch' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ================================================================
         PARCEL QR — the confirmation code, shown after a successful dispatch
         ================================================================ -->
        <div v-if="qrOpen" class="modal-overlay">
            <div class="modal-panel prep-qr-panel">
                <div class="modal-header">
                    <h3>Parcel confirmation code</h3>
                </div>
                <p class="modal-desc">
                    Print this and attach it to the parcel. The courier scans
                    it to confirm each hand-off — at pickup, and again on
                    delivery.
                </p>

                <div ref="qrPosterRef" class="prep-qr-poster">
                    <p class="prep-qr-poster-shop">
                        {{ dispatchedOrder?.seller?.name || 'Store' }}
                    </p>
                    <p class="prep-qr-poster-order">Order {{ dispatchedOrder?.id }}</p>
                    <ParcelQrCode
                        v-if="qrPayload"
                        :value="qrPayload"
                        :size="200"
                    />
                    <p
                        v-if="dispatchedOrder?.shipping?.trackingNumber"
                        class="prep-qr-poster-track"
                    >
                        Tracking: {{ dispatchedOrder.shipping.trackingNumber }}
                    </p>
                </div>

                <div class="modal-actions">
                    <button class="btn-outline" @click="printDeliveryDetails">
                        Print
                    </button>
                    <button class="btn-primary" @click="finishDispatch">Done</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useOrders } from '../composables/useOrders';
import { printDeliveryDetails as printDeliveryDetailsDoc } from '../lib/deliveryDetailsPrint';
import ParcelQrCode from './ParcelQrCode.vue';

const props = defineProps({
    orderId: { type: String, default: null },
});

const {
    orders,
    isLoadingOrders,
    loadOrders,
    getOrderById,
    ensureDispatchPrep,
    statusBadgeClass,
    statusLabel,
    formatCurrency,
    isUpdatingStatus,
    updateError,
    shipOrder,
    logisticsCompanies,
    isLoadingLogisticsCompanies,
    loadLogisticsCompanies,
} = useOrders();

loadLogisticsCompanies();

// Any status between "accepted" and "handed to the courier" — a seller can
// jump straight from Confirmed to packing/dispatch (see Order::
// ALLOWED_TRANSITIONS) rather than being forced through Processing/Packed/
// Ready for Pickup one click at a time first.
const PREPARABLE_STATUSES = ['Confirmed', 'Processing', 'Packed', 'Ready for Pickup'];

const isLoading = ref(true);
const order = ref(null);

const packedState = ref({}); // { [itemIndex]: boolean } — local packing checklist
const trackingNumber = ref('');
const shippingCarrier = ref('');
const shippingService = ref('Standard');
const packageWeight = ref(null);
const packageDims = ref({ l: null, w: null, h: null });
const draftSavedAt = ref(null);

// Confirm-dispatch flow: review sheet (with the QR) -> dispatch -> parcel
// QR sheet (same code again, for printing after the fact).
const reviewOpen = ref(false);
const qrOpen = ref(false);
const dispatchedOrder = ref(null); // the full order returned by shipOrder()
const qrPosterRef = ref(null); // poster node in the post-dispatch sheet
const reviewPosterRef = ref(null); // poster node in the review sheet

// Dispatch identifiers assigned by the backend the moment an order is
// opened for preparation: the parcel QR payload (shown in the review sheet
// and reused post-dispatch) and the generated tracking number (shown
// read-only on the form — sellers never type it).
const reviewQrPayload = ref('');
const isPreppingDispatch = ref(false);

const shopLocation = computed(() => {
    const s = order.value?.seller;

    return s ? [s.city, s.province].filter(Boolean).join(', ') : '';
});

const dimensionsLabel = computed(() => {
    const d = packageDims.value;

    if (!d || (!d.l && !d.w && !d.h)) {
        return '—';
    }

    return `${d.l || '?'} × ${d.w || '?'} × ${d.h || '?'} cm`;
});

// The string the seller SPA encodes into the parcel QR. Same value in the
// review sheet and the post-dispatch sheet — the backend hands back the
// order's existing token rather than minting a second one.
const qrPayload = computed(
    () => dispatchedOrder.value?.dispatch?.qrPayload || reviewQrPayload.value || '',
);

// Runs once `order.value` is populated (from cache or a fresh fetch):
// seeds the local packing checklist, restores any saved draft, shows a
// known tracking number, and kicks off dispatch-prep in the background.
function afterOrderLoaded() {
    packedState.value = Object.fromEntries(
        order.value.items.map((_, idx) => [idx, false]),
    );
    draftSavedAt.value = null;
    loadDraft();
    // If the order already carries a tracking number (e.g. resumed
    // later), show that; otherwise ask the backend to assign one. The
    // cached list summary carries it flat; the full detail nests it
    // under `shipping` — check both.
    trackingNumber.value = order.value.shipping?.trackingNumber || order.value.trackingNumber || '';
    prepareDispatch();
}

async function loadOrder() {
    reviewOpen.value = false;
    qrOpen.value = false;
    dispatchedOrder.value = null;
    reviewQrPayload.value = '';
    trackingNumber.value = '';

    if (!props.orderId) {
        // No specific order — e.g. the seller clicked "Prepare Orders" in
        // the sidebar directly, rather than a specific order's "Prepare
        // Shipment" button. Show the picker shell immediately; its own
        // isLoadingOrders spinner covers the (not awaited) list fetch
        // instead of blocking this whole component behind it.
        order.value = null;
        isLoading.value = false;

        if (!orders.value.length) {
            loadOrders();
        }

        return;
    }

    // Instant paint: if this order is already in the cached list (the
    // seller got here from the Orders list, Courier Handover, or this
    // page's own picker — all backed by the same module-scoped `orders`
    // ref), seed the page from that summary right away instead of making
    // the seller wait on a network round trip for data we already have.
    // The summary already carries items/status/customer in full.
    const cached = orders.value.find((o) => o.id === props.orderId);

    if (cached) {
        order.value = { ...cached };
        isLoading.value = false;
        afterOrderLoaded();

        // Backfill the detail-only fields the summary doesn't carry
        // (seller/shop info) in the background — never blocks the page.
        // Tracking is skipped; this view never renders it.
        getOrderById(props.orderId, { includeJourney: false }).then((full) => {
            if (full && order.value?.id === props.orderId) {
                order.value = { ...order.value, ...full };
            }
        });

        return;
    }

    // Cold path (e.g. a direct link) — nothing cached, so show the
    // skeleton while fetching. Tracking is skipped; this view never
    // renders it and it's the priciest part of the response.
    isLoading.value = true;
    order.value = await getOrderById(props.orderId, { includeJourney: false });
    isLoading.value = false;

    if (order.value) {
        afterOrderLoaded();
    }
}

onMounted(loadOrder);

// Orders a seller has already accepted and can pack/dispatch.
const preparableOrders = computed(() =>
    orders.value.filter((o) => PREPARABLE_STATUSES.includes(o.status)),
);

function selectOrder(id) {
    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'prepareOrders', orderId: id },
        }),
    );
}

watch(() => props.orderId, loadOrder);

const canPrepare = computed(() => order.value && PREPARABLE_STATUSES.includes(order.value.status));

const totalItems = computed(() => order.value?.items.length || 0);
const packedCount = computed(
    () => Object.values(packedState.value).filter(Boolean).length,
);
const allPacked = computed(
    () => totalItems.value > 0 && packedCount.value === totalItems.value,
);
const packProgressPct = computed(() =>
    totalItems.value > 0 ? Math.round((packedCount.value / totalItems.value) * 100) : 0,
);

function togglePacked(idx) {
    packedState.value = { ...packedState.value, [idx]: !packedState.value[idx] };
}

// Package weight, dimensions and courier are required before dispatch —
// the buyer-facing shipment record needs them, and there's no sane
// default to fall back on for any of the three.
const shippingConfigComplete = computed(() => {
    const d = packageDims.value || {};

    return (
        Number(packageWeight.value) > 0 &&
        Number(d.l) > 0 &&
        Number(d.w) > 0 &&
        Number(d.h) > 0 &&
        shippingCarrier.value.trim().length > 0
    );
});

const canDispatch = computed(
    () =>
        canPrepare.value &&
        trackingNumber.value.trim().length > 0 &&
        shippingConfigComplete.value &&
        !isUpdatingStatus.value,
);
const dispatchDisabledReason = computed(() => {
    if (isUpdatingStatus.value) return '';
    if (!trackingNumber.value.trim()) {
        return isPreppingDispatch.value
            ? 'Preparing this shipment…'
            : 'This shipment isn\'t ready to dispatch yet.';
    }

    if (!shippingConfigComplete.value) {
        const d = packageDims.value || {};
        const missing = [];

        if (!(Number(packageWeight.value) > 0)) {
            missing.push('weight');
        }

        if (!(Number(d.l) > 0 && Number(d.w) > 0 && Number(d.h) > 0)) {
            missing.push('dimensions');
        }

        if (!shippingCarrier.value.trim()) {
            missing.push('courier');
        }

        return `Enter the package ${missing.join(', ')} before dispatching.`;
    }

    return '';
});

// ---- local draft (this device only) ----
// There's no backend field for "packing progress" or a shipment draft —
// this is a convenience so a seller can leave mid-pack and come back
// without losing their checklist. Nothing here is sent to the server
// until Confirm Dispatch actually calls shipOrder().
const draftKey = computed(() => (order.value ? `nexmart:prepare-draft:${order.value.id}` : null));

function loadDraft() {
    if (!draftKey.value) return;

    try {
        const raw = window.localStorage.getItem(draftKey.value);

        if (!raw) return;

        const draft = JSON.parse(raw);
        packedState.value = { ...packedState.value, ...(draft.packedState || {}) };
        // trackingNumber is assigned by the backend, not drafted here.
        shippingCarrier.value = draft.shippingCarrier || '';
        shippingService.value = draft.shippingService || 'Standard';
        packageWeight.value = draft.packageWeight ?? null;
        packageDims.value = draft.packageDims || { l: null, w: null, h: null };
    } catch {
        // Corrupt/old draft — ignore and start fresh.
    }
}

function saveDraft() {
    if (!draftKey.value) return;

    window.localStorage.setItem(
        draftKey.value,
        JSON.stringify({
            packedState: packedState.value,
            shippingCarrier: shippingCarrier.value,
            shippingService: shippingService.value,
            packageWeight: packageWeight.value,
            packageDims: packageDims.value,
        }),
    );
    draftSavedAt.value = new Date();
}

function clearDraft() {
    if (draftKey.value) {
        window.localStorage.removeItem(draftKey.value);
    }
}

// Ask the backend to assign this order's dispatch identifiers (parcel QR
// token + tracking number). Runs once when the order is opened; safe to
// call again as a retry if the first attempt failed.
async function prepareDispatch() {
    if ((reviewQrPayload.value && trackingNumber.value) || !order.value) {
        return;
    }

    isPreppingDispatch.value = true;

    const res = await ensureDispatchPrep(order.value.id);

    isPreppingDispatch.value = false;

    if (res) {
        reviewQrPayload.value = res.qrPayload || reviewQrPayload.value;
        trackingNumber.value = res.trackingNumber || trackingNumber.value;
    }
}

// "Confirm Dispatch" opens the review sheet rather than dispatching
// straight away — the seller checks the QR, shop, items and shipping
// summary (and the still-pending-items warning) there before committing.
function confirmDispatch() {
    if (!canDispatch.value) return;

    reviewOpen.value = true;
    prepareDispatch();
}

// Keep the weight / dimension boxes to digits (and a single decimal
// point). type="number" already ignores letters on most browsers; this
// covers "e"/"+"/"-" and pasted text so nothing but a number gets in.
function blockNonNumericKey(event) {
    if (['e', 'E', '+', '-'].includes(event.key)) {
        event.preventDefault();
    }
}

function sanitizeNumericPaste(event) {
    const text = (event.clipboardData || window.clipboardData)?.getData('text') ?? '';

    if (!/^\d*\.?\d*$/.test(text.trim())) {
        event.preventDefault();
    }
}

async function doDispatch() {
    if (!canDispatch.value) return;

    const updated = await shipOrder(order.value.id, {
        tracking_number: trackingNumber.value.trim(),
        shipping_carrier: shippingCarrier.value.trim() || null,
        shipping_service: shippingService.value || null,
    });

    // shipOrder() swallows the error and surfaces it via updateError —
    // keep the review sheet open so the seller sees it and can retry.
    if (!updated) return;

    clearDraft();
    dispatchedOrder.value = updated;
    reviewOpen.value = false;

    // Show the parcel QR the courier will scan at each checkpoint. If the
    // backend didn't return one, just go straight to the order.
    if (updated.dispatch?.qrPayload) {
        qrOpen.value = true;
    } else {
        goToOrderDetails();
    }
}

function finishDispatch() {
    qrOpen.value = false;
    goToOrderDetails();
}

// Print the full delivery summary — shop, items, shipping config, tracking
// number and the parcel QR. Shared with Order Details (see
// lib/deliveryDetailsPrint.js) so it can be reprinted after the order has
// left this list. Weight/dimensions live only on this screen, so pass
// them through as extras.
function printDeliveryDetails() {
    const o = dispatchedOrder.value || order.value;

    if (!o) {
        return;
    }

    printDeliveryDetailsDoc(o, {
        weight: packageWeight.value,
        dims: packageDims.value,
    });
}

function goTo(section) {
    window.dispatchEvent(new CustomEvent('seller-nav', { detail: { section } }));
}

function goToOrderDetails() {
    if (!order.value) {
        goTo('orders');

        return;
    }

    window.dispatchEvent(
        new CustomEvent('seller-nav', {
            detail: { section: 'orderDetails', orderId: order.value.id },
        }),
    );
}
</script>
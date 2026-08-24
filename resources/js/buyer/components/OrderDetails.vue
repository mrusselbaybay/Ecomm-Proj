<script setup>
import { computed, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import ReviewModal from './ReviewModal.vue';
import ReturnRequestModal from './ReturnRequestModal.vue';

const props = defineProps({
    order: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['back']);

const {
    ORDER_STATUSES,
    cancelOrder,
    submitReview,
    submitReturnRequest
} = useBuyer();

// OUT_FOR_DELIVERY is an alias of IN_TRANSIT in ORDER_STATUSES (see
// useBuyer.js — there's no separate DB state for it yet), so it's left
// out here to avoid a duplicate step in the progress bar.
const trackingSteps = [
    ORDER_STATUSES.TO_SHIP,
    ORDER_STATUSES.PROCESSING,
    ORDER_STATUSES.IN_TRANSIT,
    ORDER_STATUSES.DELIVERED
];

const currentTrackingIndex = computed(() => {
    if (!props.order) {
        return -1;
    }

    return trackingSteps.indexOf(
        props.order.status
    );
});

const canCancelOrder = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.TO_SHIP
    );
});

const canReviewOrder = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.DELIVERED
    );
});

const canRequestReturn = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.DELIVERED
    );
});

const isCancelled = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.CANCELLED
    );
});

// RETURNED is an alias of CANCELLED in ORDER_STATUSES (there's no
// returns subsystem yet — see submitReturnRequest() in useBuyer.js), so
// this always mirrors isCancelled for now.
const isReturned = computed(() => false);

const deliveryAddress = computed(() => {
    return props.order?.delivery_address || {};
});

const isReviewModalOpen = ref(false);
const selectedReviewItem = ref(null);
const selectedReviewItemIndex = ref(-1);

const isReturnModalOpen = ref(false);
const selectedReturnItem = ref(null);
const selectedReturnItemIndex = ref(-1);

function formatPrice(price) {
    return `₱${Number(price || 0).toFixed(2)}`;
}

function formatDate(date) {
    if (!date) {
        return 'No date available';
    }

    return new Date(date).toLocaleString();
}

function formatPaymentMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'cod') {
        return 'Cash on Delivery';
    }

    if (method === 'gcash') {
        return 'GCash';
    }

    if (method === 'card') {
        return 'Credit / Debit Card';
    }

    return method;
}

function formatShippingMethod(method) {
    if (!method) {
        return 'Not specified';
    }

    if (method === 'standard') {
        return 'Standard Delivery';
    }

    if (method === 'express') {
        return 'Express Delivery';
    }

    return method;
}

function formatReturnType(type) {
    if (type === 'return_and_refund') {
        return 'Return and Refund';
    }

    if (type === 'refund_only') {
        return 'Refund Only';
    }

    return type || 'Not specified';
}

function formatReturnReason(reason) {
    const reasonLabels = {
        damaged: 'Product arrived damaged',
        wrong_item: 'Wrong product received',
        incomplete: 'Missing parts or items',
        not_as_described:
            'Product is not as described',
        quality_issue: 'Product quality issue',
        other: 'Other reason'
    };

    return reasonLabels[reason] ||
        reason ||
        'Not specified';
}

function returnStatusClass(status) {
    return `return-status-${String(
        status || 'pending'
    ).toLowerCase()}`;
}

function isTrackingStepCompleted(index) {
    return currentTrackingIndex.value >= index;
}

function getItemPrice(item) {
    return Number(
        item.unit_price ??
        item.price ??
        0
    );
}

async function handleCancelOrder() {
    if (!props.order) {
        return;
    }

    if (!canCancelOrder.value) {
        alert(
            'This order can no longer be cancelled.'
        );
        return;
    }

    const confirmed = window.confirm(
        'Are you sure you want to cancel this order?'
    );

    if (!confirmed) {
        return;
    }

    const cancelled = await cancelOrder(
        props.order.orderId,
        'Cancelled by buyer'
    );

    if (!cancelled) {
        alert('Unable to cancel this order right now \u2014 please contact the seller.');
        return;
    }

    alert('Order cancelled successfully.');
}

function openReviewModal(item, index) {
    if (
        !canReviewOrder.value ||
        item.review
    ) {
        return;
    }

    selectedReviewItem.value = item;
    selectedReviewItemIndex.value = index;
    isReviewModalOpen.value = true;
}

function closeReviewModal() {
    isReviewModalOpen.value = false;
    selectedReviewItem.value = null;
    selectedReviewItemIndex.value = -1;
}

function handleReviewSubmit(reviewData) {
    if (
        !props.order ||
        selectedReviewItemIndex.value < 0
    ) {
        return;
    }

    const review = submitReview(
        props.order.orderId,
        selectedReviewItemIndex.value,
        reviewData
    );

    if (!review) {
        alert(
            'Unable to submit this review. The product may already be reviewed or the order is not yet delivered.'
        );
        return;
    }

    closeReviewModal();
    alert('Review submitted successfully.');
}

function openReturnModal(item, index) {
    if (
        !canRequestReturn.value ||
        item.returnRequest
    ) {
        return;
    }

    selectedReturnItem.value = item;
    selectedReturnItemIndex.value = index;
    isReturnModalOpen.value = true;
}

function closeReturnModal() {
    isReturnModalOpen.value = false;
    selectedReturnItem.value = null;
    selectedReturnItemIndex.value = -1;
}

function handleReturnSubmit(requestData) {
    if (
        !props.order ||
        selectedReturnItemIndex.value < 0
    ) {
        return;
    }

    const returnRequest = submitReturnRequest(
        props.order.orderId,
        selectedReturnItemIndex.value,
        requestData
    );

    if (!returnRequest) {
        alert(
            'Unable to submit this request. The product may already have a request or the order is not eligible.'
        );
        return;
    }

    closeReturnModal();
    alert(
        'Return / refund request submitted successfully.'
    );
}
</script>

<template>
    <div
        v-if="order"
        class="order-details-page"
    >
        <header class="order-details-header">
            <button
                type="button"
                class="order-details-back-button"
                @click="emit('back')"
            >
                Back to Orders
            </button>

            <div>
                <h1>Order Details</h1>
                <p>Order {{ order.orderId }}</p>
            </div>
        </header>

        <main class="order-details-content">
            <section class="order-details-section">
                <div class="order-details-heading">
                    <div>
                        <span>Order ID</span>
                        <h2>{{ order.orderId }}</h2>
                        <p>{{ formatDate(order.createdAt) }}</p>
                    </div>

                    <span class="order-details-status">
                        {{ order.status }}
                    </span>
                </div>
            </section>

            <section class="order-details-section">
                <h2>Order Tracking</h2>

                <div
                    v-if="!isCancelled && !isReturned"
                    class="order-tracking"
                >
                    <div
                        v-for="(step, index) in trackingSteps"
                        :key="step"
                        class="tracking-step"
                        :class="{
                            completed:
                                isTrackingStepCompleted(index)
                        }"
                    >
                        <div class="tracking-marker">
                            {{ index + 1 }}
                        </div>

                        <div class="tracking-information">
                            <strong>{{ step }}</strong>
                            <span v-if="order.status === step">
                                Current status
                            </span>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="isCancelled"
                    class="order-special-status cancelled-order-status"
                >
                    <strong>Order Cancelled</strong>
                    <p>This order has been cancelled.</p>
                    <p v-if="order.cancellationReason">
                        Reason: {{ order.cancellationReason }}
                    </p>
                    <p v-if="order.cancelledAt">
                        Cancelled:
                        {{ formatDate(order.cancelledAt) }}
                    </p>
                </div>

                <div
                    v-else-if="isReturned"
                    class="order-special-status"
                >
                    <strong>Order Returned</strong>
                    <p>This order has been returned.</p>
                </div>
            </section>

            <section class="order-details-section">
                <h2>Delivery Address</h2>

                <div class="delivery-details">
                    <strong>
                        {{
                            deliveryAddress.recipient_name ||
                            'Recipient not available'
                        }}
                    </strong>
                    <p>
                        {{
                            deliveryAddress.contact_number ||
                            'No contact number'
                        }}
                    </p>
                    <p>
                        {{
                            deliveryAddress.address ||
                            'No address available'
                        }}
                    </p>
                </div>
            </section>

            <section class="order-details-section">
                <h2>Products</h2>

                <div
                    v-for="(item, index) in order.items"
                    :key="`${order.orderId}-${index}`"
                    class="order-details-product"
                >
                    <div class="order-details-product-image">
                        Product Image
                    </div>

                    <div class="order-details-product-info">
                        <h3>
                            {{
                                item.name ||
                                `Product #${
                                    item.product_id ||
                                    item.productId
                                }`
                            }}
                        </h3>
                        <p>
                            Seller:
                            {{ item.seller || 'NEXMART Seller' }}
                        </p>
                        <p v-if="item.category">
                            Category: {{ item.category }}
                        </p>
                        <p>
                            Variation:
                            {{ item.variation || 'Default' }}
                        </p>
                        <p>Quantity: {{ item.quantity }}</p>
                    </div>

                    <div class="order-details-product-price">
                        <span>
                            Unit Price:
                            {{ formatPrice(getItemPrice(item)) }}
                        </span>
                        <strong>
                            {{
                                formatPrice(
                                    getItemPrice(item) *
                                    Number(item.quantity)
                                )
                            }}
                        </strong>
                    </div>

                    <div
                        v-if="canReviewOrder"
                        class="order-product-review"
                    >
                        <div
                            v-if="item.review"
                            class="submitted-review"
                        >
                            <div class="submitted-review-heading">
                                <strong>Your review</strong>
                                <div
                                    class="submitted-review-stars"
                                    :aria-label="`${item.review.rating} out of 5 stars`"
                                >
                                    <span
                                        v-for="star in 5"
                                        :key="star"
                                        :class="{
                                            active:
                                                star <= item.review.rating
                                        }"
                                    >
                                        &#9733;
                                    </span>
                                </div>
                            </div>

                            <p v-if="item.review.comment">
                                {{ item.review.comment }}
                            </p>
                            <p v-else class="submitted-review-empty">
                                No written comment was added.
                            </p>
                            <small>
                                Submitted
                                {{ formatDate(item.review.createdAt) }}
                            </small>
                        </div>

                        <button
                            v-else
                            type="button"
                            class="rate-product-button"
                            @click="openReviewModal(item, index)"
                        >
                            Rate Product
                        </button>
                    </div>

                    <div
                        v-if="
                            canRequestReturn ||
                            item.returnRequest
                        "
                        class="order-product-return"
                    >
                        <article
                            v-if="item.returnRequest"
                            class="submitted-return-request"
                        >
                            <div class="submitted-return-heading">
                                <div>
                                    <span>Return / Refund Request</span>
                                    <strong>
                                        {{
                                            formatReturnType(
                                                item.returnRequest.requestType
                                            )
                                        }}
                                    </strong>
                                </div>

                                <span
                                    class="return-request-status"
                                    :class="
                                        returnStatusClass(
                                            item.returnRequest.status
                                        )
                                    "
                                >
                                    {{ item.returnRequest.status }}
                                </span>
                            </div>

                            <div class="submitted-return-grid">
                                <div>
                                    <span>Reason</span>
                                    <strong>
                                        {{
                                            formatReturnReason(
                                                item.returnRequest.reason
                                            )
                                        }}
                                    </strong>
                                </div>
                                <div>
                                    <span>Quantity</span>
                                    <strong>
                                        {{ item.returnRequest.quantity }}
                                    </strong>
                                </div>
                                <div>
                                    <span>Evidence</span>
                                    <strong>
                                        {{
                                            item.returnRequest.evidence?.length ||
                                            0
                                        }}
                                        image(s)
                                    </strong>
                                </div>
                            </div>

                            <p>
                                {{ item.returnRequest.details }}
                            </p>

                            <small>
                                Submitted
                                {{
                                    formatDate(
                                        item.returnRequest.submittedAt
                                    )
                                }}
                            </small>
                        </article>

                        <button
                            v-else
                            type="button"
                            class="request-return-button"
                            @click="openReturnModal(item, index)"
                        >
                            Return / Refund
                        </button>
                    </div>
                </div>
            </section>

            <section class="order-details-section">
                <h2>Payment &amp; Shipping</h2>

                <div class="order-details-info-grid">
                    <div class="order-details-info">
                        <span>Payment Method</span>
                        <strong>
                            {{
                                formatPaymentMethod(
                                    order.payment_method
                                )
                            }}
                        </strong>
                    </div>

                    <div class="order-details-info">
                        <span>Shipping Method</span>
                        <strong>
                            {{
                                formatShippingMethod(
                                    order.shipping_method
                                )
                            }}
                        </strong>
                    </div>

                    <div
                        v-if="order.voucher_code"
                        class="order-details-info"
                    >
                        <span>Voucher</span>
                        <strong>{{ order.voucher_code }}</strong>
                    </div>
                </div>
            </section>

            <section class="order-details-summary">
                <h2>Payment Summary</h2>

                <div class="order-summary-row">
                    <span>Merchandise Subtotal</span>
                    <span>{{ formatPrice(order.subtotal) }}</span>
                </div>

                <div class="order-summary-row">
                    <span>Shipping Fee</span>
                    <span>{{ formatPrice(order.shipping_fee) }}</span>
                </div>

                <div
                    v-if="Number(order.discount) > 0"
                    class="order-summary-row order-summary-discount"
                >
                    <span>Voucher Discount</span>
                    <span>-{{ formatPrice(order.discount) }}</span>
                </div>

                <div class="order-summary-row order-summary-total">
                    <span>Total Payment</span>
                    <strong>{{ formatPrice(order.total) }}</strong>
                </div>
            </section>

            <section
                v-if="canCancelOrder"
                class="order-details-actions"
            >
                <div class="order-cancel-information">
                    <strong>Need to cancel this order?</strong>
                    <p>
                        You can cancel the order while it has not yet been shipped.
                    </p>
                </div>

                <button
                    type="button"
                    class="cancel-order-button"
                    @click="handleCancelOrder"
                >
                    Cancel Order
                </button>
            </section>
        </main>

        <ReviewModal
            :show="isReviewModalOpen"
            :item="selectedReviewItem"
            :order-id="order.orderId"
            @close="closeReviewModal"
            @submit="handleReviewSubmit"
        />

        <ReturnRequestModal
            :show="isReturnModalOpen"
            :item="selectedReturnItem"
            :order-id="order.orderId"
            @close="closeReturnModal"
            @submit="handleReturnSubmit"
        />
    </div>

    <div
        v-else
        class="order-details-empty"
    >
        <h2>Order not found</h2>
        <button
            type="button"
            @click="emit('back')"
        >
            Back to Orders
        </button>
    </div>
</template>
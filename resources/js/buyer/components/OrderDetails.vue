<script setup>
import { computed } from 'vue';
import { useBuyer } from '../composables/useBuyer';

const props = defineProps({
    order: {
        type: Object,
        default: null
    }
});

const emit = defineEmits([
    'back'
]);

const {
    ORDER_STATUSES,
    cancelOrder
} = useBuyer();
/*
|--------------------------------------------------------------------------
| Tracking Steps
|--------------------------------------------------------------------------
*/

const trackingSteps = [
    ORDER_STATUSES.TO_SHIP,
    ORDER_STATUSES.IN_TRANSIT,
    ORDER_STATUSES.OUT_FOR_DELIVERY,
    ORDER_STATUSES.DELIVERED
];

/*
|--------------------------------------------------------------------------
| Current Tracking Step
|--------------------------------------------------------------------------
*/

const currentTrackingIndex = computed(() => {
    if (!props.order) {
        return -1;
    }

    return trackingSteps.indexOf(
        props.order.status
    );
});
/*
|--------------------------------------------------------------------------
| Can Cancel Order
|--------------------------------------------------------------------------
*/

const canCancelOrder = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.TO_SHIP
    );
});

/*
|--------------------------------------------------------------------------
| Cancelled / Returned
|--------------------------------------------------------------------------
*/

const isCancelled = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.CANCELLED
    );
});

const isReturned = computed(() => {
    return (
        props.order?.status ===
        ORDER_STATUSES.RETURNED
    );
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

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

function isTrackingStepCompleted(index) {
    return (
        currentTrackingIndex.value >= index
    );
}

function getItemPrice(item) {
    return Number(
        item.unit_price ??
        item.price ??
        0
    );
}

/*
|--------------------------------------------------------------------------
| Delivery Address
|--------------------------------------------------------------------------
*/

const deliveryAddress = computed(() => {
    return (
        props.order?.delivery_address ||
        {}
    );
});

/*
|--------------------------------------------------------------------------
| Cancel Order
|--------------------------------------------------------------------------
*/

function handleCancelOrder() {
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

    const cancelled = cancelOrder(
        props.order.orderId,
        'Cancelled by buyer'
    );

    if (!cancelled) {
        alert(
            'Unable to cancel this order.'
        );

        return;
    }

    alert(
        'Order cancelled successfully.'
    );
}
</script>

<template>

    <!-- ================================================================ -->
    <!-- ORDER DETAILS -->
    <!-- ================================================================ -->

    <div
        v-if="order"
        class="order-details-page"
    >

        <!-- ============================================================ -->
        <!-- HEADER -->
        <!-- ============================================================ -->

        <header class="order-details-header">

            <button
                type="button"
                class="order-details-back-button"
                @click="emit('back')"
            >
                Back to Orders
            </button>

            <div>

                <h1>
                    Order Details
                </h1>

                <p>
                    Order {{ order.orderId }}
                </p>

            </div>

        </header>

        <!-- ============================================================ -->
        <!-- MAIN CONTENT -->
        <!-- ============================================================ -->

        <main class="order-details-content">

            <!-- ======================================================== -->
            <!-- ORDER STATUS -->
            <!-- ======================================================== -->

            <section class="order-details-section">

                <div class="order-details-heading">

                    <div>

                        <span>
                            Order ID
                        </span>

                        <h2>
                            {{ order.orderId }}
                        </h2>

                        <p>
                            {{ formatDate(order.createdAt) }}
                        </p>

                    </div>

                    <span class="order-details-status">
                        {{ order.status }}
                    </span>

                </div>

            </section>


            <!-- ======================================================== -->
            <!-- ORDER TRACKING -->
            <!-- ======================================================== -->

            <section class="order-details-section">

                <h2>
                    Order Tracking
                </h2>

                <!-- Normal Order Flow -->

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

                            <strong>
                                {{ step }}
                            </strong>

                            <span
                                v-if="order.status === step"
                            >
                                Current status
                            </span>

                        </div>

                    </div>

                </div>


                <!-- Cancelled Order -->

                <div
                    v-else-if="isCancelled"
                    class="order-special-status cancelled-order-status"
                >

                    <strong>
                        Order Cancelled
                    </strong>

                    <p>
                        This order has been cancelled.
                    </p>

                    <p
                        v-if="order.cancellationReason"
                    >
                        Reason:
                        {{ order.cancellationReason }}
                    </p>

                    <p
                        v-if="order.cancelledAt"
                    >
                        Cancelled:
                        {{ formatDate(order.cancelledAt) }}
                    </p>

                </div>


                <!-- Returned Order -->

                <div
                    v-else-if="isReturned"
                    class="order-special-status"
                >

                    <strong>
                        Order Returned
                    </strong>

                    <p>
                        This order has been returned.
                    </p>

                </div>

            </section>


            <!-- ======================================================== -->
            <!-- DELIVERY ADDRESS -->
            <!-- ======================================================== -->

            <section class="order-details-section">

                <h2>
                    Delivery Address
                </h2>

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


            <!-- ======================================================== -->
            <!-- PRODUCTS -->
            <!-- ======================================================== -->

            <section class="order-details-section">

                <h2>
                    Products
                </h2>

                <div
                    v-for="(item, index) in order.items"
                    :key="`${order.orderId}-${index}`"
                    class="order-details-product"
                >

                    <!-- Product Image -->

                    <div class="order-details-product-image">
                        Product Image
                    </div>


                    <!-- Product Info -->

                    <div class="order-details-product-info">

                        <h3>
                            {{
                                item.name ||
                                `Product #${item.product_id || item.productId}`
                            }}
                        </h3>

                        <p>
                            Seller:
                            {{
                                item.seller ||
                                'NEXMART Seller'
                            }}
                        </p>

                        <p
                            v-if="item.category"
                        >
                            Category:
                            {{ item.category }}
                        </p>

                        <p>
                            Variation:
                            {{
                                item.variation ||
                                'Default'
                            }}
                        </p>

                        <p>
                            Quantity:
                            {{ item.quantity }}
                        </p>

                    </div>


                    <!-- Product Price -->

                    <div class="order-details-product-price">

                        <span>
                            Unit Price:
                            {{
                                formatPrice(
                                    getItemPrice(item)
                                )
                            }}
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

                </div>

            </section>


            <!-- ======================================================== -->
            <!-- PAYMENT & SHIPPING -->
            <!-- ======================================================== -->

            <section class="order-details-section">

                <h2>
                    Payment & Shipping
                </h2>

                <div class="order-details-info-grid">

                    <!-- Payment -->

                    <div class="order-details-info">

                        <span>
                            Payment Method
                        </span>

                        <strong>
                            {{
                                formatPaymentMethod(
                                    order.payment_method
                                )
                            }}
                        </strong>

                    </div>


                    <!-- Shipping -->

                    <div class="order-details-info">

                        <span>
                            Shipping Method
                        </span>

                        <strong>
                            {{
                                formatShippingMethod(
                                    order.shipping_method
                                )
                            }}
                        </strong>

                    </div>


                    <!-- Voucher -->

                    <div
                        v-if="order.voucher_code"
                        class="order-details-info"
                    >

                        <span>
                            Voucher
                        </span>

                        <strong>
                            {{ order.voucher_code }}
                        </strong>

                    </div>

                </div>

            </section>


            <!-- ======================================================== -->
            <!-- PAYMENT SUMMARY -->
            <!-- ======================================================== -->

            <section class="order-details-summary">

                <h2>
                    Payment Summary
                </h2>


                <!-- Subtotal -->

                <div class="order-summary-row">

                    <span>
                        Merchandise Subtotal
                    </span>

                    <span>
                        {{
                            formatPrice(
                                order.subtotal
                            )
                        }}
                    </span>

                </div>


                <!-- Shipping -->

                <div class="order-summary-row">

                    <span>
                        Shipping Fee
                    </span>

                    <span>
                        {{
                            formatPrice(
                                order.shipping_fee
                            )
                        }}
                    </span>

                </div>


                <!-- Discount -->

                <div
                    v-if="Number(order.discount) > 0"
                    class="
                        order-summary-row
                        order-summary-discount
                    "
                >

                    <span>
                        Voucher Discount
                    </span>

                    <span>
                        -{{ formatPrice(order.discount) }}
                    </span>

                </div>


                <!-- Total -->

                <div
                    class="
                        order-summary-row
                        order-summary-total
                    "
                >

                    <span>
                        Total Payment
                    </span>

                    <strong>
                        {{
                            formatPrice(
                                order.total
                            )
                        }}
                    </strong>

                </div>

            </section>


            <!-- ======================================================== -->
            <!-- ORDER ACTIONS -->
            <!-- ======================================================== -->

            <section
                v-if="canCancelOrder"
                class="order-details-actions"
            >

                <div class="order-cancel-information">

                    <strong>
                        Need to cancel this order?
                    </strong>

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

    </div>


    <!-- ================================================================ -->
    <!-- ORDER NOT FOUND -->
    <!-- ================================================================ -->

    <div
        v-else
        class="order-details-empty"
    >

        <h2>
            Order not found
        </h2>

        <button
            type="button"
            @click="emit('back')"
        >
            Back to Orders
        </button>

    </div>

</template>
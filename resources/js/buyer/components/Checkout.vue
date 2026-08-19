<script setup>
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'back',
    'place-order'
]);

/*
|--------------------------------------------------------------------------
| Checkout Form
|--------------------------------------------------------------------------
|
| Later, these values can be loaded from the buyer profile/database.
|
*/

const checkoutForm = reactive({
    recipientName: '',
    contactNumber: '',
    address: '',
    shippingMethod: 'standard',
    paymentMethod: 'cod'
});

/*
|--------------------------------------------------------------------------
| Voucher
|--------------------------------------------------------------------------
*/

const voucherCode = ref('');
const appliedVoucher = ref(null);

/*
|--------------------------------------------------------------------------
| Shipping Options
|--------------------------------------------------------------------------
*/

const shippingOptions = [
    {
        id: 'standard',
        name: 'Standard Delivery',
        description: 'Estimated 3-5 days',
        fee: 60
    },
    {
        id: 'express',
        name: 'Express Delivery',
        description: 'Estimated 1-2 days',
        fee: 120
    }
];

/*
|--------------------------------------------------------------------------
| Payment Methods
|--------------------------------------------------------------------------
|
| These are selection options only.
| No real payment information is being processed yet.
|
*/

const paymentMethods = [
    {
        id: 'cod',
        name: 'Cash on Delivery',
        description: 'Pay when your order arrives.'
    },
    {
        id: 'gcash',
        name: 'GCash',
        description: 'Pay using your GCash account.'
    },
    {
        id: 'card',
        name: 'Credit / Debit Card',
        description: 'Pay using a supported card.'
    }
];

/*
|--------------------------------------------------------------------------
| Subtotal
|--------------------------------------------------------------------------
*/

const subtotal = computed(() => {
    return props.items.reduce(
        (total, item) =>
            total +
            Number(item.price) *
            Number(item.quantity),
        0
    );
});

/*
|--------------------------------------------------------------------------
| Shipping Fee
|--------------------------------------------------------------------------
*/

const shippingFee = computed(() => {
    const selected = shippingOptions.find(
        option =>
            option.id === checkoutForm.shippingMethod
    );

    return selected
        ? Number(selected.fee)
        : 0;
});

/*
|--------------------------------------------------------------------------
| Discount
|--------------------------------------------------------------------------
|
| Temporary mock voucher:
|
| NEXMART10 = 10% discount
|
| Later, applyVoucher() can call your Laravel/Supabase API instead.
|
*/

const discount = computed(() => {
    if (!appliedVoucher.value) {
        return 0;
    }

    if (appliedVoucher.value.code === 'NEXMART10') {
        return subtotal.value * 0.10;
    }

    return 0;
});

/*
|--------------------------------------------------------------------------
| Total
|--------------------------------------------------------------------------
*/

const total = computed(() => {
    return Math.max(
        subtotal.value +
        shippingFee.value -
        discount.value,
        0
    );
});

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

function formatPrice(price) {
    return `₱${Number(price).toFixed(2)}`;
}

/*
|--------------------------------------------------------------------------
| Voucher
|--------------------------------------------------------------------------
*/

function applyVoucher() {
    const code = voucherCode.value
        .trim()
        .toUpperCase();

    if (!code) {
        alert('Please enter a voucher code.');
        return;
    }

    if (code === 'NEXMART10') {
        appliedVoucher.value = {
            code: 'NEXMART10'
        };

        alert('Voucher applied successfully.');
        return;
    }

    appliedVoucher.value = null;

    alert('Invalid voucher code.');
}

function removeVoucher() {
    voucherCode.value = '';
    appliedVoucher.value = null;
}

/*
|--------------------------------------------------------------------------
| Place Order
|--------------------------------------------------------------------------
|
| This creates one clean object that can later be sent to Laravel:
|
| POST /buyer/orders
|
*/

function placeOrder() {
    if (props.items.length === 0) {
        alert('There are no items to checkout.');
        return;
    }

    if (!checkoutForm.recipientName.trim()) {
        alert('Please enter the recipient name.');
        return;
    }

    if (!checkoutForm.contactNumber.trim()) {
        alert('Please enter the contact number.');
        return;
    }

    if (!checkoutForm.address.trim()) {
        alert('Please enter the delivery address.');
        return;
    }

    if (!checkoutForm.shippingMethod) {
        alert('Please select a shipping method.');
        return;
    }

    if (!checkoutForm.paymentMethod) {
        alert('Please select a payment method.');
        return;
    }

    /*
     * Database/API-ready order payload.
     */
    const orderPayload = {
        items: props.items.map(item => ({
            product_id: item.productId,
            seller: item.seller,
            variation: item.variation,
            quantity: Number(item.quantity),
            unit_price: Number(item.price)
        })),

        delivery_address: {
            recipient_name: checkoutForm.recipientName,
            contact_number: checkoutForm.contactNumber,
            address: checkoutForm.address
        },

        shipping_method: checkoutForm.shippingMethod,

        voucher_code:
            appliedVoucher.value?.code || null,

        payment_method:
            checkoutForm.paymentMethod,

        subtotal: subtotal.value,
        shipping_fee: shippingFee.value,
        discount: discount.value,
        total: total.value
    };

    console.log(
        'Order ready for database:',
        orderPayload
    );

    emit('place-order', orderPayload);

    alert(
        `Order ready to be placed!\n\n` +
        `Total: ${formatPrice(total.value)}\n` +
        `Payment: ${checkoutForm.paymentMethod.toUpperCase()}`
    );
}
</script>

<template>

    <div class="buyer-checkout-page">

        <!-- ============================================================ -->
        <!-- HEADER -->
        <!-- ============================================================ -->

        <header class="checkout-header">

            <button
                type="button"
                class="checkout-back-button"
                @click="emit('back')"
            >
                ← Back
            </button>

            <div>
                <h1>
                    Checkout
                </h1>

                <p>
                    Review your order before placing it.
                </p>
            </div>

        </header>

        <main class="checkout-content">

            <!-- ======================================================== -->
            <!-- DELIVERY ADDRESS -->
            <!-- ======================================================== -->

            <section class="checkout-section">

                <div class="checkout-section-title">

                    <div>
                        <h2>
                            Delivery Address
                        </h2>

                        <p>
                            Where should we deliver your order?
                        </p>
                    </div>

                </div>

                <div class="checkout-form-grid">

                    <div class="checkout-field">

                        <label>
                            Recipient Name
                        </label>

                        <input
                            v-model="checkoutForm.recipientName"
                            type="text"
                            placeholder="Enter recipient name"
                        />

                    </div>

                    <div class="checkout-field">

                        <label>
                            Contact Number
                        </label>

                        <input
                            v-model="checkoutForm.contactNumber"
                            type="text"
                            placeholder="09XXXXXXXXX"
                        />

                    </div>

                    <div class="checkout-field checkout-field-full">

                        <label>
                            Complete Address
                        </label>

                        <textarea
                            v-model="checkoutForm.address"
                            rows="3"
                            placeholder="House number, street, barangay, municipality, province"
                        ></textarea>

                    </div>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- PRODUCTS -->
            <!-- ======================================================== -->

            <section class="checkout-section">

                <div class="checkout-section-title">

                    <div>
                        <h2>
                            Products
                        </h2>

                        <p>
                            Items included in this order.
                        </p>
                    </div>

                </div>

                <div
                    v-for="item in items"
                    :key="`${item.productId}-${item.variation}`"
                    class="checkout-item"
                >

                    <div class="checkout-item-image">
                        Product Image
                    </div>

                    <div class="checkout-item-info">

                        <h3>
                            {{ item.name }}
                        </h3>

                        <p>
                            Seller: {{ item.seller }}
                        </p>

                        <p>
                            Variation: {{ item.variation }}
                        </p>

                    </div>

                    <div class="checkout-item-price">
                        {{ formatPrice(item.price) }}
                    </div>

                    <div class="checkout-item-quantity">
                        x{{ item.quantity }}
                    </div>

                    <div class="checkout-item-total">
                        {{
                            formatPrice(
                                item.price *
                                item.quantity
                            )
                        }}
                    </div>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- SHIPPING -->
            <!-- ======================================================== -->

            <section class="checkout-section">

                <div class="checkout-section-title">

                    <div>
                        <h2>
                            Shipping Option
                        </h2>

                        <p>
                            Choose your preferred delivery service.
                        </p>
                    </div>

                </div>

                <div class="checkout-option-list">

                    <label
                        v-for="option in shippingOptions"
                        :key="option.id"
                        class="checkout-option"
                        :class="{
                            active:
                                checkoutForm.shippingMethod === option.id
                        }"
                    >

                        <input
                            v-model="checkoutForm.shippingMethod"
                            type="radio"
                            name="shipping"
                            :value="option.id"
                        />

                        <div class="checkout-option-info">

                            <strong>
                                {{ option.name }}
                            </strong>

                            <span>
                                {{ option.description }}
                            </span>

                        </div>

                        <strong class="checkout-option-price">
                            {{ formatPrice(option.fee) }}
                        </strong>

                    </label>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- VOUCHER -->
            <!-- ======================================================== -->

            <section class="checkout-section">

                <div class="checkout-section-title">

                    <div>
                        <h2>
                            Voucher / Discount
                        </h2>

                        <p>
                            Enter an available NEXMART voucher.
                        </p>
                    </div>

                </div>

                <div class="voucher-input-row">

                    <input
                        v-model="voucherCode"
                        type="text"
                        placeholder="Enter voucher code"
                        :disabled="Boolean(appliedVoucher)"
                    />

                    <button
                        v-if="!appliedVoucher"
                        type="button"
                        class="voucher-button"
                        @click="applyVoucher"
                    >
                        Apply
                    </button>

                    <button
                        v-else
                        type="button"
                        class="voucher-remove-button"
                        @click="removeVoucher"
                    >
                        Remove
                    </button>

                </div>

                <p
                    v-if="appliedVoucher"
                    class="voucher-applied"
                >
                    NEXMART10 applied. You received a 10% discount.
                </p>

            </section>

            <!-- ======================================================== -->
            <!-- PAYMENT -->
            <!-- ======================================================== -->

            <section class="checkout-section">

                <div class="checkout-section-title">

                    <div>
                        <h2>
                            Payment Method
                        </h2>

                        <p>
                            Choose how you want to pay.
                        </p>
                    </div>

                </div>

                <div class="checkout-option-list">

                    <label
                        v-for="payment in paymentMethods"
                        :key="payment.id"
                        class="checkout-option"
                        :class="{
                            active:
                                checkoutForm.paymentMethod === payment.id
                        }"
                    >

                        <input
                            v-model="checkoutForm.paymentMethod"
                            type="radio"
                            name="payment"
                            :value="payment.id"
                        />

                        <div class="checkout-option-info">

                            <strong>
                                {{ payment.name }}
                            </strong>

                            <span>
                                {{ payment.description }}
                            </span>

                        </div>

                    </label>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- ORDER SUMMARY -->
            <!-- ======================================================== -->

            <section class="checkout-summary">

                <h2>
                    Order Summary
                </h2>

                <div class="summary-row">

                    <span>
                        Merchandise Subtotal
                    </span>

                    <span>
                        {{ formatPrice(subtotal) }}
                    </span>

                </div>

                <div class="summary-row">

                    <span>
                        Shipping Fee
                    </span>

                    <span>
                        {{ formatPrice(shippingFee) }}
                    </span>

                </div>

                <div
                    v-if="discount > 0"
                    class="summary-row summary-discount"
                >

                    <span>
                        Voucher Discount
                    </span>

                    <span>
                        -{{ formatPrice(discount) }}
                    </span>

                </div>

                <div class="summary-row summary-total">

                    <span>
                        Total Payment
                    </span>

                    <strong>
                        {{ formatPrice(total) }}
                    </strong>

                </div>

                <button
                    type="button"
                    class="place-order-button"
                    @click="placeOrder"
                >
                    Place Order
                </button>

            </section>

        </main>

    </div>

</template>
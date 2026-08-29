<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { metaFor } from '../composables/useCategoryMeta';
import Footer from './Footer.vue';
import Header from './Header.vue';

// Renamed on import: this component's own placeOrder() below is the
// click handler (validates the form, builds the payload); it calls this
// composable function to actually submit to the backend.
const { placeOrder: submitCheckout, isPlacingOrder } = useBuyer();

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    }
});

const emit = defineEmits([
    'back',
    'place-order',
    'search',
    'select-category',
    'view-profile',
    'browse-all',
    'browse-categories'
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
| Prefill from the buyer's default saved address
|--------------------------------------------------------------------------
|
| Non-destructive: fills the existing form fields (which stay fully
| editable) from useBuyerAddresses' default the moment it loads, unless
| the buyer has already typed something. No new markup — the same three
| fields, just not blank when there's a saved address to start from.
|
*/

const { defaultAddress } = useBuyerAddresses();

function applyDefaultAddress(address) {
    if (!address) {
        return;
    }

    if (!checkoutForm.recipientName) {
        checkoutForm.recipientName = address.fullName || '';
    }

    if (!checkoutForm.contactNumber) {
        checkoutForm.contactNumber = address.phone || '';
    }

    if (!checkoutForm.address) {
        checkoutForm.address = [address.line1, address.city, address.province, address.postalCode]
            .filter(Boolean)
            .join(', ');
    }
}

applyDefaultAddress(defaultAddress.value);
watch(defaultAddress, applyDefaultAddress);

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
| Header relay
|--------------------------------------------------------------------------
*/

function handleHeaderSearch(query) {
    emit('search', query);
}

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
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

async function placeOrder() {
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
            variant_id: item.variantId || null,

            name: item.name,
            category: item.category,

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

    /*
     * Sends the payload to POST /api/buyer/checkout (App\Http\Controllers\
     * Buyer\CheckoutController via useBuyer.js's placeOrder()), which
     * re-validates price/stock server-side and creates one real order
     * per seller. Nothing is emitted/cleared until that succeeds.
     */
    try {
        const createdOrders = await submitCheckout(orderPayload);

        emit('place-order', createdOrders);

        alert(
            `Order placed!\n\n` +
            `Total: ${formatPrice(total.value)}\n` +
            `Payment: ${checkoutForm.paymentMethod.toUpperCase()}`
        );
    } catch (err) {
        alert(err?.message || 'Could not place your order. Please try again.');
    }
}
</script>

<template>

    <div class="buyer-page">

        <Header
            @select-category="handleHeaderSelectCategory"
            @cart-click="() => {}"
            @account-click="emit('view-profile')"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <div class="buyer-checkout-page">

            <div class="checkout-page-content">

                <!-- ======================================================== -->
                <!-- BREADCRUMB -->
                <!-- ======================================================== -->

                <nav class="product-breadcrumb">
                    <button
                        type="button"
                        class="breadcrumb-link"
                        @click="emit('back')"
                    >
                        Home
                    </button>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current breadcrumb-current--active">
                        Checkout
                    </span>
                </nav>

                <!-- ======================================================== -->
                <!-- PROGRESS STEPPER -->
                <!-- ======================================================== -->

                <div class="checkout-stepper">

                    <div class="checkout-step">
                        <div class="checkout-step-circle done">✓</div>
                        <span class="checkout-step-label done">Cart</span>
                    </div>

                    <div class="checkout-step-line done"></div>

                    <div class="checkout-step">
                        <div class="checkout-step-circle current">2</div>
                        <span class="checkout-step-label current">Checkout</span>
                    </div>

                    <div class="checkout-step-line"></div>

                    <div class="checkout-step">
                        <div class="checkout-step-circle">3</div>
                        <span class="checkout-step-label">Finished</span>
                    </div>

                </div>

                <div class="checkout-layout">

                    <!-- ==================================================== -->
                    <!-- FORM COLUMN -->
                    <!-- ==================================================== -->

                    <div class="checkout-form-column">

                        <!-- Delivery Address -->
                        <section class="checkout-section">

                            <div class="checkout-section-title">
                                <div class="checkout-section-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" />
                                        <circle cx="12" cy="10" r="3" />
                                    </svg>
                                </div>
                                <div>
                                    <h2>Delivery Address</h2>
                                    <p>Where should we deliver your order?</p>
                                </div>
                            </div>

                            <div class="checkout-form-grid">

                                <div class="checkout-field">
                                    <label>Recipient Name</label>
                                    <input
                                        v-model="checkoutForm.recipientName"
                                        type="text"
                                        placeholder="Enter recipient name"
                                    >
                                </div>

                                <div class="checkout-field">
                                    <label>Contact Number</label>
                                    <input
                                        v-model="checkoutForm.contactNumber"
                                        type="text"
                                        placeholder="09XXXXXXXXX"
                                    >
                                </div>

                                <div class="checkout-field checkout-field-full">
                                    <label>Complete Address</label>
                                    <textarea
                                        v-model="checkoutForm.address"
                                        rows="3"
                                        placeholder="House number, street, barangay, municipality, province"
                                    ></textarea>
                                </div>

                            </div>

                        </section>

                        <!-- Products -->
                        <section class="checkout-section">

                            <div class="checkout-section-title">
                                <div class="checkout-section-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" />
                                        <path d="M3 6h18" />
                                        <path d="M16 10a4 4 0 0 1-8 0" />
                                    </svg>
                                </div>
                                <div>
                                    <h2>Products</h2>
                                    <p>Items included in this order.</p>
                                </div>
                            </div>

                            <div
                                v-for="item in items"
                                :key="`${item.productId}-${item.variantId || 'simple'}`"
                                class="checkout-item"
                            >

                                <div
                                    class="checkout-item-image"
                                    :class="'accent-' + metaFor(item.category).accent"
                                >
                                    <span
                                        class="product-image-icon"
                                        v-html="metaFor(item.category).icon"
                                    ></span>
                                </div>

                                <div class="checkout-item-info">
                                    <h3>{{ item.name }}</h3>
                                    <p>Seller: {{ item.seller }}<template v-if="item.variation"> | Variation: {{ item.variation }}</template></p>
                                </div>

                                <div class="checkout-item-quantity">
                                    x{{ item.quantity }}
                                </div>

                                <div class="checkout-item-total">
                                    {{ formatPrice(item.price * item.quantity) }}
                                </div>

                            </div>

                        </section>

                        <!-- Shipping Option -->
                        <section class="checkout-section">

                            <div class="checkout-section-title">
                                <div class="checkout-section-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" />
                                        <path d="M15 18H9" />
                                        <path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62L18.3 8.38A1 1 0 0 0 17.52 8H14" />
                                        <circle cx="17" cy="18" r="2" />
                                        <circle cx="7" cy="18" r="2" />
                                    </svg>
                                </div>
                                <div>
                                    <h2>Shipping Option</h2>
                                    <p>Choose your preferred delivery service.</p>
                                </div>
                            </div>

                            <div class="checkout-option-list">

                                <label
                                    v-for="option in shippingOptions"
                                    :key="option.id"
                                    class="checkout-option"
                                    :class="{ active: checkoutForm.shippingMethod === option.id }"
                                >

                                    <input
                                        v-model="checkoutForm.shippingMethod"
                                        type="radio"
                                        name="shipping"
                                        :value="option.id"
                                    >

                                    <div class="checkout-option-info">
                                        <strong>{{ option.name }}</strong>
                                        <span>{{ option.description }}</span>
                                    </div>

                                    <strong class="checkout-option-price">
                                        {{ formatPrice(option.fee) }}
                                    </strong>

                                </label>

                            </div>

                        </section>

                        <!-- Voucher -->
                        <section class="checkout-section">

                            <div class="checkout-section-title">
                                <div class="checkout-section-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z" />
                                        <circle cx="7" cy="7" r="1" />
                                    </svg>
                                </div>
                                <div>
                                    <h2>Voucher / Discount</h2>
                                    <p>Enter an available NEXMART voucher.</p>
                                </div>
                            </div>

                            <div class="voucher-input-row">

                                <input
                                    v-model="voucherCode"
                                    type="text"
                                    placeholder="Enter voucher code"
                                    :disabled="Boolean(appliedVoucher)"
                                >

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

                        <!-- Payment Method -->
                        <section class="checkout-section">

                            <div class="checkout-section-title">
                                <div class="checkout-section-icon">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="5" width="20" height="14" rx="2" />
                                        <path d="M2 10h20" />
                                    </svg>
                                </div>
                                <div>
                                    <h2>Payment Method</h2>
                                    <p>Choose how you want to pay.</p>
                                </div>
                            </div>

                            <div class="checkout-option-list">

                                <label
                                    v-for="payment in paymentMethods"
                                    :key="payment.id"
                                    class="checkout-option"
                                    :class="{ active: checkoutForm.paymentMethod === payment.id }"
                                >

                                    <input
                                        v-model="checkoutForm.paymentMethod"
                                        type="radio"
                                        name="payment"
                                        :value="payment.id"
                                    >

                                    <div class="checkout-option-info">
                                        <strong>{{ payment.name }}</strong>
                                        <span>{{ payment.description }}</span>
                                    </div>

                                </label>

                            </div>

                        </section>

                    </div>

                    <!-- ==================================================== -->
                    <!-- ORDER SUMMARY SIDEBAR -->
                    <!-- ==================================================== -->

                    <div class="checkout-summary-sidebar">

                        <div class="cart-summary-card">

                            <h2>Order Summary</h2>

                            <div class="cart-summary-rows">

                                <div class="cart-summary-row">
                                    <span>Merchandise Subtotal</span>
                                    <span class="value">{{ formatPrice(subtotal) }}</span>
                                </div>

                                <div class="cart-summary-row">
                                    <span>Shipping Fee</span>
                                    <span class="value">{{ formatPrice(shippingFee) }}</span>
                                </div>

                                <div
                                    v-if="discount > 0"
                                    class="cart-summary-row"
                                >
                                    <span>Voucher Discount</span>
                                    <span class="value--accent">-{{ formatPrice(discount) }}</span>
                                </div>

                                <div class="cart-summary-divider"></div>

                                <div class="cart-summary-total">
                                    <span>Total Payment</span>
                                    <span class="value">{{ formatPrice(total) }}</span>
                                </div>

                            </div>

                            <button
                                type="button"
                                class="cart-checkout-button"
                                :disabled="isPlacingOrder"
                                @click="placeOrder"
                            >
                                {{ isPlacingOrder ? 'Placing Order…' : 'Place Order' }}
                            </button>

                            <div class="cart-summary-notes">

                                <div class="cart-summary-note">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                                    </svg>
                                    <span>Secure Checkout Guaranteed</span>
                                </div>

                                <div class="cart-summary-note">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 12a9 9 0 1 0 3-6.7" />
                                        <path d="M3 4v5h5" />
                                    </svg>
                                    <span>30-Day Free Returns</span>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="() => {}"
        />

    </div>

</template>
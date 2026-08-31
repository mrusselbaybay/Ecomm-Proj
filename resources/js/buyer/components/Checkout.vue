<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { useBuyerPayments } from '../composables/useBuyerPayments';
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
| The order itself only ever carries a payment_method *string* ('cod',
| 'card', 'gcash', 'maya') — that's all CheckoutService stores. The card
| / wallet detail forms below are validated entirely in the browser
| (Luhn + expiry + CVC format via useBuyerPayments' helpers). The full
| card number and the CVC are NEVER put in the checkout payload and never
| reach the server; if "save this card" is ticked we hand the raw number
| to useBuyerPayments.addCard(), which itself derives brand + last4 +
| expiry client-side and sends only those.
|
*/

const {
    cards: savedCards,
    wallets: savedWallets,
    detectBrand,
    luhnValid,
    parseExpiry,
    addCard,
    addWallet,
} = useBuyerPayments();

const paymentMethods = [
    {
        id: 'cod',
        name: 'Cash on Delivery',
        description: 'Pay with cash when your package arrives.',
        tag: 'Popular in your area'
    },
    {
        id: 'card',
        name: 'Credit / Debit Card',
        description: 'Visa, Mastercard, AMEX, or JCB.'
    },
    {
        id: 'gcash',
        name: 'GCash Wallet',
        description: 'Direct payment via your GCash mobile wallet.',
        tag: 'Instant confirmation'
    },
    {
        id: 'maya',
        name: 'Maya Wallet',
        description: 'Pay easily using your Maya account balance.',
        tag: 'Rewards eligible'
    }
];

const cardForm = reactive({
    holder: '',
    number: '',
    expiry: '',
    cvc: ''
});

const walletForm = reactive({
    phone: ''
});

// '' => the buyer is entering fresh details; otherwise the id of a saved
// card / wallet they picked (details already on file, nothing to collect).
const savedMethodId = ref('');
const savePaymentDetails = ref(false);
const paymentError = ref('');

const isWalletMethod = computed(
    () => checkoutForm.paymentMethod === 'gcash' || checkoutForm.paymentMethod === 'maya'
);

const requiresPaymentDetails = computed(
    () => checkoutForm.paymentMethod === 'card' || isWalletMethod.value
);

const walletProvider = computed(() => (checkoutForm.paymentMethod === 'maya' ? 'Maya' : 'GCash'));

const cardBrand = computed(() => detectBrand(cardForm.number));

const savedMethodsForSelection = computed(() => {
    if (checkoutForm.paymentMethod === 'card') {
        return savedCards.value;
    }

    if (isWalletMethod.value) {
        return savedWallets.value.filter(wallet => wallet.provider === walletProvider.value);
    }

    return [];
});

// Switching payment method: clear any error, and default to the buyer's
// primary saved method for that type if they have one on file.
watch(() => checkoutForm.paymentMethod, () => {
    paymentError.value = '';
    savePaymentDetails.value = false;

    const saved = savedMethodsForSelection.value;
    savedMethodId.value = saved.length
        ? (saved.find(method => method.isPrimary)?.id || saved[0].id)
        : '';
});

// Keep the card number grouped in 4s as the buyer types, digits only.
function formatCardNumber(event) {
    const digits = event.target.value.replace(/\D/g, '').slice(0, 19);
    cardForm.number = digits.replace(/(.{4})(?=.)/g, '$1 ');
}

function validatePaymentSelection() {
    if (!checkoutForm.paymentMethod) {
        return 'Please select a payment method.';
    }

    // COD and any already-saved method need nothing more from the buyer.
    if (checkoutForm.paymentMethod === 'cod' || savedMethodId.value) {
        return '';
    }

    if (checkoutForm.paymentMethod === 'card') {
        if (!cardForm.holder.trim()) {
            return 'Enter the cardholder name.';
        }

        if (!luhnValid(cardForm.number)) {
            return 'Enter a valid card number.';
        }

        if (!parseExpiry(cardForm.expiry)) {
            return 'Enter a valid, non-expired expiry date (MM / YY).';
        }

        if (!/^\d{3,4}$/.test(cardForm.cvc.trim())) {
            return 'Enter the 3 or 4 digit security code.';
        }

        return '';
    }

    if (!/^09\d{9}$/.test(walletForm.phone.replace(/\s/g, ''))) {
        return `Enter the ${walletProvider.value} mobile number (09XXXXXXXXX).`;
    }

    return '';
}

// Best-effort — a failure here never blocks the order that was already
// placed; the card / wallet just doesn't get saved for next time. The CVC
// is deliberately not passed on.
async function persistPaymentDetails() {
    if (!savePaymentDetails.value || savedMethodId.value) {
        return;
    }

    try {
        if (checkoutForm.paymentMethod === 'card') {
            await addCard({
                holder: cardForm.holder,
                number: cardForm.number,
                expiry: cardForm.expiry
            });
        } else if (isWalletMethod.value) {
            await addWallet({
                provider: walletProvider.value,
                phone: walletForm.phone
            });
        }
    } catch (err) {
        console.error('Could not save payment method for future use:', err);
    }
}

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

    const paymentProblem = validatePaymentSelection();

    if (paymentProblem) {
        paymentError.value = paymentProblem;
        alert(paymentProblem);

        return;
    }

    paymentError.value = '';

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

        await persistPaymentDetails();

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
                                    <p>Choose how you'd like to pay. All transactions are secure and encrypted.</p>
                                </div>
                            </div>

                            <div class="payment-method-grid">

                                <label
                                    v-for="payment in paymentMethods"
                                    :key="payment.id"
                                    class="payment-method-card"
                                    :class="{ active: checkoutForm.paymentMethod === payment.id }"
                                >

                                    <input
                                        v-model="checkoutForm.paymentMethod"
                                        type="radio"
                                        name="payment"
                                        :value="payment.id"
                                        class="payment-method-radio"
                                    >

                                    <div class="payment-method-card-top">

                                        <span
                                            class="payment-method-icon"
                                            :class="'pm-icon-' + payment.id"
                                        >
                                            <svg
                                                v-if="payment.id === 'cod'"
                                                viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            >
                                                <rect x="2" y="6" width="20" height="12" rx="2" />
                                                <circle cx="12" cy="12" r="2" />
                                                <path d="M6 12h.01M18 12h.01" />
                                            </svg>
                                            <svg
                                                v-else-if="payment.id === 'card'"
                                                viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            >
                                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                                <path d="M2 10h20" />
                                            </svg>
                                            <span
                                                v-else
                                                class="payment-method-icon-text"
                                            >{{ payment.id === 'maya' ? 'Maya' : 'GCash' }}</span>
                                        </span>

                                        <span
                                            v-if="checkoutForm.paymentMethod === payment.id"
                                            class="payment-method-check"
                                        >
                                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10" />
                                                <path d="m9 12 2 2 4-4" />
                                            </svg>
                                        </span>

                                    </div>

                                    <h3>{{ payment.name }}</h3>
                                    <p>{{ payment.description }}</p>

                                    <span
                                        v-if="payment.tag"
                                        class="payment-method-tag"
                                    >
                                        {{ payment.tag }}
                                    </span>

                                </label>

                            </div>

                            <!-- Card / wallet details -->
                            <div
                                v-if="requiresPaymentDetails"
                                class="payment-detail-panel"
                            >

                                <div
                                    v-if="savedMethodsForSelection.length"
                                    class="payment-saved-list"
                                >

                                    <label
                                        v-for="method in savedMethodsForSelection"
                                        :key="method.id"
                                        class="payment-saved-option"
                                        :class="{ active: savedMethodId === method.id }"
                                    >
                                        <input
                                            v-model="savedMethodId"
                                            type="radio"
                                            name="saved-method"
                                            :value="method.id"
                                        >
                                        <span v-if="method.type === 'card'">
                                            {{ method.brand }} •••• {{ method.last4 }}
                                            <template v-if="method.expMonth"> · {{ method.expMonth }}/{{ String(method.expYear).slice(-2) }}</template>
                                        </span>
                                        <span v-else>
                                            {{ method.provider }} · {{ method.phoneMasked }}
                                        </span>
                                    </label>

                                    <label
                                        class="payment-saved-option"
                                        :class="{ active: savedMethodId === '' }"
                                    >
                                        <input
                                            v-model="savedMethodId"
                                            type="radio"
                                            name="saved-method"
                                            value=""
                                        >
                                        <span>
                                            Use {{ checkoutForm.paymentMethod === 'card' ? 'a new card' : 'a new number' }}
                                        </span>
                                    </label>

                                </div>

                                <!-- New card -->
                                <div
                                    v-if="checkoutForm.paymentMethod === 'card' && !savedMethodId"
                                    class="checkout-form-grid"
                                >

                                    <div class="checkout-field checkout-field-full">
                                        <label>Cardholder Name</label>
                                        <input
                                            v-model="cardForm.holder"
                                            type="text"
                                            autocomplete="cc-name"
                                            placeholder="JONATHAN DOE"
                                        >
                                    </div>

                                    <div class="checkout-field checkout-field-full">
                                        <label>Card Number</label>
                                        <div class="payment-card-number">
                                            <input
                                                :value="cardForm.number"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="cc-number"
                                                placeholder="0000 0000 0000 0000"
                                                @input="formatCardNumber"
                                            >
                                            <span class="payment-card-brand">{{ cardBrand }}</span>
                                        </div>
                                    </div>

                                    <div class="checkout-field">
                                        <label>Expiry Date</label>
                                        <input
                                            v-model="cardForm.expiry"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="cc-exp"
                                            placeholder="MM / YY"
                                        >
                                    </div>

                                    <div class="checkout-field">
                                        <label>CVC / CVV</label>
                                        <input
                                            v-model="cardForm.cvc"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="cc-csc"
                                            maxlength="4"
                                            placeholder="123"
                                        >
                                    </div>

                                </div>

                                <!-- New wallet -->
                                <div
                                    v-else-if="isWalletMethod && !savedMethodId"
                                    class="checkout-form-grid"
                                >
                                    <div class="checkout-field checkout-field-full">
                                        <label>{{ walletProvider }} Mobile Number</label>
                                        <input
                                            v-model="walletForm.phone"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="13"
                                            placeholder="09XXXXXXXXX"
                                        >
                                    </div>
                                </div>

                                <label
                                    v-if="!savedMethodId"
                                    class="payment-save-toggle"
                                >
                                    <input
                                        v-model="savePaymentDetails"
                                        type="checkbox"
                                    >
                                    <span>
                                        Save this {{ checkoutForm.paymentMethod === 'card' ? 'card' : 'number' }} for future purchases
                                    </span>
                                </label>

                                <p class="payment-secure-note">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="3" y="11" width="18" height="11" rx="2" />
                                        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                                    </svg>
                                    <span v-if="checkoutForm.paymentMethod === 'card'">
                                        Your card is checked in this browser. We only ever store the brand, last 4 digits and expiry — never the full number or CVC.
                                    </span>
                                    <span v-else>
                                        You'll confirm the payment in your {{ walletProvider }} app. We only store a masked number.
                                    </span>
                                </p>

                                <p
                                    v-if="paymentError"
                                    class="payment-error"
                                >
                                    {{ paymentError }}
                                </p>

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

<style scoped>
/*
| Payment method picker — adapted from the ShopVerse "Select Payment
| Method" reference onto the buyer app's own tokens (teal --nx-accent,
| the shared .checkout-field / .checkout-form-grid form styles) instead
| of Tailwind utilities.
*/

.payment-method-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.payment-method-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;

    padding: 18px;

    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: #ffffff;

    cursor: pointer;
    transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.payment-method-card:hover {
    transform: translateY(-2px);
    border-color: rgba(13, 148, 136, 0.5);
    box-shadow: 0 8px 24px -12px rgba(15, 23, 42, 0.15);
}

.payment-method-card.active {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.payment-method-radio {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.payment-method-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    width: 100%;
    margin-bottom: 14px;
}

.payment-method-icon {
    display: flex;
    align-items: center;
    justify-content: center;

    width: 46px;
    height: 46px;

    border-radius: 14px;
    background: #f1f5f9;
    color: var(--nx-ink);
}

.payment-method-icon-text {
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.2px;
}

.pm-icon-cod {
    background: #ffedd5;
    color: #c2410c;
}

.pm-icon-card {
    background: #dbeafe;
    color: #1d4ed8;
}

.pm-icon-gcash {
    background: #2563eb;
    color: #ffffff;
}

.pm-icon-maya {
    background: #0f172a;
    color: #c1ff00;
    font-style: italic;
}

.payment-method-check {
    color: var(--nx-accent);
}

.payment-method-card h3 {
    margin: 0;
    color: var(--nx-ink);
    font-size: 15px;
    font-weight: 700;
}

.payment-method-card p {
    margin: 4px 0 0;
    color: var(--nx-muted);
    font-size: 12.5px;
    line-height: 1.4;
}

.payment-method-tag {
    margin-top: 12px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: var(--nx-accent);
}

.payment-detail-panel {
    margin-top: 18px;
    padding: 20px;

    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: var(--nx-bg, #f8fafc);
}

.payment-saved-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 18px;
}

.payment-saved-option {
    display: flex;
    align-items: center;
    gap: 12px;

    padding: 12px 14px;

    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;

    font-size: 13px;
    color: var(--nx-ink);
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.payment-saved-option.active {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
}

.payment-saved-option input {
    width: 16px;
    height: 16px;
    accent-color: var(--nx-accent);
}

.payment-card-number {
    position: relative;
}

.payment-card-number input {
    width: 100%;
    padding: 12px 16px;

    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: var(--nx-bg);

    outline: none;
    font: inherit;
    box-sizing: border-box;

    transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}

.payment-card-number input:focus {
    border-color: var(--nx-accent);
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(13, 148, 136, 0.1);
}

.payment-card-brand {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);

    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: var(--nx-muted);
}

.payment-save-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;

    font-size: 13px;
    color: var(--nx-ink);
    cursor: pointer;
}

.payment-save-toggle input {
    width: 16px;
    height: 16px;
    accent-color: var(--nx-accent);
}

.payment-secure-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 14px 0 0;

    color: var(--nx-muted);
    font-size: 11.5px;
    line-height: 1.5;
}

.payment-secure-note svg {
    flex-shrink: 0;
    margin-top: 2px;
}

.payment-error {
    margin: 12px 0 0;
    color: #dc2626;
    font-size: 12.5px;
    font-weight: 600;
}

@media (max-width: 640px) {
    .payment-method-grid {
        grid-template-columns: 1fr;
    }
}
</style>
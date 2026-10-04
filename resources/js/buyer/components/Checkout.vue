<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { useBuyerSession } from '../composables/useBuyerSession';
import { formatPrice, metaFor } from '../composables/useCategoryMeta';
import { availablePaymentMethods, paymentMethods } from '../composables/usePayment';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';
import { shippingOptions } from '../composables/useShipping';
import { useToasts } from '../composables/useToasts';
import Footer from './Footer.vue';
import Header from './Header.vue';
import PaymentMethodSelector from './PaymentMethodSelector.vue';

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| One page, three short steps (address, items and shipping, payment), then
| a confirmation view built from the orders the server actually created.
|
| Only what the backend supports is offered (see CheckoutService):
|   - one order and one flat shipping fee per seller, so shipping is shown
|     per parcel and the total counts every parcel
|   - one shipping method for the whole checkout
|   - cash on delivery only: nothing is charged online and every order is
|     saved as Unpaid, so card / e-wallet options are not offered here
|   - no vouchers (the server ignores voucher_code and discount is 0)
|
*/

// Renamed on import: this component's own placeOrder() below is the click
// handler; submitCheckout is the request itself.
const { placeOrder: submitCheckout, isPlacingOrder } = useBuyer();
const { buyerProfile, isLoadingSession } = useBuyerSession();
const { addresses, defaultAddress, addAddress } = useBuyerAddresses();
const { warning, error: toastError } = useToasts();

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    // 'cart' or 'buy-now': decides whether "Edit cart" makes sense.
    source: {
        type: String,
        default: 'cart'
    }
});

const emit = defineEmits([
    'back',
    'place-order',
    'search',
    'select-category',
    'view-profile',
    'view-orders',
    'edit-cart',
    'remove-item',
    'browse-all',
    'browse-categories'
]);

function itemKey(item) {
    return `${item.productId}-${item.variantId || 'simple'}`;
}

// Product photo when the item carries one; a missing, placeholder or
// broken image falls back to the category icon tile.
const PLACEHOLDER_IMAGE = '/images/product-placeholder.svg';
const failedImages = ref(new Set());

function hasImage(item) {
    return Boolean(item.image)
        && item.image !== PLACEHOLDER_IMAGE
        && !failedImages.value.has(item.image);
}

function markImageFailed(item) {
    failedImages.value = new Set(failedImages.value).add(item.image);
}

// Server order lines carry only name/qty/price/variant; match them back to
// the checkout item for its photo and category.
function itemForLine(line) {
    return props.items.find(item => item.name === line.name) || {};
}

const isSignedOut = computed(() => !isLoadingSession.value && !buyerProfile.value);

/*
|--------------------------------------------------------------------------
| Delivery address
|--------------------------------------------------------------------------
|
| Returning buyers start on their default saved address (shown as a short
| summary with "Change"); first-time buyers get the form. A new address can
| be saved to the address book after the order goes through.
|
*/

const addressMode = ref('new');
const selectedAddressId = ref('');
const isChoosingAddress = ref(false);
const saveNewAddress = ref(true);

const newAddress = reactive({
    fullName: '',
    phone: '',
    line1: '',
    city: '',
    province: '',
    postalCode: ''
});

let hasPickedInitialAddress = false;

function pickInitialAddress(address) {
    if (hasPickedInitialAddress || !address) {
        return;
    }

    hasPickedInitialAddress = true;
    addressMode.value = 'saved';
    selectedAddressId.value = address.id;
}

pickInitialAddress(defaultAddress.value);
watch(defaultAddress, pickInitialAddress);

// Prefill the new-address form with the buyer's own name and number.
watch(buyerProfile, profile => {
    if (!profile) {
        return;
    }

    if (!newAddress.fullName) {
        newAddress.fullName = [profile.first_name, profile.last_name].filter(Boolean).join(' ');
    }

    if (!newAddress.phone) {
        newAddress.phone = toLocalMobile(profile.contact_no || '');
    }
}, { immediate: true });

const selectedSavedAddress = computed(
    () => addresses.value.find(address => address.id === selectedAddressId.value) || null
);

function formatAddressLine(address) {
    return [address.line1, address.city, address.province, address.postalCode]
        .map(part => String(part || '').trim())
        .filter(Boolean)
        .join(', ');
}

const deliveryAddress = computed(() => {
    const source = addressMode.value === 'saved' ? selectedSavedAddress.value : newAddress;

    if (!source) {
        return null;
    }

    return {
        recipient_name: String(source.fullName || '').trim(),
        contact_number: toLocalMobile(source.phone || ''),
        address: formatAddressLine(source)
    };
});

function chooseSavedAddress(id) {
    addressMode.value = 'saved';
    selectedAddressId.value = id;
    isChoosingAddress.value = false;
    clearError('address');
}

function useNewAddress() {
    addressMode.value = 'new';
    isChoosingAddress.value = false;
    clearError('address');
    nextTick(() => document.getElementById('checkout-name')?.focus());
}

function onPhoneInput(event) {
    newAddress.phone = toLocalMobile(event.target.value);
    event.target.value = newAddress.phone;
    revalidate('phone');
}

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
|
| Errors sit under the field they belong to. Fields are checked on blur
| once touched, and every field is re-checked as the buyer fixes it after a
| failed submit. Nothing typed is ever cleared.
|
*/

const errors = reactive({});
const touched = reactive({});
const hasTriedSubmit = ref(false);

const fieldOrder = ['fullName', 'phone', 'line1', 'city', 'province', 'address'];

const fieldLabels = {
    fullName: 'recipient name',
    phone: 'contact number',
    line1: 'street address',
    city: 'city or municipality',
    province: 'province',
    address: 'delivery address'
};

function validateField(field) {
    if (addressMode.value === 'saved') {
        if (field !== 'address') {
            return '';
        }

        const saved = selectedSavedAddress.value;

        if (!saved) {
            return 'Choose a saved address or enter a new one.';
        }

        if (!isValidLocalMobile(toLocalMobile(saved.phone || ''))) {
            return 'This saved address has no valid contact number. Use a new address, or update it in Saved Addresses.';
        }

        return '';
    }

    const value = String(newAddress[field] ?? '').trim();

    switch (field) {
        case 'fullName':
            return value ? '' : 'Enter the name of the person receiving the parcel.';
        case 'phone':
            if (!value) {
                return 'Enter a mobile number so the courier can reach you.';
            }

            return isValidLocalMobile(value) ? '' : 'Enter an 11-digit mobile number starting with 09, e.g. 09171234567.';
        case 'line1':
            return value ? '' : 'Enter the house number, street and barangay.';
        case 'city':
            return value ? '' : 'Enter the city or municipality.';
        case 'province':
            return value ? '' : 'Enter the province.';
        default:
            return '';
    }
}

function setError(field, message) {
    if (message) {
        errors[field] = message;
    } else {
        delete errors[field];
    }
}

function clearError(field) {
    delete errors[field];
}

function touch(field) {
    touched[field] = true;
    setError(field, validateField(field));
}

function revalidate(field) {
    if (touched[field] || hasTriedSubmit.value) {
        setError(field, validateField(field));
    }
}

function validateAll() {
    const fields = addressMode.value === 'saved'
        ? ['address']
        : ['fullName', 'phone', 'line1', 'city', 'province'];

    fieldOrder.forEach(field => clearError(field));
    fields.forEach(field => setError(field, validateField(field)));

    return fieldOrder.filter(field => errors[field]);
}

const errorFields = computed(() => fieldOrder.filter(field => errors[field]));

watch(addressMode, () => {
    fieldOrder.forEach(field => clearError(field));
});

/*
|--------------------------------------------------------------------------
| Parcels and totals
|--------------------------------------------------------------------------
|
| CheckoutService creates one order per seller and charges the flat
| shipping fee once per order, so the summary does the same.
|
*/

const shippingMethod = ref('standard');

const selectedShipping = computed(
    () => shippingOptions.find(option => option.id === shippingMethod.value) || shippingOptions[0]
);

const parcels = computed(() => {
    const groups = new Map();

    props.items.forEach(item => {
        const seller = item.seller || 'Seller';

        if (!groups.has(seller)) {
            groups.set(seller, { seller, items: [], subtotal: 0 });
        }

        const group = groups.get(seller);
        group.items.push(item);
        group.subtotal += Number(item.price) * Number(item.quantity);
    });

    return [...groups.values()];
});

const itemCount = computed(
    () => props.items.reduce((count, item) => count + Number(item.quantity), 0)
);

const subtotal = computed(
    () => parcels.value.reduce((total, parcel) => total + parcel.subtotal, 0)
);

const shippingTotal = computed(() => selectedShipping.value.fee * parcels.value.length);

/*
|--------------------------------------------------------------------------
| Payment method
|--------------------------------------------------------------------------
|
| Only methods from usePayment.js (mirroring CheckoutService::
| PAYMENT_METHODS) are offered. A single available method is selected by
| default; with several, the buyer must choose. The choice is checked
| again on Place order and by the server.
|
*/

const availableMethods = computed(() => availablePaymentMethods());
const paymentMethod = ref('');

watch(availableMethods, methods => {
    const stillAvailable = methods.some(method => method.id === paymentMethod.value);

    if (!stillAvailable) {
        paymentMethod.value = methods.length === 1 ? methods[0].id : '';
    }
}, { immediate: true });

watch(paymentMethod, () => clearError('payment'));

const selectedPayment = computed(
    () => availableMethods.value.find(method => method.id === paymentMethod.value) || null
);

const paymentFee = computed(() => Number(selectedPayment.value?.fee || 0));

function validatePayment() {
    if (!availableMethods.value.length) {
        return 'No payment method is available for this order.';
    }

    if (!selectedPayment.value) {
        return 'Choose how you want to pay.';
    }

    return '';
}

const total = computed(() => subtotal.value + shippingTotal.value + paymentFee.value);

function pluralize(count, singular, plural = `${singular}s`) {
    return `${count} ${count === 1 ? singular : plural}`;
}

/*
|--------------------------------------------------------------------------
| Placing the order
|--------------------------------------------------------------------------
|
| The button states the exact total, then locks and shows progress; the
| form goes read-only until the server answers, so a second click can't
| send a second order. Failures keep everything the buyer entered.
|
*/

const submitError = ref(null);
const problemItemKey = ref('');
const placedOrders = ref(null);
const confirmation = ref(null);

const errorBanner = ref(null);
const confirmationHeading = ref(null);

function findItemInMessage(message) {
    const quoted = /"([^"]+)"/.exec(message || '');

    if (!quoted) {
        return null;
    }

    return props.items.find(item => item.name === quoted[1]) || null;
}

function describeFailure(err) {
    if (!err?.status) {
        return {
            kind: 'unknown',
            title: 'We couldn\'t reach the server.',
            message: 'Your connection dropped before we got an answer, so the order may or may not have gone through. Check My Orders before trying again.'
        };
    }

    if (err.status === 401 || err.status === 403) {
        return {
            kind: 'auth',
            title: 'Please sign in again.',
            message: 'Your session has ended. Sign in, then come back to place your order. Your cart is saved.'
        };
    }

    if (err.status === 422 && err.body?.errors?.payment_method) {
        return {
            kind: 'payment',
            field: err.body.errors.payment_method[0],
            title: 'Your order wasn\'t placed.',
            message: 'The payment method you chose can\'t be used. Choose another option under "Choose how to pay". Your other details are still here.'
        };
    }

    if (err.status === 422) {
        const item = findItemInMessage(err.message);

        return {
            kind: item ? 'item' : 'general',
            itemKey: item ? itemKey(item) : '',
            title: 'Your order wasn\'t placed.',
            message: `${err.message || 'Something in your order needs attention.'} Your address and choices are still here.`
        };
    }

    return {
        kind: 'general',
        title: 'Your order wasn\'t placed.',
        message: 'Something went wrong on our side. Nothing was ordered, and your details are still here. Please try again.'
    };
}

async function focusFirstError() {
    await nextTick();

    const first = errorFields.value[0];
    const target = first === 'address'
        ? document.getElementById('checkout-address-card')
        : document.getElementById(`checkout-${first === 'fullName' ? 'name' : first}`);

    target?.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    target?.focus?.({ preventScroll: true });
}

async function focusPaymentSection() {
    await nextTick();

    const section = document.getElementById('checkout-payment-card');

    section?.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
    section?.querySelector('input[name="payment-method"]:not(:disabled)')?.focus({ preventScroll: true });
}

function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

async function placeOrder() {
    if (isPlacingOrder.value || placedOrders.value) {
        return;
    }

    if (props.items.length === 0) {
        warning('There are no items to check out.');

        return;
    }

    hasTriedSubmit.value = true;
    submitError.value = null;
    problemItemKey.value = '';

    const addressProblems = validateAll();
    const paymentProblem = validatePayment();

    setError('payment', paymentProblem);

    if (addressProblems.length) {
        focusFirstError();

        return;
    }

    if (paymentProblem) {
        warning(paymentProblem);
        focusPaymentSection();

        return;
    }

    const address = deliveryAddress.value;

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
        delivery_address: address,
        shipping_method: shippingMethod.value,
        payment_method: selectedPayment.value.id,
        subtotal: subtotal.value,
        shipping_fee: shippingTotal.value,
        discount: 0,
        total: total.value
    };

    try {
        const createdOrders = await submitCheckout(orderPayload);
        const orders = Array.isArray(createdOrders) ? createdOrders : [];

        confirmation.value = {
            orders: orders.map(order => ({
                ...order,
                seller: sellerForOrder(order)
            })),
            address: { ...address },
            shipping: { ...selectedShipping.value },
            payment: { ...selectedPayment.value },
            paymentStatus: orders[0]?.payment_status || 'Unpaid',
            total: orders.reduce((sum, order) => sum + Number(order.total || 0), 0) || total.value
        };
        placedOrders.value = orders;

        if (addressMode.value === 'new' && saveNewAddress.value) {
            saveAddressForLater();
        }

        emit('place-order', createdOrders);

        await nextTick();
        window.scrollTo({ top: 0, behavior: 'auto' });
        confirmationHeading.value?.focus();
    } catch (err) {
        submitError.value = describeFailure(err);
        problemItemKey.value = submitError.value.itemKey || '';

        if (submitError.value.kind === 'payment') {
            setError('payment', submitError.value.field);
            toastError(submitError.value.field);
            submitError.value = null;
            focusPaymentSection();

            return;
        }

        await nextTick();
        errorBanner.value?.scrollIntoView({ block: 'center', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        errorBanner.value?.focus({ preventScroll: true });
    }
}

// The server returns seller_id, not a name; match each order back to its
// parcel through the items it contains.
function sellerForOrder(order) {
    const names = (order.items || []).map(item => item.name);
    const parcel = parcels.value.find(group => group.items.some(item => names.includes(item.name)));

    return parcel?.seller || '';
}

// Best effort: a failure here never affects the order already placed.
async function saveAddressForLater() {
    try {
        await addAddress({ ...newAddress, label: 'Home', makeDefault: addresses.value.length === 0 });
    } catch (err) {
        console.error('Could not save the delivery address:', err);
    }
}

function removeProblemItem(item) {
    submitError.value = null;
    problemItemKey.value = '';
    emit('remove-item', itemKey(item));
}

/*
|--------------------------------------------------------------------------
| Mobile total bar
|--------------------------------------------------------------------------
|
| On small screens the order total sits at the end of the page, right
| above Place order. A slim bar shows the total and scrolls to it; it never
| places the order itself, and it hides whenever the summary is on screen
| so it can't cover the total or the note under the button.
|
*/

const summaryCard = ref(null);
const isCompact = ref(false);
const isSummaryVisible = ref(true);

let compactQuery = null;
let summaryObserver = null;

function onCompactChange(event) {
    isCompact.value = event.matches;
}

function observeSummary() {
    summaryObserver?.disconnect();
    summaryObserver = null;

    if (!summaryCard.value || typeof IntersectionObserver === 'undefined') {
        isSummaryVisible.value = true;

        return;
    }

    summaryObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            isSummaryVisible.value = entry.isIntersecting;
        });
    });

    summaryObserver.observe(summaryCard.value);
}

watch(summaryCard, observeSummary);

onMounted(() => {
    compactQuery = window.matchMedia('(max-width: 960px)');
    isCompact.value = compactQuery.matches;
    compactQuery.addEventListener('change', onCompactChange);
    observeSummary();
});

onBeforeUnmount(() => {
    compactQuery?.removeEventListener('change', onCompactChange);
    summaryObserver?.disconnect();
});

const showTotalBar = computed(
    () => isCompact.value && !isSummaryVisible.value && !placedOrders.value && !isSignedOut.value && props.items.length > 0
);

function scrollToSummary() {
    summaryCard.value?.scrollIntoView({ block: 'start', behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
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
</script>

<template>

    <div
        class="buyer-page co-page"
        :class="{ 'has-total-bar': showTotalBar }"
    >

        <Header
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('edit-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <main class="co-content">

            <!-- ======================================================== -->
            <!-- CONFIRMATION -->
            <!-- ======================================================== -->

            <div
                v-if="confirmation"
                class="co-layout"
            >

                <div class="co-stack">

                    <section class="co-card">

                        <div class="co-done-head">
                            <span
                                class="co-done-mark"
                                aria-hidden="true"
                            >
                                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 6 9 17l-5-5" />
                                </svg>
                            </span>
                            <div>
                                <h1
                                    ref="confirmationHeading"
                                    class="co-title"
                                    tabindex="-1"
                                >
                                    Order placed
                                </h1>
                                <p class="co-lede">
                                    <template v-if="confirmation.orders.length > 1">
                                        Your checkout became {{ confirmation.orders.length }} orders, one per seller. Each ships separately.
                                    </template>
                                    <template v-else>
                                        Thanks, {{ confirmation.address.recipient_name }}. Your order is with the seller.
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div
                            v-for="order in confirmation.orders"
                            :key="order.id"
                            class="co-parcel"
                        >
                            <div class="co-parcel-head">
                                <strong>Order {{ order.id }}</strong>
                                <span v-if="order.seller">{{ order.seller }}</span>
                            </div>

                            <ul class="co-lines">
                                <li
                                    v-for="(line, index) in order.items"
                                    :key="index"
                                    class="co-line"
                                >
                                    <span
                                        class="co-thumb"
                                        :class="hasImage(itemForLine(line)) ? 'has-image' : 'accent-' + metaFor(itemForLine(line).category).accent"
                                    >
                                        <img
                                            v-if="hasImage(itemForLine(line))"
                                            :src="itemForLine(line).image"
                                            alt=""
                                            decoding="async"
                                            @error="markImageFailed(itemForLine(line))"
                                        >
                                        <span
                                            v-else
                                            class="product-image-icon"
                                            v-html="metaFor(itemForLine(line).category).icon"
                                        ></span>
                                    </span>
                                    <div class="co-line-info">
                                        <span class="co-line-name">{{ line.name }}</span>
                                        <span
                                            v-if="line.variant"
                                            class="co-line-meta"
                                        >{{ line.variant }}</span>
                                        <span class="co-line-meta">Qty {{ line.qty }}</span>
                                    </div>
                                    <span class="co-money">{{ formatPrice(line.price * line.qty) }}</span>
                                </li>
                            </ul>

                            <div class="co-row co-row--muted">
                                <span>Shipping ({{ confirmation.shipping.shortName }})</span>
                                <span class="co-money">{{ formatPrice(confirmation.shipping.fee) }}</span>
                            </div>
                            <div class="co-row co-row--strong">
                                <span>Pay on delivery</span>
                                <span class="co-money">{{ formatPrice(order.total) }}</span>
                            </div>
                        </div>

                    </section>

                    <section class="co-card">
                        <h2 class="co-card-title">What happens next</h2>
                        <ol class="co-next">
                            <li>The seller prepares your {{ confirmation.orders.length > 1 ? 'parcels' : 'parcel' }}. You can follow each order's status in My Orders.</li>
                            <li>{{ confirmation.shipping.name }} is estimated at {{ confirmation.shipping.eta }}.</li>
                            <li>
                                Have {{ formatPrice(confirmation.total) }} in cash ready<template v-if="confirmation.orders.length > 1">, paid separately to each courier</template>.
                            </li>
                        </ol>
                        <div class="co-actions">
                            <button
                                type="button"
                                class="co-btn co-btn--primary"
                                @click="emit('view-orders')"
                            >
                                View my orders
                            </button>
                            <button
                                type="button"
                                class="co-btn co-btn--secondary"
                                @click="emit('back')"
                            >
                                Continue shopping
                            </button>
                        </div>
                    </section>

                </div>

                <aside class="co-card co-summary">
                    <h2 class="co-card-title">Delivering to</h2>
                    <p class="co-address">
                        <strong>{{ confirmation.address.recipient_name }}</strong>
                        <span>{{ confirmation.address.contact_number }}</span>
                        <span>{{ confirmation.address.address }}</span>
                    </p>
                    <div class="co-row co-row--total">
                        <span>Total to pay</span>
                        <span class="co-money">{{ formatPrice(confirmation.total) }}</span>
                    </div>
                    <div class="co-row co-row--muted co-pay-row">
                        <span>Payment</span>
                        <span>{{ confirmation.payment.name }}</span>
                    </div>
                    <div class="co-row co-row--muted">
                        <span>Payment status</span>
                        <span>{{ confirmation.paymentStatus }}</span>
                    </div>
                    <p
                        v-if="confirmation.payment.id === 'cod'"
                        class="co-note"
                    >
                        Nothing was charged online. You pay the courier when your order arrives.
                    </p>
                </aside>

            </div>

            <!-- ======================================================== -->
            <!-- SIGNED OUT -->
            <!-- ======================================================== -->

            <div
                v-else-if="isSignedOut"
                class="co-gate"
            >
                <section class="co-card">
                    <h1 class="co-title">Sign in to check out</h1>
                    <p class="co-lede">
                        You need a buyer account to place an order. Your cart is saved on this device, so it'll be here when you come back.
                    </p>
                    <div class="co-actions">
                        <a
                            href="/login"
                            class="co-btn co-btn--primary"
                        >
                            Sign in
                        </a>
                        <button
                            type="button"
                            class="co-btn co-btn--secondary"
                            @click="emit('back')"
                        >
                            Keep browsing
                        </button>
                    </div>
                </section>
            </div>

            <!-- ======================================================== -->
            <!-- CHECKOUT -->
            <!-- ======================================================== -->

            <template v-else>

                <nav
                    class="product-breadcrumb co-breadcrumb"
                    aria-label="Breadcrumb"
                >
                    <button
                        type="button"
                        class="breadcrumb-link"
                        @click="emit('back')"
                    >
                        Home
                    </button>
                    <template v-if="source === 'cart'">
                        <span class="breadcrumb-separator">/</span>
                        <button
                            type="button"
                            class="breadcrumb-link"
                            @click="emit('edit-cart')"
                        >
                            Cart
                        </button>
                    </template>
                    <span class="breadcrumb-separator">/</span>
                    <span class="breadcrumb-current breadcrumb-current--active">Checkout</span>
                </nav>

                <h1 class="co-title">Checkout</h1>
                <p class="co-lede">Review your order. You pay the courier in cash when it arrives.</p>

                <div
                    v-if="isPlacingOrder"
                    class="co-banner co-banner--info"
                    role="status"
                >
                    <span
                        class="co-spinner co-spinner--dark"
                        aria-hidden="true"
                    ></span>
                    Sending your order. Please don't close or refresh this page.
                </div>

                <div class="co-layout">

                    <fieldset
                        class="co-stack co-fieldset"
                        :disabled="isPlacingOrder"
                    >

                        <!-- 1. Delivery address -->
                        <section
                            id="checkout-address-card"
                            class="co-card"
                            :class="{ 'has-error': errors.address }"
                            tabindex="-1"
                            aria-labelledby="checkout-address-title"
                        >
                            <div class="co-card-head">
                                <h2
                                    id="checkout-address-title"
                                    class="co-card-title"
                                >
                                    <span class="co-step">1</span>
                                    Delivery address
                                </h2>
                                <button
                                    v-if="addressMode === 'saved' && !isChoosingAddress"
                                    type="button"
                                    class="co-link"
                                    @click="isChoosingAddress = true"
                                >
                                    Change
                                </button>
                                <button
                                    v-else-if="addressMode === 'new' && addresses.length"
                                    type="button"
                                    class="co-link"
                                    @click="isChoosingAddress = true; addressMode = 'saved'"
                                >
                                    Use a saved address
                                </button>
                            </div>

                            <!-- Saved: chooser -->
                            <div
                                v-if="addressMode === 'saved' && isChoosingAddress"
                                class="co-options"
                                role="radiogroup"
                                aria-label="Saved addresses"
                            >
                                <label
                                    v-for="address in addresses"
                                    :key="address.id"
                                    class="co-option"
                                    :class="{ 'is-selected': selectedAddressId === address.id }"
                                >
                                    <input
                                        type="radio"
                                        name="saved-address"
                                        :value="address.id"
                                        :checked="selectedAddressId === address.id"
                                        @change="chooseSavedAddress(address.id)"
                                    >
                                    <span class="co-option-body">
                                        <strong>{{ address.fullName }}<span v-if="address.label" class="co-tag">{{ address.label }}</span></strong>
                                        <span>{{ formatAddressLine(address) }}</span>
                                    </span>
                                </label>
                                <button
                                    type="button"
                                    class="co-link co-link--block"
                                    @click="useNewAddress"
                                >
                                    + Deliver to a new address
                                </button>
                            </div>

                            <!-- Saved: summary -->
                            <div
                                v-else-if="addressMode === 'saved' && selectedSavedAddress"
                                class="co-address"
                            >
                                <strong>
                                    {{ selectedSavedAddress.fullName }}
                                    <span class="co-address-phone">{{ selectedSavedAddress.phone }}</span>
                                </strong>
                                <span>{{ formatAddressLine(selectedSavedAddress) }}</span>
                            </div>

                            <p
                                v-if="errors.address"
                                class="co-field-error"
                                role="alert"
                            >
                                {{ errors.address }}
                            </p>

                            <!-- New address form -->
                            <div
                                v-if="addressMode === 'new'"
                                class="co-form"
                            >
                                <div
                                    v-if="hasTriedSubmit && errorFields.length"
                                    class="co-banner co-banner--error"
                                    role="alert"
                                >
                                    <span class="co-banner-icon" aria-hidden="true">!</span>
                                    <div>
                                        <strong>{{ errorFields.length === 1 ? '1 detail needs attention' : `${errorFields.length} details need attention` }}</strong>
                                        Check the {{ errorFields.map(field => fieldLabels[field]).join(', ') }}.
                                    </div>
                                </div>

                                <div class="co-grid">
                                    <div class="co-field">
                                        <label for="checkout-name">Recipient name</label>
                                        <input
                                            id="checkout-name"
                                            v-model="newAddress.fullName"
                                            type="text"
                                            autocomplete="name"
                                            :aria-invalid="Boolean(errors.fullName)"
                                            :aria-describedby="errors.fullName ? 'checkout-name-error' : undefined"
                                            @blur="touch('fullName')"
                                            @input="revalidate('fullName')"
                                        >
                                        <p
                                            v-if="errors.fullName"
                                            id="checkout-name-error"
                                            class="co-field-error"
                                        >
                                            {{ errors.fullName }}
                                        </p>
                                    </div>

                                    <div class="co-field">
                                        <label for="checkout-phone">Contact number</label>
                                        <input
                                            id="checkout-phone"
                                            :value="newAddress.phone"
                                            type="tel"
                                            inputmode="numeric"
                                            autocomplete="tel-national"
                                            placeholder="09XXXXXXXXX"
                                            :aria-invalid="Boolean(errors.phone)"
                                            :aria-describedby="errors.phone ? 'checkout-phone-error checkout-phone-hint' : 'checkout-phone-hint'"
                                            @input="onPhoneInput"
                                            @blur="touch('phone')"
                                        >
                                        <p
                                            v-if="errors.phone"
                                            id="checkout-phone-error"
                                            class="co-field-error"
                                        >
                                            {{ errors.phone }}
                                        </p>
                                        <p
                                            id="checkout-phone-hint"
                                            class="co-hint"
                                        >
                                            The courier calls this number if they can't find you.
                                        </p>
                                    </div>

                                    <div class="co-field co-field--wide">
                                        <label for="checkout-line1">Street address, barangay</label>
                                        <input
                                            id="checkout-line1"
                                            v-model="newAddress.line1"
                                            type="text"
                                            autocomplete="address-line1"
                                            placeholder="House no., street, barangay"
                                            :aria-invalid="Boolean(errors.line1)"
                                            :aria-describedby="errors.line1 ? 'checkout-line1-error' : undefined"
                                            @blur="touch('line1')"
                                            @input="revalidate('line1')"
                                        >
                                        <p
                                            v-if="errors.line1"
                                            id="checkout-line1-error"
                                            class="co-field-error"
                                        >
                                            {{ errors.line1 }}
                                        </p>
                                    </div>

                                    <div class="co-field">
                                        <label for="checkout-city">City or municipality</label>
                                        <input
                                            id="checkout-city"
                                            v-model="newAddress.city"
                                            type="text"
                                            autocomplete="address-level2"
                                            :aria-invalid="Boolean(errors.city)"
                                            :aria-describedby="errors.city ? 'checkout-city-error' : undefined"
                                            @blur="touch('city')"
                                            @input="revalidate('city')"
                                        >
                                        <p
                                            v-if="errors.city"
                                            id="checkout-city-error"
                                            class="co-field-error"
                                        >
                                            {{ errors.city }}
                                        </p>
                                    </div>

                                    <div class="co-field">
                                        <label for="checkout-province">Province</label>
                                        <input
                                            id="checkout-province"
                                            v-model="newAddress.province"
                                            type="text"
                                            autocomplete="address-level1"
                                            :aria-invalid="Boolean(errors.province)"
                                            :aria-describedby="errors.province ? 'checkout-province-error' : undefined"
                                            @blur="touch('province')"
                                            @input="revalidate('province')"
                                        >
                                        <p
                                            v-if="errors.province"
                                            id="checkout-province-error"
                                            class="co-field-error"
                                        >
                                            {{ errors.province }}
                                        </p>
                                    </div>

                                    <div class="co-field">
                                        <label for="checkout-postal">Postal code <span class="co-optional">(optional)</span></label>
                                        <input
                                            id="checkout-postal"
                                            v-model="newAddress.postalCode"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="postal-code"
                                        >
                                    </div>

                                    <label class="co-check co-field--wide">
                                        <input
                                            v-model="saveNewAddress"
                                            type="checkbox"
                                        >
                                        Save this address for next time
                                    </label>
                                </div>
                            </div>
                        </section>

                        <!-- 2. Items and shipping -->
                        <section
                            class="co-card"
                            aria-labelledby="checkout-items-title"
                        >
                            <div class="co-card-head">
                                <h2
                                    id="checkout-items-title"
                                    class="co-card-title"
                                >
                                    <span class="co-step">2</span>
                                    Items and shipping
                                </h2>
                                <button
                                    v-if="source === 'cart'"
                                    type="button"
                                    class="co-link"
                                    @click="emit('edit-cart')"
                                >
                                    Edit cart
                                </button>
                            </div>

                            <div
                                v-if="submitError"
                                ref="errorBanner"
                                class="co-banner"
                                :class="submitError.kind === 'unknown' ? 'co-banner--warn' : 'co-banner--error'"
                                role="alert"
                                tabindex="-1"
                            >
                                <span class="co-banner-icon" aria-hidden="true">!</span>
                                <div>
                                    <strong>{{ submitError.title }}</strong>
                                    {{ submitError.message }}
                                    <div
                                        v-if="submitError.kind === 'unknown' || submitError.kind === 'auth'"
                                        class="co-banner-actions"
                                    >
                                        <button
                                            v-if="submitError.kind === 'unknown'"
                                            type="button"
                                            class="co-btn co-btn--small"
                                            @click="emit('view-orders')"
                                        >
                                            Check My Orders
                                        </button>
                                        <a
                                            v-else
                                            href="/login"
                                            class="co-btn co-btn--small"
                                        >
                                            Sign in
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-for="(parcel, parcelIndex) in parcels"
                                :key="parcel.seller"
                                class="co-parcel"
                            >
                                <div class="co-parcel-head">
                                    <span>
                                        <template v-if="parcels.length > 1">Parcel {{ parcelIndex + 1 }} of {{ parcels.length }} from </template>
                                        <template v-else>From </template>
                                        <strong>{{ parcel.seller }}</strong>
                                    </span>
                                    <span>{{ pluralize(parcel.items.reduce((count, item) => count + Number(item.quantity), 0), 'item') }}</span>
                                </div>

                                <ul class="co-lines">
                                    <li
                                        v-for="item in parcel.items"
                                        :key="itemKey(item)"
                                        class="co-line"
                                        :class="{ 'is-problem': problemItemKey === itemKey(item) }"
                                    >
                                        <span
                                            class="co-thumb"
                                            :class="hasImage(item) ? 'has-image' : 'accent-' + metaFor(item.category).accent"
                                        >
                                            <img
                                                v-if="hasImage(item)"
                                                :src="item.image"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                                @error="markImageFailed(item)"
                                            >
                                            <span
                                                v-else
                                                class="product-image-icon"
                                                v-html="metaFor(item.category).icon"
                                            ></span>
                                        </span>
                                        <div class="co-line-info">
                                            <span class="co-line-name">{{ item.name }}</span>
                                            <span
                                                v-if="item.variation"
                                                class="co-line-meta"
                                            >{{ item.variation }}</span>
                                            <span class="co-line-meta">
                                                Qty {{ item.quantity }}<template v-if="Number(item.quantity) > 1"> × {{ formatPrice(item.price) }}</template>
                                            </span>
                                        </div>
                                        <span class="co-money">{{ formatPrice(item.price * item.quantity) }}</span>

                                        <div
                                            v-if="problemItemKey === itemKey(item)"
                                            class="co-line-fix"
                                        >
                                            <span>Nothing was ordered.</span>
                                            <button
                                                v-if="items.length > 1"
                                                type="button"
                                                class="co-btn co-btn--small"
                                                @click="removeProblemItem(item)"
                                            >
                                                Remove this item
                                            </button>
                                            <button
                                                v-if="source === 'cart'"
                                                type="button"
                                                class="co-btn co-btn--small"
                                                @click="emit('edit-cart')"
                                            >
                                                Back to cart
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                class="co-btn co-btn--small"
                                                @click="emit('back')"
                                            >
                                                Keep browsing
                                            </button>
                                        </div>
                                    </li>
                                </ul>

                                <div class="co-row co-row--muted">
                                    <span>Shipping for this {{ parcels.length > 1 ? 'parcel' : 'order' }}</span>
                                    <span class="co-money">{{ formatPrice(selectedShipping.fee) }}</span>
                                </div>
                            </div>

                            <div class="co-parcel">
                                <p
                                    id="checkout-shipping-label"
                                    class="co-parcel-head"
                                >
                                    <span>
                                        <strong>Shipping method</strong>
                                        <template v-if="parcels.length > 1"> (applies to every parcel)</template>
                                    </span>
                                </p>
                                <div
                                    class="co-options"
                                    role="radiogroup"
                                    aria-labelledby="checkout-shipping-label"
                                >
                                    <label
                                        v-for="option in shippingOptions"
                                        :key="option.id"
                                        class="co-option"
                                        :class="{ 'is-selected': shippingMethod === option.id }"
                                    >
                                        <input
                                            v-model="shippingMethod"
                                            type="radio"
                                            name="shipping"
                                            :value="option.id"
                                        >
                                        <span class="co-option-body">
                                            <strong>{{ option.shortName }}</strong>
                                            <span>{{ option.description }}</span>
                                        </span>
                                        <span class="co-money">
                                            {{ formatPrice(option.fee) }}<template v-if="parcels.length > 1"> per parcel</template>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <!-- 3. Payment -->
                        <section
                            id="checkout-payment-card"
                            class="co-card"
                            :class="{ 'has-error': errors.payment }"
                            aria-labelledby="checkout-payment-title"
                        >
                            <div class="co-card-head">
                                <h2
                                    id="checkout-payment-title"
                                    class="co-card-title"
                                >
                                    <span class="co-step">3</span>
                                    Choose how to pay
                                </h2>
                            </div>
                            <PaymentMethodSelector
                                v-model="paymentMethod"
                                :methods="paymentMethods"
                                :error="errors.payment"
                                :disabled="isPlacingOrder"
                            />
                            <p
                                v-if="selectedPayment?.id === 'cod'"
                                class="co-note"
                            >
                                <template v-if="parcels.length > 1">
                                    Each seller ships separately, so you'll pay each courier:
                                    <template
                                        v-for="(parcel, index) in parcels"
                                        :key="parcel.seller"
                                    >{{ index > 0 ? ', ' : '' }}{{ formatPrice(parcel.subtotal + selectedShipping.fee) }} to {{ parcel.seller }}</template>.
                                </template>
                                <template v-else>
                                    You'll pay {{ formatPrice(total) }} to the courier.
                                </template>
                            </p>
                        </section>

                    </fieldset>

                    <!-- Order total -->
                    <aside
                        ref="summaryCard"
                        class="co-card co-summary"
                        aria-labelledby="checkout-total-title"
                    >
                        <h2
                            id="checkout-total-title"
                            class="co-card-title"
                        >
                            Order total
                        </h2>

                        <dl class="co-rows">
                            <div class="co-row">
                                <dt>Items ({{ itemCount }})</dt>
                                <dd class="co-money">{{ formatPrice(subtotal) }}</dd>
                            </div>
                            <div class="co-row">
                                <dt>
                                    Shipping<template v-if="parcels.length > 1"> ({{ parcels.length }} parcels × {{ formatPrice(selectedShipping.fee) }})</template>
                                </dt>
                                <dd class="co-money">{{ formatPrice(shippingTotal) }}</dd>
                            </div>
                            <div
                                v-if="selectedPayment?.fee"
                                class="co-row"
                            >
                                <dt>{{ selectedPayment.name }} fee</dt>
                                <dd class="co-money">{{ formatPrice(paymentFee) }}</dd>
                            </div>
                            <div class="co-row co-row--total">
                                <dt>Total</dt>
                                <dd class="co-money">{{ formatPrice(total) }}</dd>
                            </div>
                        </dl>

                        <div class="co-row co-row--muted co-pay-row">
                            <span>Payment</span>
                            <span>{{ selectedPayment ? selectedPayment.name : 'Not chosen yet' }}</span>
                        </div>
                        <p
                            v-if="selectedPayment?.id === 'cod'"
                            class="co-note"
                        >
                            Nothing is charged now. Your order stays unpaid until you pay the courier.
                        </p>
                        <p
                            v-else-if="!availableMethods.length"
                            class="co-note"
                        >
                            No payment method is available for this order.
                        </p>

                        <button
                            type="button"
                            class="co-place"
                            :disabled="isPlacingOrder || items.length === 0 || !availableMethods.length"
                            @click="placeOrder"
                        >
                            <template v-if="isPlacingOrder">
                                <span
                                    class="co-spinner"
                                    aria-hidden="true"
                                ></span>
                                Placing your order…
                            </template>
                            <template v-else>
                                Place order · {{ formatPrice(total) }}
                            </template>
                        </button>

                        <p
                            class="co-after"
                            aria-live="polite"
                        >
                            <template v-if="isPlacingOrder">Keep this page open. This usually takes a few seconds.</template>
                            <template v-else-if="parcels.length > 1">This creates {{ parcels.length }} orders, one per seller, and shows your order numbers.</template>
                            <template v-else>Next, you'll see your order number.</template>
                        </p>
                    </aside>

                </div>

            </template>

        </main>

        <Transition name="co-bar">
            <div
                v-if="showTotalBar"
                class="co-total-bar"
            >
                <div>
                    <strong class="co-money">{{ formatPrice(total) }}</strong>
                    <span>Total, cash on delivery</span>
                </div>
                <button
                    type="button"
                    @click="scrollToSummary"
                >
                    Review total
                </button>
            </div>
        </Transition>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('edit-cart')"
        />

    </div>

</template>

<style scoped>
.co-page {
    background: var(--nx-bg);
    color: var(--nx-ink);
}

.co-page.has-total-bar {
    padding-bottom: 76px;
}

.co-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 40px 64px;
}

.co-breadcrumb {
    max-width: none;
    margin: 0 0 12px;
}

.co-title {
    margin: 0;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.3px;
    outline: none;
}

.co-lede {
    margin: 4px 0 22px;
    color: var(--nx-muted);
    font-size: 14px;
}

.co-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 28px;
    align-items: start;
}

.co-stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}

.co-fieldset {
    margin: 0;
    padding: 0;
    border: 0;
}

.co-fieldset:disabled {
    opacity: 0.6;
}

.co-card {
    padding: 20px 22px;
    border: 1px solid var(--nx-border);
    border-radius: 14px;
    background: #ffffff;
    outline: none;
}

.co-card.has-error {
    border-color: #fca5a5;
}

.co-card-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.co-card-title {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin: 0 0 14px;
    font-size: 16px;
    font-weight: 800;
}

.co-card-head .co-card-title {
    margin: 0;
}

.co-step {
    display: inline-grid;
    place-items: center;
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: var(--nx-ink);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    transform: translateY(-1px);
}

.co-link {
    padding: 4px 0;
    border: 0;
    background: none;
    color: var(--nx-accent-dark);
    font: inherit;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
}

.co-link:hover {
    text-decoration: underline;
}

.co-link--block {
    align-self: flex-start;
    margin-top: 2px;
}

.co-address {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    font-size: 14.5px;
    color: var(--nx-text-2);
}

.co-address strong {
    color: var(--nx-ink);
}

.co-address-phone {
    margin-left: 8px;
    color: var(--nx-muted);
    font-weight: 500;
}

/* Form */

.co-form {
    display: flex;
    flex-direction: column;
}

.co-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px 14px;
}

.co-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.co-field--wide {
    grid-column: 1 / -1;
}

.co-field label {
    font-size: 13px;
    font-weight: 700;
}

.co-optional {
    color: var(--nx-muted);
    font-weight: 500;
}

.co-field input {
    height: 44px;
    padding: 0 12px;
    border: 1.5px solid var(--nx-line);
    border-radius: 10px;
    background: #ffffff;
    color: var(--nx-ink);
    font: inherit;
    font-size: 15px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.co-field input:focus {
    outline: none;
    border-color: var(--nx-accent);
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.15);
}

.co-field input[aria-invalid='true'] {
    border-color: #dc2626;
    background: #fffafa;
}

.co-hint {
    margin: 0;
    color: var(--nx-muted);
    font-size: 12.5px;
}

.co-field-error {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    margin: 0;
    color: #b91c1c;
    font-size: 12.5px;
    font-weight: 600;
}

.co-field-error::before {
    content: '!';
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    margin-top: 1px;
    border-radius: 999px;
    background: #b91c1c;
    color: #ffffff;
    font-size: 11px;
    font-weight: 800;
}

.co-card > .co-field-error {
    margin-top: 10px;
}

.co-check {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--nx-text-2);
    font-size: 13.5px;
    cursor: pointer;
}

.co-check input {
    width: 16px;
    height: 16px;
    accent-color: var(--nx-accent);
}

/* Banners */

.co-banner {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 10px;
    font-size: 13.5px;
    line-height: 1.5;
    outline: none;
}

.co-banner strong {
    display: block;
}

.co-banner--error {
    background: #fef2f2;
    color: #7f1d1d;
}

.co-banner--warn {
    background: #fffbeb;
    color: #78350f;
}

.co-banner--info {
    align-items: center;
    background: var(--nx-line-soft);
    color: var(--nx-text-2);
}

.co-banner-icon {
    display: inline-grid;
    place-items: center;
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    border-radius: 999px;
    background: #b91c1c;
    color: #ffffff;
    font-weight: 800;
    font-size: 12px;
}

.co-banner--warn .co-banner-icon {
    background: #b45309;
}

.co-banner-actions {
    margin-top: 10px;
}

/* Parcels and lines */

.co-parcel {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--nx-line-soft);
}

.co-card-head + .co-parcel,
.co-banner + .co-parcel,
.co-done-head + .co-parcel {
    margin-top: 0;
    padding-top: 0;
    border-top: 0;
}

.co-parcel-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin: 0 0 8px;
    color: var(--nx-muted);
    font-size: 13px;
}

.co-parcel-head strong {
    color: var(--nx-ink);
}

.co-lines {
    margin: 0;
    padding: 0;
    list-style: none;
}

.co-line {
    display: grid;
    grid-template-columns: 56px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 8px 0;
}

.co-line.is-problem {
    margin: 4px -10px;
    padding: 10px;
    border-radius: 10px;
    background: #fef2f2;
}

.co-thumb {
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    border-radius: 10px;
    background: var(--accent-bg);
    overflow: hidden;
}

.co-thumb.has-image {
    background: var(--nx-line-soft);
}

.co-thumb img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.co-thumb .product-image-icon {
    width: 28px;
    height: 28px;
}

.co-line-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.co-line-name {
    font-weight: 650;
    overflow-wrap: anywhere;
}

.co-line-meta {
    color: var(--nx-muted);
    font-size: 13px;
}

.co-line-fix {
    grid-column: 2 / -1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    color: #7f1d1d;
    font-size: 13px;
    font-weight: 600;
}

.co-money {
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
}

.co-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 14px;
}

.co-row dt,
.co-row dd {
    margin: 0;
}

.co-row dd.co-money {
    font-weight: 600;
}

.co-row--muted {
    padding-top: 6px;
    color: var(--nx-text-2);
    font-size: 13.5px;
}

.co-row--muted .co-money {
    font-weight: 600;
}

.co-row--strong {
    padding-top: 4px;
    font-weight: 700;
}

.co-row--total {
    margin-top: 4px;
    padding-top: 12px;
    border-top: 1px solid var(--nx-line-soft);
    font-size: 18px;
    font-weight: 800;
}

.co-row--total dd.co-money {
    font-weight: 800;
}

/* Options */

.co-options {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.co-option {
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 12px 14px;
    border: 1.5px solid var(--nx-line);
    border-radius: 10px;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.co-option:hover {
    border-color: var(--nx-muted-2);
}

.co-option.is-selected {
    border-color: var(--nx-ink);
    background: var(--nx-sunken);
}

.co-option:has(input:focus-visible) {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.co-option input {
    width: 18px;
    height: 18px;
    margin: 0;
    accent-color: var(--nx-ink);
}

.co-pay-row {
    margin-top: 12px;
}

.co-option-body {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.co-option-body strong {
    font-size: 14px;
}

.co-option-body > span {
    color: var(--nx-muted);
    font-size: 13px;
}

.co-tag {
    margin-left: 8px;
    padding: 1px 6px;
    border-radius: 4px;
    background: var(--nx-line-soft);
    color: var(--nx-muted);
    font-size: 11px;
    font-weight: 700;
}

.co-note {
    margin: 12px 0 0;
    color: var(--nx-text-2);
    font-size: 13px;
}

/* Summary */

.co-summary {
    position: sticky;
    top: 120px;
}

.co-rows {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
}

.co-place {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 52px;
    margin-top: 14px;
    border: 0;
    border-radius: 10px;
    background: var(--nx-accent);
    color: #ffffff;
    font: inherit;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.1s ease;
}

.co-place:hover:not(:disabled) {
    background: var(--nx-accent-dark);
}

.co-place:active:not(:disabled) {
    transform: scale(0.98);
}

.co-place:disabled {
    background: #5fb3aa;
    cursor: progress;
}

.co-place:focus-visible,
.co-btn:focus-visible,
.co-link:focus-visible {
    outline: 2px solid var(--nx-ink);
    outline-offset: 2px;
}

.co-after {
    margin: 10px 0 0;
    color: var(--nx-muted);
    font-size: 12.5px;
}

.co-spinner {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #ffffff;
    border-radius: 999px;
    animation: co-spin 0.8s linear infinite;
}

.co-spinner--dark {
    border-color: rgba(15, 23, 42, 0.2);
    border-top-color: var(--nx-ink);
}

@keyframes co-spin {
    to {
        transform: rotate(360deg);
    }
}

/* Buttons */

.co-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 18px;
}

.co-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: 0 18px;
    border-radius: 10px;
    font: inherit;
    font-size: 14px;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
}

.co-btn--primary {
    border: 0;
    background: var(--nx-accent);
    color: #ffffff;
}

.co-btn--primary:hover {
    background: var(--nx-accent-dark);
}

.co-btn--secondary {
    border: 1.5px solid var(--nx-line);
    background: #ffffff;
    color: var(--nx-ink);
}

.co-btn--small {
    min-height: 34px;
    padding: 0 12px;
    border: 1.5px solid currentColor;
    border-radius: 8px;
    background: #ffffff;
    color: inherit;
    font-size: 13px;
}

/* Confirmation */

.co-done-head {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 20px;
}

.co-done-head .co-lede {
    margin-bottom: 0;
}

.co-done-mark {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 999px;
    background: var(--nx-accent-soft);
    color: var(--nx-accent-dark);
}

.co-next {
    margin: 0;
    padding-left: 18px;
    color: var(--nx-text-2);
    font-size: 14px;
}

.co-next li {
    margin-bottom: 6px;
}

.co-summary .co-address {
    margin-bottom: 16px;
}

/* Signed out */

.co-gate {
    max-width: 560px;
    margin: 24px auto;
}

/* Mobile total bar */

.co-total-bar {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 16px calc(12px + env(safe-area-inset-bottom));
    border-top: 1px solid var(--nx-border);
    background: #ffffff;
    box-shadow: 0 -6px 18px rgba(15, 23, 42, 0.06);
}

.co-total-bar strong {
    display: block;
    font-size: 16px;
    text-align: left;
}

.co-total-bar span {
    color: var(--nx-muted);
    font-size: 12px;
}

.co-total-bar button {
    min-height: 44px;
    padding: 0 16px;
    border: 1.5px solid var(--nx-ink);
    border-radius: 10px;
    background: #ffffff;
    color: var(--nx-ink);
    font: inherit;
    font-weight: 700;
    cursor: pointer;
}

.co-bar-enter-active,
.co-bar-leave-active {
    transition: transform 0.2s ease, opacity 0.2s ease;
}

.co-bar-enter-from,
.co-bar-leave-to {
    transform: translateY(100%);
    opacity: 0;
}

@media (max-width: 960px) {
    .co-content {
        padding: 16px 16px 40px;
    }

    .co-layout {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .co-summary {
        position: static;
    }

    .co-title {
        font-size: 22px;
    }
}

@media (max-width: 640px) {
    .co-card {
        padding: 16px;
        border-radius: 12px;
    }

    .co-grid {
        grid-template-columns: 1fr;
    }

    .co-line {
        grid-template-columns: 48px minmax(0, 1fr) auto;
    }

    .co-thumb {
        width: 48px;
        height: 48px;
    }

    .co-option {
        grid-template-columns: 20px minmax(0, 1fr);
    }

    .co-option > .co-money {
        grid-column: 2;
        text-align: left;
    }

    .co-field input {
        font-size: 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .co-spinner {
        animation-duration: 2.4s;
    }

    .co-bar-enter-active,
    .co-bar-leave-active,
    .co-place,
    .co-option {
        transition: none;
    }
}
</style>

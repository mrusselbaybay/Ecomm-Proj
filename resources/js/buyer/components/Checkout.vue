<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { buyerApi } from '../composables/useBuyerApi';
import { useBuyerAccount } from '../composables/useBuyerAccount';
import { useBuyerCoupons } from '../composables/useBuyerCoupons';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { useBuyerPayments } from '../composables/useBuyerPayments';
import { metaFor } from '../composables/useCategoryMeta';
import { useConfirm } from '../composables/useConfirm';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';
import { useToasts } from '../composables/useToasts';
import AddressPinPicker from '../../shared/AddressPinPicker.vue';
import AddressForm from './AddressForm.vue';
import Footer from './Footer.vue';
import Header from './Header.vue';

// Renamed on import: this component's own placeOrder() below is the
// click handler (validates the form, builds the payload); it calls this
// composable function to actually submit to the backend.
const { placeOrder: submitCheckout, isPlacingOrder } = useBuyer();
const { success, error: toastError, warning, info } = useToasts();
const { confirm } = useConfirm();

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
    'browse-categories',
    'manage-addresses'
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
| Delivery address — saved-address switcher
|--------------------------------------------------------------------------
|
| Recipient name / contact / address are read-only here and always mirror
| one source:
|   1. The selected SAVED address (buyer_addresses) — the buyer's default
|      (else most recently used) until they switch. Its id goes to the
|      server as delivery_address.address_id, which routes the order
|      (area matching, same-day eligibility) off that address.
|   2. With no saved addresses: the account profile + on-file address
|      (public.addresses) — the original behaviour — with an offer to
|      save it as a reusable address.
|
*/

const {
    addresses,
    defaultAddress,
    hasAddresses,
    isLoading: isAddressBookLoading,
    selectedAddressId,
    formatAddress,
    addAddress,
    updateAddress,
    ADDRESS_LABELS
} = useBuyerAddresses();
const {
    profile: buyerProfile,
    address: buyerAddress,
    isLoadingProfile,
    loadBuyerAccount
} = useBuyerAccount();

// `profile`/`address` are shared module state — skip the refetch if a
// prior visit to Account already populated them this session.
if (!buyerProfile.value) {
    loadBuyerAccount();
}

// True while the delivery details may still change under the buyer.
// Placing an order in that window could submit stale/blank details, so
// both the button and placeOrder() guard on this.
const isDeliveryDetailsLoading = computed(
    () => (isAddressBookLoading.value && !hasAddresses.value) || isLoadingProfile.value
);

/*
| Exact map pin — required to place an order (riders and the tracking map
| need the door, not the barangay). Pinned inline here and saved straight
| onto the selected address, so the buyer never leaves checkout.
*/
const selectedNeedsPin = computed(() => {
    const saved = selectedAddress.value;

    if (saved) {
        return saved.isComplete && !saved.pin;
    }

    return !!buyerAddress.value && buyerAddress.value.latitude == null;
});
const isSavingPin = ref(false);

async function saveSelectedPin(pin) {
    const saved = selectedAddress.value;

    if (!pin || !saved || isSavingPin.value) {
        return;
    }

    isSavingPin.value = true;

    try {
        await updateAddress(saved.id, { ...saved, makeDefault: saved.isDefault, pin });
        success('Location pinned.');
    } catch (err) {
        toastError(err?.message || 'Could not save the pin. Please try again.');
    } finally {
        isSavingPin.value = false;
    }
}

// Falls back to the default when nothing is picked yet or the picked one
// was deleted (here or on the Saved Addresses page).
const selectedAddress = computed(
    () => addresses.value.find(a => a.id === selectedAddressId.value) || defaultAddress.value || null
);

watch(selectedAddress, address => {
    selectedAddressId.value = address?.id || null;
}, { immediate: true });

const buyerRegion = computed(
    () => (selectedAddress.value ? selectedAddress.value.region : buyerAddress.value?.region_name) || ''
);

function profileAddressText(address) {
    if (!address) {
        return '';
    }

    return [
        address.house_no,
        address.street,
        address.barangay,
        address.municipality_name,
        address.province_name,
        address.region_name
    ]
        .filter(Boolean)
        .join(', ');
}

function applyPrefill() {
    const saved = selectedAddress.value;

    checkoutForm.recipientName = saved?.fullName || buyerProfile.value?.full_name || '';
    checkoutForm.contactNumber = toLocalMobile(saved?.phone || buyerProfile.value?.contact_no || '');
    checkoutForm.address = saved ? formatAddress(saved) : profileAddressText(buyerAddress.value);
}

watch([selectedAddress, buyerProfile, buyerAddress], applyPrefill, { immediate: true });

// Switcher UI state.
const isPickerOpen = ref(false);
const isAddingAddress = ref(false);
const isSavingAddress = ref(false);

function chooseAddress(address) {
    selectedAddressId.value = address.id;
    isPickerOpen.value = false;
}

// Pre-fills the inline form from the account when the buyer has no saved
// addresses yet — "save the address you're using" in one step.
const newAddressSeed = computed(() => {
    if (hasAddresses.value) {
        return null;
    }

    const a = buyerAddress.value;

    return {
        fullName: buyerProfile.value?.full_name || '',
        phone: buyerProfile.value?.contact_no || '',
        region: a?.region_name || '',
        provinceCode: a?.province_code || '',
        province: a?.province_name || '',
        municipalityCode: a?.municipality_code || '',
        city: a?.municipality_name || '',
        barangay: a?.barangay || '',
        houseNo: a?.house_no || '',
        line1: a?.street || '',
        isDefault: true
    };
});

// No saved address and nothing usable on the account → open the form
// straight away instead of showing an empty, read-only block.
watch(isDeliveryDetailsLoading, loading => {
    if (!loading && !hasAddresses.value && !checkoutForm.address) {
        isAddingAddress.value = true;
    }
}, { immediate: true });

async function saveNewAddress(payload) {
    isSavingAddress.value = true;

    try {
        const created = await addAddress(payload);

        // Use the address just added, even if it isn't the default.
        if (created?.id) {
            selectedAddressId.value = created.id;
        }

        isAddingAddress.value = false;
        isPickerOpen.value = false;
        success('Address saved and selected.');
    } catch (err) {
        toastError(err?.message || 'Could not save the address. Please try again.');
    } finally {
        isSavingAddress.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Coupons (seller-funded, one per line)
|--------------------------------------------------------------------------
|
| The server quotes the buyer's usable wallet coupons per line (discount
| on ONE unit, best first) and picks the auto-apply coupon. The buyer can
| switch or remove it; checkout re-validates and re-prices server-side.
|
*/

const { quote: quoteCoupons, markUsed: markCouponsUsed } = useBuyerCoupons();

const lineKey = item => `${item.productId}-${item.variantId || 'simple'}`;
const couponQuote = ref({});
const selectedCoupons = reactive({});
const touchedCoupons = new Set();
const couponPickerKey = ref(null);

async function loadCouponQuote() {
    const lines = props.items
        .filter(item => item.productId)
        .map(item => ({ key: lineKey(item), product_id: item.productId, variant_id: item.variantId || null }));

    if (!lines.length) {
        return;
    }

    try {
        couponQuote.value = await quoteCoupons(lines);
    } catch {
        couponQuote.value = {};
    }

    for (const line of lines) {
        const options = couponQuote.value[line.key]?.options || [];

        // Keep a manual choice if it's still valid; otherwise auto-apply the best.
        if (!touchedCoupons.has(line.key) || !options.some(o => o.id === selectedCoupons[line.key])) {
            selectedCoupons[line.key] = couponQuote.value[line.key]?.best || null;
        }
    }
}

watch(() => props.items.map(lineKey).join(','), loadCouponQuote, { immediate: true });

function couponOptions(item) {
    return couponQuote.value[lineKey(item)]?.options || [];
}

function appliedCoupon(item) {
    const id = selectedCoupons[lineKey(item)];

    return id ? couponOptions(item).find(o => o.id === id) || null : null;
}

function isAutoApplied(item) {
    const key = lineKey(item);

    return !touchedCoupons.has(key) && selectedCoupons[key] === couponQuote.value[key]?.best;
}

// The same wallet coupon can't sit on two lines.
function couponTakenElsewhere(item, couponId) {
    const key = lineKey(item);

    return Object.entries(selectedCoupons).some(([k, id]) => k !== key && id === couponId);
}

function selectCoupon(item, couponId) {
    const key = lineKey(item);
    touchedCoupons.add(key);
    selectedCoupons[key] = couponId;
    couponPickerKey.value = null;
}

function toggleCouponPicker(item) {
    const key = lineKey(item);
    couponPickerKey.value = couponPickerKey.value === key ? null : key;
}

/*
|--------------------------------------------------------------------------
| Shipping Options
|--------------------------------------------------------------------------
*/

// Fees + Same Day availability come from CheckoutService (single source
// of truth). Each seller ships their own parcel, so the fee is per seller.
const shippingOptions = ref([]);
const shippingSellerCount = ref(1);
const isLoadingShipping = ref(false);
const shippingLoadError = ref('');

async function loadShippingOptions() {
    const ids = [...new Set(props.items.map(item => item.productId).filter(Boolean))];

    if (!ids.length) {
        return;
    }

    isLoadingShipping.value = true;
    shippingLoadError.value = '';

    try {
        // Same-day eligibility depends on the destination province, so
        // quote against the selected saved address when it's routable.
        const addressId = selectedAddress.value?.isComplete ? selectedAddress.value.id : null;
        const query = ids.map(id => `product_ids[]=${encodeURIComponent(id)}`)
            .concat(addressId ? [`address_id=${encodeURIComponent(addressId)}`] : [])
            .join('&');
        const data = await buyerApi(`/buyer/checkout/shipping-options?${query}`);

        shippingOptions.value = data.options;
        shippingSellerCount.value = Math.max(1, data.sellerCount || 1);

        const current = data.options.find(o => o.id === checkoutForm.shippingMethod);

        if (!current?.available) {
            checkoutForm.shippingMethod = 'standard';
        }
    } catch (err) {
        shippingLoadError.value = err?.message || 'Could not load shipping options.';
    } finally {
        isLoadingShipping.value = false;
    }
}

watch(
    () => props.items.map(item => item.productId).join(',')
        + `|${selectedAddress.value?.isComplete ? selectedAddress.value.id : ''}`,
    loadShippingOptions,
    { immediate: true }
);

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
    const selected = shippingOptions.value.find(
        option =>
            option.id === checkoutForm.shippingMethod
    );

    return selected
        ? Number(selected.fee) * shippingSellerCount.value
        : 0;
});

/*
|--------------------------------------------------------------------------
| Discount — sum of per-line coupon discounts (one unit each)
|--------------------------------------------------------------------------
*/

const discount = computed(() =>
    props.items.reduce((sum, item) => sum + (appliedCoupon(item)?.discount || 0), 0)
);

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
        warning('There are no items to check out.');

        return;
    }

    if (isDeliveryDetailsLoading.value) {
        warning('Please wait — we\'re still loading your recipient and address details.');

        return;
    }

    if (isAddingAddress.value) {
        warning('Save or cancel the new address first.');

        return;
    }

    if (selectedAddress.value && !selectedAddress.value.isComplete) {
        warning('This address is missing its province, city or barangay. Update it in Saved Addresses or pick another.');
        isPickerOpen.value = true;

        return;
    }

    if (selectedNeedsPin.value) {
        warning(selectedAddress.value
            ? 'Pin your exact delivery location on the map first.'
            : 'Add a delivery address with a map pin first.');
        document.getElementById('checkout-pin')?.scrollIntoView({ behavior: 'smooth', block: 'center' });

        return;
    }

    if (!checkoutForm.recipientName.trim()) {
        warning('Add a recipient name to your account or a saved address before checking out.');

        return;
    }

    if (!checkoutForm.contactNumber.trim()) {
        warning('Add a contact number to your account or a saved address before checking out.');

        return;
    }

    if (!isValidLocalMobile(checkoutForm.contactNumber)) {
        warning('The contact number on file isn\'t a valid 11-digit mobile number. Please update it in Account or Saved Addresses.');

        return;
    }

    if (!checkoutForm.address.trim()) {
        warning('Add a delivery address to your account or save one before checking out.');

        return;
    }

    if (!checkoutForm.shippingMethod) {
        warning('Please select a shipping method.');

        return;
    }

    const paymentProblem = validatePaymentSelection();

    if (paymentProblem) {
        paymentError.value = paymentProblem;
        warning(paymentProblem);

        return;
    }

    paymentError.value = '';

    // Final confirmation before money/stock moves — a real order is a
    // significant, hard-to-undo action, so spell out exactly what's about
    // to happen (count, total, payment, recipient) in an accessible
    // dialog rather than submitting on the first click.
    const itemCount = props.items.reduce((count, item) => count + Number(item.quantity), 0);
    const paymentLabel = paymentMethods.find(method => method.id === checkoutForm.paymentMethod)?.name
        || 'the selected method';

    const confirmed = await confirm({
        title: 'Place this order?',
        message:
            `You're ordering ${itemCount} ${itemCount === 1 ? 'item' : 'items'} for `
            + `${formatPrice(total.value)}, paying with ${paymentLabel}. `
            + `Delivering to ${checkoutForm.recipientName}.`,
        confirmLabel: 'Place order',
        cancelLabel: 'Keep reviewing',
    });

    if (!confirmed) {
        return;
    }

    info('Placing your order…');

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
            coupon_id: selectedCoupons[lineKey(item)] || null,

            quantity: Number(item.quantity),
            unit_price: Number(item.price)
        })),

        delivery_address: {
            address_id: selectedAddress.value?.id || null,
            recipient_name: checkoutForm.recipientName,
            contact_number: checkoutForm.contactNumber,
            address: checkoutForm.address
        },

        shipping_method: checkoutForm.shippingMethod,

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

        markCouponsUsed(orderPayload.items.map(item => item.coupon_id).filter(Boolean));

        // Saving the card/wallet for next time is best-effort and makes
        // its own API call(s) — kick it off without blocking, so it never
        // sits between the buyer and their order confirmation.
        persistPaymentDetails();

        emit('place-order', createdOrders);

        // Include the real order reference(s) so the buyer has something
        // concrete to look for in My Orders. Checkout can split into one
        // order per seller.
        const orders = Array.isArray(createdOrders) ? createdOrders : [];
        let reference = '';

        if (orders.length === 1 && orders[0]?.id) {
            reference = ` Order ${orders[0].id}.`;
        } else if (orders.length > 1) {
            reference = ` ${orders.length} orders created (one per seller).`;
        }

        success(`Order placed successfully.${reference} Total ${formatPrice(total.value)}.`, {
            timeout: 7000,
        });
    } catch (err) {
        toastError(
            err?.message
                || 'We couldn\'t place your order. Nothing was charged — please check your details and try again.',
        );

        // A coupon may have expired or run out meanwhile — re-quote.
        loadCouponQuote();
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
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" />
                                        <circle cx="12" cy="10" r="3" />
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h2>Delivery Address</h2>
                                    <p>Where should we deliver your order?</p>
                                </div>
                                <button
                                    v-if="hasAddresses"
                                    type="button"
                                    class="shrink-0 inline-flex items-center gap-1.5 min-h-[44px] px-2 text-sm font-bold text-[#0d9488] hover:text-[#0f766e]"
                                    @click="emit('manage-addresses')"
                                >
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" />
                                    </svg>
                                    Manage
                                </button>
                            </div>

                            <!-- Loading -->
                            <div v-if="isDeliveryDetailsLoading" class="space-y-2 animate-pulse" aria-busy="true" aria-label="Loading delivery address">
                                <div class="h-4 w-1/3 bg-slate-100 rounded" />
                                <div class="h-4 w-2/3 bg-slate-100 rounded" />
                                <div class="h-4 w-1/2 bg-slate-100 rounded" />
                            </div>

                            <!-- Inline add -->
                            <div v-else-if="isAddingAddress" class="rounded-2xl border border-slate-200 p-5">
                                <p class="text-sm font-bold text-slate-900 mb-4">
                                    {{ hasAddresses ? 'Add a new address' : 'Save a delivery address' }}
                                </p>
                                <AddressForm
                                    :initial="newAddressSeed"
                                    :labels="ADDRESS_LABELS"
                                    :submitting="isSavingAddress"
                                    :show-default-toggle="hasAddresses"
                                    submit-label="Save & use this address"
                                    id-prefix="checkout-addr"
                                    @submit="saveNewAddress"
                                    @cancel="isAddingAddress = false"
                                />
                            </div>

                            <!-- Auto-filled from the selected saved address (or the account) -->
                            <template v-else>
                                <div class="checkout-form-grid">
                                    <div class="checkout-field">
                                        <label for="checkout-recipient">Recipient Name</label>
                                        <input id="checkout-recipient" :value="checkoutForm.recipientName" type="text" placeholder="Not set on your account" readonly>
                                    </div>
                                    <div class="checkout-field">
                                        <label for="checkout-contact">Contact Number</label>
                                        <input id="checkout-contact" :value="checkoutForm.contactNumber" type="text" placeholder="Not set on your account" readonly>
                                    </div>
                                    <div class="checkout-field">
                                        <label for="checkout-region">Region</label>
                                        <input id="checkout-region" :value="buyerRegion" type="text" placeholder="Not set on your account" readonly>
                                    </div>
                                    <div class="checkout-field checkout-field-full">
                                        <label for="checkout-address" class="flex items-center gap-2">
                                            Complete Address
                                            <span v-if="selectedAddress" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 uppercase tracking-wide">{{ selectedAddress.label }}</span>
                                            <span v-if="selectedAddress?.isDefault" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-teal-100 text-[#0f766e] uppercase tracking-wide">Default</span>
                                        </label>
                                        <div class="relative">
                                            <textarea
                                                id="checkout-address"
                                                :value="checkoutForm.address"
                                                rows="2"
                                                class="!pr-28"
                                                placeholder="Not set on your account"
                                                readonly
                                            ></textarea>
                                            <button
                                                type="button"
                                                class="absolute right-2 top-1/2 -translate-y-1/2 min-h-[40px] px-4 rounded-lg border border-slate-200 bg-white text-sm font-bold text-[#0d9488] hover:bg-teal-50 hover:border-[#0d9488] transition-colors"
                                                :aria-expanded="isPickerOpen"
                                                aria-controls="checkout-address-picker"
                                                @click="isPickerOpen = !isPickerOpen"
                                            >
                                                {{ isPickerOpen ? 'Close' : 'Change' }}
                                            </button>
                                        </div>
                                        <small v-if="selectedAddress && !selectedAddress.isComplete" class="checkout-field-hint !text-amber-700">
                                            Missing province, city or barangay. Update it in
                                            <button type="button" class="font-bold underline underline-offset-2" @click="emit('manage-addresses')">Saved Addresses</button>
                                            or pick another.
                                        </small>

                                        <div v-if="selectedNeedsPin && selectedAddress" id="checkout-pin" class="mt-3">
                                            <AddressPinPicker
                                                :model-value="selectedAddress.pin || null"
                                                :disabled="isSavingPin"
                                                :street="selectedAddress.line1"
                                                :barangay="selectedAddress.barangay"
                                                :municipality="selectedAddress.city"
                                                :province="selectedAddress.province"
                                                hint="Required before ordering — pin your door so the rider finds you."
                                                @update:model-value="saveSelectedPin"
                                            />
                                        </div>
                                        <small v-else-if="selectedNeedsPin" id="checkout-pin" class="checkout-field-hint !text-amber-700">
                                            Your address has no map pin. Add a delivery address (with its pin) using “Change”.
                                        </small>
                                    </div>
                                </div>

                                <div v-if="isPickerOpen" id="checkout-address-picker" class="mt-3">
                                    <div v-if="hasAddresses" class="checkout-option-list" role="radiogroup" aria-label="Saved addresses">
                                        <label
                                            v-for="address in addresses"
                                            :key="address.id"
                                            class="checkout-option"
                                            :class="{ active: address.id === selectedAddress?.id }"
                                        >
                                            <input
                                                type="radio"
                                                name="checkout-address"
                                                :value="address.id"
                                                :checked="address.id === selectedAddress?.id"
                                                @change="chooseAddress(address)"
                                            >
                                            <div class="checkout-option-info min-w-0">
                                                <strong>
                                                    {{ address.fullName }}
                                                    <small class="ml-1 text-[10px] font-bold uppercase tracking-wide text-slate-500">{{ address.label }}{{ address.isDefault ? ' · Default' : '' }}</small>
                                                </strong>
                                                <span class="break-words">{{ formatAddress(address) }}</span>
                                                <span v-if="!address.isComplete" class="!text-amber-700 font-semibold">Needs update before use</span>
                                                <span v-else-if="!address.pin" class="!text-amber-700 font-semibold">No map pin — you'll pin it before ordering</span>
                                            </div>
                                        </label>
                                    </div>
                                    <button
                                        type="button"
                                        class="mt-3 w-full min-h-[44px] inline-flex items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 text-sm font-bold text-[#0d9488] hover:bg-teal-50 hover:border-[#0d9488] transition-colors"
                                        @click="isAddingAddress = true"
                                    >
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M5 12h14" /><path d="M12 5v14" />
                                        </svg>
                                        Add new address
                                    </button>
                                </div>
                            </template>
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

                            <template
                                v-for="item in items"
                                :key="lineKey(item)"
                            >
                            <div class="checkout-item">

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

                            <div
                                v-if="couponOptions(item).length"
                                class="checkout-coupon"
                            >
                                <div v-if="appliedCoupon(item)" class="checkout-coupon-applied">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10" /></svg>
                                    <strong>{{ appliedCoupon(item).label }}</strong>
                                    <span>{{ isAutoApplied(item) ? 'auto-applied' : 'applied' }}</span>
                                    <span v-if="appliedCoupon(item).runningLow" class="checkout-coupon-low">Only {{ appliedCoupon(item).remaining }} left</span>
                                    <span class="checkout-coupon-amount">−{{ formatPrice(appliedCoupon(item).discount) }}</span>
                                </div>
                                <div v-else class="checkout-coupon-applied is-none">
                                    <span>{{ couponOptions(item).length }} coupon{{ couponOptions(item).length > 1 ? 's' : '' }} available for this item</span>
                                </div>
                                <div class="checkout-coupon-actions">
                                    <button type="button" @click="toggleCouponPicker(item)">
                                        {{ appliedCoupon(item) ? 'Change' : 'Apply coupon' }}
                                    </button>
                                    <button v-if="appliedCoupon(item)" type="button" @click="selectCoupon(item, null)">Remove</button>
                                </div>

                                <div v-if="couponPickerKey === lineKey(item)" class="checkout-coupon-picker" role="radiogroup" :aria-label="`Coupons for ${item.name}`">
                                    <label
                                        v-for="option in couponOptions(item)"
                                        :key="option.id"
                                        class="checkout-coupon-option"
                                        :class="{ active: selectedCoupons[lineKey(item)] === option.id, disabled: couponTakenElsewhere(item, option.id) }"
                                    >
                                        <input
                                            type="radio"
                                            :name="`coupon-${lineKey(item)}`"
                                            :checked="selectedCoupons[lineKey(item)] === option.id"
                                            :disabled="couponTakenElsewhere(item, option.id)"
                                            @change="selectCoupon(item, option.id)"
                                        >
                                        <span class="checkout-coupon-badge">{{ option.label }}</span>
                                        <span class="checkout-coupon-meta">
                                            −{{ formatPrice(option.discount) }} on 1 unit · expires {{ new Date(option.expiresAt).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) }}
                                            <span v-if="option.runningLow" class="checkout-coupon-low">Only {{ option.remaining }} left</span>
                                        </span>
                                        <span v-if="option.id === couponQuote[lineKey(item)]?.best" class="checkout-coupon-best">Best</span>
                                    </label>
                                </div>
                            </div>
                            </template>

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

                            <div v-if="isLoadingShipping && !shippingOptions.length" class="checkout-option-list" aria-busy="true">
                                <div v-for="n in 3" :key="n" class="checkout-option checkout-option-skeleton" />
                            </div>

                            <div v-else-if="shippingLoadError" class="checkout-shipping-error" role="alert">
                                <span>{{ shippingLoadError }}</span>
                                <button type="button" @click="loadShippingOptions">Retry</button>
                            </div>

                            <div v-else class="checkout-option-list">

                                <label
                                    v-for="option in shippingOptions"
                                    :key="option.id"
                                    class="checkout-option"
                                    :class="{ active: checkoutForm.shippingMethod === option.id, disabled: !option.available }"
                                    :title="option.unavailableReason || ''"
                                >

                                    <input
                                        v-model="checkoutForm.shippingMethod"
                                        type="radio"
                                        name="shipping"
                                        :value="option.id"
                                        :disabled="!option.available"
                                    >

                                    <div class="checkout-option-info">
                                        <strong>{{ option.name }}</strong>
                                        <span>{{ option.available ? option.description : option.unavailableReason }}</span>
                                    </div>

                                    <strong class="checkout-option-price">
                                        {{ formatPrice(option.fee) }}
                                        <small v-if="shippingSellerCount > 1" class="checkout-option-per">× {{ shippingSellerCount }} sellers</small>
                                    </strong>

                                </label>

                            </div>

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
                                    <span>Coupon Discount</span>
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
                                :disabled="isPlacingOrder || isDeliveryDetailsLoading"
                                @click="placeOrder"
                            >
                                {{
                                    isPlacingOrder
                                        ? 'Placing Order…'
                                        : (isDeliveryDetailsLoading ? 'Loading details' : 'Place Order')
                                }}
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

.checkout-field-hint {
    display: block;
    margin-top: 6px;
    color: var(--nx-muted);
    font-size: 11.5px;
}

@media (max-width: 640px) {
    .payment-method-grid {
        grid-template-columns: 1fr;
    }
}
.checkout-option.disabled { opacity: .55; cursor: not-allowed; }
.checkout-option-per { display: block; font-size: 11px; font-weight: 500; color: #64748b; }
.checkout-option-skeleton { height: 64px; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: checkout-shimmer 1.2s infinite; }
@keyframes checkout-shimmer { to { background-position: -200% 0; } }
.checkout-shipping-error { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border-radius: 10px; background: #fef2f2; color: #b91c1c; font-size: 13px; }
.checkout-shipping-error button { border: 0; background: none; color: inherit; font-weight: 600; cursor: pointer; text-decoration: underline; }
.checkout-coupon { margin: -4px 0 12px; padding: 10px 12px; border: 1px dashed #fca5a5; border-radius: 12px; background: #fef2f2; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
.checkout-coupon-applied { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; flex: 1; min-width: 0; color: #b91c1c; font-size: 13px; }
.checkout-coupon-applied.is-none { color: #7f1d1d; }
.checkout-coupon-applied strong { font-weight: 800; }
.checkout-coupon-applied > span:not(.checkout-coupon-amount):not(.checkout-coupon-low) { color: #64748b; }
.checkout-coupon-amount { margin-left: auto; font-weight: 800; }
.checkout-coupon-low { padding: 1px 8px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 800; }
.checkout-coupon-actions { display: flex; gap: 4px; }
.checkout-coupon-actions button { min-height: 32px; padding: 0 12px; border: 0; border-radius: 8px; background: #fff; color: #b91c1c; font-size: 12px; font-weight: 700; cursor: pointer; }
.checkout-coupon-actions button:hover { background: #fee2e2; }
.checkout-coupon-picker { flex-basis: 100%; display: grid; gap: 6px; }
.checkout-coupon-option { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 10px; background: #fff; border: 1px solid #fecaca; cursor: pointer; }
.checkout-coupon-option.active { border-color: #dc2626; box-shadow: 0 0 0 2px rgba(220, 38, 38, .15); }
.checkout-coupon-option.disabled { opacity: .5; cursor: not-allowed; }
.checkout-coupon-option input { accent-color: #dc2626; }
.checkout-coupon-badge { padding: 4px 10px; border-radius: 8px; background: #dc2626; color: #fff; font-size: 12px; font-weight: 800; white-space: nowrap; }
.checkout-coupon-meta { flex: 1; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; color: #475569; font-size: 12px; }
.checkout-coupon-best { padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 800; }
</style>
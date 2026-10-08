<script setup>
/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| Cart → Checkout → Confirmation. There is no separate payment step: the
| only method checkout supports is cash on delivery (CheckoutOptions).
|
| Every amount on this page comes from the server's quote
| (POST /api/buyer/checkout/quote, CheckoutService::quote): today's price
| for each line and whether it can still be bought, each store's shipping
| options and fee, and the totals. The quote is refreshed whenever the
| items, a store's shipping or the payment method change; while it
| updates, the old numbers stay (dimmed) and the order can't be placed.
|
| Placing the order sends the total the buyer is looking at
| (expected_total) and an idempotency key:
|   - if anything changed in the meantime, nothing is ordered (409) and
|     the page shows what changed with the new amounts, for the buyer to
|     confirm again;
|   - a double click, or a retry after a dropped connection, reuses the
|     same key, so the server returns the orders it already created
|     instead of creating them twice.
| Cart lines are removed (by Dashboard) only after orders are created.
|
| Not offered because the platform doesn't support them: vouchers and
| discounts (the server ignores voucher codes), notes to sellers (orders
| have nowhere to keep one), online payment.
|
| Choices live in useCheckoutState, so going back to the cart and
| returning keeps them; they're re-checked by the next quote.
|
*/
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import Footer from './Footer.vue';
import Header from './Header.vue';
import OrderItemThumb from './OrderItemThumb.vue';
import { useBuyer } from '../composables/useBuyer';
import { buyerApi } from '../composables/useBuyerApi';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { useBuyerSession } from '../composables/useBuyerSession';
import { formatPrice } from '../composables/useCategoryMeta';
import { attemptKeyFor, finishAttempt, useCheckoutState } from '../composables/useCheckoutState';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';
import { useToasts } from '../composables/useToasts';

const props = defineProps({
    items: {
        type: Array,
        default: () => []
    },
    // 'cart' or 'buy-now': decides what "Edit" means for the items.
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

const state = useCheckoutState();
const { loadOrders } = useBuyer();
const { buyerProfile, isLoadingSession } = useBuyerSession();
const { addresses, defaultAddress, addAddress, updateAddress, isLoading: isLoadingAddresses } = useBuyerAddresses();
const { success: toastSuccess } = useToasts();

const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const isSignedOut = computed(() => !isLoadingSession.value && !buyerProfile.value);

function lineKey(item) {
    return `${item.productId}-${item.variantId || 'simple'}`;
}

const itemsSignature = computed(() => props.items.map(item => `${lineKey(item)}x${item.quantity}`).join('|'));

/*
|--------------------------------------------------------------------------
| Quote
|--------------------------------------------------------------------------
*/

const quote = ref(null);
const quoteStatus = ref('loading');
const quoteError = ref('');
let quoteSeq = 0;
let quoteTimer = null;

function requestBody() {
    return {
        items: props.items.map(item => ({
            product_id: item.productId,
            variant_id: item.variantId || null,
            quantity: Number(item.quantity),
            variation: item.variation || null
        })),
        shipping_methods: { ...state.shippingBySeller },
        payment_method: state.paymentMethod
    };
}

function applyQuote(data) {
    quote.value = data;

    // Show the shipping method the server actually priced for each store.
    for (const seller of data.sellers || []) {
        if (seller.seller_id) {
            state.shippingBySeller[seller.seller_id] = seller.shipping.method;
        }
    }

    const methods = (data.payment_methods || []).map(method => method.id);

    if (methods.length && !methods.includes(state.paymentMethod)) {
        state.paymentMethod = methods[0];
    }
}

async function refreshQuote() {
    if (!props.items.length || isSignedOut.value) {
        return;
    }

    const id = ++quoteSeq;

    quoteStatus.value = 'loading';
    quoteError.value = '';

    try {
        const data = await buyerApi('/buyer/checkout/quote', {
            method: 'POST',
            body: JSON.stringify(requestBody())
        });

        if (id !== quoteSeq) {
            return;
        }

        applyQuote(data);
        quoteStatus.value = 'ready';
    } catch (err) {
        if (id !== quoteSeq) {
            return;
        }

        quoteStatus.value = 'error';
        quoteError.value = err?.status === 401
            ? 'Please sign in again to see your total.'
            : 'We couldn’t work out your total. Check your connection and try again.';
    }
}

function scheduleQuote() {
    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(refreshQuote, 150);
}

watch(() => JSON.stringify(requestBody()), scheduleQuote);
watch(isSignedOut, signedOut => {
    if (!signedOut) {
        scheduleQuote();
    }
});

const sellers = computed(() => quote.value?.sellers || []);
const totals = computed(() => quote.value?.totals || null);

// Once the total has changed (a re-quote after a shipping choice, a removed
// item, a changed price), each new total settles in (.nx-value-in) so the
// change is noticed. Never on the first total.
const totalChanged = ref(false);

watch(() => totals.value?.total, (next, previous) => {
    if (previous != null && next != null && next !== previous) {
        totalChanged.value = true;
    }
});
const isUpdating = computed(() => quoteStatus.value === 'loading' && Boolean(quote.value));
const problemLines = computed(() => sellers.value.flatMap(seller => seller.items.filter(item => !item.available)));
const paymentMethods = computed(() => quote.value?.payment_methods || []);
const selectedPayment = computed(() => paymentMethods.value.find(method => method.id === state.paymentMethod) || null);

function chooseShipping(sellerId, method) {
    state.shippingBySeller[sellerId] = method;
}

function removeLine(item) {
    emit('remove-item', item.key);
}

/*
|--------------------------------------------------------------------------
| Delivery address
|--------------------------------------------------------------------------
|
| A saved address (the default first), or one typed here — saved to the
| address book or used for this order only. A saved address can be edited
| in place. Nothing typed is lost when switching between them.
|
*/

const isChoosingAddress = ref(false);
const isAddressFormOpen = ref(false);
const isSavingAddress = ref(false);
const addressSaveError = ref('');
const errors = reactive({});
const touched = reactive({});
const triedSubmit = ref(false);

const FIELDS = ['fullName', 'phone', 'line1', 'city', 'province'];

const selectedSavedAddress = computed(() => addresses.value.find(address => address.id === state.selectedAddressId) || null);

const isFormPristine = () => !['line1', 'city', 'province'].some(field => String(state.form[field] || '').trim());

// Start from the default address (or what was chosen before); with an
// empty address book, the form. Decided only once the address book has
// loaded — an empty list while it's still loading means "not yet", not
// "none".
watch([addresses, defaultAddress, isLoadingAddresses], () => {
    if (isLoadingAddresses.value) {
        return;
    }

    if (addresses.value.length && state.addressMode === 'new' && !isAddressFormOpen.value && isFormPristine()) {
        state.addressMode = 'saved';
    }

    if (state.addressMode === 'saved' && !selectedSavedAddress.value) {
        if (defaultAddress.value) {
            state.selectedAddressId = defaultAddress.value.id;
        } else if (!addresses.value.length) {
            state.addressMode = 'new';
            isAddressFormOpen.value = true;
        }
    }
}, { immediate: true });

watch(buyerProfile, profile => {
    if (profile && !state.form.fullName && !state.form.phone && state.addressMode === 'new') {
        state.form.fullName = [profile.first_name, profile.last_name].filter(Boolean).join(' ');
        state.form.phone = toLocalMobile(profile.contact_no || '');
    }
}, { immediate: true });

function formatAddressLine(address) {
    return [address.line1, address.city, address.province, address.postalCode]
        .map(part => String(part || '').trim())
        .filter(Boolean)
        .join(', ');
}

function fieldError(field) {
    const value = String(state.form[field] ?? '').trim();

    switch (field) {
        case 'fullName':
            return value ? '' : 'Enter the name of the person receiving the parcel.';
        case 'phone':
            if (!value) {
                return 'Enter a mobile number so the courier can reach you.';
            }

            return isValidLocalMobile(value) ? '' : 'Enter an 11-digit mobile number starting with 09.';
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

function touch(field) {
    touched[field] = true;
    errors[field] = fieldError(field);
}

function onFieldInput(field) {
    if (field === 'phone') {
        state.form.phone = toLocalMobile(state.form.phone);
    }

    if (touched[field] || triedSubmit.value) {
        errors[field] = fieldError(field);
    }
}

function validateForm() {
    FIELDS.forEach(field => {
        errors[field] = fieldError(field);
    });

    return FIELDS.filter(field => errors[field]);
}

// What's wrong with the chosen saved address, if anything.
const savedAddressProblem = computed(() => {
    const saved = selectedSavedAddress.value;

    if (state.addressMode !== 'saved') {
        return '';
    }

    if (!saved) {
        return 'Choose where to deliver your order.';
    }

    if (!isValidLocalMobile(toLocalMobile(saved.phone || ''))) {
        return 'This address has no valid mobile number. Edit it to add one.';
    }

    if (!saved.line1 || !saved.city || !saved.province) {
        return 'This address is incomplete. Edit it to add the street, city and province.';
    }

    return '';
});

const deliveryAddress = computed(() => {
    const source = state.addressMode === 'saved' ? selectedSavedAddress.value : state.form;

    if (!source) {
        return null;
    }

    return {
        recipient_name: String(source.fullName || '').trim(),
        contact_number: toLocalMobile(source.phone || ''),
        address: formatAddressLine(source),
        city: String(source.city || '').trim() || null,
        province: String(source.province || '').trim() || null
    };
});

function chooseSaved(id) {
    state.addressMode = 'saved';
    state.selectedAddressId = id;
    isChoosingAddress.value = false;
    isAddressFormOpen.value = false;
    addressSaveError.value = '';
}

function startNewAddress() {
    state.addressMode = 'new';
    state.editingAddressId = '';
    isChoosingAddress.value = false;
    isAddressFormOpen.value = true;
    addressSaveError.value = '';

    if (!state.form.fullName && buyerProfile.value) {
        state.form.fullName = [buyerProfile.value.first_name, buyerProfile.value.last_name].filter(Boolean).join(' ');
        state.form.phone = toLocalMobile(buyerProfile.value.contact_no || '');
    }

    nextTick(() => document.getElementById('ck-fullName')?.focus());
}

function startEdit(address) {
    state.addressMode = 'edit';
    state.editingAddressId = address.id;
    Object.assign(state.form, {
        fullName: address.fullName || '',
        phone: toLocalMobile(address.phone || ''),
        line1: address.line1 || '',
        city: address.city || '',
        province: address.province || '',
        postalCode: address.postalCode || ''
    });
    FIELDS.forEach(field => delete errors[field]);
    isChoosingAddress.value = false;
    isAddressFormOpen.value = true;
    addressSaveError.value = '';
    nextTick(() => document.getElementById('ck-fullName')?.focus());
}

function cancelAddressForm() {
    isAddressFormOpen.value = false;
    addressSaveError.value = '';

    if (state.addressMode === 'edit' || (state.addressMode === 'new' && addresses.value.length)) {
        state.addressMode = 'saved';
    }
}

async function submitAddressForm() {
    triedSubmit.value = true;

    if (validateForm().length) {
        nextTick(() => document.querySelector('.ck-field.has-error input')?.focus());

        return;
    }

    // A new address used for this order only.
    if (state.addressMode === 'new' && !state.saveNewAddress) {
        isAddressFormOpen.value = false;

        return;
    }

    isSavingAddress.value = true;
    addressSaveError.value = '';

    try {
        if (state.addressMode === 'edit') {
            const original = addresses.value.find(address => address.id === state.editingAddressId);

            await updateAddress(state.editingAddressId, { ...state.form, label: original?.label, makeDefault: original?.isDefault });
            chooseSaved(state.editingAddressId);
        } else {
            const before = new Set(addresses.value.map(address => address.id));

            await addAddress({ ...state.form, label: 'Home', makeDefault: addresses.value.length === 0 });

            const created = addresses.value.find(address => !before.has(address.id));

            if (created) {
                chooseSaved(created.id);
            } else {
                isAddressFormOpen.value = false;
            }
        }

        toastSuccess('Address saved.');
    } catch (err) {
        addressSaveError.value = err?.message || 'We couldn’t save this address. Please try again.';
    } finally {
        isSavingAddress.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Place order
|--------------------------------------------------------------------------
*/

const isPlacing = ref(false);
const submitError = ref(null);
const changes = ref([]);
const confirmation = ref(null);
const errorBanner = ref(null);
const confirmationHeading = ref(null);

const placeBlocker = computed(() => {
    if (isSignedOut.value) {
        return 'Sign in to place your order.';
    }

    if (quoteStatus.value === 'loading') {
        return 'Updating your total…';
    }

    if (quoteStatus.value === 'error' || !totals.value) {
        return 'Your total couldn’t be calculated yet.';
    }

    if (problemLines.value.length) {
        return `${problemLines.value.length === 1 ? 'An item needs' : `${problemLines.value.length} items need`} attention before you can order.`;
    }

    return '';
});

function priceChanges(before, after) {
    const list = [];
    const oldLines = new Map((before?.sellers || []).flatMap(seller => seller.items).map(item => [item.key, item]));
    const oldShipping = new Map((before?.sellers || []).map(seller => [seller.seller_id, seller]));

    for (const seller of after.sellers || []) {
        for (const item of seller.items) {
            const old = oldLines.get(item.key);

            if (item.problem && (!old || !old.problem)) {
                list.push(`${item.name}: ${item.problem}`);
            } else if (old && old.unit_price !== item.unit_price) {
                list.push(`${item.name}: ${formatPrice(old.unit_price)} → ${formatPrice(item.unit_price)} each`);
            }
        }

        const oldSeller = oldShipping.get(seller.seller_id);

        if (oldSeller && oldSeller.shipping.fee !== seller.shipping.fee) {
            list.push(`Shipping from ${seller.store_name}: ${formatPrice(oldSeller.shipping.fee)} → ${formatPrice(seller.shipping.fee)}`);
        }
    }

    if (before?.totals && before.totals.total !== after.totals.total) {
        list.push(`Order total: ${formatPrice(before.totals.total)} → ${formatPrice(after.totals.total)}`);
    }

    return list;
}

async function showSubmitError(error) {
    submitError.value = error;
    await nextTick();
    errorBanner.value?.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' });
    errorBanner.value?.focus({ preventScroll: true });
}

async function placeOrder() {
    if (isPlacing.value || confirmation.value) {
        return;
    }

    triedSubmit.value = true;
    submitError.value = null;

    if (state.addressMode !== 'saved' || isAddressFormOpen.value) {
        if (validateForm().length) {
            isAddressFormOpen.value = true;
            await nextTick();
            document.getElementById('ck-address')?.scrollIntoView({ block: 'start', behavior: reducedMotion ? 'auto' : 'smooth' });
            document.querySelector('.ck-field.has-error input')?.focus({ preventScroll: true });

            return;
        }

        if (state.addressMode === 'edit') {
            showSubmitError({ title: 'Save your address first.', message: 'Save the changes to this address (or cancel them), then place your order.' });

            return;
        }
    } else if (savedAddressProblem.value) {
        await nextTick();
        document.getElementById('ck-address')?.scrollIntoView({ block: 'start', behavior: reducedMotion ? 'auto' : 'smooth' });
        document.getElementById('ck-address-card')?.focus({ preventScroll: true });

        return;
    }

    if (placeBlocker.value) {
        showSubmitError({ title: 'Your order isn’t ready yet.', message: placeBlocker.value });

        return;
    }

    isPlacing.value = true;

    const confirmedQuote = quote.value;

    try {
        const orders = await buyerApi('/buyer/checkout', {
            method: 'POST',
            body: JSON.stringify({
                ...requestBody(),
                delivery_address: deliveryAddress.value,
                payment_method: state.paymentMethod,
                expected_total: confirmedQuote.totals.total,
                idempotency_key: attemptKeyFor(itemsSignature.value)
            })
        });

        finishAttempt();
        changes.value = [];
        confirmation.value = {
            orders: Array.isArray(orders) ? orders : [],
            address: { ...deliveryAddress.value },
            quote: confirmedQuote
        };

        emit('place-order', orders);
        loadOrders();

        await nextTick();
        window.scrollTo({ top: 0, behavior: 'auto' });
        confirmationHeading.value?.focus();
    } catch (err) {
        const code = err?.body?.code;

        if (err?.status === 409 && code === 'quote_changed' && err.body.quote) {
            changes.value = priceChanges(confirmedQuote, err.body.quote);
            applyQuote(err.body.quote);
            quoteStatus.value = 'ready';
            showSubmitError({
                kind: 'changed',
                title: 'Some amounts changed since you started checking out.',
                message: 'Nothing was ordered. Review the updated total, then place your order again.'
            });
        } else if (err?.status === 409 && code === 'in_progress') {
            showSubmitError({
                kind: 'progress',
                title: 'Your order is already being placed.',
                message: 'Give it a moment, then check My Orders before trying again.'
            });
        } else if (err?.status === 422) {
            refreshQuote();
            showSubmitError({ title: 'Your order wasn’t placed.', message: `${err.message} Your address and choices are still here.` });
        } else if (err?.status === 401 || err?.status === 403) {
            showSubmitError({ title: 'Please sign in again.', message: 'Your session has ended. Sign in, then come back — your cart and choices are saved.' });
        } else if (!err?.status) {
            showSubmitError({
                kind: 'network',
                title: 'We couldn’t confirm your order.',
                message: 'The connection dropped before we got an answer. Try again — if it already went through, you won’t be charged or ordered twice.'
            });
        } else {
            showSubmitError({ title: 'Your order wasn’t placed.', message: 'Something went wrong on our side. Nothing was ordered and your details are still here. Please try again.' });
        }

        // No toast: the banner beside Place order explains it (and a toast
        // would linger over the confirmation once a retry succeeds).
    } finally {
        isPlacing.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Mobile action bar
|--------------------------------------------------------------------------
|
| Under 1024px the total and Place order stay at the bottom of the screen.
| The bar hides while a field is focused, so it never sits on top of the
| on-screen keyboard or the field being typed in; the page reserves room
| for it so it never covers content.
|
*/

const isTyping = ref(false);

function onFocusIn(event) {
    isTyping.value = event.target.matches?.('input:not([type=radio]):not([type=checkbox]), textarea, select') || false;
}

function onFocusOut() {
    isTyping.value = false;
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(() => {
    document.addEventListener('focusin', onFocusIn);
    document.addEventListener('focusout', onFocusOut);
    refreshQuote();
});

onBeforeUnmount(() => {
    clearTimeout(quoteTimer);
    quoteSeq++;
    document.removeEventListener('focusin', onFocusIn);
    document.removeEventListener('focusout', onFocusOut);
});

function handleHeaderSelectCategory(category) {
    emit('select-category', category);
}

const paymentIcons = {
    cod: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>'
};
</script>

<template>

    <div
        class="buyer-page ck-page"
        :class="{ 'has-bar': !confirmation && !isSignedOut }"
    >

        <Header
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('edit-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('back')"
            @search="emit('search', $event)"
        />

        <main
            id="main-content"
            class="buyer-main ck-main"
            tabindex="-1"
        >

            <!-- Progress: Cart → Checkout → Confirmation (cash on delivery
                 needs no separate payment step) -->
            <nav
                class="ck-steps"
                aria-label="Checkout progress"
            >
                <ol>
                    <li class="is-done">
                        <span class="ck-step-dot" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        </span>
                        Cart
                    </li>
                    <li
                        :class="confirmation ? 'is-done' : 'is-current'"
                        :aria-current="confirmation ? undefined : 'step'"
                    >
                        <span class="ck-step-dot" aria-hidden="true">
                            <svg v-if="confirmation" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                            <template v-else>2</template>
                        </span>
                        Checkout
                    </li>
                    <li
                        :class="{ 'is-current': confirmation }"
                        :aria-current="confirmation ? 'step' : undefined"
                    >
                        <span class="ck-step-dot" aria-hidden="true">3</span>
                        Confirmation
                    </li>
                </ol>
            </nav>

            <!-- ================================================================ -->
            <!-- CONFIRMATION -->
            <!-- ================================================================ -->

            <section
                v-if="confirmation"
                class="ck-done"
                aria-labelledby="ck-done-title"
            >
                <header class="ck-done-head">
                    <span class="ck-done-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                    </span>
                    <div>
                        <h1
                            id="ck-done-title"
                            ref="confirmationHeading"
                            class="ck-title"
                            tabindex="-1"
                        >
                            Order placed
                        </h1>
                        <p class="ck-lede">
                            <template v-if="confirmation.orders.length > 1">
                                Your items ship from {{ confirmation.orders.length }} stores, so they were placed as {{ confirmation.orders.length }} orders.
                            </template>
                            <template v-else>
                                The store has your order and will start preparing it.
                            </template>
                            Pay in cash when each parcel arrives.
                        </p>
                    </div>
                </header>

                <ul class="ck-done-orders">
                    <li
                        v-for="order in confirmation.orders"
                        :key="order.id"
                        class="ck-done-order"
                    >
                        <div class="ck-done-order-head">
                            <div>
                                <p class="ck-store-name">{{ order.store_name }}</p>
                                <p class="ck-done-number">Order {{ order.id }}</p>
                            </div>
                            <dl class="ck-done-status">
                                <div>
                                    <dt>Order</dt>
                                    <dd>{{ order.status === 'New' ? 'Placed' : order.status }}</dd>
                                </div>
                                <div>
                                    <dt>Payment</dt>
                                    <dd>{{ order.payment_method_name }} · {{ order.payment_status === 'Unpaid' ? 'Pay on delivery' : order.payment_status }}</dd>
                                </div>
                            </dl>
                        </div>
                        <ul class="ck-done-items">
                            <li
                                v-for="(item, index) in order.items"
                                :key="`${order.id}-${index}`"
                            >
                                <span class="ck-done-item-name">
                                    {{ item.name }}<template v-if="item.variant"> · {{ item.variant }}</template>
                                </span>
                                <span class="ck-done-item-qty">× {{ item.qty }}</span>
                                <span class="ck-done-item-price">{{ formatPrice(item.line_total) }}</span>
                            </li>
                        </ul>
                        <dl class="ck-done-meta">
                            <div>
                                <dt>Delivery</dt>
                                <dd>{{ order.shipping.name }}<template v-if="order.shipping.eta"> · estimated {{ order.shipping.eta }} once shipped</template></dd>
                            </div>
                            <div>
                                <dt>Shipping fee</dt>
                                <dd>{{ formatPrice(order.shipping_fee) }}</dd>
                            </div>
                            <div class="is-total">
                                <dt>To pay on delivery</dt>
                                <dd>{{ formatPrice(order.total) }}</dd>
                            </div>
                        </dl>
                    </li>
                </ul>

                <div class="ck-done-foot">
                    <div class="ck-done-address">
                        <p class="ck-label">Delivering to</p>
                        <p class="ck-address-name">{{ confirmation.address.recipient_name }} <span>{{ confirmation.address.contact_number }}</span></p>
                        <p class="ck-address-line">{{ confirmation.address.address }}</p>
                    </div>
                    <p class="ck-done-total">
                        <span>Total</span>
                        <strong>{{ formatPrice(confirmation.orders.reduce((sum, order) => sum + Number(order.total || 0), 0)) }}</strong>
                    </p>
                </div>

                <div class="ck-done-actions">
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="emit('view-orders')"
                    >
                        View {{ confirmation.orders.length > 1 ? 'orders' : 'order' }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-secondary"
                        @click="emit('back')"
                    >
                        Continue shopping
                    </button>
                </div>
            </section>

            <!-- ================================================================ -->
            <!-- CHECKOUT -->
            <!-- ================================================================ -->

            <template v-else>
                <header class="ck-head">
                    <h1 class="ck-title">Checkout</h1>
                    <button
                        type="button"
                        class="ck-link"
                        @click="source === 'cart' ? emit('edit-cart') : emit('back')"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                        {{ source === 'cart' ? 'Back to cart' : 'Back' }}
                    </button>
                </header>

                <div
                    v-if="isSignedOut"
                    class="ck-state"
                >
                    <h2>Sign in to check out</h2>
                    <p>Your cart is saved. Sign in, and you’ll come back to the same items.</p>
                    <button
                        type="button"
                        class="btn btn-primary"
                        @click="emit('view-profile')"
                    >
                        Sign in
                    </button>
                </div>

                <div
                    v-else
                    class="ck-layout"
                >
                    <div class="ck-sections">

                        <!-- ============ Order items ============ -->
                        <section
                            class="ck-panel"
                            aria-labelledby="ck-items-title"
                        >
                            <header class="ck-panel-head">
                                <h2
                                    id="ck-items-title"
                                    class="ck-section-title"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8 12 3 3 8v8l9 5 9-5Z" /><path d="m3 8 9 5 9-5M12 13v8" /></svg>
                                    Order items
                                    <span
                                        v-if="totals"
                                        class="ck-count"
                                    >{{ totals.item_count }}</span>
                                </h2>
                                <button
                                    v-if="source === 'cart'"
                                    type="button"
                                    class="ck-link"
                                    @click="emit('edit-cart')"
                                >
                                    Edit cart
                                </button>
                            </header>

                            <div
                                v-if="!quote && quoteStatus === 'loading'"
                                class="ck-group is-skeleton"
                                aria-hidden="true"
                            >
                                <span class="skeleton is-line"></span>
                                <div class="ck-line">
                                    <span class="skeleton ord-thumb"></span>
                                    <span class="ck-line-text">
                                        <span class="skeleton is-line"></span>
                                        <span class="skeleton is-line is-short"></span>
                                    </span>
                                </div>
                            </div>

                            <div
                                v-else-if="!quote && quoteStatus === 'error'"
                                class="ck-state is-inline"
                                role="alert"
                            >
                                <p>{{ quoteError }}</p>
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    @click="refreshQuote"
                                >
                                    Try again
                                </button>
                            </div>

                            <template v-else>
                                <!-- Column labels: line up quantity and total across stores -->
                                <div
                                    class="ck-columns"
                                    aria-hidden="true"
                                >
                                    <span>Item</span>
                                    <span>Qty</span>
                                    <span>Total</span>
                                </div>

                                <div
                                    v-for="seller in sellers"
                                    :key="seller.seller_id || 'unavailable'"
                                    class="ck-group"
                                    :class="{ 'is-updating': isUpdating }"
                                >
                                    <p class="ck-group-name">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9h18l-1.5-5h-15Z" /><path d="M4 9v11h16V9" /><path d="M9 20v-6h6v6" /></svg>
                                        {{ seller.store_name }}
                                    </p>

                                    <ul class="ck-lines">
                                        <li
                                            v-for="item in seller.items"
                                            :key="item.key"
                                            class="ck-line"
                                            :class="{ 'is-unavailable': !item.available }"
                                        >
                                            <OrderItemThumb
                                                :src="item.image || ''"
                                                :category="''"
                                            />
                                            <div class="ck-line-text">
                                                <p class="ck-line-name">{{ item.name }}</p>
                                                <p class="ck-line-meta">
                                                    <span
                                                        v-if="item.variation"
                                                        class="ck-variant"
                                                    >{{ item.variation }}</span>
                                                    <span>{{ formatPrice(item.unit_price) }} each</span>
                                                </p>
                                                <p
                                                    v-if="!item.available"
                                                    class="ck-line-problem"
                                                >
                                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                                                    {{ item.problem }}
                                                    <button
                                                        type="button"
                                                        class="ck-link is-danger"
                                                        @click="removeLine(item)"
                                                    >
                                                        Remove from checkout
                                                    </button>
                                                </p>
                                            </div>
                                            <span class="ck-line-qty"><span class="sr-only">Quantity </span>× {{ item.quantity }}</span>
                                            <span class="ck-line-total">{{ item.available ? formatPrice(item.line_total) : '—' }}</span>
                                        </li>
                                    </ul>

                                    <p
                                        v-if="seller.subtotal > 0"
                                        class="ck-group-subtotal"
                                    >
                                        <span>Subtotal from {{ seller.store_name }}</span>
                                        <strong>{{ formatPrice(seller.subtotal) }}</strong>
                                    </p>
                                </div>
                            </template>
                        </section>

                        <!-- ============ Delivery ============ -->
                        <section
                            id="ck-delivery"
                            class="ck-panel"
                            aria-labelledby="ck-delivery-title"
                        >
                            <header class="ck-panel-head">
                                <h2
                                    id="ck-delivery-title"
                                    class="ck-section-title"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2" /><path d="M15 18H9" /><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14" /><circle cx="17" cy="18" r="2" /><circle cx="7" cy="18" r="2" /></svg>
                                    Delivery
                                </h2>
                            </header>

                            <!-- Address -->
                            <div
                                id="ck-address"
                                class="ck-block"
                            >
                                <div class="ck-block-head">
                                    <h3 class="ck-block-title">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></svg>
                                        Deliver to
                                    </h3>
                                    <button
                                        v-if="state.addressMode === 'saved' && !isAddressFormOpen && addresses.length"
                                        type="button"
                                        class="ck-link"
                                        :aria-expanded="isChoosingAddress"
                                        aria-controls="ck-address-list"
                                        @click="isChoosingAddress = !isChoosingAddress"
                                    >
                                        {{ isChoosingAddress ? 'Done' : 'Change address' }}
                                    </button>
                                </div>

                                <div
                                    v-if="isLoadingAddresses && !addresses.length"
                                    class="ck-address is-skeleton"
                                    aria-hidden="true"
                                >
                                    <span class="ck-address-text">
                                        <span class="skeleton is-line"></span>
                                        <span class="skeleton is-line is-short"></span>
                                    </span>
                                </div>

                                <!-- Selected saved address: a quick crossfade when it changes -->
                                <Transition
                                    name="ck-swap"
                                    mode="out-in"
                                >
                                    <div
                                        v-if="state.addressMode === 'saved' && selectedSavedAddress && !isChoosingAddress && !isAddressFormOpen"
                                        id="ck-address-card"
                                        :key="selectedSavedAddress.id"
                                        class="ck-address"
                                        :class="{ 'has-error': savedAddressProblem }"
                                        tabindex="-1"
                                    >
                                        <div class="ck-address-text">
                                            <p class="ck-address-name">
                                                {{ selectedSavedAddress.fullName }}
                                                <span>{{ selectedSavedAddress.phone }}</span>
                                                <span
                                                    v-if="selectedSavedAddress.isDefault"
                                                    class="ck-badge"
                                                >Default</span>
                                                <span
                                                    v-if="selectedSavedAddress.label"
                                                    class="ck-tag"
                                                >{{ selectedSavedAddress.label }}</span>
                                            </p>
                                            <p class="ck-address-line">{{ formatAddressLine(selectedSavedAddress) }}</p>
                                        </div>
                                        <button
                                            type="button"
                                            class="ck-link"
                                            @click="startEdit(selectedSavedAddress)"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                </Transition>
                                <p
                                    v-if="savedAddressProblem && !isChoosingAddress && !isAddressFormOpen && addresses.length"
                                    class="ck-error"
                                    role="alert"
                                >
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                                    {{ savedAddressProblem }}
                                </p>

                                <!-- Choose a saved address -->
                                <div
                                    v-if="isChoosingAddress"
                                    id="ck-address-list"
                                    class="ck-choices"
                                    role="radiogroup"
                                    aria-label="Saved addresses"
                                >
                                    <label
                                        v-for="address in addresses"
                                        :key="address.id"
                                        class="ck-choice"
                                        :class="{ 'is-selected': address.id === state.selectedAddressId }"
                                    >
                                        <input
                                            type="radio"
                                            name="ck-address"
                                            :checked="address.id === state.selectedAddressId"
                                            @change="chooseSaved(address.id)"
                                        >
                                        <span class="ck-choice-body">
                                            <span class="ck-address-name">
                                                {{ address.fullName }}
                                                <span>{{ address.phone }}</span>
                                                <span
                                                    v-if="address.isDefault"
                                                    class="ck-badge"
                                                >Default</span>
                                                <span
                                                    v-if="address.label"
                                                    class="ck-tag"
                                                >{{ address.label }}</span>
                                            </span>
                                            <span class="ck-address-line">{{ formatAddressLine(address) }}</span>
                                        </span>
                                    </label>
                                    <button
                                        type="button"
                                        class="ck-choice-add"
                                        @click="startNewAddress"
                                    >
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                        Add a new address
                                    </button>
                                </div>

                                <!-- New-address summary (used for this order only) -->
                                <div
                                    v-if="state.addressMode === 'new' && !isAddressFormOpen"
                                    class="ck-address"
                                >
                                    <div class="ck-address-text">
                                        <p class="ck-address-name">{{ state.form.fullName }} <span>{{ state.form.phone }}</span></p>
                                        <p class="ck-address-line">{{ formatAddressLine(state.form) }}</p>
                                        <p class="ck-hint">For this order only</p>
                                    </div>
                                    <button
                                        type="button"
                                        class="ck-link"
                                        @click="isAddressFormOpen = true"
                                    >
                                        Edit
                                    </button>
                                </div>

                                <!-- Add / edit form -->
                                <form
                                    v-if="isAddressFormOpen"
                                    class="ck-form"
                                    novalidate
                                    @submit.prevent="submitAddressForm"
                                >
                                    <p class="ck-form-title">{{ state.addressMode === 'edit' ? 'Edit address' : 'New address' }}</p>
                                    <div class="ck-grid">
                                        <div
                                            class="ck-field"
                                            :class="{ 'has-error': errors.fullName }"
                                        >
                                            <label for="ck-fullName">Recipient name</label>
                                            <input
                                                id="ck-fullName"
                                                v-model="state.form.fullName"
                                                type="text"
                                                autocomplete="name"
                                                :aria-invalid="!!errors.fullName"
                                                :aria-describedby="errors.fullName ? 'ck-fullName-error' : undefined"
                                                @blur="touch('fullName')"
                                                @input="onFieldInput('fullName')"
                                            >
                                            <p v-if="errors.fullName" id="ck-fullName-error" class="ck-field-error">{{ errors.fullName }}</p>
                                        </div>
                                        <div
                                            class="ck-field"
                                            :class="{ 'has-error': errors.phone }"
                                        >
                                            <label for="ck-phone">Mobile number</label>
                                            <input
                                                id="ck-phone"
                                                v-model="state.form.phone"
                                                type="tel"
                                                inputmode="numeric"
                                                autocomplete="tel"
                                                placeholder="09XXXXXXXXX"
                                                maxlength="11"
                                                :aria-invalid="!!errors.phone"
                                                :aria-describedby="errors.phone ? 'ck-phone-error' : undefined"
                                                @blur="touch('phone')"
                                                @input="onFieldInput('phone')"
                                            >
                                            <p v-if="errors.phone" id="ck-phone-error" class="ck-field-error">{{ errors.phone }}</p>
                                        </div>
                                        <div
                                            class="ck-field is-wide"
                                            :class="{ 'has-error': errors.line1 }"
                                        >
                                            <label for="ck-line1">House no., street and barangay</label>
                                            <input
                                                id="ck-line1"
                                                v-model="state.form.line1"
                                                type="text"
                                                autocomplete="address-line1"
                                                :aria-invalid="!!errors.line1"
                                                :aria-describedby="errors.line1 ? 'ck-line1-error' : undefined"
                                                @blur="touch('line1')"
                                                @input="onFieldInput('line1')"
                                            >
                                            <p v-if="errors.line1" id="ck-line1-error" class="ck-field-error">{{ errors.line1 }}</p>
                                        </div>
                                        <div
                                            class="ck-field"
                                            :class="{ 'has-error': errors.city }"
                                        >
                                            <label for="ck-city">City / municipality</label>
                                            <input
                                                id="ck-city"
                                                v-model="state.form.city"
                                                type="text"
                                                autocomplete="address-level2"
                                                :aria-invalid="!!errors.city"
                                                :aria-describedby="errors.city ? 'ck-city-error' : undefined"
                                                @blur="touch('city')"
                                                @input="onFieldInput('city')"
                                            >
                                            <p v-if="errors.city" id="ck-city-error" class="ck-field-error">{{ errors.city }}</p>
                                        </div>
                                        <div
                                            class="ck-field"
                                            :class="{ 'has-error': errors.province }"
                                        >
                                            <label for="ck-province">Province</label>
                                            <input
                                                id="ck-province"
                                                v-model="state.form.province"
                                                type="text"
                                                autocomplete="address-level1"
                                                :aria-invalid="!!errors.province"
                                                :aria-describedby="errors.province ? 'ck-province-error' : undefined"
                                                @blur="touch('province')"
                                                @input="onFieldInput('province')"
                                            >
                                            <p v-if="errors.province" id="ck-province-error" class="ck-field-error">{{ errors.province }}</p>
                                        </div>
                                        <div class="ck-field">
                                            <label for="ck-postal">Postal code <span class="ck-optional">Optional</span></label>
                                            <input
                                                id="ck-postal"
                                                v-model="state.form.postalCode"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="postal-code"
                                                maxlength="10"
                                            >
                                        </div>
                                    </div>

                                    <label
                                        v-if="state.addressMode === 'new'"
                                        class="ck-check"
                                    >
                                        <input
                                            v-model="state.saveNewAddress"
                                            type="checkbox"
                                        >
                                        Save to my addresses
                                    </label>

                                    <p
                                        v-if="addressSaveError"
                                        class="ck-error"
                                        role="alert"
                                    >{{ addressSaveError }}</p>

                                    <div class="ck-form-actions">
                                        <button
                                            v-if="addresses.length"
                                            type="button"
                                            class="btn btn-ghost"
                                            :disabled="isSavingAddress"
                                            @click="cancelAddressForm"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            class="btn btn-secondary"
                                            :disabled="isSavingAddress"
                                        >
                                            <span v-if="isSavingAddress" class="ord-spinner" aria-hidden="true"></span>
                                            {{ isSavingAddress ? 'Saving…' : state.addressMode === 'edit' ? 'Save address' : state.saveNewAddress ? 'Save and use' : 'Use this address' }}
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Shipping, per store -->
                            <div
                                v-if="sellers.some(seller => seller.seller_id && seller.items.some(item => item.available))"
                                class="ck-block"
                            >
                                <div class="ck-block-head">
                                    <h3 class="ck-block-title">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>
                                        Shipping
                                    </h3>
                                </div>

                                <fieldset
                                    v-for="seller in sellers.filter(seller => seller.seller_id && seller.items.some(item => item.available))"
                                    :key="`ship-${seller.seller_id}`"
                                    class="ck-ship"
                                    :class="{ 'is-updating': isUpdating }"
                                >
                                    <legend class="ck-ship-store">
                                        {{ seller.store_name }}
                                        <span>{{ seller.items.filter(item => item.available).reduce((sum, item) => sum + item.quantity, 0) }} {{ seller.items.filter(item => item.available).reduce((sum, item) => sum + item.quantity, 0) === 1 ? 'item' : 'items' }}</span>
                                    </legend>
                                    <div class="ck-choices">
                                        <label
                                            v-for="option in seller.shipping.options"
                                            :key="option.id"
                                            class="ck-choice"
                                            :class="{ 'is-selected': state.shippingBySeller[seller.seller_id] === option.id }"
                                        >
                                            <input
                                                type="radio"
                                                :name="`ck-ship-${seller.seller_id}`"
                                                :value="option.id"
                                                :checked="state.shippingBySeller[seller.seller_id] === option.id"
                                                @change="chooseShipping(seller.seller_id, option.id)"
                                            >
                                            <span class="ck-choice-body">
                                                <span class="ck-choice-title">{{ option.name }}</span>
                                                <span class="ck-choice-meta">Estimated {{ option.eta }} after it ships</span>
                                            </span>
                                            <span class="ck-choice-price">{{ formatPrice(option.fee) }}</span>
                                        </label>
                                    </div>
                                </fieldset>
                                <p class="ck-hint">Each store ships its own parcel, at the same flat rate to any address.</p>
                            </div>
                        </section>

                        <!-- ============ Payment ============ -->
                        <section
                            class="ck-panel"
                            aria-labelledby="ck-payment-title"
                        >
                            <header class="ck-panel-head">
                                <h2
                                    id="ck-payment-title"
                                    class="ck-section-title"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></svg>
                                    Payment
                                </h2>
                            </header>
                            <div
                                class="ck-choices"
                                role="radiogroup"
                                aria-labelledby="ck-payment-title"
                            >
                                <label
                                    v-for="method in paymentMethods"
                                    :key="method.id"
                                    class="ck-choice"
                                    :class="{ 'is-selected': state.paymentMethod === method.id }"
                                >
                                    <input
                                        type="radio"
                                        name="ck-payment"
                                        :value="method.id"
                                        :checked="state.paymentMethod === method.id"
                                        @change="state.paymentMethod = method.id"
                                    >
                                    <span
                                        class="ck-option-icon"
                                        aria-hidden="true"
                                        v-html="paymentIcons[method.id] || paymentIcons.cod"
                                    ></span>
                                    <span class="ck-choice-body">
                                        <span class="ck-choice-title">{{ method.name }}</span>
                                        <span class="ck-choice-meta">{{ method.description }} No extra fee.</span>
                                    </span>
                                </label>
                            </div>
                            <p class="ck-hint">Online payment isn’t available yet — cash on delivery is the only way to pay for now.</p>
                        </section>
                    </div>

                    <!-- Summary -->
                    <aside
                        class="ck-summary"
                        aria-labelledby="ck-summary-title"
                    >
                        <div
                            class="ck-summary-inner"
                            :aria-busy="quoteStatus === 'loading'"
                        >
                            <h2
                                id="ck-summary-title"
                                class="ck-section-title"
                            >
                                Order summary
                            </h2>

                            <div
                                v-if="submitError"
                                ref="errorBanner"
                                class="ck-alert"
                                :class="{ 'is-changed': submitError.kind === 'changed' }"
                                role="alert"
                                tabindex="-1"
                            >
                                <p class="ck-alert-title">{{ submitError.title }}</p>
                                <p>{{ submitError.message }}</p>
                                <ul
                                    v-if="changes.length"
                                    class="ck-alert-list"
                                >
                                    <li
                                        v-for="change in changes"
                                        :key="change"
                                    >{{ change }}</li>
                                </ul>
                                <button
                                    v-if="submitError.kind === 'progress'"
                                    type="button"
                                    class="ck-link"
                                    @click="emit('view-orders')"
                                >
                                    Go to My Orders
                                </button>
                            </div>

                            <dl
                                class="ck-totals"
                                :class="{ 'is-updating': isUpdating }"
                            >
                                <div>
                                    <dt>Items<template v-if="totals"> ({{ totals.item_count }})</template></dt>
                                    <dd>
                                        <template v-if="totals">{{ formatPrice(totals.subtotal) }}</template>
                                        <span v-else class="skeleton is-line ck-sk-amount"></span>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Shipping<template v-if="sellers.length > 1"> ({{ sellers.filter(s => s.shipping.fee > 0).length }} parcels)</template></dt>
                                    <dd>
                                        <template v-if="totals">{{ formatPrice(totals.shipping) }}</template>
                                        <span v-else class="skeleton is-line ck-sk-amount"></span>
                                    </dd>
                                </div>
                                <div v-if="totals && totals.store_discount > 0">
                                    <dt>Store discounts</dt>
                                    <dd>−{{ formatPrice(totals.store_discount) }}</dd>
                                </div>
                                <div v-if="totals && totals.platform_discount > 0">
                                    <dt>BuyTheWay discounts</dt>
                                    <dd>−{{ formatPrice(totals.platform_discount) }}</dd>
                                </div>
                                <div v-if="totals && totals.payment_fee > 0">
                                    <dt>Payment fee</dt>
                                    <dd>{{ formatPrice(totals.payment_fee) }}</dd>
                                </div>
                                <div class="is-total">
                                    <dt>Total</dt>
                                    <dd>
                                        <span
                                            v-if="totals"
                                            :key="totals.total"
                                            :class="{ 'nx-value-in': totalChanged }"
                                        >{{ formatPrice(totals.total) }}</span>
                                        <span v-else class="skeleton is-line ck-sk-amount is-lg"></span>
                                    </dd>
                                </div>
                            </dl>

                            <p
                                class="ck-summary-status"
                                aria-live="polite"
                            >
                                <template v-if="quoteStatus === 'loading' && quote">
                                    <span class="ord-spinner" aria-hidden="true"></span>
                                    Updating your total…
                                </template>
                                <template v-else-if="quoteStatus === 'error' && quote">
                                    {{ quoteError }}
                                    <button type="button" class="ck-link" @click="refreshQuote">Try again</button>
                                </template>
                                <template v-else-if="selectedPayment && totals">
                                    Pay {{ formatPrice(totals.total) }} in cash on delivery.
                                </template>
                            </p>

                            <button
                                type="button"
                                class="btn btn-primary ck-place"
                                :disabled="isPlacing || (!!placeBlocker && quoteStatus === 'loading')"
                                :aria-busy="isPlacing"
                                @click="placeOrder"
                            >
                                <span v-if="isPlacing" class="ord-spinner" aria-hidden="true"></span>
                                {{ isPlacing ? 'Placing your order…' : submitError?.kind === 'changed' ? 'Place order with new total' : 'Place order' }}
                            </button>
                            <p
                                v-if="placeBlocker && quoteStatus !== 'loading'"
                                class="ck-hint is-center"
                            >{{ placeBlocker }}</p>
                            <p class="ck-hint is-center">Stock and prices are checked again when you place your order.</p>
                        </div>
                    </aside>
                </div>
            </template>
        </main>

        <!-- Mobile: total + Place order always within reach -->
        <div
            v-if="!confirmation && !isSignedOut"
            class="ck-bar"
            :class="{ 'is-hidden': isTyping }"
        >
            <p class="ck-bar-total">
                <span>Total</span>
                <strong>
                    <span
                        v-if="totals"
                        :key="totals.total"
                        :class="{ 'nx-value-in': totalChanged }"
                    >{{ formatPrice(totals.total) }}</span>
                    <template v-else>—</template>
                </strong>
                <span
                    v-if="quoteStatus === 'loading' && quote"
                    class="ck-bar-note"
                >Updating…</span>
            </p>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="isPlacing || (!!placeBlocker && quoteStatus === 'loading')"
                :aria-busy="isPlacing"
                @click="placeOrder"
            >
                <span v-if="isPlacing" class="ord-spinner" aria-hidden="true"></span>
                {{ isPlacing ? 'Placing…' : 'Place order' }}
            </button>
        </div>

        <Footer
            @browse-all="emit('browse-all')"
            @browse-categories="emit('browse-categories')"
            @cart-click="emit('edit-cart')"
        />

    </div>

</template>

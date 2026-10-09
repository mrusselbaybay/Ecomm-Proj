<script setup>
/*
|--------------------------------------------------------------------------
| Payment Methods
|--------------------------------------------------------------------------
|
| What a payment provider has saved for the buyer, and how checkout can be
| paid today. Two different things, kept apart on purpose: a method being
| payable at checkout (CheckoutOptions) says nothing about whether it can
| be saved or linked.
|
| Methods are only ever added by a provider's hosted / tokenised setup —
| this page never asks for a card number, CVV, wallet PIN or OTP. While no
| provider integration is enabled (SavedPaymentSupport on the server) the
| page says so plainly and offers no add or link control. Details typed
| into BuyTheWay before (no provider reference) are listed as unusable,
| with Remove only.
|
| Backed by /api/buyer/payment-methods (owner-scoped; default and remove
| only). Setting a default starts no payment; removing one leaves orders
| untouched.
|
*/
import { computed, onMounted, ref } from 'vue';
import { buyerApi } from '../composables/useBuyerApi';
import { authHeaders } from '../composables/useBuyerSession';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';

const { confirm } = useConfirm();
const toasts = useToasts();

const methods = ref([]);
const saving = ref({ enabled: false, provider: null });
const checkoutMethods = ref([]);
const loading = ref(true);
const loadError = ref('');
const busyId = ref(null);

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        const response = await fetchWithMeta('/buyer/payment-methods');

        methods.value = (response.data || []).map(method => ({ ...method, walletName: method.provider, walletId: method.phoneMasked, isDefault: method.isPrimary, usable: false }));
        saving.value = { enabled: false };
        checkoutMethods.value = [{ id: 'cod', name: 'Cash on delivery', description: 'Pay the courier when your parcel arrives.' }];
    } catch (err) {
        loadError.value = err?.status === 401
            ? 'Your session has ended. Sign in again to see your payment methods.'
            : 'We couldn’t load your payment methods. Check your connection and try again.';
    } finally {
        loading.value = false;
    }
}

// buyerApi unwraps `data`; this list needs `meta` (provider support) too.
async function fetchWithMeta(path) {
    const response = await fetch(`/api${path}`, { headers: await authHeaders() });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(body.message || 'Request failed.');
        error.status = response.status;

        throw error;
    }

    return body;
}

onMounted(load);

const usable = computed(() => methods.value.filter(m => m.usable || m.unusableReason === 'expired'));
const legacy = computed(() => methods.value.filter(m => !m.usable && m.unusableReason !== 'expired'));

/*
|--------------------------------------------------------------------------
| Display
|--------------------------------------------------------------------------
*/

const BRANDS = {
    visa: { label: 'Visa', className: 'is-visa', text: 'VISA' },
    mastercard: { label: 'Mastercard', className: 'is-mastercard', text: '' },
    amex: { label: 'American Express', className: 'is-amex', text: 'AMEX' },
    'american express': { label: 'American Express', className: 'is-amex', text: 'AMEX' },
    jcb: { label: 'JCB', className: 'is-jcb', text: 'JCB' },
    gcash: { label: 'GCash', className: 'is-gcash', text: 'GCash' },
    maya: { label: 'Maya', className: 'is-maya', text: 'maya' }
};

function brandOf(method) {
    const key = String(method.type === 'wallet' ? method.walletName : method.brand || '').toLowerCase();

    return BRANDS[key] || { label: method.brand || method.walletName || 'Card', className: 'is-generic', text: (method.brand || method.walletName || 'Card').slice(0, 6) };
}

function titleOf(method) {
    return method.type === 'wallet'
        ? `${brandOf(method).label} ${method.walletId || ''}`.trim()
        : `${brandOf(method).label} ending in ${method.last4}`;
}

function expiryOf(method) {
    if (method.type !== 'card' || !method.expMonth || !method.expYear) {
        return '';
    }

    const label = `${String(method.expMonth).padStart(2, '0')}/${String(method.expYear).slice(-2)}`;

    return method.expired ? `Expired ${label}` : `Expires ${label}`;
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

async function makeDefault(method) {
    if (busyId.value) {
        return;
    }

    busyId.value = method.id;

    try {
        await buyerApi(`/buyer/payment-methods/${encodeURIComponent(method.id)}/primary`, { method: 'PUT' });
        methods.value = methods.value.map(m => ({ ...m, isDefault: m.id === method.id }));
        toasts.success(`${titleOf(method)} is now your default. Nothing was charged.`);
    } catch (err) {
        toasts.error(err?.message || 'Could not change your default payment method.');
    } finally {
        busyId.value = null;
    }
}

async function remove(method) {
    if (busyId.value) {
        return;
    }

    const ok = await confirm({
        title: 'Remove this payment method?',
        message: `${titleOf(method)} will be removed from your account. Orders you’ve already placed aren’t affected.`,
        confirmLabel: 'Remove',
        tone: 'danger'
    });

    if (!ok) {
        return;
    }

    busyId.value = method.id;

    try {
        await buyerApi(`/buyer/payment-methods/${encodeURIComponent(method.id)}`, { method: 'DELETE' });
        toasts.success('Payment method removed.');
        await load();
    } catch (err) {
        toasts.error(err?.message || 'Could not remove this payment method.');
    } finally {
        busyId.value = null;
    }
}
</script>

<template>

    <section
        class="acc-section acc-payments"
        aria-labelledby="acc-payments-title"
    >
        <header class="acc-head">
            <h1
                id="acc-payments-title"
                class="acc-title"
            >
                Payment methods
            </h1>
            <p class="acc-lede">Cards and e-wallets saved through our payment provider, and the ways you can pay at checkout.</p>
        </header>

        <div
            v-if="loading"
            class="acc-loading"
            aria-busy="true"
        >
            <span class="skeleton is-line acc-sk-title"></span>
            <span class="skeleton is-line"></span>
            <span class="skeleton is-line"></span>
        </div>

        <div
            v-else-if="loadError"
            class="acc-empty"
            role="alert"
        >
            <p class="acc-empty-title">Payment methods didn’t load</p>
            <p>{{ loadError }}</p>
            <button
                type="button"
                class="btn btn-secondary"
                @click="load"
            >
                Try again
            </button>
        </div>

        <Transition
            v-else
            name="acc-swap"
            appear
        >
            <div>
                <!-- Saved through the provider -->
                <div class="acc-block is-first">
                    <h2 class="acc-subtitle">Saved cards and e-wallets</h2>

                    <template v-if="saving.enabled">
                        <p
                            v-if="!usable.length"
                            class="acc-hint"
                        >
                            You haven’t saved a card or linked an e-wallet yet.
                        </p>

                        <TransitionGroup
                            v-else
                            name="acc-list"
                            tag="ul"
                            class="acc-pay-list"
                        >
                            <li
                                v-for="method in usable"
                                :key="method.id"
                                class="acc-pay"
                                :class="{ 'is-busy': busyId === method.id, 'is-expired': method.expired }"
                            >
                                <span
                                    class="acc-pay-logo"
                                    :class="brandOf(method).className"
                                    aria-hidden="true"
                                >{{ brandOf(method).text }}</span>
                                <div class="acc-pay-main">
                                    <p class="acc-pay-title">
                                        {{ titleOf(method) }}
                                        <span
                                            v-if="method.isDefault"
                                            class="acc-tag is-default"
                                        >Default</span>
                                        <span
                                            v-if="method.expired"
                                            class="acc-tag is-warn"
                                        >Expired</span>
                                    </p>
                                    <p class="acc-pay-meta">{{ expiryOf(method) || (method.type === 'wallet' ? 'Linked e-wallet' : '') }}</p>
                                </div>
                                <div class="acc-address-actions">
                                    <button
                                        v-if="method.usable && !method.isDefault"
                                        type="button"
                                        class="link-btn"
                                        :disabled="Boolean(busyId)"
                                        @click="makeDefault(method)"
                                    >
                                        Set as default
                                    </button>
                                    <button
                                        type="button"
                                        class="link-btn is-danger"
                                        :disabled="Boolean(busyId)"
                                        @click="remove(method)"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </li>
                        </TransitionGroup>
                    </template>

                    <div
                        v-else
                        class="acc-pay-status"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2" /><path d="M3 10h18M7 15h3" /></svg>
                        <div>
                            <p class="acc-pay-status-title">Saving cards and linking GCash or Maya isn’t available yet</p>
                            <p>BuyTheWay isn’t connected to a payment provider that can securely keep a card or link an e-wallet, so there’s nothing to add here yet. When it is, you’ll add them through the provider’s own secure form — you’ll never type a card number into BuyTheWay.</p>
                        </div>
                    </div>
                </div>

                <!-- Typed in before a provider existed -->
                <div
                    v-if="legacy.length"
                    class="acc-block"
                >
                    <h2 class="acc-subtitle">Card details saved earlier</h2>
                    <p class="acc-hint">These were noted on BuyTheWay before payments were connected. They were never linked to your bank or e-wallet and can’t be used to pay, so you may want to remove them.</p>

                    <TransitionGroup
                        name="acc-list"
                        tag="ul"
                        class="acc-pay-list"
                    >
                        <li
                            v-for="method in legacy"
                            :key="method.id"
                            class="acc-pay is-legacy"
                            :class="{ 'is-busy': busyId === method.id }"
                        >
                            <span
                                class="acc-pay-logo"
                                :class="brandOf(method).className"
                                aria-hidden="true"
                            >{{ brandOf(method).text }}</span>
                            <div class="acc-pay-main">
                                <p class="acc-pay-title">
                                    {{ titleOf(method) }}
                                    <span class="acc-tag">Not linked</span>
                                    <span
                                        v-if="method.expired"
                                        class="acc-tag is-warn"
                                    >Expired</span>
                                </p>
                                <p
                                    v-if="expiryOf(method)"
                                    class="acc-pay-meta"
                                >
                                    {{ expiryOf(method) }}
                                </p>
                            </div>
                            <div class="acc-address-actions">
                                <button
                                    type="button"
                                    class="link-btn is-danger"
                                    :disabled="Boolean(busyId)"
                                    @click="remove(method)"
                                >
                                    Remove
                                </button>
                            </div>
                        </li>
                    </TransitionGroup>
                </div>

                <!-- What checkout accepts -->
                <div class="acc-block">
                    <h2 class="acc-subtitle">How you can pay at checkout</h2>
                    <ul class="acc-pay-list">
                        <li
                            v-for="option in checkoutMethods"
                            :key="option.id"
                            class="acc-pay is-option"
                        >
                            <span
                                class="acc-pay-logo is-cash"
                                aria-hidden="true"
                            >
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="6" width="19" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 9.5v.01M18 14.5v.01" /></svg>
                            </span>
                            <div class="acc-pay-main">
                                <p class="acc-pay-title">{{ option.name }}</p>
                                <p class="acc-pay-meta">{{ option.description }}</p>
                            </div>
                        </li>
                    </ul>
                    <p class="acc-hint">Chosen at checkout for each order — nothing to set up here. New ways to pay appear in this list once checkout accepts them.</p>
                </div>
            </div>
        </Transition>
    </section>

</template>

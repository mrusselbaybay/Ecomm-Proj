import { computed, ref } from 'vue';

import { buyerApi } from './useBuyerApi';

/*
|--------------------------------------------------------------------------
| useBuyerPayments — saved cards, wallets, and the COD preference
|--------------------------------------------------------------------------
|
| Cards and wallets are backed by the Laravel Buyer API
| (/api/buyer/payment-methods -> App\Http\Controllers\Buyer\
| PaymentMethodController, buyer_payment_methods table). Previously
| localStorage only.
|
| SECURITY: the full card number never leaves the browser. detectBrand()
| + luhnValid() run here on the raw number to validate it and derive the
| brand + last 4; only { type, brand, last4, holder, exp_month, exp_year,
| label } is sent to the server. A CVV is never asked for or stored. For a
| wallet, only a masked number (0917 •••• 4567) is sent.
|
| The "Cash on Delivery" preference is a single UI toggle with no
| server-side effect on checkout yet, so it stays in localStorage — the
| one thing here that still does.
|
| The exported surface is unchanged so PaymentMethods.vue needs no edits.
|
*/

const COD_KEY = 'nexmart_buyer_cod_enabled';

const WALLET_PROVIDERS = ['GCash', 'Maya'];

const methods = ref([]);
const isLoading = ref(false);
const loadError = ref('');

let loadedOnce = false;
let inFlight = null;

function loadCod() {
    try {
        const stored = localStorage.getItem(COD_KEY);

        return stored === null ? true : stored === 'true';
    } catch (err) {
        return true;
    }
}

const codEnabled = ref(loadCod());

function persistCod() {
    try {
        localStorage.setItem(COD_KEY, String(codEnabled.value));
    } catch (err) {
        // Storage unavailable — the toggle just won't survive a refresh.
    }
}

const cards = computed(() => methods.value.filter(method => method.type === 'card'));
const wallets = computed(() => methods.value.filter(method => method.type === 'wallet'));
const primaryMethod = computed(() => methods.value.find(method => method.isPrimary) || null);
const hasMethods = computed(() => methods.value.length > 0);

/*
|--------------------------------------------------------------------------
| Card helpers (client-side only — operate on the raw number)
|--------------------------------------------------------------------------
*/

function detectBrand(number) {
    const digits = (number || '').replace(/\D/g, '');

    if (/^4/.test(digits)) {
        return 'Visa';
    }

    if (/^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[01]|2720)/.test(digits)) {
        return 'Mastercard';
    }

    if (/^3[47]/.test(digits)) {
        return 'Amex';
    }

    if (/^(6011|65|64[4-9])/.test(digits)) {
        return 'Discover';
    }

    if (/^35(2[89]|[3-8]\d)/.test(digits)) {
        return 'JCB';
    }

    return 'Card';
}

function luhnValid(number) {
    const digits = (number || '').replace(/\D/g, '');

    if (digits.length < 13 || digits.length > 19) {
        return false;
    }

    let sum = 0;
    let double = false;

    for (let i = digits.length - 1; i >= 0; i--) {
        let value = parseInt(digits[i], 10);

        if (double) {
            value *= 2;

            if (value > 9) {
                value -= 9;
            }
        }

        sum += value;
        double = !double;
    }

    return sum % 10 === 0;
}

// Accepts 'MM/YY' or 'MM / YYYY' and returns { month, year } (year as a
// full 4-digit number) or null if it doesn't parse / is in the past.
function parseExpiry(value) {
    const match = (value || '').replace(/\s/g, '').match(/^(0[1-9]|1[0-2])\/(\d{2}|\d{4})$/);

    if (!match) {
        return null;
    }

    const month = Number(match[1]);
    const year = match[2].length === 2 ? 2000 + Number(match[2]) : Number(match[2]);

    const now = new Date();
    const thisYear = now.getFullYear();
    const thisMonth = now.getMonth() + 1;

    if (year < thisYear || (year === thisYear && month < thisMonth)) {
        return null;
    }

    return { month, year };
}

function maskPhone(number) {
    const digits = (number || '').replace(/\D/g, '');

    if (digits.length < 7) {
        return digits;
    }

    return `${digits.slice(0, 4)} •••• ${digits.slice(-4)}`;
}

/*
|--------------------------------------------------------------------------
| Load
|--------------------------------------------------------------------------
*/

async function fetchMethods() {
    isLoading.value = true;
    loadError.value = '';

    try {
        methods.value = await buyerApi('/buyer/payment-methods');
        loadedOnce = true;
    } catch (err) {
        if (err?.status && err.status !== 401) {
            loadError.value = err?.message || 'Could not load your payment methods.';
        }

        methods.value = [];
    } finally {
        isLoading.value = false;
    }
}

function loadPaymentMethods({ force = false } = {}) {
    if (inFlight) {
        return inFlight;
    }

    if (loadedOnce && !force) {
        return Promise.resolve();
    }

    inFlight = fetchMethods().finally(() => {
        inFlight = null;
    });

    return inFlight;
}

/*
|--------------------------------------------------------------------------
| Mutations
|--------------------------------------------------------------------------
*/

async function addCard(input) {
    const digits = (input.number || '').replace(/\D/g, '');
    const expiry = parseExpiry(input.expiry);

    const payload = {
        type: 'card',
        brand: detectBrand(digits),
        last4: digits.slice(-4),
        holder: (input.holder || '').trim(),
        exp_month: expiry ? String(expiry.month).padStart(2, '0') : null,
        exp_year: expiry ? expiry.year : null,
        label: (input.label || '').trim() || null,
        is_primary: Boolean(input.makePrimary),
    };

    const created = await buyerApi('/buyer/payment-methods', {
        method: 'POST',
        body: JSON.stringify(payload),
    });

    await fetchMethods();

    return created;
}

async function updateCard(id, input) {
    const expiry = parseExpiry(input.expiry);

    const payload = {
        holder: (input.holder || '').trim(),
        label: (input.label || '').trim() || null,
        is_primary: Boolean(input.makePrimary),
    };

    if (expiry) {
        payload.exp_month = String(expiry.month).padStart(2, '0');
        payload.exp_year = expiry.year;
    }

    const updated = await buyerApi(`/buyer/payment-methods/${encodeURIComponent(id)}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
    });

    await fetchMethods();

    return updated;
}

async function addWallet(input) {
    const payload = {
        type: 'wallet',
        provider: WALLET_PROVIDERS.includes(input.provider) ? input.provider : 'GCash',
        phone_masked: maskPhone(input.phone),
        is_primary: Boolean(input.makePrimary),
    };

    const created = await buyerApi('/buyer/payment-methods', {
        method: 'POST',
        body: JSON.stringify(payload),
    });

    await fetchMethods();

    return created;
}

async function removeMethod(id) {
    const snapshot = [...methods.value];
    methods.value = methods.value.filter(method => method.id !== id);

    try {
        await buyerApi(`/buyer/payment-methods/${encodeURIComponent(id)}`, { method: 'DELETE' });
        await fetchMethods();
    } catch (err) {
        methods.value = snapshot;

        throw err;
    }
}

async function setPrimary(id) {
    const snapshot = [...methods.value];
    methods.value = methods.value.map(method => ({ ...method, isPrimary: method.id === id }));

    try {
        await buyerApi(`/buyer/payment-methods/${encodeURIComponent(id)}/primary`, { method: 'PUT' });
        await fetchMethods();
    } catch (err) {
        methods.value = snapshot;

        throw err;
    }
}

function setCod(value) {
    codEnabled.value = Boolean(value);
    persistCod();
}

export function useBuyerPayments() {
    loadPaymentMethods();

    return {
        methods,
        cards,
        wallets,
        primaryMethod,
        hasMethods,
        isLoading,
        loadError,
        codEnabled,
        WALLET_PROVIDERS,

        detectBrand,
        luhnValid,
        parseExpiry,

        loadPaymentMethods,
        addCard,
        updateCard,
        addWallet,
        removeMethod,
        setPrimary,
        setCod,
    };
}

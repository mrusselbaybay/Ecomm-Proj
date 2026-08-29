<script setup>
/*
|--------------------------------------------------------------------------
| PaymentMethods.vue — saved cards, wallets, and the COD preference
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Modern Payment
| Management") onto NEXMART's stack the same way as SavedAddresses.vue:
| Tailwind utilities, the shared Header/Footer, the same account-area
| sidebar, inline SVG icons (the reference's iconify + brand-logo web
| components aren't dependencies here — brands render as text pills).
|
| Data lives in useBuyerPayments.js — localStorage-backed, because there
| is no payment vault (see that file). It only ever stores brand + last 4
| + cardholder + expiry for a card and a masked number for a wallet; the
| full card number is validated (Luhn + brand) while adding and then
| dropped, and a CVV is never asked for or stored.
|
| UX changes on top of the static reference:
|   - The add form is collapsed by default and doubles as the edit form,
|     with a Card / GCash / Maya type switch and a Cancel.
|   - Real add / edit / remove / set-primary with one primary at a time.
|   - Luhn + expiry + PH-mobile validation with inline errors.
|   - Remove asks for confirmation; a banner confirms every change.
|   - Empty states per section; the COD toggle actually persists.
|
*/
import { computed, nextTick, reactive, ref } from 'vue';

import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyerPayments } from '../composables/useBuyerPayments';

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-orders',
    'view-wishlist',
    'view-reviews',
    'view-addresses'
]);

const {
    cards,
    wallets,
    hasMethods,
    codEnabled,
    WALLET_PROVIDERS,
    luhnValid,
    parseExpiry,
    addCard,
    updateCard,
    addWallet,
    removeMethod,
    setPrimary,
    setCod
} = useBuyerPayments();

/*
|--------------------------------------------------------------------------
| Add / edit form
|--------------------------------------------------------------------------
*/

const isFormOpen = ref(false);
const formType = ref('card'); // 'card' | 'wallet'
const editingId = ref(null);
const errors = ref({});
const feedback = ref('');
const formSection = ref(null);

let feedbackTimer = null;

function focusFirstField() {
    nextTick(() => {
        formSection.value
            ?.querySelector('input:not([type="checkbox"]):not([type="radio"]), select')
            ?.focus();
    });
}

const cardForm = reactive({
    number: '',
    holder: '',
    expiry: '',
    label: '',
    makePrimary: false
});

const walletForm = reactive({
    provider: 'GCash',
    phone: '',
    makePrimary: false
});

const isEditing = computed(() => editingId.value !== null);

const brandTone = {
    Visa: 'bg-indigo-50 text-indigo-600 border-indigo-100',
    Mastercard: 'bg-orange-50 text-orange-600 border-orange-100',
    Amex: 'bg-sky-50 text-sky-600 border-sky-100',
    Discover: 'bg-amber-50 text-amber-600 border-amber-100',
    JCB: 'bg-emerald-50 text-emerald-600 border-emerald-100',
    Card: 'bg-slate-100 text-slate-500 border-slate-200'
};

function brandClass(brand) {
    return brandTone[brand] || brandTone.Card;
}

function walletTileClass(provider) {
    return provider === 'Maya'
        ? 'bg-slate-900 text-[#c1ff00]'
        : 'bg-blue-500 text-white';
}

function resetForms() {
    cardForm.number = '';
    cardForm.holder = '';
    cardForm.expiry = '';
    cardForm.label = '';
    cardForm.makePrimary = false;
    walletForm.provider = 'GCash';
    walletForm.phone = '';
    walletForm.makePrimary = false;
    errors.value = {};
}

function openAddForm(type = 'card') {
    resetForms();
    editingId.value = null;
    formType.value = type;
    isFormOpen.value = true;
    focusFirstField();
}

function openEditCard(card) {
    resetForms();
    editingId.value = card.id;
    formType.value = 'card';
    cardForm.holder = card.holder;
    cardForm.expiry = card.expYear
        ? `${card.expMonth} / ${String(card.expYear).slice(-2)}`
        : '';
    cardForm.label = card.label;
    cardForm.makePrimary = card.isPrimary;
    isFormOpen.value = true;
    focusFirstField();
}

function closeForm() {
    isFormOpen.value = false;
    editingId.value = null;
    resetForms();
}

function setFormType(type) {
    formType.value = type;
    errors.value = {};
}

function showFeedback(message) {
    feedback.value = message;
    clearTimeout(feedbackTimer);
    feedbackTimer = setTimeout(() => {
        feedback.value = '';
    }, 3000);
}

function validateCard({ requireNumber }) {
    const next = {};

    if (requireNumber && !luhnValid(cardForm.number)) {
        next.number = 'Enter a valid card number.';
    }

    if (!cardForm.holder.trim()) {
        next.holder = "Cardholder name is required.";
    }

    if (!parseExpiry(cardForm.expiry)) {
        next.expiry = 'Use MM/YY and a date that hasn’t passed.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

function validateWallet() {
    const next = {};
    const normalized = walletForm.phone.replace(/[\s-]/g, '');

    if (!normalized) {
        next.phone = 'Mobile number is required.';
    } else if (!/^(09\d{9}|\+639\d{9})$/.test(normalized)) {
        next.phone = 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

function submitForm() {
    if (formType.value === 'wallet') {
        if (!validateWallet()) {
            return;
        }

        addWallet({ ...walletForm });
        showFeedback(`${walletForm.provider} wallet connected.`);
        closeForm();

        return;
    }

    if (isEditing.value) {
        if (!validateCard({ requireNumber: false })) {
            return;
        }

        updateCard(editingId.value, { ...cardForm });
        showFeedback('Card updated.');
    } else {
        if (!validateCard({ requireNumber: true })) {
            return;
        }

        addCard({ ...cardForm });
        showFeedback('Card saved.');
    }

    closeForm();
}

/*
|--------------------------------------------------------------------------
| Card / wallet actions
|--------------------------------------------------------------------------
*/

function handleSetPrimary(method) {
    setPrimary(method.id);
    showFeedback('Primary payment method updated.');
}

function confirmRemoveCard(card) {
    if (!window.confirm(`Remove the ${card.brand} card ending in ${card.last4}?`)) {
        return;
    }

    if (editingId.value === card.id) {
        closeForm();
    }

    removeMethod(card.id);
    showFeedback('Card removed.');
}

function confirmRemoveWallet(wallet) {
    if (!window.confirm(`Disconnect this ${wallet.provider} wallet?`)) {
        return;
    }

    removeMethod(wallet.id);
    showFeedback(`${wallet.provider} wallet disconnected.`);
}

function handleCodToggle(event) {
    setCod(event.target.checked);
    showFeedback(event.target.checked ? 'Cash on Delivery enabled.' : 'Cash on Delivery disabled.');
}

function cardExpiryLabel(card) {
    return card.expYear ? `${card.expMonth} / ${String(card.expYear).slice(-2)}` : '—';
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
    <div class="buyer-page">

        <Header
            active-category=""
            @select-category="handleHeaderSelectCategory"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row lg:items-start gap-8">

                <!-- ==================================================== -->
                <!-- SIDEBAR NAV (matching Account / Saved Addresses) -->
                <!-- ==================================================== -->

                <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-36">
                    <nav class="bg-white rounded-3xl p-4 border border-slate-100 space-y-1" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-profile')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />
                            </svg>
                            My Profile
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-orders')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z" />
                                <path d="M12 22V12" /><polyline points="3.29 7 12 12 20.71 7" /><path d="m7.5 4.27 9 5.15" />
                            </svg>
                            My Orders
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-wishlist')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5" />
                            </svg>
                            Wishlist
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-reviews')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />
                            </svg>
                            My Reviews
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-addresses')"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Saved Addresses
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" />
                            </svg>
                            Payment Methods
                        </button>

                    </nav>
                </aside>

                <!-- ==================================================== -->
                <!-- MAIN CONTENT -->
                <!-- ==================================================== -->

                <div class="flex-1 space-y-8 min-w-0">

                    <!-- Breadcrumb + Title -->
                    <div class="flex flex-col gap-2">
                        <nav class="flex items-center text-sm font-medium text-slate-400" aria-label="Breadcrumb">
                            <button type="button" class="hover:text-slate-600 transition-colors" @click="emit('view-profile')">Account</button>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mx-1.5">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                            <span class="text-slate-900">Payment Methods</span>
                        </nav>
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Payment Methods</h1>
                                <p class="text-slate-500 mt-1">Cards and wallets you can pick from at checkout. Saved on this device.</p>
                            </div>
                            <button
                                v-if="!isFormOpen"
                                type="button"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                                @click="openAddForm('card')"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                                Add New Method
                            </button>
                        </div>
                    </div>

                    <!-- Feedback banner -->
                    <Transition name="pm-feedback">
                        <div
                            v-if="feedback"
                            class="flex items-center gap-3 px-5 py-3 bg-emerald-50 border border-emerald-100 rounded-2xl text-sm font-semibold text-emerald-700"
                            role="status"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5" />
                            </svg>
                            {{ feedback }}
                        </div>
                    </Transition>

                    <!-- Add / Edit form -->
                    <section
                        v-if="isFormOpen"
                        ref="formSection"
                        class="bg-white rounded-[2rem] border border-slate-200 overflow-hidden"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="px-6 sm:px-10 py-6 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 bg-teal-50 rounded-2xl flex items-center justify-center text-[#0d9488]">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                            </div>
                            <h2 class="text-xl font-bold text-slate-900">
                                {{ isEditing ? 'Edit Card' : 'Add Payment Method' }}
                            </h2>
                        </div>

                        <div class="p-6 sm:p-10 space-y-6">

                            <!-- Type switch (add mode only) -->
                            <div v-if="!isEditing">
                                <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3 px-1">Method Type</span>
                                <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Method type">
                                    <button
                                        v-for="option in [
                                            { value: 'card', label: 'Credit / Debit Card' },
                                            { value: 'wallet', label: 'Digital Wallet' }
                                        ]"
                                        :key="option.value"
                                        type="button"
                                        class="px-6 py-2.5 rounded-xl border text-sm font-bold transition-all"
                                        :class="formType === option.value
                                            ? 'bg-teal-50 border-[#0d9488] text-[#0d9488]'
                                            : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'"
                                        @click="setFormType(option.value)"
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                            </div>

                            <!-- CARD FIELDS -->
                            <form
                                v-if="formType === 'card'"
                                class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6"
                                @submit.prevent="submitForm"
                            >
                                <div v-if="!isEditing" class="md:col-span-2">
                                    <label for="pm-number" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Card Number <span class="text-red-500">*</span></label>
                                    <input
                                        id="pm-number"
                                        v-model="cardForm.number"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="cc-number"
                                        placeholder="4242 4242 4242 4242"
                                        class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 tracking-wider focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                        :class="errors.number ? 'border-red-300' : 'border-slate-200'"
                                    >
                                    <p v-if="errors.number" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.number }}</p>
                                    <p v-else class="text-xs text-slate-400 mt-1.5 px-1">Only the brand and last 4 digits are kept — never the full number or CVV.</p>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="pm-holder" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Cardholder Name <span class="text-red-500">*</span></label>
                                    <input
                                        id="pm-holder"
                                        v-model="cardForm.holder"
                                        type="text"
                                        autocomplete="cc-name"
                                        placeholder="Name as printed on the card"
                                        class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                        :class="errors.holder ? 'border-red-300' : 'border-slate-200'"
                                    >
                                    <p v-if="errors.holder" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.holder }}</p>
                                </div>

                                <div>
                                    <label for="pm-expiry" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Expiry <span class="text-red-500">*</span></label>
                                    <input
                                        id="pm-expiry"
                                        v-model="cardForm.expiry"
                                        type="text"
                                        autocomplete="cc-exp"
                                        placeholder="MM / YY"
                                        class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                        :class="errors.expiry ? 'border-red-300' : 'border-slate-200'"
                                    >
                                    <p v-if="errors.expiry" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.expiry }}</p>
                                </div>

                                <div>
                                    <label for="pm-label" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Label <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                                    <input
                                        id="pm-label"
                                        v-model="cardForm.label"
                                        type="text"
                                        placeholder="e.g. Personal, Work"
                                        class="w-full px-5 py-3.5 bg-slate-50 border border-slate-200 rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    >
                                </div>

                                <div class="md:col-span-2">
                                    <label class="flex items-center gap-3 cursor-pointer group w-fit">
                                        <input v-model="cardForm.makePrimary" type="checkbox" class="w-[18px] h-[18px] accent-[#0d9488] cursor-pointer">
                                        <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900 transition-colors">Use as my primary payment method</span>
                                    </label>
                                </div>

                                <div class="md:col-span-2 pt-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                                    <button type="button" class="px-6 py-3.5 rounded-2xl text-sm font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors" @click="closeForm">Cancel</button>
                                    <button type="submit" class="px-10 py-3.5 bg-[#0d9488] text-white rounded-2xl text-sm font-bold hover:bg-[#0f766e] transition-all">
                                        {{ isEditing ? 'Save Changes' : 'Save Card' }}
                                    </button>
                                </div>
                            </form>

                            <!-- WALLET FIELDS -->
                            <form
                                v-else
                                class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6"
                                @submit.prevent="submitForm"
                            >
                                <div class="md:col-span-2">
                                    <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3 px-1">Provider</span>
                                    <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Wallet provider">
                                        <label v-for="provider in WALLET_PROVIDERS" :key="provider" class="cursor-pointer">
                                            <input v-model="walletForm.provider" type="radio" name="pm-provider" :value="provider" class="sr-only peer">
                                            <span class="block px-6 py-2.5 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 bg-white transition-all hover:bg-slate-50 peer-checked:bg-teal-50 peer-checked:border-[#0d9488] peer-checked:text-[#0d9488]">
                                                {{ provider }}
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="pm-phone" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Mobile Number <span class="text-red-500">*</span></label>
                                    <input
                                        id="pm-phone"
                                        v-model="walletForm.phone"
                                        type="tel"
                                        placeholder="09XXXXXXXXX"
                                        class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                        :class="errors.phone ? 'border-red-300' : 'border-slate-200'"
                                    >
                                    <p v-if="errors.phone" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.phone }}</p>
                                    <p v-else class="text-xs text-slate-400 mt-1.5 px-1">Only a masked number is kept (0917 •••• 4567).</p>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="flex items-center gap-3 cursor-pointer group w-fit">
                                        <input v-model="walletForm.makePrimary" type="checkbox" class="w-[18px] h-[18px] accent-[#0d9488] cursor-pointer">
                                        <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900 transition-colors">Use as my primary payment method</span>
                                    </label>
                                </div>

                                <div class="md:col-span-2 pt-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                                    <button type="button" class="px-6 py-3.5 rounded-2xl text-sm font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors" @click="closeForm">Cancel</button>
                                    <button type="submit" class="px-10 py-3.5 bg-[#0d9488] text-white rounded-2xl text-sm font-bold hover:bg-[#0f766e] transition-all">Connect Wallet</button>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- Empty state -->
                    <div
                        v-if="!hasMethods && !isFormOpen"
                        class="bg-white rounded-3xl border border-dashed border-slate-200 p-12 text-center"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="w-14 h-14 mx-auto rounded-2xl bg-teal-50 text-[#0d9488] flex items-center justify-center mb-4">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect width="20" height="14" x="2" y="5" rx="2" /><line x1="2" x2="22" y1="10" y2="10" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-900">No saved payment methods</h2>
                        <p class="text-slate-500 text-sm mt-1 mb-6">Add a card or connect a wallet to speed up checkout.</p>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                            @click="openAddForm('card')"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" /><path d="M12 5v14" />
                            </svg>
                            Add New Method
                        </button>
                    </div>

                    <!-- Cards -->
                    <section v-if="cards.length" class="space-y-5">
                        <h2 class="text-xl font-bold text-slate-900">Credit &amp; Debit Cards</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <article
                                v-for="card in cards"
                                :key="card.id"
                                class="bg-white rounded-3xl p-6 relative overflow-hidden transition-colors"
                                :class="card.isPrimary ? 'border border-teal-100' : 'border border-slate-100'"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                            >
                                <div
                                    v-if="card.isPrimary"
                                    class="absolute top-0 right-0 w-32 h-32 bg-teal-50 rounded-full -mr-16 -mt-16 opacity-60"
                                ></div>

                                <div class="relative flex justify-between items-start mb-8">
                                    <span class="px-3 py-1 rounded-lg border text-[11px] font-black uppercase tracking-wide" :class="brandClass(card.brand)">
                                        {{ card.brand }}
                                    </span>
                                    <span
                                        v-if="card.isPrimary"
                                        class="inline-flex items-center gap-1.5 bg-teal-50 text-[#0d9488] text-[10px] font-bold uppercase py-1 px-2.5 rounded-lg border border-teal-100"
                                    >
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" stroke="none">
                                            <path d="M12 2 15.09 8.26 22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                                        </svg>
                                        Primary
                                    </span>
                                    <span
                                        v-else-if="card.label"
                                        class="bg-slate-100 text-slate-500 text-[10px] font-bold uppercase py-1 px-2.5 rounded-lg"
                                    >{{ card.label }}</span>
                                </div>

                                <div class="relative space-y-4">
                                    <div>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Card Number</p>
                                        <p class="text-lg font-bold text-slate-900 tracking-wider">•••• •••• •••• {{ card.last4 }}</p>
                                    </div>
                                    <div class="flex justify-between items-end">
                                        <div>
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Cardholder</p>
                                            <p class="text-sm font-semibold text-slate-700">{{ card.holder }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Expires</p>
                                            <p class="text-sm font-semibold text-slate-700">{{ cardExpiryLabel(card) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="relative mt-8 pt-5 border-t border-slate-50 flex flex-wrap items-center gap-x-4 gap-y-2">
                                    <button
                                        v-if="!card.isPrimary"
                                        type="button"
                                        class="text-xs font-bold text-[#0d9488] hover:text-[#0f766e] transition-colors"
                                        @click="handleSetPrimary(card)"
                                    >
                                        Set as Primary
                                    </button>
                                    <span v-if="!card.isPrimary" class="w-1 h-1 bg-slate-200 rounded-full"></span>
                                    <button type="button" class="text-xs font-bold text-slate-400 hover:text-slate-900 transition-colors" @click="openEditCard(card)">Edit</button>
                                    <span class="w-1 h-1 bg-slate-200 rounded-full"></span>
                                    <button type="button" class="text-xs font-bold text-red-400 hover:text-red-500 transition-colors" @click="confirmRemoveCard(card)">Remove</button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <!-- Wallets -->
                    <section v-if="wallets.length" class="space-y-5">
                        <h2 class="text-xl font-bold text-slate-900">Digital Wallets</h2>
                        <div class="space-y-4">
                            <article
                                v-for="wallet in wallets"
                                :key="wallet.id"
                                class="bg-white rounded-3xl border border-slate-100 p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
                                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                            >
                                <div class="flex items-center gap-5">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center shrink-0" :class="walletTileClass(wallet.provider)">
                                        <span class="font-black text-[10px] uppercase tracking-tight">{{ wallet.provider }}</span>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-slate-900">{{ wallet.provider }} Wallet</h3>
                                        <p class="text-sm text-slate-500">Connected: {{ wallet.phoneMasked }}</p>
                                        <span
                                            v-if="wallet.isPrimary"
                                            class="inline-flex items-center gap-1 mt-2 text-[9px] font-bold text-[#0d9488] uppercase tracking-widest bg-teal-50 px-2 py-0.5 rounded-md"
                                        >
                                            Primary
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 shrink-0">
                                    <button
                                        v-if="!wallet.isPrimary"
                                        type="button"
                                        class="px-4 py-2 bg-slate-50 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-100 transition-colors"
                                        @click="handleSetPrimary(wallet)"
                                    >
                                        Set as Primary
                                    </button>
                                    <button
                                        type="button"
                                        class="w-10 h-10 flex items-center justify-center rounded-xl border border-slate-100 text-slate-400 hover:text-red-500 hover:bg-slate-50 transition-all"
                                        :aria-label="`Disconnect ${wallet.provider} wallet`"
                                        @click="confirmRemoveWallet(wallet)"
                                    >
                                        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                        </svg>
                                    </button>
                                </div>
                            </article>
                        </div>
                    </section>

                    <!-- Additional options -->
                    <section class="space-y-5">
                        <h2 class="text-xl font-bold text-slate-900">Additional Options</h2>
                        <div class="bg-slate-50 rounded-3xl p-6 sm:p-8 border border-dashed border-slate-200 flex items-center justify-between gap-6">
                            <div class="flex items-center gap-5">
                                <div class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center shrink-0 text-[#ea580c]" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">
                                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect width="20" height="12" x="2" y="6" rx="2" /><circle cx="12" cy="12" r="2" /><path d="M6 12h.01" /><path d="M18 12h.01" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900">Cash on Delivery (COD)</h3>
                                    <p class="text-sm text-slate-500">Offer COD at checkout where the courier supports it.</p>
                                </div>
                            </div>
                            <label class="inline-flex items-center cursor-pointer shrink-0">
                                <input
                                    type="checkbox"
                                    class="sr-only peer"
                                    :checked="codEnabled"
                                    @change="handleCodToggle"
                                >
                                <span class="sr-only">Enable Cash on Delivery</span>
                                <div class="relative w-11 h-6 bg-slate-200 rounded-full peer peer-checked:bg-[#0d9488] peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                            </label>
                        </div>
                    </section>

                    <!-- Security note -->
                    <div class="bg-slate-900 text-white rounded-3xl p-6 flex items-start gap-4">
                        <div class="w-10 h-10 bg-white/10 rounded-xl flex items-center justify-center shrink-0 text-[#0d9488]">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /><path d="m9 12 2 2 4-4" />
                            </svg>
                        </div>
                        <div>
                            <h4 class="font-bold text-sm mb-1">Only tokens are stored here</h4>
                            <p class="text-xs text-slate-400 leading-relaxed">This screen keeps a card's brand, last 4 digits, and expiry — never the full number or CVV. Payment itself isn't processed by NEXMART yet; this list is for filling checkout in faster.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />

    </div>
</template>

<style scoped>
.pm-feedback-enter-active,
.pm-feedback-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.pm-feedback-enter-from,
.pm-feedback-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>

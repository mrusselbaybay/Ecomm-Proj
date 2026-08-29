<script setup>
/*
|--------------------------------------------------------------------------
| SavedAddresses.vue — the buyer's address book
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Premium Saved
| Addresses") onto NEXMART's stack the same way as the rest of the
| account area: Tailwind utilities, the shared Header/Footer, inline SVG
| icons (the reference's iconify web component isn't a dependency here),
| #0d9488 brand teal.
|
| Data lives in useBuyerAddresses.js — localStorage-backed, because there
| is no addresses endpoint yet (see that file). Fields are trimmed to a
| Philippine shape (province, not "state"; city/municipality) matching
| Checkout.vue's own address form rather than the reference's US layout,
| and the fake city/state <select>s with three hardcoded options are
| plain text inputs instead.
|
| UX changes on top of the static reference:
|   - The add form is collapsed by default and doubles as the edit form
|     (one form, "Add"/"Edit" modes) with a Cancel — the page stays a
|     clean list when you're only reviewing.
|   - Inline required/format validation (the reference only shows a "*").
|   - Delete asks for confirmation; a dismissible banner confirms saves
|     and removals.
|   - Exactly one default at all times: adding the first address makes it
|     default, deleting the default promotes the next one.
|   - Empty state, mobile layout, labelled inputs, a real radio group.
|
*/
import { computed, nextTick, reactive, ref } from 'vue';

import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';

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
    'view-payments'
]);

const {
    addresses,
    hasAddresses,
    ADDRESS_LABELS,
    addAddress,
    updateAddress,
    removeAddress,
    setDefault
} = useBuyerAddresses();

/*
|--------------------------------------------------------------------------
| Add / Edit form
|--------------------------------------------------------------------------
*/

const isFormOpen = ref(false);
const editingId = ref(null);
const errors = ref({});
const feedback = ref('');
const fullNameInput = ref(null);

let feedbackTimer = null;

const form = reactive({
    fullName: '',
    phone: '',
    line1: '',
    city: '',
    province: '',
    postalCode: '',
    label: 'Home',
    makeDefault: false
});

const isEditing = computed(() => editingId.value !== null);

function resetForm() {
    form.fullName = '';
    form.phone = '';
    form.line1 = '';
    form.city = '';
    form.province = '';
    form.postalCode = '';
    form.label = 'Home';
    form.makeDefault = false;
    errors.value = {};
}

function openAddForm() {
    resetForm();
    editingId.value = null;
    isFormOpen.value = true;
    nextTick(() => fullNameInput.value?.focus());
}

function openEditForm(address) {
    resetForm();
    editingId.value = address.id;
    form.fullName = address.fullName;
    form.phone = address.phone;
    form.line1 = address.line1;
    form.city = address.city;
    form.province = address.province;
    form.postalCode = address.postalCode;
    form.label = address.label;
    form.makeDefault = address.isDefault;
    isFormOpen.value = true;
    nextTick(() => fullNameInput.value?.focus());
}

function closeForm() {
    isFormOpen.value = false;
    editingId.value = null;
    resetForm();
}

function showFeedback(message) {
    feedback.value = message;
    clearTimeout(feedbackTimer);
    feedbackTimer = setTimeout(() => {
        feedback.value = '';
    }, 3000);
}

function validate() {
    const next = {};

    if (!form.fullName.trim()) {
        next.fullName = 'Full name is required.';
    }

    const normalizedPhone = form.phone.replace(/[\s-]/g, '');

    if (!normalizedPhone) {
        next.phone = 'Phone number is required.';
    } else if (!/^(09\d{9}|\+639\d{9})$/.test(normalizedPhone)) {
        next.phone = 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
    }

    if (!form.line1.trim()) {
        next.line1 = 'Street address is required.';
    }

    if (!form.city.trim()) {
        next.city = 'City / municipality is required.';
    }

    if (!form.province.trim()) {
        next.province = 'Province is required.';
    }

    if (form.postalCode.trim() && !/^\d{4}$/.test(form.postalCode.trim())) {
        next.postalCode = 'PH postal codes are 4 digits.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

function submitForm() {
    if (!validate()) {
        return;
    }

    if (isEditing.value) {
        updateAddress(editingId.value, { ...form });
        showFeedback('Address updated.');
    } else {
        addAddress({ ...form });
        showFeedback('Address saved.');
    }

    closeForm();
}

/*
|--------------------------------------------------------------------------
| Card actions
|--------------------------------------------------------------------------
*/

function handleSetDefault(address) {
    setDefault(address.id);
    showFeedback(`"${address.label}" is now your default address.`);
}

function confirmDelete(address) {
    const ok = window.confirm(
        `Remove this ${address.label.toLowerCase()} address for ${address.fullName}?`
    );

    if (!ok) {
        return;
    }

    // If the form was open editing the one being deleted, drop it.
    if (editingId.value === address.id) {
        closeForm();
    }

    removeAddress(address.id);
    showFeedback('Address removed.');
}

function addressLines(address) {
    const cityProvince = [address.city, address.province].filter(Boolean).join(', ');

    return [
        address.line1,
        [cityProvince, address.postalCode].filter(Boolean).join(' '),
        'Philippines'
    ].filter(Boolean);
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
                <!-- SIDEBAR NAV (matching Account / Orders) -->
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
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                            Saved Addresses
                        </button>

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl text-slate-500 hover:bg-slate-50 transition-colors"
                            @click="emit('view-payments')"
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
                            <span class="text-slate-900">Saved Addresses</span>
                        </nav>
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Saved Addresses</h1>
                                <p class="text-slate-500 mt-1">Delivery addresses you can pick from at checkout. Saved on this device.</p>
                            </div>
                            <button
                                v-if="!isFormOpen"
                                type="button"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                                @click="openAddForm"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                                Add New Address
                            </button>
                        </div>
                    </div>

                    <!-- Feedback banner -->
                    <Transition name="addr-feedback">
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

                    <!-- Empty state -->
                    <div
                        v-if="!hasAddresses && !isFormOpen"
                        class="bg-white rounded-3xl border border-dashed border-slate-200 p-12 text-center"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="w-14 h-14 mx-auto rounded-2xl bg-teal-50 text-[#0d9488] flex items-center justify-center mb-4">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-900">No saved addresses yet</h2>
                        <p class="text-slate-500 text-sm mt-1 mb-6">Add one now so checkout is a couple of taps next time.</p>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                            @click="openAddForm"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14" /><path d="M12 5v14" />
                            </svg>
                            Add New Address
                        </button>
                    </div>

                    <!-- Address cards -->
                    <div
                        v-if="hasAddresses"
                        class="grid grid-cols-1 md:grid-cols-2 gap-6"
                    >
                        <article
                            v-for="address in addresses"
                            :key="address.id"
                            class="bg-white rounded-3xl p-8 relative transition-colors"
                            :class="address.isDefault ? 'border-2 border-[#0d9488]' : 'border border-slate-200'"
                            style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                        >
                            <div class="absolute top-6 right-6 flex flex-wrap justify-end gap-2">
                                <span
                                    v-if="address.isDefault"
                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-bold bg-teal-50 text-[#0d9488] uppercase tracking-wide"
                                >
                                    <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" stroke="none">
                                        <path d="M12 2 15.09 8.26 22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                                    </svg>
                                    Default
                                </span>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 uppercase tracking-wide">
                                    {{ address.label }}
                                </span>
                            </div>

                            <div class="space-y-4">
                                <div class="pr-24">
                                    <h3 class="text-lg font-bold text-slate-900">{{ address.fullName }}</h3>
                                    <p class="text-sm text-slate-500 mt-2 leading-relaxed">
                                        <template v-for="(line, index) in addressLines(address)" :key="index">
                                            {{ line }}<br>
                                        </template>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2 text-sm text-slate-500">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                    </svg>
                                    <span>{{ address.phone }}</span>
                                </div>
                                <div class="pt-4 flex flex-wrap items-center gap-3">
                                    <button
                                        v-if="!address.isDefault"
                                        type="button"
                                        class="flex-1 min-w-[7rem] py-2.5 bg-white text-[#0d9488] rounded-xl text-xs font-bold hover:bg-teal-50 transition-all border border-teal-100"
                                        @click="handleSetDefault(address)"
                                    >
                                        Set as Default
                                    </button>
                                    <button
                                        type="button"
                                        class="flex-1 min-w-[5rem] py-2.5 bg-slate-50 text-slate-700 rounded-xl text-xs font-bold hover:bg-slate-100 transition-all border border-slate-200"
                                        @click="openEditForm(address)"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="px-4 py-2.5 bg-white text-slate-400 rounded-xl text-xs font-bold hover:text-red-500 hover:bg-red-50 transition-all border border-slate-200"
                                        @click="confirmDelete(address)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>

                    <!-- Add / Edit form -->
                    <section
                        v-if="isFormOpen"
                        class="bg-white rounded-[2rem] border border-slate-200 overflow-hidden"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="px-6 sm:px-10 py-6 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 bg-teal-50 rounded-2xl flex items-center justify-center text-[#0d9488]">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                            </div>
                            <h2 class="text-xl font-bold text-slate-900">{{ isEditing ? 'Edit Address' : 'Add New Address' }}</h2>
                        </div>

                        <form class="p-6 sm:p-10 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6" @submit.prevent="submitForm">

                            <div class="md:col-span-2">
                                <label for="addr-full-name" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Full Name <span class="text-red-500">*</span></label>
                                <input
                                    id="addr-full-name"
                                    ref="fullNameInput"
                                    v-model="form.fullName"
                                    type="text"
                                    placeholder="Recipient's full name"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    :class="errors.fullName ? 'border-red-300' : 'border-slate-200'"
                                >
                                <p v-if="errors.fullName" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.fullName }}</p>
                            </div>

                            <div class="md:col-span-2">
                                <label for="addr-line1" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Street Address <span class="text-red-500">*</span></label>
                                <textarea
                                    id="addr-line1"
                                    v-model="form.line1"
                                    rows="2"
                                    placeholder="House / unit number, street, barangay"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all resize-none"
                                    :class="errors.line1 ? 'border-red-300' : 'border-slate-200'"
                                ></textarea>
                                <p v-if="errors.line1" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.line1 }}</p>
                            </div>

                            <div>
                                <label for="addr-city" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">City / Municipality <span class="text-red-500">*</span></label>
                                <input
                                    id="addr-city"
                                    v-model="form.city"
                                    type="text"
                                    placeholder="e.g. Quezon City"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    :class="errors.city ? 'border-red-300' : 'border-slate-200'"
                                >
                                <p v-if="errors.city" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.city }}</p>
                            </div>

                            <div>
                                <label for="addr-province" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Province <span class="text-red-500">*</span></label>
                                <input
                                    id="addr-province"
                                    v-model="form.province"
                                    type="text"
                                    placeholder="e.g. Metro Manila"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    :class="errors.province ? 'border-red-300' : 'border-slate-200'"
                                >
                                <p v-if="errors.province" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.province }}</p>
                            </div>

                            <div>
                                <label for="addr-postal" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Postal Code <span class="text-slate-300 font-normal normal-case">(optional)</span></label>
                                <input
                                    id="addr-postal"
                                    v-model="form.postalCode"
                                    type="text"
                                    inputmode="numeric"
                                    placeholder="1100"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    :class="errors.postalCode ? 'border-red-300' : 'border-slate-200'"
                                >
                                <p v-if="errors.postalCode" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.postalCode }}</p>
                            </div>

                            <div>
                                <label for="addr-phone" class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-2 px-1">Phone Number <span class="text-red-500">*</span></label>
                                <input
                                    id="addr-phone"
                                    v-model="form.phone"
                                    type="tel"
                                    placeholder="09XXXXXXXXX"
                                    class="w-full px-5 py-3.5 bg-slate-50 border rounded-2xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all"
                                    :class="errors.phone ? 'border-red-300' : 'border-slate-200'"
                                >
                                <p v-if="errors.phone" class="text-xs text-red-500 mt-1.5 px-1">{{ errors.phone }}</p>
                            </div>

                            <div class="md:col-span-2">
                                <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-3 px-1">Address Type</span>
                                <div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Address type">
                                    <label
                                        v-for="label in ADDRESS_LABELS"
                                        :key="label"
                                        class="cursor-pointer"
                                    >
                                        <input
                                            v-model="form.label"
                                            type="radio"
                                            name="addr-type"
                                            :value="label"
                                            class="sr-only peer"
                                        >
                                        <span class="block px-6 py-2.5 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 bg-white transition-all hover:bg-slate-50 peer-checked:bg-teal-50 peer-checked:border-[#0d9488] peer-checked:text-[#0d9488] peer-focus-visible:ring-2 peer-focus-visible:ring-[#0d9488]/30">
                                            {{ label }}
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label class="flex items-center gap-3 cursor-pointer group w-fit">
                                    <input v-model="form.makeDefault" type="checkbox" class="w-[18px] h-[18px] accent-[#0d9488] cursor-pointer">
                                    <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900 transition-colors">Set as my default delivery address</span>
                                </label>
                            </div>

                            <div class="md:col-span-2 pt-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                                <button
                                    type="button"
                                    class="px-6 py-3.5 rounded-2xl text-sm font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors"
                                    @click="closeForm"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="px-10 py-3.5 bg-[#0d9488] text-white rounded-2xl text-sm font-bold hover:bg-[#0f766e] transition-all"
                                >
                                    {{ isEditing ? 'Save Changes' : 'Save Address' }}
                                </button>
                            </div>
                        </form>
                    </section>

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
.addr-feedback-enter-active,
.addr-feedback-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.addr-feedback-enter-from,
.addr-feedback-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>

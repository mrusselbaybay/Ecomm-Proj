<script setup>
/*
|--------------------------------------------------------------------------
| Account.vue — Account Settings
|--------------------------------------------------------------------------
|
| Adapted from a pasted reference design ("ShopVerse Account Settings")
| onto NEXMART's real data and components, following the same approach as
| CategoryListing.vue: Tailwind utilities (matching Cart.vue's precedent),
| the shared Header/Footer, and #0d9488 brand teal the reference already
| used. The old version of this page rendered against a hardcoded mock
| profile ("Juan Dela Cruz") with zero matching CSS anywhere in the
| project — see useBuyerAccount.js for the data-layer half of this fix.
|
| Sidebar nav mirrors the reference's structure, but only "My Profile",
| "My Orders", and now "Wishlist" go anywhere real. My Reviews / Saved
| Addresses / Payment Methods don't have a page (or, for Payment Methods,
| any real storage) to send someone to yet, so they're shown clearly
| disabled with a "Soon" badge rather than as dead clicks or, worse, forms
| that imply data is being saved when nothing is. The Notifications &
| Marketing preferences live in their own section further down this page.
|
| "Order Tracking" deliberately isn't listed here at all, real or
| disabled — it only means anything once a specific order is in view
| (OrderDetails.vue / OrderTracking.vue), and showing it as a generic
| destination from a page with no order selected was worse than not
| showing it: clicking it just detours to My Orders, where the exact same
| label then appears again for real, reading as broken rather than as a
| deliberate handoff.
|
*/
import { ref, reactive, computed, onMounted } from 'vue';
import Header from './Header.vue';
import Footer from './Footer.vue';
import { useBuyerAccount } from '../composables/useBuyerAccount';

const emit = defineEmits([
    'back',
    'view-orders',
    'view-wishlist',
    'view-reviews',
    'view-addresses',
    'view-payments',
    'search',
    'select-category',
    'open-cart'
]);

const {
    buyerProfile,
    isLoadingSession,
    buyerFullName,
    buyerInitials,
    buyerAge,
    calculateAge,
    updateBuyerProfile,
    changePassword
} = useBuyerAccount();

/*
|--------------------------------------------------------------------------
| Profile Edit Form
|--------------------------------------------------------------------------
*/

const isEditing = ref(false);
const isSaving = ref(false);
const errors = ref({});
const successMessage = ref('');

const draft = reactive({
    firstName: '',
    middleInitial: '',
    lastName: '',
    sex: '',
    contactNumber: '',
    birthday: ''
});

const maximumBirthday = computed(() => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
});

const draftAge = computed(() => calculateAge(draft.birthday));

function copyProfileToDraft() {
    if (!buyerProfile.value) {
        return;
    }

    draft.firstName = buyerProfile.value.first_name || '';
    draft.middleInitial = buyerProfile.value.middle_initial || '';
    draft.lastName = buyerProfile.value.last_name || '';
    draft.sex = buyerProfile.value.sex || '';
    draft.contactNumber = buyerProfile.value.contact_no || '';
    draft.birthday = buyerProfile.value.birthday || '';
}

copyProfileToDraft();

function startEditing() {
    copyProfileToDraft();
    errors.value = {};
    successMessage.value = '';
    isEditing.value = true;
}

function cancelEditing() {
    copyProfileToDraft();
    errors.value = {};
    isEditing.value = false;
}

function validateProfile() {
    const nextErrors = {};

    if (!draft.firstName.trim()) {
        nextErrors.firstName = 'First name is required.';
    }

    if (!draft.lastName.trim()) {
        nextErrors.lastName = 'Last name is required.';
    }

    if (draft.middleInitial && !/^[A-Za-z]$/.test(draft.middleInitial.trim())) {
        nextErrors.middleInitial = 'Use one letter only.';
    }

    if (!draft.sex) {
        nextErrors.sex = 'Sex is required.';
    }

    const normalizedContact = draft.contactNumber.replace(/[\s-]/g, '');

    if (!normalizedContact) {
        nextErrors.contactNumber = 'Contact number is required.';
    } else if (!/^(09\d{9}|\+639\d{9})$/.test(normalizedContact)) {
        nextErrors.contactNumber = 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
    }

    if (!draft.birthday) {
        nextErrors.birthday = 'Birthday is required.';
    } else if (draft.birthday > maximumBirthday.value) {
        nextErrors.birthday = 'Birthday cannot be in the future.';
    } else if (draftAge.value === null) {
        nextErrors.birthday = 'Enter a valid birthday.';
    }

    errors.value = nextErrors;

    return Object.keys(nextErrors).length === 0;
}

async function saveProfile() {
    successMessage.value = '';

    if (!validateProfile()) {
        return;
    }

    isSaving.value = true;

    const { error } = await updateBuyerProfile({
        ...draft,
        contactNumber: draft.contactNumber.replace(/[\s-]/g, '')
    });

    isSaving.value = false;

    if (error) {
        errors.value = { form: error };
        return;
    }

    errors.value = {};
    isEditing.value = false;
    successMessage.value = 'Your profile was updated successfully.';
}

/*
|--------------------------------------------------------------------------
| Password Change
|--------------------------------------------------------------------------
*/

const showPasswordModal = ref(false);
const passwordForm = reactive({ next: '', confirm: '' });
const passwordVisible = ref(false);
const passwordError = ref('');
const passwordSaving = ref(false);
const passwordSuccess = ref(false);

function openPasswordModal() {
    passwordForm.next = '';
    passwordForm.confirm = '';
    passwordError.value = '';
    passwordSuccess.value = false;
    showPasswordModal.value = true;
}

function closePasswordModal() {
    showPasswordModal.value = false;
}

async function submitPasswordChange() {
    passwordError.value = '';

    if (passwordForm.next.length < 8) {
        passwordError.value = 'Use at least 8 characters.';
        return;
    }

    if (passwordForm.next !== passwordForm.confirm) {
        passwordError.value = 'Passwords do not match.';
        return;
    }

    passwordSaving.value = true;

    const { error } = await changePassword(passwordForm.next);

    passwordSaving.value = false;

    if (error) {
        passwordError.value = error;
        return;
    }

    passwordSuccess.value = true;
    passwordForm.next = '';
    passwordForm.confirm = '';
}

/*
|--------------------------------------------------------------------------
| Notification Preferences
|--------------------------------------------------------------------------
|
| No `profiles` column or endpoint for these yet — same "confirms locally,
| not actually persisted server-side" territory Footer.vue's newsletter
| form already occupies in this codebase. Saved to localStorage (this is a
| real browser app, not a sandboxed artifact) purely so a buyer's choice
| survives a refresh; wiring these to something that actually changes what
| emails/texts get sent is real backend work for later.
|
*/

const NOTIF_STORAGE_KEY = 'nexmart_buyer_notification_prefs';

const notifPrefs = reactive({
    email: true,
    sms: false,
    newsletter: true
});

onMounted(() => {
    try {
        const saved = JSON.parse(localStorage.getItem(NOTIF_STORAGE_KEY) || 'null');

        if (saved) {
            Object.assign(notifPrefs, saved);
        }
    } catch (err) {
        // Corrupt/unavailable storage — fall back to the defaults above.
    }
});

function persistNotifPrefs() {
    try {
        localStorage.setItem(NOTIF_STORAGE_KEY, JSON.stringify(notifPrefs));
    } catch (err) {
        // Storage unavailable (private browsing etc.) — preference just
        // won't survive a refresh; nothing to recover from mid-session.
    }
}

/*
|--------------------------------------------------------------------------
| Delete Account
|--------------------------------------------------------------------------
|
| There's no account-deletion endpoint to call. Rather than fake success
| or leave a dead button, this routes to a pre-filled support email —
| a real, working action, and an honest one: even apps with this feature
| fully built often route it through a support/manual process rather than
| instant self-service deletion.
|
*/

const showDeleteModal = ref(false);

const deleteMailtoHref = computed(() => {
    const subject = encodeURIComponent('Account Deletion Request');
    const body = encodeURIComponent(
        `Please delete my NEXMART buyer account.\n\nName: ${buyerFullName.value}\nAccount email: ${buyerProfile.value?.email || ''}\nAccount ID: ${buyerProfile.value?.id || ''}`
    );

    return `mailto:support@nexmart.com?subject=${subject}&body=${body}`;
});

/*
|--------------------------------------------------------------------------
| Sidebar / Section Scroll
|--------------------------------------------------------------------------
*/

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/*
|--------------------------------------------------------------------------
| Header Relay
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
            @account-click="() => {}"
            @logo-click="emit('back')"
            @search="handleHeaderSearch"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">

            <!-- Not Signed In -->
            <div
                v-if="!isLoadingSession && !buyerProfile"
                class="max-w-lg mx-auto text-center bg-white rounded-3xl border border-slate-100 p-12"
                style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
            >
                <div class="w-16 h-16 rounded-full bg-teal-50 text-[#0d9488] flex items-center justify-center mx-auto mb-6">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
                        <circle cx="12" cy="7" r="4" />
                    </svg>
                </div>
                <h2 class="text-xl font-bold text-slate-900 mb-2">Sign in to view your account</h2>
                <p class="text-slate-500 mb-6">Your profile, orders, and settings live here once you're signed in.</p>
                <a
                    href="/login"
                    class="inline-block bg-[#0d9488] hover:bg-[#0f766e] text-white font-bold text-sm px-8 py-3 rounded-full transition-colors"
                >
                    Sign In
                </a>
            </div>

            <!-- Loading -->
            <div
                v-else-if="isLoadingSession"
                class="empty-products"
            >
                <p>Loading your account&hellip;</p>
            </div>

            <!-- Loaded -->
            <div
                v-else
                class="flex flex-col md:flex-row md:items-start gap-8"
            >

                <!-- ==================================================== -->
                <!-- SIDEBAR NAV -->
                <!-- ==================================================== -->

                <aside class="w-full md:w-64 shrink-0 md:sticky md:top-36">
                    <nav class="bg-white rounded-3xl border border-slate-100 p-4 space-y-1" style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);">

                        <button
                            type="button"
                            class="flex items-center gap-3 w-full px-4 py-3 rounded-2xl bg-slate-100 text-[#0d9488] font-semibold transition-colors"
                            @click="scrollToSection('profile-section')"
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
                <!-- CONTENT -->
                <!-- ==================================================== -->

                <div class="flex-1 space-y-8 min-w-0">

                    <div class="mb-2">
                        <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Account Settings</h1>
                        <p class="text-slate-500 mt-1">Manage your profile, security, and notification preferences.</p>
                    </div>

                    <p
                        v-if="successMessage"
                        role="status"
                        class="flex items-center gap-2 bg-emerald-50 text-emerald-700 border border-emerald-100 rounded-2xl px-5 py-3 text-sm font-medium"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0">
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                        {{ successMessage }}
                    </p>

                    <!-- Profile Information -->
                    <section
                        id="profile-section"
                        class="bg-white rounded-3xl border border-slate-100 p-8 scroll-mt-24"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="flex items-center justify-between mb-8">
                            <h2 class="text-xl font-bold text-slate-900">Profile Information</h2>
                            <button
                                v-if="!isEditing"
                                type="button"
                                class="px-4 py-2 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                                @click="startEditing"
                            >
                                Edit Profile
                            </button>
                        </div>

                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-6 mb-8 pb-8 border-b border-slate-50">
                            <div class="w-24 h-24 rounded-full bg-teal-50 text-[#0d9488] flex items-center justify-center text-2xl font-bold border-4 border-slate-50 shrink-0">
                                {{ buyerInitials }}
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <h3 class="text-lg font-bold text-slate-900">{{ buyerFullName }}</h3>
                                    <span
                                        v-if="buyerProfile.account_status"
                                        class="text-[10px] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full"
                                        :class="buyerProfile.account_status === 'active'
                                            ? 'bg-emerald-50 text-emerald-600'
                                            : 'bg-amber-50 text-amber-600'"
                                    >
                                        {{ buyerProfile.account_status }}
                                    </span>
                                </div>
                                <p class="text-slate-500 text-sm mt-1">{{ buyerProfile.email }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="saveProfile">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-10 gap-y-6">

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">First Name *</span>
                                    <input
                                        v-model="draft.firstName"
                                        type="text"
                                        autocomplete="given-name"
                                        :disabled="!isEditing"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 disabled:bg-transparent disabled:border-transparent disabled:px-0 disabled:font-medium border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.firstName ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                    <small v-if="errors.firstName" class="block text-red-500 text-xs mt-1">{{ errors.firstName }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Middle Initial</span>
                                    <input
                                        v-model="draft.middleInitial"
                                        type="text"
                                        maxlength="1"
                                        autocomplete="additional-name"
                                        :disabled="!isEditing"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 disabled:bg-transparent disabled:border-transparent disabled:px-0 disabled:font-medium border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.middleInitial ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                    <small v-if="errors.middleInitial" class="block text-red-500 text-xs mt-1">{{ errors.middleInitial }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Last Name *</span>
                                    <input
                                        v-model="draft.lastName"
                                        type="text"
                                        autocomplete="family-name"
                                        :disabled="!isEditing"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 disabled:bg-transparent disabled:border-transparent disabled:px-0 disabled:font-medium border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.lastName ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                    <small v-if="errors.lastName" class="block text-red-500 text-xs mt-1">{{ errors.lastName }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Sex *</span>
                                    <select
                                        v-if="isEditing"
                                        v-model="draft.sex"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.sex ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                        <option value="" disabled>Select sex</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Prefer not to say">Prefer not to say</option>
                                    </select>
                                    <p v-else class="text-sm text-slate-900 font-medium py-2.5">{{ draft.sex || '—' }}</p>
                                    <small v-if="errors.sex" class="block text-red-500 text-xs mt-1">{{ errors.sex }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Contact Number *</span>
                                    <input
                                        v-model="draft.contactNumber"
                                        type="tel"
                                        autocomplete="tel"
                                        placeholder="09XXXXXXXXX"
                                        :disabled="!isEditing"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 disabled:bg-transparent disabled:border-transparent disabled:px-0 disabled:font-medium border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.contactNumber ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                    <small v-if="errors.contactNumber" class="block text-red-500 text-xs mt-1">{{ errors.contactNumber }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Birthday *</span>
                                    <input
                                        v-if="isEditing"
                                        v-model="draft.birthday"
                                        type="date"
                                        autocomplete="bday"
                                        :max="maximumBirthday"
                                        class="w-full px-4 py-2.5 rounded-xl text-sm text-slate-900 border transition-colors focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                                        :class="errors.birthday ? 'border-red-300' : 'border-slate-200 bg-slate-50'"
                                    >
                                    <p v-else class="text-sm text-slate-900 font-medium py-2.5">
                                        {{ draft.birthday
                                            ? new Date(`${draft.birthday}T00:00:00`).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
                                            : '—' }}
                                    </p>
                                    <small v-if="errors.birthday" class="block text-red-500 text-xs mt-1">{{ errors.birthday }}</small>
                                </label>

                                <label class="block">
                                    <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Age</span>
                                    <p class="text-sm text-slate-900 font-medium py-2.5">
                                        {{ (isEditing ? draftAge : buyerAge) ?? 'Not available' }}
                                    </p>
                                    <small class="block text-slate-400 text-xs mt-1">Calculated from birthday.</small>
                                </label>

                            </div>

                            <p v-if="errors.form" class="text-sm text-red-500 mt-6">{{ errors.form }}</p>

                            <footer
                                v-if="isEditing"
                                class="flex justify-end gap-3 mt-8 pt-6 border-t border-slate-50"
                            >
                                <button
                                    type="button"
                                    class="px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                                    @click="cancelEditing"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="isSaving"
                                    class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-[#0d9488] hover:bg-[#0f766e] disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                                >
                                    {{ isSaving ? 'Saving…' : 'Save Changes' }}
                                </button>
                            </footer>
                        </form>
                    </section>

                    <!-- Password & Security -->
                    <section
                        class="bg-white rounded-3xl border border-slate-100 p-8"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <h2 class="text-xl font-bold text-slate-900 mb-8">Password &amp; Security</h2>

                        <div class="space-y-8">
                            <div class="flex items-center justify-between gap-6 pb-8 border-b border-slate-50">
                                <div>
                                    <h3 class="font-bold text-slate-900">Account Password</h3>
                                    <p class="text-sm text-slate-500">Change your password any time.</p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 px-4 py-2 border border-slate-200 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 transition-colors"
                                    @click="openPasswordModal"
                                >
                                    Change Password
                                </button>
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div>
                                    <h3 class="font-bold text-slate-900 flex items-center gap-2">
                                        Two-Factor Authentication (2FA)
                                        <span class="text-[9px] font-bold uppercase tracking-wide bg-slate-100 text-slate-400 px-2 py-0.5 rounded-full">Soon</span>
                                    </h3>
                                    <p class="text-sm text-slate-500">Secure your account with an extra verification layer via SMS or Authenticator App.</p>
                                </div>
                                <div
                                    class="relative w-11 h-6 bg-slate-200 rounded-full shrink-0 opacity-60 cursor-not-allowed"
                                    title="Coming soon"
                                >
                                    <div class="absolute top-[2px] left-[2px] bg-white rounded-full h-5 w-5"></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Notifications & Marketing -->
                    <section
                        id="notifications-section"
                        class="bg-white rounded-3xl border border-slate-100 p-8 scroll-mt-24"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                    >
                        <div class="flex items-baseline justify-between mb-1">
                            <h2 class="text-xl font-bold text-slate-900">Notifications &amp; Marketing</h2>
                        </div>
                        <p class="text-xs text-slate-400 mb-8">Saved on this device.</p>

                        <div class="space-y-8">
                            <div class="flex items-center justify-between gap-6">
                                <div class="max-w-xl">
                                    <h3 class="font-bold text-slate-900">Email Notifications</h3>
                                    <p class="text-sm text-slate-500">Receive transaction invoices, order shipping alerts, and product tracking links via email.</p>
                                </div>
                                <label class="inline-flex items-center cursor-pointer shrink-0">
                                    <input
                                        v-model="notifPrefs.email"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="persistNotifPrefs"
                                    >
                                    <div class="relative w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#0d9488]"></div>
                                </label>
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="max-w-xl">
                                    <h3 class="font-bold text-slate-900">SMS Alerts</h3>
                                    <p class="text-sm text-slate-500">Instant text message updates for immediate dispatch and delivery notifications.</p>
                                </div>
                                <label class="inline-flex items-center cursor-pointer shrink-0">
                                    <input
                                        v-model="notifPrefs.sms"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="persistNotifPrefs"
                                    >
                                    <div class="relative w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#0d9488]"></div>
                                </label>
                            </div>

                            <div class="flex items-center justify-between gap-6">
                                <div class="max-w-xl">
                                    <h3 class="font-bold text-slate-900">Newsletter Subscription</h3>
                                    <p class="text-sm text-slate-500">Get exclusive weekly offers, member discount codes, and curated product launches.</p>
                                </div>
                                <label class="inline-flex items-center cursor-pointer shrink-0">
                                    <input
                                        v-model="notifPrefs.newsletter"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="persistNotifPrefs"
                                    >
                                    <div class="relative w-11 h-6 bg-slate-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#0d9488]"></div>
                                </label>
                            </div>
                        </div>
                    </section>

                    <!-- Danger Zone -->
                    <div class="flex items-center justify-start py-4">
                        <button
                            type="button"
                            class="text-red-500 hover:text-red-600 text-sm font-semibold transition-colors flex items-center gap-2"
                            @click="showDeleteModal = true"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10 11v6" /><path d="M14 11v6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                <path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                            </svg>
                            Delete Account
                        </button>
                    </div>

                </div>

            </div>

        </main>

        <Footer
            @browse-all="emit('select-category', 'All')"
            @browse-categories="emit('select-category', 'All')"
            @cart-click="emit('open-cart')"
        />

        <!-- ==================================================== -->
        <!-- CHANGE PASSWORD MODAL -->
        <!-- ==================================================== -->

        <div
            v-if="showPasswordModal"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/40"
            @click.self="closePasswordModal"
        >
            <div class="bg-white rounded-3xl w-full max-w-md p-8" style="box-shadow: 0 20px 50px -12px rgba(0,0,0,0.25);">

                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold text-slate-900">Change Password</h2>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-slate-600 transition-colors"
                        @click="closePasswordModal"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18" /><path d="m6 6 12 12" />
                        </svg>
                    </button>
                </div>

                <div v-if="passwordSuccess" class="text-center py-4">
                    <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-4">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 6 9 17l-5-5" />
                        </svg>
                    </div>
                    <p class="text-slate-900 font-semibold mb-1">Password changed</p>
                    <p class="text-slate-500 text-sm mb-6">Use your new password next time you sign in.</p>
                    <button
                        type="button"
                        class="px-6 py-2.5 rounded-xl text-sm font-bold text-white bg-[#0d9488] hover:bg-[#0f766e] transition-colors"
                        @click="closePasswordModal"
                    >
                        Done
                    </button>
                </div>

                <form
                    v-else
                    class="space-y-4"
                    @submit.prevent="submitPasswordChange"
                >
                    <label class="block">
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">New Password</span>
                        <div class="relative">
                            <input
                                v-model="passwordForm.next"
                                :type="passwordVisible ? 'text' : 'password'"
                                autocomplete="new-password"
                                placeholder="At least 8 characters"
                                class="w-full px-4 py-2.5 pr-11 rounded-xl text-sm bg-slate-50 border border-slate-200 focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                            >
                            <button
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                                :title="passwordVisible ? 'Hide password' : 'Show password'"
                                @click="passwordVisible = !passwordVisible"
                            >
                                <svg v-if="passwordVisible" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49" />
                                    <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242" />
                                    <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143" />
                                    <path d="m2 2 20 20" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                            </button>
                        </div>
                    </label>

                    <label class="block">
                        <span class="block text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1.5">Confirm New Password</span>
                        <input
                            v-model="passwordForm.confirm"
                            :type="passwordVisible ? 'text' : 'password'"
                            autocomplete="new-password"
                            class="w-full px-4 py-2.5 rounded-xl text-sm bg-slate-50 border border-slate-200 focus:outline-none focus:ring-2 focus:ring-[#0d9488]/20 focus:border-[#0d9488]"
                        >
                    </label>

                    <p v-if="passwordError" class="text-sm text-red-500">{{ passwordError }}</p>

                    <button
                        type="submit"
                        :disabled="passwordSaving"
                        class="w-full mt-2 px-6 py-3 rounded-xl text-sm font-bold text-white bg-[#0d9488] hover:bg-[#0f766e] disabled:opacity-60 disabled:cursor-not-allowed transition-colors"
                    >
                        {{ passwordSaving ? 'Saving…' : 'Update Password' }}
                    </button>
                </form>

            </div>
        </div>

        <!-- ==================================================== -->
        <!-- DELETE ACCOUNT MODAL -->
        <!-- ==================================================== -->

        <div
            v-if="showDeleteModal"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/40"
            @click.self="showDeleteModal = false"
        >
            <div class="bg-white rounded-3xl w-full max-w-md p-8" style="box-shadow: 0 20px 50px -12px rgba(0,0,0,0.25);">

                <div class="w-12 h-12 rounded-full bg-red-50 text-red-500 flex items-center justify-center mb-5">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10 11v6" /><path d="M14 11v6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                        <path d="M3 6h18" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    </svg>
                </div>

                <h2 class="text-lg font-bold text-slate-900 mb-2">Delete your account?</h2>
                <p class="text-sm text-slate-500 mb-6">
                    Account deletion isn't self-service yet — we'll open an email to our support team with your
                    account details pre-filled. They'll take it from there.
                </p>

                <div class="flex gap-3">
                    <button
                        type="button"
                        class="flex-1 px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 hover:bg-slate-50 border border-slate-200 transition-colors"
                        @click="showDeleteModal = false"
                    >
                        Cancel
                    </button>
                    <a
                        :href="deleteMailtoHref"
                        class="flex-1 text-center px-5 py-2.5 rounded-xl text-sm font-bold text-white bg-red-500 hover:bg-red-600 transition-colors"
                        @click="showDeleteModal = false"
                    >
                        Email Support
                    </a>
                </div>

            </div>
        </div>

    </div>

</template>
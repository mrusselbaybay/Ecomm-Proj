<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref
} from 'vue';
import { useBuyerAccount } from '../composables/useBuyerAccount';

const emit = defineEmits(['back', 'view-orders']);

const {
    profile,
    address,
    isLoadingProfile,
    loadError,
    isSaving,
    buyerFullName,
    buyerInitials,
    buyerAge,
    calculateAge,
    loadBuyerAccount,
    updateBuyerProfile,
    deactivateBuyerAccount,
    confirmLogout
} = useBuyerAccount();

const PSGC_BASE = '/api/psgc';

// ------------------------------------------------------------
// Icons (inline SVG)
// ------------------------------------------------------------
const icons = {
    key: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>`,
    warning: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    check: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>`,
    close: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>`,
    alert: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    lock: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`,
    location: `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" /><circle cx="12" cy="10" r="3" /></svg>`
};

// ------------------------------------------------------------
// Toast message
// ------------------------------------------------------------
const message = ref('');
const messageType = ref('success');
let messageTimer = null;

function showMessage(text, type = 'success') {
    message.value = text;
    messageType.value = type;
    clearTimeout(messageTimer);

    if (type === 'success') {
        messageTimer = setTimeout(() => {
            message.value = '';
        }, 4000);
    }
}

// ------------------------------------------------------------
// Personal Information + Home Address (single edit toggle)
// ------------------------------------------------------------
const isEditing = ref(false);
const errors = ref({});

const draft = reactive({
    firstName: '',
    middleInitial: '',
    lastName: '',
    sex: '',
    contactNumber: '',
    birthday: '',

    provinceCode: '',
    provinceName: '',
    municipalityCode: '',
    municipalityName: '',
    barangay: '',
    street: '',
    houseNo: ''
});

const maximumBirthday = computed(() => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const day = String(today.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
});

const draftAge = computed(() => calculateAge(draft.birthday));

const accountStatusLabel = computed(() => {
    const status = profile.value?.account_status || '';

    return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
});

const fullAddressOnFile = computed(() => {
    if (!address.value) {
        return 'No address on file yet.';
    }

    return [
        address.value.house_no,
        address.value.street,
        address.value.barangay,
        address.value.municipality_name,
        address.value.province_name
    ].filter(Boolean).join(', ') || 'No address on file yet.';
});

function copyStateToDraft() {
    if (profile.value) {
        draft.firstName = profile.value.first_name || '';
        draft.middleInitial = profile.value.middle_initial || '';
        draft.lastName = profile.value.last_name || '';
        draft.sex = profile.value.sex || '';
        draft.contactNumber = profile.value.contact_no || '';
        draft.birthday = profile.value.birthday || '';
    }

    if (address.value) {
        draft.provinceCode = address.value.province_code || '';
        draft.provinceName = address.value.province_name || '';
        draft.municipalityCode = address.value.municipality_code || '';
        draft.municipalityName = address.value.municipality_name || '';
        draft.barangay = address.value.barangay || '';
        draft.street = address.value.street || '';
        draft.houseNo = address.value.house_no || '';
    } else {
        draft.provinceCode = '';
        draft.provinceName = '';
        draft.municipalityCode = '';
        draft.municipalityName = '';
        draft.barangay = '';
        draft.street = '';
        draft.houseNo = '';
    }
}

function formatDate(date) {
    if (!date) {
        return 'Not available';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function formatDateTime(date) {
    if (!date) {
        return 'Not available';
    }

    return new Date(date).toLocaleString();
}

async function startEditing() {
    copyStateToDraft();
    errors.value = {};
    isEditing.value = true;

    await fetchProvinces();

    if (draft.provinceCode) {
        await fetchMunicipalities(draft.provinceCode, { preserveSelection: true });
    }

    if (draft.municipalityCode) {
        await fetchBarangays(draft.municipalityCode, { preserveSelection: true });
    }
}

function cancelEditing() {
    copyStateToDraft();
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
    } else if (!/^09\d{9}$/.test(normalizedContact)) {
        nextErrors.contactNumber = 'Use 09XXXXXXXXX.';
    }

    if (!draft.birthday) {
        nextErrors.birthday = 'Birthday is required.';
    } else if (draft.birthday > maximumBirthday.value) {
        nextErrors.birthday = 'Birthday cannot be in the future.';
    } else if (draftAge.value === null) {
        nextErrors.birthday = 'Enter a valid birthday.';
    }

    if (!draft.provinceCode) {
        nextErrors.province = 'Please select a province.';
    }

    if (!draft.municipalityCode) {
        nextErrors.municipality = 'Please select a municipality/city.';
    }

    if (!draft.barangay) {
        nextErrors.barangay = 'Please select a barangay.';
    }

    if (!draft.street.trim()) {
        nextErrors.street = 'Street is required.';
    }

    errors.value = nextErrors;

    return Object.keys(nextErrors).length === 0;
}

async function saveProfile() {
    if (!validateProfile()) {
        return;
    }

    const saved = await updateBuyerProfile({
        first_name: draft.firstName.trim(),
        last_name: draft.lastName.trim(),
        middle_initial: draft.middleInitial.trim().toUpperCase().slice(0, 1) || null,
        sex: draft.sex,
        birthday: draft.birthday,
        contact_no: draft.contactNumber.replace(/[\s-]/g, ''),

        province_code: draft.provinceCode,
        province_name: draft.provinceName,
        municipality_code: draft.municipalityCode,
        municipality_name: draft.municipalityName,
        barangay: draft.barangay,
        street: draft.street.trim(),
        house_no: draft.houseNo.trim() || null
    });

    if (!saved) {
        showMessage('Something went wrong while saving your profile.', 'error');
        return;
    }

    copyStateToDraft();
    errors.value = {};
    isEditing.value = false;
    showMessage('Your profile was updated successfully.');
}

// ------------------------------------------------------------
// PSGC address lookups (mirrors resources/js/seller/components/
// Profile.vue's "Store Address" section — the same province /
// municipality / barangay API dropdown used across the app).
// ------------------------------------------------------------
const provinceOptions = ref([]);
const municipalityOptions = ref([]);
const barangayOptions = ref([]);
const loadingProvinces = ref(false);
const loadingMunicipalities = ref(false);
const loadingBarangays = ref(false);
const addressApiError = ref('');
const provinceCache = { value: [] };

function dedupeByCodeOrName(items = []) {
    const seen = new Map();

    for (const item of items) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const code = String(item.code ?? '').trim();
        const name = String(item.name ?? '').trim();

        if (!code && !name) {
            continue;
        }

        const key = code || name.toLowerCase().replace(/\s+/g, ' ');

        if (!seen.has(key)) {
            seen.set(key, item);
        }
    }

    return Array.from(seen.values()).sort((a, b) => a.name.localeCompare(b.name));
}

async function fetchProvinces() {
    if (provinceCache.value.length > 0) {
        provinceOptions.value = provinceCache.value;

        return;
    }

    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        const regionsRes = await fetch(`${PSGC_BASE}/regions?limit=100`);

        if (!regionsRes.ok) {
            throw new Error('Request failed: ' + regionsRes.status);
        }

        const regionsJson = await regionsRes.json();
        const regions = regionsJson.data || [];

        const provinceResults = await Promise.all(
            regions.map(async (r) => {
                try {
                    const res = await fetch(`${PSGC_BASE}/provinces?region_code=${r.code}`);

                    if (!res.ok) {
                        return [];
                    }

                    const json = await res.json();

                    return json.data || [];
                } catch {
                    return [];
                }
            })
        );

        const allProvinces = dedupeByCodeOrName(provinceResults.flat());

        if (allProvinces.length === 0) {
            throw new Error('No provinces returned');
        }

        provinceCache.value = allProvinces;
        provinceOptions.value = allProvinces;

        // Preserve the saved province if it isn't in the freshly fetched
        // list yet (e.g. slightly different casing) so the select doesn't
        // blank out.
        if (draft.provinceCode && !allProvinces.some((p) => p.code === draft.provinceCode)) {
            provinceOptions.value = [
                { code: draft.provinceCode, name: draft.provinceName },
                ...allProvinces
            ];
        }
    } catch {
        addressApiError.value =
            'Could not load provinces from the PSGC API. Check your connection and retry.';
    } finally {
        loadingProvinces.value = false;
    }
}

async function fetchMunicipalities(provinceCode, { preserveSelection = false } = {}) {
    if (!preserveSelection) {
        municipalityOptions.value = [];
        barangayOptions.value = [];
        draft.municipalityCode = '';
        draft.municipalityName = '';
        draft.barangay = '';
    }

    if (!provinceCode) {
        return;
    }

    loadingMunicipalities.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(`${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`);

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));

        if (
            preserveSelection &&
            draft.municipalityCode &&
            !data.some((m) => m.code === draft.municipalityCode)
        ) {
            municipalityOptions.value = [
                { code: draft.municipalityCode, name: draft.municipalityName },
                ...data
            ];
        } else {
            municipalityOptions.value = data;
        }
    } catch {
        addressApiError.value = 'Could not load cities/municipalities. Please try again.';
    } finally {
        loadingMunicipalities.value = false;
    }
}

async function fetchBarangays(municipalityCode, { preserveSelection = false } = {}) {
    if (!preserveSelection) {
        barangayOptions.value = [];
        draft.barangay = '';
    }

    if (!municipalityCode) {
        return;
    }

    loadingBarangays.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(
            `${PSGC_BASE}/barangays?city_municipality_code=${municipalityCode}&limit=500`
        );

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));

        if (preserveSelection && draft.barangay && !data.some((b) => b.name === draft.barangay)) {
            barangayOptions.value = [{ code: 'current', name: draft.barangay }, ...data];
        } else {
            barangayOptions.value = data;
        }
    } catch {
        addressApiError.value = 'Could not load barangays. Please try again.';
    } finally {
        loadingBarangays.value = false;
    }
}

function onProvinceChange() {
    const selected = provinceOptions.value.find((p) => p.code === draft.provinceCode);
    draft.provinceName = selected?.name || '';
    fetchMunicipalities(draft.provinceCode);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find((m) => m.code === draft.municipalityCode);
    draft.municipalityName = selected?.name || '';
    fetchBarangays(draft.municipalityCode);
}

// ------------------------------------------------------------
// Security & Password (email verification code flow — same
// endpoints and steps as the admin account settings page).
// ------------------------------------------------------------
const passwordDots = '•'.repeat(12);

const showPasswordModal = ref(false);
const passwordStep = ref('request'); // request | verify | update
const sendingCode = ref(false);
const passwordError = ref('');
const codeDigits = ref(['', '', '', '', '', '']);
const codeInputs = ref([]);
const verifyingCode = ref(false);
const verifyError = ref('');
const countdown = ref(0);
let countdownTimer = null;
const newPassword = ref('');
const confirmPassword = ref('');
const updatingPassword = ref(false);
const updateError = ref('');

const maskedEmail = computed(() => {
    const email = profile.value?.email || '';
    const [user, domain] = email.split('@');

    if (!domain) {
        return email;
    }

    const visible = user.slice(0, Math.min(2, user.length));

    return `${visible}${'*'.repeat(Math.max(user.length - visible.length, 1))}@${domain}`;
});

const formattedCountdown = computed(() => {
    const m = Math.floor(countdown.value / 60);
    const s = countdown.value % 60;

    return `${m}:${String(s).padStart(2, '0')}`;
});

const passwordStrength = computed(() => {
    const value = newPassword.value;
    let score = 0;

    if (value.length >= 8) score += 1;
    if (value.length >= 12) score += 1;
    if (/[A-Z]/.test(value)) score += 1;
    if (/[0-9]/.test(value)) score += 1;
    if (/[^A-Za-z0-9]/.test(value)) score += 1;

    if (score <= 2) {
        return { level: 'weak', label: 'Weak', percent: 33 };
    }

    if (score <= 3) {
        return { level: 'medium', label: 'Medium', percent: 66 };
    }

    return { level: 'strong', label: 'Strong', percent: 100 };
});

const meetsPasswordRules = computed(
    () =>
        newPassword.value.length >= 8 &&
        /[A-Z]/.test(newPassword.value) &&
        /[0-9]/.test(newPassword.value) &&
        /[^A-Za-z0-9]/.test(newPassword.value)
);

const canSubmitNewPassword = computed(
    () =>
        meetsPasswordRules.value &&
        newPassword.value === confirmPassword.value &&
        confirmPassword.value.length > 0
);

function startCountdown(seconds) {
    clearInterval(countdownTimer);
    countdown.value = seconds;
    countdownTimer = setInterval(() => {
        if (countdown.value <= 0) {
            clearInterval(countdownTimer);
            return;
        }

        countdown.value -= 1;
    }, 1000);
}

function openPasswordModal() {
    passwordStep.value = 'request';
    passwordError.value = '';
    codeDigits.value = ['', '', '', '', '', ''];
    verifyError.value = '';
    newPassword.value = '';
    confirmPassword.value = '';
    updateError.value = '';
    showPasswordModal.value = true;
}

function closePasswordModal() {
    showPasswordModal.value = false;
    clearInterval(countdownTimer);
    countdown.value = 0;
    codeDigits.value = ['', '', '', '', '', ''];
    newPassword.value = '';
    confirmPassword.value = '';
}

async function requestPasswordCode() {
    sendingCode.value = true;
    passwordError.value = '';

    try {
        const response = await fetch('/api/password/send-code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ email: profile.value?.email })
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'Failed to send verification code.');
        }

        startCountdown(data.expires_in_seconds || 15 * 60);
        passwordStep.value = 'verify';
        await nextTick();
        codeInputs.value[0]?.focus();
    } catch (error) {
        passwordError.value = error.message;
    } finally {
        sendingCode.value = false;
    }
}

async function resendCode() {
    sendingCode.value = true;
    verifyError.value = '';

    try {
        const response = await fetch('/api/password/resend-code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ email: profile.value?.email })
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'Failed to resend code.');
        }

        startCountdown(data.expires_in_seconds || 15 * 60);
        codeDigits.value = ['', '', '', '', '', ''];
        await nextTick();
        codeInputs.value[0]?.focus();
    } catch (error) {
        verifyError.value = error.message;
    } finally {
        sendingCode.value = false;
    }
}

function onCodeInput(index, event) {
    const raw = event.target.value.replace(/[^0-9]/g, '');
    codeDigits.value[index] = raw.slice(-1);
    verifyError.value = '';

    if (raw && index < 5) {
        codeInputs.value[index + 1]?.focus();
    }

    if (codeDigits.value.join('').length === 6) {
        verifyCode();
    }
}

function onCodeKeydown(index, event) {
    if (event.key === 'Backspace' && !codeDigits.value[index] && index > 0) {
        codeInputs.value[index - 1]?.focus();
    }
}

async function verifyCode() {
    const code = codeDigits.value.join('');

    if (code.length !== 6) {
        return;
    }

    verifyingCode.value = true;
    verifyError.value = '';

    try {
        const response = await fetch('/api/password/verify-code', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ email: profile.value?.email, code })
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'The verification code entered is incorrect.');
        }

        passwordStep.value = 'update';
    } catch (error) {
        verifyError.value = error.message;
    } finally {
        verifyingCode.value = false;
    }
}

async function submitNewPassword() {
    if (!canSubmitNewPassword.value) {
        return;
    }

    updatingPassword.value = true;
    updateError.value = '';

    try {
        const response = await fetch('/api/password/reset', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                email: profile.value?.email,
                code: codeDigits.value.join(''),
                password: newPassword.value,
                password_confirmation: confirmPassword.value
            })
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'Failed to update password.');
        }

        closePasswordModal();
        showMessage('Password changed successfully.');
    } catch (error) {
        updateError.value = error.message;
    } finally {
        updatingPassword.value = false;
    }
}

// ------------------------------------------------------------
// Danger Zone — self-deactivation
// ------------------------------------------------------------
const showDeactivateStep1 = ref(false);
const showDeactivateStep2 = ref(false);
const confirmPhrase = ref('');
const deactivatePassword = ref('');
const deactivateFormError = ref('');
const deactivating = ref(false);
const deactivateCountdown = ref(30);
let deactivateTimer = null;

function openDeactivateStep1() {
    confirmPhrase.value = '';
    showDeactivateStep1.value = true;
}

function openDeactivateStep2() {
    if (confirmPhrase.value !== 'DEACTIVATE') {
        return;
    }

    showDeactivateStep1.value = false;
    showDeactivateStep2.value = true;
    deactivatePassword.value = '';
    deactivateFormError.value = '';
    deactivateCountdown.value = 30;

    clearInterval(deactivateTimer);
    deactivateTimer = setInterval(() => {
        if (deactivateCountdown.value <= 0) {
            clearInterval(deactivateTimer);
            return;
        }

        deactivateCountdown.value -= 1;
    }, 1000);
}

function closeDeactivateModals() {
    showDeactivateStep1.value = false;
    showDeactivateStep2.value = false;
    confirmPhrase.value = '';
    deactivatePassword.value = '';
    clearInterval(deactivateTimer);
}

async function confirmDeactivate() {
    if (!deactivatePassword.value || deactivateCountdown.value > 0) {
        return;
    }

    deactivating.value = true;
    deactivateFormError.value = '';

    try {
        await deactivateBuyerAccount(confirmPhrase.value, deactivatePassword.value);

        closeDeactivateModals();
        showMessage('Your account has been deactivated. Signing you out…');
        setTimeout(() => confirmLogout(), 1500);
    } catch (error) {
        deactivateFormError.value = error.message;
    } finally {
        deactivating.value = false;
    }
}

// ------------------------------------------------------------
// Escape key closes whichever modal is open
// ------------------------------------------------------------
function handleEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (showPasswordModal.value) {
        closePasswordModal();
    } else if (showDeactivateStep2.value || showDeactivateStep1.value) {
        closeDeactivateModals();
    }
}

onMounted(async () => {
    window.addEventListener('keydown', handleEscape);

    await loadBuyerAccount();
    copyStateToDraft();

    if (loadError.value) {
        showMessage(loadError.value, 'error');
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape);
    clearInterval(countdownTimer);
    clearInterval(deactivateTimer);
});
</script>

<template>
    <div class="buyer-page buyer-account-page">
        <header class="account-page-header">
            <button
                type="button"
                class="account-back-button"
                @click="emit('back')"
            >
                Back to Shop
            </button>

            <div>
                <h1>My Account</h1>
                <p>
                    View and manage your Buyer information.
                </p>
            </div>

            <button
                type="button"
                class="account-back-button"
                @click="emit('view-orders')"
            >
                My Orders
            </button>
        </header>

        <transition name="account-fade">
            <div
                v-if="message"
                class="account-toast"
                :class="messageType === 'error' ? 'account-toast-error' : 'account-toast-success'"
                role="status"
            >
                <span class="account-toast-icon" v-html="messageType === 'error' ? icons.alert : icons.check"></span>
                <span>{{ message }}</span>
                <button type="button" class="account-toast-close" aria-label="Dismiss message" @click="message = ''">
                    <span v-html="icons.close"></span>
                </button>
            </div>
        </transition>

        <main class="account-content">
            <div v-if="isLoadingProfile && !profile" class="account-loading">
                <div class="account-loading-spinner"></div>
            </div>

            <template v-else>
                <section class="account-profile-card">
                    <div class="account-avatar">
                        {{ buyerInitials }}
                    </div>

                    <div class="account-profile-summary">
                        <div class="account-name-row">
                            <h2>{{ buyerFullName }}</h2>
                            <span
                                class="account-status-badge"
                                :class="{ 'account-status-badge--warning': profile?.account_status !== 'active' }"
                            >
                                {{ accountStatusLabel }}
                            </span>
                        </div>
                        <p>{{ profile?.email }}</p>
                        <span>
                            Buyer since {{ formatDate(profile?.created_at?.slice(0, 10)) }}
                        </span>
                    </div>

                    <button
                        v-if="!isEditing"
                        type="button"
                        class="account-edit-button"
                        @click="startEditing"
                    >
                        Edit Profile
                    </button>
                </section>

                <div class="account-page-grid">
                    <div class="account-main-column">
                    <section class="account-information-card">
                        <form
                            class="account-form"
                            @submit.prevent="saveProfile"
                        >
                            <!-- ==================================================== -->
                            <!-- PERSONAL INFORMATION -->
                            <!-- ==================================================== -->
                            <div class="account-section-heading">
                                <div>
                                    <span>Buyer Profile</span>
                                    <h2>Personal Information</h2>
                                </div>
                                <p v-if="isEditing">
                                    Fields marked with * are required.
                                </p>
                            </div>

                            <div class="account-form-grid">
                                <label class="account-field">
                                    <span>First Name *</span>
                                    <input
                                        v-model="draft.firstName"
                                        type="text"
                                        autocomplete="given-name"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.firstName }"
                                    >
                                    <small v-if="errors.firstName">{{ errors.firstName }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Middle Initial</span>
                                    <input
                                        v-model="draft.middleInitial"
                                        type="text"
                                        maxlength="1"
                                        autocomplete="additional-name"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.middleInitial }"
                                    >
                                    <small v-if="errors.middleInitial">{{ errors.middleInitial }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Last Name *</span>
                                    <input
                                        v-model="draft.lastName"
                                        type="text"
                                        autocomplete="family-name"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.lastName }"
                                    >
                                    <small v-if="errors.lastName">{{ errors.lastName }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Sex *</span>
                                    <select
                                        v-model="draft.sex"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.sex }"
                                    >
                                        <option value="" disabled>Select sex</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                    <small v-if="errors.sex">{{ errors.sex }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Birthday *</span>
                                    <input
                                        v-model="draft.birthday"
                                        type="date"
                                        autocomplete="bday"
                                        :max="maximumBirthday"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.birthday }"
                                    >
                                    <small v-if="errors.birthday">{{ errors.birthday }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Age</span>
                                    <input
                                        :value="(isEditing ? draftAge : buyerAge) ?? 'Not available'"
                                        type="text"
                                        disabled
                                    >
                                    <small class="account-field-note">
                                        Automatically calculated from birthday.
                                    </small>
                                </label>

                                <label class="account-field">
                                    <span>Contact Number *</span>
                                    <input
                                        v-model="draft.contactNumber"
                                        type="tel"
                                        autocomplete="tel"
                                        placeholder="09XXXXXXXXX"
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.contactNumber }"
                                    >
                                    <small v-if="errors.contactNumber">{{ errors.contactNumber }}</small>
                                </label>

                                <label class="account-field account-field-wide">
                                    <span>Email Address</span>
                                    <div class="account-readonly-field">
                                        <input :value="profile?.email" type="email" disabled>
                                        <span class="account-lock-icon" v-html="icons.lock" title="Email cannot be changed"></span>
                                    </div>
                                    <small class="account-field-note">
                                        Contact support to change the email on your account.
                                    </small>
                                </label>
                            </div>

                            <!-- ==================================================== -->
                            <!-- HOME ADDRESS -->
                            <!-- ==================================================== -->
                            <div class="account-section-heading account-section-heading-spaced">
                                <div>
                                    <span class="account-section-icon" v-html="icons.location"></span>
                                    <div>
                                        <span>Delivery Details</span>
                                        <h2>Home Address</h2>
                                    </div>
                                </div>
                            </div>

                            <div class="account-form-grid">
                                <label class="account-field">
                                    <span>Province *</span>
                                    <select
                                        v-model="draft.provinceCode"
                                        :disabled="!isEditing || loadingProvinces"
                                        :class="{ invalid: errors.province }"
                                        @change="onProvinceChange"
                                    >
                                        <option value="">
                                            {{ loadingProvinces ? 'Loading provinces…' : 'Select province' }}
                                        </option>
                                        <option v-for="p in provinceOptions" :key="p.code" :value="p.code">
                                            {{ p.name }}
                                        </option>
                                    </select>
                                    <small v-if="errors.province">{{ errors.province }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Municipality / City *</span>
                                    <select
                                        v-model="draft.municipalityCode"
                                        :disabled="!isEditing || loadingMunicipalities || !draft.provinceCode"
                                        :class="{ invalid: errors.municipality }"
                                        @change="onMunicipalityChange"
                                    >
                                        <option value="">
                                            {{ loadingMunicipalities ? 'Loading…' : 'Select municipality/city' }}
                                        </option>
                                        <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">
                                            {{ m.name }}
                                        </option>
                                    </select>
                                    <small v-if="errors.municipality">{{ errors.municipality }}</small>
                                </label>

                                <label class="account-field">
                                    <span>Barangay *</span>
                                    <select
                                        v-model="draft.barangay"
                                        :disabled="!isEditing || loadingBarangays || !draft.municipalityCode"
                                        :class="{ invalid: errors.barangay }"
                                    >
                                        <option value="">
                                            {{ loadingBarangays ? 'Loading…' : 'Select barangay' }}
                                        </option>
                                        <option v-for="b in barangayOptions" :key="b.code" :value="b.name">
                                            {{ b.name }}
                                        </option>
                                    </select>
                                    <small v-if="errors.barangay">{{ errors.barangay }}</small>
                                </label>

                                <label class="account-field">
                                    <span>House / Unit No.</span>
                                    <input
                                        v-model="draft.houseNo"
                                        type="text"
                                        placeholder="123"
                                        :disabled="!isEditing"
                                    >
                                </label>

                                <label class="account-field account-field-wide">
                                    <span>Street *</span>
                                    <input
                                        v-model="draft.street"
                                        type="text"
                                        placeholder="Rizal St."
                                        :disabled="!isEditing"
                                        :class="{ invalid: errors.street }"
                                    >
                                    <small v-if="errors.street">{{ errors.street }}</small>
                                </label>
                            </div>

                            <p v-if="addressApiError" class="account-form-error">
                                {{ addressApiError }}
                                <button type="button" class="account-inline-retry" @click="fetchProvinces">Retry</button>
                            </p>

                            <footer
                                v-if="isEditing"
                                class="account-form-actions"
                            >
                                <button
                                    type="button"
                                    class="account-cancel-button"
                                    :disabled="isSaving"
                                    @click="cancelEditing"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="account-save-button"
                                    :disabled="isSaving"
                                >
                                    {{ isSaving ? 'Saving…' : 'Save Changes' }}
                                </button>
                            </footer>
                        </form>
                    </section>

                    <!-- ==================================================== -->
                    <!-- SECURITY & PASSWORD -->
                    <!-- ==================================================== -->
                    <section class="account-security-card">
                        <div class="account-section-heading">
                            <span class="account-section-icon account-section-icon-key" v-html="icons.key"></span>
                            <div>
                                <span>Security</span>
                                <h2>Security &amp; Password</h2>
                            </div>
                        </div>

                        <p class="account-section-desc">
                            Your password is never shown — only its presence is confirmed below.
                        </p>

                        <div class="account-password-display">
                            <span class="account-password-dots" aria-hidden="true">{{ passwordDots }}</span>
                            <span class="account-password-note">Password set</span>
                        </div>

                        <button type="button" class="account-security-button" @click="openPasswordModal">
                            <span v-html="icons.key"></span> Change Password
                        </button>
                    </section>

                    <!-- ==================================================== -->
                    <!-- DANGER ZONE -->
                    <!-- ==================================================== -->
                    <section class="account-danger-card">
                        <div class="account-section-heading">
                            <span class="account-section-icon account-section-icon-warning" v-html="icons.warning"></span>
                            <div>
                                <span class="account-danger-eyebrow">Danger Zone</span>
                                <h2 class="account-danger-title">Deactivate Account</h2>
                            </div>
                        </div>

                        <p class="account-section-desc">
                            Deactivating your account is irreversible and immediately ends your session.
                        </p>

                        <button type="button" class="account-danger-button" @click="openDeactivateStep1">
                            <span v-html="icons.warning"></span> Deactivate Account
                        </button>
                    </section>
                    </div>

                    <aside class="account-overview-card">
                        <div class="account-section-heading">
                            <div>
                                <span>Account</span>
                                <h2>Account Overview</h2>
                            </div>
                        </div>

                        <dl class="account-overview-list">
                            <div>
                                <dt>Account Status</dt>
                                <dd :class="{ 'account-overview-success': profile?.account_status === 'active' }">
                                    {{ accountStatusLabel }}
                                </dd>
                            </div>
                            <div>
                                <dt>Address on File</dt>
                                <dd>{{ fullAddressOnFile }}</dd>
                            </div>
                            <div>
                                <dt>Buyer Since</dt>
                                <dd>{{ formatDate(profile?.created_at?.slice(0, 10)) }}</dd>
                            </div>
                            <div>
                                <dt>Last Updated</dt>
                                <dd>{{ formatDateTime(profile?.updated_at) }}</dd>
                            </div>
                        </dl>
                    </aside>
                </div>
            </template>
        </main>

        <!-- ==================================================== -->
        <!-- PASSWORD CHANGE MODAL -->
        <!-- ==================================================== -->
        <div v-if="showPasswordModal" class="account-modal-overlay" @click.self="closePasswordModal">
            <div class="account-modal-panel" role="dialog" aria-modal="true" aria-label="Change password">
                <div class="account-modal-header">
                    <h3>Change Password</h3>
                    <button class="account-modal-close" aria-label="Close" @click="closePasswordModal">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <template v-if="passwordStep === 'request'">
                    <p class="account-modal-desc">
                        We'll send a 6-digit verification code to
                        <strong>{{ maskedEmail }}</strong> to confirm it's really you before changing your password.
                    </p>
                    <p v-if="passwordError" class="account-field-error account-mb-2">{{ passwordError }}</p>
                    <div class="account-modal-actions">
                        <button class="account-btn-outline" @click="closePasswordModal">Cancel</button>
                        <button class="account-btn-primary" :disabled="sendingCode" @click="requestPasswordCode">
                            {{ sendingCode ? 'Sending…' : 'Send Code' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="passwordStep === 'verify'">
                    <p class="account-modal-desc">Enter the 6-digit code sent to <strong>{{ maskedEmail }}</strong>.</p>

                    <div class="account-otp-row">
                        <input
                            v-for="(digit, i) in codeDigits"
                            :key="i"
                            :ref="(el) => (codeInputs[i] = el)"
                            v-model="codeDigits[i]"
                            class="account-otp-box"
                            :class="{ invalid: verifyError }"
                            inputmode="numeric"
                            maxlength="1"
                            @input="onCodeInput(i, $event)"
                            @keydown="onCodeKeydown(i, $event)"
                        >
                    </div>

                    <p v-if="verifyError" class="account-field-error account-text-center account-mb-2">{{ verifyError }}</p>

                    <p class="account-otp-timer">
                        <span v-if="countdown > 0">Code expires in {{ formattedCountdown }}</span>
                        <button v-else type="button" class="account-link-button" :disabled="sendingCode" @click="resendCode">
                            {{ sendingCode ? 'Sending…' : 'Resend code' }}
                        </button>
                    </p>

                    <div class="account-modal-actions">
                        <button class="account-btn-outline" @click="passwordStep = 'request'">Back</button>
                        <button
                            class="account-btn-primary"
                            :disabled="verifyingCode || codeDigits.join('').length !== 6"
                            @click="verifyCode"
                        >
                            {{ verifyingCode ? 'Verifying…' : 'Verify' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="passwordStep === 'update'">
                    <p class="account-modal-desc">Choose a new password for your account.</p>

                    <label class="account-field account-mb-3">
                        <span>New Password</span>
                        <input v-model="newPassword" type="password" @input="updateError = ''">
                        <div class="account-strength-meter">
                            <div
                                class="account-strength-bar"
                                :class="`account-strength-${passwordStrength.level}`"
                                :style="{ width: passwordStrength.percent + '%' }"
                            ></div>
                        </div>
                        <small :class="`account-strength-text-${passwordStrength.level}`">
                            {{ newPassword ? passwordStrength.label : 'Enter a password' }}
                        </small>
                        <ul class="account-requirements-list">
                            <li :class="{ met: newPassword.length >= 8 }">At least 8 characters</li>
                            <li :class="{ met: /[A-Z]/.test(newPassword) }">One uppercase letter</li>
                            <li :class="{ met: /[0-9]/.test(newPassword) }">One number</li>
                            <li :class="{ met: /[^A-Za-z0-9]/.test(newPassword) }">One special character</li>
                        </ul>
                    </label>

                    <label class="account-field account-mb-2">
                        <span>Confirm Password</span>
                        <input
                            v-model="confirmPassword"
                            type="password"
                            :class="{ invalid: confirmPassword && confirmPassword !== newPassword }"
                            @input="updateError = ''"
                        >
                        <small v-if="confirmPassword && confirmPassword !== newPassword" class="account-field-error">
                            Passwords do not match.
                        </small>
                    </label>

                    <p v-if="updateError" class="account-field-error account-mb-2">{{ updateError }}</p>

                    <div class="account-modal-actions">
                        <button class="account-btn-outline" @click="closePasswordModal">Cancel</button>
                        <button
                            class="account-btn-primary"
                            :disabled="!canSubmitNewPassword || updatingPassword"
                            @click="submitNewPassword"
                        >
                            {{ updatingPassword ? 'Updating…' : 'Update Password' }}
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- DEACTIVATION MODAL — STEP 1 -->
        <!-- ==================================================== -->
        <div v-if="showDeactivateStep1" class="account-modal-overlay" @click.self="closeDeactivateModals">
            <div class="account-modal-panel" role="dialog" aria-modal="true" aria-label="Deactivate account">
                <div class="account-modal-header">
                    <h3 class="account-danger-title">Deactivate your account?</h3>
                    <button class="account-modal-close" aria-label="Close" @click="closeDeactivateModals">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <div class="account-danger-callout">
                    <span class="account-section-icon account-section-icon-warning" v-html="icons.warning"></span>
                    <div>
                        <p class="account-modal-desc account-mb-1"><strong>This action is irreversible.</strong> Deactivating your account will:</p>
                        <ul class="account-requirements-list account-danger-list">
                            <li>Immediately end your buyer session</li>
                            <li>Hide your order history from active use</li>
                            <li>Require support assistance to reactivate</li>
                        </ul>
                    </div>
                </div>

                <label class="account-field">
                    <span>Type <strong>DEACTIVATE</strong> to confirm you understand</span>
                    <input v-model="confirmPhrase" autocomplete="off" placeholder="DEACTIVATE">
                </label>

                <div class="account-modal-actions">
                    <button class="account-btn-outline" @click="closeDeactivateModals">Cancel</button>
                    <button
                        class="account-btn-danger"
                        :disabled="confirmPhrase !== 'DEACTIVATE'"
                        @click="openDeactivateStep2"
                    >
                        Continue
                    </button>
                </div>
            </div>
        </div>

        <!-- ==================================================== -->
        <!-- DEACTIVATION MODAL — STEP 2 (final) -->
        <!-- ==================================================== -->
        <div v-if="showDeactivateStep2" class="account-modal-overlay" @click.self="closeDeactivateModals">
            <div class="account-modal-panel" role="dialog" aria-modal="true" aria-label="Confirm deactivation">
                <div class="account-modal-header">
                    <h3 class="account-danger-title">Last step — this cannot be undone</h3>
                    <button class="account-modal-close" aria-label="Close" @click="closeDeactivateModals">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <p class="account-modal-desc">
                    Enter your current password to permanently deactivate your account.
                </p>

                <label class="account-field account-mb-2">
                    <span>Current Password</span>
                    <input v-model="deactivatePassword" type="password" @input="deactivateFormError = ''">
                </label>

                <p v-if="deactivateFormError" class="account-field-error account-mb-2">{{ deactivateFormError }}</p>

                <div class="account-modal-actions">
                    <button class="account-btn-outline" @click="closeDeactivateModals">Cancel</button>
                    <button
                        class="account-btn-danger"
                        :disabled="!deactivatePassword || deactivateCountdown > 0 || deactivating"
                        @click="confirmDeactivate"
                    >
                        {{ deactivating ? 'Deactivating…' : deactivateCountdown > 0 ? `Confirm (${deactivateCountdown}s)` : 'Deactivate My Account' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

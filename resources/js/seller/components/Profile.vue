<!-- resources/js/seller/components/Profile.vue -->
<template>
    <div>
        <!-- Floating save-feedback toast — visible regardless of scroll
             position, since "Save All Changes" lives in the sticky header
             but the field it's saving might be far down a long page. -->
        <Transition name="acct-toast-fade">
            <div
                v-if="toastMessage"
                class="acct-toast"
                :class="toastIsError ? 'error' : 'success'"
            >
                <svg v-if="!toastIsError" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="m4 10 4 4 8-8" />
                </svg>
                <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M10 6v5M10 14h.01" />
                    <circle cx="10" cy="10" r="8" />
                </svg>
                {{ toastMessage }}
            </div>
        </Transition>

        <!-- ================================================================
         HEADER
         ================================================================ -->
        <header class="acct-header">
            <div>
                <h2 class="acct-title">Account Settings</h2>
                <nav class="prep-breadcrumb" style="margin-top: 0.35rem">
                    <span>Personal</span>
                    <span>/</span>
                    <span>Settings</span>
                </nav>
            </div>
            <div class="flex items-center gap-3">
                <span class="acct-last-updated">Last updated: {{ lastUpdatedLabel }}</span>
                <button
                    type="button"
                    class="btn-primary"
                    @click="handleSave"
                    :disabled="savingProfile || !isFormDirty"
                    :title="!isFormDirty ? 'No changes to save' : ''"
                >
                    {{ savingProfile ? 'Saving…' : 'Save All Changes' }}
                </button>
            </div>
        </header>

        <div class="acct-layout">
            <!-- ============================================================
             LEFT: settings nav (real anchors — scrolls to each real
             section below; no Payment Methods / API Keys / Sessions,
             since none of those exist in this app)
             ============================================================ -->
            <aside class="acct-nav">
                <button
                    v-for="item in settingsNavItems"
                    :key="item.id"
                    type="button"
                    class="acct-nav-btn"
                    :class="{ active: activeSectionId === item.id }"
                    @click="scrollToSection(item.id)"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                        <path :d="item.icon" />
                    </svg>
                    {{ item.label }}
                </button>
            </aside>

            <div class="acct-content">
                <form id="profile-form" @submit.prevent="handleSave">
                    <!-- ====================================================
                     PROFILE INFORMATION (Personal Information)
                     ==================================================== -->
                    <section id="section-profile" class="card acct-section">
                        <div class="acct-section-head">
                            <div class="flex items-center gap-4">
                                <div class="acct-avatar">{{ initials }}</div>
                                <div>
                                    <h3>{{ fullName }}</h3>
                                    <p class="acct-section-sub">
                                        This is the information on file for
                                        your seller account.
                                    </p>
                                </div>
                            </div>
                            <span
                                v-if="isVerifiedSeller"
                                class="acct-verified-badge"
                            >
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="m4 10 4 4 8-8" />
                                </svg>
                                Verified Seller
                            </span>
                            <span
                                v-else
                                class="badge"
                                :class="statusBadgeClass(profile?.status)"
                            >
                                {{ profile?.status || 'Pending' }}
                            </span>
                        </div>

                        <div class="form-grid">
                            <div>
                                <label class="field-label"
                                    >Last Name
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.last_name"
                                    data-field="last_name"
                                    @input="onNameInput('last_name')"
                                    class="field-input"
                                    placeholder="Dela Cruz"
                                />
                                <span v-if="errors.last_name" class="save-msg error">{{
                                    errors.last_name
                                }}</span>
                            </div>
                            <div>
                                <label class="field-label"
                                    >First Name
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.first_name"
                                    data-field="first_name"
                                    @input="onNameInput('first_name')"
                                    class="field-input"
                                    placeholder="Juan"
                                />
                                <span v-if="errors.first_name" class="save-msg error">{{
                                    errors.first_name
                                }}</span>
                            </div>
                            <div>
                                <label class="field-label">M.I.</label>
                                <input
                                    v-model="formData.middle_initial"
                                    maxlength="1"
                                    @input="onMiddleInitialInput"
                                    class="field-input"
                                    placeholder="B"
                                />
                            </div>

                            <div>
                                <label class="field-label"
                                    >Sex
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <select v-model="formData.sex" class="field-input">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label"
                                    >Birthday
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.birthday"
                                    data-field="birthday"
                                    type="date"
                                    :max="todayStr"
                                    class="field-input"
                                />
                                <span v-if="errors.birthday" class="save-msg error">{{
                                    errors.birthday
                                }}</span>
                            </div>
                            <div>
                                <label class="field-label">Age</label>
                                <input
                                    :value="age !== null ? age : '—'"
                                    class="field-input"
                                    disabled
                                />
                            </div>

                            <div>
                                <label class="field-label"
                                    >Contact No.
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.contact_no"
                                    data-field="contact_no"
                                    @input="onContactInput"
                                    class="field-input"
                                    placeholder="09XXXXXXXXX"
                                    maxlength="11"
                                />
                                <span v-if="errors.contact_no" class="save-msg error">{{
                                    errors.contact_no
                                }}</span>
                            </div>
                            <div class="full-span" style="grid-column: span 2">
                                <label class="field-label">Email</label>
                                <input
                                    :value="profile?.email"
                                    class="field-input"
                                    disabled
                                />
                            </div>
                        </div>
                    </section>

                    <!-- ====================================================
                     BUSINESS DETAILS
                     ==================================================== -->
                    <section id="section-business" class="card acct-section">
                        <div class="acct-section-head">
                            <div>
                                <h3>Business Details</h3>
                                <p class="acct-section-sub">Shown to buyers on your storefront.</p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="full-span" style="grid-column: span 2">
                                <label class="field-label"
                                    >Business Name
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.business_name"
                                    class="field-input"
                                    placeholder="My Store"
                                />
                            </div>
                            <div>
                                <label class="field-label"
                                    >Line of Business
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <select
                                    v-model="formData.line_of_business"
                                    class="field-input"
                                >
                                    <option value="">Select category</option>
                                    <option
                                        v-for="opt in LINE_OF_BUSINESS_OPTIONS"
                                        :key="opt"
                                        :value="opt"
                                    >
                                        {{ opt }}
                                    </option>
                                </select>
                                <p class="field-hint">
                                    This is the category used for your product
                                    listings and their available options.
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- ====================================================
                     STORE ADDRESS
                     ==================================================== -->
                    <section id="section-address" class="card acct-section">
                        <div class="acct-section-head">
                            <div>
                                <h3>Store Address</h3>
                                <p class="acct-section-sub">
                                    Used for logistics and courier
                                    pickup/delivery matching.
                                </p>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div>
                                <label class="field-label"
                                    >Province
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <select
                                    v-model="formData.province_code"
                                    @change="onProvinceChange"
                                    class="field-input"
                                    :disabled="loadingProvinces"
                                >
                                    <option value="">
                                        {{
                                            loadingProvinces
                                                ? 'Loading provinces…'
                                                : 'Select province'
                                        }}
                                    </option>
                                    <option
                                        v-for="p in provinceOptions"
                                        :key="p.code"
                                        :value="p.code"
                                    >
                                        {{ p.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label"
                                    >Municipality / City
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <select
                                    v-model="formData.municipality_code"
                                    @change="onMunicipalityChange"
                                    class="field-input"
                                    :disabled="
                                        loadingMunicipalities || !formData.province_code
                                    "
                                >
                                    <option value="">
                                        {{
                                            loadingMunicipalities
                                                ? 'Loading…'
                                                : 'Select municipality/city'
                                        }}
                                    </option>
                                    <option
                                        v-for="m in municipalityOptions"
                                        :key="m.code"
                                        :value="m.code"
                                    >
                                        {{ m.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label"
                                    >Barangay
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <select
                                    v-model="formData.barangay"
                                    class="field-input"
                                    :disabled="
                                        loadingBarangays || !formData.municipality_code
                                    "
                                >
                                    <option value="">
                                        {{
                                            loadingBarangays
                                                ? 'Loading…'
                                                : 'Select barangay'
                                        }}
                                    </option>
                                    <option
                                        v-for="b in barangayOptions"
                                        :key="b.code"
                                        :value="b.name"
                                    >
                                        {{ b.name }}
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="field-label">House / Unit No.</label>
                                <input
                                    v-model="formData.house_no"
                                    class="field-input"
                                    placeholder="123"
                                />
                            </div>
                            <div class="full-span" style="grid-column: span 2">
                                <label class="field-label"
                                    >Street
                                    <span style="color: var(--teal-500)">*</span></label
                                >
                                <input
                                    v-model="formData.street"
                                    class="field-input"
                                    placeholder="Rizal St."
                                />
                            </div>
                        </div>

                        <p
                            v-if="addressApiError"
                            class="save-msg error"
                            style="margin-top: 0.75rem"
                        >
                            {{ addressApiError }}
                            <button
                                type="button"
                                @click="fetchProvinces"
                                style="
                                    text-decoration: underline;
                                    font-weight: 600;
                                    margin-left: 0.25rem;
                                "
                            >
                                Retry
                            </button>
                        </p>
                    </section>
                </form>

                <!-- ========================================================
                 SECURITY — real password change via Supabase Auth. Kept
                 outside the profile <form> since it's a separate action
                 with its own request, not part of "Save All Changes".
                 No 2FA toggle, active-sessions list, or API keys here —
                 none of those exist in this app, and a control that
                 looks functional but silently does nothing is worse than
                 not having it.
                 ======================================================== -->
                <section id="section-security" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Security</h3>
                            <p class="acct-section-sub">Update your account password.</p>
                        </div>
                    </div>

                    <div class="form-grid" style="max-width: 32rem; grid-template-columns: 1fr 1fr">
                        <div>
                            <label class="field-label">New Password</label>
                            <div class="acct-password-box">
                                <input
                                    :type="showNewPassword ? 'text' : 'password'"
                                    v-model="newPassword"
                                    class="field-input"
                                    placeholder="••••••••"
                                    autocomplete="new-password"
                                />
                                <button
                                    type="button"
                                    class="acct-password-toggle"
                                    :aria-label="showNewPassword ? 'Hide password' : 'Show password'"
                                    @click="showNewPassword = !showNewPassword"
                                >
                                    <svg v-if="showNewPassword" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M3 3l14 14M8.3 8.3a2.5 2.5 0 0 0 3.4 3.4M6.2 5.5C4 6.8 2.4 8.7 1.7 10c1.4 2.6 4.3 5.5 8.3 5.5 1.4 0 2.6-.3 3.7-.9M11.8 4.6c.7.2 1.4.5 2 .9 2 1.3 3.6 3.2 4.3 4.5-.4.7-1 1.6-1.8 2.5" />
                                    </svg>
                                    <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M1.7 10c1.4-2.6 4.3-5.5 8.3-5.5s6.9 2.9 8.3 5.5c-1.4 2.6-4.3 5.5-8.3 5.5S3.1 12.6 1.7 10Z" />
                                        <circle cx="10" cy="10" r="2.5" />
                                    </svg>
                                </button>
                            </div>
                            <p class="field-hint">At least 8 characters.</p>
                        </div>
                        <div>
                            <label class="field-label">Confirm New Password</label>
                            <div class="acct-password-box">
                                <input
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    v-model="confirmPassword"
                                    class="field-input"
                                    placeholder="••••••••"
                                    autocomplete="new-password"
                                />
                                <button
                                    type="button"
                                    class="acct-password-toggle"
                                    :aria-label="showConfirmPassword ? 'Hide password' : 'Show password'"
                                    @click="showConfirmPassword = !showConfirmPassword"
                                >
                                    <svg v-if="showConfirmPassword" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M3 3l14 14M8.3 8.3a2.5 2.5 0 0 0 3.4 3.4M6.2 5.5C4 6.8 2.4 8.7 1.7 10c1.4 2.6 4.3 5.5 8.3 5.5 1.4 0 2.6-.3 3.7-.9M11.8 4.6c.7.2 1.4.5 2 .9 2 1.3 3.6 3.2 4.3 4.5-.4.7-1 1.6-1.8 2.5" />
                                    </svg>
                                    <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
                                        <path d="M1.7 10c1.4-2.6 4.3-5.5 8.3-5.5s6.9 2.9 8.3 5.5c-1.4 2.6-4.3 5.5-8.3 5.5S3.1 12.6 1.7 10Z" />
                                        <circle cx="10" cy="10" r="2.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <p v-if="passwordError" class="save-msg error" style="margin-top: 0.75rem">
                        {{ passwordError }}
                    </p>
                    <p v-if="passwordSuccess" class="ch-success-note" style="margin-top: 0.75rem; max-width: 32rem">
                        {{ passwordSuccess }}
                    </p>

                    <button
                        type="button"
                        class="btn-outline"
                        style="margin-top: 1rem"
                        :disabled="isChangingPassword || !canUpdatePassword"
                        :title="passwordDisabledReason"
                        @click="handleChangePassword"
                    >
                        {{ isChangingPassword ? 'Updating…' : 'Update Password' }}
                    </button>
                </section>

                <!-- ========================================================
                 COMPLIANCE DOCUMENTS (read-only summary)
                 ======================================================== -->
                <section id="section-documents" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Compliance Documents</h3>
                            <p class="acct-section-sub">
                                Submitted during registration. Contact support
                                to resubmit a document.
                            </p>
                        </div>
                    </div>

                    <div v-if="documents.length === 0" class="empty-state">
                        <p>No documents on file.</p>
                    </div>
                    <div v-else class="doc-list">
                        <div v-for="doc in documents" :key="doc.id" class="doc-row">
                            <div class="doc-info">
                                <div class="avatar">
                                    {{
                                        docTypeLabel(doc.doc_type)
                                            .slice(0, 2)
                                            .toUpperCase()
                                    }}
                                </div>
                                <div>
                                    <p class="doc-type">
                                        {{ docTypeLabel(doc.doc_type) }}
                                    </p>
                                    <p class="doc-date">
                                        Submitted {{ formatDate(doc.created_at) }}
                                    </p>
                                </div>
                            </div>
                            <span
                                class="badge"
                                :class="statusBadgeClass(doc.status)"
                                >{{ doc.status }}</span
                            >
                        </div>
                    </div>
                </section>

                <!-- ========================================================
                 SAVE / RESET — the true bottom of the page, after every
                 section. `form="profile-form"` associates this button
                 with the <form> above (per the HTML spec) without it
                 needing to physically sit inside it, so it can live down
                 here instead of appearing mid-page after just the
                 Address section.
                 ======================================================== -->
                <div class="form-actions">
                    <span v-if="saveSuccess" class="save-msg success">{{
                        saveSuccess
                    }}</span>
                    <span v-if="saveError" class="save-msg error">{{
                        saveError
                    }}</span>
                    <button
                        type="button"
                        class="btn-outline"
                        @click="handleResetClick"
                        :disabled="savingProfile || !isFormDirty"
                        :title="!isFormDirty ? 'No changes to reset' : ''"
                    >
                        Reset
                    </button>
                    <button
                        type="submit"
                        form="profile-form"
                        class="btn-primary"
                        :disabled="savingProfile || !isFormDirty"
                        :title="!isFormDirty ? 'No changes to save' : ''"
                    >
                        {{ savingProfile ? 'Saving…' : 'Save Changes' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    reactive,
    computed,
    watch,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import { useSeller, getSupabase } from '../composables/useSeller';

const {
    profile,
    address,
    sellerDetails,
    documents,
    savingProfile,
    saveError,
    saveSuccess,
    LINE_OF_BUSINESS_OPTIONS,
    fullName,
    initials,
    age,
    saveProfile,
    formatDate,
    docTypeLabel,
    statusBadgeClass,
} = useSeller();

const PSGC_BASE = '/api/psgc';

const todayStr = new Date().toISOString().split('T')[0];

const errors = reactive({
    last_name: '',
    first_name: '',
    birthday: '',
    contact_no: '',
});

function emptyFormData() {
    return {
        last_name: '',
        first_name: '',
        middle_initial: '',
        sex: '',
        birthday: '',
        contact_no: '',
        province_code: '',
        province_name: '',
        municipality_code: '',
        municipality_name: '',
        barangay: '',
        street: '',
        house_no: '',
        business_name: '',
        line_of_business: '',
    };
}

const formData = reactive(emptyFormData());
const savedFormData = reactive(emptyFormData());

const provinceOptions = ref([]);
const municipalityOptions = ref([]);
const barangayOptions = ref([]);
const loadingProvinces = ref(false);
const loadingMunicipalities = ref(false);
const loadingBarangays = ref(false);
const addressApiError = ref('');
const provinceCache = { value: [] };
const municipalityCache = new Map();
const barangayCache = new Map();
const ADDRESS_CACHE_TTL_MS = 24 * 60 * 60 * 1000;

function readAddressCache(key) {
    try {
        const cached = JSON.parse(sessionStorage.getItem(key) || 'null');

        if (
            cached &&
            Array.isArray(cached.data) &&
            Date.now() - cached.savedAt < ADDRESS_CACHE_TTL_MS
        ) {
            return cached.data;
        }
    } catch {
        // Storage can be unavailable in private/restricted browser contexts.
    }

    return null;
}

function writeAddressCache(key, data) {
    try {
        sessionStorage.setItem(
            key,
            JSON.stringify({ data, savedAt: Date.now() }),
        );
    } catch {
        // A failed cache write must never prevent the form from loading.
    }
}

const PROFILE_FIELDS = [
    'last_name',
    'first_name',
    'middle_initial',
    'sex',
    'birthday',
    'contact_no',
];
const ADDRESS_FIELDS = [
    'province_code',
    'province_name',
    'municipality_code',
    'municipality_name',
    'barangay',
    'street',
    'house_no',
];
const BUSINESS_FIELDS = ['business_name', 'line_of_business'];
const hydrated = {
    profile: false,
    address: false,
    business: false,
};

function hasLoadedRecord(value) {
    return value && typeof value === 'object' && Object.keys(value).length > 0;
}

function hydrateFields(source, fields) {
    for (const field of fields) {
        const value = source?.[field] ?? '';
        formData[field] = value;
        savedFormData[field] = value;
    }
}

// Keep the form responsive even when useSeller() is still loading. Each data
// source hydrates its own fields as soon as it arrives instead of relying on a
// single onMounted snapshot that may run before Supabase has returned data.
watch(
    profile,
    (value) => {
        if (!hydrated.profile && hasLoadedRecord(value)) {
            hydrateFields(value, PROFILE_FIELDS);
            hydrated.profile = true;
        }
    },
    { immediate: true, deep: true },
);

watch(
    sellerDetails,
    (value) => {
        if (!hydrated.business && hasLoadedRecord(value)) {
            hydrateFields(value, BUSINESS_FIELDS);
            hydrated.business = true;
        }
    },
    { immediate: true, deep: true },
);

watch(
    address,
    (value) => {
        if (hydrated.address || !hasLoadedRecord(value)) {
            return;
        }

        hydrateFields(value, ADDRESS_FIELDS);
        hydrated.address = true;

        // Some stored addresses (older onboarding, a past relocation
        // entered by name) have a province/municipality NAME but no PSGC
        // code. Without a code the <select> can't show the value at all.
        // Seed a "saved:" stand-in code so the current location is
        // visible and the field stays editable; handleSave() strips it
        // back out, and picking a real option replaces it entirely.
        if (!formData.province_code && formData.province_name) {
            formData.province_code = `saved:${formData.province_name}`;
            savedFormData.province_code = formData.province_code;
        }

        if (!formData.municipality_code && formData.municipality_name) {
            formData.municipality_code = `saved:${formData.municipality_name}`;
            savedFormData.municipality_code = formData.municipality_code;
        }

        // Make saved values visible immediately while the dropdown choices
        // are fetched in the background.
        if (
            formData.province_code &&
            !provinceOptions.value.some(
                (item) => item.code === formData.province_code,
            )
        ) {
            provinceOptions.value = [
                {
                    code: formData.province_code,
                    name: formData.province_name || formData.province_code,
                },
                ...provinceOptions.value,
            ];
        }

        if (
            formData.municipality_code &&
            !municipalityOptions.value.some(
                (item) => item.code === formData.municipality_code,
            )
        ) {
            municipalityOptions.value = [
                {
                    code: formData.municipality_code,
                    name:
                        formData.municipality_name ||
                        formData.municipality_code,
                },
                ...municipalityOptions.value,
            ];
        }

        if (
            formData.barangay &&
            !barangayOptions.value.some(
                (item) => item.name === formData.barangay,
            )
        ) {
            barangayOptions.value = [
                { code: 'current', name: formData.barangay },
                ...barangayOptions.value,
            ];
        }

        // These endpoints are independent once the saved codes are known.
        // Running them together removes the previous request waterfall.
        const lookups = [];

        if (formData.province_code) {
            lookups.push(
                fetchMunicipalities(formData.province_code, {
                    preserveSelection: true,
                }),
            );
        }

        if (formData.municipality_code) {
            lookups.push(
                fetchBarangays(formData.municipality_code, {
                    preserveSelection: true,
                }),
            );
        }

        void Promise.allSettled(lookups);
    },
    { immediate: true, deep: true },
);

// ---------- dirty-check (avoids pointless saves/resets when nothing changed) ----------
const isFormDirty = computed(
    () => JSON.stringify(formData) !== JSON.stringify(savedFormData),
);

function handleResetClick() {
    if (
        isFormDirty.value &&
        !window.confirm('Discard your unsaved changes to this form?')
    ) {
        return;
    }

    resetForm();
}

function resetForm() {
    Object.assign(formData, savedFormData);
    Object.keys(errors).forEach((k) => (errors[k] = ''));
}

// ---------- PSGC address lookups (mirrors resources/js/app.js signup form) ----------
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

    return Array.from(seen.values()).sort((a, b) =>
        a.name.localeCompare(b.name),
    );
}

async function fetchProvinces() {
    if (provinceCache.value.length > 0) {
        provinceOptions.value = provinceCache.value;

        return;
    }

    const cachedProvinces = readAddressCache('seller-address:provinces');

    if (cachedProvinces?.length) {
        provinceCache.value = cachedProvinces;
        provinceOptions.value = cachedProvinces;

        return;
    }

    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        // Prefer one all-provinces request. Keep the region fan-out as a
        // compatibility fallback for older PSGC proxy routes.
        let allProvinces = [];
        const allRes = await fetch(`${PSGC_BASE}/provinces?limit=200`);

        if (allRes.ok) {
            const allJson = await allRes.json();
            allProvinces = dedupeByCodeOrName(allJson.data || []);
        }

        if (allProvinces.length === 0) {
            const regionsRes = await fetch(`${PSGC_BASE}/regions?limit=100`);

            if (!regionsRes.ok) {
                throw new Error('Request failed: ' + regionsRes.status);
            }

            const regionsJson = await regionsRes.json();
            const regions = regionsJson.data || [];
            const provinceResults = await Promise.all(
                regions.map(async (r) => {
                    try {
                        const res = await fetch(
                            `${PSGC_BASE}/provinces?region_code=${r.code}`,
                        );

                        if (!res.ok) {
                            return [];
                        }

                        const json = await res.json();

                        return json.data || [];
                    } catch {
                        return [];
                    }
                }),
            );

            allProvinces = dedupeByCodeOrName(provinceResults.flat());
        }

        if (allProvinces.length === 0) {
            throw new Error('No provinces returned');
        }

        provinceCache.value = allProvinces;
        writeAddressCache('seller-address:provinces', allProvinces);
        provinceOptions.value = allProvinces;

        // Preserve the saved province if it isn't in the freshly fetched list yet
        // (e.g. slightly different casing) so the select doesn't blank out.
        if (
            formData.province_code &&
            !allProvinces.some((p) => p.code === formData.province_code)
        ) {
            provinceOptions.value = [
                { code: formData.province_code, name: formData.province_name },
                ...allProvinces,
            ];
        }
    } catch {
        addressApiError.value =
            'Could not load provinces from the PSGC API. Check your connection and retry.';
    } finally {
        loadingProvinces.value = false;
    }
}

async function fetchMunicipalities(
    provinceCode,
    { preserveSelection = false } = {},
) {
    if (!preserveSelection) {
        municipalityOptions.value = [];
        barangayOptions.value = [];
        formData.municipality_code = '';
        formData.municipality_name = '';
        formData.barangay = '';
    }

    if (!provinceCode) {
        return;
    }

    const cacheKey = `seller-address:municipalities:${provinceCode}`;
    const cachedData =
        municipalityCache.get(provinceCode) || readAddressCache(cacheKey);

    if (cachedData?.length) {
        municipalityCache.set(provinceCode, cachedData);

        if (
            preserveSelection &&
            formData.municipality_code &&
            !cachedData.some((m) => m.code === formData.municipality_code)
        ) {
            municipalityOptions.value = [
                {
                    code: formData.municipality_code,
                    name: formData.municipality_name,
                },
                ...cachedData,
            ];
        } else {
            municipalityOptions.value = cachedData;
        }

        return;
    }

    loadingMunicipalities.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(
            `${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`,
        );

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || [])
            .slice()
            .sort((a, b) => a.name.localeCompare(b.name));

        municipalityCache.set(provinceCode, data);
        writeAddressCache(cacheKey, data);

        if (
            preserveSelection &&
            formData.municipality_code &&
            !data.some((m) => m.code === formData.municipality_code)
        ) {
            municipalityOptions.value = [
                {
                    code: formData.municipality_code,
                    name: formData.municipality_name,
                },
                ...data,
            ];
        } else {
            municipalityOptions.value = data;
        }
    } catch {
        addressApiError.value =
            'Could not load cities/municipalities. Please try again.';
    } finally {
        loadingMunicipalities.value = false;
    }
}

async function fetchBarangays(
    municipalityCode,
    { preserveSelection = false } = {},
) {
    if (!preserveSelection) {
        barangayOptions.value = [];
        formData.barangay = '';
    }

    if (!municipalityCode) {
        return;
    }

    const cacheKey = `seller-address:barangays:${municipalityCode}`;
    const cachedData =
        barangayCache.get(municipalityCode) || readAddressCache(cacheKey);

    if (cachedData?.length) {
        barangayCache.set(municipalityCode, cachedData);

        if (
            preserveSelection &&
            formData.barangay &&
            !cachedData.some((b) => b.name === formData.barangay)
        ) {
            barangayOptions.value = [
                { code: 'current', name: formData.barangay },
                ...cachedData,
            ];
        } else {
            barangayOptions.value = cachedData;
        }

        return;
    }

    loadingBarangays.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(
            `${PSGC_BASE}/barangays?city_municipality_code=${municipalityCode}&limit=500`,
        );

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || [])
            .slice()
            .sort((a, b) => a.name.localeCompare(b.name));

        barangayCache.set(municipalityCode, data);
        writeAddressCache(cacheKey, data);

        if (
            preserveSelection &&
            formData.barangay &&
            !data.some((b) => b.name === formData.barangay)
        ) {
            barangayOptions.value = [
                { code: 'current', name: formData.barangay },
                ...data,
            ];
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
    const selected = provinceOptions.value.find(
        (p) => p.code === formData.province_code,
    );
    formData.province_name = selected?.name || '';
    fetchMunicipalities(formData.province_code);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find(
        (m) => m.code === formData.municipality_code,
    );
    formData.municipality_name = selected?.name || '';
    fetchBarangays(formData.municipality_code);
}

// ---------- Input formatting / validation (mirrors resources/js/app.js) ----------
function onNameInput(field) {
    formData[field] = formData[field].replace(/[^A-Za-z\s-]/g, '');
    errors[field] = formData[field].trim() ? '' : 'This field is required';
}
function onMiddleInitialInput() {
    formData.middle_initial = formData.middle_initial
        .replace(/[^A-Za-z]/g, '')
        .toUpperCase()
        .slice(0, 1);
}
function onContactInput() {
    formData.contact_no = formData.contact_no.replace(/\D/g, '').slice(0, 11);
}

function validate() {
    let valid = true;
    errors.last_name = formData.last_name.trim()
        ? ''
        : ((valid = false), 'Last name is required');
    errors.first_name = formData.first_name.trim()
        ? ''
        : ((valid = false), 'First name is required');

    if (!formData.birthday) {
        errors.birthday = 'Birthday is required';
        valid = false;
    } else if (new Date(formData.birthday) > new Date()) {
        errors.birthday = 'Cannot select a future date';
        valid = false;
    } else {
        errors.birthday = '';
    }

    if (!/^09\d{9}$/.test(formData.contact_no)) {
        errors.contact_no = 'Enter a valid 11-digit number starting with 09';
        valid = false;
    } else {
        errors.contact_no = '';
    }

    if (!valid) {
        // Scroll/focus the first invalid field so a seller doesn't have
        // to hunt for it — especially now that the page has multiple
        // sections and the error might be off-screen.
        const firstErrorField = ['last_name', 'first_name', 'birthday', 'contact_no'].find(
            (key) => errors[key],
        );
        const el = firstErrorField
            ? document.querySelector(`[data-field="${firstErrorField}"]`)
            : null;

        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.focus();
        }
    }

    return valid;
}

// A "saved:<name>" province/municipality code is a display-only
// stand-in for a stored address that had no PSGC code — never persist it.
function stripSavedCode(code) {
    return typeof code === 'string' && code.startsWith('saved:') ? '' : code;
}

async function handleSave() {
    if (!validate()) {
        return;
    }

    await saveProfile({
        ...formData,
        province_code: stripSavedCode(formData.province_code),
        municipality_code: stripSavedCode(formData.municipality_code),
    });

    if (saveSuccess.value) {
        // Marks the form clean again so Save/Reset disable until the
        // next real edit — avoids a pointless second save of identical
        // data.
        Object.assign(savedFormData, formData);
    }

    showToast(saveSuccess.value || saveError.value, !saveSuccess.value);
}

// ---------- floating save-feedback toast ----------
const toastMessage = ref('');
const toastIsError = ref(false);
let toastTimer = null;

function showToast(message, isError) {
    if (!message) {
        return;
    }

    toastMessage.value = message;
    toastIsError.value = isError;

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toastMessage.value = '';
    }, 4000);
}

// ---------- Real: verified badge, last-updated, in-page nav ----------
const isVerifiedSeller = computed(() => profile.value?.status === 'active');

const lastUpdatedLabel = computed(() => {
    const raw = profile.value?.updated_at;

    if (!raw) {
        return '—';
    }

    return new Date(raw).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
});

const settingsNavItems = [
    { id: 'section-profile', label: 'Profile Information', icon: 'M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM4 17c0-3 2.7-5.5 6-5.5s6 2.5 6 5.5' },
    { id: 'section-business', label: 'Business Details', icon: 'M4 17V7l6-3 6 3v10M8 17v-4h4v4M4 17h12' },
    { id: 'section-address', label: 'Store Address', icon: 'M10 2a6 6 0 0 1 6 6c0 4.2-6 10-6 10s-6-5.8-6-10a6 6 0 0 1 6-6Z M10 10.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z' },
    { id: 'section-security', label: 'Security', icon: 'M10 2 4 4.5v5c0 4 2.5 6.7 6 8 3.5-1.3 6-4 6-8v-5L10 2Z' },
    { id: 'section-documents', label: 'Compliance Documents', icon: 'M6 2.5h6l3 3v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z M8 10h4M8 13h4' },
];

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ---------- scroll-spy: highlight the nav item for whichever section is
// currently in view, so the long page always shows you where you are ----------
const activeSectionId = ref('section-profile');
let sectionObserver = null;

function setupScrollSpy() {
    const sectionEls = settingsNavItems
        .map((item) => document.getElementById(item.id))
        .filter(Boolean);

    if (!sectionEls.length || typeof IntersectionObserver === 'undefined') {
        return;
    }

    sectionObserver = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((e) => e.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio);

            if (visible[0]) {
                activeSectionId.value = visible[0].target.id;
            }
        },
        { rootMargin: '-15% 0px -65% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] },
    );

    sectionEls.forEach((el) => sectionObserver.observe(el));
}

onBeforeUnmount(() => {
    sectionObserver?.disconnect();
    clearTimeout(toastTimer);
});

// ---------- Real: change password via Supabase Auth ----------
// A genuine account action, not a fabricated control — supabase.auth
// .updateUser() re-uses the seller's own already-authenticated session,
// no separate Laravel endpoint needed.
const newPassword = ref('');
const confirmPassword = ref('');
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);
const isChangingPassword = ref(false);
const passwordError = ref('');
const passwordSuccess = ref('');

const canUpdatePassword = computed(
    () => newPassword.value.length >= 8 && newPassword.value === confirmPassword.value,
);
const passwordDisabledReason = computed(() => {
    if (isChangingPassword.value) {
return '';
}

    if (newPassword.value.length < 8) {
return 'Password must be at least 8 characters.';
}

    if (newPassword.value !== confirmPassword.value) {
return 'Passwords do not match.';
}

    return '';
});

async function handleChangePassword() {
    passwordError.value = '';
    passwordSuccess.value = '';

    if (!canUpdatePassword.value) {
        passwordError.value = passwordDisabledReason.value;
        showToast(passwordError.value, true);

        return;
    }

    isChangingPassword.value = true;

    try {
        const supabase = getSupabase();
        const { error } = await supabase.auth.updateUser({
            password: newPassword.value,
        });

        if (error) {
            throw error;
        }

        passwordSuccess.value = 'Your password has been updated.';
        newPassword.value = '';
        confirmPassword.value = '';
        showToast(passwordSuccess.value, false);
    } catch (err) {
        passwordError.value = err?.message || 'Could not update your password.';
        showToast(passwordError.value, true);
    } finally {
        isChangingPassword.value = false;
    }
}

onMounted(() => {
    setupScrollSpy();
    // Do not block the account page while dropdown choices load. The saved
    // address is hydrated by the watcher above as soon as it is available.
    void fetchProvinces();
});
</script>

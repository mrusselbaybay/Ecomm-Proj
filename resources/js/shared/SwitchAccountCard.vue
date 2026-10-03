<script setup>
// "Switch Account" for the buyer/seller account settings pages. One
// account holds both roles: shopping is always available, selling needs an
// approved seller application (the modal below, first time only). The
// active role lives on the server (POST /api/account/role/switch), so the
// portal is simply reloaded at its new home afterwards.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { apiRequest } from './accountApi';

const props = defineProps({
    // Supabase-style auth client of the host portal (getSession()/getUser()).
    client: { type: Object, required: true },
    fullName: { type: String, default: '' },
    email: { type: String, default: '' },
});

const LINES_OF_BUSINESS = [
    'Pet Supplies',
    'Kids and Baby',
    'Electronics and Gadgets',
    'House and Garden',
    "Woman's Apparel",
    "Men's Apparel",
    'Sports and Outdoors',
    'Health and Beauty',
];

// Keep in sync with AuthController::ID_TYPES.
const ID_TYPES = [
    'Passport',
    "Driver's License",
    'PRC ID',
    'UMID',
    'SSS ID',
    'National ID',
    'Student ID',
    'Philippine Postal ID',
];

const MAX_FILE_BYTES = 10 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'application/pdf'];
const HOME_BY_ROLE = { buyer: '/buyer/dashboard', seller: '/seller/dashboard' };

const state = ref(null);
const loading = ref(true);
const loadError = ref('');
const switching = ref(false);
const switchError = ref('');

const isSeller = computed(() => state.value?.active_role === 'seller');
const application = computed(() => state.value?.seller_application || null);
const applicationStatus = computed(() => (state.value?.can_sell ? 'approved' : application.value?.status || null));
const isPending = computed(() => !isSeller.value && applicationStatus.value === 'pending');
const isRejected = computed(() => !isSeller.value && applicationStatus.value === 'rejected');

const buttonLabel = computed(() => {
    if (switching.value) {
        return 'Switching…';
    }
    if (isPending.value) {
        return 'Application pending';
    }

    return isSeller.value ? 'Start Shopping' : 'Start Selling';
});

const description = computed(() => {
    if (isSeller.value) {
        return 'Shop from other stores using this same account. You can switch back to selling anytime.';
    }
    if (isPending.value) {
        return 'Your seller application is being reviewed. We\'ll email you once an administrator approves it.';
    }
    if (state.value?.can_sell) {
        return 'Your seller account is ready. Switch to your store dashboard anytime — no need to sign in again.';
    }

    return 'Open your own store using this same account. Your personal details and address carry over.';
});

async function loadState() {
    loading.value = true;
    loadError.value = '';

    const { data, error } = await apiRequest(props.client, '/api/account/role');

    if (error) {
        loadError.value = error.message || 'Could not load your account mode.';
    } else {
        state.value = data;
    }

    loading.value = false;
}

async function onSwitchClick() {
    switchError.value = '';

    if (!isSeller.value && !state.value?.can_sell) {
        openModal();

        return;
    }

    switching.value = true;
    const { data, error } = await apiRequest(props.client, '/api/account/role/switch', { method: 'POST' });

    if (error) {
        switching.value = false;
        switchError.value = error.message || 'Could not switch accounts. Please try again.';
        if (error.payload?.data) {
            state.value = error.payload.data;
        }

        return;
    }

    // Refresh the stored session user so its cached role matches the server.
    try {
        await props.client.auth.getUser?.();
    } catch {
        // Non-fatal: the next page load re-reads the profile from the server.
    }
    window.location.href = HOME_BY_ROLE[data.active_role] || '/';
}

// ---------- Become a Seller modal ----------
const showModal = ref(false);
const submitting = ref(false);
const submitted = ref(false);
const formError = ref('');
const replaceId = ref(false);
const form = reactive({ businessName: '', lineOfBusiness: '', idType: '', idFile: null, businessPermit: null });
const errors = reactive({ businessName: '', lineOfBusiness: '', idType: '', idFile: '', businessPermit: '' });
const dragging = reactive({ idFile: false, businessPermit: false });
const fileInputs = reactive({ idFile: null, businessPermit: null });

const idOnFile = computed(() => state.value?.valid_id_on_file || null);
const needsNewId = computed(() => !idOnFile.value || replaceId.value);

function openModal() {
    form.businessName = application.value?.business_name || '';
    form.lineOfBusiness = application.value?.line_of_business || '';
    form.idType = '';
    form.idFile = null;
    form.businessPermit = null;
    replaceId.value = false;
    submitted.value = false;
    formError.value = '';
    Object.keys(errors).forEach((key) => (errors[key] = ''));
    showModal.value = true;
}

function closeModal() {
    if (!submitting.value) {
        showModal.value = false;
    }
}

function validateFile(file) {
    if (!file) {
        return 'Please upload a file.';
    }
    if (!ACCEPTED_TYPES.includes(file.type)) {
        return 'Only JPG, PNG or PDF files are allowed.';
    }
    if (file.size > MAX_FILE_BYTES) {
        return 'File must be 10 MB or smaller.';
    }

    return '';
}

const validators = {
    businessName: () => (form.businessName.trim() ? '' : 'Business name is required.'),
    lineOfBusiness: () => (LINES_OF_BUSINESS.includes(form.lineOfBusiness) ? '' : 'Please select a line of business.'),
    idType: () => (!needsNewId.value || ID_TYPES.includes(form.idType) ? '' : 'Please select an ID type.'),
    idFile: () => (needsNewId.value ? validateFile(form.idFile) : ''),
    businessPermit: () => validateFile(form.businessPermit),
};

function validateField(field) {
    errors[field] = validators[field]();

    return !errors[field];
}

function validateAll() {
    return Object.keys(validators).map(validateField).every(Boolean);
}

function setFile(field, file) {
    form[field] = file || null;
    if (file) {
        validateField(field);
    } else {
        errors[field] = '';
    }
}

function onFileChange(field, event) {
    setFile(field, event.target.files?.[0]);
    event.target.value = '';
}

function onFileDrop(field, event) {
    dragging[field] = false;
    setFile(field, event.dataTransfer.files?.[0]);
}

function formatSize(bytes) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function cancelReplaceId() {
    replaceId.value = false;
    form.idType = '';
    form.idFile = null;
    errors.idType = '';
    errors.idFile = '';
}

const SERVER_FIELDS = {
    business_name: 'businessName',
    line_of_business: 'lineOfBusiness',
    id_type: 'idType',
    id_file: 'idFile',
    business_permit: 'businessPermit',
};

async function submitApplication() {
    formError.value = '';

    if (!validateAll()) {
        formError.value = 'Please fix the highlighted fields.';

        return;
    }

    submitting.value = true;

    try {
        const { data: sessionData } = await props.client.auth.getSession();
        const token = sessionData.session?.access_token;

        if (!token) {
            throw new Error('Your session has expired. Please sign in again.');
        }

        const body = new FormData();
        body.append('business_name', form.businessName.trim());
        body.append('line_of_business', form.lineOfBusiness);
        body.append('business_permit', form.businessPermit);
        if (needsNewId.value) {
            body.append('id_type', form.idType);
            body.append('id_file', form.idFile);
        }

        const response = await fetch('/api/account/role/seller-application', {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            body,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            Object.entries(payload.errors || {}).forEach(([key, messages]) => {
                if (SERVER_FIELDS[key]) {
                    errors[SERVER_FIELDS[key]] = messages[0];
                }
            });
            throw new Error(
                payload.errors ? 'Please fix the highlighted fields.' : payload.message || 'Could not submit your application.',
            );
        }

        state.value = payload.data;
        submitted.value = true;
    } catch (error) {
        formError.value = error.message;
    } finally {
        submitting.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape' && showModal.value) {
        closeModal();
    }
}

watch(showModal, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
});

onMounted(() => {
    loadState();
    window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '';
}
</script>

<template>
    <section class="sa-card" aria-labelledby="sa-title">
        <div class="sa-head">
            <span class="sa-icon" aria-hidden="true">
                <!-- store / bag -->
                <svg v-if="isSeller" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>
            </span>
            <div class="sa-head-text">
                <span class="sa-eyebrow">Account mode</span>
                <h2 id="sa-title" class="sa-title">Switch Account</h2>
            </div>
            <span v-if="state" class="sa-mode-pill">
                <span class="sa-dot"></span>{{ isSeller ? 'Selling' : 'Shopping' }}
            </span>
        </div>

        <template v-if="loading">
            <div class="sa-skeleton sa-skeleton-line"></div>
            <div class="sa-skeleton sa-skeleton-button"></div>
        </template>

        <div v-else-if="loadError" class="sa-alert sa-alert-error" role="alert">
            <span>{{ loadError }}</span>
            <button type="button" class="sa-link" @click="loadState">Retry</button>
        </div>

        <template v-else>
            <p class="sa-desc">{{ description }}</p>

            <div v-if="isPending" class="sa-alert sa-alert-info">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                <span>
                    <strong>{{ application.business_name }}</strong> · Submitted {{ formatDate(application.applied_at) }}
                </span>
            </div>

            <div v-if="isRejected" class="sa-alert sa-alert-error" role="status">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                <span>
                    Your previous application wasn't approved<template v-if="application.reason">: {{ application.reason }}</template>.
                    You can update it and apply again.
                </span>
            </div>

            <p v-if="switchError" class="sa-error" role="alert">{{ switchError }}</p>

            <button
                type="button"
                class="sa-button"
                :disabled="switching || isPending"
                :aria-busy="switching"
                @click="onSwitchClick"
            >
                <span v-if="switching" class="sa-spinner" aria-hidden="true"></span>
                <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/></svg>
                {{ buttonLabel }}
            </button>
        </template>
    </section>

    <Teleport to="body">
        <Transition name="sa-fade">
            <div v-if="showModal" class="sa-overlay" @click.self="closeModal">
                <div class="sa-modal" role="dialog" aria-modal="true" aria-labelledby="sa-modal-title">
                    <!-- Success -->
                    <div v-if="submitted" class="sa-success">
                        <span class="sa-success-icon" aria-hidden="true">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                        </span>
                        <h3 id="sa-modal-title">Application submitted</h3>
                        <p>
                            An administrator will review your business details and documents. We'll email you at
                            <strong>{{ email }}</strong> once you're approved — then “Start Selling” switches you instantly.
                        </p>
                        <button type="button" class="sa-button sa-button-block" @click="closeModal">Got it</button>
                    </div>

                    <form v-else novalidate @submit.prevent="submitApplication">
                        <header class="sa-modal-head">
                            <div>
                                <h3 id="sa-modal-title">Start selling on BuyTheWay</h3>
                                <p>Just add your business info — everything else comes from your account.</p>
                            </div>
                            <button type="button" class="sa-close" aria-label="Close" :disabled="submitting" @click="closeModal">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                            </button>
                        </header>

                        <div class="sa-modal-body">
                            <div class="sa-identity">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                <div>
                                    <span class="sa-identity-label">Using your existing account</span>
                                    <span class="sa-identity-value">{{ fullName }}<template v-if="email"> · {{ email }}</template></span>
                                </div>
                            </div>

                            <fieldset class="sa-fieldset">
                                <legend>Business information</legend>

                                <div class="sa-field">
                                    <label for="sa-business-name" class="sa-label">Business Name <span class="sa-req">*</span></label>
                                    <input
                                        id="sa-business-name"
                                        v-model="form.businessName"
                                        class="sa-input"
                                        :class="{ 'sa-input-error': errors.businessName }"
                                        placeholder="My Store"
                                        maxlength="255"
                                        autocomplete="organization"
                                        @blur="validateField('businessName')"
                                    />
                                    <p v-if="errors.businessName" class="sa-field-error">{{ errors.businessName }}</p>
                                </div>

                                <div class="sa-field">
                                    <label for="sa-line" class="sa-label">Line of Business <span class="sa-req">*</span></label>
                                    <select
                                        id="sa-line"
                                        v-model="form.lineOfBusiness"
                                        class="sa-input"
                                        :class="{ 'sa-input-error': errors.lineOfBusiness }"
                                        @change="validateField('lineOfBusiness')"
                                    >
                                        <option value="">Select Line of Business</option>
                                        <option v-for="line in LINES_OF_BUSINESS" :key="line" :value="line">{{ line }}</option>
                                    </select>
                                    <p v-if="errors.lineOfBusiness" class="sa-field-error">{{ errors.lineOfBusiness }}</p>
                                </div>
                            </fieldset>

                            <fieldset class="sa-fieldset">
                                <legend>Verification documents</legend>

                                <!-- Valid ID: reuse the one on file unless replacing -->
                                <div v-if="!needsNewId" class="sa-onfile">
                                    <span class="sa-file-icon" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M16 10h2"/><path d="M16 14h2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.5-1.5 1.7-2 3-2s2.5.5 3 2"/></svg>
                                    </span>
                                    <div class="sa-onfile-text">
                                        <span class="sa-onfile-title">Valid ID <span class="sa-badge">On file</span></span>
                                        <span class="sa-onfile-sub">{{ idOnFile.id_type || 'Uploaded at registration' }}</span>
                                    </div>
                                    <button type="button" class="sa-link" @click="replaceId = true">Replace</button>
                                </div>

                                <template v-else>
                                    <div class="sa-field">
                                        <div class="sa-label-row">
                                            <label for="sa-id-type" class="sa-label">ID Type <span class="sa-req">*</span></label>
                                            <button v-if="idOnFile" type="button" class="sa-link" @click="cancelReplaceId">Keep ID on file</button>
                                        </div>
                                        <select
                                            id="sa-id-type"
                                            v-model="form.idType"
                                            class="sa-input"
                                            :class="{ 'sa-input-error': errors.idType }"
                                            @change="validateField('idType')"
                                        >
                                            <option value="">Select ID type</option>
                                            <option v-for="type in ID_TYPES" :key="type" :value="type">{{ type }}</option>
                                        </select>
                                        <p v-if="errors.idType" class="sa-field-error">{{ errors.idType }}</p>
                                    </div>
                                </template>

                                <template v-for="field in (needsNewId ? ['idFile', 'businessPermit'] : ['businessPermit'])" :key="field">
                                    <div class="sa-field">
                                        <span class="sa-label">
                                            {{ field === 'idFile' ? 'Upload ID' : 'Upload Business Permit' }} <span class="sa-req">*</span>
                                        </span>
                                        <div
                                            class="sa-dropzone"
                                            :class="{
                                                'sa-dropzone-drag': dragging[field],
                                                'sa-dropzone-filled': form[field],
                                                'sa-dropzone-error': errors[field],
                                            }"
                                            role="button"
                                            tabindex="0"
                                            :aria-label="field === 'idFile' ? 'Upload valid ID' : 'Upload business permit'"
                                            @click="fileInputs[field]?.click()"
                                            @keydown.enter.prevent="fileInputs[field]?.click()"
                                            @keydown.space.prevent="fileInputs[field]?.click()"
                                            @dragenter.prevent="dragging[field] = true"
                                            @dragover.prevent="dragging[field] = true"
                                            @dragleave.prevent="dragging[field] = false"
                                            @drop.prevent="onFileDrop(field, $event)"
                                        >
                                            <input
                                                :ref="(el) => (fileInputs[field] = el)"
                                                type="file"
                                                class="sa-hidden"
                                                accept="image/jpeg,image/png,.pdf"
                                                tabindex="-1"
                                                @change="onFileChange(field, $event)"
                                            />
                                            <div v-if="!form[field]" class="sa-dropzone-empty">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/></svg>
                                                <p><span class="sa-dropzone-link">Click to upload</span> or drag &amp; drop</p>
                                                <p class="sa-dropzone-hint">JPG, PNG or PDF · Max 10MB</p>
                                            </div>
                                            <div v-else class="sa-dropzone-file">
                                                <span class="sa-file-icon" aria-hidden="true">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><path d="M14 2v6h6"/></svg>
                                                </span>
                                                <div class="sa-onfile-text">
                                                    <span class="sa-file-name">{{ form[field].name }}</span>
                                                    <span class="sa-onfile-sub">{{ formatSize(form[field].size) }}</span>
                                                </div>
                                                <button type="button" class="sa-remove" aria-label="Remove file" @click.stop="setFile(field, null)">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                                </button>
                                            </div>
                                        </div>
                                        <p v-if="errors[field]" class="sa-field-error">{{ errors[field] }}</p>
                                    </div>
                                </template>
                            </fieldset>

                            <p class="sa-note">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/></svg>
                                An administrator reviews every seller application. You can keep shopping meanwhile.
                            </p>
                            <p v-if="formError" class="sa-error" role="alert">{{ formError }}</p>
                        </div>

                        <footer class="sa-modal-foot">
                            <button type="button" class="sa-button sa-button-ghost" :disabled="submitting" @click="closeModal">Cancel</button>
                            <button type="submit" class="sa-button" :disabled="submitting" :aria-busy="submitting">
                                <span v-if="submitting" class="sa-spinner" aria-hidden="true"></span>
                                {{ submitting ? 'Submitting…' : 'Submit application' }}
                            </button>
                        </footer>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.sa-card {
    --sa-brand: #0d9488;
    --sa-brand-dark: #0f766e;
    --sa-brand-soft: #f0fdfa;
    --sa-ink: #0f172a;
    --sa-muted: #64748b;
    --sa-border: #e2e8f0;
    --sa-radius: 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    padding: 1.25rem;
    background: #fff;
    border: 1px solid var(--sa-border);
    border-radius: 1rem;
}

.sa-head {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.sa-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 0.7rem;
    background: var(--sa-brand-soft);
    color: var(--sa-brand);
}

.sa-head-text {
    flex: 1;
    min-width: 0;
}

.sa-eyebrow {
    display: block;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--sa-muted);
}

.sa-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--sa-ink);
}

.sa-mode-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.25rem 0.65rem;
    border-radius: 999px;
    background: #f1f5f9;
    font-size: 0.75rem;
    font-weight: 600;
    color: #334155;
}

.sa-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--sa-brand);
}

.sa-desc {
    margin: 0;
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--sa-muted);
}

.sa-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    align-self: flex-start;
    min-height: 44px;
    padding: 0.6rem 1.15rem;
    border: none;
    border-radius: var(--sa-radius, 0.75rem);
    background: var(--sa-brand, #0d9488);
    color: #fff;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, opacity 0.15s ease;
}

.sa-button:hover:not(:disabled) {
    background: var(--sa-brand-dark, #0f766e);
}

.sa-button:focus-visible {
    outline: 3px solid rgba(13, 148, 136, 0.35);
    outline-offset: 2px;
}

.sa-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.sa-button-ghost {
    background: #fff;
    color: #334155;
    border: 1px solid #e2e8f0;
}

.sa-button-ghost:hover:not(:disabled) {
    background: #f8fafc;
}

.sa-button-block {
    align-self: stretch;
    width: 100%;
}

.sa-spinner {
    width: 15px;
    height: 15px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #fff;
    border-radius: 50%;
    animation: sa-spin 0.7s linear infinite;
}

@keyframes sa-spin {
    to {
        transform: rotate(360deg);
    }
}

.sa-alert {
    display: flex;
    align-items: flex-start;
    gap: 0.55rem;
    padding: 0.7rem 0.85rem;
    border-radius: 0.65rem;
    font-size: 0.84rem;
    line-height: 1.45;
}

.sa-alert svg {
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.sa-alert-info {
    background: #fffbeb;
    color: #92400e;
}

.sa-alert-error {
    background: #fef2f2;
    color: #b91c1c;
    justify-content: space-between;
}

.sa-error,
.sa-field-error {
    margin: 0;
    font-size: 0.8rem;
    color: #dc2626;
}

.sa-link {
    padding: 0;
    border: none;
    background: none;
    font-size: 0.82rem;
    font-weight: 600;
    color: #0d9488;
    cursor: pointer;
}

.sa-link:hover {
    text-decoration: underline;
}

.sa-skeleton {
    border-radius: 0.5rem;
    background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 37%, #f1f5f9 63%);
    background-size: 400% 100%;
    animation: sa-shimmer 1.4s ease infinite;
}

.sa-skeleton-line {
    height: 14px;
    width: 85%;
}

.sa-skeleton-button {
    height: 44px;
    width: 150px;
}

@keyframes sa-shimmer {
    0% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0 50%;
    }
}

/* ---------- Modal ---------- */
.sa-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.5);
}

.sa-modal {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 520px;
    max-height: calc(100vh - 2rem);
    overflow: hidden;
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
}

.sa-modal form {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.sa-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.25rem 0.75rem;
}

.sa-modal-head h3,
.sa-success h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
}

.sa-modal-head p {
    margin: 0.25rem 0 0;
    font-size: 0.85rem;
    color: #64748b;
}

.sa-close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 0.6rem;
    background: transparent;
    color: #64748b;
    cursor: pointer;
}

.sa-close:hover {
    background: #f1f5f9;
}

.sa-modal-body {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
    padding: 0.25rem 1.25rem 1.25rem;
    overflow-y: auto;
}

.sa-modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
    padding: 0.9rem 1.25rem;
    border-top: 1px solid #f1f5f9;
}

.sa-identity {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.7rem 0.85rem;
    border-radius: 0.65rem;
    background: #f0fdfa;
    color: #0f766e;
}

.sa-identity div {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.sa-identity-label {
    font-size: 0.72rem;
    font-weight: 600;
}

.sa-identity-value {
    overflow: hidden;
    font-size: 0.85rem;
    color: #0f172a;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sa-fieldset {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    margin: 0;
    padding: 0;
    border: none;
}

.sa-fieldset legend {
    margin-bottom: 0.15rem;
    padding: 0;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.sa-field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.sa-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sa-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}

.sa-req {
    color: #14b8a6;
}

.sa-input {
    width: 100%;
    min-height: 44px;
    padding: 0.6rem 0.85rem;
    font-size: 0.9375rem;
    color: #0f172a;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.sa-input:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.18);
}

.sa-input-error {
    border-color: #ef4444;
}

.sa-hidden {
    display: none;
}

.sa-dropzone {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 104px;
    padding: 1rem;
    border: 1.5px dashed #c8d2db;
    border-radius: 0.75rem;
    background: #f8fafb;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
}

.sa-dropzone * {
    pointer-events: none;
}

.sa-dropzone .sa-remove {
    pointer-events: auto;
}

.sa-dropzone:hover,
.sa-dropzone:focus-visible,
.sa-dropzone-drag {
    border-color: #0d9488;
    background: #f0fdfa;
    outline: none;
}

.sa-dropzone-filled {
    min-height: 0;
    padding: 0.7rem 0.85rem;
    border-style: solid;
    border-color: #e2e8f0;
    background: #fff;
    cursor: default;
}

.sa-dropzone-error {
    border-color: #ef4444;
}

.sa-dropzone-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.25rem;
    color: #94a3b8;
}

.sa-dropzone-empty p {
    margin: 0;
    font-size: 0.82rem;
    color: #64748b;
}

.sa-dropzone-link {
    font-weight: 700;
    color: #0d9488;
}

.sa-dropzone-empty .sa-dropzone-hint {
    font-size: 0.72rem;
    color: #94a3b8;
}

.sa-dropzone-file,
.sa-onfile {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    text-align: left;
}

.sa-onfile {
    padding: 0.7rem 0.85rem;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
}

.sa-file-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 38px;
    height: 38px;
    border-radius: 0.6rem;
    background: #f0fdfa;
    color: #0d9488;
}

.sa-onfile-text {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
}

.sa-onfile-title,
.sa-file-name {
    overflow: hidden;
    font-size: 0.85rem;
    font-weight: 600;
    color: #1e293b;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sa-onfile-sub {
    font-size: 0.75rem;
    color: #64748b;
}

.sa-badge {
    margin-left: 0.35rem;
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    background: #dcfce7;
    font-size: 0.68rem;
    font-weight: 700;
    color: #15803d;
}

.sa-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 50%;
    background: #f1f5f9;
    color: #475569;
    cursor: pointer;
}

.sa-remove:hover {
    background: #fee2e2;
    color: #dc2626;
}

.sa-note {
    display: flex;
    align-items: flex-start;
    gap: 0.45rem;
    margin: 0;
    font-size: 0.78rem;
    color: #64748b;
}

.sa-note svg {
    flex-shrink: 0;
    margin-top: 0.1rem;
}

.sa-success {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    padding: 2rem 1.5rem 1.5rem;
    text-align: center;
}

.sa-success p {
    margin: 0 0 0.5rem;
    font-size: 0.875rem;
    line-height: 1.55;
    color: #64748b;
}

.sa-success-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #dcfce7;
    color: #16a34a;
}

.sa-fade-enter-active,
.sa-fade-leave-active {
    transition: opacity 0.18s ease;
}

.sa-fade-enter-from,
.sa-fade-leave-to {
    opacity: 0;
}

/* Bottom sheet on phones: thumb-reachable actions, full width. */
@media (max-width: 560px) {
    .sa-overlay {
        align-items: flex-end;
        padding: 0;
    }

    .sa-modal {
        max-width: none;
        max-height: 92vh;
        border-radius: 1rem 1rem 0 0;
    }

    .sa-modal-foot .sa-button {
        flex: 1;
    }

    .sa-button {
        align-self: stretch;
    }
}
</style>

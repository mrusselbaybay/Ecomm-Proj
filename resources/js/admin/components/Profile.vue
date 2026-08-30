<!-- resources/js/admin/components/Profile.vue -->
<template>
    <div class="profile-page">
        <!-- Toast message -->
        <transition name="fade">
            <div
                v-if="message"
                class="toast"
                :class="messageType === 'error' ? 'toast-error' : 'toast-success'"
            >
                <span class="icon-wrap" v-html="messageType === 'error' ? icons.alert : icons.check"></span>
                <span>{{ message }}</span>
                <button class="toast-close" @click="message = ''" aria-label="Dismiss message">
                    <span v-html="icons.close"></span>
                </button>
            </div>
        </transition>

        <div v-if="loading && !hasLoadedOnce" class="profile-grid" aria-hidden="true">
            <section class="card section-card profile-summary-card">
                <div class="skeleton skeleton-circle" style="width: 4rem; height: 4rem; flex-shrink: 0"></div>
                <div style="flex: 1">
                    <div class="skeleton skeleton-text" style="width: 40%; height: 1.1rem"></div>
                    <div class="skeleton skeleton-text" style="width: 55%; margin-top: 0.5rem"></div>
                </div>
            </section>

            <section class="card section-card">
                <div class="skeleton skeleton-text" style="width: 35%; height: 0.9rem"></div>
                <div class="field-grid" style="margin-top: 1.1rem">
                    <div v-for="n in 4" :key="n" :class="n === 4 ? 'field-email' : ''">
                        <div class="skeleton skeleton-text" style="width: 40%; height: 0.6rem"></div>
                        <div class="skeleton" style="width: 100%; height: 2.3rem; margin-top: 0.4rem"></div>
                    </div>
                </div>
            </section>

            <section class="card section-card">
                <div class="skeleton skeleton-text" style="width: 30%; height: 0.9rem"></div>
                <div class="field-grid" style="margin-top: 1.1rem">
                    <div v-for="n in 6" :key="n">
                        <div class="skeleton skeleton-text" style="width: 45%; height: 0.6rem"></div>
                        <div class="skeleton" style="width: 100%; height: 2.3rem; margin-top: 0.4rem"></div>
                    </div>
                </div>
            </section>

            <section class="card section-card">
                <div class="skeleton skeleton-text" style="width: 45%; height: 0.9rem"></div>
                <div class="skeleton" style="width: 100%; height: 2.6rem; margin-top: 1rem"></div>
                <div class="skeleton" style="width: 10rem; height: 2.3rem; margin-top: 0.9rem"></div>
            </section>
        </div>

        <div v-else class="profile-grid">
            <!-- ============================================================ -->
            <!-- PROFILE SUMMARY -->
            <!-- ============================================================ -->
            <section class="card section-card profile-summary-card">
                <div class="profile-avatar-wrap">
                    <div
                        class="profile-avatar"
                        :style="profile.avatar_url ? { backgroundImage: `url(${profile.avatar_url})` } : {}"
                    >
                        <span v-if="!profile.avatar_url">{{ initials }}</span>
                    </div>
                    <button
                        type="button"
                        class="profile-avatar-edit"
                        :disabled="uploadingAvatar"
                        aria-label="Change profile picture"
                        @click="triggerAvatarUpload"
                    >
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3.5"/></svg>
                    </button>
                    <input
                        ref="avatarInput"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="profile-avatar-input"
                        tabindex="-1"
                        @change="onAvatarSelected"
                    >
                </div>

                <div class="profile-summary-info">
                    <div class="profile-summary-name-row">
                        <h2>{{ fullName }}</h2>
                        <span class="badge" :class="statusBadgeClass(profile.account_status)">
                            {{ profile.account_status || 'active' }}
                        </span>
                    </div>
                    <p>{{ profile.email }}</p>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- PERSONAL INFORMATION -->
            <!-- ============================================================ -->
            <section class="card section-card">
                <div class="section-title section-title-with-action">
                    <div>
                        <h3>Personal Information</h3>
                        <p class="section-subtitle">Only your name can be changed here.</p>
                    </div>
                    <button v-if="!isEditingPersonal" type="button" class="btn-edit" @click="startEditingPersonal">
                        <span v-html="icons.edit"></span> Edit
                    </button>
                </div>

                <div class="field-grid">
                    <div class="field field-first">
                        <label class="field-label" for="first_name">First Name *</label>
                        <input
                            id="first_name"
                            v-model="form.first_name"
                            class="field-input"
                            :class="{ 'field-invalid': errors.first_name }"
                            :disabled="!isEditingPersonal"
                            @input="validate('first_name')"
                            @blur="validate('first_name')"
                        />
                        <p v-if="errors.first_name" class="field-error">{{ errors.first_name }}</p>
                    </div>

                    <div class="field field-mi">
                        <label class="field-label" for="middle_initial">M.I.</label>
                        <input
                            id="middle_initial"
                            v-model="form.middle_initial"
                            class="field-input"
                            :class="{ 'field-invalid': errors.middle_initial }"
                            :disabled="!isEditingPersonal"
                            maxlength="1"
                            @input="validate('middle_initial')"
                            @blur="validate('middle_initial')"
                        />
                        <p v-if="errors.middle_initial" class="field-error">{{ errors.middle_initial }}</p>
                    </div>

                    <div class="field field-last">
                        <label class="field-label" for="last_name">Last Name *</label>
                        <input
                            id="last_name"
                            v-model="form.last_name"
                            class="field-input"
                            :class="{ 'field-invalid': errors.last_name }"
                            :disabled="!isEditingPersonal"
                            @input="validate('last_name')"
                            @blur="validate('last_name')"
                        />
                        <p v-if="errors.last_name" class="field-error">{{ errors.last_name }}</p>
                    </div>

                    <div class="field field-email">
                        <label class="field-label" for="email">Email Address</label>
                        <div class="readonly-field">
                            <input id="email" class="field-input field-readonly" :value="profile.email" disabled />
                            <span class="lock-icon" v-html="icons.lock" title="Email cannot be changed"></span>
                        </div>
                    </div>
                </div>

                <div v-if="isEditingPersonal" class="section-actions">
                    <button
                        class="btn-sm-gradient px-4 py-2"
                        :disabled="!isDirty || !isFormValid || saving"
                        @click="saveProfile"
                    >
                        <span v-if="saving" class="btn-spinner"></span>
                        {{ saving ? 'Saving...' : 'Save Changes' }}
                    </button>
                    <button class="btn-cancel px-4 py-2" :disabled="saving" @click="cancelEditingPersonal">
                        Cancel
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- HOME ADDRESS -->
            <!-- ============================================================ -->
            <section class="card section-card">
                <div class="section-title section-title-with-action">
                    <div>
                        <h3>Home Address</h3>
                        <p class="section-subtitle">Optional — not used for anything customer-facing.</p>
                    </div>
                    <button v-if="!isEditingAddress" type="button" class="btn-edit" @click="startEditingAddress">
                        <span v-html="icons.edit"></span> Edit
                    </button>
                </div>

                <div class="field-grid">
                    <div class="field">
                        <label class="field-label" for="region_code">Region</label>
                        <select
                            id="region_code"
                            v-model="addressForm.region_code"
                            class="field-input"
                            :disabled="!isEditingAddress"
                            @change="onRegionChange"
                        >
                            <option value="">Select region</option>
                            <option v-for="r in REGION_OPTIONS" :key="r" :value="r">{{ r }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="province_code">Province</label>
                        <select
                            id="province_code"
                            v-model="addressForm.province_code"
                            class="field-input"
                            :disabled="!isEditingAddress || loadingProvinces || !addressForm.region_code"
                            @change="onProvinceChange"
                        >
                            <option value="">
                                {{ loadingProvinces ? 'Loading provinces…' : (addressForm.region_code ? 'Select province' : 'Select a region first') }}
                            </option>
                            <option v-for="p in filteredProvinceOptions" :key="p.code" :value="p.code">{{ p.name }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="municipality_code">Municipality / City</label>
                        <select
                            id="municipality_code"
                            v-model="addressForm.municipality_code"
                            class="field-input"
                            :disabled="!isEditingAddress || loadingMunicipalities || !addressForm.province_code"
                            @change="onMunicipalityChange"
                        >
                            <option value="">{{ loadingMunicipalities ? 'Loading…' : 'Select municipality/city' }}</option>
                            <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">{{ m.name }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="barangay">Barangay</label>
                        <select
                            id="barangay"
                            v-model="addressForm.barangay"
                            class="field-input"
                            :disabled="!isEditingAddress || loadingBarangays || !addressForm.municipality_code"
                        >
                            <option value="">{{ loadingBarangays ? 'Loading…' : 'Select barangay' }}</option>
                            <option v-for="b in barangayOptions" :key="b.code" :value="b.name">{{ b.name }}</option>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field-label" for="street">Street</label>
                        <input id="street" v-model="addressForm.street" class="field-input" :disabled="!isEditingAddress" placeholder="Rizal St." />
                    </div>

                    <div class="field">
                        <label class="field-label" for="house_no">House / Unit No.</label>
                        <input id="house_no" v-model="addressForm.house_no" class="field-input" :disabled="!isEditingAddress" placeholder="123" />
                    </div>
                </div>

                <p v-if="addressApiError" class="field-error">{{ addressApiError }}</p>

                <div v-if="isEditingAddress" class="section-actions">
                    <button class="btn-sm-gradient px-4 py-2" :disabled="savingAddress" @click="saveAddressSection">
                        <span v-if="savingAddress" class="btn-spinner"></span>
                        {{ savingAddress ? 'Saving...' : 'Save Changes' }}
                    </button>
                    <button class="btn-cancel px-4 py-2" :disabled="savingAddress" @click="cancelEditingAddress">
                        Cancel
                    </button>
                </div>
            </section>

            <!-- ============================================================ -->
            <!-- SECURITY & PASSWORD -->
            <!-- ============================================================ -->
            <section class="card section-card section-card-security">
                <div class="section-title">
                    <span class="icon-wrap icon-key" v-html="icons.key"></span>
                    <div>
                        <h3>Security &amp; Password</h3>
                        <p class="section-subtitle">Your password is never shown — only its presence is confirmed below.</p>
                    </div>
                </div>

                <div class="password-display">
                    <span class="password-dots" aria-hidden="true">{{ passwordDots }}</span>
                    <span class="password-note">Password set</span>
                </div>

                <button class="btn-security" @click="openPasswordModal">
                    <span v-html="icons.key"></span> Change Password
                </button>
            </section>

            <!-- ============================================================ -->
            <!-- DANGER ZONE -->
            <!-- ============================================================ -->
            <section class="card section-card danger-zone">
                <div class="section-title">
                    <span class="icon-wrap icon-warning" v-html="icons.warning"></span>
                    <div>
                        <h3 class="danger-title">Danger Zone</h3>
                        <p class="section-subtitle">
                            Deactivating your account is irreversible and immediately ends your admin session.
                        </p>
                    </div>
                </div>

                <button class="btn-danger px-4 py-2" @click="openDeactivateStep1">
                    <span v-html="icons.warning"></span> Deactivate Account
                </button>
            </section>
        </div>

        <!-- ============================================================ -->
        <!-- PASSWORD CHANGE MODAL -->
        <!-- ============================================================ -->
        <div v-if="showPasswordModal" class="modal-overlay" @click.self="closePasswordModal">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Change password">
                <div class="modal-header">
                    <h3>Change Password</h3>
                    <button class="modal-close" aria-label="Close" @click="closePasswordModal">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <!-- Step 1: request code -->
                <template v-if="passwordStep === 'request'">
                    <p class="modal-desc">
                        We'll send a 6-digit verification code to
                        <strong>{{ maskedEmail }}</strong> to confirm it's really you before changing your password.
                    </p>
                    <p v-if="passwordError" class="field-error mb-2">{{ passwordError }}</p>
                    <div class="modal-actions">
                        <button class="btn-outline flex-1 py-2" @click="closePasswordModal">Cancel</button>
                        <button class="btn-sm-gradient flex-1 py-2" :disabled="sendingCode" @click="requestPasswordCode">
                            <span v-if="sendingCode" class="btn-spinner"></span>
                            {{ sendingCode ? 'Sending...' : 'Send Code' }}
                        </button>
                    </div>
                </template>

                <!-- Step 2: enter code -->
                <template v-else-if="passwordStep === 'verify'">
                    <p class="modal-desc">Enter the 6-digit code sent to <strong>{{ maskedEmail }}</strong>.</p>

                    <div class="otp-row">
                        <input
                            v-for="(digit, i) in codeDigits"
                            :key="i"
                            :ref="(el) => (codeInputs[i] = el)"
                            v-model="codeDigits[i]"
                            class="otp-box"
                            :class="{ 'field-invalid': verifyError }"
                            inputmode="numeric"
                            maxlength="1"
                            @input="onCodeInput(i, $event)"
                            @keydown="onCodeKeydown(i, $event)"
                        />
                    </div>

                    <p v-if="verifyError" class="field-error text-center mb-2">{{ verifyError }}</p>

                    <p class="otp-timer">
                        <span v-if="countdown > 0">Code expires in {{ formattedCountdown }}</span>
                        <button v-else class="link-btn" :disabled="sendingCode" @click="resendCode">
                            {{ sendingCode ? 'Sending...' : 'Resend code' }}
                        </button>
                    </p>

                    <div class="modal-actions">
                        <button class="btn-outline flex-1 py-2" @click="passwordStep = 'request'">Back</button>
                        <button
                            class="btn-sm-gradient flex-1 py-2"
                            :disabled="verifyingCode || codeDigits.join('').length !== 6"
                            @click="verifyCode"
                        >
                            <span v-if="verifyingCode" class="btn-spinner"></span>
                            {{ verifyingCode ? 'Verifying...' : 'Verify' }}
                        </button>
                    </div>
                </template>

                <!-- Step 3: set new password -->
                <template v-else-if="passwordStep === 'update'">
                    <p class="modal-desc">Choose a new password for your account.</p>

                    <div class="field mb-3">
                        <label class="field-label" for="new_password">New Password</label>
                        <input
                            id="new_password"
                            v-model="newPassword"
                            type="password"
                            class="field-input"
                            @input="updateError = ''"
                        />
                        <div class="strength-meter">
                            <div class="strength-bar" :class="`strength-${passwordStrength.level}`" :style="{ width: passwordStrength.percent + '%' }"></div>
                        </div>
                        <p class="strength-label" :class="`strength-text-${passwordStrength.level}`">
                            {{ newPassword ? passwordStrength.label : 'Enter a password' }}
                        </p>
                        <ul class="requirements-list">
                            <li :class="{ met: newPassword.length >= 8 }">At least 8 characters</li>
                            <li :class="{ met: /[A-Z]/.test(newPassword) }">One uppercase letter</li>
                            <li :class="{ met: /[0-9]/.test(newPassword) }">One number</li>
                            <li :class="{ met: /[^A-Za-z0-9]/.test(newPassword) }">One special character</li>
                        </ul>
                    </div>

                    <div class="field mb-2">
                        <label class="field-label" for="confirm_password">Confirm Password</label>
                        <input
                            id="confirm_password"
                            v-model="confirmPassword"
                            type="password"
                            class="field-input"
                            :class="{ 'field-invalid': confirmPassword && confirmPassword !== newPassword }"
                            @input="updateError = ''"
                        />
                        <p v-if="confirmPassword && confirmPassword !== newPassword" class="field-error">
                            Passwords do not match.
                        </p>
                    </div>

                    <p v-if="updateError" class="field-error mb-2">{{ updateError }}</p>

                    <div class="modal-actions">
                        <button class="btn-outline flex-1 py-2" @click="closePasswordModal">Cancel</button>
                        <button
                            class="btn-sm-gradient flex-1 py-2"
                            :disabled="!canSubmitNewPassword || updatingPassword"
                            @click="submitNewPassword"
                        >
                            <span v-if="updatingPassword" class="btn-spinner"></span>
                            {{ updatingPassword ? 'Updating...' : 'Update Password' }}
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <AvatarCropper :file="cropFile" @cancel="cancelAvatarCrop" @crop="onAvatarCropped" />

        <!-- ============================================================ -->
        <!-- DEACTIVATION MODAL — STEP 1 -->
        <!-- ============================================================ -->
        <div v-if="showDeactivateStep1" class="modal-overlay" @click.self="closeDeactivateModals">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Deactivate account">
                <div class="modal-header">
                    <h3 class="danger-title">Deactivate your account?</h3>
                    <button class="modal-close" aria-label="Close" @click="closeDeactivateModals">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <div class="danger-callout">
                    <span class="icon-wrap icon-warning" v-html="icons.warning"></span>
                    <div>
                        <p class="modal-desc mb-1"><strong>This action is irreversible.</strong> Deactivating your account will:</p>
                        <ul class="requirements-list danger-list">
                            <li>Immediately end your admin session</li>
                            <li>Remove your administrative privileges</li>
                            <li>Potentially result in loss of data associated with your account</li>
                        </ul>
                    </div>
                </div>

                <label class="field-label" for="confirm_phrase">
                    Type <strong>DEACTIVATE</strong> to confirm you understand
                </label>
                <input
                    id="confirm_phrase"
                    v-model="confirmPhrase"
                    class="field-input mt-1"
                    autocomplete="off"
                    placeholder="DEACTIVATE"
                />

                <div class="modal-actions">
                    <button class="btn-outline flex-1 py-2" @click="closeDeactivateModals">Cancel</button>
                    <button
                        class="btn-danger flex-1 py-2"
                        :disabled="confirmPhrase !== 'DEACTIVATE'"
                        @click="openDeactivateStep2"
                    >
                        Continue
                    </button>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- DEACTIVATION MODAL — STEP 2 (final) -->
        <!-- ============================================================ -->
        <div v-if="showDeactivateStep2" class="modal-overlay" @click.self="closeDeactivateModals">
            <div class="modal-panel" role="dialog" aria-modal="true" aria-label="Confirm deactivation">
                <div class="modal-header">
                    <h3 class="danger-title">Last step — this cannot be undone</h3>
                    <button class="modal-close" aria-label="Close" @click="closeDeactivateModals">
                        <span v-html="icons.close"></span>
                    </button>
                </div>

                <p class="modal-desc">
                    Enter your current password to permanently deactivate your account.
                </p>

                <div class="field mb-2">
                    <label class="field-label" for="deactivate_password">Current Password</label>
                    <input
                        id="deactivate_password"
                        v-model="deactivatePassword"
                        type="password"
                        class="field-input"
                        @input="deactivateError = ''"
                    />
                </div>

                <p v-if="deactivateError" class="field-error mb-2">{{ deactivateError }}</p>

                <div class="modal-actions">
                    <button class="btn-outline flex-1 py-2" @click="closeDeactivateModals">Cancel</button>
                    <button
                        class="btn-danger flex-1 py-2"
                        :disabled="!deactivatePassword || deactivateCountdown > 0 || deactivating"
                        @click="confirmDeactivate"
                    >
                        <span v-if="deactivating" class="btn-spinner"></span>
                        {{ deactivating ? 'Deactivating...' : deactivateCountdown > 0 ? `Confirm (${deactivateCountdown}s)` : 'Deactivate My Account' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onActivated, onBeforeUnmount, nextTick } from 'vue';
import { useAdmin } from '../composables/useAdmin';
import AvatarCropper from './AvatarCropper.vue';

const { adminFetch, adminProfile, confirmLogout, statusBadgeClass } = useAdmin();
const PSGC_BASE = '/api/psgc';

// ------------------------------------------------------------
// Icons (inline SVG, matching AdminLayout.vue's convention)
// ------------------------------------------------------------
const icons = {
    lock: `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>`,
    key: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>`,
    warning: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    check: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>`,
    close: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>`,
    alert: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
    edit: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>`,
};

// ------------------------------------------------------------
// Profile load + edit
// ------------------------------------------------------------
const loading = ref(true);
// This component stays kept-alive across tab switches, so onActivated below
// reruns loadProfile() on every revisit, not just the first. Without this
// flag, the full-page skeleton would wipe out a profile that's already
// loaded and on screen just because a background refresh set "loading"
// true again — gating it on "loading && !hasLoadedOnce" instead lets that
// refresh update the page in place once it resolves.
const hasLoadedOnce = ref(false);
const saving = ref(false);
const message = ref('');
const messageType = ref('success');

const profile = reactive({
    first_name: '',
    middle_initial: '',
    last_name: '',
    email: '',
    role: '',
    account_status: '',
    avatar_url: null,
});

const fullName = computed(() => {
    const mi = profile.middle_initial ? `${profile.middle_initial}. ` : '';

    return `${profile.first_name || ''} ${mi}${profile.last_name || ''}`.trim() || 'Admin';
});

const initials = computed(() => {
    const f = profile.first_name?.[0] || '';
    const l = profile.last_name?.[0] || '';

    return (f + l).toUpperCase() || 'AD';
});

const form = reactive({ first_name: '', middle_initial: '', last_name: '' });
const errors = reactive({ first_name: '', middle_initial: '', last_name: '' });
const isEditingPersonal = ref(false);

function startEditingPersonal() {
    isEditingPersonal.value = true;
}

function cancelEditingPersonal() {
    resetForm();
    isEditingPersonal.value = false;
}

function showMessage(text, type = 'success') {
    message.value = text;
    messageType.value = type;

    if (type === 'success') {
        setTimeout(() => {
            if (message.value === text) {
message.value = '';
}
        }, 4000);
    }
}

function syncFormFromProfile() {
    form.first_name = profile.first_name || '';
    form.middle_initial = profile.middle_initial || '';
    form.last_name = profile.last_name || '';
}

async function loadProfile() {
    loading.value = true;

    try {
        const response = await adminFetch('/api/admin/profile');
        const data = await response.json();
        Object.assign(profile, data.profile);
        syncFormFromProfile();
        hydrateAddressFromServer(data.address);
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        loading.value = false;
        hasLoadedOnce.value = true;
    }
}

// ------------------------------------------------------------
// Profile picture
// ------------------------------------------------------------
const avatarInput = ref(null);
const uploadingAvatar = ref(false);
const cropFile = ref(null); // the just-picked File, shown in AvatarCropper until cropped or cancelled

function triggerAvatarUpload() {
    avatarInput.value?.click();
}

function onAvatarSelected(event) {
    const file = event.target.files?.[0];
    event.target.value = '';

    if (file) {
        cropFile.value = file;
    }
}

function cancelAvatarCrop() {
    cropFile.value = null;
}

async function onAvatarCropped(blob) {
    cropFile.value = null;
    uploadingAvatar.value = true;

    try {
        const formData = new FormData();
        // `blob` is always the cropped JPEG AvatarCropper.vue exports —
        // it has no filename of its own (unlike a real File), so one is
        // supplied explicitly here.
        formData.append('avatar', blob, 'avatar.jpg');

        const response = await adminFetch('/api/admin/profile/avatar', {
            method: 'POST',
            body: formData,
        });
        const data = await response.json();
        profile.avatar_url = data.avatar_url;
        // Keep the sidebar's mini profile picture (AdminLayout.vue, driven
        // by useAdmin's shared adminProfile) in sync immediately — otherwise
        // it would keep showing the old avatar/initials until the next full
        // page load re-runs checkAuth().
        adminProfile.value = { ...adminProfile.value, avatar_url: data.avatar_url };
        showMessage('Profile picture updated.');
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        uploadingAvatar.value = false;
    }
}

const NAME_PATTERN = /^\p{L}+$/u;

function validate(field) {
    if (field === 'first_name' || field === 'last_name') {
        const value = form[field].trim();
        const label = field === 'first_name' ? 'first name' : 'last name';

        if (!value) {
            errors[field] = `Please enter your ${label}.`;
        } else if (value.length < 2 || value.length > 50 || !NAME_PATTERN.test(value)) {
            errors[field] = `Please enter a valid ${label} (2-50 letters only).`;
        } else {
            errors[field] = '';
        }
    }

    if (field === 'middle_initial') {
        const value = form.middle_initial.trim();
        errors.middle_initial = value && !/^\p{L}$/u.test(value) ? 'Please enter a single letter.' : '';
    }
}

const isFormValid = computed(() => {
    validate('first_name');
    validate('last_name');
    validate('middle_initial');

    return !errors.first_name && !errors.last_name && !errors.middle_initial;
});

const isDirty = computed(
    () =>
        form.first_name !== (profile.first_name || '') ||
        form.middle_initial !== (profile.middle_initial || '') ||
        form.last_name !== (profile.last_name || ''),
);

function resetForm() {
    syncFormFromProfile();
    errors.first_name = '';
    errors.middle_initial = '';
    errors.last_name = '';
}

async function saveProfile() {
    if (!isFormValid.value || !isDirty.value) {
return;
}

    saving.value = true;

    try {
        const response = await adminFetch('/api/admin/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: form.first_name.trim(),
                middle_initial: form.middle_initial.trim() || null,
                last_name: form.last_name.trim(),
            }),
        });
        const data = await response.json();
        Object.assign(profile, data.profile);
        syncFormFromProfile();
        isEditingPersonal.value = false;
        showMessage('Profile updated successfully.');
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        saving.value = false;
    }
}

// Warn on tab close/reload with unsaved changes.
function handleBeforeUnload(event) {
    if (isDirty.value) {
        event.preventDefault();
        event.returnValue = '';
    }
}

// ------------------------------------------------------------
// Home Address — optional for admins (internal staff, not a delivery
// destination), edited independently of Personal Information above but
// saved through the same PUT /api/admin/profile endpoint (the backend
// only touches the addresses row when address fields are actually sent).
// Region/province/municipality/barangay cascade mirrors the buyer/seller
// account pages' PSGC lookups.
// ------------------------------------------------------------
const isEditingAddress = ref(false);
const savingAddress = ref(false);

function emptyAddressForm() {
    return {
        region_code: '',
        region_name: '',
        province_code: '',
        province_name: '',
        municipality_code: '',
        municipality_name: '',
        barangay: '',
        street: '',
        house_no: '',
    };
}

const addressForm = reactive(emptyAddressForm());
const savedAddressForm = reactive(emptyAddressForm());

function hydrateAddressFromServer(address) {
    const source = address || {};

    for (const key of Object.keys(addressForm)) {
        addressForm[key] = source[key] || '';
        savedAddressForm[key] = source[key] || '';
    }

    seedAddressOptionsFromForm();
}

async function startEditingAddress() {
    isEditingAddress.value = true;

    await fetchProvinces();

    if (addressForm.province_code) {
        await fetchMunicipalities(addressForm.province_code, { preserveSelection: true });
    }

    if (addressForm.municipality_code) {
        await fetchBarangays(addressForm.municipality_code, { preserveSelection: true });
    }
}

function cancelEditingAddress() {
    Object.assign(addressForm, savedAddressForm);
    isEditingAddress.value = false;
}

async function saveAddressSection() {
    savingAddress.value = true;

    try {
        const response = await adminFetch('/api/admin/profile', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                first_name: profile.first_name,
                middle_initial: profile.middle_initial,
                last_name: profile.last_name,
                ...addressForm,
            }),
        });
        const data = await response.json();

        hydrateAddressFromServer(data.address);
        isEditingAddress.value = false;
        showMessage('Address updated successfully.');
    } catch (error) {
        showMessage(error.message, 'error');
    } finally {
        savingAddress.value = false;
    }
}

// Same three-way Luzon/Visayas/Mindanao split already used by the
// logistics company signup form (resources/js/app.js) — not the PSGC
// API's own 17-region breakdown (NCR, CAR, Region I-XIII, BARMM), which
// would be a different, unrelated field.
const REGION_OPTIONS = ['Luzon', 'Visayas', 'Mindanao'];

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

// Every province the PSGC API returns already carries its own
// `islandGroupCode` ('luzon' | 'visayas' | 'mindanao') — filtering the one
// already-fetched full province list client-side avoids a second network
// call per region change.
const filteredProvinceOptions = computed(() => {
    if (!addressForm.region_code) {
        return provinceOptions.value;
    }

    return provinceOptions.value.filter(
        (p) => p.islandGroupCode === addressForm.region_code.toLowerCase(),
    );
});

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

function seedAddressOptionsFromForm() {
    if (addressForm.province_code && !provinceOptions.value.some((p) => p.code === addressForm.province_code)) {
        provinceOptions.value = [
            { code: addressForm.province_code, name: addressForm.province_name || addressForm.province_code },
            ...provinceOptions.value,
        ];
    }

    if (
        addressForm.municipality_code &&
        !municipalityOptions.value.some((m) => m.code === addressForm.municipality_code)
    ) {
        municipalityOptions.value = [
            {
                code: addressForm.municipality_code,
                name: addressForm.municipality_name || addressForm.municipality_code,
            },
            ...municipalityOptions.value,
        ];
    }

    if (addressForm.barangay && !barangayOptions.value.some((b) => b.name === addressForm.barangay)) {
        barangayOptions.value = [{ code: 'current', name: addressForm.barangay }, ...barangayOptions.value];
    }
}


async function fetchProvinces() {
    if (provinceCache.value.length > 0) {
        provinceOptions.value = provinceCache.value;

        return;
    }

    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(`${PSGC_BASE}/provinces/all`);

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const allProvinces = dedupeByCodeOrName(json.data || []);

        provinceCache.value = allProvinces;
        provinceOptions.value = allProvinces;
        seedAddressOptionsFromForm();
    } catch {
        addressApiError.value = 'Could not load provinces. Please try again.';
    } finally {
        loadingProvinces.value = false;
    }
}

async function fetchMunicipalities(provinceCode, { preserveSelection = false } = {}) {
    if (!preserveSelection) {
        municipalityOptions.value = [];
        barangayOptions.value = [];
        addressForm.municipality_code = '';
        addressForm.municipality_name = '';
        addressForm.barangay = '';
    }

    if (!provinceCode) {
        return;
    }

    const cached = municipalityCache.get(provinceCode);

    if (cached) {
        municipalityOptions.value = cached;
        seedAddressOptionsFromForm();

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

        municipalityCache.set(provinceCode, data);
        municipalityOptions.value = data;
        seedAddressOptionsFromForm();
    } catch {
        addressApiError.value = 'Could not load cities/municipalities. Please try again.';
    } finally {
        loadingMunicipalities.value = false;
    }
}

async function fetchBarangays(municipalityCode, { preserveSelection = false } = {}) {
    if (!preserveSelection) {
        barangayOptions.value = [];
        addressForm.barangay = '';
    }

    if (!municipalityCode) {
        return;
    }

    const cached = barangayCache.get(municipalityCode);

    if (cached) {
        barangayOptions.value = cached;
        seedAddressOptionsFromForm();

        return;
    }

    loadingBarangays.value = true;
    addressApiError.value = '';

    try {
        const res = await fetch(`${PSGC_BASE}/barangays?city_municipality_code=${municipalityCode}&limit=500`);

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));

        barangayCache.set(municipalityCode, data);
        barangayOptions.value = data;
        seedAddressOptionsFromForm();
    } catch {
        addressApiError.value = 'Could not load barangays. Please try again.';
    } finally {
        loadingBarangays.value = false;
    }
}

function onRegionChange() {
    // Region and its "name" are the same static string here (Luzon/
    // Visayas/Mindanao) — no separate code/name pair to look up, unlike
    // province/municipality which come from the PSGC API.
    addressForm.region_name = addressForm.region_code;

    addressForm.province_code = '';
    addressForm.province_name = '';
    addressForm.municipality_code = '';
    addressForm.municipality_name = '';
    municipalityOptions.value = [];
    barangayOptions.value = [];
    addressForm.barangay = '';
}

function onProvinceChange() {
    const selected = provinceOptions.value.find((p) => p.code === addressForm.province_code);
    addressForm.province_name = selected?.name || '';
    fetchMunicipalities(addressForm.province_code);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find((m) => m.code === addressForm.municipality_code);
    addressForm.municipality_name = selected?.name || '';
    fetchBarangays(addressForm.municipality_code);
}

// ------------------------------------------------------------
// Password display (fixed placeholder — see note below)
// ------------------------------------------------------------
// The actual password length can never be known here: Supabase stores
// only a hash, never the plaintext or its length, and Laravel never
// touches it either. Showing a fixed number of dots (the same
// convention GitHub/Google use) avoids either leaking real length
// info or fabricating a number that isn't actually derived from
// anything.
const passwordDots = '•'.repeat(12);

// ------------------------------------------------------------
// Password change modal
// ------------------------------------------------------------
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
    const email = profile.email || '';
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
            body: JSON.stringify({ email: profile.email }),
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
            body: JSON.stringify({ email: profile.email }),
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
            body: JSON.stringify({ email: profile.email, code }),
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

const passwordStrength = computed(() => {
    const value = newPassword.value;
    let score = 0;

    if (value.length >= 8) {
score += 1;
}

    if (value.length >= 12) {
score += 1;
}

    if (/[A-Z]/.test(value)) {
score += 1;
}

    if (/[0-9]/.test(value)) {
score += 1;
}

    if (/[^A-Za-z0-9]/.test(value)) {
score += 1;
}

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
        /[^A-Za-z0-9]/.test(newPassword.value),
);

const canSubmitNewPassword = computed(
    () => meetsPasswordRules.value && newPassword.value === confirmPassword.value && confirmPassword.value.length > 0,
);

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
                email: profile.email,
                code: codeDigits.value.join(''),
                password: newPassword.value,
                password_confirmation: confirmPassword.value,
            }),
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
// Danger zone — self-deactivation
// ------------------------------------------------------------
const showDeactivateStep1 = ref(false);
const showDeactivateStep2 = ref(false);
const confirmPhrase = ref('');
const deactivatePassword = ref('');
const deactivateError = ref('');
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
    deactivateError.value = '';
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
    deactivateError.value = '';

    try {
        const response = await adminFetch('/api/admin/account/deactivate', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                confirmation_phrase: confirmPhrase.value,
                password: deactivatePassword.value,
            }),
        });
        await response.json();

        closeDeactivateModals();
        showMessage('Your account has been deactivated. Signing you out...');
        setTimeout(() => confirmLogout(), 1500);
    } catch (error) {
        deactivateError.value = error.message;
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

// This component is kept alive by AdminLayout's <KeepAlive>, so onActivated
// (not onMounted) is what fires both on first visit and every time the
// admin returns to this tab. It's skipped while a personal-info edit is in
// progress so switching tabs mid-edit and coming back doesn't silently
// overwrite unsaved changes with the server copy.
onActivated(() => {
    if (!isEditingPersonal.value) {
        loadProfile();
    }
});

onMounted(() => {
    window.addEventListener('beforeunload', handleBeforeUnload);
    window.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
    window.removeEventListener('keydown', handleEscape);
    clearInterval(countdownTimer);
    clearInterval(deactivateTimer);
});
</script>

<style scoped>
.profile-page {
    max-width: 56rem;
    margin: 0 auto;
}

.profile-grid {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
}

.profile-summary-card {
    display: flex;
    align-items: center;
    gap: 1.25rem;
}

.profile-avatar-wrap {
    position: relative;
    flex-shrink: 0;
}

.profile-avatar {
    width: 4rem;
    height: 4rem;
    border-radius: 50%;
    background: #f0fdfa;
    background-size: cover;
    background-position: center;
    color: #0f766e;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    font-weight: 800;
    overflow: hidden;
}

.profile-avatar-edit {
    position: absolute;
    right: -4px;
    bottom: -4px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
    border-radius: 50%;
    background: #0d9488;
    color: white;
    cursor: pointer;
    transition: background 0.15s ease;
}

.profile-avatar-edit:hover {
    background: #0f766e;
}

.profile-avatar-edit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.profile-avatar-input {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    opacity: 0;
    pointer-events: none;
}

.profile-summary-info {
    min-width: 0;
}

.profile-summary-name-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.profile-summary-name-row h2 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
}

.profile-summary-info p {
    margin: 0.2rem 0 0;
    color: #64748b;
    font-size: 0.82rem;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.55rem;
    border-radius: 9999px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-transform: capitalize;
}

.badge-green {
    background: #dcfce7;
    color: #15803d;
}

.badge-amber {
    background: #fef3c7;
    color: #b45309;
}

.badge-red {
    background: #fee2e2;
    color: #b91c1c;
}

.badge-slate {
    background: #f1f5f9;
    color: #475569;
}

.section-card {
    padding: 1.25rem;
}

.section-title {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    margin-bottom: 1rem;
}

.section-title h3 {
    font-size: 0.9rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.section-title-with-action {
    justify-content: space-between;
    align-items: center;
}

.btn-edit {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 1px solid #99f6e4;
    background: #f0fdfa;
    color: #0f766e;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 0.4rem 0.85rem;
    border-radius: 0.5rem;
    cursor: pointer;
    flex-shrink: 0;
}

.btn-edit:hover {
    background: #ccfbf1;
}

.section-subtitle {
    font-size: 0.75rem;
    color: #64748b;
    margin: 0.15rem 0 0;
}

.icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.icon-key {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: #fef3c7;
    color: #b45309;
}

.icon-warning {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    background: #fee2e2;
    color: #dc2626;
}

.field-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 1.1rem 1.25rem;
}

.field-first {
    grid-column: span 3;
}

.field-mi {
    grid-column: span 1;
}

.field-last {
    grid-column: span 2;
}

.field-email {
    grid-column: span 6;
}

@media (max-width: 640px) {
    .field-grid {
        grid-template-columns: 1fr;
    }
    .field-first,
    .field-mi,
    .field-last,
    .field-email {
        grid-column: 1;
    }
}

.field-label {
    font-size: 0.65rem;
    font-weight: 700;
    color: #64748b;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    display: block;
    margin-bottom: 0.3rem;
}

.field-input {
    width: 100%;
    padding: 0.55rem 0.7rem;
    font-size: 0.85rem;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    background: white;
    transition: border-color 0.15s ease;
}

.field-input:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.field-invalid {
    border-color: #dc2626 !important;
}

.field-invalid:focus {
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12) !important;
}

.field-grid .field-input:disabled {
    background: #f8fafc;
    color: #334155;
    border-color: #e5e7eb;
    cursor: default;
}

.field-readonly {
    background: #f8fafc;
    color: #94a3b8;
    cursor: not-allowed;
    padding-right: 2.2rem;
}

.readonly-field {
    position: relative;
}

.lock-icon {
    position: absolute;
    right: 0.7rem;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    display: flex;
}

.field-error {
    font-size: 0.7rem;
    color: #dc2626;
    margin: 0.3rem 0 0;
}

.section-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 1.1rem;
}

.password-display {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 0.7rem 0.9rem;
    margin-bottom: 1rem;
}

.password-dots {
    font-size: 1.1rem;
    letter-spacing: 0.15rem;
    color: #334155;
}

.password-note {
    font-size: 0.7rem;
    color: #94a3b8;
    margin-left: auto;
}

.btn-security {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid #fde68a;
    background: #fffbeb;
    color: #b45309;
    font-weight: 700;
    font-size: 0.8rem;
    padding: 0.55rem 1.1rem;
    border-radius: 0.5rem;
    cursor: pointer;
}

.btn-security:hover {
    background: #fef3c7;
}

.btn-security svg {
    flex-shrink: 0;
}

.btn-cancel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #fecaca;
    background: #fff5f5;
    color: #b91c1c;
    font-weight: 600;
    font-size: 0.8rem;
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    cursor: pointer;
}

.btn-cancel:hover {
    background: #fee2e2;
}

.btn-cancel:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.danger-zone {
    border-color: #fecaca;
    background: #fff8f8;
}

.danger-title {
    color: #b91c1c;
}

.danger-callout {
    display: flex;
    gap: 0.6rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 0.5rem;
    padding: 0.8rem;
    margin-bottom: 1rem;
}

.danger-list li {
    color: #7f1d1d;
}

/* ---- Modal-local (base modal-overlay/panel/header/actions come from
   the shared resources/css/admin/layout.css) ---- */

.btn-outline,
.btn-danger,
.btn-sm-gradient {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
}

.btn-outline:disabled,
.btn-danger:disabled,
.btn-sm-gradient:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.btn-spinner {
    width: 0.85rem;
    height: 0.85rem;
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

.btn-outline .btn-spinner {
    border: 2px solid rgba(15, 23, 42, 0.2);
    border-top-color: #334155;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.otp-row {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    margin-bottom: 0.75rem;
}

.otp-box {
    width: 2.6rem;
    height: 3rem;
    text-align: center;
    font-size: 1.2rem;
    font-weight: 700;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    color: #0f172a;
}

.otp-box:focus {
    outline: none;
    border-color: #0d9488;
    box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.12);
}

.otp-timer {
    text-align: center;
    font-size: 0.75rem;
    color: #64748b;
    margin-bottom: 0.25rem;
}

.link-btn {
    background: none;
    border: none;
    color: #0d9488;
    font-weight: 700;
    font-size: 0.75rem;
    cursor: pointer;
    padding: 0;
}

.link-btn:disabled {
    color: #94a3b8;
    cursor: not-allowed;
}

.strength-meter {
    height: 0.35rem;
    background: #e2e8f0;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 0.5rem;
}

.strength-bar {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.2s ease, background 0.2s ease;
}

.strength-weak {
    background: #dc2626;
}
.strength-medium {
    background: #f59e0b;
}
.strength-strong {
    background: #16a34a;
}

.strength-label {
    font-size: 0.7rem;
    font-weight: 700;
    margin: 0.3rem 0 0;
}
.strength-text-weak {
    color: #dc2626;
}
.strength-text-medium {
    color: #b45309;
}
.strength-text-strong {
    color: #16a34a;
}

.requirements-list {
    list-style: none;
    padding: 0;
    margin: 0.6rem 0 0;
    font-size: 0.72rem;
    color: #94a3b8;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.25rem;
}

.requirements-list li::before {
    content: '○ ';
}

.requirements-list li.met {
    color: #16a34a;
}

.requirements-list li.met::before {
    content: '● ';
}

.danger-list {
    display: block;
    font-size: 0.78rem;
}

.danger-list li::before {
    content: '— ';
}

/* Toast */
.toast {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.7rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 1rem;
}

.toast-success {
    background: #dcfce7;
    color: #166534;
}

.toast-error {
    background: #fee2e2;
    color: #991b1b;
}

.toast-close {
    margin-left: auto;
    background: none;
    border: none;
    cursor: pointer;
    color: inherit;
    opacity: 0.7;
    display: flex;
}

.toast-close:hover {
    opacity: 1;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>

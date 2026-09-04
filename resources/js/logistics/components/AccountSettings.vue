<!-- resources/js/logistics/components/AccountSettings.vue

     The logistics portal's "Account Settings" tab. Cloned from the seller
     account page (resources/js/seller/components/Profile.vue) and adapted
     to the logistics data model: the editable "Business Details" section
     becomes "Company Details" (backed by public.logistics_companies) and
     carries the three courier-recruitment controls — a description, the
     monthly salary on offer, and the master "currently hiring" switch
     that decides whether the company shows up in the couriers' Find Work
     feed. Talks to Supabase directly under the owner's session + RLS,
     same as the seller page. -->
<template>
    <div class="logistics-page">
        <!-- Floating save-feedback toast -->
        <Transition name="acct-toast-fade">
            <div
                v-if="toastMessage"
                class="acct-toast"
                :class="toastIsError ? 'error' : 'success'"
            >
                <svg
                    v-if="!toastIsError"
                    width="16"
                    height="16"
                    viewBox="0 0 20 20"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path d="m4 10 4 4 8-8" />
                </svg>
                <svg
                    v-else
                    width="16"
                    height="16"
                    viewBox="0 0 20 20"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                >
                    <path d="M10 6v5M10 14h.01" />
                    <circle cx="10" cy="10" r="8" />
                </svg>
                {{ toastMessage }}
            </div>
        </Transition>

        <header class="acct-header">
            <div>
                <h2 class="acct-title">Account Settings</h2>
                <nav class="prep-breadcrumb" style="margin-top: 0.35rem">
                    <span>Company</span>
                    <span>/</span>
                    <span>Settings</span>
                </nav>
            </div>
            <span class="acct-last-updated"
                >Last updated: {{ lastUpdatedLabel }}</span
            >
        </header>

        <div class="acct-content">
            <form id="logistics-profile-form" @submit.prevent="handleSave">
                <!-- ====================================================
                 PERSONAL INFORMATION (the company owner)
                 ==================================================== -->
                <section id="section-profile" class="card acct-section">
                    <div class="acct-section-head">
                        <div class="flex items-center gap-4">
                            <div class="acct-avatar-wrap">
                                <div
                                    class="acct-avatar"
                                    :style="
                                        avatarUrl
                                            ? {
                                                  backgroundImage: `url(${avatarUrl})`,
                                              }
                                            : {}
                                    "
                                >
                                    <span v-if="!avatarUrl">{{
                                        initials
                                    }}</span>
                                </div>
                                <button
                                    type="button"
                                    class="acct-avatar-edit"
                                    :disabled="uploadingAvatar"
                                    aria-label="Change profile picture"
                                    @click="triggerAvatarUpload"
                                >
                                    <svg
                                        width="13"
                                        height="13"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path
                                            d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"
                                        />
                                        <circle cx="12" cy="13" r="3.5" />
                                    </svg>
                                </button>
                                <input
                                    ref="avatarInput"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    class="acct-avatar-input"
                                    tabindex="-1"
                                    @change="onAvatarSelected"
                                />
                            </div>
                            <div>
                                <h3>{{ fullName }}</h3>
                                <p class="acct-section-sub">
                                    The account owner on file for this logistics
                                    company.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span
                                v-if="isApprovedCompany"
                                class="acct-verified-badge"
                            >
                                <svg
                                    width="12"
                                    height="12"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2.5"
                                >
                                    <path d="m4 10 4 4 8-8" />
                                </svg>
                                Verified Partner
                            </span>
                            <span
                                v-else
                                class="badge"
                                :class="statusBadgeClass(company?.status)"
                            >
                                {{ company?.status || 'Pending' }}
                            </span>
                            <button
                                v-if="!isEditing"
                                type="button"
                                class="btn-primary"
                                @click="startEditing"
                            >
                                Edit
                            </button>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="field-label"
                                >Last Name <span class="req">*</span></label
                            >
                            <input
                                v-model="formData.last_name"
                                data-field="last_name"
                                :disabled="!isEditing"
                                @input="onNameInput('last_name')"
                                class="field-input"
                                placeholder="Dela Cruz"
                            />
                            <span
                                v-if="errors.last_name"
                                class="save-msg error"
                                >{{ errors.last_name }}</span
                            >
                        </div>
                        <div>
                            <label class="field-label"
                                >First Name <span class="req">*</span></label
                            >
                            <input
                                v-model="formData.first_name"
                                data-field="first_name"
                                :disabled="!isEditing"
                                @input="onNameInput('first_name')"
                                class="field-input"
                                placeholder="Juan"
                            />
                            <span
                                v-if="errors.first_name"
                                class="save-msg error"
                                >{{ errors.first_name }}</span
                            >
                        </div>
                        <div>
                            <label class="field-label">M.I.</label>
                            <input
                                v-model="formData.middle_initial"
                                maxlength="1"
                                :disabled="!isEditing"
                                @input="onMiddleInitialInput"
                                class="field-input"
                                placeholder="B"
                            />
                        </div>

                        <div>
                            <label class="field-label">Sex</label>
                            <select
                                v-model="formData.sex"
                                :disabled="!isEditing"
                                class="field-input"
                            >
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Birthday</label>
                            <input
                                v-model="formData.birthday"
                                data-field="birthday"
                                type="date"
                                :max="todayStr"
                                :disabled="!isEditing"
                                class="field-input"
                            />
                            <span
                                v-if="errors.birthday"
                                class="save-msg error"
                                >{{ errors.birthday }}</span
                            >
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
                                >Contact No. <span class="req">*</span></label
                            >
                            <input
                                v-model="formData.contact_no"
                                data-field="contact_no"
                                :disabled="!isEditing"
                                @input="onContactInput"
                                class="field-input"
                                placeholder="09XXXXXXXXX"
                                maxlength="11"
                            />
                            <span
                                v-if="errors.contact_no"
                                class="save-msg error"
                                >{{ errors.contact_no }}</span
                            >
                        </div>
                        <div class="full-span">
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
                 COMPANY DETAILS — incl. courier-recruitment controls
                 ==================================================== -->
                <section id="section-company" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Company Details</h3>
                            <p class="acct-section-sub">
                                Shown to couriers browsing for work in the
                                mobile app.
                            </p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="full-span">
                            <label class="field-label"
                                >Company Name <span class="req">*</span></label
                            >
                            <input
                                v-model="formData.company_name"
                                data-field="company_name"
                                :disabled="!isEditing"
                                class="field-input"
                                placeholder="Luzon Express Logistics"
                            />
                            <span
                                v-if="errors.company_name"
                                class="save-msg error"
                                >{{ errors.company_name }}</span
                            >
                        </div>

                        <div>
                            <label class="field-label">Company Email</label>
                            <input
                                v-model="formData.company_email"
                                :disabled="!isEditing"
                                type="email"
                                class="field-input"
                                placeholder="ops@company.com"
                            />
                            <span
                                v-if="errors.company_email"
                                class="save-msg error"
                                >{{ errors.company_email }}</span
                            >
                        </div>
                        <div>
                            <label class="field-label"
                                >Company Contact No.</label
                            >
                            <input
                                v-model="formData.company_contact_no"
                                :disabled="!isEditing"
                                class="field-input"
                                placeholder="09XXXXXXXXX"
                                maxlength="11"
                                @input="onCompanyContactInput"
                            />
                        </div>
                        <div>
                            <label class="field-label">Region</label>
                            <select
                                v-model="formData.region"
                                :disabled="!isEditing"
                                class="field-input"
                                @change="onRegionChange"
                            >
                                <option value="">Select region</option>
                                <option
                                    v-for="r in REGION_OPTIONS"
                                    :key="r"
                                    :value="r"
                                >
                                    {{ r }}
                                </option>
                            </select>
                            <p class="field-hint">
                                Couriers filter the Find Work list by this
                                region.
                            </p>
                        </div>

                        <div class="full-span">
                            <label class="field-label">Description</label>
                            <textarea
                                v-model="formData.description"
                                :disabled="!isEditing"
                                class="field-input"
                                rows="4"
                                maxlength="2000"
                                placeholder="Describe the role, routes, schedule and what you're looking for in a courier…"
                            ></textarea>
                            <p class="field-hint">
                                {{ (formData.description || '').length }}/2000
                                characters. This is the "About this role" text
                                couriers see.
                            </p>
                        </div>

                        <div>
                            <label class="field-label"
                                >Monthly Salary (PHP)</label
                            >
                            <input
                                v-model.number="formData.monthly_salary"
                                data-field="monthly_salary"
                                :disabled="!isEditing"
                                type="number"
                                min="0"
                                step="100"
                                class="field-input"
                                placeholder="18000"
                            />
                            <p class="field-hint">
                                {{ salaryDisplay }}
                            </p>
                            <span
                                v-if="errors.monthly_salary"
                                class="save-msg error"
                                >{{ errors.monthly_salary }}</span
                            >
                        </div>

                        <div class="full-span">
                            <div
                                class="acct-toggle-field"
                                :class="{ disabled: !isEditing }"
                            >
                                <div class="acct-toggle-field-text">
                                    <span class="field-label"
                                        >Currently Hiring</span
                                    >
                                    <p class="field-hint">
                                        While this is off, your company does not
                                        appear in the couriers' Find Work feed.
                                    </p>
                                </div>
                                <label class="acct-switch">
                                    <input
                                        type="checkbox"
                                        v-model="formData.is_hiring"
                                        :disabled="!isEditing"
                                    />
                                    <span class="acct-switch-track"
                                        ><span class="acct-switch-thumb"></span
                                    ></span>
                                    <span class="acct-switch-text">{{
                                        formData.is_hiring
                                            ? 'Visible'
                                            : 'Hidden'
                                    }}</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ====================================================
                 COMPANY ADDRESS
                 ==================================================== -->
                <section id="section-address" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Company Address</h3>
                            <p class="acct-section-sub">
                                Used for pickup/hand-off matching with sellers
                                and riders.
                            </p>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div>
                            <label class="field-label">Province</label>
                            <select
                                v-model="formData.province_code"
                                @change="onProvinceChange"
                                class="field-input"
                                :disabled="
                                    !isEditing ||
                                    loadingProvinces ||
                                    !formData.region
                                "
                            >
                                <option value="">
                                    {{
                                        loadingProvinces
                                            ? 'Loading provinces…'
                                            : formData.region
                                              ? 'Select province'
                                              : 'Select a region first'
                                    }}
                                </option>
                                <option
                                    v-for="p in filteredProvinceOptions"
                                    :key="p.code"
                                    :value="p.code"
                                >
                                    {{ p.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label"
                                >Municipality / City</label
                            >
                            <select
                                v-model="formData.municipality_code"
                                @change="onMunicipalityChange"
                                class="field-input"
                                :disabled="
                                    !isEditing ||
                                    loadingMunicipalities ||
                                    !formData.province_code
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
                            <label class="field-label">Barangay</label>
                            <select
                                v-model="formData.barangay"
                                class="field-input"
                                :disabled="
                                    !isEditing ||
                                    loadingBarangays ||
                                    !formData.municipality_code
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
                                :disabled="!isEditing"
                                class="field-input"
                                placeholder="123"
                            />
                        </div>
                        <div class="full-span">
                            <label class="field-label">Street</label>
                            <input
                                v-model="formData.street"
                                :disabled="!isEditing"
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
             SECURITY & PASSWORD — email verification-code flow, same
             UI + endpoints as the buyer/admin account settings pages
             (POST /api/password/{send-code,verify-code,resend-code,
             reset}). This doubles as "forgot password": identity is
             re-confirmed by a code sent to the account email, not by
             the current password.
             ======================================================== -->
            <section id="section-security" class="card acct-section">
                <div class="acct-section-head">
                    <div>
                        <h3>Security &amp; Password</h3>
                        <p class="acct-section-sub">
                            Your password is never shown — only its presence is
                            confirmed below.
                        </p>
                    </div>
                </div>

                <div class="account-password-display">
                    <span class="account-password-dots" aria-hidden="true">{{
                        passwordDots
                    }}</span>
                    <span class="account-password-note">Password set</span>
                </div>

                <button
                    type="button"
                    class="account-security-button"
                    @click="openPasswordModal"
                >
                    <svg
                        width="15"
                        height="15"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path
                            d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"
                        />
                    </svg>
                    Change Password
                </button>
            </section>

            <!-- ========================================================
             DANGER ZONE — self-deactivation.
             ======================================================== -->
            <section
                id="section-danger"
                class="card acct-section acct-section-danger"
            >
                <div class="acct-section-head">
                    <div>
                        <h3>Danger Zone</h3>
                        <p class="acct-section-sub">
                            Deactivating your account is irreversible and
                            immediately ends your session.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="btn-danger"
                    @click="openDeactivateStep1"
                >
                    Deactivate Account
                </button>
            </section>

            <!-- SAVE / CANCEL — only while editing -->
            <div v-if="isEditing" class="form-actions">
                <span v-if="saveSuccess" class="save-msg success">{{
                    saveSuccess
                }}</span>
                <span v-if="saveError" class="save-msg error">{{
                    saveError
                }}</span>
                <button
                    type="button"
                    class="btn-outline"
                    @click="handleCancelClick"
                    :disabled="savingProfile"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    form="logistics-profile-form"
                    class="btn-primary"
                    :disabled="savingProfile || !isFormDirty"
                    :title="!isFormDirty ? 'No changes to save' : ''"
                >
                    {{ savingProfile ? 'Saving…' : 'Save Changes' }}
                </button>
            </div>
        </div>

        <AvatarCropper
            :file="cropFile"
            @cancel="cancelAvatarCrop"
            @crop="onAvatarCropped"
        />

        <!-- DEACTIVATION MODAL — STEP 1 -->
        <div
            v-if="showDeactivateStep1"
            class="modal-overlay"
            @click.self="closeDeactivateModals"
        >
            <div class="modal-panel">
                <div class="modal-header">
                    <h3 style="color: #b91c1c">Deactivate your account?</h3>
                    <button class="modal-close" @click="closeDeactivateModals">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>
                <p class="modal-desc">
                    <strong>This action is irreversible.</strong> Deactivating
                    your account will immediately end your session and remove
                    access to your logistics dashboard, rider roster, and parcel
                    operations.
                </p>
                <label class="field-label"
                    >Type <strong>DEACTIVATE</strong> to confirm you
                    understand</label
                >
                <input
                    v-model="confirmPhrase"
                    class="field-input"
                    autocomplete="off"
                    placeholder="DEACTIVATE"
                    style="margin-top: 0.35rem"
                />
                <div class="modal-actions">
                    <button
                        class="btn-outline"
                        style="flex: 1"
                        @click="closeDeactivateModals"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn-danger"
                        style="flex: 1"
                        :disabled="confirmPhrase !== 'DEACTIVATE'"
                        @click="openDeactivateStep2"
                    >
                        Continue
                    </button>
                </div>
            </div>
        </div>

        <!-- DEACTIVATION MODAL — STEP 2 -->
        <div
            v-if="showDeactivateStep2"
            class="modal-overlay"
            @click.self="closeDeactivateModals"
        >
            <div class="modal-panel">
                <div class="modal-header">
                    <h3 style="color: #b91c1c">
                        Last step — this cannot be undone
                    </h3>
                    <button class="modal-close" @click="closeDeactivateModals">
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>
                <p class="modal-desc">
                    Enter your current password to permanently deactivate your
                    account.
                </p>
                <label class="field-label">Current Password</label>
                <input
                    v-model="deactivatePassword"
                    type="password"
                    class="field-input"
                    style="margin-top: 0.35rem"
                    @input="deactivateError = ''"
                />
                <p
                    v-if="deactivateError"
                    class="save-msg error"
                    style="margin-top: 0.5rem"
                >
                    {{ deactivateError }}
                </p>
                <div class="modal-actions">
                    <button
                        class="btn-outline"
                        style="flex: 1"
                        @click="closeDeactivateModals"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn-danger"
                        style="flex: 1"
                        :disabled="
                            !deactivatePassword ||
                            deactivateCountdown > 0 ||
                            deactivating
                        "
                        @click="confirmDeactivate"
                    >
                        {{
                            deactivating
                                ? 'Deactivating…'
                                : deactivateCountdown > 0
                                  ? `Confirm (${deactivateCountdown}s)`
                                  : 'Deactivate My Account'
                        }}
                    </button>
                </div>
            </div>
        </div>

        <!-- ========================================================
         CHANGE PASSWORD MODAL — request code / verify / set new
         (buyer + admin account settings UI)
         ======================================================== -->
        <div
            v-if="showPasswordModal"
            class="account-modal-overlay"
            @click.self="closePasswordModal"
        >
            <div
                class="account-modal-panel"
                role="dialog"
                aria-modal="true"
                aria-label="Change password"
            >
                <div class="account-modal-header">
                    <h3>Change Password</h3>
                    <button
                        class="account-modal-close"
                        aria-label="Close"
                        @click="closePasswordModal"
                    >
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 5l10 10M15 5 5 15" />
                        </svg>
                    </button>
                </div>

                <template v-if="passwordStep === 'request'">
                    <p class="account-modal-desc">
                        We'll send a 6-digit verification code to
                        <strong>{{ maskedEmail }}</strong> to confirm it's
                        really you before changing your password.
                    </p>
                    <p
                        v-if="passwordError"
                        class="account-field-error account-mb-2"
                    >
                        {{ passwordError }}
                    </p>
                    <div class="account-modal-actions">
                        <button
                            class="account-btn-outline"
                            @click="closePasswordModal"
                        >
                            Cancel
                        </button>
                        <button
                            class="account-btn-primary"
                            :disabled="sendingCode"
                            @click="requestPasswordCode"
                        >
                            {{ sendingCode ? 'Sending…' : 'Send Code' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="passwordStep === 'verify'">
                    <p class="account-modal-desc">
                        Enter the 6-digit code sent to
                        <strong>{{ maskedEmail }}</strong
                        >.
                    </p>

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
                        />
                    </div>

                    <p
                        v-if="verifyError"
                        class="account-field-error account-text-center account-mb-2"
                    >
                        {{ verifyError }}
                    </p>

                    <p class="account-otp-timer">
                        <span v-if="countdown > 0"
                            >Code expires in {{ formattedCountdown }}</span
                        >
                        <button
                            v-else
                            type="button"
                            class="account-link-button"
                            :disabled="sendingCode"
                            @click="resendCode"
                        >
                            {{ sendingCode ? 'Sending…' : 'Resend code' }}
                        </button>
                    </p>

                    <div class="account-modal-actions">
                        <button
                            class="account-btn-outline"
                            @click="passwordStep = 'request'"
                        >
                            Back
                        </button>
                        <button
                            class="account-btn-primary"
                            :disabled="
                                verifyingCode ||
                                codeDigits.join('').length !== 6
                            "
                            @click="verifyCode"
                        >
                            {{ verifyingCode ? 'Verifying…' : 'Verify' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="passwordStep === 'update'">
                    <p class="account-modal-desc">
                        Choose a new password for your account.
                    </p>

                    <label class="account-field account-mb-3">
                        <span>New Password</span>
                        <input
                            v-model="newPassword"
                            type="password"
                            @input="updateError = ''"
                        />
                        <div class="account-strength-meter">
                            <div
                                class="account-strength-bar"
                                :class="`account-strength-${passwordStrength.level}`"
                                :style="{
                                    width: passwordStrength.percent + '%',
                                }"
                            ></div>
                        </div>
                        <small
                            :class="`account-strength-text-${passwordStrength.level}`"
                        >
                            {{
                                newPassword
                                    ? passwordStrength.label
                                    : 'Enter a password'
                            }}
                        </small>
                        <ul class="account-requirements-list">
                            <li :class="{ met: newPassword.length >= 8 }">
                                At least 8 characters
                            </li>
                            <li :class="{ met: /[A-Z]/.test(newPassword) }">
                                One uppercase letter
                            </li>
                            <li :class="{ met: /[0-9]/.test(newPassword) }">
                                One number
                            </li>
                            <li
                                :class="{
                                    met: /[^A-Za-z0-9]/.test(newPassword),
                                }"
                            >
                                One special character
                            </li>
                        </ul>
                    </label>

                    <label class="account-field account-mb-2">
                        <span>Confirm Password</span>
                        <input
                            v-model="confirmPassword"
                            type="password"
                            :class="{
                                invalid:
                                    confirmPassword &&
                                    confirmPassword !== newPassword,
                            }"
                            @input="updateError = ''"
                        />
                        <small
                            v-if="
                                confirmPassword &&
                                confirmPassword !== newPassword
                            "
                            class="account-field-error"
                        >
                            Passwords do not match.
                        </small>
                    </label>

                    <p
                        v-if="updateError"
                        class="account-field-error account-mb-2"
                    >
                        {{ updateError }}
                    </p>

                    <div class="account-modal-actions">
                        <button
                            class="account-btn-outline"
                            @click="closePasswordModal"
                        >
                            Cancel
                        </button>
                        <button
                            class="account-btn-primary"
                            :disabled="
                                !canSubmitNewPassword || updatingPassword
                            "
                            @click="submitNewPassword"
                        >
                            {{
                                updatingPassword
                                    ? 'Updating…'
                                    : 'Update Password'
                            }}
                        </button>
                    </div>
                </template>
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
    nextTick,
    onMounted,
    onBeforeUnmount,
} from 'vue';
import { getSupabase } from '../composables/useLogistics';
import { useLogisticsProfile } from '../composables/useLogisticsProfile';
import { usePsgc } from '../composables/usePsgc';
import AvatarCropper from './AvatarCropper.vue';

const {
    profile,
    address,
    company,
    savingProfile,
    saveError,
    saveSuccess,
    REGION_OPTIONS,
    fullName,
    initials,
    age,
    avatarUrl,
    loadProfileData,
    saveProfile,
    uploadAvatar,
    statusBadgeClass,
} = useLogisticsProfile();
const {
    fetchProvinces: psgcProvinces,
    fetchMunicipalities: psgcMunicipalities,
    fetchBarangays: psgcBarangays,
} = usePsgc();

const todayStr = new Date().toISOString().split('T')[0];

const isEditing = ref(false);
function startEditing() {
    isEditing.value = true;
}

const errors = reactive({
    last_name: '',
    first_name: '',
    birthday: '',
    contact_no: '',
    company_name: '',
    company_email: '',
    monthly_salary: '',
});

function emptyFormData() {
    return {
        last_name: '',
        first_name: '',
        middle_initial: '',
        sex: '',
        birthday: '',
        contact_no: '',
        company_name: '',
        company_email: '',
        company_contact_no: '',
        region: '',
        description: '',
        monthly_salary: '',
        is_hiring: false,
        province_code: '',
        province_name: '',
        municipality_code: '',
        municipality_name: '',
        barangay: '',
        street: '',
        house_no: '',
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

// The company's `region` (Luzon/Visayas/Mindanao) is the island group we
// filter the PSGC province list by — every PSGC province carries its own
// `islandGroupCode`.
const filteredProvinceOptions = computed(() => {
    if (!formData.region) {
        return provinceOptions.value;
    }

    return provinceOptions.value.filter(
        (p) => p.islandGroupCode === formData.region.toLowerCase(),
    );
});

const salaryDisplay = computed(() => {
    const raw = formData.monthly_salary;

    if (raw === '' || raw === null || raw === undefined) {
        return 'No salary set — couriers see "Salary not disclosed".';
    }

    const n = Number(raw);

    if (Number.isNaN(n)) {
        return '';
    }

    return `${n.toLocaleString('en-PH', {
        style: 'currency',
        currency: 'PHP',
    })} / month`;
});

const isApprovedCompany = computed(
    () => (company.value?.status || '').toLowerCase() === 'approved',
);

const lastUpdatedLabel = computed(() => {
    const raw = company.value?.updated_at || profile.value?.updated_at;

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

const PROFILE_FIELDS = [
    'last_name',
    'first_name',
    'middle_initial',
    'sex',
    'birthday',
    'contact_no',
];
const COMPANY_FIELDS = [
    'company_name',
    'company_email',
    'company_contact_no',
    'region',
    'description',
    'monthly_salary',
    'is_hiring',
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
const hydrated = {
    profile: false,
    company: false,
    address: false,
};

function hasLoadedRecord(value) {
    return value && typeof value === 'object' && Object.keys(value).length > 0;
}

function hydrateFields(source, fields) {
    for (const field of fields) {
        let value = source?.[field] ?? '';

        if (field === 'is_hiring') {
            value = !!source?.[field];
        }

        // The Supabase numeric column comes back as a string
        // ("18000.00"); keep it a Number so the <input type="number">
        // (v-model.number) and the dirty-check don't see a false change.
        if (field === 'monthly_salary') {
            value =
                source?.[field] === null || source?.[field] === undefined
                    ? ''
                    : Number(source[field]);
        }

        formData[field] = value;
        savedFormData[field] = value;
    }
}

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
    company,
    (value) => {
        if (!hydrated.company && hasLoadedRecord(value)) {
            hydrateFields(value, COMPANY_FIELDS);
            hydrated.company = true;
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

        // Some stored addresses have a province/municipality NAME but no
        // PSGC code. Seed a "saved:" stand-in so the <select> can show the
        // current value; handleSave() strips it back out.
        if (!formData.province_code && formData.province_name) {
            formData.province_code = `saved:${formData.province_name}`;
            savedFormData.province_code = formData.province_code;
        }

        if (!formData.municipality_code && formData.municipality_name) {
            formData.municipality_code = `saved:${formData.municipality_name}`;
            savedFormData.municipality_code = formData.municipality_code;
        }

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

const isFormDirty = computed(
    () => JSON.stringify(formData) !== JSON.stringify(savedFormData),
);

function handleCancelClick() {
    if (
        isFormDirty.value &&
        !window.confirm('Discard your unsaved changes to this form?')
    ) {
        return;
    }

    resetForm();
    isEditing.value = false;
}

function resetForm() {
    Object.assign(formData, savedFormData);
    Object.keys(errors).forEach((k) => (errors[k] = ''));
}

// ---------- PSGC address lookups ----------
// Backed by usePsgc(), shared with Couriers.vue. This page used to carry
// its own copy that hit /api/psgc/provinces?limit=200 and, when that came
// back short, fanned out one request per region (~18 round-trips). The
// shared module uses PsgcProxyController::allProvinces, which does that
// fan-out once, server-side, and caches it — and memoises the result here
// so reopening the form costs nothing.
async function fetchProvinces() {
    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
        const provinces = await psgcProvinces();

        // Keep a saved province that isn't in the fetched list (e.g.
        // slightly different casing) so the select doesn't blank out.
        provinceOptions.value =
            formData.province_code &&
            !provinces.some((p) => p.code === formData.province_code)
                ? [
                      {
                          code: formData.province_code,
                          name: formData.province_name,
                      },
                      ...provinces,
                  ]
                : provinces;
    } catch (error) {
        addressApiError.value =
            error.message ||
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

    loadingMunicipalities.value = true;
    addressApiError.value = '';

    try {
        const data = await psgcMunicipalities(provinceCode);

        municipalityOptions.value =
            preserveSelection &&
            formData.municipality_code &&
            !data.some((m) => m.code === formData.municipality_code)
                ? [
                      {
                          code: formData.municipality_code,
                          name: formData.municipality_name,
                      },
                      ...data,
                  ]
                : data;
    } catch (error) {
        addressApiError.value =
            error.message ||
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

    loadingBarangays.value = true;
    addressApiError.value = '';

    try {
        const data = await psgcBarangays(municipalityCode);

        barangayOptions.value =
            preserveSelection &&
            formData.barangay &&
            !data.some((b) => b.name === formData.barangay)
                ? [{ code: 'current', name: formData.barangay }, ...data]
                : data;
    } catch (error) {
        addressApiError.value =
            error.message || 'Could not load barangays. Please try again.';
    } finally {
        loadingBarangays.value = false;
    }
}

function onRegionChange() {
    // Changing the company region invalidates the previously selected
    // province/municipality/barangay.
    formData.province_code = '';
    formData.province_name = '';
    formData.municipality_code = '';
    formData.municipality_name = '';
    municipalityOptions.value = [];
    barangayOptions.value = [];
    formData.barangay = '';
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

// ---------- Input formatting / validation ----------
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
function onCompanyContactInput() {
    formData.company_contact_no = formData.company_contact_no
        .replace(/\D/g, '')
        .slice(0, 11);
}

function validate() {
    let valid = true;

    errors.last_name = formData.last_name.trim()
        ? ''
        : ((valid = false), 'Last name is required');
    errors.first_name = formData.first_name.trim()
        ? ''
        : ((valid = false), 'First name is required');
    errors.company_name = formData.company_name.trim()
        ? ''
        : ((valid = false), 'Company name is required');

    if (!formData.birthday) {
        errors.birthday = '';
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

    if (
        formData.company_email &&
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.company_email)
    ) {
        errors.company_email = 'Enter a valid email address';
        valid = false;
    } else {
        errors.company_email = '';
    }

    if (formData.monthly_salary !== '' && formData.monthly_salary !== null) {
        const n = Number(formData.monthly_salary);

        if (Number.isNaN(n) || n < 0) {
            errors.monthly_salary = 'Enter a valid amount (0 or more)';
            valid = false;
        } else {
            errors.monthly_salary = '';
        }
    } else {
        errors.monthly_salary = '';
    }

    if (!valid) {
        const firstErrorField = [
            'last_name',
            'first_name',
            'company_name',
            'birthday',
            'contact_no',
            'monthly_salary',
        ].find((key) => errors[key]);
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
        Object.assign(savedFormData, formData);
        isEditing.value = false;
    }

    showToast(saveSuccess.value || saveError.value, !saveSuccess.value);
}

// ---------- profile picture ----------
const avatarInput = ref(null);
const uploadingAvatar = ref(false);
const cropFile = ref(null);

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
    const ok = await uploadAvatar(blob);
    uploadingAvatar.value = false;

    showToast(
        ok
            ? 'Profile picture updated.'
            : saveError.value || 'Failed to upload profile picture.',
        !ok,
    );
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

// ---------- Security & Password (email verification-code flow — same
// endpoints and steps as the buyer/admin account settings pages) ----------
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
    const checks = [
        value.length >= 8,
        value.length >= 12,
        /[A-Z]/.test(value),
        /[0-9]/.test(value),
        /[^A-Za-z0-9]/.test(value),
    ];
    const score = checks.filter(Boolean).length;

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
    () =>
        meetsPasswordRules.value &&
        newPassword.value === confirmPassword.value &&
        confirmPassword.value.length > 0,
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
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ email: profile.value?.email }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                data.message || 'Failed to send verification code.',
            );
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
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ email: profile.value?.email }),
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
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ email: profile.value?.email, code }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                data.message || 'The verification code entered is incorrect.',
            );
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
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                email: profile.value?.email,
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
        showToast('Password changed successfully.', false);
    } catch (error) {
        updateError.value = error.message;
    } finally {
        updatingPassword.value = false;
    }
}

// ---------- self-deactivation (Danger Zone) ----------
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
        const supabase = getSupabase();

        const { error: verifyErr } = await supabase.auth.signInWithPassword({
            email: profile.value?.email,
            password: deactivatePassword.value,
        });

        if (verifyErr) {
            throw new Error('Current password is incorrect.');
        }

        const { error: updateErr } = await supabase
            .from('profiles')
            .update({ account_status: 'deactivated' })
            .eq('id', profile.value.id);

        if (updateErr) {
            throw updateErr;
        }

        closeDeactivateModals();
        showToast('Your account has been deactivated. Signing you out…', false);

        setTimeout(async () => {
            await supabase.auth.signOut();
            document.cookie =
                'nexmart_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
            window.location.href = '/login';
        }, 1500);
    } catch (err) {
        deactivateError.value =
            err?.message || 'Deactivation failed. Please try again.';
    } finally {
        deactivating.value = false;
    }
}

function handleEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (showDeactivateStep1.value || showDeactivateStep2.value) {
        closeDeactivateModals();
    } else if (showPasswordModal.value) {
        closePasswordModal();
    }
}

onMounted(() => {
    void loadProfileData();
    void fetchProvinces();
    window.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    clearTimeout(toastTimer);
    clearInterval(deactivateTimer);
    clearInterval(countdownTimer);
    window.removeEventListener('keydown', handleEscape);
});
</script>

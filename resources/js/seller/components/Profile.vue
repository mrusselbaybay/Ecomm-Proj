<!-- resources/js/seller/components/Profile.vue -->
<!--
  Seller Account Settings.

  Real data via useSeller() (Supabase PostgREST + GoTrue) — nothing here
  is mocked. One save pattern: a sticky bar saves the profile form
  (personal + business name + address) as a unit; password and email
  changes are their own self-contained Supabase Auth actions. Line of
  Business is read-only (it's the store's fixed product category — see
  CategoryConfigController + the enforce_seller_product_category
  trigger). Image upload / notification preferences / self-deactivation
  are not backed by the current schema and are intentionally absent.
-->
<template>
    <div class="acct-page">
        <Transition name="acct-toast-fade">
            <div
                v-if="toastMessage"
                class="acct-toast"
                :class="toastIsError ? 'error' : 'success'"
                role="status"
                aria-live="polite"
            >
                <svg v-if="!toastIsError" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="m4 10 4 4 8-8" />
                </svg>
                <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <circle cx="10" cy="10" r="8" />
                    <path d="M10 6v5M10 14h.01" />
                </svg>
                {{ toastMessage }}
            </div>
        </Transition>

        <header class="acct-header">
            <div>
                <h2 class="acct-title">Account Settings</h2>
                <nav class="prep-breadcrumb" aria-label="Breadcrumb" style="margin-top: 0.35rem">
                    <span>My Account</span>
                    <span aria-hidden="true">/</span>
                    <span>Settings</span>
                </nav>
            </div>
            <span class="acct-last-updated">Last updated: {{ lastUpdatedLabel }}</span>
        </header>

        <div class="acct-layout">
            <aside class="acct-nav" aria-label="Settings sections">
                <button
                    v-for="item in settingsNavItems"
                    :key="item.id"
                    type="button"
                    class="acct-nav-btn"
                    :class="{ active: activeSectionId === item.id, danger: item.danger }"
                    :aria-current="activeSectionId === item.id ? 'true' : undefined"
                    @click="scrollToSection(item.id)"
                >
                    <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path :d="item.icon" />
                    </svg>
                    {{ item.label }}
                </button>
            </aside>

            <div class="acct-content">
                <!-- Error summary (failed submit) -->
                <div
                    v-if="showErrorSummary && formErrorList.length"
                    ref="errorSummaryEl"
                    class="acct-error-summary"
                    tabindex="-1"
                    role="alert"
                >
                    <p class="acct-error-summary-title">
                        Please fix the following before saving:
                    </p>
                    <ul>
                        <li v-for="e in formErrorList" :key="e.field">
                            <button type="button" @click="focusField(e.field)">{{ e.message }}</button>
                        </li>
                    </ul>
                </div>

                <form id="profile-form" @submit.prevent="handleSave">
                    <!-- Skeleton while profile + business details load -->
                    <template v-if="showSkeleton">
                        <div v-for="n in 3" :key="n" class="card acct-section acct-skeleton" aria-hidden="true">
                            <div class="acct-skel-line" style="width: 30%; height: 1rem"></div>
                            <div class="acct-skel-line" style="width: 60%"></div>
                            <div class="acct-skel-grid">
                                <div class="acct-skel-line"></div>
                                <div class="acct-skel-line"></div>
                                <div class="acct-skel-line"></div>
                                <div class="acct-skel-line"></div>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <!-- ===== PERSONAL INFORMATION ===== -->
                        <section id="section-profile" class="card acct-section">
                            <div class="acct-section-head">
                                <div class="acct-section-head-main">
                                    <div class="acct-avatar" aria-hidden="true">{{ initials }}</div>
                                    <div>
                                        <h3>Personal Information</h3>
                                        <p class="acct-section-sub">
                                            The details on file for {{ fullName }}.
                                        </p>
                                    </div>
                                </div>
                                <span
                                    v-if="isVerifiedSeller"
                                    class="acct-verified-badge"
                                >
                                    <svg width="12" height="12" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
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

                            <div class="acct-field-grid">
                                <div class="acct-field">
                                    <label class="field-label" for="f-last-name">
                                        Last name <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <input
                                        id="f-last-name"
                                        v-model="formData.last_name"
                                        class="field-input"
                                        autocomplete="family-name"
                                        placeholder="Dela Cruz"
                                        :aria-invalid="errors.last_name ? 'true' : undefined"
                                        :aria-describedby="errors.last_name ? 'f-last-name-err' : undefined"
                                        @input="sanitizeName('last_name')"
                                        @blur="validateField('last_name')"
                                    />
                                    <p v-if="errors.last_name" id="f-last-name-err" class="field-error" role="alert">
                                        {{ errors.last_name }}
                                    </p>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-first-name">
                                        First name <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <input
                                        id="f-first-name"
                                        v-model="formData.first_name"
                                        class="field-input"
                                        autocomplete="given-name"
                                        placeholder="Juan"
                                        :aria-invalid="errors.first_name ? 'true' : undefined"
                                        :aria-describedby="errors.first_name ? 'f-first-name-err' : undefined"
                                        @input="sanitizeName('first_name')"
                                        @blur="validateField('first_name')"
                                    />
                                    <p v-if="errors.first_name" id="f-first-name-err" class="field-error" role="alert">
                                        {{ errors.first_name }}
                                    </p>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-mi">M.I.</label>
                                    <input
                                        id="f-mi"
                                        v-model="formData.middle_initial"
                                        class="field-input"
                                        maxlength="1"
                                        autocomplete="additional-name"
                                        placeholder="B"
                                        @input="sanitizeMiddleInitial"
                                    />
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-sex">
                                        Sex <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <select id="f-sex" v-model="formData.sex" class="field-input">
                                        <option value="">Select</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-birthday">
                                        Birthday <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <input
                                        id="f-birthday"
                                        v-model="formData.birthday"
                                        type="date"
                                        class="field-input"
                                        autocomplete="bday"
                                        :max="todayStr"
                                        :aria-invalid="errors.birthday ? 'true' : undefined"
                                        :aria-describedby="errors.birthday ? 'f-birthday-err' : undefined"
                                        @blur="validateField('birthday')"
                                    />
                                    <p v-if="errors.birthday" id="f-birthday-err" class="field-error" role="alert">
                                        {{ errors.birthday }}
                                    </p>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-age">Age</label>
                                    <div id="f-age" class="field-readonly">
                                        <span>{{ age !== null ? age : '—' }}</span>
                                        <span class="field-readonly-pill">Auto</span>
                                    </div>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-contact">
                                        Contact number <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <input
                                        id="f-contact"
                                        v-model="formData.contact_no"
                                        class="field-input"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        maxlength="11"
                                        placeholder="09XXXXXXXXX"
                                        :aria-invalid="errors.contact_no ? 'true' : undefined"
                                        :aria-describedby="errors.contact_no ? 'f-contact-err' : undefined"
                                        @input="sanitizeContact"
                                        @blur="validateField('contact_no')"
                                    />
                                    <p v-if="errors.contact_no" id="f-contact-err" class="field-error" role="alert">
                                        {{ errors.contact_no }}
                                    </p>
                                </div>

                                <div class="acct-field acct-field-wide">
                                    <label class="field-label" for="f-email">Email</label>
                                    <div id="f-email" class="field-readonly">
                                        <span>{{ sellerEmail || '—' }}</span>
                                        <span class="field-readonly-pill">
                                            <svg width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                                <path d="m4 10 4 4 8-8" />
                                            </svg>
                                            Verified
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        class="acct-disclosure-btn"
                                        :aria-expanded="showEmailForm"
                                        @click="showEmailForm = !showEmailForm"
                                    >
                                        {{ showEmailForm ? 'Cancel email change' : 'Change email address' }}
                                    </button>

                                    <div v-if="showEmailForm" class="acct-inline-form">
                                        <div class="acct-field-grid">
                                            <div class="acct-field">
                                                <label class="field-label" for="f-new-email">New email</label>
                                                <input
                                                    id="f-new-email"
                                                    v-model="newEmail"
                                                    class="field-input"
                                                    type="email"
                                                    autocomplete="email"
                                                    placeholder="you@example.com"
                                                />
                                            </div>
                                            <div class="acct-field">
                                                <label class="field-label" for="f-confirm-email">Confirm new email</label>
                                                <input
                                                    id="f-confirm-email"
                                                    v-model="confirmEmail"
                                                    class="field-input"
                                                    type="email"
                                                    autocomplete="email"
                                                    placeholder="you@example.com"
                                                />
                                            </div>
                                        </div>
                                        <p class="field-hint">
                                            We'll email a confirmation link to the new address. Your
                                            sign-in email only changes after you click it.
                                        </p>
                                        <p v-if="emailMsg.text" class="field-error" :class="{ 'field-note': !emailMsg.error }" role="alert">
                                            {{ emailMsg.text }}
                                        </p>
                                        <button
                                            type="button"
                                            class="btn-outline btn-sm"
                                            style="margin-top: 0.6rem"
                                            :disabled="isChangingEmail || !canChangeEmail"
                                            @click="handleChangeEmail"
                                        >
                                            {{ isChangingEmail ? 'Sending…' : 'Send confirmation link' }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ===== BUSINESS INFORMATION ===== -->
                        <section id="section-business" class="card acct-section">
                            <div class="acct-section-head">
                                <div>
                                    <h3>Business Information</h3>
                                    <p class="acct-section-sub">Shown to buyers on your storefront.</p>
                                </div>
                            </div>

                            <div class="acct-field-grid">
                                <div class="acct-field acct-field-wide">
                                    <label class="field-label" for="f-business-name">
                                        Store / business name <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <input
                                        id="f-business-name"
                                        v-model="formData.business_name"
                                        class="field-input"
                                        autocomplete="organization"
                                        placeholder="My Store"
                                        :aria-invalid="errors.business_name ? 'true' : undefined"
                                        :aria-describedby="errors.business_name ? 'f-business-name-err' : undefined"
                                        @blur="validateField('business_name')"
                                    />
                                    <p v-if="errors.business_name" id="f-business-name-err" class="field-error" role="alert">
                                        {{ errors.business_name }}
                                    </p>
                                </div>

                                <div class="acct-field acct-field-wide">
                                    <label class="field-label" for="f-lob">Line of business</label>
                                    <div id="f-lob" class="field-readonly">
                                        <span>
                                            <svg class="acct-readonly-lock" width="13" height="13" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <rect x="4" y="9" width="12" height="8" rx="1.5" />
                                                <path d="M7 9V6a3 3 0 0 1 6 0v3" />
                                            </svg>
                                            {{ formData.line_of_business || 'Not set' }}
                                        </span>
                                        <span class="field-readonly-pill">Read-only</span>
                                    </div>
                                    <p class="field-hint">
                                        This is your store's product category — it decides which
                                        fields and options your listings use. Contact support to
                                        change it, since existing products are tied to it.
                                    </p>
                                </div>
                            </div>
                        </section>

                        <!-- ===== STORE ADDRESS ===== -->
                        <section id="section-address" class="card acct-section">
                            <div class="acct-section-head">
                                <div>
                                    <h3>Store Address</h3>
                                    <p class="acct-section-sub">
                                        Used for courier pickup and delivery matching.
                                    </p>
                                </div>
                            </div>

                            <div class="acct-field-grid">
                                <div class="acct-field">
                                    <label class="field-label" for="f-province">
                                        Province <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <select
                                        id="f-province"
                                        v-model="formData.province_code"
                                        class="field-input"
                                        :disabled="loadingProvinces"
                                        @change="onProvinceChange"
                                    >
                                        <option value="">
                                            {{ loadingProvinces ? 'Loading provinces…' : 'Select province' }}
                                        </option>
                                        <option v-for="p in provinceOptions" :key="p.code" :value="p.code">
                                            {{ p.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-municipality">
                                        City / municipality <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <select
                                        id="f-municipality"
                                        v-model="formData.municipality_code"
                                        class="field-input"
                                        :disabled="loadingMunicipalities || !formData.province_code"
                                        @change="onMunicipalityChange"
                                    >
                                        <option value="">
                                            {{ loadingMunicipalities ? 'Loading…' : 'Select city/municipality' }}
                                        </option>
                                        <option v-for="m in municipalityOptions" :key="m.code" :value="m.code">
                                            {{ m.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-barangay">
                                        Barangay <span class="field-required" aria-hidden="true">*</span>
                                        <span class="sr-only">required</span>
                                    </label>
                                    <select
                                        id="f-barangay"
                                        v-model="formData.barangay"
                                        class="field-input"
                                        :disabled="loadingBarangays || !formData.municipality_code"
                                    >
                                        <option value="">
                                            {{ loadingBarangays ? 'Loading…' : 'Select barangay' }}
                                        </option>
                                        <option v-for="b in barangayOptions" :key="b.code" :value="b.name">
                                            {{ b.name }}
                                        </option>
                                    </select>
                                </div>

                                <div class="acct-field">
                                    <label class="field-label" for="f-house">House / unit no.</label>
                                    <input
                                        id="f-house"
                                        v-model="formData.house_no"
                                        class="field-input"
                                        autocomplete="address-line2"
                                        placeholder="123"
                                    />
                                </div>

                                <div class="acct-field acct-field-wide">
                                    <label class="field-label" for="f-street">Street / building</label>
                                    <input
                                        id="f-street"
                                        v-model="formData.street"
                                        class="field-input"
                                        autocomplete="address-line1"
                                        placeholder="Rizal St."
                                    />
                                </div>
                            </div>

                            <p v-if="errors.address" class="field-error" role="alert" style="margin-top: 0.6rem">
                                {{ errors.address }}
                            </p>
                            <p v-if="addressApiError" class="field-error" role="alert" style="margin-top: 0.6rem">
                                {{ addressApiError }}
                                <button type="button" class="acct-link-btn" @click="fetchProvinces">Retry</button>
                            </p>
                        </section>
                    </template>
                </form>

                <!-- ===== ACCOUNT SECURITY ===== -->
                <section id="section-security" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Account Security</h3>
                            <p class="acct-section-sub">Password and recent account changes.</p>
                        </div>
                    </div>

                    <h4 class="acct-subheading">Change password</h4>
                    <div class="acct-field-grid acct-field-grid-narrow">
                        <div class="acct-field">
                            <label class="field-label" for="f-current-pw">Current password</label>
                            <div class="acct-password-box">
                                <input
                                    id="f-current-pw"
                                    v-model="currentPassword"
                                    :type="showCurrentPassword ? 'text' : 'password'"
                                    class="field-input"
                                    autocomplete="current-password"
                                    placeholder="••••••••"
                                />
                                <button
                                    type="button"
                                    class="acct-password-toggle"
                                    :aria-label="showCurrentPassword ? 'Hide current password' : 'Show current password'"
                                    @click="showCurrentPassword = !showCurrentPassword"
                                >
                                    <svg v-if="showCurrentPassword" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M3 3l14 14M8.3 8.3a2.5 2.5 0 0 0 3.4 3.4M6.2 5.5C4 6.8 2.4 8.7 1.7 10c1.4 2.6 4.3 5.5 8.3 5.5 1.4 0 2.6-.3 3.7-.9M11.8 4.6c.7.2 1.4.5 2 .9 2 1.3 3.6 3.2 4.3 4.5-.4.7-1 1.6-1.8 2.5" />
                                    </svg>
                                    <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M1.7 10c1.4-2.6 4.3-5.5 8.3-5.5s6.9 2.9 8.3 5.5c-1.4 2.6-4.3 5.5-8.3 5.5S3.1 12.6 1.7 10Z" />
                                        <circle cx="10" cy="10" r="2.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="acct-field">
                            <label class="field-label" for="f-new-pw">New password</label>
                            <div class="acct-password-box">
                                <input
                                    id="f-new-pw"
                                    v-model="newPassword"
                                    :type="showNewPassword ? 'text' : 'password'"
                                    class="field-input"
                                    autocomplete="new-password"
                                    placeholder="••••••••"
                                    aria-describedby="f-new-pw-hint"
                                />
                                <button
                                    type="button"
                                    class="acct-password-toggle"
                                    :aria-label="showNewPassword ? 'Hide new password' : 'Show new password'"
                                    @click="showNewPassword = !showNewPassword"
                                >
                                    <svg v-if="showNewPassword" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M3 3l14 14M8.3 8.3a2.5 2.5 0 0 0 3.4 3.4M6.2 5.5C4 6.8 2.4 8.7 1.7 10c1.4 2.6 4.3 5.5 8.3 5.5 1.4 0 2.6-.3 3.7-.9M11.8 4.6c.7.2 1.4.5 2 .9 2 1.3 3.6 3.2 4.3 4.5-.4.7-1 1.6-1.8 2.5" />
                                    </svg>
                                    <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M1.7 10c1.4-2.6 4.3-5.5 8.3-5.5s6.9 2.9 8.3 5.5c-1.4 2.6-4.3 5.5-8.3 5.5S3.1 12.6 1.7 10Z" />
                                        <circle cx="10" cy="10" r="2.5" />
                                    </svg>
                                </button>
                            </div>
                            <p id="f-new-pw-hint" class="field-hint">
                                At least 8 characters.
                                <span v-if="newPassword" class="acct-pw-strength" :class="passwordStrength.cls">
                                    · {{ passwordStrength.label }}
                                </span>
                            </p>
                        </div>

                        <div class="acct-field">
                            <label class="field-label" for="f-confirm-pw">Confirm new password</label>
                            <div class="acct-password-box">
                                <input
                                    id="f-confirm-pw"
                                    v-model="confirmPassword"
                                    :type="showConfirmPassword ? 'text' : 'password'"
                                    class="field-input"
                                    autocomplete="new-password"
                                    placeholder="••••••••"
                                />
                                <button
                                    type="button"
                                    class="acct-password-toggle"
                                    :aria-label="showConfirmPassword ? 'Hide confirmation' : 'Show confirmation'"
                                    @click="showConfirmPassword = !showConfirmPassword"
                                >
                                    <svg v-if="showConfirmPassword" width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M3 3l14 14M8.3 8.3a2.5 2.5 0 0 0 3.4 3.4M6.2 5.5C4 6.8 2.4 8.7 1.7 10c1.4 2.6 4.3 5.5 8.3 5.5 1.4 0 2.6-.3 3.7-.9M11.8 4.6c.7.2 1.4.5 2 .9 2 1.3 3.6 3.2 4.3 4.5-.4.7-1 1.6-1.8 2.5" />
                                    </svg>
                                    <svg v-else width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path d="M1.7 10c1.4-2.6 4.3-5.5 8.3-5.5s6.9 2.9 8.3 5.5c-1.4 2.6-4.3 5.5-8.3 5.5S3.1 12.6 1.7 10Z" />
                                        <circle cx="10" cy="10" r="2.5" />
                                    </svg>
                                </button>
                            </div>
                            <p v-if="confirmPassword && confirmPassword !== newPassword" class="field-error" role="alert">
                                Passwords do not match.
                            </p>
                        </div>
                    </div>

                    <p v-if="passwordMsg.text" class="field-error" :class="{ 'field-note': !passwordMsg.error }" role="alert" style="margin-top: 0.5rem">
                        {{ passwordMsg.text }}
                    </p>
                    <button
                        type="button"
                        class="btn-outline"
                        style="margin-top: 0.9rem"
                        :disabled="isChangingPassword || !canUpdatePassword"
                        @click="handleChangePassword"
                    >
                        {{ isChangingPassword ? 'Updating…' : 'Update password' }}
                    </button>

                    <h4 class="acct-subheading" style="margin-top: 1.75rem">Recent account activity</h4>
                    <ul v-if="recentActivity.length" class="acct-activity">
                        <li v-for="(row, i) in recentActivity" :key="i" class="acct-activity-row">
                            <span class="acct-activity-dot" aria-hidden="true"></span>
                            <div>
                                <p class="acct-activity-text">
                                    Account status changed to
                                    <strong style="text-transform: capitalize">{{ row.new_status || 'updated' }}</strong>
                                </p>
                                <p class="acct-activity-time">
                                    {{ formatDateTime(row.created_at) }}
                                    <span v-if="row.reason">— {{ row.reason }}</span>
                                </p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="acct-section-sub" style="margin-top: 0.5rem">
                        No recent account changes on record.
                    </p>
                </section>

                <!-- ===== COMPLIANCE DOCUMENTS ===== -->
                <section id="section-documents" class="card acct-section">
                    <div class="acct-section-head">
                        <div>
                            <h3>Compliance Documents</h3>
                            <p class="acct-section-sub">
                                Submitted during registration. Contact support to resubmit.
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
                                    {{ docTypeLabel(doc.doc_type).slice(0, 2).toUpperCase() }}
                                </div>
                                <div>
                                    <p class="doc-type">{{ docTypeLabel(doc.doc_type) }}</p>
                                    <p class="doc-date">Submitted {{ formatDate(doc.created_at) }}</p>
                                </div>
                            </div>
                            <span class="badge" :class="statusBadgeClass(doc.status)">{{ doc.status }}</span>
                        </div>
                    </div>
                </section>

                <!-- ===== DANGER ZONE ===== -->
                <section id="section-danger" class="card acct-section acct-danger">
                    <div class="acct-section-head">
                        <div>
                            <h3>Danger Zone</h3>
                            <p class="acct-section-sub">Session and account controls.</p>
                        </div>
                    </div>

                    <div class="acct-danger-action">
                        <div>
                            <p class="acct-danger-action-title">Log out</p>
                            <p class="acct-section-sub">Sign out of the seller dashboard on this device.</p>
                        </div>
                        <button type="button" class="btn-outline" @click="handleLogout">Log out</button>
                    </div>

                    <div class="acct-danger-action">
                        <div>
                            <p class="acct-danger-action-title">Deactivate or close this store</p>
                            <p class="acct-section-sub">
                                Closing a seller account affects live orders and payouts, so it's
                                handled by NEXMART support — reach out from the Messages page or
                                your registered email.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Sticky save bar — only while the profile form has unsaved edits -->
        <Transition name="acct-bar">
            <div v-if="isFormDirty && !showSkeleton" class="acct-save-bar" role="region" aria-label="Unsaved changes">
                <span class="acct-save-bar-text">
                    <span class="acct-save-bar-dot" aria-hidden="true"></span>
                    You have unsaved changes
                </span>
                <div class="acct-save-bar-actions">
                    <button
                        type="button"
                        class="btn-outline btn-sm"
                        :disabled="savingProfile"
                        @click="handleResetClick"
                    >
                        Discard
                    </button>
                    <button
                        type="submit"
                        form="profile-form"
                        class="btn-primary btn-sm"
                        :disabled="savingProfile"
                    >
                        {{ savingProfile ? 'Saving…' : 'Save changes' }}
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useSeller } from '../composables/useSeller';

const {
    profile,
    address,
    sellerDetails,
    documents,
    activityLog,
    savingProfile,
    saveError,
    saveSuccess,
    hasUnsavedAccountChanges,
    fullName,
    initials,
    sellerEmail,
    age,
    saveProfile,
    changePassword,
    requestEmailChange,
    confirmLogout,
    formatDate,
    formatDateTime,
    docTypeLabel,
    statusBadgeClass,
} = useSeller();

const PSGC_BASE = '/api/psgc';
const todayStr = new Date().toISOString().split('T')[0];

// ---------------------------------------------------------------
// Form model + hydration (unchanged data flow from useSeller)
// ---------------------------------------------------------------
const errors = reactive({
    last_name: '',
    first_name: '',
    birthday: '',
    contact_no: '',
    business_name: '',
    address: '',
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
        sessionStorage.setItem(key, JSON.stringify({ data, savedAt: Date.now() }));
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
const hydrated = { profile: false, address: false, business: false };

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

        // Older addresses may store a province/municipality NAME with no
        // PSGC code. Seed a "saved:" stand-in so the <select> can show
        // the value; handleSave() strips it, picking a real option
        // replaces it.
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
            !provinceOptions.value.some((item) => item.code === formData.province_code)
        ) {
            provinceOptions.value = [
                { code: formData.province_code, name: formData.province_name || formData.province_code },
                ...provinceOptions.value,
            ];
        }

        if (
            formData.municipality_code &&
            !municipalityOptions.value.some((item) => item.code === formData.municipality_code)
        ) {
            municipalityOptions.value = [
                { code: formData.municipality_code, name: formData.municipality_name || formData.municipality_code },
                ...municipalityOptions.value,
            ];
        }

        if (
            formData.barangay &&
            !barangayOptions.value.some((item) => item.name === formData.barangay)
        ) {
            barangayOptions.value = [
                { code: 'current', name: formData.barangay },
                ...barangayOptions.value,
            ];
        }

        const lookups = [];

        if (formData.province_code) {
            lookups.push(fetchMunicipalities(formData.province_code, { preserveSelection: true }));
        }

        if (formData.municipality_code) {
            lookups.push(fetchBarangays(formData.municipality_code, { preserveSelection: true }));
        }

        void Promise.allSettled(lookups);
    },
    { immediate: true, deep: true },
);

// ---------------------------------------------------------------
// Dirty tracking + unsaved-changes guards
// ---------------------------------------------------------------
const isFormDirty = computed(
    () => JSON.stringify(formData) !== JSON.stringify(savedFormData),
);

watch(
    isFormDirty,
    (dirty) => {
        hasUnsavedAccountChanges.value = dirty;
    },
    { immediate: true },
);

function beforeUnloadHandler(e) {
    if (isFormDirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}

function handleResetClick() {
    if (isFormDirty.value && !window.confirm('Discard your unsaved changes to this form?')) {
        return;
    }

    resetForm();
}

function resetForm() {
    Object.assign(formData, savedFormData);
    Object.keys(errors).forEach((k) => (errors[k] = ''));
    showErrorSummary.value = false;
}

// ---------------------------------------------------------------
// PSGC address lookups (logic unchanged)
// ---------------------------------------------------------------
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

    const cachedProvinces = readAddressCache('seller-address:provinces');

    if (cachedProvinces?.length) {
        provinceCache.value = cachedProvinces;
        provinceOptions.value = cachedProvinces;

        return;
    }

    loadingProvinces.value = true;
    addressApiError.value = '';

    try {
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
                        const res = await fetch(`${PSGC_BASE}/provinces?region_code=${r.code}`);

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

async function fetchMunicipalities(provinceCode, { preserveSelection = false } = {}) {
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
    const cachedData = municipalityCache.get(provinceCode) || readAddressCache(cacheKey);

    if (cachedData?.length) {
        municipalityCache.set(provinceCode, cachedData);

        if (
            preserveSelection &&
            formData.municipality_code &&
            !cachedData.some((m) => m.code === formData.municipality_code)
        ) {
            municipalityOptions.value = [
                { code: formData.municipality_code, name: formData.municipality_name },
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
        const res = await fetch(`${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`);

        if (!res.ok) {
            throw new Error('Request failed: ' + res.status);
        }

        const json = await res.json();
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));

        municipalityCache.set(provinceCode, data);
        writeAddressCache(cacheKey, data);

        if (
            preserveSelection &&
            formData.municipality_code &&
            !data.some((m) => m.code === formData.municipality_code)
        ) {
            municipalityOptions.value = [
                { code: formData.municipality_code, name: formData.municipality_name },
                ...data,
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
        formData.barangay = '';
    }

    if (!municipalityCode) {
        return;
    }

    const cacheKey = `seller-address:barangays:${municipalityCode}`;
    const cachedData = barangayCache.get(municipalityCode) || readAddressCache(cacheKey);

    if (cachedData?.length) {
        barangayCache.set(municipalityCode, cachedData);

        if (
            preserveSelection &&
            formData.barangay &&
            !cachedData.some((b) => b.name === formData.barangay)
        ) {
            barangayOptions.value = [{ code: 'current', name: formData.barangay }, ...cachedData];
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
        const data = (json.data || []).slice().sort((a, b) => a.name.localeCompare(b.name));

        barangayCache.set(municipalityCode, data);
        writeAddressCache(cacheKey, data);

        if (
            preserveSelection &&
            formData.barangay &&
            !data.some((b) => b.name === formData.barangay)
        ) {
            barangayOptions.value = [{ code: 'current', name: formData.barangay }, ...data];
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
    const selected = provinceOptions.value.find((p) => p.code === formData.province_code);
    formData.province_name = selected?.name || '';
    fetchMunicipalities(formData.province_code);
}

function onMunicipalityChange() {
    const selected = municipalityOptions.value.find((m) => m.code === formData.municipality_code);
    formData.municipality_name = selected?.name || '';
    fetchBarangays(formData.municipality_code);
}

// ---------------------------------------------------------------
// Input sanitising (on input) + validation (on blur / submit)
// ---------------------------------------------------------------
function sanitizeName(field) {
    formData[field] = formData[field].replace(/[^A-Za-z\s-]/g, '');

    // Clear a visible error as soon as it's no longer valid to show it.
    if (errors[field]) {
        validateField(field);
    }
}
function sanitizeMiddleInitial() {
    formData.middle_initial = formData.middle_initial
        .replace(/[^A-Za-z]/g, '')
        .toUpperCase()
        .slice(0, 1);
}
function sanitizeContact() {
    formData.contact_no = formData.contact_no.replace(/\D/g, '').slice(0, 11);

    if (errors.contact_no) {
        validateField('contact_no');
    }
}

function validateField(field) {
    if (field === 'last_name') {
        errors.last_name = formData.last_name.trim() ? '' : 'Last name is required.';
    } else if (field === 'first_name') {
        errors.first_name = formData.first_name.trim() ? '' : 'First name is required.';
    } else if (field === 'business_name') {
        errors.business_name = formData.business_name.trim() ? '' : 'Store name is required.';
    } else if (field === 'birthday') {
        if (!formData.birthday) {
            errors.birthday = 'Birthday is required.';
        } else if (new Date(formData.birthday) > new Date()) {
            errors.birthday = 'Birthday cannot be a future date.';
        } else {
            errors.birthday = '';
        }
    } else if (field === 'contact_no') {
        errors.contact_no = /^09\d{9}$/.test(formData.contact_no)
            ? ''
            : 'Enter an 11-digit number starting with 09.';
    }
}

function validate() {
    ['last_name', 'first_name', 'business_name', 'birthday', 'contact_no'].forEach(validateField);

    // Address consistency: if a province is chosen, the city and barangay
    // must be too (don't hard-require a full address for legacy partial data).
    errors.address =
        formData.province_code && (!formData.municipality_code || !formData.barangay)
            ? 'Complete the city/municipality and barangay for your store address.'
            : '';

    return !formErrorList.value.length;
}

const FIELD_IDS = {
    last_name: 'f-last-name',
    first_name: 'f-first-name',
    business_name: 'f-business-name',
    birthday: 'f-birthday',
    contact_no: 'f-contact',
    address: 'f-municipality',
};

const formErrorList = computed(() =>
    Object.entries(errors)
        .filter(([, msg]) => !!msg)
        .map(([field, msg]) => ({ field, message: msg })),
);

const showErrorSummary = ref(false);
const errorSummaryEl = ref(null);

function focusField(field) {
    const el = document.getElementById(FIELD_IDS[field] || '');

    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus();
    }
}

// "saved:<name>" codes are display-only — never persist them.
function stripSavedCode(code) {
    return typeof code === 'string' && code.startsWith('saved:') ? '' : code;
}

async function handleSave() {
    if (!validate()) {
        showErrorSummary.value = true;
        nextTick(() => errorSummaryEl.value?.focus());

        return;
    }

    showErrorSummary.value = false;

    await saveProfile({
        ...formData,
        province_code: stripSavedCode(formData.province_code),
        municipality_code: stripSavedCode(formData.municipality_code),
    });

    if (saveSuccess.value) {
        Object.assign(savedFormData, formData);
    }

    showToast(saveSuccess.value || saveError.value, !saveSuccess.value);
}

// ---------------------------------------------------------------
// Toast
// ---------------------------------------------------------------
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

// ---------------------------------------------------------------
// Header / nav / scroll-spy
// ---------------------------------------------------------------
const isVerifiedSeller = computed(() =>
    ['approved', 'active'].includes(profile.value?.status),
);

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
    { id: 'section-profile', label: 'Personal', icon: 'M10 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM4 17c0-3 2.7-5.5 6-5.5s6 2.5 6 5.5' },
    { id: 'section-business', label: 'Business', icon: 'M4 17V7l6-3 6 3v10M8 17v-4h4v4M4 17h12' },
    { id: 'section-address', label: 'Store Address', icon: 'M10 2a6 6 0 0 1 6 6c0 4.2-6 10-6 10s-6-5.8-6-10a6 6 0 0 1 6-6Z M10 10.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z' },
    { id: 'section-security', label: 'Security', icon: 'M10 2 4 4.5v5c0 4 2.5 6.7 6 8 3.5-1.3 6-4 6-8v-5L10 2Z' },
    { id: 'section-documents', label: 'Documents', icon: 'M6 2.5h6l3 3v11a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-13a1 1 0 0 1 1-1z M8 10h4M8 13h4' },
    { id: 'section-danger', label: 'Danger Zone', icon: 'M10 3 2 17h16L10 3ZM10 8v4M10 15h.01', danger: true },
];

function scrollToSection(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

const activeSectionId = ref('section-profile');
let sectionObserver = null;

function setupScrollSpy() {
    sectionObserver?.disconnect();

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

// ---------------------------------------------------------------
// Skeleton (first load only)
// ---------------------------------------------------------------
const showSkeleton = ref(true);

watch(
    [profile, sellerDetails],
    ([p, s]) => {
        if (p && s) {
            showSkeleton.value = false;
        }
    },
    { immediate: true },
);

// The editable sections only exist in the DOM once the skeleton clears —
// (re)attach the scroll-spy observer to them at that point.
watch(showSkeleton, (skel) => {
    if (!skel) {
        nextTick(setupScrollSpy);
    }
});

// ---------------------------------------------------------------
// Security: password change (with current-password re-auth)
// ---------------------------------------------------------------
const currentPassword = ref('');
const newPassword = ref('');
const confirmPassword = ref('');
const showCurrentPassword = ref(false);
const showNewPassword = ref(false);
const showConfirmPassword = ref(false);
const isChangingPassword = ref(false);
const passwordMsg = reactive({ text: '', error: false });

const canUpdatePassword = computed(
    () =>
        currentPassword.value.length > 0 &&
        newPassword.value.length >= 8 &&
        newPassword.value === confirmPassword.value,
);

const passwordStrength = computed(() => {
    const v = newPassword.value;
    let score = 0;

    if (v.length >= 8) {
        score++;
    }

    if (v.length >= 12) {
        score++;
    }

    if (/[A-Z]/.test(v) && /[a-z]/.test(v)) {
        score++;
    }

    if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) {
        score++;
    }

    if (score <= 1) {
        return { label: 'Weak', cls: 'is-weak' };
    }

    if (score === 2) {
        return { label: 'Fair', cls: 'is-fair' };
    }

    return { label: 'Strong', cls: 'is-strong' };
});

async function handleChangePassword() {
    passwordMsg.text = '';
    passwordMsg.error = false;

    if (!canUpdatePassword.value) {
        passwordMsg.text =
            newPassword.value !== confirmPassword.value
                ? 'The new passwords do not match.'
                : 'Enter your current password and a new one of at least 8 characters.';
        passwordMsg.error = true;

        return;
    }

    isChangingPassword.value = true;

    try {
        await changePassword({
            currentPassword: currentPassword.value,
            newPassword: newPassword.value,
        });

        passwordMsg.text = 'Your password has been updated.';
        passwordMsg.error = false;
        currentPassword.value = '';
        newPassword.value = '';
        confirmPassword.value = '';
        showToast(passwordMsg.text, false);
    } catch (err) {
        passwordMsg.text = err?.message || 'Could not update your password.';
        passwordMsg.error = true;
        showToast(passwordMsg.text, true);
    } finally {
        isChangingPassword.value = false;
    }
}

// ---------------------------------------------------------------
// Security: email change (Supabase verification flow)
// ---------------------------------------------------------------
const showEmailForm = ref(false);
const newEmail = ref('');
const confirmEmail = ref('');
const isChangingEmail = ref(false);
const emailMsg = reactive({ text: '', error: false });

const canChangeEmail = computed(() => {
    const e = newEmail.value.trim().toLowerCase();

    return (
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) &&
        e === confirmEmail.value.trim().toLowerCase() &&
        e !== (sellerEmail.value || '').toLowerCase()
    );
});

async function handleChangeEmail() {
    emailMsg.text = '';
    emailMsg.error = false;

    if (!canChangeEmail.value) {
        emailMsg.text =
            newEmail.value.trim().toLowerCase() === (sellerEmail.value || '').toLowerCase()
                ? 'That is already your current email.'
                : 'Enter a valid email address in both fields.';
        emailMsg.error = true;

        return;
    }

    isChangingEmail.value = true;

    try {
        await requestEmailChange(newEmail.value.trim().toLowerCase());
        emailMsg.text = `We've sent a confirmation link to ${newEmail.value.trim()}. Your sign-in email changes only after you confirm it.`;
        emailMsg.error = false;
        newEmail.value = '';
        confirmEmail.value = '';
        showToast('Confirmation link sent.', false);
    } catch (err) {
        emailMsg.text = err?.message || 'Could not start the email change.';
        emailMsg.error = true;
        showToast(emailMsg.text, true);
    } finally {
        isChangingEmail.value = false;
    }
}

// ---------------------------------------------------------------
// Danger zone
// ---------------------------------------------------------------
function handleLogout() {
    if (window.confirm('Log out of the seller dashboard on this device?')) {
        confirmLogout();
    }
}

// ---------------------------------------------------------------
// Recent activity (read-only, already fetched by SellerLayout)
// ---------------------------------------------------------------
const recentActivity = computed(() => (activityLog.value || []).slice(0, 5));

// ---------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------
onMounted(() => {
    setupScrollSpy();
    void fetchProvinces();
    window.addEventListener('beforeunload', beforeUnloadHandler);
    // Safety: never leave the skeleton up forever if a record is missing.
    setTimeout(() => {
        showSkeleton.value = false;
    }, 4000);
});

onBeforeUnmount(() => {
    sectionObserver?.disconnect();
    clearTimeout(toastTimer);
    window.removeEventListener('beforeunload', beforeUnloadHandler);
    hasUnsavedAccountChanges.value = false;
});
</script>

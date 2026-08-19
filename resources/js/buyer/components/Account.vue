<script setup>
import {
    computed,
    reactive,
    ref
} from 'vue';
import { useBuyerAccount } from '../composables/useBuyerAccount';

const emit = defineEmits(['back']);

const {
    buyerProfile,
    buyerFullName,
    buyerInitials,
    buyerAge,
    calculateAge,
    updateBuyerProfile
} = useBuyerAccount();

const isEditing = ref(false);
const errors = ref({});
const successMessage = ref('');

const draft = reactive({
    firstName: '',
    middleInitial: '',
    lastName: '',
    sex: '',
    email: '',
    contactNumber: '',
    birthday: ''
});

const maximumBirthday = computed(() => {
    const today = new Date();
    const year = today.getFullYear();
    const month = String(
        today.getMonth() + 1
    ).padStart(2, '0');
    const day = String(
        today.getDate()
    ).padStart(2, '0');

    return `${year}-${month}-${day}`;
});

const draftAge = computed(() => {
    return calculateAge(draft.birthday);
});

function copyProfileToDraft() {
    draft.firstName = buyerProfile.value.firstName;
    draft.middleInitial =
        buyerProfile.value.middleInitial;
    draft.lastName = buyerProfile.value.lastName;
    draft.sex = buyerProfile.value.sex;
    draft.email = buyerProfile.value.email;
    draft.contactNumber =
        buyerProfile.value.contactNumber;
    draft.birthday = buyerProfile.value.birthday;
}

copyProfileToDraft();

function formatDate(date) {
    if (!date) {
        return 'Not available';
    }

    return new Date(
        `${date}T00:00:00`
    ).toLocaleDateString(undefined, {
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

function startEditing() {
    copyProfileToDraft();
    errors.value = {};
    successMessage.value = '';
    isEditing.value = true;
}

function cancelEditing() {
    copyProfileToDraft();
    errors.value = {};
    successMessage.value = '';
    isEditing.value = false;
}

function validateProfile() {
    const nextErrors = {};

    if (!draft.firstName.trim()) {
        nextErrors.firstName =
            'First name is required.';
    }

    if (!draft.lastName.trim()) {
        nextErrors.lastName =
            'Last name is required.';
    }

    if (
        draft.middleInitial &&
        !/^[A-Za-z]$/.test(
            draft.middleInitial.trim()
        )
    ) {
        nextErrors.middleInitial =
            'Use one letter only.';
    }

    if (!draft.sex) {
        nextErrors.sex = 'Sex is required.';
    }

    if (!draft.email.trim()) {
        nextErrors.email = 'Email is required.';
    } else if (
        !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
            draft.email.trim()
        )
    ) {
        nextErrors.email =
            'Enter a valid email address.';
    }

    const normalizedContact =
        draft.contactNumber.replace(/[\s-]/g, '');

    if (!normalizedContact) {
        nextErrors.contactNumber =
            'Contact number is required.';
    } else if (
        !/^(09\d{9}|\+639\d{9})$/.test(
            normalizedContact
        )
    ) {
        nextErrors.contactNumber =
            'Use 09XXXXXXXXX or +639XXXXXXXXX.';
    }

    if (!draft.birthday) {
        nextErrors.birthday =
            'Birthday is required.';
    } else if (
        draft.birthday > maximumBirthday.value
    ) {
        nextErrors.birthday =
            'Birthday cannot be in the future.';
    } else if (draftAge.value === null) {
        nextErrors.birthday =
            'Enter a valid birthday.';
    }

    errors.value = nextErrors;

    return Object.keys(nextErrors).length === 0;
}

function saveProfile() {
    successMessage.value = '';

    if (!validateProfile()) {
        return;
    }

    const savedProfile = updateBuyerProfile({
        ...draft,
        contactNumber:
            draft.contactNumber.replace(
                /[\s-]/g,
                ''
            )
    });

    if (!savedProfile) {
        return;
    }

    copyProfileToDraft();
    errors.value = {};
    isEditing.value = false;
    successMessage.value =
        'Your profile was updated successfully.';
}
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
        </header>

        <main class="account-content">
            <section class="account-profile-card">
                <div class="account-avatar">
                    {{ buyerInitials }}
                </div>

                <div class="account-profile-summary">
                    <div class="account-name-row">
                        <h2>{{ buyerFullName }}</h2>
                        <span class="account-status-badge">
                            {{ buyerProfile.accountStatus }}
                        </span>
                    </div>
                    <p>{{ buyerProfile.email }}</p>
                    <span>
                        Buyer ID: {{ buyerProfile.buyerId }}
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

            <p
                v-if="successMessage"
                class="account-success-message"
                role="status"
            >
                {{ successMessage }}
            </p>

            <div class="account-page-grid">
                <section class="account-information-card">
                    <div class="account-section-heading">
                        <div>
                            <span>Buyer Profile</span>
                            <h2>Personal Information</h2>
                        </div>
                        <p v-if="isEditing">
                            Fields marked with * are required.
                        </p>
                    </div>

                    <form
                        class="account-form"
                        @submit.prevent="saveProfile"
                    >
                        <div class="account-form-grid">
                            <label class="account-field">
                                <span>First Name *</span>
                                <input
                                    v-model="draft.firstName"
                                    type="text"
                                    autocomplete="given-name"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid: errors.firstName
                                    }"
                                >
                                <small v-if="errors.firstName">
                                    {{ errors.firstName }}
                                </small>
                            </label>

                            <label class="account-field">
                                <span>Middle Initial</span>
                                <input
                                    v-model="draft.middleInitial"
                                    type="text"
                                    maxlength="1"
                                    autocomplete="additional-name"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid:
                                            errors.middleInitial
                                    }"
                                >
                                <small v-if="errors.middleInitial">
                                    {{ errors.middleInitial }}
                                </small>
                            </label>

                            <label class="account-field">
                                <span>Last Name *</span>
                                <input
                                    v-model="draft.lastName"
                                    type="text"
                                    autocomplete="family-name"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid: errors.lastName
                                    }"
                                >
                                <small v-if="errors.lastName">
                                    {{ errors.lastName }}
                                </small>
                            </label>

                            <label class="account-field">
                                <span>Sex *</span>
                                <select
                                    v-model="draft.sex"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid: errors.sex
                                    }"
                                >
                                    <option value="" disabled>
                                        Select sex
                                    </option>
                                    <option value="Male">Male</option>
                                    <option value="Female">
                                        Female
                                    </option>
                                    <option value="Prefer not to say">
                                        Prefer not to say
                                    </option>
                                </select>
                                <small v-if="errors.sex">
                                    {{ errors.sex }}
                                </small>
                            </label>

                            <label class="account-field account-field-wide">
                                <span>Email Address *</span>
                                <input
                                    v-model="draft.email"
                                    type="email"
                                    autocomplete="email"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid: errors.email
                                    }"
                                >
                                <small v-if="errors.email">
                                    {{ errors.email }}
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
                                    :class="{
                                        invalid:
                                            errors.contactNumber
                                    }"
                                >
                                <small v-if="errors.contactNumber">
                                    {{ errors.contactNumber }}
                                </small>
                            </label>

                            <label class="account-field">
                                <span>Birthday *</span>
                                <input
                                    v-model="draft.birthday"
                                    type="date"
                                    autocomplete="bday"
                                    :max="maximumBirthday"
                                    :disabled="!isEditing"
                                    :class="{
                                        invalid: errors.birthday
                                    }"
                                >
                                <small v-if="errors.birthday">
                                    {{ errors.birthday }}
                                </small>
                            </label>

                            <label class="account-field">
                                <span>Age</span>
                                <input
                                    :value="
                                        isEditing
                                            ? (draftAge ?? 'Not available')
                                            : (buyerAge ?? 'Not available')
                                    "
                                    type="text"
                                    disabled
                                >
                                <small class="account-field-note">
                                    Automatically calculated from birthday.
                                </small>
                            </label>
                        </div>

                        <footer
                            v-if="isEditing"
                            class="account-form-actions"
                        >
                            <button
                                type="button"
                                class="account-cancel-button"
                                @click="cancelEditing"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="account-save-button"
                            >
                                Save Changes
                            </button>
                        </footer>
                    </form>
                </section>

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
                            <dd class="account-overview-success">
                                {{ buyerProfile.accountStatus }}
                            </dd>
                        </div>
                        <div>
                            <dt>Email Verification</dt>
                            <dd
                                :class="{
                                    'account-overview-success':
                                        buyerProfile.emailVerified
                                }"
                            >
                                {{
                                    buyerProfile.emailVerified
                                        ? 'Verified'
                                        : 'Pending'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt>Birthday</dt>
                            <dd>
                                {{ formatDate(buyerProfile.birthday) }}
                            </dd>
                        </div>
                        <div>
                            <dt>Last Updated</dt>
                            <dd>
                                {{ formatDateTime(buyerProfile.updatedAt) }}
                            </dd>
                        </div>
                    </dl>

                    <p class="account-overview-note">
                        Your saved delivery addresses will be managed in the
                        separate Address Management feature.
                    </p>
                </aside>
            </div>
        </main>
    </div>
</template>

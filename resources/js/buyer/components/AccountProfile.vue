<script setup>
/*
|--------------------------------------------------------------------------
| My Profile
|--------------------------------------------------------------------------
|
| Profile photo plus the fields PUT /api/buyer/account/profile accepts
| (names, sex, birthday, mobile number). The sign-in email is shown but not
| edited here: it belongs to the auth account (Login & Security). There is
| no username on BuyTheWay accounts, so none is shown.
|
| The form is always editable; Save / Cancel wake up once something
| changes, and leaving with unsaved edits asks first (useAccountNav).
|
*/
import { computed, nextTick, onActivated, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useBuyerAccount } from '../composables/useBuyerAccount';
import { getSupabase } from '../composables/useBuyerSession';
import { AVATAR_RULES, useAccountSettings } from '../composables/useAccountSettings';
import { goToAccount, registerUnsavedGuard } from '../composables/useAccountNav';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';
import SwitchAccountCard from '../../shared/SwitchAccountCard.vue';

const { buyerProfile, buyerFullName, buyerInitials, calculateAge, updateBuyerProfile } = useBuyerAccount();
const { avatarUrl, uploadAvatar, removeAvatar } = useAccountSettings();
const { confirm } = useConfirm();
const toasts = useToasts();

/*
|--------------------------------------------------------------------------
| Photo
|--------------------------------------------------------------------------
*/

const fileInput = ref(null);
const previewUrl = ref('');
const photoBusy = ref('');
const photoError = ref('');

const shownPhoto = computed(() => previewUrl.value || avatarUrl.value);

function readImageSize(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const img = new Image();

        img.onload = () => resolve({ url, width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        img.src = url;
    });
}

async function onPhotoChosen(event) {
    const file = event.target.files?.[0];

    event.target.value = '';
    photoError.value = '';

    if (!file || photoBusy.value) {
        return;
    }

    if (!AVATAR_RULES.mimes.includes(file.type)) {
        photoError.value = 'Use a JPEG, PNG or WebP image.';

        return;
    }

    if (file.size > AVATAR_RULES.maxBytes) {
        photoError.value = 'The photo must be 2 MB or smaller.';

        return;
    }

    const image = await readImageSize(file);

    if (!image || image.width < AVATAR_RULES.minSize || image.height < AVATAR_RULES.minSize) {
        if (image) {
            URL.revokeObjectURL(image.url);
        }

        photoError.value = `Use a photo at least ${AVATAR_RULES.minSize} × ${AVATAR_RULES.minSize} pixels.`;

        return;
    }

    // Show the new photo right away; roll back if the upload fails.
    previewUrl.value = image.url;
    photoBusy.value = 'upload';

    try {
        await uploadAvatar(file);
        toasts.success('Profile photo updated.');
    } catch (err) {
        photoError.value = err?.message || 'Could not upload your photo.';
    } finally {
        URL.revokeObjectURL(image.url);
        previewUrl.value = '';
        photoBusy.value = '';
    }
}

async function onRemovePhoto() {
    const ok = await confirm({
        title: 'Remove your profile photo?',
        message: 'Your initials will show instead.',
        confirmLabel: 'Remove photo'
    });

    if (!ok) {
        return;
    }

    photoBusy.value = 'remove';
    photoError.value = '';

    try {
        await removeAvatar();
        toasts.success('Profile photo removed.');
    } catch (err) {
        photoError.value = err?.message || 'Could not remove your photo.';
    } finally {
        photoBusy.value = '';
    }
}

/*
|--------------------------------------------------------------------------
| Details form
|--------------------------------------------------------------------------
*/

const SEX_OPTIONS = ['Male', 'Female', 'Prefer not to say'];

const form = reactive({
    firstName: '',
    middleInitial: '',
    lastName: '',
    sex: '',
    contactNumber: '',
    birthday: ''
});

const saved = ref({});
const errors = ref({});
const formError = ref('');
const saving = ref(false);

function snapshotFromProfile() {
    const p = buyerProfile.value || {};

    return {
        firstName: p.first_name || '',
        middleInitial: p.middle_initial || '',
        lastName: p.last_name || '',
        sex: p.sex || '',
        contactNumber: toLocalMobile(p.contact_no || ''),
        birthday: p.birthday || ''
    };
}

function resetForm() {
    saved.value = snapshotFromProfile();
    Object.assign(form, saved.value);
    errors.value = {};
    formError.value = '';
}

resetForm();

// The session can finish loading after this section first renders.
watch(() => buyerProfile.value?.id, () => {
    if (!dirty.value) {
        resetForm();
    }
});

const dirty = computed(() => Object.keys(saved.value).some(key => form[key] !== saved.value[key]));

const unregister = registerUnsavedGuard({ label: 'My Profile', isDirty: () => dirty.value, discard: resetForm });

onBeforeUnmount(unregister);

// Coming back to a kept-alive section shows fresh values unless mid-edit.
onActivated(() => {
    if (!dirty.value) {
        resetForm();
    }
});

const today = new Date().toISOString().slice(0, 10);

function onPhoneInput(event) {
    form.contactNumber = toLocalMobile(event.target.value);
}

function validate() {
    const next = {};

    if (!form.firstName.trim()) {
        next.firstName = 'Enter your first name.';
    }

    if (!form.lastName.trim()) {
        next.lastName = 'Enter your last name.';
    }

    if (form.middleInitial && !/^[A-Za-z]$/.test(form.middleInitial.trim())) {
        next.middleInitial = 'Use one letter.';
    }

    if (form.contactNumber && !isValidLocalMobile(form.contactNumber)) {
        next.contactNumber = 'Enter an 11-digit mobile number, like 09171234567.';
    }

    if (form.birthday && (form.birthday >= today || calculateAge(form.birthday) === null)) {
        next.birthday = 'Enter a past date.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

async function save() {
    formError.value = '';

    if (!validate()) {
        nextTick(() => document.querySelector('.acc-profile [aria-invalid="true"]')?.focus());

        return;
    }

    if (saving.value) {
        return;
    }

    saving.value = true;

    const { error } = await updateBuyerProfile({ ...form, contactNumber: toLocalMobile(form.contactNumber) });

    saving.value = false;

    if (error) {
        // Keep what the buyer typed; just explain.
        formError.value = error;

        return;
    }

    resetForm();
    toasts.success('Profile saved.');
}
</script>

<template>

    <section
        class="acc-section acc-profile"
        aria-labelledby="acc-profile-title"
    >
        <header class="acc-head">
            <h1
                id="acc-profile-title"
                class="acc-title"
            >
                My Profile
            </h1>
            <p class="acc-lede">How you appear to sellers and on your orders.</p>
        </header>

        <!-- Photo -->
        <div class="acc-photo">
            <div
                class="acc-avatar is-lg"
                :class="{ 'is-busy': photoBusy === 'upload' }"
            >
                <img
                    v-if="shownPhoto"
                    :src="shownPhoto"
                    alt=""
                    width="88"
                    height="88"
                >
                <span
                    v-else
                    aria-hidden="true"
                >{{ buyerInitials }}</span>
            </div>
            <div class="acc-photo-body">
                <p class="acc-photo-name">{{ buyerFullName || 'Your name' }}</p>
                <p
                    id="acc-photo-hint"
                    class="acc-hint"
                >
                    JPEG, PNG or WebP, up to 2 MB, at least 128 × 128 pixels.
                </p>
                <div class="acc-photo-actions">
                    <label
                        class="btn btn-secondary acc-file"
                        :class="{ 'is-disabled': photoBusy }"
                    >
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            aria-describedby="acc-photo-hint"
                            :disabled="Boolean(photoBusy)"
                            @change="onPhotoChosen"
                        >
                        {{ photoBusy === 'upload' ? 'Uploading…' : shownPhoto ? 'Change photo' : 'Upload photo' }}
                    </label>
                    <button
                        v-if="avatarUrl"
                        type="button"
                        class="btn btn-ghost"
                        :disabled="Boolean(photoBusy)"
                        @click="onRemovePhoto"
                    >
                        {{ photoBusy === 'remove' ? 'Removing…' : 'Remove' }}
                    </button>
                </div>
                <p
                    v-if="photoError"
                    class="acc-error"
                    role="alert"
                >
                    {{ photoError }}
                </p>
            </div>
        </div>

        <!-- Details -->
        <form
            class="acc-form"
            novalidate
            @submit.prevent="save"
        >
            <fieldset class="acc-fieldset">
                <legend class="acc-legend">Name</legend>
                <div class="acc-grid is-name">
                    <div class="acc-field">
                        <label for="acc-first">First name</label>
                        <input
                            id="acc-first"
                            v-model="form.firstName"
                            type="text"
                            autocomplete="given-name"
                            maxlength="100"
                            :aria-invalid="errors.firstName ? 'true' : undefined"
                            :aria-describedby="errors.firstName ? 'acc-first-err' : undefined"
                        >
                        <p
                            v-if="errors.firstName"
                            id="acc-first-err"
                            class="acc-error"
                        >
                            {{ errors.firstName }}
                        </p>
                    </div>
                    <div class="acc-field is-narrow">
                        <label for="acc-mi">M.I.</label>
                        <input
                            id="acc-mi"
                            v-model="form.middleInitial"
                            type="text"
                            autocomplete="additional-name"
                            maxlength="1"
                            :aria-invalid="errors.middleInitial ? 'true' : undefined"
                            :aria-describedby="errors.middleInitial ? 'acc-mi-err' : undefined"
                        >
                        <p
                            v-if="errors.middleInitial"
                            id="acc-mi-err"
                            class="acc-error"
                        >
                            {{ errors.middleInitial }}
                        </p>
                    </div>
                    <div class="acc-field">
                        <label for="acc-last">Last name</label>
                        <input
                            id="acc-last"
                            v-model="form.lastName"
                            type="text"
                            autocomplete="family-name"
                            maxlength="100"
                            :aria-invalid="errors.lastName ? 'true' : undefined"
                            :aria-describedby="errors.lastName ? 'acc-last-err' : undefined"
                        >
                        <p
                            v-if="errors.lastName"
                            id="acc-last-err"
                            class="acc-error"
                        >
                            {{ errors.lastName }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="acc-fieldset">
                <legend class="acc-legend">Personal details</legend>
                <div class="acc-grid">
                    <div class="acc-field">
                        <label for="acc-sex">Sex</label>
                        <select
                            id="acc-sex"
                            v-model="form.sex"
                            autocomplete="sex"
                        >
                            <option value="">Not specified</option>
                            <option
                                v-for="option in SEX_OPTIONS"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                    </div>
                    <div class="acc-field">
                        <label for="acc-bday">Birthday</label>
                        <input
                            id="acc-bday"
                            v-model="form.birthday"
                            type="date"
                            autocomplete="bday"
                            :max="today"
                            :aria-invalid="errors.birthday ? 'true' : undefined"
                            :aria-describedby="errors.birthday ? 'acc-bday-err' : undefined"
                        >
                        <p
                            v-if="errors.birthday"
                            id="acc-bday-err"
                            class="acc-error"
                        >
                            {{ errors.birthday }}
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="acc-fieldset">
                <legend class="acc-legend">Contact</legend>
                <div class="acc-grid">
                    <div class="acc-field">
                        <label for="acc-phone">Mobile number</label>
                        <input
                            id="acc-phone"
                            :value="form.contactNumber"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            placeholder="09171234567"
                            :aria-invalid="errors.contactNumber ? 'true' : undefined"
                            :aria-describedby="errors.contactNumber ? 'acc-phone-err' : 'acc-phone-hint'"
                            @input="onPhoneInput"
                        >
                        <p
                            v-if="errors.contactNumber"
                            id="acc-phone-err"
                            class="acc-error"
                        >
                            {{ errors.contactNumber }}
                        </p>
                        <p
                            v-else
                            id="acc-phone-hint"
                            class="acc-hint"
                        >
                            Shown to sellers on your orders. Not verified: BuyTheWay doesn&rsquo;t check phone numbers yet.
                        </p>
                    </div>
                    <div class="acc-field">
                        <span
                            id="acc-email-label"
                            class="acc-label"
                        >Sign-in email</span>
                        <p
                            class="acc-readonly"
                            aria-labelledby="acc-email-label"
                        >
                            {{ buyerProfile?.email || '—' }}
                        </p>
                        <p class="acc-hint">
                            Managed in
                            <button
                                type="button"
                                class="link-btn"
                                @click="goToAccount('security')"
                            >
                                Login &amp; Security
                            </button>
                        </p>
                    </div>
                </div>
            </fieldset>

            <p
                v-if="formError"
                class="acc-error acc-form-error"
                role="alert"
            >
                {{ formError }}
            </p>

            <div class="acc-actions">
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="!dirty || saving"
                >
                    {{ saving ? 'Saving…' : 'Save changes' }}
                </button>
                <button
                    type="button"
                    class="btn btn-ghost"
                    :disabled="!dirty || saving"
                    @click="resetForm"
                >
                    Cancel
                </button>
                <span
                    v-if="dirty"
                    class="acc-dirty"
                    role="status"
                >Unsaved changes</span>
            </div>
        </form>
        <div class="acc-switch-account">
            <SwitchAccountCard
                :client="getSupabase()"
                :full-name="buyerFullName"
                :email="buyerProfile?.email || ''"
            />
        </div>
    </section>

</template>

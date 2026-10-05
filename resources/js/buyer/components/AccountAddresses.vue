<script setup>
/*
|--------------------------------------------------------------------------
| Addresses
|--------------------------------------------------------------------------
|
| The buyer's address book (useBuyerAddresses -> /api/buyer/addresses).
| One form handles add and edit and opens in place; delete asks first;
| the server keeps exactly one default. The list is shown only after this
| session's own server load, never from a cache another account on the
| same browser may have left behind.
|
| Field set matches checkout's address shape (free-text city and province,
| 4-digit postal code), so saved addresses keep working there.
|
*/
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { registerUnsavedGuard } from '../composables/useAccountNav';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';

const { addresses, loadError, ADDRESS_LABELS, loadAddresses, addAddress, updateAddress, removeAddress, setDefault } = useBuyerAddresses();
const { confirm } = useConfirm();
const toasts = useToasts();

const ready = ref(false);
const busyId = ref(null);

async function load() {
    ready.value = false;
    await loadAddresses({ force: true });
    ready.value = true;
}

onMounted(load);

const sorted = computed(() => [...addresses.value].sort((a, b) => Number(b.isDefault) - Number(a.isDefault)));

/*
|--------------------------------------------------------------------------
| Add / edit form
|--------------------------------------------------------------------------
*/

const EMPTY = { fullName: '', phone: '', line1: '', city: '', province: '', postalCode: '', label: 'Home', makeDefault: false };

const formOpen = ref(false);
const editingId = ref(null);
const form = reactive({ ...EMPTY });
const original = ref({ ...EMPTY });
const errors = ref({});
const formError = ref('');
const saving = ref(false);
const firstField = ref(null);
const formAnchor = ref(null);

const dirty = computed(() => formOpen.value && Object.keys(EMPTY).some(key => form[key] !== original.value[key]));

function openForm(address = null) {
    editingId.value = address?.id || null;

    const values = address
        ? {
            fullName: address.fullName || '',
            phone: toLocalMobile(address.phone || ''),
            line1: address.line1 || '',
            city: address.city || '',
            province: address.province || '',
            postalCode: address.postalCode || '',
            label: address.label || 'Home',
            makeDefault: Boolean(address.isDefault)
        }
        : { ...EMPTY, makeDefault: addresses.value.length === 0 };

    Object.assign(form, values);
    original.value = { ...values };
    errors.value = {};
    formError.value = '';
    formOpen.value = true;

    nextTick(() => {
        formAnchor.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        firstField.value?.focus({ preventScroll: true });
    });
}

function closeForm() {
    formOpen.value = false;
    editingId.value = null;
    errors.value = {};
    formError.value = '';
}

const unregister = registerUnsavedGuard({ label: 'Addresses', isDirty: () => dirty.value, discard: closeForm });

onBeforeUnmount(unregister);

function onPhoneInput(event) {
    form.phone = toLocalMobile(event.target.value);
}

function validate() {
    const next = {};

    if (!form.fullName.trim()) {
        next.fullName = 'Enter the recipient’s name.';
    }

    if (!form.phone) {
        next.phone = 'Enter a mobile number.';
    } else if (!isValidLocalMobile(form.phone)) {
        next.phone = 'Enter an 11-digit mobile number, like 09171234567.';
    }

    if (!form.line1.trim()) {
        next.line1 = 'Enter the house number, street and barangay.';
    }

    if (!form.city.trim()) {
        next.city = 'Enter the city or municipality.';
    }

    if (!form.province.trim()) {
        next.province = 'Enter the province.';
    }

    if (form.postalCode.trim() && !/^\d{4}$/.test(form.postalCode.trim())) {
        next.postalCode = 'Philippine postal codes have 4 digits.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

async function submit() {
    formError.value = '';

    if (!validate()) {
        nextTick(() => document.querySelector('.acc-address-form [aria-invalid="true"]')?.focus());

        return;
    }

    if (saving.value) {
        return;
    }

    saving.value = true;

    try {
        if (editingId.value) {
            await updateAddress(editingId.value, { ...form });
            toasts.success('Address updated.');
        } else {
            await addAddress({ ...form });
            toasts.success('Address added.');
        }

        closeForm();
    } catch (err) {
        // Keep the form and its input; explain what went wrong.
        const first = err?.body?.errors ? Object.values(err.body.errors).flat()[0] : null;

        formError.value = first || err?.message || 'Could not save this address.';
    } finally {
        saving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Row actions
|--------------------------------------------------------------------------
*/

async function makeDefault(address) {
    if (busyId.value) {
        return;
    }

    busyId.value = address.id;

    try {
        await setDefault(address.id);
        toasts.success(`${address.label} address is now your default.`);
    } catch (err) {
        toasts.error(err?.message || 'Could not change your default address.');
    } finally {
        busyId.value = null;
    }
}

async function remove(address) {
    const ok = await confirm({
        title: 'Delete this address?',
        message: `${address.fullName}, ${address.line1}, ${address.city}. Orders already placed keep their delivery address.`,
        confirmLabel: 'Delete address',
        tone: 'danger'
    });

    if (!ok) {
        return;
    }

    if (editingId.value === address.id) {
        closeForm();
    }

    busyId.value = address.id;

    try {
        await removeAddress(address.id);
        toasts.success('Address deleted.');
    } catch (err) {
        toasts.error(err?.message || 'Could not delete this address.');
    } finally {
        busyId.value = null;
    }
}

function cityLine(address) {
    return [[address.city, address.province].filter(Boolean).join(', '), address.postalCode].filter(Boolean).join(' ');
}
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-addresses-title"
    >
        <header class="acc-head has-action">
            <div>
                <h1
                    id="acc-addresses-title"
                    class="acc-title"
                >
                    Addresses
                </h1>
                <p class="acc-lede">Where your orders can be delivered. The default is picked first at checkout.</p>
            </div>
            <button
                v-if="ready && !formOpen"
                type="button"
                class="btn btn-primary"
                @click="openForm()"
            >
                Add address
            </button>
        </header>

        <div ref="formAnchor"></div>

        <Transition name="acc-expand">
            <form
                v-if="formOpen"
                class="acc-form acc-address-form acc-panel"
                novalidate
                :aria-labelledby="'acc-address-form-title'"
                @submit.prevent="submit"
            >
                <h2
                    id="acc-address-form-title"
                    class="acc-subtitle"
                >
                    {{ editingId ? 'Edit address' : 'New address' }}
                </h2>

                <div class="acc-grid">
                    <div class="acc-field">
                        <label for="acc-addr-name">Recipient name</label>
                        <input
                            id="acc-addr-name"
                            ref="firstField"
                            v-model="form.fullName"
                            type="text"
                            autocomplete="name"
                            maxlength="255"
                            :aria-invalid="errors.fullName ? 'true' : undefined"
                            :aria-describedby="errors.fullName ? 'acc-addr-name-err' : undefined"
                        >
                        <p
                            v-if="errors.fullName"
                            id="acc-addr-name-err"
                            class="acc-error"
                        >
                            {{ errors.fullName }}
                        </p>
                    </div>
                    <div class="acc-field">
                        <label for="acc-addr-phone">Mobile number</label>
                        <input
                            id="acc-addr-phone"
                            :value="form.phone"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            placeholder="09171234567"
                            :aria-invalid="errors.phone ? 'true' : undefined"
                            :aria-describedby="errors.phone ? 'acc-addr-phone-err' : undefined"
                            @input="onPhoneInput"
                        >
                        <p
                            v-if="errors.phone"
                            id="acc-addr-phone-err"
                            class="acc-error"
                        >
                            {{ errors.phone }}
                        </p>
                    </div>
                </div>

                <div class="acc-field">
                    <label for="acc-addr-line1">House no., street, barangay</label>
                    <input
                        id="acc-addr-line1"
                        v-model="form.line1"
                        type="text"
                        autocomplete="street-address"
                        maxlength="500"
                        :aria-invalid="errors.line1 ? 'true' : undefined"
                        :aria-describedby="errors.line1 ? 'acc-addr-line1-err' : undefined"
                    >
                    <p
                        v-if="errors.line1"
                        id="acc-addr-line1-err"
                        class="acc-error"
                    >
                        {{ errors.line1 }}
                    </p>
                </div>

                <div class="acc-grid is-three">
                    <div class="acc-field">
                        <label for="acc-addr-city">City / municipality</label>
                        <input
                            id="acc-addr-city"
                            v-model="form.city"
                            type="text"
                            autocomplete="address-level2"
                            :aria-invalid="errors.city ? 'true' : undefined"
                            :aria-describedby="errors.city ? 'acc-addr-city-err' : undefined"
                        >
                        <p
                            v-if="errors.city"
                            id="acc-addr-city-err"
                            class="acc-error"
                        >
                            {{ errors.city }}
                        </p>
                    </div>
                    <div class="acc-field">
                        <label for="acc-addr-province">Province</label>
                        <input
                            id="acc-addr-province"
                            v-model="form.province"
                            type="text"
                            autocomplete="address-level1"
                            :aria-invalid="errors.province ? 'true' : undefined"
                            :aria-describedby="errors.province ? 'acc-addr-province-err' : undefined"
                        >
                        <p
                            v-if="errors.province"
                            id="acc-addr-province-err"
                            class="acc-error"
                        >
                            {{ errors.province }}
                        </p>
                    </div>
                    <div class="acc-field">
                        <label for="acc-addr-zip">Postal code <span class="acc-optional">optional</span></label>
                        <input
                            id="acc-addr-zip"
                            v-model="form.postalCode"
                            type="text"
                            inputmode="numeric"
                            autocomplete="postal-code"
                            maxlength="4"
                            :aria-invalid="errors.postalCode ? 'true' : undefined"
                            :aria-describedby="errors.postalCode ? 'acc-addr-zip-err' : undefined"
                        >
                        <p
                            v-if="errors.postalCode"
                            id="acc-addr-zip-err"
                            class="acc-error"
                        >
                            {{ errors.postalCode }}
                        </p>
                    </div>
                </div>

                <fieldset class="acc-fieldset is-inline">
                    <legend class="acc-label">Label</legend>
                    <div class="acc-segment">
                        <label
                            v-for="label in ADDRESS_LABELS"
                            :key="label"
                            class="acc-segment-option"
                        >
                            <input
                                v-model="form.label"
                                type="radio"
                                name="acc-addr-label"
                                :value="label"
                            >
                            <span>{{ label }}</span>
                        </label>
                    </div>
                </fieldset>

                <label class="acc-check">
                    <input
                        v-model="form.makeDefault"
                        type="checkbox"
                        :disabled="addresses.length === 0 && !editingId"
                    >
                    <span>Use as my default address</span>
                </label>

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
                        :disabled="saving"
                    >
                        {{ saving ? 'Saving…' : editingId ? 'Save address' : 'Add address' }}
                    </button>
                    <button
                        type="button"
                        class="btn btn-ghost"
                        :disabled="saving"
                        @click="closeForm"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </Transition>

        <!-- List -->
        <div
            v-if="!ready"
            class="acc-loading"
            aria-busy="true"
        >
            <span class="skeleton is-line"></span>
            <span class="skeleton is-line"></span>
        </div>

        <div
            v-else-if="loadError && !addresses.length"
            class="acc-empty"
            role="alert"
        >
            <p>{{ loadError }}</p>
            <button
                type="button"
                class="btn btn-secondary"
                @click="load"
            >
                Try again
            </button>
        </div>

        <div
            v-else-if="!addresses.length && !formOpen"
            class="acc-empty"
        >
            <p class="acc-empty-title">No saved addresses yet</p>
            <p>Add one now and checkout fills it in for you.</p>
            <button
                type="button"
                class="btn btn-primary"
                @click="openForm()"
            >
                Add your first address
            </button>
        </div>

        <TransitionGroup
            v-else
            name="acc-list"
            tag="ul"
            class="acc-address-list"
        >
            <li
                v-for="address in sorted"
                :key="address.id"
                class="acc-address"
                :class="{ 'is-default': address.isDefault, 'is-busy': busyId === address.id }"
            >
                <div class="acc-address-main">
                    <p class="acc-address-top">
                        <strong>{{ address.fullName }}</strong>
                        <span class="acc-tag">{{ address.label }}</span>
                        <span
                            v-if="address.isDefault"
                            class="acc-tag is-default"
                        >
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                            Default
                        </span>
                    </p>
                    <p class="acc-address-line">{{ address.phone }}</p>
                    <p class="acc-address-line">{{ address.line1 }}</p>
                    <p class="acc-address-line">{{ cityLine(address) }}</p>
                </div>
                <div class="acc-address-actions">
                    <button
                        type="button"
                        class="link-btn"
                        :disabled="busyId === address.id"
                        @click="openForm(address)"
                    >
                        Edit
                    </button>
                    <button
                        v-if="!address.isDefault"
                        type="button"
                        class="link-btn"
                        :disabled="busyId === address.id"
                        @click="makeDefault(address)"
                    >
                        Set as default
                    </button>
                    <button
                        type="button"
                        class="link-btn is-danger"
                        :disabled="busyId === address.id"
                        @click="remove(address)"
                    >
                        Delete
                    </button>
                </div>
            </li>
        </TransitionGroup>
    </section>

</template>

<script setup>
/*
|--------------------------------------------------------------------------
| AddressForm.vue — add / edit one saved address
|--------------------------------------------------------------------------
|
| Shared by SavedAddresses.vue and Checkout.vue's inline "Add new address".
| Region / province / municipality / barangay use the same island-group +
| PSGC cascade as the Account page and registration (/api/psgc via
| shared/usePsgc.js), so saved addresses carry the structured destination
| checkout routes orders on.
|
| Emits `submit` with the camelCase payload useBuyerAddresses() expects;
| the parent owns the API call and passes `submitting` back in.
|
*/
import { computed, nextTick, onMounted, reactive, ref } from 'vue';

import { usePsgc } from '../../shared/usePsgc';
import { useBuyerAccount } from '../composables/useBuyerAccount';
import { isValidLocalMobile, toLocalMobile } from '../composables/usePhone';

const props = defineProps({
    initial: { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    submitLabel: { type: String, default: 'Save Address' },
    labels: { type: Array, default: () => ['Home', 'Work', 'Other'] },
    // Hides the "set as default" checkbox (e.g. the very first address,
    // which the server always makes default).
    showDefaultToggle: { type: Boolean, default: true },
    idPrefix: { type: String, default: 'addr' }
});

const emit = defineEmits(['submit', 'cancel']);

const REGION_OPTIONS = ['Luzon', 'Visayas', 'Mindanao'];

const { fetchProvinces, fetchMunicipalities, fetchBarangays } = usePsgc();

// Recipient name / phone aren't asked for — they come from the buyer's
// account (an edited address keeps the ones it was saved with).
const { profile, buyerFullName, loadBuyerAccount } = useBuyerAccount();

if (!profile.value) {
    loadBuyerAccount();
}

const recipientName = computed(
    () => props.initial?.fullName || profile.value?.full_name || buyerFullName.value || ''
);
const recipientPhone = computed(
    () => toLocalMobile(props.initial?.phone || profile.value?.contact_no || '')
);

const form = reactive({
    region: props.initial?.region || '',
    provinceCode: props.initial?.provinceCode || '',
    province: props.initial?.province || '',
    municipalityCode: props.initial?.municipalityCode || '',
    city: props.initial?.city || '',
    barangay: props.initial?.barangay || '',
    houseNo: props.initial?.houseNo || '',
    line1: props.initial?.line1 || '',
    postalCode: props.initial?.postalCode || '',
    label: props.initial?.label || 'Home',
    makeDefault: Boolean(props.initial?.isDefault)
});

const errors = ref({});
const lookupError = ref('');

// Seeded with the saved selection so an edit shows its values instantly
// while the full lists load.
const provinces = ref(form.provinceCode ? [{ code: form.provinceCode, name: form.province }] : []);
const municipalities = ref(form.municipalityCode ? [{ code: form.municipalityCode, name: form.city }] : []);
const barangays = ref(form.barangay ? [{ code: 'current', name: form.barangay }] : []);
const loading = reactive({ provinces: false, municipalities: false, barangays: false });

const filteredProvinces = computed(() => {
    const group = form.region.toLowerCase();

    if (!group || !provinces.value.some(p => p.islandGroupCode)) {
        return provinces.value;
    }

    return provinces.value.filter(p => !p.islandGroupCode || p.islandGroupCode === group);
});

// Keeps the current selection visible even if a refreshed list lacks it.
function withSelected(list, selected, key = 'code') {
    if (!selected?.[key] || list.some(item => item[key] === selected[key])) {
        return list;
    }

    return [selected, ...list];
}

async function load(kind, fetcher, target, selected, key) {
    loading[kind] = true;
    lookupError.value = '';

    try {
        target.value = withSelected(await fetcher(), selected, key);
    } catch (err) {
        lookupError.value = err?.message || 'Could not load the address list. Please retry.';
    } finally {
        loading[kind] = false;
    }
}

function loadProvinces() {
    return load('provinces', fetchProvinces, provinces,
        form.provinceCode ? { code: form.provinceCode, name: form.province } : null);
}

function loadMunicipalities() {
    return load('municipalities', () => fetchMunicipalities(form.provinceCode), municipalities,
        form.municipalityCode ? { code: form.municipalityCode, name: form.city } : null);
}

function loadBarangays() {
    return load('barangays', () => fetchBarangays(form.municipalityCode), barangays,
        form.barangay ? { code: 'current', name: form.barangay } : null, 'name');
}

function retryLookups() {
    loadProvinces();

    if (form.provinceCode) {
        loadMunicipalities();
    }

    if (form.municipalityCode) {
        loadBarangays();
    }
}

function onRegionChange() {
    const current = provinces.value.find(p => p.code === form.provinceCode);

    if (current?.islandGroupCode && current.islandGroupCode !== form.region.toLowerCase()) {
        form.provinceCode = '';
        onProvinceChange();
    }
}

function onProvinceChange() {
    form.province = provinces.value.find(p => p.code === form.provinceCode)?.name || '';

    // Fill in a missing island group from the province itself.
    const group = provinces.value.find(p => p.code === form.provinceCode)?.islandGroupCode;

    if (group && !form.region) {
        form.region = group.charAt(0).toUpperCase() + group.slice(1);
    }

    form.municipalityCode = '';
    form.city = '';
    form.barangay = '';
    municipalities.value = [];
    barangays.value = [];

    if (form.provinceCode) {
        loadMunicipalities();
    }
}

function onMunicipalityChange() {
    form.city = municipalities.value.find(m => m.code === form.municipalityCode)?.name || '';
    form.barangay = '';
    barangays.value = [];

    if (form.municipalityCode) {
        loadBarangays();
    }
}

function validate() {
    const next = {};

    if (!recipientName.value.trim() || !isValidLocalMobile(recipientPhone.value)) {
        next.account = 'Add your name and an 11-digit mobile number in Account first.';
    }
    if (!form.region) next.region = 'Select a region.';
    if (!form.provinceCode) next.province = 'Select a province.';
    if (!form.municipalityCode) next.city = 'Select a city / municipality.';
    if (!form.barangay) next.barangay = 'Select a barangay.';
    if (!form.line1.trim()) next.line1 = 'Street is required.';
    if (form.postalCode.trim() && !/^\d{4}$/.test(form.postalCode.trim())) next.postalCode = 'PH postal codes are 4 digits.';

    errors.value = next;

    return Object.keys(next).length === 0;
}

async function submit() {
    if (props.submitting) {
        return;
    }

    if (!validate()) {
        await nextTick();
        document.querySelector(`[aria-invalid="true"][id^="${props.idPrefix}-"]`)?.focus();

        return;
    }

    emit('submit', { ...form, fullName: recipientName.value, phone: recipientPhone.value });
}

onMounted(() => {
    loadProvinces();

    if (form.provinceCode) loadMunicipalities();
    if (form.municipalityCode) loadBarangays();
});

const inputClass = 'w-full px-4 py-3 bg-slate-50 border rounded-xl text-sm text-slate-900 focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/10 transition-all disabled:opacity-50 disabled:cursor-not-allowed';
const labelClass = 'block text-[11px] font-bold text-slate-500 uppercase tracking-widest mb-1.5 px-1';
const id = name => `${props.idPrefix}-${name}`;
</script>

<template>
    <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5" novalidate @submit.prevent="submit">

        <p
            v-if="errors.account"
            class="sm:col-span-2 px-4 py-2.5 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700"
            role="alert"
        >
            {{ errors.account }}
        </p>

        <div
            v-if="lookupError"
            class="sm:col-span-2 flex items-center justify-between gap-3 px-4 py-2.5 rounded-xl bg-amber-50 border border-amber-100 text-sm text-amber-800"
            role="alert"
        >
            <span>{{ lookupError }}</span>
            <button type="button" class="font-bold underline underline-offset-2" @click="retryLookups">Retry</button>
        </div>

        <div>
            <label :for="id('region')" :class="labelClass">Region <span class="text-red-500">*</span></label>
            <select
                :id="id('region')" v-model="form.region" :class="[inputClass, errors.region ? 'border-red-300' : 'border-slate-200']"
                :aria-invalid="!!errors.region" @change="onRegionChange"
            >
                <option value="">Select region</option>
                <option v-for="r in REGION_OPTIONS" :key="r" :value="r">{{ r }}</option>
            </select>
            <p v-if="errors.region" class="text-xs text-red-500 mt-1 px-1">{{ errors.region }}</p>
        </div>

        <div>
            <label :for="id('province')" :class="labelClass">Province <span class="text-red-500">*</span></label>
            <select
                :id="id('province')" v-model="form.provinceCode" :disabled="loading.provinces && !provinces.length"
                :class="[inputClass, errors.province ? 'border-red-300' : 'border-slate-200']"
                :aria-invalid="!!errors.province" @change="onProvinceChange"
            >
                <option value="">{{ loading.provinces ? 'Loading provinces…' : 'Select province' }}</option>
                <option v-for="p in filteredProvinces" :key="p.code" :value="p.code">{{ p.name }}</option>
            </select>
            <p v-if="errors.province" class="text-xs text-red-500 mt-1 px-1">{{ errors.province }}</p>
        </div>

        <div>
            <label :for="id('city')" :class="labelClass">City / Municipality <span class="text-red-500">*</span></label>
            <select
                :id="id('city')" v-model="form.municipalityCode" :disabled="!form.provinceCode || (loading.municipalities && !municipalities.length)"
                :class="[inputClass, errors.city ? 'border-red-300' : 'border-slate-200']"
                :aria-invalid="!!errors.city" @change="onMunicipalityChange"
            >
                <option value="">{{ loading.municipalities ? 'Loading…' : (form.provinceCode ? 'Select city / municipality' : 'Select a province first') }}</option>
                <option v-for="m in municipalities" :key="m.code" :value="m.code">{{ m.name }}</option>
            </select>
            <p v-if="errors.city" class="text-xs text-red-500 mt-1 px-1">{{ errors.city }}</p>
        </div>

        <div>
            <label :for="id('barangay')" :class="labelClass">Barangay <span class="text-red-500">*</span></label>
            <select
                :id="id('barangay')" v-model="form.barangay" :disabled="!form.municipalityCode || (loading.barangays && !barangays.length)"
                :class="[inputClass, errors.barangay ? 'border-red-300' : 'border-slate-200']"
                :aria-invalid="!!errors.barangay"
            >
                <option value="">{{ loading.barangays ? 'Loading…' : (form.municipalityCode ? 'Select barangay' : 'Select a city first') }}</option>
                <option v-for="b in barangays" :key="b.code + b.name" :value="b.name">{{ b.name }}</option>
            </select>
            <p v-if="errors.barangay" class="text-xs text-red-500 mt-1 px-1">{{ errors.barangay }}</p>
        </div>

        <div class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-[9rem_1fr_9rem] gap-x-6 gap-y-5">
            <div>
                <label :for="id('house')" :class="labelClass">House / Unit No.</label>
                <input
                    :id="id('house')" v-model="form.houseNo" type="text" maxlength="50"
                    placeholder="Optional" :class="[inputClass, 'border-slate-200']"
                >
            </div>
            <div>
                <label :for="id('line1')" :class="labelClass">Street <span class="text-red-500">*</span></label>
                <input
                    :id="id('line1')" v-model="form.line1" type="text" autocomplete="address-line1"
                    placeholder="e.g. Mabini St." :class="[inputClass, errors.line1 ? 'border-red-300' : 'border-slate-200']"
                    :aria-invalid="!!errors.line1"
                >
                <p v-if="errors.line1" class="text-xs text-red-500 mt-1 px-1">{{ errors.line1 }}</p>
            </div>
            <div>
                <label :for="id('postal')" :class="labelClass">Postal Code</label>
                <input
                    :id="id('postal')" v-model="form.postalCode" type="text" inputmode="numeric" maxlength="4" autocomplete="postal-code"
                    placeholder="Optional" :class="[inputClass, errors.postalCode ? 'border-red-300' : 'border-slate-200']"
                    :aria-invalid="!!errors.postalCode"
                >
                <p v-if="errors.postalCode" class="text-xs text-red-500 mt-1 px-1">{{ errors.postalCode }}</p>
            </div>
        </div>

        <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-4">
            <div class="flex gap-2" role="radiogroup" aria-label="Address type">
                <label v-for="label in labels" :key="label" class="cursor-pointer">
                    <input v-model="form.label" type="radio" :name="id('type')" :value="label" class="sr-only peer">
                    <span class="block px-4 py-2 rounded-lg border border-slate-200 text-xs font-bold text-slate-600 bg-white transition-all hover:bg-slate-50 peer-checked:bg-teal-50 peer-checked:border-[#0d9488] peer-checked:text-[#0d9488] peer-focus-visible:ring-2 peer-focus-visible:ring-[#0d9488]/30">
                        {{ label }}
                    </span>
                </label>
            </div>
            <label v-if="showDefaultToggle" class="flex items-center gap-2.5 cursor-pointer">
                <input v-model="form.makeDefault" type="checkbox" class="w-[18px] h-[18px] accent-[#0d9488] cursor-pointer">
                <span class="text-sm font-medium text-slate-600">Set as default address</span>
            </label>
        </div>

        <div class="sm:col-span-2 flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-1">
            <button
                type="button"
                class="px-6 py-3 rounded-xl text-sm font-bold text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors"
                :disabled="submitting"
                @click="emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                class="inline-flex items-center justify-center gap-2 px-8 py-3 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-all disabled:opacity-60 disabled:cursor-wait"
                :disabled="submitting"
                :aria-busy="submitting"
            >
                <svg v-if="submitting" class="animate-spin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56" stroke-linecap="round" />
                </svg>
                {{ submitting ? 'Saving…' : submitLabel }}
            </button>
        </div>
    </form>
</template>

<script setup>
/*
|--------------------------------------------------------------------------
| SavedAddresses.vue — the buyer's address book
|--------------------------------------------------------------------------
|
| List + add / edit / delete / set-default over useBuyerAddresses()
| (GET/POST/PUT/DELETE /api/buyer/addresses). The add/edit form is the
| shared AddressForm.vue — the same island-group + PSGC cascade the Account
| page and registration use — so every address carries the structured
| destination checkout routes orders on.
|
| Legacy free-text addresses (saved before the PSGC fields existed) show a
| "Needs update" flag; checkout won't route to them until edited.
|
| `fromCheckout` is set when the buyer came here from Checkout's "Manage"
| link; a "Back to checkout" button returns them with the cart intact.
|
*/
import { computed, nextTick, ref } from 'vue';

import Header from './Header.vue';
import AccountSidebar from './AccountSidebar.vue';
import Footer from './Footer.vue';
import AddressForm from './AddressForm.vue';
import { useBuyerAddresses } from '../composables/useBuyerAddresses';
import { useConfirm } from '../composables/useConfirm';
import { useToasts } from '../composables/useToasts';

defineProps({
    fromCheckout: { type: Boolean, default: false }
});

const emit = defineEmits([
    'back',
    'go-home',
    'search',
    'select-category',
    'open-cart',
    'view-profile',
    'view-orders',
    'view-wishlist',
    'view-reviews',
    'view-payments'
]);

const {
    addresses,
    hasAddresses,
    isLoading,
    loadError,
    ADDRESS_LABELS,
    loadAddresses,
    addAddress,
    updateAddress,
    removeAddress,
    setDefault
} = useBuyerAddresses();

const { confirm } = useConfirm();
const { success, error: toastError } = useToasts();

// Skeletons only for a true first load — a cached list renders instantly.
const showSkeleton = computed(() => isLoading.value && !hasAddresses.value);

/*
|--------------------------------------------------------------------------
| Add / Edit form
|--------------------------------------------------------------------------
*/

const isFormOpen = ref(false);
const editing = ref(null);
const isSaving = ref(false);
// Remounts AddressForm per open so its state never leaks between edits.
const formKey = ref(0);
const formSection = ref(null);
const busyId = ref(null);

function openForm(address = null) {
    editing.value = address;
    formKey.value += 1;
    isFormOpen.value = true;
    nextTick(() => formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

function closeForm() {
    isFormOpen.value = false;
    editing.value = null;
}

async function submitForm(payload) {
    isSaving.value = true;

    try {
        if (editing.value) {
            await updateAddress(editing.value.id, payload);
            success('Address updated.');
        } else {
            await addAddress(payload);
            success('Address saved.');
        }

        closeForm();
    } catch (err) {
        toastError(err?.message || 'Could not save the address. Please try again.');
    } finally {
        isSaving.value = false;
    }
}

/*
|--------------------------------------------------------------------------
| Card actions
|--------------------------------------------------------------------------
*/

async function handleSetDefault(address) {
    busyId.value = address.id;

    try {
        await setDefault(address.id);
        success(`${address.label} address is now your default.`);
    } catch (err) {
        toastError(err?.message || 'Could not change your default address.');
    } finally {
        busyId.value = null;
    }
}

async function handleDelete(address) {
    const others = addresses.value.length - 1;
    const ok = await confirm({
        title: address.isDefault ? 'Delete your default address?' : 'Delete this address?',
        message: address.isDefault && others > 0
            ? `${address.fullName}'s ${address.label.toLowerCase()} address is your default. Your most recently used address will become the new default.`
            : `${address.fullName}'s ${address.label.toLowerCase()} address will be removed from your address book.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Keep it',
        tone: 'danger'
    });

    if (!ok) {
        return;
    }

    if (editing.value?.id === address.id) {
        closeForm();
    }

    busyId.value = address.id;

    try {
        await removeAddress(address.id);
        success('Address deleted.');
    } catch (err) {
        toastError(err?.message || 'Could not delete the address.');
    } finally {
        busyId.value = null;
    }
}

function addressLines(address) {
    return [
        [address.houseNo, address.line1].filter(Boolean).join(' '),
        [address.barangay, address.city].filter(Boolean).join(', '),
        [address.province, address.postalCode].filter(Boolean).join(' ')
    ].filter(Boolean);
}
</script>

<template>
    <div class="buyer-page">

        <Header
            active-category=""
            @select-category="category => emit('select-category', category)"
            @cart-click="emit('open-cart')"
            @account-click="emit('view-profile')"
            @logo-click="emit('go-home')"
            @search="query => emit('search', query)"
        />

        <main class="max-w-7xl mx-auto w-full px-4 lg:px-8 py-10">
            <div class="flex flex-col lg:flex-row lg:items-start gap-8">

                <AccountSidebar active="addresses" />

                <div class="flex-1 space-y-6 min-w-0">

                    <!-- Back to checkout -->
                    <button
                        v-if="fromCheckout"
                        type="button"
                        class="inline-flex items-center gap-2 text-sm font-bold text-[#0d9488] hover:text-[#0f766e]"
                        @click="emit('back')"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m15 18-6-6 6-6" />
                        </svg>
                        Back to checkout
                    </button>

                    <!-- Breadcrumb + Title -->
                    <div class="flex flex-col gap-2">
                        <nav v-if="!fromCheckout" class="flex items-center text-sm font-medium text-slate-400" aria-label="Breadcrumb">
                            <button type="button" class="hover:text-slate-600 transition-colors" @click="emit('view-profile')">Account</button>
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mx-1.5" aria-hidden="true">
                                <path d="m9 18 6-6-6-6" />
                            </svg>
                            <span class="text-slate-900">Saved Addresses</span>
                        </nav>
                        <div class="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Saved Addresses</h1>
                                <p class="text-slate-500 mt-1">Your default address is pre-selected at checkout.</p>
                            </div>
                            <button
                                v-if="hasAddresses && !isFormOpen"
                                type="button"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                                @click="openForm()"
                            >
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                                Add New Address
                            </button>
                        </div>
                    </div>

                    <!-- Load error -->
                    <div
                        v-if="loadError && !hasAddresses"
                        class="flex items-center justify-between gap-4 px-5 py-4 bg-red-50 border border-red-100 rounded-2xl text-sm text-red-700"
                        role="alert"
                    >
                        <span>{{ loadError }}</span>
                        <button type="button" class="font-bold underline underline-offset-2" @click="loadAddresses({ force: true })">Retry</button>
                    </div>

                    <!-- Add / Edit form -->
                    <section
                        v-if="isFormOpen"
                        ref="formSection"
                        class="bg-white rounded-3xl border border-slate-200 overflow-hidden scroll-mt-24"
                        style="box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05), 0 2px 8px -2px rgba(0,0,0,0.04);"
                        :aria-label="editing ? 'Edit address' : 'Add new address'"
                    >
                        <div class="px-6 sm:px-8 py-5 border-b border-slate-100 flex items-center gap-3">
                            <div class="w-10 h-10 bg-teal-50 rounded-2xl flex items-center justify-center text-[#0d9488]">
                                <svg v-if="editing" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                                <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M5 12h14" /><path d="M12 5v14" />
                                </svg>
                            </div>
                            <h2 class="text-lg font-bold text-slate-900">{{ editing ? 'Edit Address' : 'Add New Address' }}</h2>
                        </div>
                        <div class="p-6 sm:p-8">
                            <AddressForm
                                :key="formKey"
                                :initial="editing"
                                :labels="ADDRESS_LABELS"
                                :submitting="isSaving"
                                :submit-label="editing ? 'Save Changes' : 'Save Address'"
                                :show-default-toggle="hasAddresses && !editing?.isDefault"
                                id-prefix="book"
                                @submit="submitForm"
                                @cancel="closeForm"
                            />
                        </div>
                    </section>

                    <!-- Skeleton -->
                    <div v-if="showSkeleton" class="grid grid-cols-1 md:grid-cols-2 gap-6" aria-busy="true" aria-label="Loading addresses">
                        <div v-for="n in 2" :key="n" class="bg-white rounded-3xl border border-slate-200 p-7 space-y-3 animate-pulse">
                            <div class="h-5 w-1/2 bg-slate-100 rounded" />
                            <div class="h-4 w-3/4 bg-slate-100 rounded" />
                            <div class="h-4 w-2/3 bg-slate-100 rounded" />
                            <div class="h-10 w-full bg-slate-100 rounded-xl mt-6" />
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div
                        v-else-if="!hasAddresses && !isFormOpen && !loadError"
                        class="bg-white rounded-3xl border border-dashed border-slate-200 p-12 text-center"
                    >
                        <div class="w-14 h-14 mx-auto rounded-2xl bg-teal-50 text-[#0d9488] flex items-center justify-center mb-4">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /><circle cx="12" cy="10" r="3" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-900">No saved addresses yet</h2>
                        <p class="text-slate-500 text-sm mt-1 mb-6">Add one and it'll be ready to pick at checkout.</p>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-[#0d9488] text-white rounded-xl text-sm font-bold hover:bg-[#0f766e] transition-colors"
                            @click="openForm()"
                        >
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h14" /><path d="M12 5v14" />
                            </svg>
                            Add New Address
                        </button>
                    </div>

                    <!-- Address cards -->
                    <div v-else-if="hasAddresses" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <article
                            v-for="address in addresses"
                            :key="address.id"
                            class="bg-white rounded-3xl p-7 flex flex-col transition-opacity"
                            :class="[
                                address.isDefault ? 'border-2 border-[#0d9488]' : 'border border-slate-200',
                                busyId === address.id ? 'opacity-60 pointer-events-none' : ''
                            ]"
                            :aria-busy="busyId === address.id"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <h3 class="text-lg font-bold text-slate-900 min-w-0 break-words">{{ address.fullName }}</h3>
                                <div class="flex flex-wrap justify-end gap-1.5 shrink-0">
                                    <span
                                        v-if="address.isDefault"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold bg-teal-50 text-[#0d9488] uppercase tracking-wide"
                                    >
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true">
                                            <path d="M12 2 15.09 8.26 22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                                        </svg>
                                        Default
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 uppercase tracking-wide">
                                        {{ address.label }}
                                    </span>
                                </div>
                            </div>

                            <p class="text-sm text-slate-500 mt-2 leading-relaxed">
                                <template v-for="(line, index) in addressLines(address)" :key="index">
                                    {{ line }}<br>
                                </template>
                            </p>

                            <div class="flex items-center gap-2 text-sm text-slate-500 mt-3">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-slate-400" aria-hidden="true">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                                </svg>
                                <span>{{ address.phone }}</span>
                            </div>

                            <p
                                v-if="!address.isComplete"
                                class="mt-4 flex items-start gap-2 px-3 py-2.5 rounded-xl bg-amber-50 text-xs font-medium text-amber-800"
                            >
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-px" aria-hidden="true">
                                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /><path d="M12 9v4" /><path d="M12 17h.01" />
                                </svg>
                                Needs update: pick the region, province, city and barangay before using it at checkout.
                            </p>

                            <div class="pt-5 mt-auto flex flex-wrap items-center gap-2">
                                <button
                                    v-if="!address.isDefault"
                                    type="button"
                                    class="flex-1 min-w-[7rem] min-h-[44px] px-3 bg-white text-[#0d9488] rounded-xl text-xs font-bold hover:bg-teal-50 transition-colors border border-teal-100"
                                    @click="handleSetDefault(address)"
                                >
                                    Set as Default
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 min-w-[5rem] min-h-[44px] px-3 inline-flex items-center justify-center gap-1.5 rounded-xl text-xs font-bold transition-colors border"
                                    :class="address.isComplete ? 'bg-slate-50 text-slate-700 hover:bg-slate-100 border-slate-200' : 'bg-amber-500 text-white hover:bg-amber-600 border-amber-500'"
                                    @click="openForm(address)"
                                >
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 20h9" /><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                    </svg>
                                    {{ address.isComplete ? 'Edit' : 'Update' }}
                                </button>
                                <button
                                    type="button"
                                    class="min-h-[44px] min-w-[44px] px-3 inline-flex items-center justify-center bg-white text-slate-400 rounded-xl hover:text-red-600 hover:bg-red-50 transition-colors border border-slate-200"
                                    :aria-label="`Delete ${address.label} address for ${address.fullName}`"
                                    title="Delete"
                                    @click="handleDelete(address)"
                                >
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                    </svg>
                                </button>
                            </div>
                        </article>
                    </div>

                </div>
            </div>
        </main>

        <Footer
            @browse-all="emit('go-home')"
            @browse-categories="emit('go-home')"
            @cart-click="emit('open-cart')"
        />

    </div>
</template>

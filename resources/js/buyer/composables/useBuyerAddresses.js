import { computed, ref } from 'vue';

import { buyerApi } from './useBuyerApi';

/*
|--------------------------------------------------------------------------
| useBuyerAddresses — the buyer's saved address book
|--------------------------------------------------------------------------
|
| Backed by the Laravel Buyer API (GET/POST/PUT/DELETE /api/buyer/addresses
| -> App\Http\Controllers\Buyer\AddressController, buyer_addresses table).
| Previously localStorage only. The exported surface is unchanged so
| SavedAddresses.vue and Checkout.vue need no edits:
|
|   addresses, defaultAddress, hasAddresses, ADDRESS_LABELS,
|   loadAddresses, addAddress, updateAddress, removeAddress, setDefault
|
| Address objects use the same camelCase shape the components already read
| ({ id, fullName, phone, line1, city, province, postalCode, label,
| isDefault }) — the controller maps to/from the snake_case columns.
|
| Mutations are optimistic: local state updates first, then the API call;
| on failure the previous list is restored and the error is re-thrown so
| the caller can surface it. Module-level refs (like the other buyer
| composables) so the list is the same wherever it's read.
|
*/

const STORAGE_KEY = 'nexmart_buyer_addresses';

const ADDRESS_LABELS = ['Home', 'Work', 'Other'];

const addresses = ref([]);
const isLoading = ref(false);
const loadError = ref('');

let loadedOnce = false;
let inFlight = null;

// Offline cache only — the server list always wins once it loads. Keeps
// the address list from flashing empty on a slow connection / brief
// re-mount.
function readCache() {
    try {
        const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');

        return Array.isArray(parsed) ? parsed : [];
    } catch (err) {
        return [];
    }
}

function writeCache() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(addresses.value));
    } catch (err) {
        // Storage unavailable (private browsing etc.) — nothing to recover.
    }
}

if (addresses.value.length === 0) {
    addresses.value = readCache();
}

const defaultAddress = computed(
    () => addresses.value.find(address => address.isDefault) || addresses.value[0] || null
);

const hasAddresses = computed(() => addresses.value.length > 0);

async function fetchAddresses() {
    isLoading.value = true;
    loadError.value = '';

    try {
        addresses.value = await buyerApi('/buyer/addresses');
        writeCache();
        loadedOnce = true;
    } catch (err) {
        // Not signed in yet, or a transient failure — keep whatever the
        // cache gave us and let the caller retry later.
        if (err?.status && err.status !== 401) {
            loadError.value = err?.message || 'Could not load your addresses.';
        }
    } finally {
        isLoading.value = false;
    }
}

function loadAddresses({ force = false } = {}) {
    if (inFlight) {
        return inFlight;
    }

    if (loadedOnce && !force) {
        return Promise.resolve();
    }

    inFlight = fetchAddresses().finally(() => {
        inFlight = null;
    });

    return inFlight;
}

function normalize(input) {
    return {
        recipient_name: (input.fullName || '').trim(),
        contact_no: (input.phone || '').trim(),
        line1: (input.line1 || '').trim(),
        city: (input.city || '').trim(),
        province: (input.province || '').trim(),
        postal_code: (input.postalCode || '').trim() || null,
        label: ADDRESS_LABELS.includes(input.label) ? input.label : 'Home',
        is_default: Boolean(input.makeDefault),
    };
}

async function addAddress(input) {
    const snapshot = [...addresses.value];

    try {
        const created = await buyerApi('/buyer/addresses', {
            method: 'POST',
            body: JSON.stringify(normalize(input)),
        });

        // Server owns the is_default bookkeeping (first address is always
        // default; a new default demotes the others), so refetch rather
        // than guess.
        await fetchAddresses();

        return created;
    } catch (err) {
        addresses.value = snapshot;

        throw err;
    }
}

async function updateAddress(id, input) {
    const snapshot = [...addresses.value];

    try {
        const updated = await buyerApi(`/buyer/addresses/${encodeURIComponent(id)}`, {
            method: 'PUT',
            body: JSON.stringify(normalize(input)),
        });

        await fetchAddresses();

        return updated;
    } catch (err) {
        addresses.value = snapshot;

        throw err;
    }
}

async function removeAddress(id) {
    const snapshot = [...addresses.value];
    addresses.value = addresses.value.filter(address => address.id !== id);
    writeCache();

    try {
        await buyerApi(`/buyer/addresses/${encodeURIComponent(id)}`, { method: 'DELETE' });
        await fetchAddresses();
    } catch (err) {
        addresses.value = snapshot;
        writeCache();

        throw err;
    }
}

async function setDefault(id) {
    const snapshot = [...addresses.value];
    addresses.value = addresses.value.map(address => ({
        ...address,
        isDefault: address.id === id,
    }));
    writeCache();

    try {
        await buyerApi(`/buyer/addresses/${encodeURIComponent(id)}/default`, { method: 'PUT' });
        await fetchAddresses();
    } catch (err) {
        addresses.value = snapshot;
        writeCache();

        throw err;
    }
}

export function useBuyerAddresses() {
    // Kick off the first load lazily the moment any component asks for the
    // address book.
    loadAddresses();

    return {
        addresses,
        isLoading,
        loadError,
        defaultAddress,
        hasAddresses,
        ADDRESS_LABELS,

        loadAddresses,
        addAddress,
        updateAddress,
        removeAddress,
        setDefault,
    };
}

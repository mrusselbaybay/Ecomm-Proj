// resources/js/seller/composables/useCouriers.js
//
// Real registered logistics companies (App\Http\Controllers\Seller\
// SellerCourierController -> public.logistics_companies), approved and
// active only. Feeds the Courier/Carrier dropdown on Prepare Shipment
// and Courier Handover so a seller picks a real courier instead of
// typing an arbitrary name — same Bearer-token pattern useOrders.js and
// useSellerProducts.js already use, just for a much smaller, rarely-
// changing list, so it's fetched once and shared for the whole session.
import { ref } from 'vue';
import { getSupabase } from './useSeller';

const couriers = ref([]); // [{ id, name }]
const isLoadingCouriers = ref(false);
const loadError = ref('');

let loaded = false;
let inFlight = null;

async function authHeaders() {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Not signed in.');
    }

    return {
        Accept: 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

async function loadCouriers({ force = false } = {}) {
    if (inFlight) {
        return inFlight;
    }

    if (!force && loaded) {
        return;
    }

    isLoadingCouriers.value = true;
    loadError.value = '';

    inFlight = (async () => {
        try {
            const headers = await authHeaders();
            const response = await fetch('/api/seller/couriers', { headers });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(body.message || 'Request failed.');
            }

            couriers.value = Array.isArray(body.data) ? body.data : [];
            loaded = true;
        } catch (err) {
            console.error('Error loading couriers:', err);
            loadError.value = err?.message || 'Could not load couriers.';
        } finally {
            isLoadingCouriers.value = false;
            inFlight = null;
        }
    })();

    return inFlight;
}

export function useCouriers() {
    return {
        couriers,
        isLoadingCouriers,
        loadError,
        loadCouriers,
    };
}

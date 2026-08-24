// resources/js/buyer/composables/useBuyerSession.js
import { ref } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

// Lazy singleton — same pattern as resources/js/seller/composables/useSeller.js,
// to avoid a race with the Supabase CDN <script> tag in
// resources/views/buyer/dashboard.blade.php loading after this module runs.
let _supabase = null;
function getSupabase() {
    if (!_supabase) {
        if (!window.supabase) {
            throw new Error(
                'window.supabase is not defined. Make sure the Supabase CDN <script> tag ' +
                    'is present in the <head> of buyer/dashboard.blade.php, before @vite(...).',
            );
        }

        _supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
    }

    return _supabase;
}

export { getSupabase };

// Product browsing is public — buyers aren't forced to log in just to look
// around. buyerProfile is only populated once a session exists (e.g. after
// signing in on the auth page, which persists the Supabase session and
// carries over here). Cart/Checkout/Orders check this before proceeding.
const buyerProfile = ref(null);
const isLoadingSession = ref(true);

async function loadSession() {
    isLoadingSession.value = true;

    try {
        const supabase = getSupabase();
        const {
            data: { user },
        } = await supabase.auth.getUser();

        if (!user) {
            buyerProfile.value = null;
            return null;
        }

        const { data: profile, error } = await supabase
            .from('profiles')
            .select('*')
            .eq('id', user.id)
            .single();

        if (error || !profile || profile.role !== 'buyer') {
            buyerProfile.value = null;
            return null;
        }

        buyerProfile.value = profile;

        return profile;
    } catch (err) {
        console.error('Error loading buyer session:', err);
        buyerProfile.value = null;

        return null;
    } finally {
        isLoadingSession.value = false;
    }
}

// Bearer headers for our own Laravel API (checkout, orders) — same
// contract as resources/js/seller/composables/useOrders.js's authHeaders().
export async function authHeaders() {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Please sign in to continue.');
    }

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

/**
 * Shared authenticated-fetch helper for the Laravel Buyer API, used by
 * useBuyer.js (checkout/orders) and useBuyerAccount.js (profile/address/
 * deactivation) so the auth-header/JSON-parse/error-handling contract
 * lives in exactly one place instead of being copy-pasted per composable.
 *
 * `unwrapData` controls the response shape: useBuyer.js's endpoints wrap
 * their payload as `{ data: ... }` and only want that inner value, while
 * useBuyerAccount.js's endpoints return a flatter `{ message, profile,
 * address }` shape and want the whole parsed body.
 */
export async function apiFetch(path, options = {}, { unwrapData = false } = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Request failed.');
    }

    return unwrapData ? body.data : body;
}

export function useBuyerSession() {
    return {
        buyerProfile,
        isLoadingSession,
        loadSession,
        authHeaders,
    };
}
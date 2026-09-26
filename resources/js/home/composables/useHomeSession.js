import { ref, computed } from 'vue';
import { createClient } from '../../shared/backendClient';

/*
|--------------------------------------------------------------------------
| Homepage session state
|--------------------------------------------------------------------------
|
| The app's auth session lives in a plain cookie ('nexmart_session') set
| by resources/js/app.js after a Supabase login — see its handleLogin().
| This page has no Supabase-driven login form of its own, so it just
| reads that same cookie to decide what the header shows (Login/Register
| vs. an account menu) and reuses the same getSupabase()+signOut()
| pattern already used by the seller/logistics headers for the actual
| logout action.
|
*/

const SESSION_COOKIE = 'nexmart_session';

function getCookie(name) {
    const match = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');

    return match ? decodeURIComponent(match.pop()) : null;
}

function deleteCookie(name) {
    document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;`;
}

function readSession() {
    const raw = getCookie(SESSION_COOKIE);

    if (!raw) {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}


let _supabase = null;
function getSupabase() {
    if (!_supabase) {

        _supabase = createClient();
    }

    return _supabase;
}

const ROLE_DASHBOARDS = {
    admin: '/admin/dashboard',
    seller: '/seller/dashboard',
    logistics: '/logistics/dashboard',
    logistics_admin: '/logistics/dashboard',
    buyer: '/buyer/dashboard',
};

export function useHomeSession() {
    const session = ref(readSession());

    const isLoggedIn = computed(() => !!session.value?.email);

    // Couriers/drivers have no dedicated dashboard route yet in this
    // branch of the app — see the homepage delivery summary. They still
    // count as "logged in" for header display, just with no extra
    // "Go to my account" destination.
    const dashboardPath = computed(() => ROLE_DASHBOARDS[session.value?.role] ?? null);

    async function logout() {
        try {
            const supabase = getSupabase();
            await supabase.auth.signOut();
        } catch (err) {
            console.error('Logout error:', err);
        } finally {
            deleteCookie(SESSION_COOKIE);
            window.location.href = '/';
        }
    }

    return {
        session,
        isLoggedIn,
        dashboardPath,
        logout,
    };
}

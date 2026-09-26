// resources/js/logistics/logistics.js
import { getSupabase } from './composables/useLogistics';
import { fetchOwnProfile } from '../shared/accountApi';

// The logistics portal serves two apps: the logistics company dashboard and,
// for `logistics_admin` accounts, the logistics admin panel (the same admin
// SPA as /admin, scoped to couriers, drivers and logistics companies). Each
// bundle — and its stylesheet — only downloads for the role that needs it.
//
// A transient profile-fetch failure must not silently mount the company
// portal for a logistics_admin, so retry, then fall back to the role the
// auth session itself carries (it belongs to the same token).
async function resolveRole() {
    let session = null;

    try {
        const supabase = getSupabase();
        ({
            data: { session },
        } = await supabase.auth.getSession());

        if (!session?.user) {
            return null;
        }

        for (let attempt = 0; attempt < 3; attempt++) {
            const { data, error } = await fetchOwnProfile(supabase);

            if (data?.role) {
                return data.role;
            }

            if (error?.status === 401) {
                break;
            }

            await new Promise((resolve) => setTimeout(resolve, 300 * (attempt + 1)));
        }
    } catch {
        // fall through to the session's own role
    }

    return session?.user?.role ?? session?.user?.user_metadata?.role ?? null;
}

async function mount() {
    const { default: mountApp } =
        (await resolveRole()) === 'logistics_admin'
            ? await import('./mountLogisticsAdmin')
            : await import('./mountLogisticsPortal');

    mountApp('#app');
}

mount();

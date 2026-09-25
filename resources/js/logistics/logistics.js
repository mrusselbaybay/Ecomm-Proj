// resources/js/logistics/logistics.js
import { getSupabase } from './composables/useLogistics';

// The logistics portal serves two apps: the logistics company dashboard and,
// for `logistics_admin` accounts, the logistics admin panel (the same admin
// SPA as /admin, scoped to couriers, drivers and logistics companies). Each
// bundle — and its stylesheet — only downloads for the role that needs it.
async function resolveRole() {
    try {
        const supabase = getSupabase();
        const {
            data: { session },
        } = await supabase.auth.getSession();

        if (!session?.user) {
            return null;
        }

        const { data } = await supabase
            .from('profiles')
            .select('role')
            .eq('id', session.user.id)
            .single();

        return data?.role ?? null;
    } catch {
        return null;
    }
}

async function mount() {
    const { default: mountApp } =
        (await resolveRole()) === 'logistics_admin'
            ? await import('./mountLogisticsAdmin')
            : await import('./mountLogisticsPortal');

    mountApp('#app');
}

mount();

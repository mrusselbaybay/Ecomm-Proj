// resources/js/logistics/composables/useLogistics.js
import { ref } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

// Lazy singleton — created on first use, not at module-eval time.
// Avoids a race condition where this module runs before the
// window.supabase CDN script has finished loading.
let _supabase = null;
function getSupabase() {
    if (!_supabase) {
        if (!window.supabase) {
            throw new Error(
                'window.supabase is not defined. Make sure the Supabase CDN <script> tag ' +
                    'is present in the <head> of dashboard.blade.php, before @vite(...).',
            );
        }

        _supabase = window.supabase.createClient(
            SUPABASE_URL,
            SUPABASE_ANON_KEY,
        );
    }

    return _supabase;
}

// shared state across all logistics components
const companyId = ref(null);
const companyName = ref('');
const applications = ref([]);
const couriers = ref([]);
const pendingCount = ref(0);
const loadingCompany = ref(true);

async function resolveCompany() {
    const supabase = getSupabase();
    loadingCompany.value = true;

    try {
        const { data: userData } = await supabase.auth.getUser();
        const uid = userData?.user?.id;

        if (!uid) {
            return null;
        }

        const { data: owned } = await supabase
            .from('logistics_companies')
            .select('id, company_name')
            .eq('owner_profile_id', uid)
            .maybeSingle();

        if (owned) {
            companyId.value = owned.id;
            companyName.value = owned.company_name;

            return owned.id;
        }

        const { data: staff } = await supabase
            .from('logistics_admin_details')
            .select('logistics_company_id, logistics_companies(company_name)')
            .eq('profile_id', uid)
            .maybeSingle();

        if (staff) {
            companyId.value = staff.logistics_company_id;
            companyName.value = staff.logistics_companies?.company_name || '';

            return staff.logistics_company_id;
        }

        return null;
    } finally {
        loadingCompany.value = false;
    }
}

async function loadApplications(filters = {}) {
    const supabase = getSupabase();

    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        return;
    }

    let query = supabase
        .from('courier_applications')
        .select(
            `
      *,
      courier:profiles!courier_applications_courier_profile_id_fkey (
        id, first_name, last_name, email, contact_no,
        courier_details ( vehicle, plate_number )
      )
    `,
        )
        .eq('logistics_company_id', companyId.value)
        .order('applied_at', { ascending: false });

    if (filters.status) {
        query = query.eq('status', filters.status);
    }

    if (filters.search) {
        query = query.or(
            `courier.first_name.ilike.%${filters.search}%,courier.last_name.ilike.%${filters.search}%,courier.email.ilike.%${filters.search}%`,
        );
    }

    const { data, error } = await query;

    if (error) {
        throw error;
    }

    applications.value = data || [];
    pendingCount.value = applications.value.filter(
        (a) => a.status === 'pending',
    ).length;

    return applications.value;
}

async function loadCouriers() {
    const supabase = getSupabase();

    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        return;
    }

    const { data, error } = await supabase
        .from('courier_details')
        .select(
            '*, profile:profiles!courier_details_profile_id_fkey(id, first_name, last_name, email, contact_no)',
        )
        .eq('logistics_company_id', companyId.value);

    if (error) {
        throw error;
    }

    couriers.value = data || [];

    return couriers.value;
}

export function useLogistics() {
    return {
        supabase: getSupabase(),
        companyId,
        companyName,
        applications,
        couriers,
        pendingCount,
        loadingCompany,
        resolveCompany,
        loadApplications,
        loadCouriers,
    };
}

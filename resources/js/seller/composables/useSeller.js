// resources/js/seller/composables/useSeller.js
import { ref, computed } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

// Lazy singleton — created on first use, not at module-eval time.
// Avoids a race condition where this module runs before the
// window.supabase CDN script has finished loading (same pattern as
// resources/js/logistics/composables/useLogistics.js).
let _supabase = null;
function getSupabase() {
    if (!_supabase) {
        if (!window.supabase) {
            throw new Error(
                'window.supabase is not defined. Make sure the Supabase CDN <script> tag ' +
                    'is present in the <head> of seller/dashboard.blade.php, before @vite(...).',
            );
        }

        _supabase = window.supabase.createClient(
            SUPABASE_URL,
            SUPABASE_ANON_KEY,
        );
    }

    return _supabase;
}

// Exported so other seller composables (e.g. useSellerProducts.js) reuse this
// exact singleton instead of each creating their own Supabase client.
export { getSupabase };

// ---- shared state across all seller components ----
const isLoading = ref(true);
const isAuthenticated = ref(false);
const isSeller = ref(false);
const sellerUser = ref(null);

const profile = ref(null); // row from public.profiles
const address = ref(null); // row from public.addresses
const sellerDetails = ref(null); // row from public.seller_details
const documents = ref([]); // rows from public.documents
const activityLog = ref([]); // rows from public.status_audit_log

const savingProfile = ref(false);
const saveError = ref('');
const saveSuccess = ref('');

const LINE_OF_BUSINESS_OPTIONS = [
    'Pet Supplies',
    'Kids and Baby',
    'Electronics and Gadgets',
    'House and Garden',
    "Woman's Apparel",
    "Men's Apparel",
    'Sports and Outdoors',
    'Health and Beauty',
];

const fullName = computed(() => {
    if (!profile.value) {
        return 'Seller';
    }

    const mi = profile.value.middle_initial
        ? ` ${profile.value.middle_initial}.`
        : '';

    return (
        `${profile.value.first_name || ''}${mi} ${profile.value.last_name || ''}`.trim() ||
        'Seller'
    );
});

const initials = computed(() => {
    const f = profile.value?.first_name?.[0] || '';
    const l = profile.value?.last_name?.[0] || '';

    return (f + l).toUpperCase() || 'SE';
});

const age = computed(() => {
    const bday = profile.value?.birthday;

    if (!bday) {
        return null;
    }

    const b = new Date(bday);

    if (Number.isNaN(b.getTime())) {
        return null;
    }

    const today = new Date();
    let years = today.getFullYear() - b.getFullYear();
    const monthDiff = today.getMonth() - b.getMonth();

    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < b.getDate())) {
        years--;
    }

    return years;
});

const verifiedDocsCount = computed(
    () => documents.value.filter((d) => d.status === 'approved').length,
);
const pendingDocsCount = computed(
    () => documents.value.filter((d) => d.status === 'pending').length,
);
const totalDocsCount = computed(() => documents.value.length);

async function checkAuth() {
    isLoading.value = true;

    try {
        const supabase = getSupabase();
        const {
            data: { user },
            error,
        } = await supabase.auth.getUser();

        if (error || !user) {
            window.location.href = '/';

            return;
        }

        const { data: prof, error: profileError } = await supabase
            .from('profiles')
            .select('*')
            .eq('id', user.id)
            .single();

        if (profileError || !prof || prof.role !== 'seller') {
            window.location.href = '/';

            return;
        }

        sellerUser.value = user;
        profile.value = prof;
        isAuthenticated.value = true;
        isSeller.value = true;
    } catch (err) {
        console.error('Seller auth error:', err);
        window.location.href = '/';
    } finally {
        isLoading.value = false;
    }
}

async function loadProfileData() {
    if (!sellerUser.value) {
        return;
    }

    const supabase = getSupabase();
    const uid = sellerUser.value.id;

    const [{ data: addr }, { data: details }, { data: docs }] =
        await Promise.all([
            supabase
                .from('addresses')
                .select('*')
                .eq('profile_id', uid)
                .eq('owner_kind', 'profile')
                .maybeSingle(),
            supabase
                .from('seller_details')
                .select('*')
                .eq('profile_id', uid)
                .maybeSingle(),
            supabase
                .from('documents')
                .select('*')
                .eq('profile_id', uid)
                .order('created_at', { ascending: false }),
        ]);

    address.value = addr || null;
    sellerDetails.value = details || null;
    documents.value = docs || [];
}

async function loadActivityLog() {
    if (!sellerUser.value) {
        return;
    }

    const supabase = getSupabase();
    const { data, error } = await supabase
        .from('status_audit_log')
        .select('*')
        .eq('entity_type', 'profile')
        .eq('entity_id', sellerUser.value.id)
        .order('created_at', { ascending: false })
        .limit(8);

    if (!error) {
        activityLog.value = data || [];
    }
}

async function refreshAll() {
    await Promise.all([loadProfileData(), loadActivityLog()]);
}

async function saveProfile(payload) {
    savingProfile.value = true;
    saveError.value = '';
    saveSuccess.value = '';

    try {
        const supabase = getSupabase();
        const uid = sellerUser.value.id;

        const { error: profileErr } = await supabase
            .from('profiles')
            .update({
                last_name: payload.last_name,
                first_name: payload.first_name,
                middle_initial: payload.middle_initial,
                sex: payload.sex,
                contact_no: payload.contact_no,
                birthday: payload.birthday,
                updated_at: new Date().toISOString(),
            })
            .eq('id', uid);

        if (profileErr) {
            throw profileErr;
        }

        if (address.value?.id) {
            const { error: addrErr } = await supabase
                .from('addresses')
                .update({
                    province_code: payload.province_code,
                    province_name: payload.province_name,
                    municipality_code: payload.municipality_code,
                    municipality_name: payload.municipality_name,
                    barangay: payload.barangay,
                    street: payload.street,
                    house_no: payload.house_no,
                    updated_at: new Date().toISOString(),
                })
                .eq('id', address.value.id);

            if (addrErr) {
                throw addrErr;
            }
        } else {
            const { error: addrInsertErr } = await supabase
                .from('addresses')
                .insert({
                    owner_kind: 'profile',
                    profile_id: uid,
                    province_code: payload.province_code,
                    province_name: payload.province_name,
                    municipality_code: payload.municipality_code,
                    municipality_name: payload.municipality_name,
                    barangay: payload.barangay,
                    street: payload.street,
                    house_no: payload.house_no,
                });

            if (addrInsertErr) {
                throw addrInsertErr;
            }
        }

        const { error: detailsErr } = await supabase
            .from('seller_details')
            .update({
                business_name: payload.business_name,
                line_of_business: payload.line_of_business,
                updated_at: new Date().toISOString(),
            })
            .eq('profile_id', uid);

        if (detailsErr) {
            throw detailsErr;
        }

        await refreshAll();
        const { data: prof } = await supabase
            .from('profiles')
            .select('*')
            .eq('id', uid)
            .single();

        if (prof) {
            profile.value = prof;
        }

        saveSuccess.value = 'Profile updated successfully.';
    } catch (err) {
        console.error('Error saving seller profile:', err);
        saveError.value =
            err?.message || 'Something went wrong while saving your profile.';
    } finally {
        savingProfile.value = false;
    }
}

async function confirmLogout() {
    try {
        const supabase = getSupabase();
        await supabase.auth.signOut();
        window.location.href = '/';
    } catch (err) {
        console.error('Logout error:', err);
        window.location.href = '/';
    }
}

function formatDate(date) {
    if (!date) {
        return 'N/A';
    }

    const d = new Date(date);

    if (Number.isNaN(d.getTime())) {
        return 'N/A';
    }

    return d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatDateTime(date) {
    if (!date) {
        return 'N/A';
    }

    const d = new Date(date);

    if (Number.isNaN(d.getTime())) {
        return 'N/A';
    }

    return d.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function docTypeLabel(type) {
    const labels = {
        valid_id: 'Valid ID',
        business_permit: 'Business Permit',
        drivers_license: "Driver's License",
        orcr: 'OR/CR',
    };

    return labels[type] || type;
}

function statusBadgeClass(status) {
    const s = (status || '').toLowerCase();

    if (['active', 'approved'].includes(s)) {
        return 'badge-teal';
    }

    if (['pending'].includes(s)) {
        return 'badge-amber';
    }

    if (['deactivated', 'rejected', 'suspended'].includes(s)) {
        return 'badge-red';
    }

    return 'badge-slate';
}

export function useSeller() {
    return {
        isLoading,
        isAuthenticated,
        isSeller,
        sellerUser,
        profile,
        address,
        sellerDetails,
        documents,
        activityLog,
        savingProfile,
        saveError,
        saveSuccess,
        LINE_OF_BUSINESS_OPTIONS,
        fullName,
        initials,
        age,
        verifiedDocsCount,
        pendingDocsCount,
        totalDocsCount,
        checkAuth,
        loadProfileData,
        loadActivityLog,
        refreshAll,
        saveProfile,
        confirmLogout,
        formatDate,
        formatDateTime,
        docTypeLabel,
        statusBadgeClass,
    };
}

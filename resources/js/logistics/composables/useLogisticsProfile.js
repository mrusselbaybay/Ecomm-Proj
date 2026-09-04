// resources/js/logistics/composables/useLogisticsProfile.js
//
// Backs the logistics portal's Account Settings page (AccountSettings.vue).
// Talks to Supabase straight from the browser under the signed-in
// logistics owner's session + RLS — the same approach the seller account
// page (resources/js/seller/composables/useSeller.js) uses, rather than
// going through a Laravel endpoint.
import { ref, computed } from 'vue';
import { getSupabase } from './useLogistics';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;

// ---- shared state across all Account Settings sub-components ----
const profile = ref(null); // row from public.profiles (the owner)
const address = ref(null); // row from public.addresses (owner_kind = 'logistics_company')
const company = ref(null); // row from public.logistics_companies

const savingProfile = ref(false);
const saveError = ref('');
const saveSuccess = ref('');

// Same three-way island-group split the logistics signup form uses.
const REGION_OPTIONS = ['Luzon', 'Visayas', 'Mindanao'];

const fullName = computed(() => {
    if (!profile.value) {
        return 'Logistics Owner';
    }

    const mi = profile.value.middle_initial
        ? ` ${profile.value.middle_initial}.`
        : '';

    return (
        `${profile.value.first_name || ''}${mi} ${profile.value.last_name || ''}`.trim() ||
        'Logistics Owner'
    );
});

const initials = computed(() => {
    const f = profile.value?.first_name?.[0] || '';
    const l = profile.value?.last_name?.[0] || '';

    return (f + l).toUpperCase() || 'LG';
});

// `avatars` is a public bucket, so this is a stable URL. Mirrors
// useSeller.js avatarUrl.
const avatarUrl = computed(() => {
    if (!profile.value?.avatar_path) {
        return null;
    }

    return `${SUPABASE_URL}/storage/v1/object/public/avatars/${profile.value.avatar_path}`;
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

/**
 * Loads the owner profile, the company row and its on-file address. The
 * layout (LogisticsLayout.vue) has already verified the session + role by
 * the time this runs, but we re-read the full profile here because
 * useLogistics.checkAuth() only selects a handful of columns — not enough
 * for an editable settings form.
 */
async function loadProfileData() {
    const supabase = getSupabase();

    const {
        data: { user },
        error: userError,
    } = await supabase.auth.getUser();

    if (userError || !user) {
        return;
    }

    const uid = user.id;

    const [{ data: prof }, { data: comp }] = await Promise.all([
        supabase.from('profiles').select('*').eq('id', uid).single(),
        supabase
            .from('logistics_companies')
            .select('*')
            .eq('owner_profile_id', uid)
            .maybeSingle(),
    ]);

    profile.value = prof || null;
    company.value = comp || null;

    if (!comp?.id) {
        address.value = null;

        return;
    }

    const { data: addr } = await supabase
        .from('addresses')
        .select('*')
        .eq('logistics_company_id', comp.id)
        .eq('owner_kind', 'logistics_company')
        .maybeSingle();

    address.value = addr || null;
}

async function refreshAll() {
    await loadProfileData();
}

/**
 * Persists the personal-info, company-detail (incl. the courier-hiring
 * fields) and address edits in one action. Mirrors useSeller.saveProfile.
 */
async function saveProfile(payload) {
    savingProfile.value = true;
    saveError.value = '';
    saveSuccess.value = '';

    try {
        const supabase = getSupabase();
        const uid = profile.value.id;

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

        if (company.value?.id) {
            const { error: companyErr } = await supabase
                .from('logistics_companies')
                .update({
                    company_name: payload.company_name,
                    company_email: payload.company_email,
                    company_contact_no: payload.company_contact_no,
                    region: payload.region,
                    description: payload.description,
                    // Empty input -> NULL rather than 0, so "no salary set"
                    // and "offering ₱0" stay distinguishable.
                    monthly_salary:
                        payload.monthly_salary === '' ||
                        payload.monthly_salary === null
                            ? null
                            : Number(payload.monthly_salary),
                    is_hiring: !!payload.is_hiring,
                    updated_at: new Date().toISOString(),
                })
                .eq('id', company.value.id);

            if (companyErr) {
                throw companyErr;
            }

            const addressPayload = {
                province_code: payload.province_code,
                province_name: payload.province_name,
                municipality_code: payload.municipality_code,
                municipality_name: payload.municipality_name,
                barangay: payload.barangay,
                street: payload.street,
                house_no: payload.house_no,
            };

            if (address.value?.id) {
                const { error: addrErr } = await supabase
                    .from('addresses')
                    .update({
                        ...addressPayload,
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
                        owner_kind: 'logistics_company',
                        logistics_company_id: company.value.id,
                        ...addressPayload,
                    });

                if (addrInsertErr) {
                    throw addrInsertErr;
                }
            }
        }

        await refreshAll();

        saveSuccess.value = 'Account updated successfully.';
    } catch (err) {
        console.error('Error saving logistics account:', err);
        saveError.value =
            err?.message || 'Something went wrong while saving your account.';
    } finally {
        savingProfile.value = false;
    }
}

/**
 * Uploads/replaces the owner's profile picture directly via the Supabase
 * client. Fresh uniquely-timestamped path per upload (not a fixed path +
 * upsert) to sidestep the known Postgres RLS rough edge with
 * INSERT ... ON CONFLICT DO UPDATE — identical to useSeller.uploadAvatar.
 */
async function uploadAvatar(file) {
    if (!profile.value || !file) {
        return false;
    }

    savingProfile.value = true;
    saveError.value = '';

    try {
        const supabase = getSupabase();
        const uid = profile.value.id;
        const path = `${uid}/avatar_${Date.now()}.jpg`;

        const { error: uploadErr } = await supabase.storage
            .from('avatars')
            .upload(path, file, { contentType: 'image/jpeg' });

        if (uploadErr) {
            throw uploadErr;
        }

        const { error: updateErr } = await supabase
            .from('profiles')
            .update({ avatar_path: path, updated_at: new Date().toISOString() })
            .eq('id', uid);

        if (updateErr) {
            throw updateErr;
        }

        profile.value = { ...profile.value, avatar_path: path };

        return true;
    } catch (err) {
        console.error('Error uploading logistics avatar:', err);
        saveError.value = err?.message || 'Failed to upload profile picture.';

        return false;
    } finally {
        savingProfile.value = false;
    }
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

export function useLogisticsProfile() {
    return {
        profile,
        address,
        company,
        savingProfile,
        saveError,
        saveSuccess,
        REGION_OPTIONS,
        fullName,
        initials,
        age,
        avatarUrl,
        loadProfileData,
        refreshAll,
        saveProfile,
        uploadAvatar,
        statusBadgeClass,
    };
}

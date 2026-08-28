import { computed } from 'vue';
import { useBuyerSession, getSupabase } from './useBuyerSession';

/*
|--------------------------------------------------------------------------
| Buyer Account
|--------------------------------------------------------------------------
|
| Was previously a second, unrelated `buyerProfile` ref hardcoded to fake
| data ("Juan Dela Cruz", buyer@nexmart.test, ...) — Account.vue rendered
| that instead of whoever was actually signed in. This now wraps the ONE
| real buyerProfile singleton from useBuyerSession.js (populated from
| Supabase Auth + a real `profiles` row — see loadSession() there), so
| there's a single source of truth instead of two.
|
| Writes go straight from the browser to Supabase, same as sellers already
| do for their own products (see useSellerProducts.js) — not through a
| Laravel endpoint. This assumes an UPDATE RLS policy on public.profiles
| letting a signed-in buyer update their own row (auth.uid() = id), mirroring
| whatever SELECT policy already lets loadSession() read it; if that policy
| doesn't exist yet, updateBuyerProfile()/changePassword() below will
| surface Supabase's real error message rather than silently no-op'ing.
|
| Column names match the real `profiles` table (see the schema this app
| was built from): first_name, middle_initial, last_name, sex, contact_no,
| birthday, email, account_status — snake_case, not the old mock's
| camelCase invented field names.
|
| `sex` keeps the same option set the previous mock UI used
| (Male/Female/Prefer not to say) since the actual Postgres enum's exact
| values aren't visible from here — if the real enum uses different
| casing/values, the <select> in Account.vue is the one place to fix it.
|
| Email is intentionally read-only here: it lives in both `profiles.email`
| and Supabase Auth's own user record, and only Auth's copy is what's
| actually used to sign in. Editing it safely means Supabase's email-change
| confirmation flow (a verification link to the new address), not a plain
| profile field update — out of scope for this pass, so it's surfaced as
| account info, not an editable field.
|
*/

const { buyerProfile, isLoadingSession } = useBuyerSession();

function calculateAge(birthday) {
    if (!birthday) {
        return null;
    }

    const birthDate = new Date(`${birthday}T00:00:00`);

    if (Number.isNaN(birthDate.getTime())) {
        return null;
    }

    const today = new Date();
    let age = today.getFullYear() - birthDate.getFullYear();

    const monthDifference = today.getMonth() - birthDate.getMonth();

    if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
        age--;
    }

    return age >= 0 ? age : null;
}

const buyerFullName = computed(() => {
    if (!buyerProfile.value) {
        return '';
    }

    const middle = buyerProfile.value.middle_initial
        ? `${buyerProfile.value.middle_initial}.`
        : '';

    return [buyerProfile.value.first_name, middle, buyerProfile.value.last_name]
        .filter(Boolean)
        .join(' ');
});

const buyerInitials = computed(() => {
    if (!buyerProfile.value) {
        return '';
    }

    const firstInitial = buyerProfile.value.first_name?.charAt(0) || '';
    const lastInitial = buyerProfile.value.last_name?.charAt(0) || '';

    return `${firstInitial}${lastInitial}`.toUpperCase() || 'BU';
});

const buyerAge = computed(() => calculateAge(buyerProfile.value?.birthday));

/*
|--------------------------------------------------------------------------
| Update Profile
|--------------------------------------------------------------------------
|
| Accepts the same camelCase draft shape Account.vue's form already
| collects (firstName, middleInitial, lastName, sex, contactNumber,
| birthday) and maps it onto the real columns. Returns { data, error } —
| Account.vue is responsible for showing `error` rather than assuming success.
*/
async function updateBuyerProfile(draft) {
    if (!buyerProfile.value?.id) {
        return { data: null, error: 'You need to be signed in to update your profile.' };
    }

    const supabase = getSupabase();

    const { data, error } = await supabase
        .from('profiles')
        .update({
            first_name: draft.firstName?.trim() || null,
            middle_initial: draft.middleInitial
                ? draft.middleInitial.trim().charAt(0).toUpperCase()
                : null,
            last_name: draft.lastName?.trim() || null,
            sex: draft.sex || null,
            contact_no: draft.contactNumber?.trim() || null,
            birthday: draft.birthday || null,
        })
        .eq('id', buyerProfile.value.id)
        .select()
        .single();

    if (error) {
        console.error('Error updating buyer profile:', error);

        return { data: null, error: error.message || 'Could not save your changes.' };
    }

    buyerProfile.value = data;

    return { data, error: null };
}

/*
|--------------------------------------------------------------------------
| Change Password
|--------------------------------------------------------------------------
|
| Supabase Auth's own updateUser() call — no Laravel endpoint needed, this
| operates on the buyer's already-authenticated session directly.
*/
async function changePassword(newPassword) {
    const supabase = getSupabase();
    const { error } = await supabase.auth.updateUser({ password: newPassword });

    if (error) {
        console.error('Error changing password:', error);

        return { error: error.message || 'Could not change your password.' };
    }

    return { error: null };
}

export function useBuyerAccount() {
    return {
        buyerProfile,
        isLoadingSession,
        buyerFullName,
        buyerInitials,
        buyerAge,
        calculateAge,
        updateBuyerProfile,
        changePassword,
    };
}
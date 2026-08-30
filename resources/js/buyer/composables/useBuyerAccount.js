
import { computed, ref } from 'vue';
import { apiFetch as sharedApiFetch, getSupabase } from './useBuyerSession';

/*
|--------------------------------------------------------------------------
| Shared Buyer Account State
|--------------------------------------------------------------------------
|
| Backed by the Laravel Buyer API (routes/buyer.php + App\Http\Controllers\
| Buyer\BuyerProfileController), the same pattern useBuyer.js already uses
| for checkout/orders: every request forwards the current Supabase access
| token as a Bearer header (see useBuyerSession.js).
|
| profile/address are refs outside the composable so edits made on the
| Account page stay visible while the Buyer switches views.
|
*/

const profile = ref(null); // public.profiles row (snake_case, as returned by the API)
const address = ref(null); // public.addresses row (owner_kind = 'profile'), or null if none saved yet

const isLoadingProfile = ref(false);
const loadError = ref('');

const isSaving = ref(false);
const saveError = ref('');
const saveSuccess = ref('');

const isDeactivating = ref(false);
const deactivateError = ref('');

function apiFetch(path, options = {}) {
    return sharedApiFetch(`/buyer${path}`, options);
}

function calculateAge(birthday) {
    if (!birthday) {
        return null;
    }

    const birthDate = new Date(
        `${birthday}T00:00:00`
    );

    if (Number.isNaN(birthDate.getTime())) {
        return null;
    }

    const today = new Date();
    let age =
        today.getFullYear() -
        birthDate.getFullYear();

    const monthDifference =
        today.getMonth() -
        birthDate.getMonth();

    if (
        monthDifference < 0 ||
        (
            monthDifference === 0 &&
            today.getDate() < birthDate.getDate()
        )
    ) {
        age--;
    }

    return age >= 0 ? age : null;
}

const buyerFullName = computed(() => {
    if (!profile.value) {
        return 'Buyer';
    }

    const middle = profile.value.middle_initial
        ? `${profile.value.middle_initial}.`
        : '';

    return [
        profile.value.first_name,
        middle,
        profile.value.last_name
    ]
        .filter(Boolean)
        .join(' ') || 'Buyer';
});

const buyerInitials = computed(() => {
    const firstInitial =
        profile.value?.first_name?.charAt(0) ||
        '';

    const lastInitial =
        profile.value?.last_name?.charAt(0) ||
        '';

    return `${firstInitial}${lastInitial}`
        .toUpperCase() ||
        'BU';
});

const buyerAge = computed(() => {
    return calculateAge(profile.value?.birthday);
});

/**
 * GET /api/buyer/profile — loads this buyer's profile + on-file address.
 */
async function loadBuyerAccount() {
    isLoadingProfile.value = true;
    loadError.value = '';

    try {
        const body = await apiFetch('/profile');

        profile.value = body.profile;
        address.value = body.address;

        return profile.value;
    } catch (err) {
        console.error('Error loading buyer account:', err);
        loadError.value = err?.message || 'Something went wrong while loading your account.';

        return null;
    } finally {
        isLoadingProfile.value = false;
    }
}

/**
 * PUT /api/buyer/profile — saves personal info + address in one request.
 * `payload` is the flat draft object built by Account.vue (snake_case
 * keys matching UpdateBuyerProfileRequest's validated fields).
 */
async function updateBuyerProfile(payload) {
    if (!payload) {
        return null;
    }

    isSaving.value = true;
    saveError.value = '';
    saveSuccess.value = '';

    try {
        const body = await apiFetch('/profile', {
            method: 'PUT',
            body: JSON.stringify(payload)
        });

        profile.value = body.profile;
        address.value = body.address;
        saveSuccess.value = body.message || 'Your profile was updated successfully.';

        return profile.value;
    } catch (err) {
        console.error('Error saving buyer profile:', err);
        saveError.value = err?.message || 'Something went wrong while saving your profile.';

        return null;
    } finally {
        isSaving.value = false;
    }
}

/**
 * POST /api/buyer/profile/avatar — uploads/replaces the profile picture.
 * Doesn't go through the shared apiFetch()/authHeaders() helpers since
 * those hardcode `Content-Type: application/json`, which breaks a
 * multipart FormData upload (the browser needs to set that header itself,
 * with the boundary, for the file to actually parse server-side).
 *
 * `file` may be a real File (a name of its own) or a plain Blob — the
 * cropped image AvatarCropper.vue hands back via canvas.toBlob() has no
 * filename, so one is always supplied explicitly to FormData here rather
 * than relying on `file.name`.
 */
async function uploadBuyerAvatar(file) {
    isSaving.value = true;
    saveError.value = '';

    try {
        const supabase = getSupabase();
        const {
            data: { session }
        } = await supabase.auth.getSession();
        const token = session?.access_token;

        if (!token) {
            throw new Error('Please sign in to continue.');
        }

        const formData = new FormData();
        formData.append('avatar', file, 'avatar.jpg');

        const response = await fetch('/api/buyer/profile/avatar', {
            method: 'POST',
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
            body: formData
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.message || 'Failed to upload profile picture.');
        }

        if (profile.value) {
            profile.value = { ...profile.value, avatar_url: body.avatar_url };
        }

        return true;
    } catch (err) {
        console.error('Error uploading avatar:', err);
        saveError.value = err?.message || 'Failed to upload profile picture.';

        return false;
    } finally {
        isSaving.value = false;
    }
}

/**
 * DELETE /api/buyer/account/deactivate — the "Danger Zone" self-
 * deactivation flow. Requires the typed "DEACTIVATE" phrase and the
 * buyer's current password (re-verified server-side either way).
 */
async function deactivateBuyerAccount(confirmationPhrase, password) {
    isDeactivating.value = true;
    deactivateError.value = '';

    try {
        const body = await apiFetch('/account/deactivate', {
            method: 'DELETE',
            body: JSON.stringify({
                confirmation_phrase: confirmationPhrase,
                password
            })
        });

        return body;
    } catch (err) {
        deactivateError.value = err?.message || 'Deactivation failed. Please try again.';

        throw err;
    } finally {
        isDeactivating.value = false;
    }
}

/**
 * Ends the Supabase session and returns to the landing page — used after a
 * successful self-deactivation, same as useSeller.js's confirmLogout().
 */
async function confirmLogout() {
    try {
        const supabase = getSupabase();
        await supabase.auth.signOut();
    } catch (err) {
        console.error('Logout error:', err);
    } finally {
        // The login page reads this cookie to auto-redirect signed-in
        // visitors back to their dashboard. Leaving it behind after
        // sign-out sent people straight back to /buyer/dashboard with no
        // real session, which showed "Access Denied" before they could
        // reach the login form.
        document.cookie =
            'nexmart_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
        window.location.href = '/';
    }
}

export function useBuyerAccount() {
    return {
        profile,
        address,
        isLoadingProfile,
        loadError,
        isSaving,
        saveError,
        saveSuccess,
        isDeactivating,
        deactivateError,

        buyerFullName,
        buyerInitials,
        buyerAge,
        calculateAge,

        loadBuyerAccount,
        updateBuyerProfile,
        uploadBuyerAvatar,
        deactivateBuyerAccount,
        confirmLogout
    };
}

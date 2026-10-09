// resources/js/buyer/composables/useAccountSettings.js
//
// Profile photo, email preferences and data export, backed by
// App\Http\Controllers\Buyer\AccountSettingsController
// (/api/buyer/account/avatar|preferences|export). Module-level state so
// switching account sections doesn't refetch what's already loaded.
import { computed, ref } from 'vue';
import { buyerApi } from './useBuyerApi';
import { getSupabase, useBuyerSession } from './useBuyerSession';

const { buyerProfile } = useBuyerSession();


export const AVATAR_RULES = {
    mimes: ['image/jpeg', 'image/png', 'image/webp'],
    maxBytes: 2 * 1024 * 1024,
    minSize: 128
};

/** Public URL for the signed-in buyer's photo, or '' for the initials fallback. */
const avatarUrl = computed(() => {
    const profile = buyerProfile.value;

    if (!profile) {
        return '';
    }

    if (profile.avatar_url !== undefined) {
        return profile.avatar_url || '';
    }

    const path = (profile.avatar_path || '').trim();

    if (!path) {
        return '';
    }

    return /^https?:\/\//.test(path) ? path : `/storage/avatars/${path.split('/').map(encodeURIComponent).join('/')}`;
});

async function bearer() {
    const { data: { session } } = await getSupabase().auth.getSession();

    if (!session?.access_token) {
        const error = new Error('Your session has ended. Please sign in again.');
        error.status = 401;

        throw error;
    }

    return session.access_token;
}

async function uploadAvatar(file) {
    const form = new FormData();
    form.append('avatar', file);

    const response = await fetch('/api/buyer/account/avatar', {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${await bearer()}` },
        body: form
    });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(body.errors?.avatar?.[0] || body.message || 'Could not upload your photo.');
        error.status = response.status;

        throw error;
    }

    buyerProfile.value = { ...buyerProfile.value, avatar_url: body.data?.avatar_url || null };

    return body.data;
}

async function removeAvatar() {
    const data = await buyerApi('/buyer/account/avatar', { method: 'DELETE' });

    buyerProfile.value = { ...buyerProfile.value, avatar_url: null, avatar_path: null };

    return data;
}

const preferences = ref(null);
const preferencesError = ref('');
let preferencesRequest = null;

function loadPreferences({ force = false } = {}) {
    if (preferences.value && !force) {
        return Promise.resolve(preferences.value);
    }

    if (!preferencesRequest) {
        preferencesError.value = '';
        preferencesRequest = buyerApi('/buyer/account/preferences')
            .then((data) => {
                preferences.value = data;

                return data;
            })
            .catch((err) => {
                preferencesError.value = err?.message || 'Could not load your preferences.';

                throw err;
            })
            .finally(() => {
                preferencesRequest = null;
            });
    }

    return preferencesRequest;
}

async function savePreferences(patch) {
    const data = await buyerApi('/buyer/account/preferences', {
        method: 'PUT',
        body: JSON.stringify(patch)
    });

    preferences.value = data;

    return data;
}

/** Downloads the buyer's data export as a JSON file. */
async function downloadMyData() {
    const response = await fetch('/api/buyer/account/export', {
        headers: { Accept: 'application/json', Authorization: `Bearer ${await bearer()}` }
    });

    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const error = new Error(body.message || 'Could not prepare your data. Please try again.');
        error.status = response.status;

        throw error;
    }

    const disposition = response.headers.get('Content-Disposition') || '';
    const name = /filename="([^"]+)"/.exec(disposition)?.[1] || 'buytheway-my-data.json';
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');

    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);

    return name;
}

export function useAccountSettings() {
    return {
        avatarUrl,
        uploadAvatar,
        removeAvatar,
        preferences,
        preferencesError,
        loadPreferences,
        savePreferences,
        downloadMyData
    };
}

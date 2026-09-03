const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

let client = null;

function getClient() {
    if (!client) {
        if (!window.supabase) {
            throw new Error(
                'The authentication client is unavailable. Refresh the page and try again.',
            );
        }

        client = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
    }

    return client;
}

async function accessToken() {
    const supabase = getClient();
    let { data, error } = await supabase.auth.getSession();

    if (error || !data.session?.access_token) {
        ({ data, error } = await supabase.auth.refreshSession());
    }

    if (error || !data.session?.access_token) {
        throw new Error('Your session has expired. Please sign in again.');
    }

    return data.session.access_token;
}

export async function customerServiceApi(path, options = {}) {
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('Authorization', `Bearer ${await accessToken()}`);

    if (options.body && !(options.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }

    const response = await fetch(path, { ...options, headers });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {})[0]?.[0];
        const error = new Error(
            validationMessage || payload.message || 'The request failed.',
        );
        error.status = response.status;
        error.payload = payload;
        throw error;
    }

    return payload;
}

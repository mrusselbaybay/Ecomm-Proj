// Authenticated calls to the Laravel API for data that used to be read/written
// straight from Supabase tables. Resolves to the same `{ data, error }` shape
// as the Supabase query builder so call sites stay unchanged in structure.
// Supabase is still used for auth + storage; the token is forwarded as Bearer.

async function accessToken(supabase) {
    let { data } = await supabase.auth.getSession();

    if (!data.session?.access_token) {
        ({ data } = await supabase.auth.refreshSession());
    }

    return data.session?.access_token || null;
}

export async function apiRequest(supabase, path, { method = 'GET', body } = {}) {
    try {
        const token = await accessToken(supabase);

        if (!token) {
            return { data: null, error: Object.assign(new Error('Your session has expired. Please sign in again.'), { status: 401 }) };
        }

        const response = await fetch(path, {
            method,
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message =
                Object.values(payload.errors || {})[0]?.[0] || payload.message || 'The request failed.';
            const error = Object.assign(new Error(message), { status: response.status, payload });

            // Signed in, but no profile row yet — same meaning as PostgREST's "no rows".
            if (response.status === 401 && /no profile/i.test(payload.message || '')) {
                error.code = 'PGRST116';
            }

            return { data: null, error };
        }

        return { data: payload.data !== undefined ? payload.data : payload, error: null };
    } catch (error) {
        return { data: null, error };
    }
}

/** The signed-in user's own profiles row. */
export function fetchOwnProfile(supabase) {
    return apiRequest(supabase, '/api/account/profile');
}

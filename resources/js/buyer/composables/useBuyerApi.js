// resources/js/buyer/composables/useBuyerApi.js
//
// One place for talking to the authenticated buyer API (everything under
// /api/buyer/*). Every request forwards the current Supabase access token
// as a Bearer header — the same contract routes/buyer.php's
// 'supabase.auth' middleware expects (see AuthenticateSupabaseUser). This
// replaces the copy of this logic that used to live inside useBuyer.js.
import { authHeaders } from './useBuyerSession';

/**
 * Calls /api<path> with the buyer's bearer token attached and returns the
 * parsed `data` property of the JSON body. Throws Error(message) on a
 * non-2xx response so callers can surface `err.message` directly.
 *
 * @param {string} path  e.g. '/buyer/addresses' (the leading /api is added)
 * @param {RequestInit} options
 * @returns {Promise<any>} the response body's `data` (or the whole body if absent)
 */
export async function buyerApi(path, options = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const error = new Error(body.message || 'Request failed.');
        error.status = response.status;
        error.body = body;

        throw error;
    }

    return body.data !== undefined ? body.data : body;
}

export function useBuyerApi() {
    return { buyerApi };
}

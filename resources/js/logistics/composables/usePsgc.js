// resources/js/logistics/composables/usePsgc.js
//
// One PSGC address-lookup module for the whole logistics portal.
//
// Previously Couriers.vue and AccountSettings.vue each carried their own
// copy of this, and they had drifted: Couriers hit the fast
// /api/psgc/provinces/all endpoint (PsgcProxyController::allProvinces does
// the regions -> provinces fan-out once, server-side, and caches it) while
// Account Settings hit /api/psgc/provinces?limit=200 with an ~18-request
// per-region fan-out as a fallback. Both now use the fast path.
//
// Every lookup is memoised at module scope (so switching tabs or reopening
// a modal costs nothing) and mirrored into sessionStorage (so a full page
// reload costs nothing either). Concurrent callers share one in-flight
// promise instead of each firing their own request.

const PSGC_BASE = '/api/psgc';
const CACHE_TTL_MS = 24 * 60 * 60 * 1000;
const REQUEST_TIMEOUT_MS = 15000;

// code -> sorted list, for the lifetime of the page.
const memo = new Map();
// code -> in-flight promise, so N callers make 1 request.
const inFlight = new Map();

function readSession(key) {
    try {
        const cached = JSON.parse(sessionStorage.getItem(key) || 'null');

        if (
            cached &&
            Array.isArray(cached.data) &&
            Date.now() - cached.savedAt < CACHE_TTL_MS
        ) {
            return cached.data;
        }
    } catch {
        // Storage is unavailable in private/restricted browsing contexts.
    }

    return null;
}

function writeSession(key, data) {
    try {
        sessionStorage.setItem(
            key,
            JSON.stringify({ data, savedAt: Date.now() }),
        );
    } catch {
        // A failed cache write must never break the form.
    }
}

function dedupeByCodeOrName(items = []) {
    const seen = new Map();

    for (const item of items) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const code = String(item.code ?? '').trim();
        const name = String(item.name ?? '').trim();

        if (!code && !name) {
            continue;
        }

        const key = code || name.toLowerCase().replace(/\s+/g, ' ');

        if (!seen.has(key)) {
            seen.set(key, item);
        }
    }

    return Array.from(seen.values()).sort((a, b) =>
        a.name.localeCompare(b.name),
    );
}

async function fetchJson(url) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

    try {
        const response = await fetch(url, { signal: controller.signal });

        if (!response.ok) {
            throw new Error(`Request failed: ${response.status}`);
        }

        return await response.json();
    } finally {
        clearTimeout(timeoutId);
    }
}

/**
 * Memoised + deduped list loader. `key` identifies the list in both the
 * in-memory map and sessionStorage.
 */
function loadList(key, url) {
    if (memo.has(key)) {
        return Promise.resolve(memo.get(key));
    }

    if (inFlight.has(key)) {
        return inFlight.get(key);
    }

    const cached = readSession(key);

    if (cached?.length) {
        memo.set(key, cached);

        return Promise.resolve(cached);
    }

    const request = fetchJson(url)
        .then((json) => {
            const list = dedupeByCodeOrName(json.data || []);

            if (list.length === 0) {
                throw new Error('No results returned');
            }

            memo.set(key, list);
            writeSession(key, list);

            return list;
        })
        .catch((error) => {
            throw error.name === 'AbortError'
                ? new Error('The address lookup timed out. Please retry.')
                : error;
        })
        .finally(() => {
            inFlight.delete(key);
        });

    inFlight.set(key, request);

    return request;
}

const fetchProvinces = () =>
    loadList('psgc:provinces', `${PSGC_BASE}/provinces/all`);

const fetchMunicipalities = (provinceCode) =>
    provinceCode
        ? loadList(
              `psgc:municipalities:${provinceCode}`,
              `${PSGC_BASE}/cities-municipalities?province_code=${provinceCode}`,
          )
        : Promise.resolve([]);

const fetchBarangays = (municipalityCode) =>
    municipalityCode
        ? loadList(
              `psgc:barangays:${municipalityCode}`,
              `${PSGC_BASE}/barangays?city_municipality_code=${municipalityCode}&limit=500`,
          )
        : Promise.resolve([]);

export function usePsgc() {
    return { fetchProvinces, fetchMunicipalities, fetchBarangays };
}

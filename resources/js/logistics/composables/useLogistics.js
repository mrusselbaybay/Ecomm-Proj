// resources/js/logistics/composables/useLogistics.js
//
// Shared data layer for the logistics portal.
//
// Two things keep the portal's request count down:
//
//   1. `cached()` memoises each loader for CACHE_TTL_MS and collapses
//      concurrent callers onto one in-flight promise. Tab switches (which
//      keep components alive via <KeepAlive>) and two components asking
//      for the same list therefore cost zero extra requests.
//   2. Mutations patch the already-loaded row in place from the response
//      body — every one of these endpoints returns the updated resource —
//      instead of re-downloading the whole list. Assigning one parcel used
//      to re-fetch the entire sorting queue.
//
// Anything that genuinely invalidates other data calls `invalidate()` for
// just those keys, so the next reader refetches and nothing else does.
import { computed, ref } from 'vue';

const SUPABASE_URL = import.meta.env.VITE_SUPABASE_URL;
const SUPABASE_ANON_KEY = import.meta.env.VITE_SUPABASE_ANON_KEY;

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

// Exported so useLogisticsProfile.js (the Account Settings composable)
// reuses this exact singleton instead of creating a second client.
export { getSupabase };

// ---------------------------------------------------------------- state

const companyId = ref(null);
const companyName = ref('');
const applications = ref([]);
// The accepted-rider roster, in the same LogisticsApplicationResource
// shape as `applications` — its own list (and cache key) so the Riders
// page and the Rider Applications page never clobber each other's data.
const acceptedRiders = ref([]);
const couriers = ref([]);
const barangayAssignments = ref([]);
const assignmentRiders = ref([]);
const parcelAssignments = ref([]);
const transferRequests = ref([]);
const transferRequestsMeta = ref({ pendingTotal: 0 });
const resignationRequests = ref([]);
const resignationRequestsMeta = ref({
    currentPage: 1,
    lastPage: 1,
    total: 0,
    pendingTotal: 0,
});
const pendingCount = ref(0);
const loadingCompany = ref(true);
const isLoading = ref(true);
const isAuthenticated = ref(false);
const logisticsProfile = ref(null);
const lastSyncedAt = ref(null);

// ------------------------------------------------- request cache / dedupe

const CACHE_TTL_MS = 30_000;
const CACHE = new Map(); // key -> { at, signature, value, promise }

export const CACHE_KEYS = {
    applications: 'applications',
    acceptedRiders: 'accepted-riders',
    couriers: 'couriers',
    barangayAssignments: 'barangay-assignments',
    parcels: 'parcel-assignments',
    transferRequests: 'parcel-transfer-requests',
    resignations: 'resignation-requests',
};

/**
 * Runs `loader` at most once per TTL per (key, signature), and collapses
 * concurrent callers onto the same promise.
 *
 * `signature` distinguishes different argument sets for the same key —
 * e.g. the applications list filtered by status/search must not be served
 * from a cache entry built for a different filter.
 */
function cached(key, signature, loader, { force = false } = {}) {
    const entry = CACHE.get(key);

    if (!force && entry) {
        if (entry.promise) {
            return entry.promise;
        }

        if (
            entry.signature === signature &&
            Date.now() - entry.at < CACHE_TTL_MS
        ) {
            return Promise.resolve(entry.value);
        }
    }

    const promise = loader()
        .then((value) => {
            CACHE.set(key, { at: Date.now(), signature, value, promise: null });
            lastSyncedAt.value = new Date();

            return value;
        })
        .catch((error) => {
            CACHE.delete(key);

            throw error;
        });

    CACHE.set(key, { at: 0, signature, value: entry?.value, promise });

    return promise;
}

/** Marks entries stale so the next reader refetches. */
function invalidate(...keys) {
    keys.forEach((key) => CACHE.delete(key));
}

/** Clears everything — used on logout so a second sign-in starts clean. */
function resetCache() {
    CACHE.clear();
    applications.value = [];
    acceptedRiders.value = [];
    couriers.value = [];
    barangayAssignments.value = [];
    assignmentRiders.value = [];
    parcelAssignments.value = [];
    transferRequests.value = [];
    transferRequestsMeta.value = { pendingTotal: 0 };
    resignationRequests.value = [];
    resignationRequestsMeta.value = {
        currentPage: 1,
        lastPage: 1,
        total: 0,
        pendingTotal: 0,
    };
    pendingCount.value = 0;
    lastSyncedAt.value = null;
}

/** Replaces a row by id in a ref'd array, or prepends it when new. */
function upsertRow(listRef, row) {
    if (!row?.id) {
        return;
    }

    const index = listRef.value.findIndex((item) => item.id === row.id);

    listRef.value =
        index === -1
            ? [row, ...listRef.value]
            : listRef.value.map((item, i) => (i === index ? row : item));
}

function removeRow(listRef, id) {
    listRef.value = listRef.value.filter((item) => item.id !== id);
}

// ------------------------------------------------------------------ auth

async function checkAuth() {
    const supabase = getSupabase();
    isLoading.value = true;

    try {
        const {
            data: { user },
            error,
        } = await supabase.auth.getUser();

        if (error || !user) {
            window.location.href = '/login';

            return false;
        }

        const { data: profile, error: profileError } = await supabase
            .from('profiles')
            .select(
                'id, role, first_name, last_name, email, status, account_status',
            )
            .eq('id', user.id)
            .single();

        if (
            profileError ||
            !profile ||
            profile.role !== 'logistics' ||
            profile.status !== 'approved' ||
            profile.account_status !== 'active'
        ) {
            await supabase.auth.signOut();
            document.cookie =
                'buytheway_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
            window.location.href = '/login';

            return false;
        }

        logisticsProfile.value = profile;
        isAuthenticated.value = true;

        return true;
    } catch (error) {
        console.error('Logistics authentication failed:', error);
        window.location.href = '/login';

        return false;
    } finally {
        isLoading.value = false;
    }
}

async function logout() {
    try {
        await getSupabase().auth.signOut();
    } finally {
        document.cookie =
            'buytheway_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
        companyId.value = null;
        companyName.value = '';
        logisticsProfile.value = null;
        isAuthenticated.value = false;
        resetCache();
        window.location.href = '/login';
    }
}

async function logisticsFetch(url, options = {}) {
    const supabase = getSupabase();

    async function requestWithToken(accessToken) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('Authorization', `Bearer ${accessToken}`);

        return fetch(url, { ...options, headers });
    }

    let {
        data: { session },
    } = await supabase.auth.getSession();

    if (!session?.access_token) {
        const { data, error } = await supabase.auth.refreshSession();

        if (error || !data.session?.access_token) {
            await logout();

            throw new Error('Your session has expired. Please sign in again.');
        }

        session = data.session;
    }

    let response = await requestWithToken(session.access_token);

    if (response.status === 401) {
        const { data, error } = await supabase.auth.refreshSession();

        if (error || !data.session?.access_token) {
            await logout();

            throw new Error('Your session has expired. Please sign in again.');
        }

        response = await requestWithToken(data.session.access_token);
    }

    if (response.status === 401) {
        await logout();

        throw new Error('Your session has expired. Please sign in again.');
    }

    return response;
}

/** Reads a JSON API response, raising the first validation message it finds. */
async function readJson(response, fallbackMessage) {
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {})
            .flat()
            .at(0);

        throw new Error(
            validationMessage ||
                payload.message ||
                payload.error ||
                fallbackMessage,
        );
    }

    return payload;
}

/**
 * Finds the company this account belongs to — as owner, or as appointed
 * logistics staff.
 *
 * `uid` is passed in by the caller that already has it (the layout, right
 * after checkAuth) so this doesn't make a second auth.getUser() round-trip
 * for a user it was just handed.
 */
async function resolveCompany(uid = null) {
    if (companyId.value) {
        return companyId.value;
    }

    const supabase = getSupabase();
    loadingCompany.value = true;

    try {
        let profileId = uid;

        if (!profileId) {
            const { data: userData } = await supabase.auth.getUser();
            profileId = userData?.user?.id;
        }

        if (!profileId) {
            return null;
        }

        const { data: owned } = await supabase
            .from('logistics_companies')
            .select('id, company_name')
            .eq('owner_profile_id', profileId)
            .maybeSingle();

        if (owned) {
            companyId.value = owned.id;
            companyName.value = owned.company_name;

            return owned.id;
        }

        const { data: staff } = await supabase
            .from('logistics_admin_details')
            .select('logistics_company_id, logistics_companies(company_name)')
            .eq('profile_id', profileId)
            .maybeSingle();

        if (staff) {
            companyId.value = staff.logistics_company_id;
            companyName.value = staff.logistics_companies?.company_name || '';

            return staff.logistics_company_id;
        }

        return null;
    } catch (error) {
        console.error('Error resolving the logistics company:', error);

        return null;
    } finally {
        loadingCompany.value = false;
    }
}

// ---------------------------------------------------------- applications

async function loadApplications(filters = {}, { force = false } = {}) {
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        return [];
    }

    const params = new URLSearchParams();

    if (filters.status) {
        params.set('status', filters.status);
    }

    if (filters.search) {
        params.set('search', filters.search);
    }

    const query = params.toString();

    return cached(
        CACHE_KEYS.applications,
        query,
        async () => {
            const response = await logisticsFetch(
                `/api/logistics/applications${query ? `?${query}` : ''}`,
            );
            const payload = await readJson(
                response,
                'Failed to load applications.',
            );

            applications.value = payload.data || [];

            // Only an unfiltered read reflects the true company-wide
            // pending total; a filtered one would under-report the badge.
            if (!query) {
                pendingCount.value = applications.value.filter(
                    (item) => item.status === 'pending',
                ).length;
            }

            return applications.value;
        },
        { force },
    );
}

// -------------------------------------------------- accepted rider roster

// Page metadata for the (paginated) accepted-rider roster — read by
// Riders.vue to render Prev/Next controls.
const acceptedRidersMeta = ref({ currentPage: 1, lastPage: 1, total: 0 });

/**
 * The accepted riders only — backs the Riders page. Separate list and
 * cache entry from loadApplications() so the two pages don't overwrite
 * each other under <KeepAlive>. `search` is the only server-side filter,
 * `page` paginates server-side (10/page) via the endpoint's opt-in
 * `per_page` param.
 */
async function loadAcceptedRiders(filters = {}, { force = false } = {}) {
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        acceptedRiders.value = [];

        return [];
    }

    const params = new URLSearchParams({ status: 'accepted', per_page: '10' });

    if (filters.search) {
        params.set('search', filters.search);
    }

    if (filters.page && filters.page > 1) {
        params.set('page', String(filters.page));
    }

    const query = params.toString();

    return cached(
        CACHE_KEYS.acceptedRiders,
        query,
        async () => {
            const response = await logisticsFetch(
                `/api/logistics/applications?${query}`,
            );
            const payload = await readJson(
                response,
                'Failed to load the rider roster.',
            );

            acceptedRiders.value = payload.data || [];
            acceptedRidersMeta.value = {
                currentPage: payload.meta?.current_page ?? 1,
                lastPage: payload.meta?.last_page ?? 1,
                total: payload.meta?.total ?? acceptedRiders.value.length,
            };

            return acceptedRiders.value;
        },
        { force },
    );
}

/** Drops a rider from the roster locally after they're let go. */
function removeAcceptedRider(id) {
    acceptedRiders.value = acceptedRiders.value.filter(
        (item) => item.id !== id,
    );
    // Firing withdraws the application and pulls the rider off every
    // barangay assignment — the roster, the accepted-rider pool and the
    // applications list all now disagree with the server.
    invalidate(
        CACHE_KEYS.couriers,
        CACHE_KEYS.barangayAssignments,
        CACHE_KEYS.applications,
    );
}

/** Patches one application row locally after an accept/reject/interview. */
function patchApplication(id, changes) {
    applications.value = applications.value.map((item) =>
        item.id === id ? { ...item, ...changes } : item,
    );
    pendingCount.value = applications.value.filter(
        (item) => item.status === 'pending',
    ).length;

    // The roster, the accepted-rider pool and the Riders page all derive
    // from this list — an accept/reject here changes what they show.
    invalidate(
        CACHE_KEYS.couriers,
        CACHE_KEYS.barangayAssignments,
        CACHE_KEYS.acceptedRiders,
    );
}

async function loadCouriers({ force = false } = {}) {
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        couriers.value = [];

        return [];
    }

    return cached(
        CACHE_KEYS.couriers,
        'accepted',
        async () => {
            const response = await logisticsFetch(
                '/api/logistics/applications?status=accepted',
            );
            const payload = await readJson(
                response,
                'Failed to load the accepted rider roster.',
            );

            couriers.value = (payload.data || []).map((application) => ({
                profile_id: application.courier?.id,
                application_id: application.id,
                vehicle: application.courier_details?.vehicle || null,
                plate_number: application.courier_details?.plate_number || null,
                profile: application.courier,
            }));

            return couriers.value;
        },
        { force },
    );
}

// --------------------------------------------------- barangay assignments

async function loadBarangayAssignments({ force = false } = {}) {
    return cached(
        CACHE_KEYS.barangayAssignments,
        'all',
        async () => {
            const response = await logisticsFetch(
                '/api/logistics/barangay-assignments',
            );
            const payload = await readJson(
                response,
                'Failed to load barangay assignments.',
            );

            barangayAssignments.value = payload.assignments || [];
            assignmentRiders.value = payload.riders || [];

            return payload;
        },
        { force },
    );
}

async function saveBarangayAssignment(assignment, id = null) {
    const response = await logisticsFetch(
        id
            ? `/api/logistics/barangay-assignments/${id}`
            : '/api/logistics/barangay-assignments',
        {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(assignment),
        },
    );
    const payload = await readJson(
        response,
        'Failed to save the barangay assignment.',
    );

    // The endpoint returns the saved row, so patch it in rather than
    // re-downloading every assignment.
    upsertRow(barangayAssignments, payload.data);

    return payload.data;
}

// Creates one unassigned assignment per barangay of a municipality in one
// request — bulk alternative to saveBarangayAssignment for covering a
// whole city/municipality at once instead of adding barangays one by one.
async function bulkCreateBarangayAssignments({
    province_name,
    municipality_code,
    municipality_name,
    barangays,
}) {
    const response = await logisticsFetch(
        '/api/logistics/barangay-assignments/bulk',
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                province_name,
                municipality_code,
                municipality_name,
                barangays,
            }),
        },
    );
    const payload = await readJson(
        response,
        'Failed to auto-create delivery areas.',
    );

    // The endpoint returns every assignment now covering that municipality
    // (old + newly created) — merge them all in rather than re-downloading
    // the whole company roster.
    (payload.assignments || []).forEach((assignment) =>
        upsertRow(barangayAssignments, assignment),
    );

    return payload;
}

async function deleteBarangayAssignment(id) {
    if (!id) {
        throw new Error('No barangay assignment was selected to delete.');
    }

    const response = await logisticsFetch(
        `/api/logistics/barangay-assignments/${id}`,
        { method: 'DELETE' },
    );
    await readJson(response, 'Failed to delete the barangay assignment.');

    removeRow(barangayAssignments, id);
}

// A barangay has at most one rider — pass null to clear it.
async function setAssignmentRider(assignmentId, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/barangay-assignments/${assignmentId}/rider`,
        {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rider_profile_id: riderProfileId }),
        },
    );
    const payload = await readJson(response, 'Failed to update the driver.');

    upsertRow(barangayAssignments, payload.data);

    return payload.data;
}

// Paginated (5/page), searched server-side — backs the rider picker on the
// assignment form. Deliberately not folded into
// loadBarangayAssignments()/assignmentRiders: that's the whole company
// roster, unpaginated, used for the summary table further down the page —
// this is fetched only when the picker is actually opened. Not cached: the
// pool changes as riders are accepted/let go.
async function loadAvailableRiders(assignmentId, { search = '', page = 1 } = {}) {
    const params = new URLSearchParams();

    if (search) {
        params.set('search', search);
    }

    if (page > 1) {
        params.set('page', String(page));
    }

    const query = params.toString();
    const response = await logisticsFetch(
        `/api/logistics/barangay-assignments/${assignmentId}/available-riders${query ? `?${query}` : ''}`,
    );

    return readJson(response, 'Failed to load available riders.');
}

// ------------------------------------------- provincial / regional pools
//
// One tier-agnostic set of calls — both pools share the exact same shape
// (a single area + a rider roster + a paginated "available riders" picker
// gated by vehicle type server-side), just different endpoints.

const provincialAssignment = ref(null);
const regionalAssignment = ref(null);

const TIER_BASE_PATH = {
    provincial: '/api/logistics/provincial-assignment',
    regional: '/api/logistics/regional-assignment',
};
const TIER_STATE = {
    get provincial() {
        return provincialAssignment;
    },
    get regional() {
        return regionalAssignment;
    },
};

async function loadTierAssignment(tier, { force = false } = {}) {
    return cached(
        tier === 'provincial' ? 'provincial-assignment' : 'regional-assignment',
        'all',
        async () => {
            const response = await logisticsFetch(TIER_BASE_PATH[tier]);
            const payload = await readJson(
                response,
                `Failed to load the ${tier} pool.`,
            );

            TIER_STATE[tier].value = payload.assignment || null;

            return payload;
        },
        { force },
    );
}

async function loadTierAvailableRiders(tier, { search = '', page = 1 } = {}) {
    const params = new URLSearchParams();

    if (search) {
        params.set('search', search);
    }

    if (page > 1) {
        params.set('page', String(page));
    }

    const query = params.toString();
    const response = await logisticsFetch(
        `${TIER_BASE_PATH[tier]}/available-riders${query ? `?${query}` : ''}`,
    );

    return readJson(response, `Failed to load available ${tier} riders.`);
}

async function addTierRiders(tier, riderProfileIds) {
    const response = await logisticsFetch(`${TIER_BASE_PATH[tier]}/riders`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rider_profile_ids: riderProfileIds }),
    });
    const payload = await readJson(response, `Failed to add riders to the ${tier} pool.`);

    TIER_STATE[tier].value = payload.assignment || null;

    return payload.assignment;
}

async function removeTierRider(tier, riderProfileId) {
    const response = await logisticsFetch(
        `${TIER_BASE_PATH[tier]}/riders/${riderProfileId}`,
        { method: 'DELETE' },
    );
    const payload = await readJson(response, `Failed to remove that rider from the ${tier} pool.`);

    TIER_STATE[tier].value = payload.assignment || null;

    return payload.assignment;
}

// ----------------------------------------------------- parcel assignments

async function loadParcelAssignments({ force = false } = {}) {
    return cached(
        CACHE_KEYS.parcels,
        'all',
        async () => {
            const response = await logisticsFetch(
                '/api/logistics/parcel-assignments',
            );
            const payload = await readJson(
                response,
                'Failed to load the sorting queue.',
            );

            parcelAssignments.value = payload.data || [];

            return parcelAssignments.value;
        },
        { force },
    );
}

async function receiveParcel(trackingNumber) {
    const response = await logisticsFetch(
        '/api/logistics/parcel-assignments/receive',
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ tracking_number: trackingNumber }),
        },
    );
    const payload = await readJson(response, 'Failed to receive the parcel.');

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
}

async function assignParcel(id, barangayAssignmentId, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/assign`,
        {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                barangay_assignment_id: barangayAssignmentId,
                rider_profile_id: riderProfileId,
            }),
        },
    );
    const payload = await readJson(response, 'Failed to assign the parcel.');

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
}

/**
 * Auto-routes one parcel: the server matches its address to a barangay's
 * assigned rider, falling back to a company-wide rotation when that
 * barangay has nobody assigned (or they're not available right now).
 *
 * Resolves for every outcome, not just a successful assignment — check
 * `outcome` ('assigned' | 'no_area' | 'no_rider' | 'skipped'). Only a
 * genuine request failure rejects. That's what lets the sweep in
 * ParcelOperations.vue keep going and tally the reasons at the end.
 */
async function autoAssignParcel(id) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/auto-assign`,
        { method: 'PUT' },
    );
    const payload = await readJson(
        response,
        'Failed to auto-assign the parcel.',
    );

    upsertRow(parcelAssignments, payload.data);

    return payload;
}

async function handoffParcel(id) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/handoff`,
        { method: 'PUT' },
    );
    const payload = await readJson(response, 'Failed to hand off the parcel.');

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
}

// Dispatch picks who carries an accepted transfer to the target company's
// hub ('transfer_ongoing' -> 'transfer_assigned'). The courier still has
// to be physically handed the parcel (handoffParcel, same as a local
// rider) before they can confirm the transfer from their app.
async function assignTransferCourier(id, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/assign-transfer-courier`,
        {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rider_profile_id: riderProfileId }),
        },
    );
    const payload = await readJson(
        response,
        'Failed to assign a transfer courier.',
    );

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
}

// Logistics companies this parcel can be routed to (every other
// active/approved company) — backs the target-company picker once a
// parcel is flagged "To Transfer". Not cached: the eligible set is small
// and only fetched when the routing modal actually opens for one.
async function fetchTransferOptions(id) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/transfer-options`,
    );

    return readJson(response, 'Failed to load transfer destinations.');
}

// Product/seller/status-history for the Order page's Details modal — one
// parcel at a time, only when the modal actually opens for it, and never
// cached: it's read once per view, not re-rendered on every list refresh.
async function fetchParcelDetails(id) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/details`,
    );
    const payload = await readJson(
        response,
        'Failed to load the parcel details.',
    );

    return payload.data;
}

// Asks another logistics company to take a picked-up parcel this company
// can't deliver. Nothing moves yet — the parcel parks at
// 'transfer_pending' until the receiving company accepts or rejects the
// request (see respondToTransferRequest).
async function requestParcelTransfer(id, transferToCompanyId) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/transfer`,
        {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transfer_to_company_id: transferToCompanyId,
            }),
        },
    );
    const payload = await readJson(
        response,
        'Failed to send the transfer request.',
    );

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
}

// Transfer requests addressed to this company (pending first) plus the
// company-wide pending count for the header badge.
async function loadTransferRequests({ force = false } = {}) {
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        transferRequests.value = [];

        return [];
    }

    return cached(
        CACHE_KEYS.transferRequests,
        'incoming',
        async () => {
            const response = await logisticsFetch(
                '/api/logistics/parcel-transfer-requests',
            );
            const payload = await readJson(
                response,
                'Failed to load transfer requests.',
            );

            transferRequests.value = payload.data || [];
            transferRequestsMeta.value = {
                pendingTotal: payload.meta?.pending_total || 0,
            };

            return transferRequests.value;
        },
        { force },
    );
}

// action: 'accept' | 'reject' (receiving company) | 'cancel' (origin
// company withdrawing its own pending request). A note is only read on
// reject. Accepting moves custody, so the sorting queue is invalidated —
// the new "to be delivered" row (accept) or the released origin row
// (reject/cancel) needs a refetch to show up.
async function respondToTransferRequest(id, action, note = null) {
    const wasPending =
        transferRequests.value.find((item) => item.id === id)?.status ===
        'pending';

    const response = await logisticsFetch(
        `/api/logistics/parcel-transfer-requests/${id}/${action}`,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(note ? { note } : {}),
        },
    );
    const payload = await readJson(
        response,
        `Failed to ${action} the transfer request.`,
    );

    upsertRow(transferRequests, payload.data);

    if (wasPending && payload.data.status !== 'pending') {
        transferRequestsMeta.value = {
            ...transferRequestsMeta.value,
            pendingTotal: Math.max(
                0,
                transferRequestsMeta.value.pendingTotal - 1,
            ),
        };
    }

    invalidate(CACHE_KEYS.parcels);

    return payload.data;
}

const pendingTransferCount = computed(
    () => transferRequestsMeta.value.pendingTotal,
);

/**
 * "Still needs someone at this desk to act on it".
 *
 * Not handed off yet (needs a pickup courier), or handed off but back in
 * the pool with no rider (the pickup courier confirmed collection — now
 * it needs the deliver-or-transfer call). A handed-off parcel that
 * already has a rider AND was actually confirmed by a staff member
 * (assigned_by set) is out for delivery and off this desk — but a rider
 * ParcelInventoryController::scan()'s auto-match filled in isn't a real
 * handoff yet (nobody at the desk physically gave that rider the
 * parcel), so it stays actionable until staff open Manage and confirm
 * it. Also stays actionable for a regional-tier match (buyer outside
 * this company's own region) even once a regional rider is
 * auto-assigned, so staff can still choose to hand it to another company
 * instead of using the in-house regional pool. One already given to
 * another company ('transferred') is gone for good.
 *
 * An accepted transfer runs its own two-step desk work before it's off
 * this queue too: 'transfer_ongoing' needs a courier assigned, and
 * 'transfer_assigned' needs that courier physically handed the parcel
 * (there's no self-serve pickup for a transfer leg — see
 * Driver\DriverDeliveryController's docblock). 'ready_to_transfer' is
 * with the courier already, nothing left for this desk to do.
 */
function isParcelActionable(parcel) {
    // 'transferred' is gone for good; 'transfer_pending' is parked
    // waiting on the receiving company and only offers a "cancel
    // request" affordance, not a routing decision. 'for_inventory' has
    // nothing for this desk to do either — it's waiting on a Logistics
    // scan from the mobile app (see ParcelAssignment::STATUS_FOR_INVENTORY).
    if (
        parcel.status === 'transferred' ||
        parcel.status === 'transfer_pending' ||
        parcel.status === 'ready_to_transfer' ||
        parcel.status === 'for_inventory'
    ) {
        return false;
    }

    if (
        parcel.status === 'transfer_ongoing' ||
        parcel.status === 'transfer_assigned'
    ) {
        return true;
    }

    if (
        parcel.status === 'handed_off' &&
        (parcel.area_fallback_tier === 'regional' ||
            parcel.area_fallback_tier === 'provincial')
    ) {
        return true;
    }

    return (
        parcel.status !== 'handed_off' || !parcel.rider || !parcel.assigned_by
    );
}

// Mirrors ParcelOperations.vue's stageOf() exactly — four tab counts,
// kept in sync by hand since the two files don't share a composable
// export for it. If stageOf() ever changes its branching, update this
// the same way.
const parcelStats = computed(() => {
    const stats = {
        toPickUp: 0,
        awaitingInventory: 0,
        toDeliver: 0,
        toTransfer: 0,
        transferred: 0,
        total: 0,
    };

    for (const parcel of parcelAssignments.value) {
        stats.total += 1;

        if (parcel.status === 'transferred') {
            stats.transferred += 1;
        } else if (parcel.status === 'for_inventory') {
            stats.awaitingInventory += 1;
        } else if (parcel.status === 'transfer_pending') {
            // Offered to another company, waiting on their answer.
            stats.toTransfer += 1;
        } else if (
            parcel.status === 'transfer_ongoing' ||
            parcel.status === 'transfer_assigned' ||
            parcel.status === 'ready_to_transfer'
        ) {
            // Accepted — a courier is carrying it to the target company.
            stats.toDeliver += 1;
        } else if (parcel.status !== 'handed_off') {
            stats.toPickUp += 1;
        } else if (
            !parcel.is_transfer_receipt &&
            (parcel.area_fallback_tier === 'regional' ||
                parcel.area_fallback_tier === 'provincial')
        ) {
            // Regional/provincial pool pre-match, still an open "local
            // delivery or transfer?" decision — not for a transfer
            // receipt, which was chosen specifically to cover this area.
            stats.toTransfer += 1;
        } else if (parcel.is_transfer && !parcel.is_transfer_receipt) {
            // Picked up, but this company doesn't cover the buyer's
            // region — it needs handing to one that does.
            stats.toTransfer += 1;
        } else {
            stats.toDeliver += 1;
        }
    }

    return stats;
});

const assignmentStats = computed(() => {
    const active = barangayAssignments.value.filter((a) => a.is_active);

    return {
        active: active.length,
        staffed: active.filter((a) => a.rider).length,
        total: barangayAssignments.value.length,
    };
});

// ------------------------------------------------- resignation requests

// 5 per page — this used to pull the company's entire resignation history
// in one shot every time the panel opened.
async function loadResignationRequests({ page = 1, force = false } = {}) {
    return cached(
        CACHE_KEYS.resignations,
        String(page),
        async () => {
            const response = await logisticsFetch(
                `/api/logistics/resignation-requests?page=${page}`,
            );
            const payload = await readJson(
                response,
                'Failed to load resignation requests.',
            );

            resignationRequests.value = payload.data || [];
            resignationRequestsMeta.value = {
                currentPage: payload.meta?.current_page || 1,
                lastPage: payload.meta?.last_page || 1,
                total: payload.meta?.total || 0,
                // Company-wide, independent of which page is loaded — the
                // pending badge shouldn't only reflect one page of 5.
                pendingTotal: payload.meta?.pending_total || 0,
            };

            return resignationRequests.value;
        },
        { force },
    );
}

const pendingResignationCount = computed(
    () => resignationRequestsMeta.value.pendingTotal,
);

async function reviewResignation(id, action, note = null) {
    const wasPending =
        resignationRequests.value.find((item) => item.id === id)?.status ===
        'pending';

    const response = await logisticsFetch(
        `/api/logistics/resignation-requests/${id}/${action}`,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(note ? { note } : {}),
        },
    );
    const payload = await readJson(
        response,
        `Failed to ${action} the resignation request.`,
    );

    upsertRow(resignationRequests, payload.data);

    // Keep the pending badge accurate immediately rather than waiting on
    // a refetch — the row just left the pending bucket.
    if (wasPending && payload.data.status !== 'pending') {
        resignationRequestsMeta.value = {
            ...resignationRequestsMeta.value,
            pendingTotal: Math.max(
                0,
                resignationRequestsMeta.value.pendingTotal - 1,
            ),
        };
    }

    // A review can change which rows belong on which page (pending sorts
    // first), so the cached pages are no longer trustworthy — the caller
    // re-fetches the page it's looking at.
    invalidate(CACHE_KEYS.resignations);

    // Approving detaches the rider from every barangay they were assigned
    // to, so those lists are no longer accurate.
    if (action === 'approve') {
        invalidate(CACHE_KEYS.barangayAssignments, CACHE_KEYS.couriers);
    }

    return payload.data;
}

const approveResignation = (id, note = null) =>
    reviewResignation(id, 'approve', note);
const rejectResignation = (id, note) => reviewResignation(id, 'reject', note);

async function resignationLetterUrl(id) {
    const response = await logisticsFetch(
        `/api/logistics/resignation-requests/${id}/letter`,
    );
    const payload = await readJson(response, 'Could not open the letter.');

    return payload.url;
}

export function useLogistics() {
    return {
        supabase: getSupabase(),
        companyId,
        companyName,
        applications,
        acceptedRiders,
        acceptedRidersMeta,
        couriers,
        barangayAssignments,
        assignmentRiders,
        parcelAssignments,
        transferRequests,
        transferRequestsMeta,
        resignationRequests,
        resignationRequestsMeta,
        pendingCount,
        pendingResignationCount,
        pendingTransferCount,
        parcelStats,
        assignmentStats,
        loadingCompany,
        isLoading,
        isAuthenticated,
        logisticsProfile,
        lastSyncedAt,
        checkAuth,
        logout,
        logisticsFetch,
        resolveCompany,
        loadApplications,
        patchApplication,
        loadAcceptedRiders,
        removeAcceptedRider,
        loadCouriers,
        loadBarangayAssignments,
        saveBarangayAssignment,
        bulkCreateBarangayAssignments,
        deleteBarangayAssignment,
        setAssignmentRider,
        loadAvailableRiders,
        provincialAssignment,
        regionalAssignment,
        loadTierAssignment,
        loadTierAvailableRiders,
        addTierRiders,
        removeTierRider,
        loadParcelAssignments,
        receiveParcel,
        assignParcel,
        autoAssignParcel,
        handoffParcel,
        assignTransferCourier,
        fetchTransferOptions,
        fetchParcelDetails,
        requestParcelTransfer,
        loadTransferRequests,
        respondToTransferRequest,
        isParcelActionable,
        loadResignationRequests,
        approveResignation,
        rejectResignation,
        resignationLetterUrl,
        invalidate,
    };
}

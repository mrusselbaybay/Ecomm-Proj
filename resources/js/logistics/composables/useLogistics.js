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
const couriers = ref([]);
const deliveryAreas = ref([]);
const areaRiders = ref([]);
const parcelAssignments = ref([]);
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
    couriers: 'couriers',
    deliveryAreas: 'delivery-areas',
    parcels: 'parcel-assignments',
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
    couriers.value = [];
    deliveryAreas.value = [];
    areaRiders.value = [];
    parcelAssignments.value = [];
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
                'nexmart_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
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
            'nexmart_session=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
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

/** Patches one application row locally after an accept/reject/interview. */
function patchApplication(id, changes) {
    applications.value = applications.value.map((item) =>
        item.id === id ? { ...item, ...changes } : item,
    );
    pendingCount.value = applications.value.filter(
        (item) => item.status === 'pending',
    ).length;

    // The roster and the accepted-rider pool both derive from this list.
    invalidate(CACHE_KEYS.couriers, CACHE_KEYS.deliveryAreas);
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

// -------------------------------------------------------- delivery areas

async function loadDeliveryAreas({ force = false } = {}) {
    return cached(
        CACHE_KEYS.deliveryAreas,
        'all',
        async () => {
            const response = await logisticsFetch(
                '/api/logistics/delivery-areas',
            );
            const payload = await readJson(
                response,
                'Failed to load delivery areas.',
            );

            deliveryAreas.value = payload.areas || [];
            areaRiders.value = payload.riders || [];

            return payload;
        },
        { force },
    );
}

async function saveDeliveryArea(area, id = null) {
    const response = await logisticsFetch(
        id
            ? `/api/logistics/delivery-areas/${id}`
            : '/api/logistics/delivery-areas',
        {
            method: id ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(area),
        },
    );
    const payload = await readJson(
        response,
        'Failed to save the delivery area.',
    );

    // The endpoint returns the saved area, so patch it in rather than
    // re-downloading every area and the whole rider roster.
    upsertRow(deliveryAreas, payload.data);

    return payload.data;
}

async function deleteDeliveryArea(id) {
    const response = await logisticsFetch(
        `/api/logistics/delivery-areas/${id}`,
        { method: 'DELETE' },
    );
    await readJson(response, 'Failed to delete the delivery area.');

    removeRow(deliveryAreas, id);
}

async function addAreaRider(areaId, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/delivery-areas/${areaId}/riders`,
        {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rider_profile_id: riderProfileId }),
        },
    );
    const payload = await readJson(response, 'Failed to add the driver.');

    upsertRow(deliveryAreas, payload.data);

    return payload.data;
}

async function removeAreaRider(areaId, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/delivery-areas/${areaId}/riders/${riderProfileId}`,
        { method: 'DELETE' },
    );
    const payload = await readJson(response, 'Failed to remove the driver.');

    upsertRow(deliveryAreas, payload.data);

    return payload.data;
}

// Paginated (5/page), searched server-side — backs the "Add driver" side
// panel. Deliberately not folded into loadDeliveryAreas()/areaRiders:
// that's the whole company roster, unpaginated, used for the summary
// table further down the page — this is scoped to one area, excludes
// riders already appointed to it, and is fetched only when the panel is
// actually opened. Not cached: the pool changes as riders are appointed.
async function loadAvailableRiders(areaId, { search = '', page = 1 } = {}) {
    const params = new URLSearchParams();

    if (search) {
        params.set('search', search);
    }

    if (page > 1) {
        params.set('page', String(page));
    }

    const query = params.toString();
    const response = await logisticsFetch(
        `/api/logistics/delivery-areas/${areaId}/available-riders${query ? `?${query}` : ''}`,
    );

    return readJson(response, 'Failed to load available riders.');
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

async function assignParcel(id, deliveryAreaId, riderProfileId) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/assign`,
        {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                delivery_area_id: deliveryAreaId,
                rider_profile_id: riderProfileId,
            }),
        },
    );
    const payload = await readJson(response, 'Failed to assign the parcel.');

    upsertRow(parcelAssignments, payload.data);

    return payload.data;
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

/**
 * "Still needs someone at this desk to act on it": either not handed off
 * yet (needs a pickup rider), or handed off but back in the pool with no
 * rider (the pickup courier confirmed collection — needs a delivery
 * rider). A handed-off parcel that already has a rider is out for
 * delivery and off this desk.
 */
function isParcelActionable(parcel) {
    return parcel.status !== 'handed_off' || !parcel.rider;
}

const parcelStats = computed(() => {
    const stats = { toPickUp: 0, toDeliver: 0, outForDelivery: 0, total: 0 };

    for (const parcel of parcelAssignments.value) {
        stats.total += 1;

        if (parcel.status !== 'handed_off') {
            stats.toPickUp += 1;
        } else if (!parcel.rider) {
            stats.toDeliver += 1;
        } else {
            stats.outForDelivery += 1;
        }
    }

    return stats;
});

const areaStats = computed(() => {
    const active = deliveryAreas.value.filter((area) => area.is_active);

    return {
        active: active.length,
        staffed: active.filter((area) => area.riders?.length).length,
        total: deliveryAreas.value.length,
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

    // Approving detaches the rider from every delivery area they served,
    // so those lists are no longer accurate.
    if (action === 'approve') {
        invalidate(CACHE_KEYS.deliveryAreas, CACHE_KEYS.couriers);
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
        couriers,
        deliveryAreas,
        areaRiders,
        parcelAssignments,
        resignationRequests,
        resignationRequestsMeta,
        pendingCount,
        pendingResignationCount,
        parcelStats,
        areaStats,
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
        loadCouriers,
        loadDeliveryAreas,
        saveDeliveryArea,
        deleteDeliveryArea,
        addAreaRider,
        removeAreaRider,
        loadAvailableRiders,
        loadParcelAssignments,
        receiveParcel,
        assignParcel,
        handoffParcel,
        isParcelActionable,
        loadResignationRequests,
        approveResignation,
        rejectResignation,
        resignationLetterUrl,
        invalidate,
    };
}

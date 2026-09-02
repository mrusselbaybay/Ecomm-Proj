// resources/js/logistics/composables/useLogistics.js
import { ref } from 'vue';

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

const companyId = ref(null);
const companyName = ref('');
const applications = ref([]);
const couriers = ref([]);
const deliveryAreas = ref([]);
const areaRiders = ref([]);
const parcelAssignments = ref([]);
const pendingCount = ref(0);
const loadingCompany = ref(true);
const isLoading = ref(true);
const isAuthenticated = ref(false);
const logisticsProfile = ref(null);

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

async function resolveCompany() {
    const supabase = getSupabase();
    loadingCompany.value = true;
    try {
        const { data: userData } = await supabase.auth.getUser();
        const uid = userData?.user?.id;
        if (!uid) {
            console.warn('No user ID found');
            return null;
        }

        console.log('Resolving company for user:', uid);

        // Check if user is owner
        const { data: owned, error: ownerError } = await supabase
            .from('logistics_companies')
            .select('id, company_name')
            .eq('owner_profile_id', uid)
            .maybeSingle();

        if (ownerError) {
            console.error('Error checking ownership:', ownerError);
        }

        if (owned) {
            companyId.value = owned.id;
            companyName.value = owned.company_name;
            console.log('Found owned company:', owned);
            return owned.id;
        }

        // Check if user is logistics_admin
        const { data: staff, error: staffError } = await supabase
            .from('logistics_admin_details')
            .select('logistics_company_id, logistics_companies(company_name)')
            .eq('profile_id', uid)
            .maybeSingle();

        if (staffError) {
            console.error('Error checking staff:', staffError);
        }

        if (staff) {
            companyId.value = staff.logistics_company_id;
            companyName.value = staff.logistics_companies?.company_name || '';
            console.log('Found staff company:', staff);
            return staff.logistics_company_id;
        }

        console.log('No company found for user');
        return null;
    } catch (error) {
        console.error('Error resolving company:', error);
        return null;
    } finally {
        loadingCompany.value = false;
    }
}

async function loadApplications(filters = {}) {
    const supabase = getSupabase();

    // Make sure company is resolved
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        console.warn('No company ID found, cannot load applications');
        return [];
    }

    console.log('Loading applications for company:', companyId.value);

    const params = new URLSearchParams();
    if (filters.status) params.set('status', filters.status);
    if (filters.search) params.set('search', filters.search);

    const query = params.toString();
    const url = `/api/logistics/applications${query ? `?${query}` : ''}`;

    try {
        const response = await logisticsFetch(url);

        const payload = await response.json();
        if (!response.ok) {
            throw new Error(
                payload.message ||
                    payload.error ||
                    'Failed to load applications.',
            );
        }

        applications.value = payload.data || [];
        pendingCount.value = applications.value.filter(
            (a) => a.status === 'pending',
        ).length;
        return applications.value;
    } catch (error) {
        console.error('Error loading applications:', error);
        throw error;
    }
}

async function loadCouriers() {
    if (!companyId.value) {
        await resolveCompany();
    }

    if (!companyId.value) {
        couriers.value = [];
        return [];
    }

    const response = await logisticsFetch(
        '/api/logistics/applications?status=accepted',
    );
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(
            payload.message || 'Failed to load the accepted rider roster.',
        );
    }

    couriers.value = (payload.data || []).map((application) => ({
        profile_id: application.courier?.id,
        application_id: application.id,
        vehicle: application.courier_details?.vehicle || null,
        plate_number: application.courier_details?.plate_number || null,
        profile: application.courier,
    }));

    return couriers.value;
}

async function loadDeliveryAreas() {
    const response = await logisticsFetch('/api/logistics/delivery-areas');
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || 'Failed to load delivery areas.');
    }

    deliveryAreas.value = payload.areas || [];
    areaRiders.value = payload.riders || [];

    return payload;
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
    const payload = await response.json();

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {})
            .flat()
            .at(0);
        throw new Error(
            validationMessage ||
                payload.message ||
                'Failed to save the delivery area.',
        );
    }

    await loadDeliveryAreas();
    return payload.data;
}

async function deleteDeliveryArea(id) {
    const response = await logisticsFetch(
        `/api/logistics/delivery-areas/${id}`,
        { method: 'DELETE' },
    );
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(
            payload.message || 'Failed to delete the delivery area.',
        );
    }

    await loadDeliveryAreas();
}

async function loadParcelAssignments() {
    const response = await logisticsFetch('/api/logistics/parcel-assignments');
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || 'Failed to load the sorting queue.');
    }

    parcelAssignments.value = payload.data || [];
    return parcelAssignments.value;
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
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || 'Failed to receive the parcel.');
    }

    await loadParcelAssignments();
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
    const payload = await response.json();

    if (!response.ok) {
        const validationMessage = Object.values(payload.errors || {})
            .flat()
            .at(0);
        throw new Error(
            validationMessage ||
                payload.message ||
                'Failed to assign the parcel.',
        );
    }

    await loadParcelAssignments();
    return payload.data;
}

async function handoffParcel(id) {
    const response = await logisticsFetch(
        `/api/logistics/parcel-assignments/${id}/handoff`,
        { method: 'PUT' },
    );
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || 'Failed to hand off the parcel.');
    }

    await loadParcelAssignments();
    return payload.data;
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
        pendingCount,
        loadingCompany,
        isLoading,
        isAuthenticated,
        logisticsProfile,
        checkAuth,
        logout,
        logisticsFetch,
        resolveCompany,
        loadApplications,
        loadCouriers,
        loadDeliveryAreas,
        saveDeliveryArea,
        deleteDeliveryArea,
        loadParcelAssignments,
        receiveParcel,
        assignParcel,
        handoffParcel,
    };
}

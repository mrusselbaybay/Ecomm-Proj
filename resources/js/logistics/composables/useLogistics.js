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
        'is present in the <head> of dashboard.blade.php, before @vite(...).'
      );
    }
    _supabase = window.supabase.createClient(SUPABASE_URL, SUPABASE_ANON_KEY);
  }
  return _supabase;
}

const companyId = ref(null);
const companyName = ref('');
const applications = ref([]);
const couriers = ref([]);
const pendingCount = ref(0);
const loadingCompany = ref(true);

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

  const { data: { session } } = await supabase.auth.getSession();
  if (!session?.access_token) {
    console.warn('No access token found');
    // Try to get user instead
    const { data: userData } = await supabase.auth.getUser();
    if (!userData?.user) {
      throw new Error('Please sign in again to view applications.');
    }
    // Continue without token - the cookie should handle auth
  }

  const params = new URLSearchParams();
  if (filters.status) params.set('status', filters.status);
  if (filters.search) params.set('search', filters.search);

  const url = `/api/logistics/applications?${params.toString()}`;
  console.log('Fetching applications from:', url);

  try {
    const response = await fetch(url, {
      headers: {
        'Accept': 'application/json',
        // Try to send the token if available
        ...(session?.access_token ? { 'Authorization': `Bearer ${session.access_token}` } : {})
      },
      credentials: 'include' // Important: send cookies
    });

    const payload = await response.json();
    console.log('Applications response:', payload);

    if (!response.ok) {
      throw new Error(payload.message || payload.error || 'Failed to load applications.');
    }

    applications.value = payload.data || [];
    pendingCount.value = applications.value.filter(a => a.status === 'pending').length;
    console.log('Applications loaded:', applications.value.length);
    return applications.value;
  } catch (error) {
    console.error('Error loading applications:', error);
    throw error;
  }
}

async function loadCouriers() {
  const supabase = getSupabase();
  if (!companyId.value) await resolveCompany();
  if (!companyId.value) return;

  const { data, error } = await supabase
    .from('courier_details')
    .select('*, profile:profiles!courier_details_profile_id_fkey(id, first_name, last_name, email, contact_no)')
    .eq('logistics_company_id', companyId.value);

  if (error) throw error;
  couriers.value = data || [];
  return couriers.value;
}

export function useLogistics() {
  return {
    supabase: getSupabase(),
    companyId,
    companyName,
    applications,
    couriers,
    pendingCount,
    loadingCompany,
    resolveCompany,
    loadApplications,
    loadCouriers,
  };
}
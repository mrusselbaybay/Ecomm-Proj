// resources/js/seller/composables/useFeedback.js
//
// ---------------------------------------------------------------
// Backed by the Laravel Seller Feedback API (routes/seller.php +
// App\Http\Controllers\Seller\SellerFeedbackController), same pattern as
// resources/js/seller/composables/useOrders.js: every request forwards
// the current Supabase access token as a Bearer header, and every review
// returned is already scoped server-side to the authenticated seller.
// ---------------------------------------------------------------

import { ref, computed } from 'vue';
import { getSupabase } from './useSeller';

const reviews = ref([]);
const meta = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 10,
    total: 0,
    statusCounts: { all: 0, unanswered: 0, lowRating: 0, responded: 0 },
});
const isLoadingReviews = ref(false);
const loadError = ref('');

const summary = ref(null);
const isLoadingSummary = ref(false);
const summaryError = ref('');

const respondingId = ref(null);
const respondError = ref('');

const isExporting = ref(false);
const exportError = ref('');

// Reactive filter/sort/page state shared with the Feedback.vue template.
// status: all | unanswered | low_rating | responded
// sort: newest | oldest | highest | lowest
// ("Most helpful" is intentionally absent — there's no helpful-vote data
// to sort by; see SellerFeedbackController's docblock.)
const filters = ref({
    search: '',
    rating: null,
    status: 'all',
    sort: 'newest',
    dateFrom: '',
    dateTo: '',
    page: 1,
});

async function authHeaders() {
    const supabase = getSupabase();
    const {
        data: { session },
    } = await supabase.auth.getSession();
    const token = session?.access_token;

    if (!token) {
        throw new Error('Not signed in.');
    }

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
    };
}

async function apiFetch(path, options = {}) {
    const headers = await authHeaders();
    const response = await fetch(`/api/seller${path}`, { ...options, headers });
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(body.message || 'Request failed.');
    }

    return body;
}

function buildQuery(includePage = true) {
    const params = new URLSearchParams();
    const f = filters.value;

    if (f.search) params.set('search', f.search);
    if (f.rating) params.set('rating', f.rating);
    if (f.status && f.status !== 'all') params.set('status', f.status);
    if (f.sort) params.set('sort', f.sort);
    if (f.dateFrom) params.set('date_from', f.dateFrom);
    if (f.dateTo) params.set('date_to', f.dateTo);
    if (includePage && f.page) params.set('page', f.page);

    return params.toString();
}

async function loadReviews() {
    isLoadingReviews.value = true;
    loadError.value = '';

    try {
        const body = await apiFetch(`/feedback?${buildQuery()}`);
        reviews.value = body.data;
        meta.value = body.meta;
    } catch (err) {
        console.error('Error loading seller feedback:', err);
        loadError.value =
            err?.message || 'Something went wrong while loading your reviews.';
        reviews.value = [];
    } finally {
        isLoadingReviews.value = false;
    }
}

async function loadSummary() {
    isLoadingSummary.value = true;
    summaryError.value = '';

    try {
        const body = await apiFetch('/feedback/summary');
        summary.value = body.data;
    } catch (err) {
        console.error('Error loading feedback summary:', err);
        summaryError.value =
            err?.message || 'Something went wrong while loading your feedback summary.';
        summary.value = null;
    } finally {
        isLoadingSummary.value = false;
    }
}

// Sets one or more filters and resets to page 1 (unless the change IS
// the page itself), then reloads — keeps Feedback.vue from having to
// remember to do both every time a filter control changes.
function setFilter(patch) {
    Object.assign(filters.value, patch);
    if (!('page' in patch)) {
        filters.value.page = 1;
    }
    loadReviews();
}

function clearFilters() {
    filters.value = {
        search: '',
        rating: null,
        status: 'all',
        sort: 'newest',
        dateFrom: '',
        dateTo: '',
        page: 1,
    };
    loadReviews();
}

// Guards against double-submission: while a response is in flight for a
// given review, respondingId holds its id so the template can disable
// that card's Send button specifically (other cards stay usable).
async function respondToReview(id, text) {
    if (respondingId.value) {
        return null;
    }

    respondingId.value = id;
    respondError.value = '';

    try {
        const body = await apiFetch(`/feedback/${encodeURIComponent(id)}/respond`, {
            method: 'PUT',
            body: JSON.stringify({ response: text }),
        });

        const updated = body.data;
        const idx = reviews.value.findIndex((r) => r.id === id);
        if (idx !== -1) {
            reviews.value[idx] = updated;
        }

        // A newly-answered/edited review shifts the response-rate,
        // unanswered count, and avg-response-time cards, plus the tab
        // counts, so refresh both.
        loadSummary();
        loadReviews();

        return updated;
    } catch (err) {
        console.error('Error responding to review:', err);
        respondError.value = err?.message || 'Could not send your response.';

        return null;
    } finally {
        respondingId.value = null;
    }
}

async function exportCsv() {
    isExporting.value = true;
    exportError.value = '';

    try {
        const headers = await authHeaders();
        const response = await fetch(`/api/seller/feedback/export?${buildQuery(false)}`, {
            headers,
        });

        if (!response.ok) {
            throw new Error('Export failed.');
        }

        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `feedback-export-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        console.error('Error exporting feedback:', err);
        exportError.value = err?.message || 'Could not export your reviews.';
    } finally {
        isExporting.value = false;
    }
}

const hasReviews = computed(() => reviews.value.length > 0);
const hasAnyReviewsAtAll = computed(
    () => (summary.value?.totalReviews ?? 0) > 0,
);
const hasActiveFilters = computed(() => {
    const f = filters.value;
    return !!(f.search || f.rating || (f.status && f.status !== 'all') || f.dateFrom || f.dateTo);
});

export function useFeedback() {
    return {
        reviews,
        meta,
        isLoadingReviews,
        loadError,
        hasReviews,
        hasAnyReviewsAtAll,
        hasActiveFilters,

        summary,
        isLoadingSummary,
        summaryError,

        filters,
        setFilter,
        clearFilters,

        respondingId,
        respondError,
        respondToReview,

        isExporting,
        exportError,
        exportCsv,

        loadReviews,
        loadSummary,
        buildQuery,
    };
}
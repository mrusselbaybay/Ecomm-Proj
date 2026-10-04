// resources/js/buyer/composables/useSearchFilters.js
//
// Search-results filter state, kept at module scope so it survives the
// results view unmounting while the buyer looks at a product — coming back
// restores the same filters, sort and "load more" depth instead of
// resetting them. Reset only when the query itself changes.
import { reactive } from 'vue';

export const SEARCH_PAGE_SIZE = 24;

function defaults() {
    return {
        query: '',
        categories: [],
        priceMin: '',
        priceMax: '',
        inStockOnly: false,
        onSaleOnly: false,
        minRating: 0,
        sort: 'relevance',
        visibleCount: SEARCH_PAGE_SIZE,
    };
}

const state = reactive(defaults());

function resetFilters({ keepQuery = true } = {}) {
    const query = state.query;

    Object.assign(state, defaults());

    if (keepQuery) {
        state.query = query;
    }
}

function syncQuery(query) {
    if (state.query !== query) {
        resetFilters({ keepQuery: false });
        state.query = query;
    }
}

export function useSearchFilters() {
    return {
        filters: state,
        resetFilters,
        syncQuery,
    };
}

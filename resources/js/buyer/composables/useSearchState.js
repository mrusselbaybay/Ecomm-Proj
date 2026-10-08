// resources/js/buyer/composables/useSearchState.js
//
// Browse state for the search results page and its URL form. Same model as
// useStoreBrowseState.js: the URL is the source of truth (refresh, shared
// links, Back / Forward), and an in-memory copy brings the buyer back to
// the same filters, sort and page after opening a product or store.
//
//   /buyer?search=cat&category=Pet+Supplies&subcategory=Toys&subcategory=Beds
//         &price_min=100&price_max=500&rating=4&in_stock=1&on_sale=1
//         &sort=price-asc&page=2
//
// Unknown or malformed values are dropped. These filters only narrow the
// product results; store matches are never filtered by them. There is
// deliberately no Brand, Life Stage, Pack Size or Flavor filter (product
// decision).
import { categories } from './useCategoryMeta';

export const SEARCH_SORTS = ['relevance', 'newest', 'popular', 'rating', 'price-asc', 'price-desc'];
const RATINGS = [4, 3];
const MAX_QUERY = 100;

const remembered = { state: null };

// Sidebar counts per search + filter combination (see SearchResults.vue).
const rememberedFacets = new Map();
const MAX_FACET_SETS = 30;

function cleanText(value, max = 80) {
    return typeof value === 'string' ? value.trim().slice(0, max) : '';
}

function cleanPrice(value) {
    const number = Number(value);

    return value !== '' && value !== null && value !== undefined && Number.isFinite(number) && number >= 0
        ? String(number)
        : '';
}

/**
 * @returns {{ q: string, category: string, subcategories: string[], priceMin: string, priceMax: string, minRating: number, inStockOnly: boolean, onSaleOnly: boolean, sort: string, page: number }}
 */
export function defaultSearchState(q = '') {
    return {
        q: cleanText(q, MAX_QUERY),
        category: '',
        subcategories: [],
        priceMin: '',
        priceMax: '',
        minRating: 0,
        inStockOnly: false,
        onSaleOnly: false,
        sort: 'relevance',
        page: 1
    };
}

export function sanitizeSearchState(raw) {
    const source = raw || {};
    const category = categories.includes(source.category) && source.category !== 'All' ? source.category : '';

    return {
        q: cleanText(source.q, MAX_QUERY),
        category,
        // Subcategories only mean something inside a category.
        subcategories: category
            ? [...new Set((Array.isArray(source.subcategories) ? source.subcategories : []).map(value => cleanText(String(value))).filter(Boolean))].slice(0, 20)
            : [],
        priceMin: cleanPrice(source.priceMin),
        priceMax: cleanPrice(source.priceMax),
        minRating: RATINGS.includes(Number(source.minRating)) ? Number(source.minRating) : 0,
        inStockOnly: source.inStockOnly === true,
        onSaleOnly: source.onSaleOnly === true,
        sort: SEARCH_SORTS.includes(source.sort) ? source.sort : 'relevance',
        page: Math.max(1, Math.floor(Number(source.page)) || 1)
    };
}

export function searchTermFromQuery(search) {
    return cleanText(new URLSearchParams(search).get('search') || '', MAX_QUERY);
}

export function searchStateFromQuery(search) {
    const params = new URLSearchParams(search);

    return sanitizeSearchState({
        q: params.get('search') ?? '',
        category: params.get('category') ?? '',
        subcategories: params.getAll('subcategory'),
        priceMin: params.get('price_min') ?? '',
        priceMax: params.get('price_max') ?? '',
        minRating: params.get('rating'),
        inStockOnly: params.get('in_stock') === '1',
        onSaleOnly: params.get('on_sale') === '1',
        sort: params.get('sort') ?? 'relevance',
        page: params.get('page') ?? 1
    });
}

export function queryForSearchState(state) {
    const clean = sanitizeSearchState(state);
    const params = new URLSearchParams({ search: clean.q });

    if (clean.category) {
        params.set('category', clean.category);
    }

    for (const subcategory of clean.subcategories) {
        params.append('subcategory', subcategory);
    }

    if (clean.priceMin !== '') {
        params.set('price_min', clean.priceMin);
    }

    if (clean.priceMax !== '') {
        params.set('price_max', clean.priceMax);
    }

    if (clean.minRating) {
        params.set('rating', String(clean.minRating));
    }

    if (clean.inStockOnly) {
        params.set('in_stock', '1');
    }

    if (clean.onSaleOnly) {
        params.set('on_sale', '1');
    }

    if (clean.sort !== 'relevance') {
        params.set('sort', clean.sort);
    }

    if (clean.page > 1) {
        params.set('page', String(clean.page));
    }

    return `?${params.toString()}`;
}

export function rememberSearchState(state) {
    remembered.state = sanitizeSearchState(JSON.parse(JSON.stringify(state)));
}

/** The remembered state for this query, or a fresh one for a new query. */
export function rememberedSearchState(q) {
    const term = cleanText(q, MAX_QUERY);

    return remembered.state && remembered.state.q === term
        ? sanitizeSearchState(remembered.state)
        : defaultSearchState(term);
}

export function searchUrl(q) {
    return `${window.location.pathname}${queryForSearchState(rememberedSearchState(q))}`;
}

/**
 * The search and filter parameters alone (no page, sort or page size):
 * what sidebar counts and related products depend on.
 */
export function searchFilterParams(state) {
    const clean = sanitizeSearchState(state);

    return {
        search: clean.q,
        category: clean.category,
        subcategory: clean.subcategories,
        price_min: clean.priceMin,
        price_max: clean.priceMax,
        min_rating: clean.minRating || '',
        in_stock: clean.inStockOnly,
        on_sale: clean.onSaleOnly
    };
}

/** The product API's parameters for a search state. */
export function searchProductParams(state, perPage) {
    const clean = sanitizeSearchState(state);

    return {
        ...searchFilterParams(clean),
        sort: clean.sort,
        page: clean.page,
        per_page: perPage,
        facets: true
    };
}

/** Keeps the facets returned for one search + filter combination. */
export function rememberSearchFacets(key, facets) {
    rememberedFacets.delete(key);
    rememberedFacets.set(key, facets);

    if (rememberedFacets.size > MAX_FACET_SETS) {
        rememberedFacets.delete(rememberedFacets.keys().next().value);
    }
}

export function rememberedSearchFacets(key) {
    return rememberedFacets.get(key) || null;
}

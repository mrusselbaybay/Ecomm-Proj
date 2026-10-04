// resources/js/buyer/composables/useCategoryFilterState.js
//
// Applied filter state for category listing pages, plus its URL form.
//
// The URL is the source of truth for a category page:
//   /buyer?category=Pet+Supplies&animal=Cat&price_min=100&in_stock=1&sort=price-asc&page=2
// Repeated keys carry multiple values (animal=Cat&animal=Dog). Only keys a
// category actually supports are read or written, so stale parameters from
// old links — including the retired brand / life stage / pack size / flavor
// filters — are dropped the moment the page canonicalises its URL.
//
// A per-category in-memory copy is also kept, so opening a category again
// from the header (a fresh URL) still comes back to where the buyer left it.
import { categoryConfig } from './useCategoryConfig';

const SORTS = ['newest', 'price-asc', 'price-desc', 'rating', 'name-asc'];
const RATINGS = [4, 3];

const remembered = new Map();

/**
 * @returns {{ priceMin: string, priceMax: string, selections: Record<string, string[]>, conditions: string[], inStockOnly: boolean, onSaleOnly: boolean, minRating: number, sortBy: string, page: number }}
 */
export function defaultFilterState() {
    return {
        priceMin: '',
        priceMax: '',
        selections: {},
        conditions: [],
        inStockOnly: false,
        onSaleOnly: false,
        minRating: 0,
        sortBy: 'newest',
        page: 1
    };
}

function cleanPrice(value) {
    const number = Number(value);

    return value !== '' && value !== null && value !== undefined && Number.isFinite(number) && number >= 0
        ? String(number)
        : '';
}

function cleanList(values) {
    return [...new Set((Array.isArray(values) ? values : []).map(String).filter(Boolean))];
}

/**
 * Keeps only what the category supports; everything else (unknown facet
 * keys, retired filters, malformed values) is discarded.
 */
export function sanitizeFilterState(raw, category) {
    const config = categoryConfig(category);
    const state = defaultFilterState();
    const source = raw || {};

    state.priceMin = cleanPrice(source.priceMin);
    state.priceMax = cleanPrice(source.priceMax);

    for (const facet of config.facets) {
        const values = cleanList(source.selections?.[facet.key]);

        if (values.length) {
            state.selections[facet.key] = values;
        }
    }

    state.conditions = cleanList(source.conditions);
    state.inStockOnly = source.inStockOnly === true;
    state.onSaleOnly = source.onSaleOnly === true;
    state.minRating = RATINGS.includes(Number(source.minRating)) ? Number(source.minRating) : 0;
    state.sortBy = SORTS.includes(source.sortBy) ? source.sortBy : 'newest';
    state.page = Math.max(1, Math.floor(Number(source.page)) || 1);

    return state;
}

export function filterStateFromQuery(search, category) {
    const params = new URLSearchParams(search);
    const config = categoryConfig(category);
    const selections = {};

    for (const facet of config.facets) {
        selections[facet.key] = params.getAll(facet.key);
    }

    return sanitizeFilterState({
        priceMin: params.get('price_min') ?? '',
        priceMax: params.get('price_max') ?? '',
        selections,
        conditions: params.getAll('condition'),
        inStockOnly: params.get('in_stock') === '1',
        onSaleOnly: params.get('on_sale') === '1',
        minRating: params.get('rating'),
        sortBy: params.get('sort') ?? 'newest',
        page: params.get('page') ?? 1
    }, category);
}

export function queryForFilterState(category, state) {
    const config = categoryConfig(category);
    const clean = sanitizeFilterState(state, category);
    const params = new URLSearchParams({ category });

    for (const facet of config.facets) {
        for (const value of clean.selections[facet.key] || []) {
            params.append(facet.key, value);
        }
    }

    if (clean.priceMin !== '') {
        params.set('price_min', clean.priceMin);
    }

    if (clean.priceMax !== '') {
        params.set('price_max', clean.priceMax);
    }

    for (const value of clean.conditions) {
        params.append('condition', value);
    }

    if (clean.inStockOnly) {
        params.set('in_stock', '1');
    }

    if (clean.onSaleOnly) {
        params.set('on_sale', '1');
    }

    if (clean.minRating) {
        params.set('rating', String(clean.minRating));
    }

    if (clean.sortBy !== 'newest') {
        params.set('sort', clean.sortBy);
    }

    if (clean.page > 1) {
        params.set('page', String(clean.page));
    }

    return `?${params.toString()}`;
}

export function categoryFromQuery(search) {
    return new URLSearchParams(search).get('category') || null;
}

export function rememberFilterState(category, state) {
    remembered.set(category, sanitizeFilterState(JSON.parse(JSON.stringify(state)), category));
}

export function rememberedFilterState(category) {
    return remembered.has(category)
        ? sanitizeFilterState(remembered.get(category), category)
        : defaultFilterState();
}

/** The canonical URL for a category page with its current filters. */
export function categoryUrl(category) {
    return `${window.location.pathname}${queryForFilterState(category, rememberedFilterState(category))}`;
}

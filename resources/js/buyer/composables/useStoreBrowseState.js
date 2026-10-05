// resources/js/buyer/composables/useStoreBrowseState.js
//
// Browse state for the store directory and individual store pages, plus
// its URL form. Same model as useCategoryFilterState.js: the URL is the
// source of truth (refresh, shared links, Back / Forward), and a per-page
// in-memory copy brings the buyer back to where they left off when the
// page is reopened from a fresh link (header, breadcrumb, product page).
//
//   Directory:  /buyer?view=stores&q=paws&category=Pet+Supplies&sort=name&page=2
//   Store page: /buyer?store=<uuid>&q=cable&sort=price-asc&in_stock=1&on_sale=1
//               &condition=new&price_min=100&price_max=500&rating=4&page=2
//
// Unknown or malformed values are dropped. There is deliberately no Brand,
// Life Stage, Pack Size or Flavor filter here (product decision).

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export const DIRECTORY_SORTS = ['products', 'rating', 'newest', 'name'];
export const STORE_PRODUCT_SORTS = ['newest', 'popular', 'price-asc', 'price-desc', 'rating', 'name-asc'];
export const STORE_TABS = ['products', 'reviews', 'about'];
const RATINGS = [4, 3];

const rememberedDirectory = { state: null };
const rememberedStores = new Map();

function cleanText(value, max = 80) {
    return typeof value === 'string' ? value.trim().slice(0, max) : '';
}

function cleanPage(value) {
    return Math.max(1, Math.floor(Number(value)) || 1);
}

function cleanPrice(value) {
    const number = Number(value);

    return value !== '' && value !== null && value !== undefined && Number.isFinite(number) && number >= 0
        ? String(number)
        : '';
}

/*
|--------------------------------------------------------------------------
| Store directory
|--------------------------------------------------------------------------
*/

/**
 * @returns {{ q: string, category: string, sort: string, page: number }}
 */
export function defaultDirectoryState() {
    return { q: '', category: '', sort: 'products', page: 1 };
}

export function sanitizeDirectoryState(raw) {
    const source = raw || {};

    return {
        q: cleanText(source.q),
        category: cleanText(source.category),
        sort: DIRECTORY_SORTS.includes(source.sort) ? source.sort : 'products',
        page: cleanPage(source.page)
    };
}

export function isDirectoryQuery(search) {
    return new URLSearchParams(search).get('view') === 'stores';
}

export function directoryStateFromQuery(search) {
    const params = new URLSearchParams(search);

    return sanitizeDirectoryState({
        q: params.get('q') ?? '',
        category: params.get('category') ?? '',
        sort: params.get('sort') ?? 'products',
        page: params.get('page') ?? 1
    });
}

export function queryForDirectoryState(state) {
    const clean = sanitizeDirectoryState(state);
    const params = new URLSearchParams({ view: 'stores' });

    if (clean.q) {
        params.set('q', clean.q);
    }

    if (clean.category) {
        params.set('category', clean.category);
    }

    if (clean.sort !== 'products') {
        params.set('sort', clean.sort);
    }

    if (clean.page > 1) {
        params.set('page', String(clean.page));
    }

    return `?${params.toString()}`;
}

export function rememberDirectoryState(state) {
    rememberedDirectory.state = sanitizeDirectoryState(state);
}

export function rememberedDirectoryState() {
    return sanitizeDirectoryState(rememberedDirectory.state || defaultDirectoryState());
}

export function directoryUrl() {
    return `${window.location.pathname}${queryForDirectoryState(rememberedDirectoryState())}`;
}

/*
|--------------------------------------------------------------------------
| Store page
|--------------------------------------------------------------------------
*/

/**
 * @returns {{ q: string, sort: string, page: number, inStockOnly: boolean, onSaleOnly: boolean, conditions: string[], priceMin: string, priceMax: string, minRating: number, tab: string }}
 */
export function defaultStoreState() {
    return {
        q: '',
        sort: 'newest',
        page: 1,
        inStockOnly: false,
        onSaleOnly: false,
        conditions: [],
        priceMin: '',
        priceMax: '',
        minRating: 0,
        tab: 'products'
    };
}

export function sanitizeStoreState(raw) {
    const source = raw || {};

    return {
        q: cleanText(source.q),
        sort: STORE_PRODUCT_SORTS.includes(source.sort) ? source.sort : 'newest',
        page: cleanPage(source.page),
        inStockOnly: source.inStockOnly === true,
        onSaleOnly: source.onSaleOnly === true,
        conditions: [...new Set((Array.isArray(source.conditions) ? source.conditions : []).map(String).filter(Boolean))].slice(0, 10),
        priceMin: cleanPrice(source.priceMin),
        priceMax: cleanPrice(source.priceMax),
        minRating: RATINGS.includes(Number(source.minRating)) ? Number(source.minRating) : 0,
        tab: STORE_TABS.includes(source.tab) ? source.tab : 'products'
    };
}

export function storeIdFromQuery(search) {
    const id = new URLSearchParams(search).get('store');

    return id && UUID.test(id) ? id : null;
}

export function storeStateFromQuery(search) {
    const params = new URLSearchParams(search);

    return sanitizeStoreState({
        q: params.get('q') ?? '',
        sort: params.get('sort') ?? 'newest',
        page: params.get('page') ?? 1,
        inStockOnly: params.get('in_stock') === '1',
        onSaleOnly: params.get('on_sale') === '1',
        conditions: params.getAll('condition'),
        priceMin: params.get('price_min') ?? '',
        priceMax: params.get('price_max') ?? '',
        minRating: params.get('rating'),
        tab: params.get('tab') ?? 'products'
    });
}

export function queryForStoreState(storeId, state) {
    const clean = sanitizeStoreState(state);
    const params = new URLSearchParams({ store: storeId });

    if (clean.q) {
        params.set('q', clean.q);
    }

    if (clean.sort !== 'newest') {
        params.set('sort', clean.sort);
    }

    if (clean.inStockOnly) {
        params.set('in_stock', '1');
    }

    if (clean.onSaleOnly) {
        params.set('on_sale', '1');
    }

    for (const condition of clean.conditions) {
        params.append('condition', condition);
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

    if (clean.tab !== 'products') {
        params.set('tab', clean.tab);
    }

    if (clean.page > 1) {
        params.set('page', String(clean.page));
    }

    return `?${params.toString()}`;
}

/** The product API's parameters for a store page state. */
export function storeProductParams(state, perPage) {
    const clean = sanitizeStoreState(state);

    return {
        search: clean.q,
        sort: clean.sort,
        page: clean.page,
        per_page: perPage,
        in_stock: clean.inStockOnly,
        on_sale: clean.onSaleOnly,
        condition: clean.conditions,
        price_min: clean.priceMin,
        price_max: clean.priceMax,
        min_rating: clean.minRating || ''
    };
}

export function rememberStoreState(storeId, state) {
    rememberedStores.set(storeId, sanitizeStoreState(JSON.parse(JSON.stringify(state))));
}

export function rememberedStoreState(storeId) {
    return sanitizeStoreState(rememberedStores.get(storeId) || defaultStoreState());
}

export function storePageUrl(storeId) {
    return `${window.location.pathname}${queryForStoreState(storeId, rememberedStoreState(storeId))}`;
}

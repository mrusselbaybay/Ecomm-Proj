/*
|--------------------------------------------------------------------------
| Category Metadata
|--------------------------------------------------------------------------
|
| Icon + accent color used for category cards, product image placeholders,
| and the product details page. Purely presentational — does not affect
| filtering logic. Extracted from Dashboard.vue so every buyer page shares
| the same icons/colors instead of redefining them.
|
| These MUST mirror seller_details.line_of_business (see the CHECK
| constraint on that column, and app/Support/CategoryFieldConfig.php).
| Every product's `category` is force-set server-side to its seller's
| line_of_business (see the enforce_product_status_workflow trigger /
| 2026_08_23_000002 migration), so the buyer-facing filter list has to use
| those exact strings or a seller's own products would never match their
| own category tab.
|
*/

export const categories = [
    'All',
    'Pet Supplies',
    'Kids and Baby',
    'Electronics and Gadgets',
    'House and Garden',
    "Woman's Apparel",
    "Men's Apparel",
    'Sports and Outdoors',
    'Health and Beauty'
];

const ICON_SVG = {
    bag: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 10a4 4 0 0 1-8 0"/><path d="M3.103 6.034h17.794"/><path d="M3.4 5.467a2 2 0 0 0-.4 1.2V20a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6.667a2 2 0 0 0-.4-1.2l-2-2.667A2 2 0 0 0 17 2H7a2 2 0 0 0-1.6.8z"/></svg>',
    cpu: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v2"/><path d="M12 2v2"/><path d="M17 20v2"/><path d="M17 2v2"/><path d="M2 12h2"/><path d="M2 17h2"/><path d="M2 7h2"/><path d="M20 12h2"/><path d="M20 17h2"/><path d="M20 7h2"/><path d="M7 20v2"/><path d="M7 2v2"/><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="8" y="8" width="8" height="8" rx="1"/></svg>',
    shirt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.38 3.46 16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>',
    sofa: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v3"/><path d="M2 16a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-5a2 2 0 0 0-4 0v1.5a.5.5 0 0 1-.5.5h-11a.5.5 0 0 1-.5-.5V11a2 2 0 0 0-4 0z"/><path d="M4 18v2"/><path d="M20 18v2"/><path d="M12 4v9"/></svg>',
    sparkles: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg>',
    trophy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14.66V17a1 1 0 0 1-1 1 2 2 0 0 0-2 2v2"/><path d="M14 14.66V17a1 1 0 0 0 1 1 2 2 0 0 1 2 2v2"/><path d="M17.916 10H19.5A2.5 2.5 0 0 0 22 7.5V5a1 1 0 0 0-1-1h-3"/><path d="M4 22h16"/><path d="M6 9a6 6 0 0 0 12 0V3a1 1 0 0 0-1-1H7a1 1 0 0 0-1 1z"/><path d="M6.084 10H4.5A2.5 2.5 0 0 1 2 7.5V5a1 1 0 0 1 1-1h3"/></svg>',
    paw: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>',
    baby: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M15 12h.01"/><path d="M19.38 6.813A9 9 0 0 1 20.8 10.2a2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1"/><path d="M9 12h.01"/></svg>'
};

const categoryMeta = {
    'All': { icon: ICON_SVG.bag, accent: 'slate' },
    'Pet Supplies': { icon: ICON_SVG.paw, accent: 'teal' },
    'Kids and Baby': { icon: ICON_SVG.baby, accent: 'orange' },
    'Electronics and Gadgets': { icon: ICON_SVG.cpu, accent: 'indigo' },
    'House and Garden': { icon: ICON_SVG.sofa, accent: 'amber' },
    "Woman's Apparel": { icon: ICON_SVG.shirt, accent: 'pink' },
    "Men's Apparel": { icon: ICON_SVG.shirt, accent: 'blue' },
    'Sports and Outdoors': { icon: ICON_SVG.trophy, accent: 'green' },
    'Health and Beauty': { icon: ICON_SVG.sparkles, accent: 'purple' }
};

export function metaFor(category) {
    return categoryMeta[category] || { icon: ICON_SVG.bag, accent: 'slate' };
}

/*
|--------------------------------------------------------------------------
| Pricing / Rating Helpers
|--------------------------------------------------------------------------
*/

export function discountPercent(product) {
    if (!product || !product.oldPrice) {
        return 0;
    }

    return Math.round(
        (1 - product.price / product.oldPrice) * 100
    );
}

export function ratingStars(rating) {
    const filled = Math.round(rating || 0);

    return '★'.repeat(filled) + '☆'.repeat(5 - filled);
}

export function formatPrice(value) {
    return `\u20B1${Number(value).toFixed(2)}`;
}

/*
|--------------------------------------------------------------------------
| Quick-Add Default Variation
|--------------------------------------------------------------------------
|
| One-click "quick add" (no variation picker shown) falls back to a
| sensible default per category, matching what the full Product Details
| page offers as options.
|
*/

export function defaultVariationFor(category) {
    if (category === "Woman's Apparel" || category === "Men's Apparel") {
        return 'M';
    }

    if (category === 'Electronics and Gadgets') {
        return 'Black';
    }

    return 'Default';
}

export function useCategoryMeta() {
    return {
        categories,
        metaFor,
        discountPercent,
        ratingStars,
        formatPrice,
        defaultVariationFor
    };
}
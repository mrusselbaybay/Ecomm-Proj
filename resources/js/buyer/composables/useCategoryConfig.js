// resources/js/buyer/composables/useCategoryConfig.js
//
// Per-category browsing configuration for CategoryListing.vue.
//
// Every filterable dimension here mirrors app/Support/CategoryFieldConfig.php
// — the template sellers fill in — so a facet can only ever be built from
// data a seller could actually have entered:
//   source 'spec'   -> a `select` specification, returned by the API as
//                      product.specifications[label] (labelSpecifications())
//   source 'option' -> a controlled variant option, returned as
//                      product.options[{ name, values: [{ value }] }]
// Free-text fields (Model, Shade, Ingredients ...) are deliberately left out:
// they can't be faceted honestly.
//
// A configured facet still only renders when the loaded products give it
// something to filter by (see facetIsUseful in CategoryListing.vue).
//
// Deliberately NOT filterable anywhere (product decision): Brand, Life
// Stage, Pack Size / Pack Weight, Flavor. Those attributes still exist on
// products and can still appear as card detail / on the product page.

const PACK_WEIGHTS = ['100g', '250g', '500g', '1kg', '2kg', '5kg', '10kg', '20kg'];
const VOLUMES = ['30ml', '50ml', '100ml', '150ml', '200ml', '250ml', '500ml', '1L'];
const APPAREL_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', 'One Size'];
const KIDS_SIZES = ['Newborn', '0-3M', '3-6M', '6-12M', '12-18M', '18-24M', '2T', '3T', '4T', '5T', '6', '7', '8'];

const apparel = {
    description: 'Everyday clothing from independent fashion sellers.',
    imageRatio: 'portrait',
    primaryFacet: null,
    facets: [
        { key: 'size', label: 'Size', source: 'option', name: 'Size', order: APPAREL_SIZES, display: 'grid' },
        { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' },
        { key: 'material', label: 'Material', source: 'option', name: 'Material' },
        { key: 'fit', label: 'Fit', source: 'spec', name: 'Fit', order: ['Slim', 'Regular', 'Loose', 'Oversized'] }
    ],
    cardDetail: [{ type: 'option', name: 'Size', order: APPAREL_SIZES, prefix: 'Sizes' }, { type: 'spec', name: 'Fit' }]
};

const CONFIG = {
    'Pet Supplies': {
        description: 'Food, treats and accessories for dogs, cats and other pets.',
        imageRatio: 'square',
        primaryFacet: 'animal',
        facets: [
            { key: 'animal', label: 'Animal type', source: 'spec', name: 'Animal Type', order: ['Dog', 'Cat', 'Bird', 'Fish', 'Small Pet', 'Other'] },
            { key: 'type', label: 'Product type', source: 'spec', name: 'Food Type', order: ['Dry Food', 'Wet Food', 'Treats', 'Toy', 'Accessory', 'Other'] }
        ],
        cardDetail: [{ type: 'spec', name: 'Animal Type' }, { type: 'spec', name: 'Food Type' }, { type: 'option', name: 'Pack Weight', order: PACK_WEIGHTS }]
    },

    'Electronics and Gadgets': {
        description: 'Phones, audio, accessories and everyday tech from local electronics sellers.',
        imageRatio: 'square',
        primaryFacet: 'connectivity',
        facets: [
            { key: 'connectivity', label: 'Connectivity', source: 'spec', name: 'Connectivity', order: ['Wired', 'Bluetooth', 'Wi-Fi', 'USB-C', 'NFC', 'Other'] },
            { key: 'storage', label: 'Storage', source: 'option', name: 'Storage Capacity', order: ['32GB', '64GB', '128GB', '256GB', '512GB', '1TB', '2TB'], display: 'grid' },
            { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' }
        ],
        cardDetail: [{ type: 'spec', name: 'Connectivity' }, { type: 'option', name: 'Storage Capacity', order: ['32GB', '64GB', '128GB', '256GB', '512GB', '1TB', '2TB'] }]
    },

    'House and Garden': {
        description: 'Furniture, decor and garden pieces for every room.',
        imageRatio: 'square',
        primaryFacet: 'room',
        facets: [
            { key: 'room', label: 'Room', source: 'spec', name: 'Room Type', order: ['Living Room', 'Bedroom', 'Kitchen', 'Bathroom', 'Garden', 'Office', 'Other'] },
            { key: 'size', label: 'Size', source: 'option', name: 'Size', order: ['Small', 'Medium', 'Large', 'Custom'], display: 'grid' },
            { key: 'material', label: 'Material', source: 'option', name: 'Material' },
            { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' },
            { key: 'assembly', label: 'Assembly required', source: 'spec', name: 'Assembly Required', order: ['Yes', 'No'] }
        ],
        cardDetail: [{ type: 'spec', name: 'Room Type' }, { type: 'spec', name: 'Material' }]
    },

    "Woman's Apparel": apparel,
    "Men's Apparel": apparel,

    'Kids and Baby': {
        description: 'Clothes, toys and essentials for babies and kids.',
        imageRatio: 'square',
        primaryFacet: 'age',
        facets: [
            { key: 'age', label: 'Age range', source: 'spec', name: 'Age Range', order: ['0-6 months', '6-12 months', '1-2 years', '3-5 years', '6-8 years', '9-12 years', 'All Ages'] },
            { key: 'size', label: 'Size', source: 'option', name: 'Size', order: KIDS_SIZES, display: 'grid' },
            { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' }
        ],
        cardDetail: [{ type: 'spec', name: 'Age Range' }, { type: 'option', name: 'Size', order: KIDS_SIZES, prefix: 'Sizes' }]
    },

    'Sports and Outdoors': {
        description: 'Gear and apparel for training, the trail and the court.',
        imageRatio: 'square',
        primaryFacet: 'activity',
        facets: [
            { key: 'activity', label: 'Activity', source: 'spec', name: 'Activity Type', order: ['Running', 'Gym', 'Camping', 'Cycling', 'Swimming', 'Team Sports', 'Outdoor', 'Other'] },
            { key: 'size', label: 'Size', source: 'option', name: 'Size', order: APPAREL_SIZES, display: 'grid' },
            { key: 'capacity', label: 'Capacity', source: 'option', name: 'Capacity', order: ['1L', '2L', '5L', '10L', '20L', '50L'], display: 'grid' },
            { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' }
        ],
        cardDetail: [{ type: 'spec', name: 'Activity Type' }, { type: 'option', name: 'Capacity', order: ['1L', '2L', '5L', '10L', '20L', '50L'] }]
    },

    'Health and Beauty': {
        description: 'Skincare, fragrance and personal care from independent shops.',
        imageRatio: 'square',
        primaryFacet: 'skin',
        facets: [
            { key: 'skin', label: 'Skin type', source: 'spec', name: 'Skin Type', order: ['Normal', 'Oily', 'Dry', 'Combination', 'Sensitive', 'All Skin Types'] },
            { key: 'scent', label: 'Scent', source: 'option', name: 'Scent', order: ['Unscented', 'Floral', 'Fresh', 'Citrus', 'Woody', 'Fruity', 'Other'] },
            { key: 'volume', label: 'Volume', source: 'option', name: 'Volume', order: VOLUMES, display: 'grid' }
        ],
        cardDetail: [{ type: 'spec', name: 'Skin Type' }, { type: 'option', name: 'Volume', order: VOLUMES }]
    }
};

const FALLBACK = {
    description: '',
    imageRatio: 'square',
    primaryFacet: null,
    facets: [
        { key: 'color', label: 'Color', source: 'option', name: 'Color', display: 'swatch' }
    ],
    cardDetail: []
};

export function categoryConfig(category) {
    return CONFIG[category] || FALLBACK;
}

/**
 * The values a product carries for one facet — a spec gives at most one,
 * a variant option can give several (e.g. sizes S, M, L).
 *
 * @returns {string[]}
 */
export function facetValuesOf(product, facet) {
    if (facet.source === 'spec') {
        const value = product.specifications?.[facet.name];

        return value ? [String(value)] : [];
    }

    const option = (product.options || []).find(
        o => (o.name || '').toLowerCase() === facet.name.toLowerCase()
    );

    if (!option) {
        return [];
    }

    return [...new Set((option.values || []).map(v => v.value).filter(Boolean))];
}

/** Sorts values by the facet's known order, then naturally. */
export function sortFacetValues(values, facet) {
    const order = facet.order || [];

    return [...values].sort((a, b) => {
        const ai = order.indexOf(a);
        const bi = order.indexOf(b);

        if (ai !== -1 && bi !== -1) {
            return ai - bi;
        }

        if (ai !== -1) {
            return -1;
        }

        if (bi !== -1) {
            return 1;
        }

        return String(a).localeCompare(String(b), undefined, { numeric: true });
    });
}

/**
 * One short line of real, category-relevant detail for a product card,
 * e.g. "Cat · Dry Food" or "Sizes S–XL". Empty string when the product has
 * none of the configured fields filled in — never a placeholder.
 */
export function cardDetailFor(product, config) {
    const parts = [];

    for (const part of config.cardDetail || []) {
        if (part.type === 'spec') {
            const value = product.specifications?.[part.name];

            if (value) {
                parts.push(String(value));
            }
        } else if (part.type === 'option') {
            // Read straight from the product's variant option, so a card can
            // describe an attribute (e.g. Pack Weight) that isn't a filter.
            const facet = { source: 'option', name: part.name, order: part.order };
            const values = sortFacetValues(facetValuesOf(product, facet), facet);

            if (values.length === 1) {
                parts.push(values[0]);
            } else if (values.length > 1) {
                const range = `${values[0]}–${values[values.length - 1]}`;

                parts.push(part.prefix ? `${part.prefix} ${range}` : range);
            }
        }

        if (parts.length === 2) {
            break;
        }
    }

    return parts.join(' · ');
}

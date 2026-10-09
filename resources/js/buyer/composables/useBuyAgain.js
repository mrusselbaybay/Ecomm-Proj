// resources/js/buyer/composables/useBuyAgain.js
//
// "Buy again" on a completed order: put the same products — the same
// variants, the same quantities — back in the cart, at today's prices and
// stock. The order itself is never touched.
//
// The products are fetched fresh in one request (GET /api/products?ids=,
// the cart's own re-check endpoint, with the catalogue's visibility rules),
// then every line is matched to exactly the variant that was bought.
// Nothing is substituted: a variant that's gone, inactive or sold out is
// reported by name instead, and so is a product that has since gained
// options (the buyer has to choose one). Quantities are capped at what's
// in stock, and the cap is reported too. The cart's own rules
// (addToCart) apply as for any other add; there are no per-buyer
// purchase limits in the catalogue beyond stock.
import { useBuyer } from './useBuyer';
import { fetchJson } from './useStores';

/**
 * @returns {Promise<{ added: Array<{ name: string, quantity: number, note: string }>, problems: Array<{ name: string, message: string }> }>}
 */
export async function buyAgain(order) {
    const { addToCart } = useBuyer();
    const items = order?.items || [];
    const ids = [...new Set(items.map(item => item.product_id).filter(Boolean))];

    const products = ids.length
        ? (await fetchJson(`/api/products?ids=${ids.map(encodeURIComponent).join(',')}`)).data || []
        : [];
    const byId = new Map(products.map(product => [product.id, product]));

    const added = [];
    const problems = [];

    for (const item of items) {
        const name = item.variation ? `${item.name} (${item.variation})` : (item.name || 'An item');
        const product = item.product_id ? byId.get(item.product_id) : null;
        const quantity = Math.max(1, Number(item.quantity) || 1);

        if (!product) {
            problems.push({ name, message: 'is no longer available.' });
            continue;
        }

        let variant = null;

        if (item.variant_id) {
            variant = (product.variants || []).find(candidate => candidate.id === item.variant_id) || null;

            if (!variant) {
                problems.push({ name, message: 'isn’t sold in this option anymore.' });
                continue;
            }

            if (variant.status && variant.status !== 'active') {
                problems.push({ name, message: 'is unavailable in this option right now.' });
                continue;
            }
        } else if (product.hasVariants) {
            problems.push({ name, message: 'now comes in options — choose one on its product page.' });
            continue;
        }

        const result = addToCart(product, variant, quantity, { silent: true });

        if (result.ok) {
            added.push({
                name,
                quantity,
                note: result.capped ? `only ${result.ceiling} available — your cart now has the most you can buy` : ''
            });
        } else if (result.reason === 'out_of_stock') {
            problems.push({ name, message: 'is out of stock.' });
        } else if (result.reason === 'cart_full') {
            problems.push({ name, message: `is already in your cart at the most available (${result.ceiling}).` });
        } else {
            problems.push({ name, message: 'couldn’t be added right now.' });
        }
    }

    return { added, problems };
}

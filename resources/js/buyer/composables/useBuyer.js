import { ref, computed } from 'vue';

const cart = ref([]);

/*
|--------------------------------------------------------------------------
| Favorites
|--------------------------------------------------------------------------
|
| Client-side only for now (no wishlist table/API yet). Stored as a Set of
| product IDs, shared across every component via this module-level ref —
| same pattern as `cart` above. Swap for a real API-backed version once a
| wishlist endpoint exists.
|
*/

const favoriteProductIds = ref(new Set());

function toggleFavorite(productId) {
    if (!productId) {
        return;
    }

    const next = new Set(favoriteProductIds.value);

    if (next.has(productId)) {
        next.delete(productId);
    } else {
        next.add(productId);
    }

    favoriteProductIds.value = next;
}

function isFavorite(productId) {
    return favoriteProductIds.value.has(productId);
}

/*
|--------------------------------------------------------------------------
| Cart
|--------------------------------------------------------------------------
*/

function addToCart(product, variation, quantity) {
    if (!product) {
        return;
    }

    const existingItem = cart.value.find(item =>
        item.productId === product.id &&
        item.variation === variation
    );

    if (existingItem) {
        existingItem.quantity += quantity;
        return;
    }

    cart.value.push({
        cartId: Date.now() + Math.random(),
        productId: product.id,
        name: product.name,
        price: Number(product.price),
        category: product.category,
        variation: variation,
        quantity: quantity,
        seller: product.seller || 'NEXMART Seller',
        selected: true
    });
}

function removeFromCart(cartId) {
    cart.value = cart.value.filter(item => item.cartId !== cartId);
}

function increaseCartQuantity(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item) {
        item.quantity++;
    }
}

function decreaseCartQuantity(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item && item.quantity > 1) {
        item.quantity--;
    }
}

function toggleCartItem(cartId) {
    const item = cart.value.find(item => item.cartId === cartId);

    if (item) {
        item.selected = !item.selected;
    }
}

function toggleSellerItems(seller, selected) {
    cart.value
        .filter(item => item.seller === seller)
        .forEach(item => {
            item.selected = selected;
        });
}

/*
|--------------------------------------------------------------------------
| Cart Computed Values
|--------------------------------------------------------------------------
*/

const selectedItems = computed(() => {
    return cart.value.filter(item => item.selected);
});

const selectedItemCount = computed(() => {
    return selectedItems.value.reduce(
        (total, item) => total + item.quantity,
        0
    );
});

const cartSubtotal = computed(() => {
    return selectedItems.value.reduce(
        (total, item) => total + (item.price * item.quantity),
        0
    );
});

const cartItemCount = computed(() => {
    return cart.value.reduce(
        (total, item) => total + item.quantity,
        0
    );
});

const sellers = computed(() => {
    return [...new Set(cart.value.map(item => item.seller))];
});

const isCartEmpty = computed(() => {
    return cart.value.length === 0;
});

/*
|--------------------------------------------------------------------------
| Select All
|--------------------------------------------------------------------------
*/

const allItemsSelected = computed(() => {
    return cart.value.length > 0 &&
        cart.value.every(item => item.selected);
});

function toggleSelectAll(selected) {
    cart.value.forEach(item => {
        item.selected = selected;
    });
}

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

export function useBuyer() {
    return {
        cart,
        sellers,

        selectedItems,
        selectedItemCount,
        cartSubtotal,
        cartItemCount,
        isCartEmpty,
        allItemsSelected,

        addToCart,
        removeFromCart,
        increaseCartQuantity,
        decreaseCartQuantity,
        toggleCartItem,
        toggleSellerItems,
        toggleSelectAll,

        toggleFavorite,
        isFavorite
    };
}
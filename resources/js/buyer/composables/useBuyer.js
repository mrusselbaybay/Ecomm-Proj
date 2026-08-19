import { ref, computed } from 'vue';

/*
|--------------------------------------------------------------------------
| Shared Buyer State
|--------------------------------------------------------------------------
|
| These refs are outside useBuyer(), which means every Buyer component
| that calls useBuyer() will share the same cart and orders.
|
*/

const cart = ref([]);
const orders = ref([]);

/*
|--------------------------------------------------------------------------
| Order Statuses
|--------------------------------------------------------------------------
|
| Keeping statuses in one place prevents spelling differences later.
| These can eventually match your database status values.
|
*/

const ORDER_STATUSES = {
    TO_SHIP: 'To Ship',
    IN_TRANSIT: 'In Transit',
    OUT_FOR_DELIVERY: 'Out for Delivery',
    DELIVERED: 'Delivered',
    CANCELLED: 'Cancelled',
    RETURNED: 'Returned'
};

const RETURN_REQUEST_STATUSES = {
    PENDING: 'Pending',
    APPROVED: 'Approved',
    REJECTED: 'Rejected',
    COMPLETED: 'Completed'
};

const RETURN_REQUEST_TYPES = [
    'return_and_refund',
    'refund_only'
];

const RETURN_REQUEST_REASONS = [
    'damaged',
    'wrong_item',
    'incomplete',
    'not_as_described',
    'quality_issue',
    'other'
];

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
    cart.value = cart.value.filter(
        item => item.cartId !== cartId
    );
}

function increaseCartQuantity(cartId) {
    const item = cart.value.find(
        item => item.cartId === cartId
    );

    if (item) {
        item.quantity++;
    }
}

function decreaseCartQuantity(cartId) {
    const item = cart.value.find(
        item => item.cartId === cartId
    );

    if (item && item.quantity > 1) {
        item.quantity--;
    }
}

function toggleCartItem(cartId) {
    const item = cart.value.find(
        item => item.cartId === cartId
    );

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
    return cart.value.filter(
        item => item.selected
    );
});

const selectedItemCount = computed(() => {
    return selectedItems.value.reduce(
        (total, item) =>
            total + item.quantity,
        0
    );
});

const cartSubtotal = computed(() => {
    return selectedItems.value.reduce(
        (total, item) =>
            total +
            (item.price * item.quantity),
        0
    );
});

const cartItemCount = computed(() => {
    return cart.value.reduce(
        (total, item) =>
            total + item.quantity,
        0
    );
});

const sellers = computed(() => {
    return [
        ...new Set(
            cart.value.map(
                item => item.seller
            )
        )
    ];
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
    return (
        cart.value.length > 0 &&
        cart.value.every(
            item => item.selected
        )
    );
});

function toggleSelectAll(selected) {
    cart.value.forEach(item => {
        item.selected = selected;
    });
}

/*
|--------------------------------------------------------------------------
| Orders
|--------------------------------------------------------------------------
|
| addOrder() receives the final order payload from Checkout.vue.
|
| For now, orders are stored in frontend memory.
|
| Later:
|
| Checkout.vue
|      ↓
| Laravel API
|      ↓
| Supabase/PostgreSQL
|      ↓
| API response
|      ↓
| addOrder(response)
|
| So the UI structure will not need to be completely redesigned later.
|
*/

function addOrder(orderData) {
    if (
        !orderData ||
        !Array.isArray(orderData.items) ||
        orderData.items.length === 0
    ) {
        return null;
    }

    const order = {
        /*
         * Temporary frontend order ID.
         *
         * Later the database-generated order ID can replace this.
         */
        orderId:
            orderData.orderId ||
            `NX-${Date.now()}`,

        /*
         * Newly placed orders start as To Ship.
         */
        status:
        orderData.status ||
        ORDER_STATUSES.DELIVERED,

        /*
         * Copy products so changing the cart later
         * does not modify an existing order.
         */
        items: orderData.items.map(item => ({
            ...item,

            /*
             * Each purchased item can receive one review.
             * This remains null until the buyer submits a rating.
             */
            review: item.review || null,

            /*
             * A delivered order item may receive one return/refund request.
             */
            returnRequest:
                item.returnRequest || null
        })),

        /*
         * Checkout information
         */
        delivery_address:
            orderData.delivery_address || null,

        shipping_method:
            orderData.shipping_method || null,

        voucher_code:
            orderData.voucher_code || null,

        payment_method:
            orderData.payment_method || null,

        /*
         * Financial summary
         */
        subtotal:
            Number(orderData.subtotal || 0),

        shipping_fee:
            Number(orderData.shipping_fee || 0),

        discount:
            Number(orderData.discount || 0),

        total:
            Number(orderData.total || 0),

        /*
         * Temporary frontend timestamp.
         *
         * Later this can come directly from the database.
         */
        createdAt:
            orderData.createdAt ||
            new Date().toISOString()
    };

    /*
     * Newest orders appear first.
     */
    orders.value.unshift(order);

    return order;
}

/*
|--------------------------------------------------------------------------
| Find Order
|--------------------------------------------------------------------------
*/

function findOrderById(orderId) {
    return orders.value.find(
        order =>
            order.orderId === orderId
    ) || null;
}

/*
|--------------------------------------------------------------------------
| Orders By Status
|--------------------------------------------------------------------------
*/

function getOrdersByStatus(status) {
    if (!status || status === 'All') {
        return orders.value;
    }

    return orders.value.filter(
        order =>
            order.status === status
    );
}

/*
|--------------------------------------------------------------------------
| Update Order Status
|--------------------------------------------------------------------------
|
| Useful for our frontend prototype.
|
| Later the Seller / Logistics side will update the status
| through the database/API instead.
|
*/

function updateOrderStatus(
    orderId,
    newStatus
) {
    const order = findOrderById(orderId);

    if (!order) {
        return false;
    }

    order.status = newStatus;

    return true;
}


/*
|--------------------------------------------------------------------------
| Cancel Order
|--------------------------------------------------------------------------
|
| Buyers can only cancel orders that are still waiting to be shipped.
|
| Later this function can be replaced with a Laravel API request.
|
*/

function cancelOrder(
    orderId,
    reason = 'Cancelled by buyer'
) {
    const order = findOrderById(orderId);

    if (!order) {
        return false;
    }

    /*
     * Buyer can only cancel while the order
     * is still under To Ship.
     */
    if (
        order.status !==
        ORDER_STATUSES.TO_SHIP
    ) {
        return false;
    }

    order.status =
        ORDER_STATUSES.CANCELLED;

    order.cancellationReason = reason;

    order.cancelledAt =
        new Date().toISOString();

    return true;
}

/*
|--------------------------------------------------------------------------
| Product Review
|--------------------------------------------------------------------------
|
| A buyer can review each item once, and only after the order is delivered.
| For now the review is stored on the order item. Later, this function can be
| replaced with a Laravel API request that inserts into a reviews table.
|
*/

function submitReview(
    orderId,
    itemIndex,
    reviewData
) {
    const order = findOrderById(orderId);

    if (
        !order ||
        order.status !== ORDER_STATUSES.DELIVERED
    ) {
        return null;
    }

    const item = order.items?.[itemIndex];

    /*
     * Prevent reviews for missing items and duplicate reviews.
     */
    if (!item || item.review) {
        return null;
    }

    const rating = Number(reviewData?.rating);

    if (
        !Number.isInteger(rating) ||
        rating < 1 ||
        rating > 5
    ) {
        return null;
    }

    const review = {
        /* Temporary frontend ID until the database creates one. */
        reviewId: `RV-${Date.now()}`,
        orderId: order.orderId,
        productId:
            item.productId ??
            item.product_id ??
            null,
        rating,
        comment: String(
            reviewData?.comment || ''
        ).trim().slice(0, 500),
        createdAt: new Date().toISOString()
    };

    item.review = review;

    return review;
}

/*
|--------------------------------------------------------------------------
| Return / Refund Request
|--------------------------------------------------------------------------
|
| The Buyer can submit one request per delivered order item. For now the
| request is stored in frontend memory with a Pending status. Later this can
| post the data and evidence files to a Laravel API using FormData.
|
*/

function submitReturnRequest(
    orderId,
    itemIndex,
    requestData
) {
    const order = findOrderById(orderId);

    if (
        !order ||
        order.status !== ORDER_STATUSES.DELIVERED
    ) {
        return null;
    }

    const item = order.items?.[itemIndex];

    /*
     * Prevent requests for missing items and duplicate requests.
     */
    if (!item || item.returnRequest) {
        return null;
    }

    const requestType = String(
        requestData?.requestType || ''
    );

    const reason = String(
        requestData?.reason || ''
    );

    const quantity = Number(
        requestData?.quantity
    );

    const details = String(
        requestData?.details || ''
    ).trim().slice(0, 1000);

    const evidenceFiles = Array.isArray(
        requestData?.evidence
    )
        ? requestData.evidence
        : [];

    const evidenceIsValid = (
        evidenceFiles.length >= 1 &&
        evidenceFiles.length <= 3 &&
        evidenceFiles.every(file =>
            file &&
            String(file.type || '').startsWith(
                'image/'
            ) &&
            Number(file.size || 0) <=
                (5 * 1024 * 1024)
        )
    );

    if (
        !RETURN_REQUEST_TYPES.includes(
            requestType
        ) ||
        !RETURN_REQUEST_REASONS.includes(
            reason
        ) ||
        !Number.isInteger(quantity) ||
        quantity < 1 ||
        quantity > Number(item.quantity || 0) ||
        details.length < 10 ||
        !evidenceIsValid
    ) {
        return null;
    }

    const returnRequest = {
        /* Temporary frontend ID until the database creates one. */
        requestId: `RR-${Date.now()}`,
        orderId: order.orderId,
        productId:
            item.productId ??
            item.product_id ??
            null,
        requestType,
        reason,
        quantity,
        details,
        evidence: evidenceFiles.map(file => ({
            name: String(file.name || 'Evidence'),
            type: String(file.type || ''),
            size: Number(file.size || 0)
        })),
        status:
            RETURN_REQUEST_STATUSES.PENDING,
        submittedAt: new Date().toISOString(),
        updatedAt: new Date().toISOString()
    };

    item.returnRequest = returnRequest;

    return returnRequest;
}

/*
|--------------------------------------------------------------------------
| Order Computed Values
|--------------------------------------------------------------------------
*/

const orderCount = computed(() => {
    return orders.value.length;
});

const activeOrderCount = computed(() => {
    return orders.value.filter(order =>
        order.status !==
            ORDER_STATUSES.DELIVERED &&
        order.status !==
            ORDER_STATUSES.CANCELLED &&
        order.status !==
            ORDER_STATUSES.RETURNED
    ).length;
});

/*
|--------------------------------------------------------------------------
| Export
|--------------------------------------------------------------------------
*/

export function useBuyer() {
    return {
        /*
        |--------------------------------------------------------------------------
        | Cart State
        |--------------------------------------------------------------------------
        */

        cart,
        sellers,

        selectedItems,
        selectedItemCount,
        cartSubtotal,
        cartItemCount,
        isCartEmpty,
        allItemsSelected,

        /*
        |--------------------------------------------------------------------------
        | Cart Functions
        |--------------------------------------------------------------------------
        */

        addToCart,
        removeFromCart,
        increaseCartQuantity,
        decreaseCartQuantity,
        toggleCartItem,
        toggleSellerItems,
        toggleSelectAll,

<<<<<<< HEAD
        toggleFavorite,
        isFavorite
=======
        /*
        |--------------------------------------------------------------------------
        | Order State
        |--------------------------------------------------------------------------
        */

        orders,
        orderCount,
        activeOrderCount,
        ORDER_STATUSES,
        RETURN_REQUEST_STATUSES,

        /*
        |--------------------------------------------------------------------------
        | Order Functions
        |--------------------------------------------------------------------------
        */

<<<<<<< HEAD
      addOrder,
findOrderById,
getOrdersByStatus,
updateOrderStatus,
cancelOrder
>>>>>>> 87c29c1 (feat: add buyer checkout orders tracking and cancellation)
=======
        addOrder,
        findOrderById,
        getOrdersByStatus,
        updateOrderStatus,
        cancelOrder,
        submitReview,
        submitReturnRequest
>>>>>>> 036ce43 (feat: add buyer reviews returns and account profile)
    };
}
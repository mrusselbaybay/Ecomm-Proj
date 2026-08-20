<script setup>
import { useBuyer } from '../composables/useBuyer';

const emit = defineEmits([
    'back',
    'checkout'
]);

const {
    cart,
    sellers,
    selectedItems,
    selectedItemCount,
    cartSubtotal,
    allItemsSelected,

    removeFromCart,
    increaseCartQuantity,
    decreaseCartQuantity,

    toggleCartItem,
    toggleSellerItems,
    toggleSelectAll
} = useBuyer();

/*
|--------------------------------------------------------------------------
| Seller Items
|--------------------------------------------------------------------------
*/

function sellerItems(seller) {
    return cart.value.filter(
        item => item.seller === seller
    );
}

/*
|--------------------------------------------------------------------------
| Seller Selection
|--------------------------------------------------------------------------
*/

function isSellerSelected(seller) {
    const items = sellerItems(seller);

    return (
        items.length > 0 &&
        items.every(item => item.selected)
    );
}

function handleSellerSelection(seller, event) {
    toggleSellerItems(
        seller,
        event.target.checked
    );
}

/*
|--------------------------------------------------------------------------
| Item Selection
|--------------------------------------------------------------------------
*/

function handleItemSelection(cartId) {
    toggleCartItem(cartId);
}

/*
|--------------------------------------------------------------------------
| Select All
|--------------------------------------------------------------------------
*/

function handleSelectAll(event) {
    toggleSelectAll(
        event.target.checked
    );
}

/*
|--------------------------------------------------------------------------
| Currency
|--------------------------------------------------------------------------
*/

function formatPrice(price) {
    return `₱${Number(price).toFixed(2)}`;
}

/*
|--------------------------------------------------------------------------
| Checkout
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| Cart no longer displays a temporary checkout alert.
|
| It sends ONLY the selected cart items to Dashboard.vue.
| Dashboard then opens the same Checkout.vue used by Buy Now.
|
*/

function checkout() {
    if (selectedItems.value.length === 0) {
        alert('Please select at least one item.');
        return;
    }

    const checkoutItems = selectedItems.value.map(
        item => ({
            cartId: item.cartId,
            productId: item.productId,
            name: item.name,
            price: Number(item.price),
            category: item.category,
            variation: item.variation,
            quantity: Number(item.quantity),
            seller:
                item.seller ||
                'NEXMART Seller'
        })
    );

    emit(
        'checkout',
        checkoutItems
    );
}
</script>

<template>

    <div class="buyer-cart-page">

        <!-- ============================================================ -->
        <!-- HEADER -->
        <!-- ============================================================ -->

        <header class="cart-page-header">

            <button
                type="button"
                class="cart-back-button"
                @click="emit('back')"
            >
                ← Continue Shopping
            </button>

            <h1>
                Shopping Cart
            </h1>

        </header>

        <!-- ============================================================ -->
        <!-- EMPTY CART -->
        <!-- ============================================================ -->

        <div
            v-if="cart.length === 0"
            class="empty-cart"
        >

            <div class="empty-cart-icon">
                🛒
            </div>

            <h2>
                Your cart is empty
            </h2>

            <p>
                Add some products to your cart first.
            </p>

            <button
                type="button"
                class="continue-shopping-button"
                @click="emit('back')"
            >
                Continue Shopping
            </button>

        </div>

        <!-- ============================================================ -->
        <!-- CART CONTENT -->
        <!-- ============================================================ -->

        <main
            v-else
            class="cart-content"
        >

            <!-- ======================================================== -->
            <!-- SELECT ALL -->
            <!-- ======================================================== -->

            <div class="cart-select-all">

                <label>

                    <input
                        type="checkbox"
                        :checked="allItemsSelected"
                        @change="handleSelectAll"
                    />

                    <span>
                        Select All
                    </span>

                </label>

            </div>

            <!-- ======================================================== -->
            <!-- SELLERS -->
            <!-- ======================================================== -->

            <section
                v-for="seller in sellers"
                :key="seller"
                class="seller-cart-section"
            >

                <!-- Seller Header -->
                <div class="seller-cart-header">

                    <label class="seller-select">

                        <input
                            type="checkbox"
                            :checked="
                                isSellerSelected(seller)
                            "
                            @change="
                                handleSellerSelection(
                                    seller,
                                    $event
                                )
                            "
                        />

                        <strong>
                            {{ seller }}
                        </strong>

                    </label>

                </div>

                <!-- ==================================================== -->
                <!-- SELLER PRODUCTS -->
                <!-- ==================================================== -->

                <div
                    v-for="item in sellerItems(seller)"
                    :key="item.cartId"
                    class="cart-item"
                >

                    <!-- Item Selection -->
                    <div class="cart-item-select">

                        <input
                            type="checkbox"
                            :checked="item.selected"
                            @change="
                                handleItemSelection(
                                    item.cartId
                                )
                            "
                        />

                    </div>

                    <!-- Product Image -->
                    <div class="cart-item-image">
                        Product Image
                    </div>

                    <!-- Product Information -->
                    <div class="cart-item-info">

                        <h3>
                            {{ item.name }}
                        </h3>

                        <span class="cart-item-category">
                            {{ item.category }}
                        </span>

                        <p class="cart-item-variation">
                            Variation:
                            {{ item.variation }}
                        </p>

                    </div>

                    <!-- Price -->
                    <div class="cart-item-price">

                        {{
                            formatPrice(
                                item.price
                            )
                        }}

                    </div>

                    <!-- Quantity -->
                    <div class="cart-item-quantity">

                        <button
                            type="button"
                            @click="
                                decreaseCartQuantity(
                                    item.cartId
                                )
                            "
                        >
                            −
                        </button>

                        <span>
                            {{ item.quantity }}
                        </span>

                        <button
                            type="button"
                            @click="
                                increaseCartQuantity(
                                    item.cartId
                                )
                            "
                        >
                            +
                        </button>

                    </div>

                    <!-- Item Total -->
                    <div class="cart-item-total">

                        {{
                            formatPrice(
                                item.price *
                                item.quantity
                            )
                        }}

                    </div>

                    <!-- Remove -->
                    <button
                        type="button"
                        class="cart-item-remove"
                        title="Remove item"
                        @click="
                            removeFromCart(
                                item.cartId
                            )
                        "
                    >
                        🗑
                    </button>

                </div>

            </section>

            <!-- ======================================================== -->
            <!-- CHECKOUT BAR -->
            <!-- ======================================================== -->

            <section class="cart-checkout-bar">

                <div class="cart-summary">

                    <span>
                        Selected Items:
                    </span>

                    <strong>
                        {{ selectedItemCount }}
                    </strong>

                </div>

                <div class="cart-subtotal">

                    <span>
                        Subtotal:
                    </span>

                    <strong>
                        {{
                            formatPrice(
                                cartSubtotal
                            )
                        }}
                    </strong>

                </div>

                <button
                    type="button"
                    class="checkout-button"
                    :disabled="
                        selectedItems.length === 0
                    "
                    @click="checkout"
                >
                    Checkout
                </button>

            </section>

        </main>

    </div>

</template>
<script setup>
import { computed, ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';

const props = defineProps({
    product: {
        type: Object,
        default: null
    }
});

const emit = defineEmits([
    'back',
    'buy-now'
]);

const { addToCart } = useBuyer();

const quantity = ref(1);
const selectedVariation = ref('');

/*
|--------------------------------------------------------------------------
| Variations
|--------------------------------------------------------------------------
*/

const variations = computed(() => {
    if (!props.product) {
        return [];
    }

    if (props.product.category === 'Fashion') {
        return [
            'Small',
            'Medium',
            'Large',
            'XL'
        ];
    }

    if (props.product.category === 'Electronics') {
        return [
            'Black',
            'White'
        ];
    }

    return [
        'Default'
    ];
});

/*
|--------------------------------------------------------------------------
| Quantity
|--------------------------------------------------------------------------
*/

function increaseQuantity() {
    quantity.value++;
}

function decreaseQuantity() {
    if (quantity.value > 1) {
        quantity.value--;
    }
}

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

function validateSelection() {
    if (!props.product) {
        return false;
    }

    if (!selectedVariation.value) {
        alert('Please select a variation.');
        return false;
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| Add To Cart
|--------------------------------------------------------------------------
*/

function handleAddToCart() {
    if (!validateSelection()) {
        return;
    }

    addToCart(
        props.product,
        selectedVariation.value,
        quantity.value
    );

    alert(
        `${props.product.name} added to cart!\n` +
        `Quantity: ${quantity.value}\n` +
        `Variation: ${selectedVariation.value}`
    );
}

/*
|--------------------------------------------------------------------------
| Buy Now
|--------------------------------------------------------------------------
*/

function handleBuyNow() {
    if (!validateSelection()) {
        return;
    }

    emit('buy-now', {
        product: props.product,
        variation: selectedVariation.value,
        quantity: quantity.value
    });
}

/*
|--------------------------------------------------------------------------
| Back
|--------------------------------------------------------------------------
*/

function goBack() {
    emit('back');
}
</script>

<template>

    <div
        v-if="product"
        class="product-details-page"
    >

        <!-- ============================================================ -->
        <!-- BACK -->
        <!-- ============================================================ -->

        <button
            type="button"
            class="back-button"
            @click="goBack"
        >
            Back to Products
        </button>

        <!-- ============================================================ -->
        <!-- PRODUCT DETAILS -->
        <!-- ============================================================ -->

        <div class="product-details-card">

            <!-- Product Image -->
            <div class="product-details-image">
                Product Image
            </div>

            <!-- Product Information -->
            <div class="product-details-info">

                <!-- Category -->
                <span class="product-category">
                    {{ product.category }}
                </span>

                <!-- Product Name -->
                <h1>
                    {{ product.name }}
                </h1>

                <!-- Price -->
                <p class="product-details-price">
                    ₱{{ Number(product.price).toFixed(2) }}
                </p>

                <!-- Description -->
                <p class="product-description">
                    Select your preferred variation and quantity
                    before adding this product to your cart or
                    purchasing it immediately.
                </p>

                <!-- ==================================================== -->
                <!-- VARIATION -->
                <!-- ==================================================== -->

                <div class="variation-section">

                    <label for="product-variation">
                        Variation
                    </label>

                    <select
                        id="product-variation"
                        v-model="selectedVariation"
                    >

                        <option
                            value=""
                            disabled
                        >
                            Select a variation
                        </option>

                        <option
                            v-for="variation in variations"
                            :key="variation"
                            :value="variation"
                        >
                            {{ variation }}
                        </option>

                    </select>

                </div>

                <!-- ==================================================== -->
                <!-- QUANTITY -->
                <!-- ==================================================== -->

                <div class="quantity-section">

                    <label>
                        Quantity
                    </label>

                    <div class="quantity-control">

                        <button
                            type="button"
                            @click="decreaseQuantity"
                        >
                            -
                        </button>

                        <span>
                            {{ quantity }}
                        </span>

                        <button
                            type="button"
                            @click="increaseQuantity"
                        >
                            +
                        </button>

                    </div>

                </div>

                <!-- ==================================================== -->
                <!-- ACTIONS -->
                <!-- ==================================================== -->

                <div class="product-actions">

                    <button
                        type="button"
                        class="add-to-cart-button"
                        @click="handleAddToCart"
                    >
                        Add to Cart
                    </button>

                    <button
                        type="button"
                        class="buy-now-button"
                        @click="handleBuyNow"
                    >
                        Buy Now
                    </button>

                </div>

            </div>

        </div>

    </div>

    <!-- ================================================================ -->
    <!-- PRODUCT NOT FOUND -->
    <!-- ================================================================ -->

    <div
        v-else
        class="product-not-found"
    >

        <h2>
            Product not found.
        </h2>

        <button
            type="button"
            class="back-button"
            @click="goBack"
        >
            Back to Products
        </button>

    </div>

</template>
<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        default: null
    }
});

const emit = defineEmits(['back']);

const quantity = ref(1);
const selectedVariation = ref('');

const variations = computed(() => {
    if (!props.product) {
        return [];
    }

    if (props.product.category === 'Fashion') {
        return ['Small', 'Medium', 'Large', 'XL'];
    }

    if (props.product.category === 'Electronics') {
        return ['Black', 'White'];
    }

    return ['Default'];
});

function increaseQuantity() {
    quantity.value++;
}

function decreaseQuantity() {
    if (quantity.value > 1) {
        quantity.value--;
    }
}

function addToCart() {
    if (!selectedVariation.value) {
        alert('Please select a variation.');
        return;
    }

    alert(
        `${props.product.name} added to cart!\n` +
        `Quantity: ${quantity.value}\n` +
        `Variation: ${selectedVariation.value}`
    );
}
</script>

<template>
    <div
        v-if="product"
        class="product-details-page"
    >

        <!-- Back -->
        <button
            type="button"
            class="back-button"
            @click="emit('back')"
        >
            ← Back to Products
        </button>

        <div class="product-details-card">

            <!-- Product Image -->
            <div class="product-details-image">
                Product Image
            </div>

            <!-- Product Information -->
            <div class="product-details-info">

                <span class="product-category">
                    {{ product.category }}
                </span>

                <h1>
                    {{ product.name }}
                </h1>

                <p class="product-details-price">
                    ₱{{ product.price.toFixed(2) }}
                </p>

                <p class="product-description">
                    This is a sample product description.
                    Product information, seller details,
                    variations, and other product information
                    will be connected to the actual product
                    database later.
                </p>

                <!-- Variation -->
                <div class="variation-section">

                    <label>
                        Select Variation
                    </label>

                    <select v-model="selectedVariation">
                        <option
                            value=""
                            disabled
                        >
                            Choose a variation
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

                <!-- Quantity -->
                <div class="quantity-section">

                    <label>
                        Quantity
                    </label>

                    <div class="quantity-control">

                        <button
                            type="button"
                            @click="decreaseQuantity"
                        >
                            −
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

                <!-- Add To Cart -->
                <button
                    type="button"
                    class="add-to-cart-button"
                    @click="addToCart"
                >
                    Add to Cart
                </button>

            </div>

        </div>

    </div>

    <div
        v-else
        class="product-not-found"
    >
        <h2>
            Product not found
        </h2>

        <button
            type="button"
            @click="emit('back')"
        >
            Back to Products
        </button>
    </div>
</template>
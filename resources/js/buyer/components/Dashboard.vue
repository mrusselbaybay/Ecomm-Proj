<script setup>
import { ref, computed } from 'vue';
import ProductDetails from './ProductDetails.vue';

const searchQuery = ref('');
const selectedCategory = ref('All');
const selectedProduct = ref(null);

const categories = [
    'All',
    'Electronics',
    'Fashion',
    'Home & Living',
    'Beauty',
    'Sports',
    'Groceries'
];

const products = [
    {
        id: 1,
        name: 'Sample Product 1',
        price: 299,
        category: 'Electronics'
    },
    {
        id: 2,
        name: 'Sample Product 2',
        price: 499,
        category: 'Fashion'
    },
    {
        id: 3,
        name: 'Sample Product 3',
        price: 799,
        category: 'Home & Living'
    }
];

/*
|--------------------------------------------------------------------------
| Filter Products
|--------------------------------------------------------------------------
*/

const filteredProducts = computed(() => {
    const search = searchQuery.value.trim().toLowerCase();

    return products.filter(product => {
        const matchesCategory =
            selectedCategory.value === 'All' ||
            product.category === selectedCategory.value;

        const matchesSearch =
            !search ||
            product.name.toLowerCase().includes(search) ||
            product.category.toLowerCase().includes(search);

        return matchesCategory && matchesSearch;
    });
});

/*
|--------------------------------------------------------------------------
| Category
|--------------------------------------------------------------------------
*/

function selectCategory(category) {
    selectedCategory.value = category;
}

/*
|--------------------------------------------------------------------------
| Product
|--------------------------------------------------------------------------
*/

function viewProduct(product) {
    selectedProduct.value = product;
}

function backToProducts() {
    selectedProduct.value = null;
}

/*
|--------------------------------------------------------------------------
| Clear Filters
|--------------------------------------------------------------------------
*/

function clearFilters() {
    searchQuery.value = '';
    selectedCategory.value = 'All';
}
</script>

<template>

    <!-- Product Details -->
    <ProductDetails
        v-if="selectedProduct"
        :product="selectedProduct"
        @back="backToProducts"
    />

    <!-- Buyer Dashboard -->
    <div
        v-else
        class="buyer-page"
    >

        <!-- Header -->
        <header class="buyer-header">

            <!-- Logo -->
            <div class="buyer-logo">
                NEXMART
            </div>

            <!-- Search -->
            <div class="buyer-search">

                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search products..."
                />

                <button
                    type="button"
                    title="Search"
                >
                    🔍
                </button>

            </div>

            <!-- Buyer Actions -->
            <nav class="buyer-actions">

                <button
                    type="button"
                    title="Messages"
                >
                    💬
                </button>

                <button
                    type="button"
                    title="Cart"
                >
                    🛒
                </button>

                <button
                    type="button"
                    title="Account"
                >
                    👤
                </button>

            </nav>

        </header>

        <!-- Main -->
        <main class="buyer-main">

            <!-- Welcome -->
            <section class="welcome-section">

                <h1>
                    Welcome to NEXMART!
                </h1>

                <p>
                    Discover products from verified local sellers.
                </p>

            </section>

            <!-- Categories -->
            <section class="buyer-section">

                <div class="section-header">
                    <h2>
                        Categories
                    </h2>
                </div>

                <div class="category-list">

                    <button
                        v-for="category in categories"
                        :key="category"
                        type="button"
                        class="category-button"
                        :class="{
                            active: selectedCategory === category
                        }"
                        @click="selectCategory(category)"
                    >
                        {{ category }}
                    </button>

                </div>

            </section>

            <!-- Products -->
            <section class="buyer-section">

                <div class="section-header">

                    <h2>
                        Products
                    </h2>

                </div>

                <!-- No Products -->
                <div
                    v-if="filteredProducts.length === 0"
                    class="empty-products"
                >

                    <p>
                        No products found.
                    </p>

                    <button
                        type="button"
                        @click="clearFilters"
                    >
                        Clear Filters
                    </button>

                </div>

                <!-- Product Grid -->
                <div
                    v-else
                    class="product-grid"
                >

                    <article
                        v-for="product in filteredProducts"
                        :key="product.id"
                        class="product-card"
                    >

                        <!-- Product Image -->
                        <div class="product-image">
                            Product Image
                        </div>

                        <!-- Product Information -->
                        <div class="product-info">

                            <span class="product-category">
                                {{ product.category }}
                            </span>

                            <h3>
                                {{ product.name }}
                            </h3>

                            <p class="product-price">
                                ₱{{ product.price.toFixed(2) }}
                            </p>

                            <button
                                type="button"
                                class="view-product-button"
                                @click="viewProduct(product)"
                            >
                                View Product
                            </button>

                        </div>

                    </article>

                </div>

            </section>

        </main>

    </div>

</template>
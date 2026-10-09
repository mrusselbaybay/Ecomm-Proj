<script setup>
/*
| Site footer. The old newsletter form only confirmed locally (there is no
| subscribers endpoint), so it was replaced with real navigation instead of
| a "you're on the list" message that wasn't true.
*/
import BrandMark from './BrandMark.vue';
import { requestBuyerView } from '../composables/useBuyerNav';
import { categories } from '../composables/useCategoryMeta';

const emit = defineEmits([
    'browse-all',
    'browse-categories',
    'cart-click'
]);

const footerCategories = categories.filter(category => category !== 'All');

const accountLinks = [
    { view: 'orders', label: 'Orders & tracking' },
    { view: 'wishlist', label: 'Wishlist' },
    { view: 'reviews', label: 'My reviews' },
    { view: 'account', section: 'addresses', label: 'Addresses' },
    { view: 'account', section: 'help', label: 'Help & support' }
];
</script>

<template>

    <footer class="site-footer">

        <div class="site-footer-top">

            <div class="site-footer-brand">
                <BrandMark inverse />
                <p>
                    A marketplace for independent sellers across the Philippines. Compare listings, message sellers and track every order in one place.
                </p>
            </div>

            <nav
                class="site-footer-col"
                aria-label="Shop"
            >
                <h2 class="site-footer-heading">Shop</h2>
                <button
                    type="button"
                    @click="emit('browse-all')"
                >
                    All products
                </button>
                <button
                    type="button"
                    @click="requestBuyerView('deals')"
                >
                    Deals
                </button>
                <button
                    type="button"
                    @click="requestBuyerView('stores')"
                >
                    Stores
                </button>
                <button
                    type="button"
                    @click="emit('cart-click')"
                >
                    Your cart
                </button>
            </nav>

            <nav
                class="site-footer-col"
                aria-label="Categories"
            >
                <h2 class="site-footer-heading">Categories</h2>
                <button
                    v-for="category in footerCategories"
                    :key="category"
                    type="button"
                    @click="requestBuyerView('category', category)"
                >
                    {{ category }}
                </button>
            </nav>

            <nav
                class="site-footer-col"
                aria-label="Your account"
            >
                <h2 class="site-footer-heading">Your account</h2>
                <button
                    v-for="link in accountLinks"
                    :key="link.label"
                    type="button"
                    @click="requestBuyerView(link.view, link.section ? { section: link.section } : null)"
                >
                    {{ link.label }}
                </button>
            </nav>

        </div>

        <div class="site-footer-bottom">
            <span>&copy; {{ new Date().getFullYear() }} BuyTheWay</span>
            <span>Prices shown in Philippine peso (&#8369;)</span>
        </div>

    </footer>

</template>

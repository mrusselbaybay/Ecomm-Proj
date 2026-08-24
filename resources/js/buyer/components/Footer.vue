<script setup>
import { ref } from 'vue';

const emit = defineEmits([
    'browse-all',
    'browse-categories',
    'cart-click',
    'subscribe'
]);

const newsletterEmail = ref('');
const newsletterSubscribed = ref(false);

function subscribeNewsletter() {
    if (!newsletterEmail.value.trim()) {
        return;
    }

    emit('subscribe', newsletterEmail.value);

    // No backend endpoint yet — this only confirms locally, same as Dashboard.
    newsletterSubscribed.value = true;
    newsletterEmail.value = '';
}
</script>

<template>

    <footer class="buyer-footer">

        <div class="buyer-footer-top">

            <div class="buyer-footer-brand">

                <div class="buyer-logo">
                    <span class="buyer-logo-badge">N</span>
                    <span class="buyer-logo-text light">NEX<span class="accent">MART</span></span>
                </div>

                <p>
                    Your local marketplace — quality goods from verified sellers, delivered to your door.
                </p>

                <div
                    class="buyer-footer-social"
                    aria-hidden="true"
                >
                    <span>f</span>
                    <span>𝕏</span>
                    <span>◎</span>
                </div>

            </div>

            <nav class="buyer-footer-links">

                <span class="buyer-footer-heading">
                    Shop
                </span>

                <a
                    href="#buyer-products"
                    @click="emit('browse-all')"
                >
                    All Products
                </a>

                <button
                    type="button"
                    @click="emit('browse-categories')"
                >
                    Popular Categories
                </button>

                <a
                    href="#"
                    @click.prevent="emit('cart-click')"
                >
                    My Cart
                </a>

            </nav>

            <div class="buyer-footer-newsletter">

                <span class="buyer-footer-heading">
                    Newsletter
                </span>

                <p>
                    Get updates on new arrivals from local sellers.
                </p>

                <form
                    class="buyer-footer-newsletter-form"
                    @submit.prevent="subscribeNewsletter"
                >

                    <input
                        v-model="newsletterEmail"
                        type="email"
                        placeholder="Enter your email address"
                        required
                    >

                    <button type="submit">
                        Subscribe Now
                    </button>

                </form>

                <p
                    v-if="newsletterSubscribed"
                    class="buyer-footer-newsletter-note"
                >
                    Thanks — you're on the list!
                </p>

            </div>

        </div>

        <div class="buyer-footer-bottom">
            <span>© {{ new Date().getFullYear() }} NEXMART. All rights reserved.</span>
        </div>

    </footer>

</template>
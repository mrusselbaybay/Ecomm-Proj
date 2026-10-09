<script setup>
/*
|--------------------------------------------------------------------------
| AccountLayout — the one frame for every account page
|--------------------------------------------------------------------------
|
| Header, AccountNav and the page area, mounted once by Dashboard for
| Account settings, My Orders (with order details and tracking), Wishlist
| and My Reviews. Those pages render only their content into the slot, so
| moving between them swaps the content while the sidebar stays exactly
| where it is: same width, column, gap, sticky offset and breakpoint
| (all from .acc-layout / .acc-nav in layout.css).
|
*/
import AccountNav from './AccountNav.vue';
import Footer from './Footer.vue';
import Header from './Header.vue';

defineProps({
    // The AccountNav item to mark as the current page.
    active: {
        type: String,
        required: true
    }
});

const emit = defineEmits(['back', 'search', 'select-category', 'open-cart']);
</script>

<template>

    <div class="buyer-page">

        <Header
            active-view="account"
            @select-category="emit('select-category', $event)"
            @cart-click="emit('open-cart')"
            @logo-click="emit('back')"
            @search="emit('search', $event)"
        />

        <main
            id="main-content"
            class="buyer-main acc-page"
            tabindex="-1"
        >
            <div class="acc-layout">
                <AccountNav :active="active" />

                <div class="acc-content">
                    <slot />
                </div>
            </div>
        </main>

        <Footer
            @browse-all="emit('back')"
            @browse-categories="emit('back')"
            @cart-click="emit('open-cart')"
        />

    </div>

</template>

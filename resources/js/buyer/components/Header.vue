<script setup>
import { ref } from 'vue';
import { useBuyer } from '../composables/useBuyer';
import { useBuyerChat } from '../composables/useBuyerChat';
import { categories } from '../composables/useCategoryMeta';

const props = defineProps({
    /*
    |----------------------------------------------------------------------
    | activeCategory
    |----------------------------------------------------------------------
    |
    | On the dashboard this is the live filter ('All', 'Electronics and
    | Gadgets', ...).
    | On other pages (e.g. Product Details) pass the current product's
    | category so the matching subnav tab highlights; pass '' to highlight
    | nothing.
    |
    */
    activeCategory: {
        type: String,
        default: 'All'
    },
    searchQuery: {
        type: String,
        default: ''
    }
});

const emit = defineEmits([
    'update:searchQuery',
    'search',
    'select-category',
    'account-click',
    'cart-click',
    'logo-click'
]);

const { cartItemCount } = useBuyer();
const { totalUnread, toggleChat } = useBuyerChat();

const localSearch = ref(props.searchQuery);

function handleSearchInput(event) {
    localSearch.value = event.target.value;
    emit('update:searchQuery', localSearch.value);
}

function handleSearchSubmit() {
    emit('search', localSearch.value);
}
</script>

<template>

    <div>

        <!-- Top Bar -->
        <div class="buyer-topbar">
            Shop trusted local sellers on NEXMART — quality goods, delivered to your door.
        </div>

        <div class="buyer-header-wrap">

            <!-- Header -->
            <header class="buyer-header">

                <a
                    href="#"
                    class="buyer-logo"
                    @click.prevent="emit('logo-click')"
                >
                    <span class="buyer-logo-badge">N</span>
                    <span class="buyer-logo-text">NEX<span class="accent">MART</span></span>
                </a>

                <!-- Search -->
                <form
                    class="buyer-search"
                    @submit.prevent="handleSearchSubmit"
                >

                    <svg class="search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="11" cy="11" r="7" />
                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                    </svg>

                    <input
                        :value="localSearch"
                        type="text"
                        placeholder="Search products, brands and categories..."
                        @input="handleSearchInput"
                    >

                    <button
                        type="submit"
                        title="Search"
                    >
                        Search
                    </button>

                </form>

                <!-- Buyer Actions -->
                <nav class="buyer-actions">

                    <button
                        type="button"
                        data-chat-trigger
                        :title="totalUnread > 0 ? `Messages (${totalUnread} unread)` : 'Messages'"
                        @click="toggleChat"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                        </svg>

                        <span
                            v-if="totalUnread > 0"
                            class="cart-count"
                        >
                            {{ totalUnread }}
                        </span>
                    </button>

                    <button
                        type="button"
                        title="Cart"
                        class="cart-button"
                        @click="emit('cart-click')"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="21" r="1" />
                            <circle cx="20" cy="21" r="1" />
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6" />
                        </svg>

                        <span
                            v-if="cartItemCount > 0"
                            class="cart-count"
                        >
                            {{ cartItemCount }}
                        </span>
                    </button>

                    <button
                        type="button"
                        title="Account"
                        @click="emit('account-click')"
                    >
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                            <circle cx="12" cy="7" r="4" />
                        </svg>
                    </button>

                </nav>

            </header>

            <!-- Secondary category nav -->
            <nav class="buyer-subnav">

                <button
                    v-for="category in categories"
                    :key="category"
                    type="button"
                    class="buyer-subnav-link"
                    :class="{ active: activeCategory === category }"
                    @click="emit('select-category', category)"
                >
                    {{ category }}
                </button>

            </nav>

        </div>

    </div>

</template>
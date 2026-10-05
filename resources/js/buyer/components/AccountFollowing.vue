<script setup>
/*
|--------------------------------------------------------------------------
| Followed Stores
|--------------------------------------------------------------------------
|
| GET /api/buyer/follows (newest first, paginated), with Visit store and
| Unfollow (DELETE /api/buyer/follows/{id}). Stores that have since closed
| drop out of the list on the server side.
|
*/
import { onActivated, onMounted, ref } from 'vue';
import { buyerApi } from '../composables/useBuyerApi';
import { requestBuyerView } from '../composables/useBuyerNav';
import { authHeaders } from '../composables/useBuyerSession';
import { useToasts } from '../composables/useToasts';
import StoreLogo from './StoreLogo.vue';

const toasts = useToasts();

const stores = ref([]);
const page = ref(0);
const lastPage = ref(1);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);
const error = ref('');
const busyId = ref(null);

async function load({ append = false } = {}) {
    if (loading.value) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const nextPage = append ? page.value + 1 : 1;
        const headers = await authHeaders();
        const response = await fetch(`/api/buyer/follows?page=${nextPage}&per_page=12`, { headers });
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            const err = new Error(body.message || 'Could not load the stores you follow.');
            err.status = response.status;

            throw err;
        }

        stores.value = append ? [...stores.value, ...(body.data || [])] : (body.data || []);
        page.value = body.meta?.current_page || nextPage;
        lastPage.value = body.meta?.last_page || 1;
        total.value = body.meta?.total || stores.value.length;
        loaded.value = true;
    } catch (err) {
        error.value = err?.status === 401
            ? 'Your session has ended. Please sign in again.'
            : err?.message || 'Could not load the stores you follow.';
    } finally {
        loading.value = false;
    }
}

onMounted(() => load());

// Back from a store page: pick up any follow changed there.
onActivated(() => {
    if (loaded.value) {
        load();
    }
});

function visit(store) {
    requestBuyerView('store', { id: store.id, name: store.name, category: store.category, logo: store.logo });
}

async function unfollow(store) {
    if (busyId.value) {
        return;
    }

    busyId.value = store.id;

    try {
        await buyerApi(`/buyer/follows/${encodeURIComponent(store.id)}`, { method: 'DELETE' });
        stores.value = stores.value.filter(item => item.id !== store.id);
        total.value = Math.max(0, total.value - 1);
        toasts.success(`Unfollowed ${store.name}.`);
    } catch (err) {
        toasts.error(err?.message || `Could not unfollow ${store.name}.`);
    } finally {
        busyId.value = null;
    }
}

function joined(iso) {
    const date = iso ? new Date(iso) : null;

    return date && !Number.isNaN(date.getTime())
        ? date.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' })
        : '';
}
</script>

<template>

    <section
        class="acc-section"
        aria-labelledby="acc-following-title"
    >
        <header class="acc-head">
            <h1
                id="acc-following-title"
                class="acc-title"
            >
                Followed Stores
                <span
                    v-if="total"
                    class="acc-count"
                >{{ total }}</span>
            </h1>
            <p class="acc-lede">Shops you follow, newest first.</p>
        </header>

        <div
            v-if="!loaded && !error"
            class="acc-loading"
            aria-busy="true"
        >
            <span class="skeleton is-line"></span>
            <span class="skeleton is-line"></span>
            <span class="skeleton is-line"></span>
        </div>

        <div
            v-else-if="error && !stores.length"
            class="acc-empty"
            role="alert"
        >
            <p>{{ error }}</p>
            <button
                type="button"
                class="btn btn-secondary"
                @click="load()"
            >
                Try again
            </button>
        </div>

        <div
            v-else-if="!stores.length"
            class="acc-empty"
        >
            <p class="acc-empty-title">You&rsquo;re not following any stores yet</p>
            <p>Follow a shop from its store page to keep it close at hand.</p>
            <button
                type="button"
                class="btn btn-primary"
                @click="requestBuyerView('stores')"
            >
                Browse stores
            </button>
        </div>

        <template v-else>
            <TransitionGroup
                name="acc-list"
                tag="ul"
                class="acc-store-list"
            >
                <li
                    v-for="store in stores"
                    :key="store.id"
                    class="acc-store"
                    :class="{ 'is-busy': busyId === store.id }"
                >
                    <StoreLogo
                        :name="store.name"
                        :src="store.logo || ''"
                        :category="store.category || ''"
                    />
                    <div class="acc-store-text">
                        <button
                            type="button"
                            class="acc-store-name"
                            @click="visit(store)"
                        >
                            {{ store.name }}
                        </button>
                        <p class="acc-store-meta">
                            <span v-if="store.category">{{ store.category }}</span>
                            <span v-if="store.location">{{ store.location }}</span>
                            <span>{{ store.productCount }} {{ store.productCount === 1 ? 'product' : 'products' }}</span>
                            <span v-if="store.rating">&#9733; {{ store.rating.toFixed(1) }} from product reviews</span>
                        </p>
                        <p
                            v-if="store.followedAt"
                            class="acc-hint"
                        >
                            Following since {{ joined(store.followedAt) }}
                        </p>
                    </div>
                    <div class="acc-store-actions">
                        <button
                            type="button"
                            class="btn btn-secondary"
                            @click="visit(store)"
                        >
                            Visit store
                        </button>
                        <button
                            type="button"
                            class="btn btn-ghost"
                            :disabled="busyId === store.id"
                            :aria-label="`Unfollow ${store.name}`"
                            @click="unfollow(store)"
                        >
                            {{ busyId === store.id ? 'Unfollowing…' : 'Unfollow' }}
                        </button>
                    </div>
                </li>
            </TransitionGroup>

            <button
                v-if="page < lastPage"
                type="button"
                class="btn btn-secondary acc-more"
                :disabled="loading"
                @click="load({ append: true })"
            >
                {{ loading ? 'Loading…' : 'Show more stores' }}
            </button>
        </template>
    </section>

</template>

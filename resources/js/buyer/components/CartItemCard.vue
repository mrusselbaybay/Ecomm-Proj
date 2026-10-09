<script setup>
/*
|--------------------------------------------------------------------------
| CartItemCard — one line in the buyer cart
|--------------------------------------------------------------------------
|
| Purely presentational: it renders an item from useBuyer's `cart` and
| emits intent. All mutation (quantity clamping, selection, removal,
| revalidation) stays in useBuyer / Cart.vue.
|
| One clean row: small image, name, seller, each chosen variant option,
| unit price, quantity, line total and a labelled Remove button, plus an
| inline status banner when the catalog revalidation found a problem
| (never auto-removed). Long names and many options wrap instead of
| stretching the row.
|
*/
import { computed, ref, watch } from 'vue';
import { formatPrice, metaFor } from '../composables/useCategoryMeta';
import QuantityStepper from './QuantityStepper.vue';

const props = defineProps({
    item: { type: Object, required: true },
    validating: { type: Boolean, default: false },
});

const emit = defineEmits(['update-quantity', 'remove', 'toggle-select']);

// Per-line image fallback, same idea as ProductCard.vue — a broken/404
// image URL drops back to the category icon tile. Reset if the URL itself
// changes (revalidation can refresh it).
const imageError = ref(false);

watch(
    () => props.item.image,
    () => {
        imageError.value = false;
    },
);

const unitPrice = computed(() =>
    props.item.status === 'price_changed' && props.item.serverPrice != null
        ? Number(props.item.serverPrice)
        : Number(props.item.price),
);

const lineTotal = computed(() => unitPrice.value * props.item.quantity);

const hasDiscount = computed(
    () => !!props.item.oldPrice && Number(props.item.oldPrice) > unitPrice.value,
);

const showImage = computed(
    () => !!props.item.image && props.item.image !== '/images/product-placeholder.svg' && !imageError.value,
);

// "Flavor: Tuna, Pack Weight: 100g" -> [{ name: 'Flavor', value: 'Tuna' }, ...]
const variantOptions = computed(() => {
    if (!props.item.variation) {
        return [];
    }

    return String(props.item.variation)
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => {
            const [name, ...rest] = part.split(':');

            return rest.length
                ? { name: name.trim(), value: rest.join(':').trim() }
                : { name: '', value: part };
        });
});

const isBlocked = computed(() =>
    ['unavailable', 'out_of_stock', 'variant_unavailable'].includes(props.item.status),
);

// Only mention stock when it limits the buyer. null == unknown stock
// (simple product whose API row had no number); no claim in that case.
const stockNote = computed(() => {
    const max = props.item.maxStock;

    if (max == null || max === 0 || isBlocked.value) {
        return '';
    }

    if (props.item.quantity >= max) {
        return `Max ${max} available`;
    }

    if (max <= 5) {
        return `Only ${max} left`;
    }

    return '';
});

const ISSUE_BANNERS = {
    unavailable: {
        tone: 'bad',
        text: 'This product is no longer available. Remove it to continue checking out.',
    },
    variant_unavailable: {
        tone: 'bad',
        text: 'The selected option is no longer available. Remove it or pick another on the product page.',
    },
    out_of_stock: {
        tone: 'bad',
        text: 'This item is out of stock. Remove it to continue checking out.',
    },
    insufficient_stock: {
        tone: 'warn',
        text: 'Not enough stock for the quantity you chose. Lower the quantity to continue.',
    },
    price_changed: {
        tone: 'warn',
        text: 'The price changed since you added this. The new price is shown and will be used at checkout.',
    },
};

const issueBanner = computed(() => ISSUE_BANNERS[props.item.status] || null);
</script>

<template>
    <article
        class="grid grid-cols-[auto_72px_minmax(0,1fr)] sm:grid-cols-[auto_88px_minmax(0,1fr)_auto] gap-x-4 gap-y-3 p-4 sm:p-5"
        :aria-label="item.name"
    >
        <label class="row-span-2 flex min-h-[44px] min-w-[28px] cursor-pointer items-start pt-1 sm:row-span-1">
            <input
                type="checkbox"
                class="h-5 w-5 cursor-pointer accent-teal-600"
                :checked="item.selected"
                :aria-label="`Select ${item.name} for checkout`"
                @change="emit('toggle-select', item.cartId)"
            >
        </label>

        <div
            class="flex h-[72px] w-[72px] items-center justify-center overflow-hidden rounded-xl bg-slate-100 sm:h-[88px] sm:w-[88px]"
            :class="{ 'opacity-60': isBlocked }"
        >
            <img
                v-if="showImage"
                :src="item.image"
                alt=""
                class="h-full w-full object-contain"
                loading="lazy"
                decoding="async"
                @error="imageError = true"
            >
            <span
                v-else
                class="h-9 w-9 text-slate-400"
                aria-hidden="true"
                v-html="metaFor(item.category).icon"
            />
        </div>

        <!-- Details -->
        <div class="min-w-0">
            <h3
                class="text-[15px] font-bold leading-snug [overflow-wrap:anywhere]"
                :class="isBlocked ? 'text-slate-500' : 'text-slate-900'"
            >
                {{ item.name }}
            </h3>
            <p class="mt-0.5 text-xs text-slate-500">
                Sold by {{ item.seller }}
            </p>

            <ul
                v-if="variantOptions.length"
                class="mt-2 flex flex-wrap gap-1.5"
                aria-label="Selected options"
            >
                <li
                    v-for="option in variantOptions"
                    :key="`${option.name}-${option.value}`"
                    class="rounded-md bg-slate-100 px-2 py-0.5 text-[12px] text-slate-600 [overflow-wrap:anywhere]"
                >
                    <template v-if="option.name">
                        {{ option.name }}: <span class="font-semibold text-slate-800">{{ option.value }}</span>
                    </template>
                    <span
                        v-else
                        class="font-semibold text-slate-800"
                    >{{ option.value }}</span>
                </li>
            </ul>

            <p class="mt-2 flex flex-wrap items-baseline gap-x-2 text-[13px]">
                <span class="font-semibold tabular-nums text-slate-900">{{ formatPrice(unitPrice) }}</span>
                <span class="text-slate-500">each</span>
                <span
                    v-if="hasDiscount"
                    class="text-xs tabular-nums text-slate-400 line-through"
                >
                    {{ formatPrice(item.oldPrice) }}
                </span>
            </p>
        </div>

        <!-- Quantity, line total, remove -->
        <div class="col-span-2 col-start-2 flex flex-wrap items-center justify-between gap-3 sm:col-span-1 sm:col-start-4 sm:row-start-1 sm:flex-col sm:items-end sm:justify-start">
            <span class="cart-line-total order-2 text-base font-bold tabular-nums text-slate-900 sm:order-1">
                <Transition
                    name="cart-amount"
                    mode="out-in"
                >
                    <span :key="lineTotal">{{ formatPrice(lineTotal) }}</span>
                </Transition>
            </span>

            <div class="order-1 flex flex-col items-start gap-1 sm:order-2 sm:items-end">
                <QuantityStepper
                    :model-value="item.quantity"
                    :min="1"
                    :max="item.maxStock"
                    :busy="validating"
                    :disabled="isBlocked"
                    :label="`Quantity for ${item.name}`"
                    @update:model-value="emit('update-quantity', item.cartId, $event)"
                />
                <p
                    v-if="stockNote"
                    class="text-[11px] font-semibold text-orange-600"
                >
                    {{ stockNote }}
                </p>
            </div>

            <button
                type="button"
                class="order-3 inline-flex min-h-[36px] items-center gap-1.5 rounded-lg px-2 text-[13px] font-semibold text-slate-500 transition-colors hover:bg-red-50 hover:text-red-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600"
                :aria-label="`Remove ${item.name} from cart`"
                @click="emit('remove', item)"
            >
                <svg
                    viewBox="0 0 24 24"
                    width="15"
                    height="15"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M3 6h18" />
                    <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                </svg>
                Remove
            </button>
        </div>

        <!-- Issue banner -->
        <p
            v-if="issueBanner"
            class="col-span-2 col-start-2 flex items-start gap-2 rounded-lg px-3 py-2 text-xs font-medium sm:col-span-2 sm:col-start-3"
            :class="issueBanner.tone === 'bad' ? 'bg-red-50 text-red-700' : 'bg-orange-50 text-orange-700'"
            role="status"
        >
            <svg
                viewBox="0 0 24 24"
                width="15"
                height="15"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="mt-px shrink-0"
                aria-hidden="true"
            >
                <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            </svg>
            {{ issueBanner.text }}
        </p>
    </article>
</template>

<style scoped>
.cart-amount-enter-active,
.cart-amount-leave-active {
    transition: opacity 0.12s ease;
}

.cart-amount-enter-from,
.cart-amount-leave-to {
    opacity: 0.35;
}

@media (prefers-reduced-motion: reduce) {
    .cart-amount-enter-active,
    .cart-amount-leave-active {
        transition: none;
    }
}
</style>

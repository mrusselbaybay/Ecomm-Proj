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
| Shows everything the brief asks for per line: image (with graceful
| fallback), name, seller, chosen variant, unit price + original price +
| discount, stock, subtotal, rating + review count + "View reviews",
| remove, and an inline status banner when the catalog revalidation found
| a problem (never auto-removed).
*/
import { computed, ref, watch } from 'vue';
import { metaFor, formatPrice } from '../composables/useCategoryMeta';
import QuantityStepper from './QuantityStepper.vue';
import StarRating from './StarRating.vue';

const props = defineProps({
    item: { type: Object, required: true },
    validating: { type: Boolean, default: false },
});

const emit = defineEmits(['update-quantity', 'remove', 'toggle-select', 'view-reviews']);

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

const discountPct = computed(() =>
    hasDiscount.value
        ? Math.round((1 - unitPrice.value / Number(props.item.oldPrice)) * 100)
        : 0,
);

const showImage = computed(() => !!props.item.image && !imageError.value);

// null == unknown stock (simple product whose API row had no number); we
// don't claim a count in that case.
const stockState = computed(() => {
    const max = props.item.maxStock;

    if (max === 0) {
        return { tone: 'bad', text: 'Out of stock' };
    }

    if (max == null) {
        return { tone: 'ok', text: 'In stock' };
    }

    if (max <= 5) {
        return { tone: 'warn', text: `Only ${max} left` };
    }

    return { tone: 'ok', text: `${max} in stock` };
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
        text: 'The price changed since you added this. The new price is shown above and will be used at checkout.',
    },
};

const issueBanner = computed(() => ISSUE_BANNERS[props.item.status] || null);
</script>

<template>
    <div
        class="flex flex-col sm:flex-row gap-4 p-5 border-b border-slate-100 last:border-b-0"
        :class="{ 'opacity-70': item.status === 'unavailable' || item.status === 'out_of_stock' || item.status === 'variant_unavailable' }"
    >
        <!-- Select + image -->
        <div class="flex gap-4 sm:flex-col sm:items-center">
            <label class="flex items-start pt-1 cursor-pointer">
                <input
                    type="checkbox"
                    class="w-5 h-5 accent-teal-600 cursor-pointer"
                    :checked="item.selected"
                    :aria-label="`Select ${item.name} for checkout`"
                    @change="emit('toggle-select', item.cartId)"
                >
            </label>

            <div class="w-24 h-24 sm:w-28 sm:h-28 shrink-0 rounded-xl bg-slate-100 overflow-hidden flex items-center justify-center">
                <img
                    v-if="showImage"
                    :src="item.image"
                    :alt="item.name"
                    class="w-full h-full object-cover"
                    loading="lazy"
                    @error="imageError = true"
                >
                <span
                    v-else
                    class="w-10 h-10 text-slate-400"
                    aria-hidden="true"
                    v-html="metaFor(item.category).icon"
                />
            </div>
        </div>

        <!-- Body -->
        <div class="flex-1 min-w-0 flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-[15px] font-bold text-slate-900 leading-snug">
                        {{ item.name }}
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        {{ item.seller }}
                    </p>
                    <p
                        v-if="item.variation"
                        class="inline-flex mt-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-[11px] font-semibold text-slate-600"
                    >
                        {{ item.variation }}
                    </p>
                </div>

                <button
                    type="button"
                    class="shrink-0 flex items-center justify-center w-9 h-9 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                    :aria-label="`Remove ${item.name} from cart`"
                    @click="emit('remove', item)"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
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
                </button>
            </div>

            <!-- Rating + reviews -->
            <div class="flex items-center gap-3 flex-wrap">
                <StarRating
                    :rating="typeof item.rating === 'number' ? item.rating : null"
                    :count="item.reviewCount || 0"
                    :size="14"
                />
                <button
                    type="button"
                    class="text-xs font-bold text-teal-700 hover:text-teal-800 underline underline-offset-2"
                    @click="emit('view-reviews', item)"
                >
                    View reviews
                </button>
            </div>

            <!-- Price + stock -->
            <div class="flex items-end justify-between gap-3 flex-wrap">
                <div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-[15px] font-bold text-slate-900 tabular-nums">
                            {{ formatPrice(unitPrice) }}
                        </span>
                        <span
                            v-if="hasDiscount"
                            class="text-xs text-slate-400 line-through tabular-nums"
                        >
                            {{ formatPrice(item.oldPrice) }}
                        </span>
                        <span
                            v-if="hasDiscount"
                            class="text-[11px] font-bold text-orange-600"
                        >
                            -{{ discountPct }}%
                        </span>
                    </div>
                    <p
                        class="text-[11px] font-semibold mt-0.5"
                        :class="{
                            'text-emerald-600': stockState.tone === 'ok',
                            'text-orange-600': stockState.tone === 'warn',
                            'text-red-600': stockState.tone === 'bad',
                        }"
                    >
                        {{ stockState.text }}
                    </p>
                </div>

                <div class="flex items-center gap-4">
                    <QuantityStepper
                        :model-value="item.quantity"
                        :min="1"
                        :max="item.maxStock"
                        :busy="validating"
                        :disabled="item.status === 'unavailable' || item.status === 'out_of_stock' || item.status === 'variant_unavailable'"
                        :label="`Quantity for ${item.name}`"
                        @update:model-value="emit('update-quantity', item.cartId, $event)"
                    />
                    <span class="text-[15px] font-bold text-slate-900 tabular-nums min-w-[76px] text-right">
                        {{ formatPrice(lineTotal) }}
                    </span>
                </div>
            </div>

            <!-- Issue banner -->
            <p
                v-if="issueBanner"
                class="flex items-start gap-2 text-xs font-medium rounded-lg px-3 py-2"
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
                    class="shrink-0 mt-px"
                    aria-hidden="true"
                >
                    <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
                </svg>
                {{ issueBanner.text }}
            </p>
        </div>
    </div>
</template>

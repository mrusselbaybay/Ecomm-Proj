<script setup>
/*
|--------------------------------------------------------------------------
| VariantPicker — choose one of the seller's actual variants
|--------------------------------------------------------------------------
|
| Each choice is one real variant (built by the parent from
| product.variants), labelled with its attribute values combined, e.g.
| "Chicken / 500g / Cats", with a small thumbnail. Nothing here invents
| combinations.
|
| Every choice is a visible button (a native radio group, so Tab enters
| the group once and the arrow keys move between options). Long lists sit
| in a bounded, scrollable area that keeps the selected option in view
| without hijacking page scrolling. Unavailable choices stay visible, are
| labelled in words ("Out of stock"), and can't be picked.
|
| choices: [{ id, label, image, priceLabel, state: 'ok'|'soldout'|'unavailable', note }]
|
*/
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    choices: {
        type: Array,
        required: true
    },
    modelValue: {
        type: [String, Number],
        default: null
    },
    // e.g. "Flavor / Pack Weight / Pet Type" — tells buyers how labels read.
    attributeNames: {
        type: String,
        default: ''
    },
    invalid: {
        type: Boolean,
        default: false
    },
    describedBy: {
        type: String,
        default: undefined
    },
    // Lists longer than this get a bounded, scrollable area.
    boundedAfter: {
        type: Number,
        default: 9
    }
});

const emit = defineEmits(['update:modelValue']);

const uid = `vp-${Math.random().toString(36).slice(2, 8)}`;

const isBounded = computed(() => props.choices.length > props.boundedAfter);

const availableCount = computed(() => props.choices.filter(c => c.state === 'ok').length);

const scroller = ref(null);
const failedThumbs = ref(new Set());

function thumbFor(choice) {
    return choice.image && !failedThumbs.value.has(choice.image) ? choice.image : '';
}

function onThumbError(src) {
    failedThumbs.value = new Set(failedThumbs.value).add(src);
}

function pick(choice) {
    if (!choice || choice.state !== 'ok') {
        return;
    }

    emit('update:modelValue', choice.id);
}

/**
 * Brings the selected option into view inside the bounded area only,
 * adjusting the area's own scrollTop rather than scrolling the page.
 */
function revealSelected() {
    const area = scroller.value;

    if (!isBounded.value || !area) {
        return;
    }

    const option = area.querySelector('.vp-btn.is-selected');

    if (!option) {
        return;
    }

    // .vp-area is the positioning context, so offsetTop is inside it.
    const top = option.offsetTop;
    const bottom = top + option.offsetHeight;

    if (top < area.scrollTop) {
        area.scrollTop = top - 6;
    } else if (bottom > area.scrollTop + area.clientHeight) {
        area.scrollTop = bottom - area.clientHeight + 6;
    }
}

watch(() => props.modelValue, () => nextTick(revealSelected));
onMounted(() => nextTick(revealSelected));

/** Lets the parent move focus here when a purchase needs a choice. */
function focus() {
    const root = scroller.value;
    const target = root?.querySelector('input:checked') || root?.querySelector('input:not(:disabled)');

    target?.focus({ preventScroll: true });
    nextTick(revealSelected);
}

defineExpose({ focus });
</script>

<template>

    <div
        class="vp"
        :class="{ 'is-invalid': invalid, 'is-bounded': isBounded }"
    >

        <div
            ref="scroller"
            class="vp-area"
            :tabindex="isBounded ? -1 : undefined"
        >
            <div
                class="vp-grid"
                role="radiogroup"
                :aria-label="attributeNames ? `Option: ${attributeNames}` : 'Option'"
                :aria-describedby="[describedBy, isBounded ? `${uid}-count` : null].filter(Boolean).join(' ') || undefined"
                :aria-invalid="invalid || undefined"
            >
                <label
                    v-for="choice in choices"
                    :key="choice.id"
                    class="vp-btn"
                    :class="[`is-${choice.state}`, { 'is-selected': choice.id === modelValue }]"
                    :title="choice.label"
                >
                    <input
                        type="radio"
                        class="sr-only"
                        :name="uid"
                        :value="choice.id"
                        :checked="choice.id === modelValue"
                        :disabled="choice.state !== 'ok'"
                        :aria-describedby="choice.note ? `${uid}-${choice.id}-note` : undefined"
                        @change="pick(choice)"
                    >
                    <span class="vp-btn-box">
                        <span class="vp-thumb">
                            <img
                                v-if="thumbFor(choice)"
                                :src="thumbFor(choice)"
                                alt=""
                                width="40"
                                height="40"
                                loading="lazy"
                                decoding="async"
                                @error="onThumbError(choice.image)"
                            >
                            <svg
                                v-else
                                viewBox="0 0 24 24"
                                width="18"
                                height="18"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.6"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            ><path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5z" /><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9" /></svg>
                        </span>

                        <span class="vp-text">
                            <span class="vp-label">{{ choice.label }}</span>
                            <span
                                v-if="choice.priceLabel || choice.note"
                                class="vp-sub"
                            >
                                <span
                                    v-if="choice.note"
                                    :id="`${uid}-${choice.id}-note`"
                                    class="vp-note"
                                >{{ choice.note }}</span>
                                <span v-else>{{ choice.priceLabel }}</span>
                            </span>
                        </span>

                        <span
                            class="vp-check"
                            aria-hidden="true"
                        >
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <p
            v-if="isBounded"
            :id="`${uid}-count`"
            class="vp-count"
        >
            {{ choices.length }} options, {{ availableCount }} in stock. Scroll the list to see them all.
        </p>

    </div>

</template>

<style scoped>
/* Bounded area for long lists: about four rows, a visible scrollbar,
   and scroll that hands back to the page at either end. */
.vp-area {
    position: relative;

    min-width: 0;
}

.vp.is-bounded .vp-area {
    max-height: 244px;
    padding: 8px 6px 8px 2px;

    overflow-y: auto;
    scrollbar-gutter: stable;
    scrollbar-width: thin;
    scrollbar-color: var(--nx-line-strong) transparent;

    border-top: 1px solid var(--nx-line);
    border-bottom: 1px solid var(--nx-line);
}

.vp.is-bounded .vp-area:focus-visible {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

.vp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 176px), 1fr));
    gap: 8px;
}

.vp-btn {
    position: relative;

    display: flex;
    min-width: 0;

    cursor: pointer;
}

.vp-btn-box {
    position: relative;

    display: flex;
    flex: 1;
    align-items: center;
    gap: 10px;

    min-width: 0;
    min-height: 54px;
    padding: 6px 28px 6px 6px;

    border: 1px solid var(--nx-line-strong);
    border-radius: var(--nx-radius-sm);
    background: var(--nx-surface);
    color: var(--nx-ink);

    transition:
        border-color var(--nx-dur-fast) var(--nx-ease),
        box-shadow var(--nx-dur-fast) var(--nx-ease),
        background-color var(--nx-dur-fast) var(--nx-ease),
        transform var(--nx-dur-fast) var(--nx-ease);
}

.vp-thumb {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;

    width: 40px;
    height: 40px;

    overflow: hidden;

    border-radius: 4px;
    background: var(--nx-sunken);
    color: var(--nx-muted);
}

.vp-thumb img {
    display: block;

    width: 100%;
    height: 100%;

    object-fit: contain;
}

.vp-text {
    display: flex;
    flex-direction: column;
    gap: 1px;

    min-width: 0;
}

/* Up to two lines, then an ellipsis; the full label is in the title and
   in the selection summary under the picker. */
.vp-label {
    display: -webkit-box;

    overflow: hidden;

    font-size: 13.5px;
    font-weight: 500;
    line-height: 1.3;
    overflow-wrap: anywhere;

    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.vp-sub {
    color: var(--nx-text-2);

    font-size: 12px;
    font-variant-numeric: tabular-nums;
}

.vp-note {
    color: var(--nx-muted);
    font-weight: 600;
}

/* Check badge: shown when selected, so selection never rests on colour. */
.vp-check {
    position: absolute;
    top: 6px;
    right: 6px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 18px;
    height: 18px;

    border-radius: 50%;
    background: var(--nx-accent);
    color: #ffffff;

    opacity: 0;
    transform: scale(0.6);

    transition: opacity var(--nx-dur-fast) var(--nx-ease), transform var(--nx-dur) var(--nx-ease);
}

.vp-btn:hover .vp-btn-box {
    border-color: var(--nx-ink);
}

.vp-btn:active .vp-btn-box {
    transform: scale(0.98);
}

.vp-btn.is-selected .vp-btn-box {
    border-color: var(--nx-accent);
    background: var(--nx-accent-soft);
    box-shadow: inset 0 0 0 1px var(--nx-accent);
}

.vp-btn.is-selected .vp-label {
    font-weight: 600;
}

.vp-btn.is-selected .vp-check {
    opacity: 1;
    transform: scale(1);
}

.vp-btn input:focus-visible + .vp-btn-box {
    outline: 2px solid var(--nx-accent);
    outline-offset: 2px;
}

/* Unavailable: dashed border, faded thumbnail and text, plus the worded
   note ("Out of stock"), never colour alone. */
.vp-btn.is-soldout,
.vp-btn.is-unavailable {
    cursor: not-allowed;
}

.vp-btn.is-soldout .vp-btn-box,
.vp-btn.is-unavailable .vp-btn-box {
    border-style: dashed;
    background: var(--nx-bg);
    color: var(--nx-muted);
}

.vp-btn.is-soldout .vp-thumb,
.vp-btn.is-unavailable .vp-thumb {
    opacity: 0.5;
    filter: grayscale(1);
}

.vp-btn.is-soldout:hover .vp-btn-box,
.vp-btn.is-unavailable:hover .vp-btn-box {
    border-color: var(--nx-line-strong);
}

.vp-btn.is-soldout:active .vp-btn-box,
.vp-btn.is-unavailable:active .vp-btn-box {
    transform: none;
}

.vp.is-invalid .vp-btn:not(.is-soldout):not(.is-unavailable) .vp-btn-box {
    border-color: #b42318;
}

.vp-count {
    margin: 8px 0 0;

    color: var(--nx-muted);

    font-size: 12.5px;
}

@media (max-width: 480px) {
    .vp-grid {
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 148px), 1fr));
    }

    .vp.is-bounded .vp-area {
        max-height: 268px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .vp-check,
    .vp-btn-box {
        transition: none;
    }
}
</style>

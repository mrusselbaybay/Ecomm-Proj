<script setup>
/*
|--------------------------------------------------------------------------
| FilterGroup — one filter group, rendered the same way everywhere
|--------------------------------------------------------------------------
|
| Used by the category page's desktop sidebar, its compact toolbar panels
| and its mobile drawer, so a control behaves identically in all three.
|
| group.kind decides the control:
|   checkbox  multiple selection (attributes, condition, availability)
|   radio     one exclusive choice (minimum rating)
|   swatch    colours: a labelled chip with a check mark, never colour alone
|   toggle    short values (sizes, capacities) as a grid of toggle buttons
|   price     labelled Min / Max inputs + a synchronised two-thumb slider
|
| modelValue is an array (checkbox / swatch / toggle), a value (radio) or
| { min, max } strings (price). Long lists get a search box (> 8 options)
| and a "Show all" control (> 6). Collapsed groups keep their selection
| visible as a summary line under the heading.
|
*/
import { computed, ref, watch, onUnmounted } from 'vue';
import { formatPrice } from '../composables/useCategoryMeta';

const props = defineProps({
    group: {
        type: Object,
        required: true
    },
    modelValue: {
        type: [Array, String, Number, Object],
        default: null
    },
    collapsible: {
        type: Boolean,
        default: false
    },
    defaultOpen: {
        type: Boolean,
        default: true
    },
    // Price only: delay before a typed range is applied (desktop applies
    // immediately; the drawer passes 0 because it commits on Apply).
    debounceMs: {
        type: Number,
        default: 0
    },
    idPrefix: {
        type: String,
        default: 'filter'
    }
});

const emit = defineEmits(['update:modelValue']);

const PREVIEW = 6;
const SEARCH_THRESHOLD = 8;

const baseId = computed(() => `${props.idPrefix}-${props.group.key}`);

/*
|--------------------------------------------------------------------------
| Collapse
|--------------------------------------------------------------------------
*/

const isOpen = ref(props.defaultOpen || !props.collapsible);

watch(() => props.defaultOpen, (open) => {
    if (open) {
        isOpen.value = true;
    }
});

const selectedLabels = computed(() => {
    const value = props.modelValue;

    if (props.group.kind === 'price') {
        return priceSummary.value ? [priceSummary.value] : [];
    }

    if (props.group.kind === 'radio') {
        const option = props.group.options.find(o => o.value === value);

        return option && option.value ? [option.label] : [];
    }

    return props.group.options.filter(o => (value || []).includes(o.value)).map(o => o.label);
});

/*
|--------------------------------------------------------------------------
| Options: search + show more
|--------------------------------------------------------------------------
*/

const query = ref('');
const showAll = ref(false);

const searchable = computed(() => props.group.options?.length > SEARCH_THRESHOLD);

const matchingOptions = computed(() => {
    const term = query.value.trim().toLowerCase();
    const options = props.group.options || [];

    return term ? options.filter(o => o.label.toLowerCase().includes(term)) : options;
});

const visibleOptions = computed(() => {
    if (query.value.trim() || showAll.value || props.group.kind === 'toggle') {
        return matchingOptions.value;
    }

    // Selected options always stay visible, even past the preview cut.
    const preview = matchingOptions.value.slice(0, PREVIEW);
    const selected = matchingOptions.value.filter(o => isChecked(o.value) && !preview.includes(o));

    return [...preview, ...selected];
});

const hiddenCount = computed(() => matchingOptions.value.length - visibleOptions.value.length);

function isChecked(value) {
    return props.group.kind === 'radio'
        ? props.modelValue === value
        : (props.modelValue || []).includes(value);
}

function toggle(value) {
    const current = props.modelValue || [];

    emit('update:modelValue', current.includes(value)
        ? current.filter(v => v !== value)
        : [...current, value]);
}

function choose(value) {
    emit('update:modelValue', value);
}

/*
|--------------------------------------------------------------------------
| Price
|--------------------------------------------------------------------------
*/

const localMin = ref('');
const localMax = ref('');

watch(() => props.modelValue, (value) => {
    if (props.group.kind === 'price') {
        localMin.value = value?.min ?? '';
        localMax.value = value?.max ?? '';
    }
}, { immediate: true, deep: true });

const bounds = computed(() => props.group.bounds || { min: 0, max: 0 });

const priceError = computed(() => {
    const min = localMin.value === '' ? null : Number(localMin.value);
    const max = localMax.value === '' ? null : Number(localMax.value);

    if ((min !== null && (!Number.isFinite(min) || min < 0)) || (max !== null && (!Number.isFinite(max) || max < 0))) {
        return 'Enter a price of 0 or more.';
    }

    if (min !== null && max !== null && min > max) {
        return 'Minimum price must be lower than the maximum.';
    }

    return '';
});

const priceSummary = computed(() => {
    const { min, max } = props.modelValue || {};

    if (min && max) {
        return `${formatPrice(min)} – ${formatPrice(max)}`;
    }

    if (min) {
        return `From ${formatPrice(min)}`;
    }

    if (max) {
        return `Up to ${formatPrice(max)}`;
    }

    return '';
});

let priceTimer = null;

function commitPrice() {
    clearTimeout(priceTimer);

    if (priceError.value) {
        return;
    }

    const next = { min: localMin.value === '' ? '' : String(localMin.value), max: localMax.value === '' ? '' : String(localMax.value) };
    const current = props.modelValue || {};

    if (next.min !== (current.min ?? '') || next.max !== (current.max ?? '')) {
        emit('update:modelValue', next);
    }
}

function schedulePrice() {
    clearTimeout(priceTimer);

    if (props.debounceMs > 0) {
        priceTimer = setTimeout(commitPrice, props.debounceMs);
    } else {
        commitPrice();
    }
}

// Slider thumbs: a value at the bound means "no limit" on that side.
const sliderMin = computed(() => (localMin.value === '' ? bounds.value.min : Number(localMin.value)));
const sliderMax = computed(() => (localMax.value === '' ? bounds.value.max : Number(localMax.value)));

const span = computed(() => Math.max(1, bounds.value.max - bounds.value.min));
const minPercent = computed(() => ((Math.min(Math.max(sliderMin.value, bounds.value.min), bounds.value.max) - bounds.value.min) / span.value) * 100);
const maxPercent = computed(() => ((Math.min(Math.max(sliderMax.value, bounds.value.min), bounds.value.max) - bounds.value.min) / span.value) * 100);

function onSlideMin(event) {
    const value = Math.min(Number(event.target.value), sliderMax.value);

    localMin.value = value <= bounds.value.min ? '' : String(value);
    event.target.value = value;
    schedulePrice();
}

function onSlideMax(event) {
    const value = Math.max(Number(event.target.value), sliderMin.value);

    localMax.value = value >= bounds.value.max ? '' : String(value);
    event.target.value = value;
    schedulePrice();
}

onUnmounted(() => clearTimeout(priceTimer));
</script>

<template>

    <section
        class="fg"
        :class="[`is-${group.kind}`, { 'is-collapsed': collapsible && !isOpen }]"
    >

        <h3 class="fg-head">
            <button
                v-if="collapsible"
                type="button"
                class="fg-toggle"
                :aria-expanded="isOpen"
                :aria-controls="`${baseId}-body`"
                @click="isOpen = !isOpen"
            >
                <span>{{ group.label }}</span>
                <svg class="fg-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            </button>
            <span
                v-else
                class="fg-title"
            >{{ group.label }}</span>
        </h3>

        <p
            v-if="collapsible && !isOpen && selectedLabels.length"
            class="fg-summary"
        >
            {{ selectedLabels.join(', ') }}
        </p>

        <div
            :id="`${baseId}-body`"
            class="fg-body"
            :inert="(collapsible && !isOpen) || undefined"
        >
            <div class="fg-inner">

                <!-- Price -->
                <template v-if="group.kind === 'price'">
                    <div class="fg-price">
                        <div class="fg-price-field">
                            <label :for="`${baseId}-min`">Min</label>
                            <div class="fg-price-input">
                                <span aria-hidden="true">&#8369;</span>
                                <input
                                    :id="`${baseId}-min`"
                                    v-model="localMin"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    :aria-invalid="!!priceError"
                                    :aria-describedby="priceError ? `${baseId}-error` : `${baseId}-range`"
                                    @input="schedulePrice"
                                    @change="commitPrice"
                                >
                            </div>
                        </div>
                        <div class="fg-price-field">
                            <label :for="`${baseId}-max`">Max</label>
                            <div class="fg-price-input">
                                <span aria-hidden="true">&#8369;</span>
                                <input
                                    :id="`${baseId}-max`"
                                    v-model="localMax"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    :aria-invalid="!!priceError"
                                    :aria-describedby="priceError ? `${baseId}-error` : `${baseId}-range`"
                                    @input="schedulePrice"
                                    @change="commitPrice"
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="bounds.max > bounds.min"
                        class="fg-slider"
                        :style="{ '--lo': `${minPercent}%`, '--hi': `${maxPercent}%` }"
                    >
                        <input
                            type="range"
                            :min="bounds.min"
                            :max="bounds.max"
                            step="1"
                            :value="sliderMin"
                            aria-label="Minimum price"
                            :aria-valuetext="formatPrice(sliderMin)"
                            @input="onSlideMin"
                        >
                        <input
                            type="range"
                            :min="bounds.min"
                            :max="bounds.max"
                            step="1"
                            :value="sliderMax"
                            aria-label="Maximum price"
                            :aria-valuetext="formatPrice(sliderMax)"
                            @input="onSlideMax"
                        >
                    </div>

                    <p
                        v-if="priceError"
                        :id="`${baseId}-error`"
                        class="fg-error"
                        role="alert"
                    >
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.01" /></svg>
                        {{ priceError }}
                    </p>
                    <p
                        v-else
                        :id="`${baseId}-range`"
                        class="fg-hint"
                    >
                        Prices here run {{ formatPrice(bounds.min) }} to {{ formatPrice(bounds.max) }}
                    </p>
                </template>

                <template v-else>
                    <div
                        v-if="searchable"
                        class="fg-search"
                    >
                        <label
                            :for="`${baseId}-search`"
                            class="sr-only"
                        >Search {{ group.label.toLowerCase() }}</label>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                        <input
                            :id="`${baseId}-search`"
                            v-model="query"
                            type="search"
                            :placeholder="`Search ${group.label.toLowerCase()}`"
                            autocomplete="off"
                        >
                    </div>

                    <!-- Toggle grid (sizes, capacities) -->
                    <div
                        v-if="group.kind === 'toggle'"
                        class="fg-toggles"
                        role="group"
                        :aria-label="group.label"
                    >
                        <label
                            v-for="option in visibleOptions"
                            :key="option.value"
                            class="fg-toggle-opt"
                            :class="{ 'is-disabled': option.disabled }"
                        >
                            <input
                                type="checkbox"
                                :checked="isChecked(option.value)"
                                :disabled="option.disabled"
                                @change="toggle(option.value)"
                            >
                            <span>{{ option.label }}</span>
                        </label>
                    </div>

                    <!-- Swatches (colours) -->
                    <div
                        v-else-if="group.kind === 'swatch'"
                        class="fg-swatches"
                        role="group"
                        :aria-label="group.label"
                    >
                        <label
                            v-for="option in visibleOptions"
                            :key="option.value"
                            class="fg-swatch"
                            :class="{ 'is-disabled': option.disabled }"
                        >
                            <input
                                type="checkbox"
                                :checked="isChecked(option.value)"
                                :disabled="option.disabled"
                                @change="toggle(option.value)"
                            >
                            <span class="fg-swatch-box">
                                <span
                                    class="fg-swatch-dot"
                                    :class="{ 'is-unknown': !option.swatch }"
                                    :style="option.swatch ? { background: option.swatch } : null"
                                    aria-hidden="true"
                                ></span>
                                <span class="fg-swatch-label">{{ option.label }}</span>
                                <svg class="fg-check" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
                            </span>
                        </label>
                    </div>

                    <!-- Checkbox / radio list -->
                    <div
                        v-else
                        class="fg-list"
                        :role="group.kind === 'radio' ? 'radiogroup' : 'group'"
                        :aria-label="group.label"
                    >
                        <label
                            v-for="option in visibleOptions"
                            :key="String(option.value)"
                            class="fg-opt"
                            :class="{ 'is-disabled': option.disabled }"
                        >
                            <input
                                v-if="group.kind === 'radio'"
                                type="radio"
                                :name="baseId"
                                :checked="isChecked(option.value)"
                                @change="choose(option.value)"
                            >
                            <input
                                v-else
                                type="checkbox"
                                :checked="isChecked(option.value)"
                                :disabled="option.disabled"
                                @change="toggle(option.value)"
                            >
                            <span class="fg-opt-label">{{ option.label }}</span>
                            <span
                                v-if="option.count !== undefined && option.count !== null"
                                class="fg-count"
                            >{{ option.count }}</span>
                        </label>
                    </div>

                    <p
                        v-if="searchable && query && matchingOptions.length === 0"
                        class="fg-hint"
                    >
                        No {{ group.label.toLowerCase() }} matches &ldquo;{{ query }}&rdquo;
                    </p>

                    <button
                        v-if="!query && (hiddenCount > 0 || showAll) && group.kind !== 'toggle'"
                        type="button"
                        class="fg-more"
                        :aria-expanded="showAll"
                        @click="showAll = !showAll"
                    >
                        {{ showAll ? 'Show fewer' : `Show ${hiddenCount} more` }}
                    </button>
                </template>

            </div>
        </div>

    </section>

</template>

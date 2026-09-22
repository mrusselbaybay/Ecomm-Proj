<!-- resources/js/seller/components/orders/OrderFilters.vue -->
<!--
  Filter/sort bar for the order list. Owns a debounced search box and
  plain <select>s for status, payment status, date range and sort, plus a
  Reset button and a row of chips showing what is currently applied.
  Emits the whole filter object via v-model; the parent turns it into the
  query for loadOrders().
-->
<template>
    <div class="order-filters card">
        <div class="order-filters-row">
            <div class="order-filter-search">
                <span class="order-filter-search-icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                </span>
                <input
                    id="order-search"
                    v-model="local.search"
                    type="search"
                    class="field-input"
                    placeholder="Search order number or buyer name…"
                    aria-label="Search orders by number or buyer name"
                    @input="onSearchInput"
                />
            </div>

            <div class="order-filter-field">
                <label class="field-label" for="order-status-filter">Order status</label>
                <select
                    id="order-status-filter"
                    v-model="local.status"
                    class="field-input"
                    @change="emitNow"
                >
                    <option value="">All statuses</option>
                    <option v-for="s in STATUS_OPTIONS" :key="s.value" :value="s.value">
                        {{ s.label }}
                    </option>
                </select>
            </div>

            <div class="order-filter-field">
                <label class="field-label" for="order-payment-filter">Payment</label>
                <select
                    id="order-payment-filter"
                    v-model="local.payment_status"
                    class="field-input"
                    @change="emitNow"
                >
                    <option value="">Any payment</option>
                    <option value="Paid">Paid</option>
                    <option value="Unpaid">Unpaid</option>
                    <option value="Refunded">Refunded</option>
                </select>
            </div>

            <div class="order-filter-field">
                <label class="field-label" for="order-date-from">From</label>
                <input
                    id="order-date-from"
                    v-model="local.date_from"
                    type="date"
                    class="field-input"
                    :max="local.date_to || undefined"
                    aria-label="Orders placed on or after"
                    @change="emitNow"
                />
            </div>

            <div class="order-filter-field">
                <label class="field-label" for="order-date-to">To</label>
                <input
                    id="order-date-to"
                    v-model="local.date_to"
                    type="date"
                    class="field-input"
                    :min="local.date_from || undefined"
                    aria-label="Orders placed on or before"
                    @change="emitNow"
                />
            </div>

            <div class="order-filter-field">
                <label class="field-label" for="order-sort">Sort by</label>
                <select id="order-sort" v-model="local.sort" class="field-input" @change="emitNow">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="total_high">Total: high to low</option>
                    <option value="total_low">Total: low to high</option>
                </select>
            </div>

            <button
                type="button"
                class="btn-outline btn-sm order-filter-reset"
                :disabled="!hasActiveFilters"
                @click="$emit('reset')"
            >
                Reset
            </button>
        </div>

        <div v-if="chips.length" class="order-filter-chips" aria-label="Active filters">
            <span class="order-filter-chips-label">Filters:</span>
            <button
                v-for="chip in chips"
                :key="chip.key"
                type="button"
                class="order-filter-chip"
                @click="clearChip(chip.key)"
            >
                {{ chip.label }}
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
                <span class="sr-only">Remove filter</span>
            </button>
        </div>
    </div>
</template>

<script setup>
import { reactive, computed, watch } from 'vue';

const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({
            search: '',
            status: '',
            payment_status: '',
            date_from: '',
            date_to: '',
            sort: 'newest',
        }),
    },
});

const emit = defineEmits(['update:modelValue', 'reset']);

const STATUS_OPTIONS = [
    { value: 'New', label: 'New' },
    { value: 'Processing', label: 'Processing' },
    { value: 'In Transit', label: 'In Transit' },
    { value: 'Delivered', label: 'Delivered' },
];

const local = reactive({ ...props.modelValue });

// Keep in sync when the parent resets or deep-links a filter.
watch(
    () => props.modelValue,
    (v) => Object.assign(local, v),
    { deep: true },
);

const hasActiveFilters = computed(
    () =>
        !!local.search ||
        !!local.status ||
        !!local.payment_status ||
        !!local.date_from ||
        !!local.date_to ||
        local.sort !== 'newest',
);

const chips = computed(() => {
    const out = [];

    if (local.search) {
        out.push({ key: 'search', label: `“${local.search}”` });
    }

    if (local.status) {
        const s = STATUS_OPTIONS.find((o) => o.value === local.status);
        out.push({ key: 'status', label: s ? s.label : local.status });
    }

    if (local.payment_status) {
        out.push({ key: 'payment_status', label: local.payment_status });
    }

    if (local.date_from) {
        out.push({ key: 'date_from', label: `From ${local.date_from}` });
    }

    if (local.date_to) {
        out.push({ key: 'date_to', label: `To ${local.date_to}` });
    }

    if (local.sort !== 'newest') {
        const labels = {
            oldest: 'Oldest first',
            total_high: 'Total ↓',
            total_low: 'Total ↑',
        };
        out.push({ key: 'sort', label: labels[local.sort] || local.sort });
    }

    return out;
});

function emitNow() {
    emit('update:modelValue', { ...local });
}

let searchTimer = null;

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(emitNow, 350);
}

function clearChip(key) {
    local[key] = key === 'sort' ? 'newest' : '';
    emitNow();
}
</script>

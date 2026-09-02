<script setup>
/*
|--------------------------------------------------------------------------
| QuantityStepper — accessible quantity control
|--------------------------------------------------------------------------
|
| Used by CartItemCard. Emits `update:modelValue` with a clamped integer;
| the parent still routes that through useBuyer.setCartQuantity (which is
| the real source of truth and re-clamps against live stock).
|
|   - −/+ are real buttons with aria-labels, 44px hit area, disabled at
|     the min/max bounds (and while `busy`)
|   - the middle field is a labelled numeric input: type freely, commit on
|     Enter/blur, clamp on commit; empty/NaN reverts to the current value
|   - `busy` sets aria-busy and locks the control during an update
|   - press feedback is colour/opacity only — the control never changes
|     size, so nothing around it jumps
|
*/
import { ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Number, required: true },
    min: { type: Number, default: 1 },
    max: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    label: { type: String, default: 'Quantity' },
});

const emit = defineEmits(['update:modelValue']);

const draft = ref(String(props.modelValue));

watch(
    () => props.modelValue,
    (value) => {
        draft.value = String(value);
    },
);

function clamp(value) {
    let next = Math.floor(Number(value));

    if (!Number.isFinite(next)) {
        return props.modelValue;
    }

    if (next < props.min) {
        next = props.min;
    }

    if (props.max != null && next > props.max) {
        next = props.max;
    }

    return next;
}

function commit(value) {
    const next = clamp(value);

    draft.value = String(next);

    if (next !== props.modelValue) {
        emit('update:modelValue', next);
    }
}

function step(delta) {
    if (props.disabled || props.busy) {
        return;
    }

    commit(props.modelValue + delta);
}

function onInput(event) {
    draft.value = event.target.value.replace(/[^\d]/g, '');
}
</script>

<template>
    <div
        class="qty-stepper"
        :class="{ 'qty-stepper--busy': busy }"
        role="group"
        :aria-label="label"
        :aria-busy="busy ? 'true' : 'false'"
    >
        <button
            type="button"
            class="qty-btn"
            :disabled="disabled || busy || modelValue <= min"
            :aria-label="`Decrease ${label.toLowerCase()}`"
            @click="step(-1)"
        >
            <svg
                viewBox="0 0 24 24"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M5 12h14" />
            </svg>
        </button>

        <input
            class="qty-input"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            :value="draft"
            :disabled="disabled || busy"
            :aria-label="label"
            @input="onInput"
            @keydown.enter.prevent="commit(draft)"
            @blur="commit(draft)"
        >

        <button
            type="button"
            class="qty-btn"
            :disabled="disabled || busy || (max != null && modelValue >= max)"
            :aria-label="`Increase ${label.toLowerCase()}`"
            @click="step(1)"
        >
            <svg
                viewBox="0 0 24 24"
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M12 5v14M5 12h14" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.qty-stepper {
    display: inline-flex;
    align-items: center;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #ffffff;
    overflow: hidden;
}

.qty-stepper--busy {
    opacity: 0.6;
}

.qty-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: none;
    background: #ffffff;
    color: #0f172a;
    cursor: pointer;
    transition: background-color 0.12s ease, color 0.12s ease;
}

.qty-btn:hover:not(:disabled) {
    background: #f1f5f9;
}

.qty-btn:active:not(:disabled) {
    background: #e2e8f0;
}

.qty-btn:disabled {
    color: #cbd5e1;
    cursor: not-allowed;
}

.qty-btn:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: -2px;
}

.qty-input {
    width: 44px;
    height: 44px;
    border: none;
    border-left: 1px solid #e2e8f0;
    border-right: 1px solid #e2e8f0;
    text-align: center;
    font-size: 14px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    color: #0f172a;
    background: #ffffff;
}

.qty-input:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: -2px;
}

.qty-input:disabled {
    background: #f8fafc;
    color: #94a3b8;
}
</style>

<script setup>
/*
|--------------------------------------------------------------------------
| Payment method selector
|--------------------------------------------------------------------------
|
| Each supported method is a native radio inside a full-width card, so
| arrow keys, Space, touch and screen readers all behave as a radio group.
| The methods themselves come from usePayment.js; this component only
| renders them and reports the choice back through v-model.
|
*/

import { computed } from 'vue';

const props = defineProps({
    methods: {
        type: Array,
        default: () => []
    },
    error: {
        type: String,
        default: ''
    },
    disabled: {
        type: Boolean,
        default: false
    }
});

const selectedMethod = defineModel({ type: String, default: '' });

// Methods marked available: false are shown as "Coming soon" but can't be
// chosen.
const hasAvailableMethod = computed(() => props.methods.some(method => method.available !== false));

function isLocked(method) {
    return props.disabled || method.available === false;
}
</script>

<template>
    <div class="payment-selector">
        <div
            v-if="!hasAvailableMethod"
            class="mb-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900"
            role="alert"
        >
            <p class="font-bold">No payment method is available for this order.</p>
            <p class="mt-0.5">You can't place this order right now. Try changing the delivery address or the items in your cart.</p>
        </div>

        <div
            v-if="methods.length"
            role="radiogroup"
            aria-labelledby="checkout-payment-title"
            :aria-describedby="error ? 'checkout-payment-error' : undefined"
            class="flex flex-col gap-3"
        >
            <label
                v-for="(method, index) in methods"
                :key="method.id"
                class="payment-option group relative flex items-center gap-4 rounded-xl border-[1.5px] p-4 transition-colors has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-teal-600"
                :class="[
                    selectedMethod === method.id
                        ? 'border-slate-900 bg-slate-50'
                        : 'border-slate-200 bg-white',
                    isLocked(method)
                        ? 'cursor-not-allowed'
                        : 'cursor-pointer hover:border-slate-400',
                    disabled ? 'opacity-60' : ''
                ]"
                :style="{ '--payment-option-delay': `${index * 60}ms` }"
            >
                <input
                    v-model="selectedMethod"
                    type="radio"
                    name="payment-method"
                    :value="method.id"
                    :disabled="isLocked(method)"
                    :aria-describedby="method.available === false ? `payment-${method.id}-soon` : undefined"
                    class="sr-only"
                >

                <span
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg"
                    :class="{
                        'bg-teal-50 text-teal-700': method.id === 'cod',
                        'bg-blue-600 text-white': method.id === 'gcash',
                        'bg-slate-900 text-lime-300': method.id === 'maya',
                        'bg-slate-100 text-slate-700': method.id === 'card',
                        'opacity-50': method.available === false
                    }"
                    aria-hidden="true"
                >
                    <span
                        v-if="method.id === 'gcash' || method.id === 'maya'"
                        class="text-[11px] font-extrabold"
                        :class="{ italic: method.id === 'maya' }"
                    >{{ method.name }}</span>
                    <svg
                        v-else-if="method.id === 'card'"
                        viewBox="0 0 24 24"
                        width="22"
                        height="22"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect x="2" y="5" width="20" height="14" rx="2" />
                        <path d="M2 10h20" />
                    </svg>
                    <svg
                        v-else
                        viewBox="0 0 24 24"
                        width="22"
                        height="22"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect x="2" y="6" width="20" height="12" rx="2" />
                        <circle cx="12" cy="12" r="2" />
                        <path d="M6 12h.01M18 12h.01" />
                    </svg>
                </span>

                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            class="text-[15px] font-bold"
                            :class="method.available === false ? 'text-slate-500' : 'text-slate-900'"
                        >{{ method.name }}</span>
                        <span
                            v-if="method.available === false"
                            :id="`payment-${method.id}-soon`"
                            class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-600"
                        >Coming soon</span>
                    </span>
                    <span class="text-[13px] leading-snug text-slate-500">{{ method.description }}</span>
                </span>

                <span
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
                    :class="[
                        selectedMethod === method.id ? 'border-slate-900' : 'border-slate-300',
                        method.available === false ? 'bg-slate-100' : ''
                    ]"
                    aria-hidden="true"
                >
                    <span
                        class="payment-dot h-2.5 w-2.5 rounded-full bg-slate-900"
                        :class="selectedMethod === method.id ? 'scale-100' : 'scale-0'"
                    ></span>
                </span>
            </label>
        </div>

        <p
            v-if="error"
            id="checkout-payment-error"
            class="mt-3 text-[13px] font-semibold text-red-700"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>

<style scoped>
/*
| Subtle only: options fade in, the selected dot scales into place. Both
| are skipped under prefers-reduced-motion, and neither delays input.
*/

.payment-dot {
    transition: transform 0.18s cubic-bezier(0.2, 0.9, 0.3, 1.2);
}

@media (prefers-reduced-motion: no-preference) {
    .payment-option {
        animation: payment-option-in 0.24s ease-out both;
        animation-delay: var(--payment-option-delay, 0ms);
    }
}

@keyframes payment-option-in {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .payment-dot {
        transition: none;
    }
}
</style>

<script setup>
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch
} from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false
    },
    item: {
        type: Object,
        default: null
    },
    orderId: {
        type: [String, Number],
        default: null
    },
    submitting: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits([
    'close',
    'submit'
]);

const requestType = ref('refund_only');
const quantity = ref(1);
const reason = ref('');
const otherReason = ref('');
const details = ref('');
const evidenceFiles = ref([]);
const evidencePreviews = ref([]);
const validationMessage = ref('');
const evidenceInput = ref(null);
const otherReasonInput = ref(null);

const requestTypeOptions = [
    {
        value: 'refund_only',
        label: 'Refund only',
        description: 'Keep the item, get your money back.'
    },
    {
        value: 'return_and_refund',
        label: 'Return & refund',
        description: 'Send the item back for a refund.'
    }
];

// Icon paths are Lucide-style 24x24 strokes.
const reasonOptions = [
    { value: 'damaged', label: 'Arrived damaged', icon: 'M16.5 9.4 7.55 4.24M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96 12 12.01l8.73-5.05M12 22.08V12' },
    { value: 'wrong_item', label: 'Wrong item received', icon: 'M18 6 6 18M6 6l12 12' },
    { value: 'incomplete', label: 'Missing parts or items', icon: 'M5 12h14' },
    { value: 'not_as_described', label: 'Not as described', icon: 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7ZM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z' },
    { value: 'quality_issue', label: 'Poor quality', icon: 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01' },
    { value: 'other', label: 'Other', icon: 'M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z' }
];

const productName = computed(() => {
    return props.item?.name || 'This item';
});

const maximumQuantity = computed(() => {
    return Math.max(1, Number(props.item?.quantity || 1));
});

const estimatedAmount = computed(() => {
    const unitPrice = Number(props.item?.unit_price ?? props.item?.price ?? 0);

    return unitPrice * Number(quantity.value || 0);
});

function formatPrice(price) {
    return `₱${Number(price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function clearEvidence() {
    evidencePreviews.value.forEach(preview => URL.revokeObjectURL(preview.url));
    evidenceFiles.value = [];
    evidencePreviews.value = [];

    if (evidenceInput.value) {
        evidenceInput.value.value = '';
    }
}

function resetForm() {
    requestType.value = 'refund_only';
    quantity.value = 1;
    reason.value = '';
    otherReason.value = '';
    details.value = '';
    validationMessage.value = '';
    clearEvidence();
}

watch(
    [() => props.show, () => props.item],
    ([show]) => {
        if (show) {
            resetForm();
        } else {
            clearEvidence();
        }
    }
);

async function selectReason(value) {
    reason.value = value;
    validationMessage.value = '';

    if (value === 'other') {
        await nextTick();
        otherReasonInput.value?.focus();
    }
}

function stepQuantity(delta) {
    quantity.value = Math.min(maximumQuantity.value, Math.max(1, quantity.value + delta));
}

function handleEvidenceChange(event) {
    const incoming = Array.from(event.target.files || []);
    event.target.value = '';
    validationMessage.value = '';

    if (!incoming.length) {
        return;
    }

    const files = [...evidenceFiles.value, ...incoming];

    if (files.length > 3) {
        validationMessage.value = 'You can upload up to 3 images.';
        return;
    }

    if (incoming.some(file => !String(file.type).startsWith('image/'))) {
        validationMessage.value = 'Evidence files must be images.';
        return;
    }

    if (incoming.some(file => file.size > 5 * 1024 * 1024)) {
        validationMessage.value = 'Each image must be 5 MB or smaller.';
        return;
    }

    evidenceFiles.value = files;
    evidencePreviews.value = [
        ...evidencePreviews.value,
        ...incoming.map(file => ({ name: file.name, url: URL.createObjectURL(file) }))
    ];
}

function removeEvidence(index) {
    const preview = evidencePreviews.value[index];

    if (preview) {
        URL.revokeObjectURL(preview.url);
    }

    evidenceFiles.value.splice(index, 1);
    evidencePreviews.value.splice(index, 1);
}

function closeModal() {
    if (props.submitting) {
        return;
    }

    clearEvidence();
    emit('close');
}

function submitForm() {
    validationMessage.value = '';

    if (!reason.value) {
        validationMessage.value = 'Please choose why you want a refund.';
        return;
    }

    if (reason.value === 'other' && otherReason.value.trim().length < 3) {
        validationMessage.value = 'Please tell us your reason.';
        otherReasonInput.value?.focus();
        return;
    }

    if (details.value.trim().length < 10) {
        validationMessage.value = 'Please describe the problem in at least 10 characters.';
        return;
    }

    if (evidenceFiles.value.length < 1) {
        validationMessage.value = 'Please add at least one photo.';
        return;
    }

    emit('submit', {
        requestType: requestType.value,
        quantity: Number(quantity.value),
        reason: reason.value,
        otherReason: reason.value === 'other' ? otherReason.value.trim() : null,
        details: details.value.trim(),
        evidence: [...evidenceFiles.value]
    });
}

function handleKeydown(event) {
    if (event.key === 'Escape' && props.show) {
        closeModal();
    }
}

onMounted(() => document.addEventListener('keydown', handleKeydown));

onBeforeUnmount(() => {
    clearEvidence();
    document.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-[90] flex items-end sm:items-center justify-center bg-slate-900/50 backdrop-blur-sm sm:p-4"
                @click.self="closeModal"
            >
                <section
                    class="w-full sm:max-w-xl max-h-[92vh] flex flex-col bg-white rounded-t-3xl sm:rounded-3xl shadow-2xl overflow-hidden"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="refund-modal-title"
                >
                    <!-- Header -->
                    <header class="flex items-start justify-between gap-4 px-6 pt-6 pb-4 border-b border-slate-100">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Order {{ orderId }}</p>
                            <h2 id="refund-modal-title" class="text-xl font-bold text-slate-900 mt-0.5">Request a refund</h2>
                            <p class="text-sm text-slate-500 truncate mt-0.5">{{ productName }}<span v-if="item?.variation"> · {{ item.variation }}</span></p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 w-10 h-10 grid place-items-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors"
                            aria-label="Close"
                            @click="closeModal"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </header>

                    <form class="flex-1 overflow-y-auto px-6 py-5 space-y-6" novalidate @submit.prevent="submitForm">
                        <!-- Reason -->
                        <fieldset>
                            <legend class="text-sm font-bold text-slate-900 mb-3">Why do you want a refund?</legend>
                            <div class="grid grid-cols-2 gap-2.5" role="radiogroup">
                                <button
                                    v-for="option in reasonOptions"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="reason === option.value"
                                    class="flex items-center gap-2.5 min-h-[52px] px-3.5 py-3 rounded-2xl border text-left text-sm font-semibold transition-all"
                                    :class="reason === option.value
                                        ? 'border-[#0d9488] bg-teal-50 text-[#0f766e] ring-2 ring-[#0d9488]/20'
                                        : 'border-slate-200 text-slate-700 hover:border-slate-300 hover:bg-slate-50'"
                                    @click="selectReason(option.value)"
                                >
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0" aria-hidden="true">
                                        <path :d="option.icon" />
                                    </svg>
                                    {{ option.label }}
                                </button>
                            </div>

                            <Transition
                                enter-active-class="transition duration-150 ease-out"
                                enter-from-class="opacity-0 -translate-y-1"
                            >
                                <label v-if="reason === 'other'" class="block mt-3">
                                    <span class="sr-only">Your reason</span>
                                    <input
                                        ref="otherReasonInput"
                                        v-model="otherReason"
                                        type="text"
                                        maxlength="255"
                                        placeholder="Type your reason"
                                        class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/20"
                                    >
                                </label>
                            </Transition>
                        </fieldset>

                        <!-- Request type -->
                        <fieldset>
                            <legend class="text-sm font-bold text-slate-900 mb-3">What would you like?</legend>
                            <div class="grid grid-cols-2 gap-2.5">
                                <label
                                    v-for="option in requestTypeOptions"
                                    :key="option.value"
                                    class="cursor-pointer px-4 py-3 rounded-2xl border transition-all"
                                    :class="requestType === option.value
                                        ? 'border-[#0d9488] bg-teal-50 ring-2 ring-[#0d9488]/20'
                                        : 'border-slate-200 hover:border-slate-300'"
                                >
                                    <input v-model="requestType" type="radio" name="refund-type" :value="option.value" class="sr-only">
                                    <strong class="block text-sm text-slate-900">{{ option.label }}</strong>
                                    <small class="block text-xs text-slate-500 mt-0.5">{{ option.description }}</small>
                                </label>
                            </div>
                        </fieldset>

                        <!-- Quantity + amount -->
                        <div class="flex items-center justify-between gap-4 p-4 rounded-2xl bg-slate-50">
                            <div>
                                <p class="text-xs font-semibold text-slate-500">Quantity</p>
                                <div class="flex items-center gap-2 mt-1.5">
                                    <button type="button" class="w-9 h-9 grid place-items-center rounded-xl bg-white border border-slate-200 text-slate-700 disabled:opacity-40" :disabled="quantity <= 1" aria-label="Decrease quantity" @click="stepQuantity(-1)">−</button>
                                    <span class="w-8 text-center font-bold text-slate-900">{{ quantity }}</span>
                                    <button type="button" class="w-9 h-9 grid place-items-center rounded-xl bg-white border border-slate-200 text-slate-700 disabled:opacity-40" :disabled="quantity >= maximumQuantity" aria-label="Increase quantity" @click="stepQuantity(1)">+</button>
                                    <span class="text-xs text-slate-400">of {{ maximumQuantity }}</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xs font-semibold text-slate-500">Refund amount</p>
                                <p class="text-xl font-bold text-[#0d9488] mt-1">{{ formatPrice(estimatedAmount) }}</p>
                            </div>
                        </div>

                        <!-- Details -->
                        <label class="block">
                            <span class="text-sm font-bold text-slate-900">Describe the problem</span>
                            <textarea
                                v-model="details"
                                maxlength="1000"
                                rows="3"
                                placeholder="What happened? This helps the seller review faster."
                                class="mt-2 w-full px-4 py-3 rounded-xl border border-slate-200 text-sm resize-none focus:outline-none focus:border-[#0d9488] focus:ring-2 focus:ring-[#0d9488]/20"
                            ></textarea>
                            <span class="block text-right text-[11px] text-slate-400">{{ details.length }}/1000</span>
                        </label>

                        <!-- Evidence -->
                        <div>
                            <p class="text-sm font-bold text-slate-900">Photos <span class="font-normal text-slate-400">(1–3, up to 5 MB each)</span></p>
                            <div class="flex flex-wrap gap-3 mt-2">
                                <div
                                    v-for="(preview, index) in evidencePreviews"
                                    :key="preview.url"
                                    class="relative w-20 h-20 rounded-xl overflow-hidden border border-slate-200"
                                >
                                    <img :src="preview.url" :alt="`Evidence ${index + 1}`" width="80" height="80" class="w-full h-full object-cover">
                                    <button
                                        type="button"
                                        class="absolute top-1 right-1 w-6 h-6 grid place-items-center rounded-full bg-slate-900/70 text-white text-xs"
                                        aria-label="Remove photo"
                                        @click="removeEvidence(index)"
                                    >✕</button>
                                </div>
                                <label
                                    v-if="evidencePreviews.length < 3"
                                    class="w-20 h-20 grid place-items-center rounded-xl border-2 border-dashed border-slate-200 text-slate-400 hover:border-[#0d9488] hover:text-[#0d9488] cursor-pointer transition-colors"
                                >
                                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" /><circle cx="12" cy="13" r="3" />
                                    </svg>
                                    <span class="sr-only">Add photos</span>
                                    <input ref="evidenceInput" type="file" accept="image/*" multiple class="sr-only" @change="handleEvidenceChange">
                                </label>
                            </div>
                        </div>

                        <p v-if="validationMessage" class="flex items-center gap-2 px-4 py-3 rounded-xl bg-red-50 text-sm font-medium text-red-600" role="alert">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="shrink-0"><circle cx="12" cy="12" r="10" /><path d="M12 8v4M12 16h.01" /></svg>
                            {{ validationMessage }}
                        </p>
                    </form>

                    <!-- Footer -->
                    <footer class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-white">
                        <button
                            type="button"
                            class="flex-1 h-12 rounded-xl border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-colors"
                            :disabled="submitting"
                            @click="closeModal"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="flex-[2] h-12 rounded-xl bg-[#0d9488] text-sm font-bold text-white hover:bg-[#0f766e] disabled:opacity-60 disabled:cursor-wait transition-colors flex items-center justify-center gap-2"
                            :disabled="submitting"
                            @click="submitForm"
                        >
                            <svg v-if="submitting" class="animate-spin" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.22-8.56" /></svg>
                            {{ submitting ? 'Submitting…' : 'Submit request' }}
                        </button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

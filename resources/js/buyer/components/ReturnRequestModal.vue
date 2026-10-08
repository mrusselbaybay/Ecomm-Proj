<script setup>
/*
|--------------------------------------------------------------------------
| ReturnRequestModal — ask for a return / refund on one delivered item
|--------------------------------------------------------------------------
|
| Collects what OrderDetails' handleReturnSubmit sends (useBuyer
| submitReturnRequest): request type, quantity, reason, details (10+
| characters) and 1–3 evidence images (images only, 5 MB each).
|
| The parent does the saving and passes `saving` / `error` back, so a
| failed request keeps everything entered and the form can't be sent
| twice. Each field reports its own problem; on submit the first invalid
| field takes focus. Dialog behaviour is BaseModal's, including the
| "Discard changes?" step when closing with entries made.
|
*/
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import BaseModal from './BaseModal.vue';
import OrderItemThumb from './OrderItemThumb.vue';

const MAX_EVIDENCE = 3;
const MAX_EVIDENCE_BYTES = 5 * 1024 * 1024;
const MIN_DETAILS = 10;
const MAX_DETAILS = 1000;

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
    saving: {
        type: Boolean,
        default: false
    },
    error: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['close', 'submit']);

const uid = useId();
const formId = `rq-form-${uid}`;
const fieldId = name => `rq-${name}-${uid}`;

const requestTypeOptions = [
    {
        value: 'return_and_refund',
        label: 'Return and refund',
        description: 'Send the item back; you’re refunded once the seller approves.'
    },
    {
        value: 'refund_only',
        label: 'Refund only',
        description: 'Keep the item and ask for a refund without returning it.'
    }
];

const reasonOptions = [
    { value: 'damaged', label: 'Product arrived damaged' },
    { value: 'wrong_item', label: 'Wrong product received' },
    { value: 'incomplete', label: 'Missing parts or items' },
    { value: 'not_as_described', label: 'Product is not as described' },
    { value: 'quality_issue', label: 'Product quality issue' },
    { value: 'other', label: 'Other reason' }
];

// Kept while the exit plays; the parent clears `item` on close.
const shownItem = ref(null);

const requestType = ref('');
const quantity = ref(1);
const reason = ref('');
const details = ref('');
const evidence = ref([]);
const errors = ref({});
const evidenceInput = ref(null);
const errorAlert = ref(null);

const productName = computed(() => shownItem.value?.name
    || `Product #${shownItem.value?.productId ?? shownItem.value?.product_id ?? 'Unknown'}`);

const maximumQuantity = computed(() => Math.max(1, Number(shownItem.value?.quantity || 1)));

const estimatedAmount = computed(() => {
    const unitPrice = Number(shownItem.value?.unit_price ?? shownItem.value?.price ?? 0);

    return unitPrice * Number(quantity.value || 0);
});

const isDirty = computed(() => Boolean(requestType.value || reason.value || details.value.trim() || evidence.value.length));

function formatPrice(price) {
    return `₱${Number(price || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatFileSize(size) {
    const bytes = Number(size || 0);

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function releaseEvidence() {
    evidence.value.forEach(entry => URL.revokeObjectURL(entry.url));
}

function resetForm() {
    releaseEvidence();
    requestType.value = '';
    quantity.value = 1;
    reason.value = '';
    details.value = '';
    evidence.value = [];
    errors.value = {};
}

watch(() => props.show, (show) => {
    if (show) {
        shownItem.value = props.item;
        resetForm();
    }
}, { immediate: true });

watch(() => props.error, (error) => {
    if (error) {
        nextTick(() => errorAlert.value?.scrollIntoView({ block: 'nearest' }));
    }
});

onBeforeUnmount(releaseEvidence);

function clearError(name) {
    if (errors.value[name]) {
        const next = { ...errors.value };

        delete next[name];
        errors.value = next;
    }
}

/*
| Evidence: added to, never replaced, up to MAX_EVIDENCE.
*/

function chooseEvidence() {
    evidenceInput.value?.click();
}

function addEvidence(event) {
    let problem = '';

    for (const file of [...(event.target.files || [])]) {
        if (evidence.value.length >= MAX_EVIDENCE) {
            problem = `You can attach up to ${MAX_EVIDENCE} images.`;
            break;
        }

        if (!String(file.type).startsWith('image/')) {
            problem = `${file.name} isn’t an image.`;
            continue;
        }

        if (file.size > MAX_EVIDENCE_BYTES) {
            problem = `${file.name} is larger than 5 MB.`;
            continue;
        }

        evidence.value.push({ file, name: file.name, size: file.size, url: URL.createObjectURL(file), key: `${file.name}-${file.size}-${Math.random()}` });
    }

    event.target.value = '';

    if (problem) {
        errors.value = { ...errors.value, evidence: problem };
    } else {
        clearError('evidence');
    }
}

function removeEvidence(index) {
    URL.revokeObjectURL(evidence.value[index].url);
    evidence.value.splice(index, 1);
    clearError('evidence');
}

/*
| Submit
*/

function validate() {
    const next = {};
    const count = Number(quantity.value);

    if (!requestType.value) {
        next.requestType = 'Choose what you’d like to happen.';
    }

    if (!Number.isInteger(count) || count < 1 || count > maximumQuantity.value) {
        next.quantity = `Choose a quantity from 1 to ${maximumQuantity.value}.`;
    }

    if (!reason.value) {
        next.reason = 'Choose a reason.';
    }

    if (details.value.trim().length < MIN_DETAILS) {
        next.details = `Describe the problem in at least ${MIN_DETAILS} characters.`;
    }

    if (evidence.value.length < 1) {
        next.evidence = 'Attach at least one photo of the problem.';
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

function submitForm() {
    if (props.saving) {
        return;
    }

    if (!validate()) {
        nextTick(() => {
            const invalid = document.querySelector(`#${CSS.escape(formId)} [aria-invalid="true"]`);

            invalid?.focus();
            invalid?.scrollIntoView({ block: 'nearest' });
        });

        return;
    }

    emit('submit', {
        requestType: requestType.value,
        quantity: Number(quantity.value),
        reason: reason.value,
        details: details.value.trim(),
        evidence: evidence.value.map(entry => entry.file)
    });
}
</script>

<template>
    <BaseModal
        :open="show"
        size="md"
        title="Request a return or refund"
        :eyebrow="orderId ? `Order ${orderId}` : ''"
        description="Tell the seller what went wrong. They review every request before a refund is issued."
        close-label="Close return request form"
        :busy="saving"
        :dirty="isDirty"
        :initial-focus="`#${fieldId('type')}-0`"
        @close="emit('close')"
        @after-leave="resetForm"
    >
        <div class="rv-product">
            <OrderItemThumb
                :src="shownItem?.image || ''"
                :category="shownItem?.category || ''"
            />
            <div class="rv-product-text">
                <p class="rv-product-name">{{ productName }}</p>
                <p class="rv-product-meta">
                    <span v-if="shownItem?.variation">{{ shownItem.variation }}</span>
                    <span>Quantity bought: {{ maximumQuantity }}</span>
                </p>
            </div>
        </div>

        <form
            :id="formId"
            class="rq-form"
            novalidate
            @submit.prevent="submitForm"
        >
            <fieldset
                class="rq-field"
                :disabled="saving"
                :aria-describedby="errors.requestType ? fieldId('type-err') : undefined"
            >
                <legend class="rv-label">What would you like? <span class="rv-required">Required</span></legend>
                <div class="rq-choices">
                    <label
                        v-for="(option, index) in requestTypeOptions"
                        :key="option.value"
                        class="rq-choice"
                        :class="{ 'is-selected': requestType === option.value }"
                    >
                        <input
                            :id="`${fieldId('type')}-${index}`"
                            v-model="requestType"
                            type="radio"
                            :name="fieldId('type')"
                            :value="option.value"
                            :aria-invalid="errors.requestType && index === 0 ? 'true' : undefined"
                            @change="clearError('requestType')"
                        >
                        <span class="rq-choice-text">
                            <strong>{{ option.label }}</strong>
                            <small>{{ option.description }}</small>
                        </span>
                    </label>
                </div>
                <p
                    v-if="errors.requestType"
                    :id="fieldId('type-err')"
                    class="nx-field-error"
                >{{ errors.requestType }}</p>
            </fieldset>

            <div class="rq-grid">
                <div class="rq-field">
                    <label
                        class="rv-label"
                        :for="fieldId('qty')"
                    >Quantity</label>
                    <select
                        :id="fieldId('qty')"
                        v-model.number="quantity"
                        class="rq-control"
                        :disabled="saving"
                        :aria-invalid="errors.quantity ? 'true' : undefined"
                        :aria-describedby="errors.quantity ? fieldId('qty-err') : undefined"
                        @change="clearError('quantity')"
                    >
                        <option
                            v-for="option in maximumQuantity"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                    <p
                        v-if="errors.quantity"
                        :id="fieldId('qty-err')"
                        class="nx-field-error"
                    >{{ errors.quantity }}</p>
                </div>

                <div class="rq-field">
                    <label
                        class="rv-label"
                        :for="fieldId('reason')"
                    >Reason <span class="rv-required">Required</span></label>
                    <select
                        :id="fieldId('reason')"
                        v-model="reason"
                        class="rq-control"
                        :disabled="saving"
                        :aria-invalid="errors.reason ? 'true' : undefined"
                        :aria-describedby="errors.reason ? fieldId('reason-err') : undefined"
                        @change="clearError('reason')"
                    >
                        <option
                            value=""
                            disabled
                        >
                            Select a reason
                        </option>
                        <option
                            v-for="option in reasonOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <p
                        v-if="errors.reason"
                        :id="fieldId('reason-err')"
                        class="nx-field-error"
                    >{{ errors.reason }}</p>
                </div>
            </div>

            <p class="rq-amount">
                <span>Estimated item amount</span>
                <strong>{{ formatPrice(estimatedAmount) }}</strong>
            </p>

            <div class="rq-field">
                <label
                    class="rv-label"
                    :for="fieldId('details')"
                >What went wrong? <span class="rv-required">Required</span></label>
                <textarea
                    :id="fieldId('details')"
                    v-model="details"
                    class="rq-control"
                    :maxlength="MAX_DETAILS"
                    :readonly="saving"
                    rows="4"
                    placeholder="Describe the problem so the seller can review it, e.g. the screen is cracked in the top corner."
                    :aria-invalid="errors.details ? 'true' : undefined"
                    :aria-describedby="errors.details ? `${fieldId('details-err')} ${fieldId('details-count')}` : fieldId('details-count')"
                    @input="details.trim().length >= MIN_DETAILS && clearError('details')"
                ></textarea>
                <p
                    v-if="errors.details"
                    :id="fieldId('details-err')"
                    class="nx-field-error"
                >{{ errors.details }}</p>
                <p
                    :id="fieldId('details-count')"
                    class="rv-hint"
                >{{ details.length }} / {{ MAX_DETAILS }}</p>
            </div>

            <fieldset
                class="rq-field"
                :disabled="saving"
            >
                <legend class="rv-label">Photos of the problem <span class="rv-required">Required · 1 to {{ MAX_EVIDENCE }}</span></legend>
                <ul class="rv-photos">
                    <li
                        v-for="(entry, index) in evidence"
                        :key="entry.key"
                        class="rv-photo"
                    >
                        <img
                            :src="entry.url"
                            :alt="`Evidence ${index + 1}: ${entry.name}, ${formatFileSize(entry.size)}`"
                        >
                        <button
                            type="button"
                            class="rv-photo-remove"
                            :aria-label="`Remove ${entry.name}`"
                            @click="removeEvidence(index)"
                        >
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </li>
                    <li v-if="evidence.length < MAX_EVIDENCE">
                        <button
                            type="button"
                            class="rv-photo-add"
                            :aria-invalid="errors.evidence ? 'true' : undefined"
                            :aria-describedby="errors.evidence ? fieldId('evidence-err') : undefined"
                            @click="chooseEvidence"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3Z" /><circle cx="12" cy="13" r="3" /></svg>
                            Add photo
                        </button>
                    </li>
                </ul>
                <input
                    ref="evidenceInput"
                    type="file"
                    class="sr-only"
                    accept="image/*"
                    multiple
                    tabindex="-1"
                    aria-hidden="true"
                    @change="addEvidence"
                >
                <p
                    v-if="errors.evidence"
                    :id="fieldId('evidence-err')"
                    class="nx-field-error"
                    role="alert"
                >{{ errors.evidence }}</p>
                <p
                    v-else
                    class="rv-hint"
                >Images up to 5 MB each.</p>
            </fieldset>

            <p
                v-if="error"
                ref="errorAlert"
                class="nx-form-alert"
                role="alert"
            >
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5M12 16.5h.01" /></svg>
                <span>{{ error }}</span>
            </p>

            <p class="rq-notice">
                Your request is sent as Pending. The seller reviews it, and any refund is processed after approval.
            </p>
        </form>

        <template #footer="{ requestClose }">
            <button
                type="button"
                class="btn btn-ghost"
                :aria-disabled="saving ? 'true' : undefined"
                @click="requestClose"
            >
                Cancel
            </button>
            <button
                type="submit"
                class="btn btn-primary"
                :form="formId"
                :aria-disabled="saving ? 'true' : undefined"
                :aria-busy="saving ? 'true' : undefined"
            >
                <span
                    v-if="saving"
                    class="nx-spinner"
                    aria-hidden="true"
                ></span>
                {{ saving ? 'Submitting…' : 'Submit Request' }}
            </button>
        </template>
    </BaseModal>
</template>

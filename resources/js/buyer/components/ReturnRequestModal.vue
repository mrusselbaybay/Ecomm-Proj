<script setup>
import {
    computed,
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
    }
});

const emit = defineEmits([
    'close',
    'submit'
]);

const requestType = ref('');
const quantity = ref(1);
const reason = ref('');
const details = ref('');
const evidenceFiles = ref([]);
const evidencePreviews = ref([]);
const validationMessage = ref('');
const evidenceInput = ref(null);

const requestTypeOptions = [
    {
        value: 'return_and_refund',
        label: 'Return and Refund',
        description:
            'Send the product back and receive a refund after approval.'
    },
    {
        value: 'refund_only',
        label: 'Refund Only',
        description:
            'Request a refund without returning the product.'
    }
];

const reasonOptions = [
    {
        value: 'damaged',
        label: 'Product arrived damaged'
    },
    {
        value: 'wrong_item',
        label: 'Wrong product received'
    },
    {
        value: 'incomplete',
        label: 'Missing parts or items'
    },
    {
        value: 'not_as_described',
        label: 'Product is not as described'
    },
    {
        value: 'quality_issue',
        label: 'Product quality issue'
    },
    {
        value: 'other',
        label: 'Other reason'
    }
];

const productName = computed(() => {
    return (
        props.item?.name ||
        `Product #${
            props.item?.productId ??
            props.item?.product_id ??
            'Unknown'
        }`
    );
});

const maximumQuantity = computed(() => {
    return Math.max(
        1,
        Number(props.item?.quantity || 1)
    );
});

const quantityOptions = computed(() => {
    return Array.from(
        {
            length: maximumQuantity.value
        },
        (_, index) => index + 1
    );
});

const estimatedAmount = computed(() => {
    const unitPrice = Number(
        props.item?.unit_price ??
        props.item?.price ??
        0
    );

    return unitPrice * Number(quantity.value || 0);
});

function formatPrice(price) {
    return `₱${Number(price || 0).toFixed(2)}`;
}

function formatFileSize(size) {
    const bytes = Number(size || 0);

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${
        (bytes / (1024 * 1024)).toFixed(1)
    } MB`;
}

function clearEvidence() {
    evidencePreviews.value.forEach(preview => {
        URL.revokeObjectURL(preview.url);
    });

    evidenceFiles.value = [];
    evidencePreviews.value = [];

    if (evidenceInput.value) {
        evidenceInput.value.value = '';
    }
}

function resetForm() {
    requestType.value = '';
    quantity.value = 1;
    reason.value = '';
    details.value = '';
    validationMessage.value = '';
    clearEvidence();
}

watch(
    [
        () => props.show,
        () => props.item
    ],
    ([show]) => {
        if (show) {
            resetForm();
        } else {
            clearEvidence();
        }
    }
);

function handleEvidenceChange(event) {
    const files = Array.from(
        event.target.files || []
    );

    validationMessage.value = '';

    if (files.length < 1) {
        return;
    }

    if (files.length > 3) {
        validationMessage.value =
            'You can upload a maximum of 3 images.';
        event.target.value = '';
        return;
    }

    const invalidType = files.some(file =>
        !String(file.type).startsWith('image/')
    );

    if (invalidType) {
        validationMessage.value =
            'Evidence files must be images.';
        event.target.value = '';
        return;
    }

    const oversizedFile = files.some(file =>
        file.size > (5 * 1024 * 1024)
    );

    if (oversizedFile) {
        validationMessage.value =
            'Each evidence image must be 5 MB or smaller.';
        event.target.value = '';
        return;
    }

    clearEvidence();

    evidenceFiles.value = files;
    evidencePreviews.value = files.map(file => ({
        name: file.name,
        size: file.size,
        url: URL.createObjectURL(file)
    }));
}

function removeEvidence(index) {
    const preview = evidencePreviews.value[index];

    if (preview) {
        URL.revokeObjectURL(preview.url);
    }

    evidenceFiles.value.splice(index, 1);
    evidencePreviews.value.splice(index, 1);

    if (
        evidenceFiles.value.length === 0 &&
        evidenceInput.value
    ) {
        evidenceInput.value.value = '';
    }
}

function closeModal() {
    clearEvidence();
    emit('close');
}

function submitForm() {
    validationMessage.value = '';

    if (!requestType.value) {
        validationMessage.value =
            'Please select a request type.';
        return;
    }

    if (!reason.value) {
        validationMessage.value =
            'Please select a reason.';
        return;
    }

    if (
        !Number.isInteger(Number(quantity.value)) ||
        Number(quantity.value) < 1 ||
        Number(quantity.value) > maximumQuantity.value
    ) {
        validationMessage.value =
            'Please select a valid quantity.';
        return;
    }

    if (details.value.trim().length < 10) {
        validationMessage.value =
            'Please provide at least 10 characters of details.';
        return;
    }

    if (evidenceFiles.value.length < 1) {
        validationMessage.value =
            'Please attach at least one evidence image.';
        return;
    }

    emit('submit', {
        requestType: requestType.value,
        quantity: Number(quantity.value),
        reason: reason.value,
        details: details.value.trim(),
        evidence: [...evidenceFiles.value]
    });
}

function handleKeydown(event) {
    if (
        event.key === 'Escape' &&
        props.show
    ) {
        closeModal();
    }
}

onMounted(() => {
    document.addEventListener(
        'keydown',
        handleKeydown
    );
});

onBeforeUnmount(() => {
    clearEvidence();
    document.removeEventListener(
        'keydown',
        handleKeydown
    );
});
</script>

<template>
    <div
        v-if="show"
        class="return-modal-overlay"
        @click.self="closeModal"
    >
        <section
            class="return-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="return-modal-title"
        >
            <header class="return-modal-header">
                <div>
                    <span class="return-modal-eyebrow">
                        Order {{ orderId }}
                    </span>
                    <h2 id="return-modal-title">
                        Return / Refund Request
                    </h2>
                    <p>
                        Tell us what went wrong with this product.
                    </p>
                </div>

                <button
                    type="button"
                    class="return-modal-close"
                    aria-label="Close return request form"
                    @click="closeModal"
                >
                    &times;
                </button>
            </header>

            <div class="return-product-summary">
                <div class="return-product-placeholder">
                    Product
                </div>

                <div>
                    <strong>{{ productName }}</strong>
                    <p>
                        Variation:
                        {{ item?.variation || 'Default' }}
                    </p>
                    <p>
                        Purchased quantity:
                        {{ maximumQuantity }}
                    </p>
                </div>
            </div>

            <form
                class="return-request-form"
                @submit.prevent="submitForm"
            >
                <fieldset class="return-type-fieldset">
                    <legend>
                        Request type <span>*</span>
                    </legend>

                    <div class="return-type-options">
                        <label
                            v-for="option in requestTypeOptions"
                            :key="option.value"
                            class="return-type-option"
                            :class="{
                                selected:
                                    requestType === option.value
                            }"
                        >
                            <input
                                v-model="requestType"
                                type="radio"
                                name="return-request-type"
                                :value="option.value"
                            >
                            <span>
                                <strong>{{ option.label }}</strong>
                                <small>{{ option.description }}</small>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <div class="return-form-grid">
                    <label class="return-form-field">
                        <span>Quantity <b>*</b></span>
                        <select v-model.number="quantity">
                            <option
                                v-for="option in quantityOptions"
                                :key="option"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                    </label>

                    <label class="return-form-field">
                        <span>Reason <b>*</b></span>
                        <select v-model="reason">
                            <option value="" disabled>
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
                    </label>
                </div>

                <div class="return-estimated-amount">
                    <span>Estimated item amount</span>
                    <strong>
                        {{ formatPrice(estimatedAmount) }}
                    </strong>
                </div>

                <label class="return-details-field">
                    <span>
                        Explain the problem <b>*</b>
                    </span>
                    <textarea
                        v-model="details"
                        maxlength="1000"
                        rows="5"
                        placeholder="Describe the issue clearly so the request can be reviewed."
                    ></textarea>
                    <small>
                        {{ details.length }}/1000 characters
                    </small>
                </label>

                <div class="return-evidence-field">
                    <div class="return-evidence-heading">
                        <div>
                            <strong>
                                Evidence images <b>*</b>
                            </strong>
                            <p>
                                Upload 1–3 images, up to 5 MB each.
                            </p>
                        </div>

                        <label class="return-upload-button">
                            Choose Images
                            <input
                                ref="evidenceInput"
                                type="file"
                                accept="image/*"
                                multiple
                                @change="handleEvidenceChange"
                            >
                        </label>
                    </div>

                    <div
                        v-if="evidencePreviews.length"
                        class="return-evidence-previews"
                    >
                        <article
                            v-for="(preview, index) in evidencePreviews"
                            :key="`${preview.name}-${index}`"
                            class="return-evidence-preview"
                        >
                            <img
                                :src="preview.url"
                                :alt="`Evidence ${index + 1}`"
                            >
                            <div>
                                <strong>{{ preview.name }}</strong>
                                <small>
                                    {{ formatFileSize(preview.size) }}
                                </small>
                            </div>
                            <button
                                type="button"
                                aria-label="Remove evidence image"
                                @click="removeEvidence(index)"
                            >
                                &times;
                            </button>
                        </article>
                    </div>
                </div>

                <p
                    v-if="validationMessage"
                    class="return-validation-message"
                    role="alert"
                >
                    {{ validationMessage }}
                </p>

                <p class="return-request-notice">
                    Your request will be submitted as Pending. Approval and
                    refund processing will be handled by the Seller and
                    platform after database integration.
                </p>

                <footer class="return-modal-actions">
                    <button
                        type="button"
                        class="return-cancel-button"
                        @click="closeModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="return-submit-button"
                    >
                        Submit Request
                    </button>
                </footer>
            </form>
        </section>
    </div>
</template>

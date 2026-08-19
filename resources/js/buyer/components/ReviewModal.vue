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

const rating = ref(0);
const hoveredRating = ref(0);
const comment = ref('');
const validationMessage = ref('');

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

const displayedRating = computed(() => {
    return hoveredRating.value || rating.value;
});

function resetForm() {
    rating.value = 0;
    hoveredRating.value = 0;
    comment.value = '';
    validationMessage.value = '';
}

watch(
    [
        () => props.show,
        () => props.item
    ],
    ([show]) => {
        if (show) {
            resetForm();
        }
    }
);

function selectRating(value) {
    rating.value = value;
    validationMessage.value = '';
}

function closeModal() {
    emit('close');
}

function submitForm() {
    if (rating.value < 1) {
        validationMessage.value =
            'Please select a star rating.';
        return;
    }

    emit('submit', {
        rating: rating.value,
        comment: comment.value.trim()
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
    document.removeEventListener(
        'keydown',
        handleKeydown
    );
});
</script>

<template>
    <div
        v-if="show"
        class="review-modal-overlay"
        @click.self="closeModal"
    >
        <section
            class="review-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="review-modal-title"
        >
            <header class="review-modal-header">
                <div>
                    <span class="review-modal-eyebrow">
                        Order {{ orderId }}
                    </span>
                    <h2 id="review-modal-title">
                        Rate this product
                    </h2>
                    <p>
                        Share your experience with your purchase.
                    </p>
                </div>

                <button
                    type="button"
                    class="review-modal-close"
                    aria-label="Close review form"
                    @click="closeModal"
                >
                    &times;
                </button>
            </header>

            <div class="review-product-summary">
                <div class="review-product-placeholder">
                    Product
                </div>

                <div>
                    <strong>{{ productName }}</strong>
                    <p>
                        Variation:
                        {{ item?.variation || 'Default' }}
                    </p>
                    <p>
                        Seller:
                        {{ item?.seller || 'NEXMART Seller' }}
                    </p>
                </div>
            </div>

            <form
                class="review-form"
                @submit.prevent="submitForm"
            >
                <fieldset class="review-rating-fieldset">
                    <legend>
                        Your rating
                        <span>*</span>
                    </legend>

                    <div
                        class="review-star-picker"
                        @mouseleave="hoveredRating = 0"
                    >
                        <button
                            v-for="star in 5"
                            :key="star"
                            type="button"
                            class="review-star-button"
                            :class="{
                                active:
                                    star <= displayedRating
                            }"
                            :aria-label="`${star} star${
                                star === 1 ? '' : 's'
                            }`"
                            @mouseenter="hoveredRating = star"
                            @focus="hoveredRating = star"
                            @blur="hoveredRating = 0"
                            @click="selectRating(star)"
                        >
                            &#9733;
                        </button>

                        <span class="review-rating-label">
                            {{
                                rating
                                    ? `${rating} out of 5`
                                    : 'Select a rating'
                            }}
                        </span>
                    </div>
                </fieldset>

                <label
                    class="review-comment-field"
                    for="review-comment"
                >
                    <span>Written review (optional)</span>
                    <textarea
                        id="review-comment"
                        v-model="comment"
                        maxlength="500"
                        rows="5"
                        placeholder="What did you like or dislike about this product?"
                    ></textarea>
                    <small>
                        {{ comment.length }}/500 characters
                    </small>
                </label>

                <p
                    v-if="validationMessage"
                    class="review-validation-message"
                    role="alert"
                >
                    {{ validationMessage }}
                </p>

                <footer class="review-modal-actions">
                    <button
                        type="button"
                        class="review-cancel-button"
                        @click="closeModal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="review-submit-button"
                    >
                        Submit Review
                    </button>
                </footer>
            </form>
        </section>
    </div>
</template>

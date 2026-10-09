<script setup>
/*
|--------------------------------------------------------------------------
| ReviewModal — write or edit a review for one purchased item
|--------------------------------------------------------------------------
|
| Opened from a completed order's details (OrderDetails.vue), per order
| line. What it collects is what the reviews API stores (Buyer\
| ReviewController): a 1–5 star rating (required), optional text up to
| 2,000 characters, and up to MAX_PHOTOS photos (JPEG, PNG or WebP, 5 MB
| each — uploaded to Storage by the server). Passing `review` opens it in
| edit mode with the current rating, text and photos; current photos can
| be kept or removed, new ones added.
|
| The parent does the saving: `submit` hands it { rating, comment, files,
| keepImages } and the parent passes `saving` / `error` back, so a failed
| save keeps everything the buyer typed. While saving, the form can't be
| sent twice or closed.
|
| Dialog behaviour (focus, Escape, backdrop, scroll lock, motion) is
| BaseModal's. Closing with unsaved changes asks first, inside the dialog.
|
*/
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import BaseModal from './BaseModal.vue';
import OrderItemThumb from './OrderItemThumb.vue';

const MAX_PHOTOS = 3;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_COMMENT = 2000;
const RATING_WORDS = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'];

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
    // The existing review: edit mode.
    review: {
        type: Object,
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

const formId = `rv-form-${useId()}`;

// What the dialog was opened with. The parent clears item / review as soon
// as it closes; these keep the content steady while the exit plays.
const shownItem = ref(null);
const shownReview = ref(null);

const isEdit = computed(() => Boolean(shownReview.value));

const rating = ref(0);
const comment = ref('');
const keptImages = ref([]);
const newPhotos = ref([]);
const validationMessage = ref('');
const photoMessage = ref('');

const starButtons = ref([]);
const fileInput = ref(null);

const photoCount = computed(() => keptImages.value.length + newPhotos.value.length);

const isDirty = computed(() => {
    const original = shownReview.value;

    return rating.value !== (Number(original?.rating) || 0)
        || comment.value.trim() !== (original?.comment || '').trim()
        || newPhotos.value.length > 0
        || keptImages.value.length !== (original?.images || []).length;
});

function releasePreviews() {
    newPhotos.value.forEach(photo => URL.revokeObjectURL(photo.preview));
}

function resetForm() {
    releasePreviews();
    rating.value = Number(shownReview.value?.rating) || 0;
    comment.value = shownReview.value?.comment || '';
    keptImages.value = [...(shownReview.value?.images || [])];
    newPhotos.value = [];
    validationMessage.value = '';
    photoMessage.value = '';
}

watch(() => props.show, (show) => {
    if (show) {
        shownItem.value = props.item;
        shownReview.value = props.review;
        resetForm();
    }
}, { immediate: true });

function afterLeave() {
    releasePreviews();
    newPhotos.value = [];
}

onBeforeUnmount(releasePreviews);

/*
| Rating: a radio group (arrow keys move and select).
*/

function selectRating(value) {
    rating.value = value;
    validationMessage.value = '';
}

function handleStarKeydown(event, star) {
    const next = { ArrowRight: star + 1, ArrowUp: star + 1, ArrowLeft: star - 1, ArrowDown: star - 1 }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();

    const value = Math.min(5, Math.max(1, next));

    selectRating(value);
    starButtons.value[value - 1]?.focus();
}

/*
| Photos
*/

function choosePhotos() {
    fileInput.value?.click();
}

function addPhotos(event) {
    photoMessage.value = '';

    for (const file of [...(event.target.files || [])]) {
        if (photoCount.value >= MAX_PHOTOS) {
            photoMessage.value = `You can add up to ${MAX_PHOTOS} photos.`;
            break;
        }

        if (!PHOTO_TYPES.includes(file.type)) {
            photoMessage.value = `${file.name} isn’t a JPEG, PNG or WebP photo.`;
            continue;
        }

        if (file.size > MAX_PHOTO_BYTES) {
            photoMessage.value = `${file.name} is larger than 5 MB.`;
            continue;
        }

        newPhotos.value.push({ file, preview: URL.createObjectURL(file), key: `${file.name}-${file.size}-${Math.random()}` });
    }

    event.target.value = '';
}

function removeNewPhoto(index) {
    URL.revokeObjectURL(newPhotos.value[index].preview);
    newPhotos.value.splice(index, 1);
    photoMessage.value = '';
}

function removeKeptPhoto(index) {
    keptImages.value.splice(index, 1);
    photoMessage.value = '';
}

/*
| Submit
*/

// A failed save: bring the message into view (it's announced as an alert).
const errorAlert = ref(null);

watch(() => props.error, (error) => {
    if (error) {
        nextTick(() => errorAlert.value?.scrollIntoView({ block: 'nearest' }));
    }
});

function submitForm() {
    if (props.saving) {
        return;
    }

    if (rating.value < 1) {
        validationMessage.value = 'Choose a star rating.';
        nextTick(() => starButtons.value[0]?.focus());

        return;
    }

    emit('submit', {
        rating: rating.value,
        comment: comment.value.trim(),
        files: newPhotos.value.map(photo => photo.file),
        // Edits always say which current photos stay (all, some or none).
        keepImages: isEdit.value ? [...keptImages.value] : undefined
    });
}
</script>

<template>
    <BaseModal
        :open="show"
        size="md"
        :title="isEdit ? 'Edit your review' : 'Review this product'"
        :eyebrow="orderId ? `Order ${orderId}` : ''"
        close-label="Close review form"
        :busy="saving"
        :dirty="isDirty"
        initial-focus=".rv-star[tabindex='0']"
        @close="emit('close')"
        @after-leave="afterLeave"
    >
        <div class="rv-product">
            <OrderItemThumb
                :src="shownItem?.image || ''"
                :category="shownItem?.category || ''"
            />
            <div class="rv-product-text">
                <p class="rv-product-name">{{ shownItem?.name || 'Product' }}</p>
                <p class="rv-product-meta">
                    <span v-if="shownItem?.variation">{{ shownItem.variation }}</span>
                    <span>{{ shownItem?.seller || 'BuyTheWay Seller' }}</span>
                </p>
            </div>
        </div>

        <form
            :id="formId"
            class="rv-form"
            novalidate
            @submit.prevent="submitForm"
        >
            <fieldset
                class="rv-field"
                :disabled="saving"
            >
                <legend class="rv-label">Your rating <span class="rv-required">Required</span></legend>
                <div
                    class="rv-stars"
                    role="radiogroup"
                    aria-label="Rating"
                    :aria-describedby="validationMessage ? 'rv-rating-error' : undefined"
                    :aria-invalid="validationMessage ? 'true' : undefined"
                >
                    <button
                        v-for="star in 5"
                        :key="star"
                        :ref="el => (starButtons[star - 1] = el)"
                        type="button"
                        role="radio"
                        class="rv-star"
                        :class="{ 'is-on': star <= rating }"
                        :aria-checked="rating === star"
                        :aria-label="`${star} ${star === 1 ? 'star' : 'stars'}, ${RATING_WORDS[star]}`"
                        :tabindex="(rating || 1) === star ? 0 : -1"
                        @click="selectRating(star)"
                        @keydown="handleStarKeydown($event, star)"
                    >
                        <svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M12 2.8l2.8 5.9 6.4.8-4.7 4.4 1.2 6.4L12 17.2l-5.7 3.1 1.2-6.4-4.7-4.4 6.4-.8z" /></svg>
                    </button>
                    <span
                        class="rv-rating-word"
                        aria-hidden="true"
                    >{{ rating ? RATING_WORDS[rating] : 'Tap a star' }}</span>
                </div>
                <p
                    v-if="validationMessage"
                    id="rv-rating-error"
                    class="nx-field-error"
                    role="alert"
                >{{ validationMessage }}</p>
            </fieldset>

            <div class="rv-field">
                <label
                    class="rv-label"
                    for="rv-comment"
                >Your review <span class="rv-optional">Optional</span></label>
                <textarea
                    id="rv-comment"
                    v-model="comment"
                    :maxlength="MAX_COMMENT"
                    :readonly="saving"
                    rows="4"
                    placeholder="How was the quality, fit or value? What should other buyers know?"
                    aria-describedby="rv-comment-count"
                ></textarea>
                <p
                    id="rv-comment-count"
                    class="rv-hint"
                >{{ comment.length }} / {{ MAX_COMMENT }}</p>
            </div>

            <fieldset
                class="rv-field"
                :disabled="saving"
            >
                <legend class="rv-label">Photos <span class="rv-optional">Optional · up to {{ MAX_PHOTOS }}</span></legend>
                <ul class="rv-photos">
                    <li
                        v-for="(url, index) in keptImages"
                        :key="url"
                        class="rv-photo"
                    >
                        <img
                            :src="url"
                            alt="Photo in your review"
                        >
                        <button
                            type="button"
                            class="rv-photo-remove"
                            :aria-label="`Remove photo ${index + 1}`"
                            @click="removeKeptPhoto(index)"
                        >
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </li>
                    <li
                        v-for="(photo, index) in newPhotos"
                        :key="photo.key"
                        class="rv-photo"
                    >
                        <img
                            :src="photo.preview"
                            :alt="`New photo: ${photo.file.name}`"
                        >
                        <button
                            type="button"
                            class="rv-photo-remove"
                            :aria-label="`Remove ${photo.file.name}`"
                            @click="removeNewPhoto(index)"
                        >
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12" /></svg>
                        </button>
                    </li>
                    <li v-if="photoCount < MAX_PHOTOS">
                        <button
                            type="button"
                            class="rv-photo-add"
                            @click="choosePhotos"
                        >
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3Z" /><circle cx="12" cy="13" r="3" /></svg>
                            Add photo
                        </button>
                    </li>
                </ul>
                <input
                    ref="fileInput"
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    tabindex="-1"
                    aria-hidden="true"
                    @change="addPhotos"
                >
                <p
                    v-if="photoMessage"
                    class="nx-field-error"
                    role="alert"
                >{{ photoMessage }}</p>
                <p
                    v-else
                    class="rv-hint"
                >JPEG, PNG or WebP, up to 5 MB each.</p>
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
                {{ saving ? (isEdit ? 'Saving…' : 'Submitting…') : (isEdit ? 'Save Changes' : 'Submit Review') }}
            </button>
        </template>
    </BaseModal>
</template>

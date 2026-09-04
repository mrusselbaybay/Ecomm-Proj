<!-- resources/js/logistics/components/AvatarCropper.vue
     Shown between "pick a file" and "actually upload it" for every avatar
     upload in the app (buyer/seller/admin/logistics each carry their own
     copy of this component, matching how this codebase already duplicates
     small per-module pieces — e.g. the PSGC address-lookup functions —
     rather than sharing a file across separate Vite entry bundles).

     Lets the user pan (drag) and zoom (slider) the selected image inside a
     fixed circular frame, then exports exactly what's visible as a square
     JPEG blob via <canvas>. No cropping library — just pointer events and
     canvas math, so no new dependency to install across three bundles. -->
<template>
    <div v-if="file" class="cropper-overlay" @click.self="$emit('cancel')">
        <div class="cropper-panel">
            <h3 class="cropper-title">Adjust your photo</h3>
            <p class="cropper-hint">
                Drag to reposition, use the slider to zoom.
            </p>

            <div
                ref="viewportEl"
                class="cropper-viewport"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @pointerleave="onPointerUp"
            >
                <img
                    v-if="imageUrl"
                    :src="imageUrl"
                    class="cropper-image"
                    :style="imageStyle"
                    draggable="false"
                    @load="onImageLoad"
                />
            </div>

            <div class="cropper-zoom-row">
                <svg
                    width="14"
                    height="14"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
                <input
                    v-model.number="zoom"
                    type="range"
                    min="1"
                    max="3"
                    step="0.01"
                    class="cropper-zoom-slider"
                    :disabled="!ready"
                    @input="clampOffset"
                />
                <svg
                    width="19"
                    height="19"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
            </div>

            <div class="cropper-actions">
                <button
                    type="button"
                    class="cropper-btn-outline"
                    @click="$emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    class="cropper-btn-primary"
                    :disabled="!ready"
                    @click="saveCrop"
                >
                    Save Photo
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';

const props = defineProps({
    // The raw File the user just picked via <input type="file">. The
    // component shows itself whenever this is non-null.
    file: { type: [File, Object], default: null },
});
const emit = defineEmits(['cancel', 'crop']);

// Square frame the user drags/zooms within (CSS pixels) and the pixel
// size of the exported image — independent of each other on purpose, so
// the exported photo is sharper than the on-screen preview.
const VIEWPORT = 280;
const OUTPUT_SIZE = 480;

const imageUrl = ref('');
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const baseScale = ref(1); // scale at which the image exactly covers VIEWPORT with zoom = 1
const zoom = ref(1);
const offsetX = ref(0);
const offsetY = ref(0);
const ready = ref(false);

let dragging = false;
let dragStartX = 0;
let dragStartY = 0;
let startOffsetX = 0;
let startOffsetY = 0;

const dispWidth = computed(
    () => naturalWidth.value * baseScale.value * zoom.value,
);
const dispHeight = computed(
    () => naturalHeight.value * baseScale.value * zoom.value,
);

const imageStyle = computed(() => ({
    width: `${dispWidth.value}px`,
    height: `${dispHeight.value}px`,
    left: `${VIEWPORT / 2 - dispWidth.value / 2 + offsetX.value}px`,
    top: `${VIEWPORT / 2 - dispHeight.value / 2 + offsetY.value}px`,
}));

function revokeUrl() {
    if (imageUrl.value) {
        URL.revokeObjectURL(imageUrl.value);
    }
}

// A fresh file (including re-picking after Cancel) always resets pan/zoom
// rather than carrying over the previous photo's adjustments.
watch(
    () => props.file,
    (file) => {
        revokeUrl();
        ready.value = false;
        zoom.value = 1;
        offsetX.value = 0;
        offsetY.value = 0;
        imageUrl.value = file ? URL.createObjectURL(file) : '';
    },
    { immediate: true },
);

function onImageLoad(event) {
    const img = event.target;
    naturalWidth.value = img.naturalWidth;
    naturalHeight.value = img.naturalHeight;
    // "Cover" fit: the smaller dimension's scale-to-fill wins, so there's
    // never a gap inside the circular frame at zoom = 1.
    baseScale.value = Math.max(
        VIEWPORT / img.naturalWidth,
        VIEWPORT / img.naturalHeight,
    );
    zoom.value = 1;
    offsetX.value = 0;
    offsetY.value = 0;
    ready.value = true;
}

// Keeps the image from being dragged/zoomed-out past the point where the
// frame would show empty space instead of photo.
function clampOffset() {
    const maxX = Math.max((dispWidth.value - VIEWPORT) / 2, 0);
    const maxY = Math.max((dispHeight.value - VIEWPORT) / 2, 0);
    offsetX.value = Math.min(maxX, Math.max(-maxX, offsetX.value));
    offsetY.value = Math.min(maxY, Math.max(-maxY, offsetY.value));
}

function onPointerDown(event) {
    if (!ready.value) {
        return;
    }

    dragging = true;
    dragStartX = event.clientX;
    dragStartY = event.clientY;
    startOffsetX = offsetX.value;
    startOffsetY = offsetY.value;
    event.target.setPointerCapture?.(event.pointerId);
}

function onPointerMove(event) {
    if (!dragging) {
        return;
    }

    offsetX.value = startOffsetX + (event.clientX - dragStartX);
    offsetY.value = startOffsetY + (event.clientY - dragStartY);
    clampOffset();
}

function onPointerUp() {
    dragging = false;
}

// Re-decodes the source file at full resolution (rather than reusing the
// on-screen <img>, which the browser may have downscaled for display) so
// the export stays sharp regardless of preview size.
function saveCrop() {
    if (!ready.value) {
        return;
    }

    const img = new Image();

    img.onload = () => {
        const scaleFactor = dispWidth.value / naturalWidth.value;
        const sourceSize = VIEWPORT / scaleFactor;
        const sourceX =
            (dispWidth.value / 2 - VIEWPORT / 2 - offsetX.value) / scaleFactor;
        const sourceY =
            (dispHeight.value / 2 - VIEWPORT / 2 - offsetY.value) / scaleFactor;

        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT_SIZE;
        canvas.height = OUTPUT_SIZE;

        const ctx = canvas.getContext('2d');
        ctx.drawImage(
            img,
            sourceX,
            sourceY,
            sourceSize,
            sourceSize,
            0,
            0,
            OUTPUT_SIZE,
            OUTPUT_SIZE,
        );

        canvas.toBlob(
            (blob) => {
                if (blob) {
                    emit('crop', blob);
                }
            },
            'image/jpeg',
            0.92,
        );
    };
    img.src = imageUrl.value;
}

onBeforeUnmount(revokeUrl);
</script>

<style scoped>
.cropper-overlay {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.55);
    padding: 1rem;
}

.cropper-panel {
    width: 100%;
    max-width: 22rem;
    background: #ffffff;
    border-radius: 1.25rem;
    padding: 1.5rem;
    box-shadow: 0 24px 64px -12px rgba(0, 0, 0, 0.35);
    text-align: center;
}

.cropper-title {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 800;
    color: #0f172a;
}

.cropper-hint {
    margin: 0.25rem 0 1.1rem;
    font-size: 0.8rem;
    color: #64748b;
}

.cropper-viewport {
    position: relative;
    width: 280px;
    height: 280px;
    margin: 0 auto;
    border-radius: 50%;
    overflow: hidden;
    background: #f1f5f9;
    box-shadow:
        0 0 0 3px #ffffff,
        0 0 0 4px #e2e8f0;
    cursor: grab;
    touch-action: none;
    user-select: none;
}

.cropper-viewport:active {
    cursor: grabbing;
}

.cropper-image {
    position: absolute;
    max-width: none;
    pointer-events: none;
}

.cropper-zoom-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin: 1.1rem 0.25rem 0;
    color: #94a3b8;
}

.cropper-zoom-slider {
    flex: 1;
    accent-color: #0d9488;
}

.cropper-actions {
    display: flex;
    gap: 0.6rem;
    margin-top: 1.25rem;
}

.cropper-btn-outline,
.cropper-btn-primary {
    flex: 1;
    padding: 0.55rem 0.75rem;
    border-radius: 0.6rem;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    border: none;
}

.cropper-btn-outline {
    border: 1px solid #d1d5db;
    background: #ffffff;
    color: #334155;
}

.cropper-btn-primary {
    background: linear-gradient(90deg, #0d9488, #0f766e);
    color: #ffffff;
}

.cropper-btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

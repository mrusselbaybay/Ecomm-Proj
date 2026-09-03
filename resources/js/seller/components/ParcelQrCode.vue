<!-- resources/js/seller/components/ParcelQrCode.vue -->
<!--
    Renders a QR code as a self-contained inline SVG (no network, no <img>),
    so it prints cleanly and scales without blurring. Encoding is done in the
    browser by the vendored Nayuki qrcodegen library.

    Used on Prepare Orders to show the parcel confirmation code the courier
    scans at each checkpoint (pickup / delivery) — see PrepareOrders.vue.
-->
<template>
    <svg
        v-if="matrix"
        class="parcel-qr"
        :viewBox="`0 0 ${dimension} ${dimension}`"
        :width="size"
        :height="size"
        role="img"
        :aria-label="ariaLabel"
        shape-rendering="crispEdges"
        xmlns="http://www.w3.org/2000/svg"
    >
        <rect width="100%" height="100%" fill="#ffffff" />
        <path :d="path" fill="#0f172a" />
    </svg>
    <p v-else class="parcel-qr-error">
        Couldn't render the confirmation code.
    </p>
</template>

<script setup>
import { computed } from 'vue';
import { QrCode } from '../vendor/qrcodegen.js';

const props = defineProps({
    // Text to encode (the parcel confirmation payload).
    value: { type: String, required: true },
    // Rendered size in CSS pixels (the SVG stays square).
    size: { type: Number, default: 220 },
    // Quiet-zone width in modules. The spec recommends at least 4.
    border: { type: Number, default: 4 },
    ariaLabel: { type: String, default: 'Parcel confirmation QR code' },
});

// The QR module grid, or null if the value can't be encoded (e.g. too long).
const matrix = computed(() => {
    if (!props.value) {
        return null;
    }

    try {
        const qr = QrCode.encodeText(props.value, QrCode.Ecc.MEDIUM);
        const grid = [];

        for (let y = 0; y < qr.size; y++) {
            const row = [];

            for (let x = 0; x < qr.size; x++) {
                row.push(qr.getModule(x, y));
            }

            grid.push(row);
        }

        return grid;
    } catch (err) {
        console.error('QR encode failed:', err);

        return null;
    }
});

const dimension = computed(() =>
    matrix.value ? matrix.value.length + props.border * 2 : 0,
);

// One <path> for every dark module — far lighter than one <rect> each.
const path = computed(() => {
    if (!matrix.value) {
        return '';
    }

    const parts = [];

    matrix.value.forEach((row, y) => {
        row.forEach((dark, x) => {
            if (dark) {
                parts.push(`M${x + props.border} ${y + props.border}h1v1h-1z`);
            }
        });
    });

    return parts.join('');
});
</script>

<style scoped>
.parcel-qr {
    display: block;
    border-radius: 0.5rem;
}
.parcel-qr-error {
    font-size: 0.8rem;
    color: #dc2626;
}
</style>

<!-- resources/js/admin/components/SkeletonRows.vue -->
<!--
    Table-body skeleton placeholder shown while a section's data is
    loading, in place of a single "Loading..." message row. Renders
    `rows` x `columns` shimmering bars shaped like the real cells so the
    table doesn't jump once data arrives — used by every admin table
    (Registrations, Users, Compliance, Complaints, Commission, Reports).
-->
<template>
    <tr v-for="row in rows" :key="row" aria-hidden="true">
        <td v-for="col in columns" :key="col">
            <div
                class="skeleton skeleton-text"
                :style="{ width: widthFor(row, col) }"
            ></div>
        </td>
    </tr>
</template>

<script setup>
defineProps({
    columns: { type: Number, required: true },
    rows: { type: Number, default: 5 },
});

// A handful of bar widths cycled per cell so the placeholder reads as
// varied text rather than a uniform grid of identical blocks.
const WIDTHS = ['85%', '60%', '72%', '50%', '90%', '65%'];

function widthFor(row, col) {
    return WIDTHS[(row + col) % WIDTHS.length];
}
</script>

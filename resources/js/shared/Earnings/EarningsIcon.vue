<script setup>
defineProps({ name: { type: String, required: true } });
const paths = {
    couriers:
        'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M16 3a4 4 0 0 1 0 8 M22 21v-2a4 4 0 0 0-3-3.87 M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    ledger: 'M4 3h16v18l-4-2-4 2-4-2-4 2V3 M8 7h8 M8 11h8 M8 15h4',
    payouts: 'M3 6h18v14H3V6 M3 10h18 M7 15h4 M7 3v3 M17 3v3',
    settings: 'M4 7h16 M4 17h16 M8 4v6 M16 14v6',
    refresh:
        'M20 7v5h-5 M4 17v-5h5 M6 7a7 7 0 0 1 12-1l2 3 M4 15l2 3a7 7 0 0 0 12-1',
    close: 'M6 6l12 12 M18 6 6 18',
    adjustment: 'M12 5v14 M5 12h14',
    remittance: 'M3 5h18v14H3V5 M3 9h18 M7 14h3 M15 14h2',
    statement: 'M5 3h10l4 4v14H5V3 M14 3v5h5 M9 12h6 M9 16h6',
    pay: 'M3 5h18v14H3V5 M3 9h18 M7 14h3 M15 14h2',
    chevron: 'M9 5l7 7-7 7',
    parcel: 'm12 3 9 5-9 5-9-5 9-5 M3 8v9l9 5 9-5V8 M12 13v9 M7.5 5.5l9 5',
};
</script>

<template>
    <svg
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.65"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="paths[name] || paths.ledger" />
    </svg>
</template>

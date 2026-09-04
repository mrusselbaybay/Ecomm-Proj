<!-- resources/js/logistics/components/NavIcon.vue
     The portal's icon set. Replaces the typographic glyphs the sidebar
     used to render (⌂ ◫ ▣ ♙ ◇ ▥ ⚙ and ↪ for log out) — those rendered at
     wildly different weights and sizes across platforms, and ♙ is a chess
     pawn. Stroked 24x24 paths on a shared grid keep every icon optically
     consistent and they inherit currentColor. -->
<template>
    <svg
        class="nav-icon"
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        focusable="false"
    >
        <path v-for="(d, i) in paths" :key="i" :d="d" />
    </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
    strokeWidth: { type: [Number, String], default: 1.7 },
});

const ICONS = {
    dashboard: [
        'M3 10.2 12 3.2l9 7M5.4 9.2V20h13.2V9.2',
        'M9.8 20v-5.4h4.4V20',
    ],
    applications: [
        'M9 3.8h6a1 1 0 0 1 1 1V6H8V4.8a1 1 0 0 1 1-1Z',
        'M16 5.2h1.6a1.6 1.6 0 0 1 1.6 1.6v12.4a1.6 1.6 0 0 1-1.6 1.6H6.4a1.6 1.6 0 0 1-1.6-1.6V6.8a1.6 1.6 0 0 1 1.6-1.6H8',
        'M8.6 11.4h6.8M8.6 15.2h4.4',
    ],
    parcels: [
        'M21 7.9 12 3 3 7.9v8.2L12 21l9-4.9V7.9Z',
        'm3 7.9 9 4.9 9-4.9',
        'M12 12.8V21',
        'm7.5 5.4 9 4.9',
    ],
    couriers: [
        'M3 16.5V7.6a1 1 0 0 1 1-1h9.2v9.9',
        'M13.2 9.8h3.6L21 13.2v3.3h-1.8',
        'M4 16.5h1.6M11.4 16.5h4.2',
        'M7.6 16.5a1.6 1.6 0 1 0 3.2 0 1.6 1.6 0 0 0-3.2 0Z',
        'M16.4 16.5a1.6 1.6 0 1 0 3.2 0 1.6 1.6 0 0 0-3.2 0Z',
    ],
    messages: [
        'M20.4 13.4a1.9 1.9 0 0 1-1.9 1.9H8.1L4.3 19V6.5a1.9 1.9 0 0 1 1.9-1.9h12.3a1.9 1.9 0 0 1 1.9 1.9v6.9Z',
    ],
    reports: ['M4.6 20h14.8', 'M7.6 20v-6.2M12 20V6.8M16.4 20v-9'],
    account: [
        'M12 15.1a3.1 3.1 0 1 0 0-6.2 3.1 3.1 0 0 0 0 6.2Z',
        'M19.2 14.4a1.4 1.4 0 0 0 .3 1.5l.1.1a1.7 1.7 0 1 1-2.4 2.4l-.1-.1a1.4 1.4 0 0 0-2.4 1v.2a1.7 1.7 0 1 1-3.4 0v-.1a1.4 1.4 0 0 0-2.4-1l-.1.1a1.7 1.7 0 1 1-2.4-2.4l.1-.1a1.4 1.4 0 0 0-1-2.4h-.2a1.7 1.7 0 1 1 0-3.4h.1a1.4 1.4 0 0 0 1-2.4l-.1-.1a1.7 1.7 0 1 1 2.4-2.4l.1.1a1.4 1.4 0 0 0 2.4-1v-.2a1.7 1.7 0 1 1 3.4 0v.1a1.4 1.4 0 0 0 2.4 1l.1-.1a1.7 1.7 0 1 1 2.4 2.4l-.1.1a1.4 1.4 0 0 0 1 2.4h.2a1.7 1.7 0 1 1 0 3.4h-.1a1.4 1.4 0 0 0-1.3.9Z',
    ],
    logout: [
        'M15 17.2v1.6a1.6 1.6 0 0 1-1.6 1.6H5.8a1.6 1.6 0 0 1-1.6-1.6V5.2a1.6 1.6 0 0 1 1.6-1.6h7.6A1.6 1.6 0 0 1 15 5.2v1.6',
        'M18.4 15.4 21.6 12l-3.2-3.4',
        'M21.6 12H9.4',
    ],
    menu: ['M4 7h16M4 12h16M4 17h16'],
    close: ['M6 6l12 12M18 6 6 18'],
    search: [
        'M11 18.2a7.2 7.2 0 1 0 0-14.4 7.2 7.2 0 0 0 0 14.4Z',
        'm20.4 20.4-4.3-4.3',
    ],
    refresh: [
        'M20.2 11.2a8.2 8.2 0 0 0-14-4.6L3.6 9',
        'M3.8 12.8a8.2 8.2 0 0 0 14 4.6l2.6-2.4',
        'M3.6 4.4V9h4.6M20.4 19.6V15h-4.6',
    ],
    check: ['m5 12.6 4.4 4.4L19 7.4'],
    clock: [
        'M12 20.4a8.4 8.4 0 1 0 0-16.8 8.4 8.4 0 0 0 0 16.8Z',
        'M12 7.4V12l3 1.9',
    ],
    alert: [
        'M12 8.4v4.4M12 16.4h.01',
        'M12 20.4a8.4 8.4 0 1 0 0-16.8 8.4 8.4 0 0 0 0 16.8Z',
    ],
    scan: [
        'M4 8.4V6a2 2 0 0 1 2-2h2.4M15.6 4H18a2 2 0 0 1 2 2v2.4M20 15.6V18a2 2 0 0 1-2 2h-2.4M8.4 20H6a2 2 0 0 1-2-2v-2.4',
        'M4 12h16',
    ],
    truck: [
        'M3 16.5V7.6a1 1 0 0 1 1-1h9.2v9.9',
        'M13.2 9.8h3.6L21 13.2v3.3h-1.8',
        'M7.6 16.5a1.6 1.6 0 1 0 3.2 0 1.6 1.6 0 0 0-3.2 0ZM16.4 16.5a1.6 1.6 0 1 0 3.2 0 1.6 1.6 0 0 0-3.2 0Z',
    ],
    inbox: [
        'M20 12.6h-4l-1.2 2.4H9.2L8 12.6H4',
        'M6.8 5.4h10.4l2.8 7.2v4.8a1.6 1.6 0 0 1-1.6 1.6H5.6A1.6 1.6 0 0 1 4 17.4v-4.8l2.8-7.2Z',
    ],
    pin: [
        'M19.2 10.6c0 5-7.2 10-7.2 10s-7.2-5-7.2-10a7.2 7.2 0 1 1 14.4 0Z',
        'M12 13a2.4 2.4 0 1 0 0-4.8A2.4 2.4 0 0 0 12 13Z',
    ],
};

const paths = computed(() => ICONS[props.name] || ICONS.dashboard);
</script>

<script setup>
/*
|------------------------------------------------------------------------------
| OrderJourneyMap — shared buyer + seller parcel tracking view
|------------------------------------------------------------------------------
|
| A Leaflet + OpenStreetMap map of the parcel's journey, plus a milestone
| rail. The `journey` prop comes from App\Services\OrderTrackingService.
|
| Two modes, decided by the payload:
|   - LIVE  (journey.live === true): the courier marker sits on the newest
|     real GPS ping (parcel_locations) and a breadcrumb trail is drawn. The
|     parent re-fetches /orders/{id}/tracking on an interval and passes a
|     fresh `journey`; this component animates the marker between positions.
|   - ESTIMATED (fallback): no recent ping — the marker is interpolated from
|     the order's status progress. Labelled "Estimated position".
|
| Milestone rows + times are always real (order_status_history).
|
| Tiles load from tile.openstreetmap.org at runtime (needs network). If the
| map can't init, or there's no mappable origin/destination, only the
| milestone rail renders.
*/
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Leaflet (~42KB gz + its CSS) is loaded on demand — this shared
// component is imported in a few places but the map only actually renders
// on an order-details view with a mappable route.
let L = null;

async function ensureLeaflet() {
    if (L) {
        return L;
    }

    const [mod] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]);

    L = mod.default;

    return L;
}

const props = defineProps({
    journey: {
        type: Object,
        required: true,
    },
});

const mapEl = ref(null);
let map = null;
let layers = {};
let rafId = null;
let ageTimer = null;

const reducedMotion =
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const hasRoute = computed(
    () => !!(props.journey?.mappable && props.journey.origin && props.journey.destination),
);
const live = computed(() => !!props.journey?.live);
const estimated = computed(() => !!props.journey?.estimated);
const cancelled = computed(() => !!props.journey?.cancelled);
const phases = computed(() => props.journey?.phases ?? []);

const ageText = ref('');

function tickAge() {
    const iso = props.journey?.lastPingAt;

    if (!iso) {
        ageText.value = '';

        return;
    }

    const secs = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    if (secs < 60) {
        ageText.value = `${secs}s ago`;
    } else if (secs < 3600) {
        ageText.value = `${Math.round(secs / 60)}m ago`;
    } else {
        ageText.value = `${Math.round(secs / 3600)}h ago`;
    }
}

function ll(p) {
    return [p.lat, p.lng];
}

function courierIcon() {
    const cls = live.value ? 'is-live' : 'is-est';

    return L.divIcon({
        className: 'jm-courier-wrap',
        html: `<span class="jm-courier ${cls}"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
    });
}

function pinIcon(kind) {
    return L.divIcon({
        className: 'jm-pin-wrap',
        html: `<span class="jm-pin jm-pin--${kind}"></span>`,
        iconSize: [16, 16],
        iconAnchor: [8, 8],
    });
}

function fitAll() {
    if (!map) {
        return;
    }

    const pts = [
        ll(props.journey.origin),
        ll(props.journey.destination),
        ...(props.journey.trail || []).map(ll),
    ];

    if (props.journey.parcel) {
        pts.push(ll(props.journey.parcel));
    }

    map.fitBounds(L.latLngBounds(pts).pad(0.25), { animate: false });
}

async function initMap() {
    if (!hasRoute.value || !mapEl.value || map) {
        return;
    }

    try {
        await ensureLeaflet();

        // A concurrent call may have won the race while we awaited.
        if (map || !mapEl.value) {
            return;
        }

        map = L.map(mapEl.value, { scrollWheelZoom: false, zoomControl: true });

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution: '&copy; OpenStreetMap contributors',
        }).addTo(map);

        const o = props.journey.origin;
        const d = props.journey.destination;

        layers.route = L.polyline([ll(o), ll(d)], {
            color: '#64748b',
            weight: 2,
            opacity: 0.7,
            dashArray: '4 8',
        }).addTo(map);

        layers.trail = L.polyline((props.journey.trail || []).map(ll), {
            color: '#0d9488',
            weight: 3,
            opacity: 0.9,
        }).addTo(map);

        L.marker(ll(o), { icon: pinIcon('origin'), title: o.name }).addTo(map);
        L.marker(ll(d), { icon: pinIcon('dest'), title: d.name }).addTo(map);

        layers.courier = L.marker(props.journey.parcel ? ll(props.journey.parcel) : ll(o), {
            icon: courierIcon(),
            zIndexOffset: 1000,
            keyboard: false,
        }).addTo(map);

        fitAll();
    } catch (err) {
        console.error('OrderJourneyMap: Leaflet init failed', err);
        destroyMap();
    }
}

function animateCourierTo(target) {
    if (!layers.courier || !L) {
        return;
    }

    const from = layers.courier.getLatLng();
    const to = L.latLng(target[0], target[1]);

    if (reducedMotion) {
        layers.courier.setLatLng(to);

        return;
    }

    const dur = 1200;
    const t0 = performance.now();

    cancelAnimationFrame(rafId);

    const step = (now) => {
        const k = Math.min(1, (now - t0) / dur);
        const e = k < 0.5 ? 2 * k * k : 1 - ((-2 * k + 2) ** 2) / 2;

        layers.courier.setLatLng([
            from.lat + (to.lat - from.lat) * e,
            from.lng + (to.lng - from.lng) * e,
        ]);

        if (k < 1) {
            rafId = requestAnimationFrame(step);
        }
    };

    rafId = requestAnimationFrame(step);
}

function destroyMap() {
    cancelAnimationFrame(rafId);

    if (map) {
        map.remove();
        map = null;
    }

    layers = {};
}

watch(
    () => props.journey,
    (j) => {
        tickAge();

        if (!hasRoute.value) {
            destroyMap();

            return;
        }

        if (!map) {
            initMap();

            return;
        }

        if (layers.trail) {
            layers.trail.setLatLngs((j.trail || []).map(ll));
        }

        if (layers.courier) {
            layers.courier.setIcon(courierIcon());
        }

        if (j.parcel) {
            animateCourierTo(ll(j.parcel));
        }
    },
    { deep: true },
);

onMounted(() => {
    initMap();
    tickAge();
    ageTimer = setInterval(tickAge, 5000);
    // The card may still be sizing when the map inits.
    setTimeout(() => map && map.invalidateSize(), 200);
});

onBeforeUnmount(() => {
    clearInterval(ageTimer);
    destroyMap();
});

function fmt(iso) {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);

    if (Number.isNaN(d.getTime())) {
        return '';
    }

    return `${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} · ${d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
}
</script>

<template>
    <div class="journey" :class="{ 'journey--cancelled': cancelled }">
        <div class="journey-head">
            <h3 class="journey-title">Parcel tracking</h3>
            <span v-if="cancelled" class="journey-flag journey-flag--stop">Cancelled</span>
            <span v-else-if="journey.delivered" class="journey-flag journey-flag--ok">Delivered</span>
            <span v-else-if="live" class="journey-flag journey-flag--live">
                <i class="journey-live-dot"></i>Live<template v-if="ageText"> · {{ ageText }}</template>
            </span>
            <span v-else-if="estimated" class="journey-flag">Estimated position</span>
        </div>

        <div v-if="hasRoute" class="journey-map-wrap">
            <div ref="mapEl" class="journey-map"></div>

            <div class="journey-endpoints">
                <span><i class="journey-dot journey-dot--origin"></i>{{ journey.origin.name }}</span>
                <span><i class="journey-dot journey-dot--dest"></i>{{ journey.destination.name }}</span>
            </div>
        </div>

        <p v-else class="journey-nomap">
            Map view needs a recognised pick-up and delivery location — showing the milestone timeline only.
        </p>

        <!-- milestone rail (always real data) -->
        <ol class="journey-rail">
            <li
                v-for="p in phases"
                :key="p.key"
                class="journey-step"
                :class="{
                    'is-reached': p.reached && !cancelled,
                    'is-current': p.current,
                }"
            >
                <span class="journey-step-dot"></span>
                <span class="journey-step-label">{{ p.label }}</span>
                <span v-if="p.at" class="journey-step-time">{{ fmt(p.at) }}</span>
                <span v-else class="journey-step-time journey-step-time--pending">Pending</span>
            </li>
        </ol>

        <p v-if="journey.trackingNumber" class="journey-meta">
            Tracking #{{ journey.trackingNumber }}<template v-if="journey.carrier"> · {{ journey.carrier }}</template>
        </p>
        <p class="journey-disclaimer">{{ journey.disclaimer }}</p>
    </div>
</template>

<style scoped>
.journey {
    --jm-accent: #0d9488;
    --jm-ink: #0f172a;
    --jm-muted: #64748b;
    --jm-line: #e2e8f0;

    color: var(--jm-ink);
    font-size: 13px;
}

.journey-head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}

.journey-title {
    margin: 0;
    font-size: 14px;
    font-weight: 700;
}

.journey-flag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 8px;
    border-radius: 999px;
    background: var(--jm-muted);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
}

.journey-flag--ok {
    background: #16a34a;
}

.journey-flag--stop {
    background: #dc2626;
}

.journey-flag--live {
    background: #16a34a;
}

.journey-live-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #fff;
    animation: jm-blink 1.4s ease-in-out infinite;
}

@keyframes jm-blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.25; }
}

.journey-map-wrap {
    position: relative;
}

.journey-map {
    height: 360px;
    width: 100%;
    border: 1px solid var(--jm-line);
    border-radius: 16px;
    overflow: hidden;
    background: #e8eef3;
}

/* Leaflet injects its own DOM into the map container — reach it with :deep */
.journey-map :deep(.leaflet-container) {
    font: inherit;
    background: #e8eef3;
}

.journey-map :deep(.jm-pin) {
    display: block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.4);
}

.journey-map :deep(.jm-pin--origin) {
    background: var(--jm-accent);
}

.journey-map :deep(.jm-pin--dest) {
    background: #dc2626;
}

.journey-map :deep(.jm-courier) {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--jm-accent);
    color: #fff;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.35);
}

.journey-map :deep(.jm-courier.is-live) {
    animation: jm-ping 2s ease-out infinite;
}

@keyframes jm-ping {
    0% { box-shadow: 0 2px 8px rgba(15, 23, 42, 0.35), 0 0 0 0 rgba(22, 163, 74, 0.5); }
    70% { box-shadow: 0 2px 8px rgba(15, 23, 42, 0.35), 0 0 0 16px rgba(22, 163, 74, 0); }
    100% { box-shadow: 0 2px 8px rgba(15, 23, 42, 0.35), 0 0 0 0 rgba(22, 163, 74, 0); }
}

.journey-map :deep(.jm-courier.is-est) {
    background: var(--jm-muted);
}

@media (prefers-reduced-motion: reduce) {
    .journey-map :deep(.jm-courier.is-live),
    .journey-live-dot {
        animation: none;
    }
}

.journey-endpoints {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 8px;
    color: var(--jm-muted);
    font-size: 11.5px;
}

.journey-endpoints span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-width: 0;
}

.journey-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}

.journey-dot--origin {
    background: var(--jm-accent);
}

.journey-dot--dest {
    background: #dc2626;
}

.journey-nomap {
    margin: 0 0 4px;
    padding: 12px;
    border: 1px dashed var(--jm-line);
    border-radius: 12px;
    color: var(--jm-muted);
    font-size: 12px;
}

.journey-rail {
    list-style: none;
    margin: 16px 0 0;
    padding: 0;
    display: grid;
    gap: 2px;
}

.journey-step {
    position: relative;
    display: grid;
    grid-template-columns: 18px 1fr auto;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
}

.journey-step-dot {
    width: 11px;
    height: 11px;
    margin-left: 3px;
    border-radius: 50%;
    border: 2px solid var(--jm-line);
    background: #fff;
    z-index: 1;
}

.journey-step:not(:last-child) .journey-step-dot::after {
    content: '';
    position: absolute;
    left: 8px;
    top: 18px;
    bottom: -6px;
    width: 2px;
    background: var(--jm-line);
}

.journey-step.is-reached .journey-step-dot {
    border-color: var(--jm-accent);
    background: var(--jm-accent);
}

.journey-step.is-reached:not(:last-child) .journey-step-dot::after {
    background: var(--jm-accent);
}

.journey-step.is-current .journey-step-dot {
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--jm-accent) 20%, transparent);
}

.journey-step-label {
    font-weight: 600;
    color: var(--jm-muted);
}

.journey-step.is-reached .journey-step-label {
    color: var(--jm-ink);
}

.journey-step-time {
    font-size: 11.5px;
    color: var(--jm-muted);
}

.journey-step-time--pending {
    font-style: italic;
    opacity: 0.7;
}

.journey--cancelled .journey-rail {
    opacity: 0.55;
}

.journey-meta {
    margin: 12px 0 0;
    font-size: 11.5px;
    color: var(--jm-muted);
}

.journey-disclaimer {
    margin: 6px 0 0;
    font-size: 10.5px;
    line-height: 1.5;
    color: var(--jm-muted);
}
</style>

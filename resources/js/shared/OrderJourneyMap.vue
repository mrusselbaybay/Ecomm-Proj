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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { loadLeaflet, OSM_ATTRIBUTION, OSM_TILES } from './leaflet';

let L = null;

async function ensureLeaflet() {
    L ??= await loadLeaflet();

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

// Route stops (seller -> hubs -> buyer). Older payloads without `stops`
// fall back to just origin + destination.
const stops = computed(() => {
    const j = props.journey;

    if (j?.stops?.length >= 2) {
        return j.stops;
    }

    return j?.origin && j?.destination
        ? [{ ...j.origin, role: 'origin', at: 'x' }, { ...j.destination, role: 'destination', at: j.delivered ? 'x' : null }]
        : [];
});
// The original delivery, kept as history while a return is under way.
const previousStops = computed(() => props.journey?.previousStops ?? []);
const hasTrail = computed(() => (props.journey?.trail?.length ?? 0) > 1);
const reachedIndex = computed(() => props.journey?.reachedIndex ?? (props.journey?.delivered ? stops.value.length - 1 : 0));
const activeLeg = computed(() => props.journey?.activeLeg ?? null);
const statusLabel = computed(() => props.journey?.statusLabel || '');

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
    const cls = cancelled.value ? 'is-cancelled' : live.value ? 'is-live' : 'is-est';

    return L.divIcon({
        className: 'jm-courier-wrap',
        html: `<span class="jm-courier ${cls}"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg></span>`,
        iconSize: [34, 34],
        iconAnchor: [17, 17],
    });
}

function pinIcon(kind, reached) {
    return L.divIcon({
        className: 'jm-pin-wrap',
        html: `<span class="jm-pin jm-pin--${kind}${reached ? '' : ' is-pending'}"></span>`,
        iconSize: [16, 16],
        iconAnchor: [8, 8],
    });
}

const ROLE_KIND = { origin: 'origin', hub: 'hub', destination: 'dest' };

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function stopTooltip(stop) {
    const when = stop.at ? `<br><span class="jm-tip-time">${stop.role === 'origin' ? 'Left' : 'Arrived'} ${escapeHtml(fmt(stop.at))}</span>` : '';

    return `${escapeHtml(stop.name)}${stop.exact === false ? ' <em>(approx.)</em>' : ''}${when}`;
}

// Legs + stop markers live in one layer group, redrawn only when the
// route itself changes (not on every live ping).
let routeKey = '';

function drawRoute() {
    const key = JSON.stringify([
        stops.value.map((s) => [s.lat, s.lng, s.at]),
        previousStops.value.map((s) => [s.lat, s.lng]),
        reachedIndex.value,
        activeLeg.value,
        cancelled.value,
    ]);

    if (key === routeKey && layers.route) {
        return;
    }

    routeKey = key;
    layers.route?.remove();
    layers.route = L.layerGroup().addTo(map);

    // History first, so the current route draws on top of it.
    const past = previousStops.value;

    if (past.length > 1) {
        L.polyline(past.map(ll), { color: '#94a3b8', weight: 3, opacity: 0.55 }).addTo(layers.route);

        past.forEach((stop) => {
            L.circleMarker(ll(stop), { radius: 4, color: '#94a3b8', weight: 2, fillColor: '#fff', fillOpacity: 1 })
                .bindTooltip(`<strong>Original delivery</strong><br>${stopTooltip(stop)}`, { direction: 'top' })
                .addTo(layers.route);
        });
    }

    const list = stops.value;

    for (let i = 0; i < list.length - 1; i++) {
        // Cancelled: the whole route freezes, greyed out.
        const done = !cancelled.value && i < reachedIndex.value;
        const active = !cancelled.value && i === activeLeg.value;

        L.polyline([ll(list[i]), ll(list[i + 1])], {
            color: done || active ? '#0d9488' : '#64748b',
            weight: done ? 4 : 3,
            opacity: done || active ? 0.9 : 0.6,
            dashArray: done ? null : active ? '8 6' : '4 8',
            className: active ? 'jm-leg-active' : '',
        }).addTo(layers.route);
    }

    list.forEach((stop, i) => {
        const reached = i <= reachedIndex.value;

        L.marker(ll(stop), { icon: pinIcon(ROLE_KIND[stop.role] || 'hub', reached), title: stop.name, keyboard: false })
            .bindTooltip(stopTooltip(stop), { direction: 'top', offset: [0, -8] })
            .addTo(layers.route);
    });
}

function fitAll() {
    if (!map) {
        return;
    }

    const pts = [...stops.value.map(ll), ...previousStops.value.map(ll), ...(props.journey.trail || []).map(ll)];

    if (props.journey.parcel) {
        pts.push(ll(props.journey.parcel));
    }

    // maxZoom: identical points (e.g. seller and buyer pinned together)
    // make a zero-size box that would otherwise zoom to infinity.
    map.fitBounds(L.latLngBounds(pts).pad(0.25), { animate: false, maxZoom: 16 });
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

        map = L.map(mapEl.value, { scrollWheelZoom: false, zoomControl: true, maxZoom: 18 });

        // The view MUST exist before any vector layer is added: a path
        // added to a view-less map is clipped against renderer bounds that
        // don't exist yet ("can't access property 'min'").
        fitAll();

        L.tileLayer(OSM_TILES, { maxZoom: 18, attribution: OSM_ATTRIBUTION }).addTo(map);

        routeKey = '';
        drawRoute();

        // Where the courier's GPS actually went over the whole journey.
        layers.trail = L.polyline((props.journey.trail || []).map(ll), {
            color: '#0f766e',
            weight: 3,
            opacity: 0.85,
            lineJoin: 'round',
        }).addTo(map);

        layers.courier = L.marker(props.journey.parcel ? ll(props.journey.parcel) : ll(stops.value[0]), {
            icon: courierIcon(),
            zIndexOffset: 1000,
            keyboard: false,
        }).addTo(map);
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
        // After a failed init Leaflet's own teardown can throw on layers
        // that never rendered; clear the container either way so the next
        // init doesn't hit "Map container is being reused".
        try {
            map.remove();
        } catch {
            if (mapEl.value) {
                delete mapEl.value._leaflet_id;
                mapEl.value.innerHTML = '';
            }
        }

        map = null;
    }

    layers = {};
    routeKey = '';
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

        drawRoute();

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

/* ---------------- fullscreen ---------------- */
// The SAME map element is teleported into a large modal (no second Leaflet
// instance, no re-fetching tiles), then resized to fit.
const fullscreen = ref(false);
const fsCloseBtn = ref(null);
let fsReturnFocus = null;

async function setFullscreen(on) {
    if (fullscreen.value === on) {
        return;
    }

    if (on) {
        fsReturnFocus = document.activeElement;
    }

    fullscreen.value = on;
    document.body.style.overflow = on ? 'hidden' : '';
    document[on ? 'addEventListener' : 'removeEventListener']('keydown', onFsKeydown);

    await nextTick();

    if (map) {
        map.invalidateSize();
        fitAll();
        on ? map.scrollWheelZoom.enable() : map.scrollWheelZoom.disable();
    }

    if (on) {
        fsCloseBtn.value?.focus();
    } else {
        fsReturnFocus?.focus?.();
    }
}

function onFsKeydown(e) {
    if (e.key === 'Escape') {
        setFullscreen(false);
    }
}

onMounted(() => {
    initMap();
    tickAge();
    ageTimer = setInterval(tickAge, 5000);
    // The card may still be sizing when the map inits.
    setTimeout(() => map && map.invalidateSize(), 200);
});

onBeforeUnmount(() => {
    if (fullscreen.value) {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onFsKeydown);
    }

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
            <span v-else-if="journey.returned" class="journey-flag journey-flag--ok">Returned</span>
            <span v-else-if="journey.delivered" class="journey-flag journey-flag--ok">Delivered</span>
            <span v-else-if="live" class="journey-flag journey-flag--live">
                <i class="journey-live-dot"></i>Live<template v-if="ageText"> · {{ ageText }}</template>
            </span>
            <span v-else-if="journey.isReturn" class="journey-flag journey-flag--return">Return to seller</span>
            <span v-else-if="estimated" class="journey-flag">Estimated position</span>
        </div>

        <p v-if="statusLabel && !cancelled" class="journey-status">{{ statusLabel }}</p>

        <div v-if="hasRoute" class="journey-map-wrap">
            <Teleport to="body" :disabled="!fullscreen">
                <div
                    :class="fullscreen ? 'jm-fs-overlay' : 'jm-inline'"
                    @click.self="setFullscreen(false)"
                >
                    <div
                        :class="fullscreen ? 'jm-fs-panel' : 'jm-inline'"
                        :role="fullscreen ? 'dialog' : null"
                        :aria-modal="fullscreen ? 'true' : null"
                        :aria-label="fullscreen ? 'Parcel tracking map' : null"
                    >
                        <header v-if="fullscreen" class="jm-fs-head">
                            <div class="jm-fs-head-text">
                                <h2>Parcel tracking</h2>
                                <p v-if="statusLabel">{{ statusLabel }}</p>
                            </div>
                            <button ref="fsCloseBtn" type="button" class="jm-fs-close" aria-label="Close full screen map" @click="setFullscreen(false)">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M18 6 6 18M6 6l12 12" />
                                </svg>
                            </button>
                        </header>

                        <div class="jm-map-box">
                            <div ref="mapEl" class="journey-map" :class="{ 'journey-map--fs': fullscreen }"></div>

                            <button
                                v-if="!fullscreen"
                                type="button"
                                class="jm-fs-btn"
                                aria-label="View map full screen"
                                title="Full screen"
                                @click="setFullscreen(true)"
                            >
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7" />
                                </svg>
                            </button>
                        </div>

                        <ul v-if="fullscreen" class="jm-legend jm-legend--fs" aria-label="Map legend">
                            <li><i class="jm-key jm-key--done"></i>Travelled</li>
                            <li v-if="activeLeg !== null"><i class="jm-key jm-key--active"></i>Current leg</li>
                            <li><i class="jm-key jm-key--next"></i>Upcoming</li>
                            <li v-if="hasTrail"><i class="jm-key jm-key--gps"></i>GPS trail</li>
                            <li v-if="previousStops.length"><i class="jm-key jm-key--past"></i>Original delivery</li>
                        </ul>
                    </div>
                </div>
            </Teleport>

            <ol class="journey-stops" aria-label="Route">
                <li
                    v-for="(stop, i) in stops"
                    :key="i"
                    class="journey-stop"
                    :class="{ 'is-reached': i <= reachedIndex, 'is-here': i === reachedIndex && i < stops.length - 1 }"
                >
                    <i class="journey-dot" :class="`journey-dot--${ROLE_KIND[stop.role] || 'hub'}`"></i>
                    <span class="journey-stop-name">{{ stop.name }}</span>
                    <span v-if="stop.at && i <= reachedIndex" class="journey-stop-time">{{ fmt(stop.at) }}</span>
                    <span v-if="stop.exact === false" class="journey-stop-approx" title="No map pin — shown at the town centre">approx.</span>
                </li>
            </ol>

            <ul class="jm-legend" aria-label="Map legend">
                <li><i class="jm-key jm-key--done"></i>Travelled</li>
                <li v-if="activeLeg !== null"><i class="jm-key jm-key--active"></i>Current leg</li>
                <li><i class="jm-key jm-key--next"></i>Upcoming</li>
                <li v-if="hasTrail"><i class="jm-key jm-key--gps"></i>GPS trail</li>
                <li v-if="previousStops.length"><i class="jm-key jm-key--past"></i>Original delivery</li>
            </ul>
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
/* Tokens also on the fullscreen overlay: it's teleported out of .journey. */
.journey,
.jm-fs-overlay {
    --jm-accent: #0d9488;
    --jm-ink: #0f172a;
    --jm-muted: #64748b;
    --jm-line: #e2e8f0;
}

.journey {
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

.journey-flag--return {
    background: #d97706;
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

.journey-map :deep(.jm-courier.is-cancelled) {
    background: #94a3b8;
}

.journey--cancelled .journey-map {
    filter: grayscale(0.6);
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

/* ---------- map box + fullscreen ---------- */
.jm-map-box {
    position: relative;
}

.jm-fs-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 800;
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border: 1px solid var(--jm-line);
    border-radius: 10px;
    background: #fff;
    color: var(--jm-ink);
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.jm-fs-btn:hover {
    background: #f0fdfa;
    color: var(--jm-accent);
}

.jm-fs-btn:focus-visible,
.jm-fs-close:focus-visible {
    outline: 2px solid var(--jm-accent);
    outline-offset: 2px;
}

.jm-fs-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, 0.6);
}

.jm-fs-panel {
    display: flex;
    flex-direction: column;
    width: min(1280px, 100%);
    height: min(860px, 100%);
    overflow: hidden;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
}

.jm-fs-panel .jm-map-box {
    display: flex;
    flex: 1;
    min-height: 0;
}

.jm-fs-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-bottom: 1px solid var(--jm-line);
}

.jm-fs-head-text {
    flex: 1;
    min-width: 0;
}

.jm-fs-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: var(--jm-ink);
}

.jm-fs-head p {
    margin: 2px 0 0;
    font-size: 13px;
    font-weight: 600;
    color: var(--jm-accent);
}

.jm-fs-close {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--jm-muted);
    cursor: pointer;
}

.jm-fs-close:hover {
    background: #f1f5f9;
}

.journey-map.journey-map--fs {
    flex: 1;
    height: auto;
    min-height: 0;
    border: 0;
    border-radius: 0;
}

.jm-legend {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    margin: 8px 0 0;
    padding: 0;
    font-size: 11px;
    color: var(--jm-muted);
}

.jm-legend li {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.jm-legend--fs {
    margin: 0;
    padding: 10px 18px;
    border-top: 1px solid var(--jm-line);
    font-size: 12px;
}

.jm-key {
    width: 18px;
    height: 0;
    border-top: 3px solid var(--jm-accent);
}

.jm-key--active {
    border-top-style: dashed;
}

.jm-key--next {
    border-top: 3px dashed #94a3b8;
}

.jm-key--gps {
    border-top-color: #0f766e;
    border-top-width: 2px;
}

.jm-key--past {
    border-top-color: #cbd5e1;
}

.journey-map :deep(.jm-tip-time) {
    color: var(--jm-muted);
    font-size: 11px;
}

.journey-stop-time {
    color: var(--jm-muted);
    font-size: 10.5px;
}

@media (max-width: 640px) {
    .jm-fs-overlay {
        padding: 0;
    }

    .jm-fs-panel {
        width: 100%;
        height: 100%;
        border-radius: 0;
    }
}

.journey-status {
    margin: -4px 0 10px;
    font-size: 13px;
    font-weight: 600;
    color: var(--jm-accent);
}

.journey-stops {
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 6px;
    margin: 8px 0 0;
    padding: 0;
    color: var(--jm-muted);
    font-size: 11.5px;
}

.journey-stop {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-width: 0;
    opacity: 0.6;
}

.journey-stop.is-reached {
    opacity: 1;
}

.journey-stop.is-here .journey-stop-name {
    color: var(--jm-ink);
    font-weight: 700;
}

.journey-stop:not(:last-child)::after {
    content: '→';
    margin-left: 2px;
    color: var(--jm-line);
}

.journey-stop-approx {
    padding: 0 5px;
    border-radius: 999px;
    background: #f1f5f9;
    font-size: 10px;
}

.journey-dot--hub {
    background: #f59e0b;
    border-radius: 2px;
}

.journey-map :deep(.jm-pin--hub) {
    background: #f59e0b;
    border-radius: 4px;
}

.journey-map :deep(.jm-pin.is-pending) {
    background: #fff;
    border-color: #94a3b8;
}

.journey-map :deep(.jm-leg-active) {
    animation: jm-dash 1s linear infinite;
}

@keyframes jm-dash {
    to { stroke-dashoffset: -14; }
}

@media (prefers-reduced-motion: reduce) {
    .journey-map :deep(.jm-leg-active) {
        animation: none;
    }
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

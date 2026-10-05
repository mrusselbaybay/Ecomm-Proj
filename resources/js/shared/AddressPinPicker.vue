<script setup>
/*
|------------------------------------------------------------------------------
| AddressPinPicker — pinpoint an address on the map
|------------------------------------------------------------------------------
|
| v-model is { lat, lng } | null. Used by the buyer's saved addresses, the
| seller's pickup address and the logistics hub address; the pin feeds the
| parcel tracking map (App\Services\OrderTrackingService).
|
| The picker opens already centred on the typed address (/api/geo/locate:
| street → barangay → town → country), so the user only nudges it. It uses
| the "fixed centre pin" pattern — the map moves under the pin — which is
| far easier on touch screens than dragging a tiny marker.
|
| Changing the province / city / barangay after pinning clears the pin
| (the server's HasMapPin does the same), so a pin can never silently
| point at the old area.
*/
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

import { inPhilippines, loadLeaflet, OSM_ATTRIBUTION, OSM_TILES, PH_BOUNDS } from './leaflet';

const props = defineProps({
    modelValue: { type: Object, default: null },
    street: { type: String, default: '' },
    barangay: { type: String, default: '' },
    municipality: { type: String, default: '' },
    province: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    // Who the pin is for, used in the helper copy.
    hint: { type: String, default: 'Riders use this pin to find you.' },
    // 'dark' matches the seller portal's dark surfaces (also applied to
    // the dialog, which is teleported outside the page's theme scope).
    theme: { type: String, default: 'light' },
});

const emit = defineEmits(['update:modelValue']);

const pin = computed(() => {
    const v = props.modelValue;

    return v && Number.isFinite(+v.lat) && Number.isFinite(+v.lng) ? { lat: +v.lat, lng: +v.lng } : null;
});

const areaReady = computed(() => !!(props.municipality && props.province));
const areaKey = computed(() =>
    [props.barangay, props.municipality, props.province].map((s) => (s || '').trim().toLowerCase()).join('|'),
);

// Area edited after pinning → the pin points at the old place. Only a
// change FROM a filled area counts, so async-loaded forms (empty → filled)
// keep their pin; and when the parent swaps area AND pin together (form
// hydrate, Cancel restoring saved values) the new pin is trusted.
const staleNotice = ref(false);

watch([areaKey, () => props.modelValue], ([nextArea, nextPin], [prevArea, prevPin]) => {
    const prevFilled = prevArea && prevArea.replace(/\|/g, '') !== '';

    if (nextPin !== prevPin) {
        staleNotice.value = false;

        return;
    }

    if (prevFilled && nextArea !== prevArea && pin.value) {
        emit('update:modelValue', null);
        staleNotice.value = true;
    }
});

/* ---------------- locate (where to open the map) ---------------- */

const locateCache = new Map();

async function locateAddress() {
    const params = new URLSearchParams({
        street: props.street || '',
        barangay: props.barangay || '',
        municipality: props.municipality || '',
        province: props.province || '',
    });
    const key = params.toString();

    if (!locateCache.has(key)) {
        const request = fetch(`/api/geo/locate?${key}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
            .then((j) => j.data)
            .catch(() => {
                locateCache.delete(key);

                return null;
            });
        locateCache.set(key, request);
    }

    return locateCache.get(key);
}

const PRECISION_TEXT = {
    street: 'Map opened on your street.',
    barangay: 'Map opened on your barangay.',
    municipality: 'Map opened on your city / municipality.',
    approximate: 'Map opened near your area.',
    country: "We couldn't find your area — zoom in to your location.",
};

/* ---------------- modal + map ---------------- */

const open = ref(false);
const mapEl = ref(null);
const dialogEl = ref(null);
const mapLoading = ref(false);
const mapError = ref('');
const precisionText = ref('');
const geoBusy = ref(false);
const geoError = ref('');

let L = null;
let map = null;
let addressCenter = null;

async function openPicker() {
    if (props.disabled || !areaReady.value) {
        return;
    }

    open.value = true;
    staleNotice.value = false;
    mapError.value = '';
    geoError.value = '';
    precisionText.value = '';
    mapLoading.value = true;
    document.addEventListener('keydown', onKeydown);

    try {
        const [leaflet, located] = await Promise.all([loadLeaflet(), pin.value ? null : locateAddress()]);
        L = leaflet;

        if (!open.value) {
            return; // closed while loading
        }

        await nextTick();

        addressCenter = located ? { lat: located.lat, lng: located.lng, zoom: located.zoom } : null;
        precisionText.value = pin.value ? 'Showing your saved pin.' : PRECISION_TEXT[located?.precision] || PRECISION_TEXT.country;

        const start = pin.value
            ? { ...pin.value, zoom: 17 }
            : addressCenter || { lat: 12.8797, lng: 121.774, zoom: 6 };

        map = L.map(mapEl.value, {
            zoomControl: true,
            maxBounds: PH_BOUNDS,
            maxBoundsViscosity: 0.8,
            minZoom: 5,
        }).setView([start.lat, start.lng], start.zoom);

        L.tileLayer(OSM_TILES, { maxZoom: 19, attribution: OSM_ATTRIBUTION }).addTo(map);

        // The modal is still animating in; make Leaflet re-measure.
        setTimeout(() => map?.invalidateSize(), 150);
        dialogEl.value?.querySelector('[data-autofocus]')?.focus();
    } catch (err) {
        console.error('AddressPinPicker: map failed to load', err);
        mapError.value = "The map couldn't load. Check your connection and try again.";
    } finally {
        mapLoading.value = false;
    }
}

function destroyMap() {
    map?.remove();
    map = null;
}

function closePicker() {
    open.value = false;
    document.removeEventListener('keydown', onKeydown);
    destroyMap();
}

function onKeydown(e) {
    if (e.key === 'Escape') {
        closePicker();
    }
}

function confirmPin() {
    if (!map) {
        return;
    }

    mapError.value = '';
    const c = map.getCenter();

    if (!inPhilippines(c.lat, c.lng)) {
        mapError.value = 'The pin must be inside the Philippines.';

        return;
    }

    // A pin from the country-wide view is a guess, not a location.
    if (map.getZoom() < 14) {
        mapError.value = 'Zoom in closer so the pin sits on the exact spot.';

        return;
    }

    emit('update:modelValue', { lat: +c.lat.toFixed(6), lng: +c.lng.toFixed(6) });
    closePicker();
}

function recenterToAddress() {
    if (map && addressCenter) {
        map.setView([addressCenter.lat, addressCenter.lng], addressCenter.zoom);
    } else if (map) {
        locateAddress().then((loc) => {
            if (loc && map) {
                addressCenter = { lat: loc.lat, lng: loc.lng, zoom: loc.zoom };
                map.setView([loc.lat, loc.lng], loc.zoom);
            }
        });
    }
}

function useMyLocation() {
    geoError.value = '';

    if (!('geolocation' in navigator)) {
        geoError.value = "Your browser doesn't support location.";

        return;
    }

    geoBusy.value = true;
    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            geoBusy.value = false;

            if (!inPhilippines(coords.latitude, coords.longitude)) {
                geoError.value = "Your current location is outside the Philippines.";

                return;
            }

            map?.setView([coords.latitude, coords.longitude], 18);
            precisionText.value = coords.accuracy > 100
                ? `Located within ~${Math.round(coords.accuracy)} m — adjust the pin if needed.`
                : 'Moved to your current location.';
        },
        (err) => {
            geoBusy.value = false;
            geoError.value = err.code === err.PERMISSION_DENIED
                ? 'Location access is blocked. Allow it in your browser settings, or move the map manually.'
                : "Couldn't get your location. Move the map manually instead.";
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
    );
}

function removePin() {
    emit('update:modelValue', null);
}

onBeforeUnmount(closePicker);

const coordsText = computed(() => (pin.value ? `${pin.value.lat.toFixed(5)}, ${pin.value.lng.toFixed(5)}` : ''));
const osmLink = computed(() =>
    pin.value ? `https://www.openstreetmap.org/?mlat=${pin.value.lat}&mlon=${pin.value.lng}#map=18/${pin.value.lat}/${pin.value.lng}` : '',
);
const addressLine = computed(() =>
    [props.street, props.barangay, props.municipality, props.province].filter(Boolean).join(', '),
);
</script>

<template>
    <div class="pp" :class="{ 'pp--set': pin, 'pp--disabled': disabled || !areaReady, 'pp--dark': theme === 'dark' }">
        <span class="pp-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" />
            </svg>
        </span>

        <div class="pp-body">
            <p class="pp-title">
                Map pin
                <span v-if="pin" class="pp-chip pp-chip--ok">Pinned</span>
                <span v-else class="pp-chip">Not pinned</span>
            </p>
            <p v-if="staleNotice" class="pp-text pp-text--warn" role="status">
                Your address changed, so the old pin was removed. Please pin the new location.
            </p>
            <p v-else-if="!areaReady" class="pp-text">Select your city / municipality first.</p>
            <p v-else-if="pin" class="pp-text">
                <a :href="osmLink" target="_blank" rel="noopener" class="pp-coords">{{ coordsText }}</a>
            </p>
            <p v-else class="pp-text">{{ hint }}</p>
        </div>

        <div class="pp-actions">
            <button
                type="button"
                class="pp-btn pp-btn--primary"
                :disabled="disabled || !areaReady"
                @click="openPicker"
            >
                {{ pin ? 'Adjust' : 'Set pin' }}
            </button>
            <button v-if="pin && !disabled" type="button" class="pp-btn pp-btn--ghost" aria-label="Remove map pin" @click="removePin">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <Teleport to="body">
            <div v-if="open" class="pp-overlay" :class="{ 'pp--dark': theme === 'dark' }" @click.self="closePicker">
                <div ref="dialogEl" class="pp-dialog" role="dialog" aria-modal="true" aria-labelledby="pp-dialog-title">
                    <header class="pp-head">
                        <div class="pp-head-text">
                            <h2 id="pp-dialog-title">Pin your exact location</h2>
                            <p>{{ addressLine }}</p>
                        </div>
                        <button type="button" class="pp-close" aria-label="Close" @click="closePicker">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" />
                            </svg>
                        </button>
                    </header>

                    <div class="pp-map-wrap">
                        <div ref="mapEl" class="pp-map"></div>

                        <!-- fixed centre pin: the map moves underneath it -->
                        <div v-if="!mapLoading && !mapError" class="pp-center-pin" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="40" height="40">
                                <path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Z" fill="#0d9488" stroke="#fff" stroke-width="1.5" />
                                <circle cx="12" cy="9" r="2.6" fill="#fff" />
                            </svg>
                            <span class="pp-center-shadow"></span>
                        </div>

                        <div v-if="mapLoading" class="pp-map-state">
                            <span class="pp-spinner" aria-hidden="true"></span> Loading map…
                        </div>

                        <div class="pp-map-tools">
                            <button type="button" class="pp-tool" :disabled="geoBusy || mapLoading" data-autofocus @click="useMyLocation">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="3" /><path d="M12 2v3M12 19v3M2 12h3M19 12h3" /><circle cx="12" cy="12" r="7" />
                                </svg>
                                {{ geoBusy ? 'Locating…' : 'Use my location' }}
                            </button>
                            <button type="button" class="pp-tool" :disabled="mapLoading" @click="recenterToAddress">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m3 11 19-9-9 19-2-8-8-2Z" />
                                </svg>
                                My address
                            </button>
                        </div>
                    </div>

                    <div class="pp-foot">
                        <p v-if="mapError" class="pp-msg pp-msg--err" role="alert">{{ mapError }}</p>
                        <p v-else-if="geoError" class="pp-msg pp-msg--err" role="alert">{{ geoError }}</p>
                        <p v-else class="pp-msg">{{ precisionText || 'Move the map so the pin sits on your door.' }}</p>

                        <div class="pp-foot-actions">
                            <button type="button" class="pp-btn pp-btn--ghost" @click="closePicker">Cancel</button>
                            <button type="button" class="pp-btn pp-btn--primary" :disabled="mapLoading || !!(mapError && !map)" @click="confirmPin">
                                Confirm pin
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.pp {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    background: #f8fafc;
}

.pp--set {
    border-style: solid;
    border-color: #99f6e4;
    background: #f0fdfa;
}

.pp-icon {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    background: #fff;
    color: #94a3b8;
    border: 1px solid #e2e8f0;
}

.pp--set .pp-icon {
    color: #0d9488;
    border-color: #99f6e4;
}

.pp-body {
    flex: 1;
    min-width: 0;
}

.pp-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: #0f172a;
}

.pp-chip {
    padding: 2px 8px;
    border-radius: 999px;
    background: #fef3c7;
    color: #92400e;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.pp-chip--ok {
    background: #ccfbf1;
    color: #0f766e;
}

.pp-text {
    margin: 2px 0 0;
    font-size: 12px;
    color: #64748b;
}

.pp-text--warn {
    color: #b45309;
}

.pp-coords {
    color: #0f766e;
    font-variant-numeric: tabular-nums;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.pp-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

.pp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
}

.pp-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pp-btn--primary {
    background: #0d9488;
    color: #fff;
    border: 1px solid #0d9488;
}

.pp-btn--primary:hover:not(:disabled) {
    background: #0f766e;
}

.pp-btn--ghost {
    background: #fff;
    color: #475569;
    border: 1px solid #e2e8f0;
    padding: 0 12px;
}

.pp-btn--ghost:hover:not(:disabled) {
    background: #f1f5f9;
}

/* ---------- dialog ---------- */
.pp-overlay {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: rgba(15, 23, 42, 0.55);
}

.pp-dialog {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 640px;
    max-height: calc(100dvh - 32px);
    overflow: hidden;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.3);
}

.pp-head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 18px 12px;
}

.pp-head-text {
    flex: 1;
    min-width: 0;
}

.pp-head h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
}

.pp-head p {
    margin: 2px 0 0;
    font-size: 12px;
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pp-close {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 0;
    background: transparent;
    color: #64748b;
    cursor: pointer;
}

.pp-close:hover {
    background: #f1f5f9;
}

.pp-map-wrap {
    position: relative;
    flex: 1;
    min-height: 0;
}

.pp-map {
    width: 100%;
    height: min(440px, 58dvh);
    background: #e8eef3;
}

.pp-center-pin {
    position: absolute;
    left: 50%;
    top: 50%;
    z-index: 500;
    transform: translate(-50%, -100%);
    pointer-events: none;
    filter: drop-shadow(0 3px 4px rgba(15, 23, 42, 0.35));
}

.pp-center-shadow {
    position: absolute;
    left: 50%;
    bottom: -3px;
    width: 10px;
    height: 4px;
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.35);
    transform: translateX(-50%);
}

.pp-map-state {
    position: absolute;
    inset: 0;
    z-index: 600;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #e8eef3;
    color: #475569;
    font-size: 13px;
    font-weight: 600;
}

.pp-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid #94a3b8;
    border-top-color: transparent;
    border-radius: 50%;
    animation: pp-spin 0.8s linear infinite;
}

@keyframes pp-spin {
    to { transform: rotate(360deg); }
}

.pp-map-tools {
    position: absolute;
    right: 10px;
    bottom: 22px;
    z-index: 550;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.pp-tool {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #0f172a;
    font-size: 12px;
    font-weight: 700;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.15);
    cursor: pointer;
}

.pp-tool:disabled {
    opacity: 0.6;
    cursor: wait;
}

.pp-foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 18px 16px;
    border-top: 1px solid #f1f5f9;
}

.pp-msg {
    flex: 1 1 220px;
    margin: 0;
    font-size: 12px;
    color: #64748b;
}

.pp-msg--err {
    color: #dc2626;
}

.pp-foot-actions {
    display: flex;
    gap: 8px;
    margin-left: auto;
}

@media (max-width: 520px) {
    .pp {
        flex-wrap: wrap;
    }

    .pp-actions {
        width: 100%;
    }

    .pp-actions .pp-btn--primary {
        flex: 1;
    }

    .pp-overlay {
        padding: 0;
        align-items: stretch;
    }

    .pp-dialog {
        max-width: none;
        max-height: none;
        border-radius: 0;
    }

    .pp-map {
        flex: 1;
        height: auto;
        min-height: 300px;
    }

    .pp-map-wrap {
        display: flex;
    }

    .pp-foot-actions {
        width: 100%;
    }

    .pp-foot-actions .pp-btn {
        flex: 1;
    }
}

/* ---------- dark theme (seller portal) ---------- */
.pp--dark.pp {
    background: #1d231e;
    border-color: rgba(255, 255, 255, 0.12);
}

.pp--dark.pp--set {
    background: rgba(20, 184, 166, 0.08);
    border-color: rgba(20, 184, 166, 0.35);
}

.pp--dark .pp-icon {
    background: #161b17;
    border-color: rgba(255, 255, 255, 0.08);
    color: #6d766e;
}

.pp--dark.pp--set .pp-icon {
    color: #5eead4;
    border-color: rgba(20, 184, 166, 0.35);
}

.pp--dark .pp-title,
.pp--dark .pp-head h2 {
    color: #f2f4f1;
}

.pp--dark .pp-text,
.pp--dark .pp-head p,
.pp--dark .pp-msg {
    color: #97a099;
}

.pp--dark .pp-text--warn {
    color: #fbbf24;
}

.pp--dark .pp-msg--err {
    color: #f7a49f;
}

.pp--dark .pp-coords {
    color: #5eead4;
}

.pp--dark .pp-chip {
    background: rgba(251, 191, 36, 0.15);
    color: #fbbf24;
}

.pp--dark .pp-chip--ok {
    background: rgba(20, 184, 166, 0.18);
    color: #5eead4;
}

.pp--dark .pp-btn--primary {
    background: #0d9488;
    border-color: #0d9488;
}

.pp--dark .pp-btn--primary:hover:not(:disabled) {
    background: #14b8a6;
    border-color: #14b8a6;
}

.pp--dark .pp-btn--ghost {
    background: transparent;
    color: #c6cbc5;
    border-color: rgba(255, 255, 255, 0.12);
}

.pp--dark .pp-btn--ghost:hover:not(:disabled),
.pp--dark .pp-close:hover {
    background: rgba(255, 255, 255, 0.06);
}

.pp--dark.pp-overlay {
    background: rgba(0, 0, 0, 0.7);
}

.pp--dark .pp-dialog {
    background: #161b17;
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.6);
}

.pp--dark .pp-close {
    color: #97a099;
}

.pp--dark .pp-foot {
    border-top-color: rgba(255, 255, 255, 0.06);
}

.pp--dark .pp-map,
.pp--dark .pp-map-state {
    background: #1d231e;
    color: #c6cbc5;
}

.pp--dark .pp-tool {
    background: #161b17;
    color: #f2f4f1;
    border-color: rgba(255, 255, 255, 0.12);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.45);
}

.pp--dark .pp-tool svg {
    color: #5eead4;
}

@media (prefers-reduced-motion: reduce) {
    .pp-spinner {
        animation: none;
    }
}
</style>

// Leaflet (~42KB gz + its CSS) loaded on demand and shared by every map
// (OrderJourneyMap, AddressPinPicker) so it's only ever fetched once.
let promise = null;

export function loadLeaflet() {
    promise ??= Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')])
        .then(([mod]) => mod.default)
        .catch((err) => {
            promise = null; // allow a retry after e.g. a chunk-load failure

            throw err;
        });

    return promise;
}

export const OSM_TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const OSM_ATTRIBUTION = '&copy; OpenStreetMap contributors';

// Generous box around the Philippines — mirrors App\Support\MapPin.
export const PH_BOUNDS = [[4.2, 116.0], [21.5, 127.0]];

export function inPhilippines(lat, lng) {
    return lat >= 4.2 && lat <= 21.5 && lng >= 116.0 && lng <= 127.0;
}

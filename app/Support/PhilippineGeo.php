<?php

namespace App\Support;

/**
 * Approximate centroid coordinates for Philippine provinces plus a handful
 * of major cities, used only to place pins on the stylised tracking map
 * (resources/js/shared/OrderJourneyMap.vue). These are rough centroids —
 * good enough to show "which part of the country" a parcel is in, not a
 * survey-grade location. There is no real geocoding or GPS in this project;
 * see OrderTrackingService for how the "estimated" parcel position is
 * derived from order status, not from any live feed.
 *
 * Keys are lower-cased. Lookups try the most specific name first
 * (municipality/city) then fall back to the province.
 */
class PhilippineGeo
{
    /** @var array<string, array{0: float, 1: float}> [lat, lng] */
    private const COORDS = [
        // --- NCR + nearby cities ---
        'manila' => [14.599, 120.984],
        'quezon city' => [14.676, 121.043],
        'makati' => [14.554, 121.024],
        'pasig' => [14.576, 121.085],
        'taguig' => [14.517, 121.050],
        'caloocan' => [14.758, 120.967],
        'parañaque' => [14.479, 121.019],
        'paranaque' => [14.479, 121.019],
        'las piñas' => [14.451, 120.983],
        'las pinas' => [14.451, 120.983],
        'muntinlupa' => [14.408, 121.041],
        'mandaluyong' => [14.579, 121.036],
        'san juan' => [14.600, 121.030],
        'marikina' => [14.650, 121.102],
        'valenzuela' => [14.696, 120.983],
        'metro manila' => [14.599, 120.984],
        'national capital region' => [14.599, 120.984],

        // --- Luzon provinces ---
        'ilocos norte' => [18.166, 120.762],
        'ilocos sur' => [17.223, 120.579],
        'la union' => [16.615, 120.319],
        'pangasinan' => [15.892, 120.486],
        'batanes' => [20.448, 121.970],
        'cagayan' => [18.041, 121.717],
        'isabela' => [16.973, 121.813],
        'nueva vizcaya' => [16.331, 121.171],
        'quirino' => [16.271, 121.585],
        'abra' => [17.594, 120.797],
        'apayao' => [18.010, 121.170],
        'benguet' => [16.559, 120.749],
        'baguio' => [16.402, 120.596],
        'ifugao' => [16.833, 121.170],
        'kalinga' => [17.475, 121.360],
        'mountain province' => [17.083, 121.144],
        'aurora' => [15.750, 121.560],
        'bataan' => [14.671, 120.480],
        'bulacan' => [14.793, 120.880],
        'nueva ecija' => [15.578, 121.083],
        'pampanga' => [15.079, 120.620],
        'tarlac' => [15.475, 120.588],
        'zambales' => [15.170, 120.070],
        'batangas' => [13.756, 121.058],
        'cavite' => [14.279, 120.868],
        'laguna' => [14.170, 121.330],
        'quezon' => [13.933, 122.030],
        'rizal' => [14.603, 121.308],
        'marinduque' => [13.400, 121.980],
        'occidental mindoro' => [12.905, 120.870],
        'oriental mindoro' => [13.060, 121.170],
        'palawan' => [9.840, 118.740],
        'puerto princesa' => [9.740, 118.735],
        'romblon' => [12.578, 122.270],
        'albay' => [13.174, 123.523],
        'legazpi' => [13.139, 123.744],
        'camarines norte' => [14.139, 122.788],
        'camarines sur' => [13.525, 123.348],
        'naga' => [13.619, 123.195],
        'catanduanes' => [13.709, 124.242],
        'masbate' => [12.164, 123.500],
        'sorsogon' => [12.870, 124.010],

        // --- Visayas provinces ---
        'aklan' => [11.815, 122.093],
        'antique' => [11.170, 122.070],
        'capiz' => [11.388, 122.640],
        'guimaras' => [10.596, 122.630],
        'iloilo' => [10.720, 122.560],
        'iloilo city' => [10.720, 122.562],
        'negros occidental' => [10.290, 123.000],
        'bacolod' => [10.667, 122.950],
        'negros oriental' => [9.620, 122.990],
        'dumaguete' => [9.307, 123.308],
        'bohol' => [9.850, 124.140],
        'cebu' => [10.320, 123.800],
        'cebu city' => [10.317, 123.891],
        'mandaue' => [10.323, 123.922],
        'lapu-lapu' => [10.310, 123.949],
        'siquijor' => [9.213, 123.515],
        'biliran' => [11.583, 124.464],
        'eastern samar' => [11.500, 125.470],
        'leyte' => [10.900, 124.870],
        'tacloban' => [11.244, 125.004],
        'northern samar' => [12.400, 124.700],
        'samar' => [11.780, 124.970],
        'southern leyte' => [10.330, 125.170],

        // --- Mindanao provinces ---
        'zamboanga del norte' => [8.290, 123.220],
        'zamboanga del sur' => [7.840, 123.300],
        'zamboanga sibugay' => [7.700, 122.680],
        'zamboanga city' => [6.921, 122.079],
        'bukidnon' => [7.900, 125.080],
        'camiguin' => [9.173, 124.730],
        'misamis occidental' => [8.330, 123.700],
        'misamis oriental' => [8.630, 124.800],
        'cagayan de oro' => [8.480, 124.647],
        'davao de oro' => [7.470, 126.170],
        'davao del norte' => [7.560, 125.660],
        'davao del sur' => [6.770, 125.330],
        'davao city' => [7.190, 125.455],
        'davao occidental' => [6.100, 125.620],
        'davao oriental' => [7.150, 126.380],
        'cotabato' => [7.200, 124.850],
        'north cotabato' => [7.200, 124.850],
        'sarangani' => [5.960, 125.200],
        'south cotabato' => [6.280, 124.900],
        'general santos' => [6.113, 125.171],
        'sultan kudarat' => [6.510, 124.420],
        'agusan del norte' => [9.000, 125.520],
        'agusan del sur' => [8.170, 125.900],
        'butuan' => [8.949, 125.543],
        'dinagat islands' => [10.130, 125.610],
        'surigao del norte' => [9.780, 125.490],
        'surigao del sur' => [8.550, 126.150],
        'basilan' => [6.430, 121.980],
        'lanao del norte' => [8.050, 124.000],
        'lanao del sur' => [7.830, 124.300],
        'maguindanao' => [6.950, 124.420],
        'maguindanao del norte' => [7.100, 124.280],
        'maguindanao del sur' => [6.800, 124.500],
        'sulu' => [6.050, 121.000],
        'tawi-tawi' => [5.130, 120.050],
    ];

    /**
     * Resolve the first name that matches (case-insensitive, "City of X"
     * and "X City" both tried). Returns ['lat', 'lng', 'label'] or null.
     */
    public static function locate(string ...$names): ?array
    {
        foreach ($names as $name) {
            if (! $name) {
                continue;
            }

            foreach (self::candidates($name) as $key) {
                if (isset(self::COORDS[$key])) {
                    [$lat, $lng] = self::COORDS[$key];

                    return ['lat' => $lat, 'lng' => $lng, 'label' => trim($name)];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function candidates(string $name): array
    {
        $n = mb_strtolower(trim($name));

        $variants = [
            $n,
            preg_replace('/\s+city$/', '', $n),
            preg_replace('/^city of\s+/', '', $n),
            str_replace(['province of ', ' province'], '', $n),
        ];

        return array_values(array_unique(array_filter($variants)));
    }
}

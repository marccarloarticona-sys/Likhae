<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class TravelDistanceEstimator
{
    /** @return array{distance_meters:int,travel_seconds:?int,method:string} */
    public function between(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): array
    {
        $key = implode(':', array_map(static fn (float $value): string => number_format($value, 5, '.', ''), [
            $latitudeA, $longitudeA, $latitudeB, $longitudeB,
        ]));
        $cached = Cache::get('seller-pickup-route:'.sha1($key));
        if (is_array($cached)) {
            return $cached;
        }

        $apiKey = trim((string) (config('geoapify.api_key') ?: config('services.geoapify.key', '')));
        if ($apiKey !== '') {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout(2)
                    ->timeout(4)
                    ->get('https://api.geoapify.com/v1/routing', [
                        'waypoints' => $latitudeA.','.$longitudeA.'|'.$latitudeB.','.$longitudeB,
                        'mode' => 'drive',
                        'format' => 'geojson',
                        'apiKey' => $apiKey,
                    ]);
                $properties = $response->successful() ? data_get($response->json(), 'features.0.properties') : null;
                if (is_array($properties) && is_numeric($properties['distance'] ?? null) && is_numeric($properties['time'] ?? null)) {
                    $estimate = [
                        'distance_meters' => max(0, (int) round((float) $properties['distance'])),
                        'travel_seconds' => max(0, (int) round((float) $properties['time'])),
                        'method' => 'ROAD_ROUTE',
                    ];
                    Cache::put('seller-pickup-route:'.sha1($key), $estimate, now()->addHours(6));

                    return $estimate;
                }
            } catch (\Throwable) {
                // A network or provider failure uses an explicitly labeled geographic estimate.
            }
        }

        $earthRadiusMeters = 6371000.0;
        $latDelta = deg2rad($latitudeB - $latitudeA);
        $lonDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($lonDelta / 2) ** 2;
        $estimate = [
            'distance_meters' => (int) round(2 * $earthRadiusMeters * asin(min(1, sqrt($a)))),
            'travel_seconds' => null,
            'method' => 'STRAIGHT_LINE_ESTIMATE',
        ];
        Cache::put('seller-pickup-route:'.sha1($key), $estimate, now()->addHours(6));

        return $estimate;
    }
}

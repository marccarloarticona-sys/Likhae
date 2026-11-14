<?php

namespace App\Services\Maps;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ApproximateAddressCoordinates
{
    /**
     * Resolve a saved address to an area-level coordinate when its map pin is
     * missing. Approximate coordinates are cached by administrative address,
     * then saved only while the address still has an incomplete coordinate pair.
     *
     * @return array{latitude: float, longitude: float, coordinate_precision: string}|null
     */
    public function resolve(Model $address): ?array
    {
        $coordinates = $this->coordinatePair($address->latitude ?? null, $address->longitude ?? null);
        if ($coordinates) {
            $precision = ($address->coordinate_precision ?? null) === 'APPROXIMATE'
                ? 'APPROXIMATE'
                : 'EXACT';
            $table = $address->getTable();
            if ($precision === 'EXACT') {
                $marker = Cache::get($this->approximateMarkerKey($address));
                if (is_array($marker)
                    && ($marker['signature'] ?? null) === $this->addressSignature($address)
                    && abs((float) ($marker['latitude'] ?? 999) - $coordinates['latitude']) < 0.0000001
                    && abs((float) ($marker['longitude'] ?? 999) - $coordinates['longitude']) < 0.0000001) {
                    $precision = 'APPROXIMATE';
                }
            }
            if ($precision === 'APPROXIMATE') {
                $address->setAttribute('coordinate_precision', 'APPROXIMATE');
                if ($address->exists
                    && Schema::hasTable($table)
                    && Schema::hasColumn($table, 'coordinate_precision')
                    && blank($address->getOriginal('coordinate_precision'))) {
                    $address->newQuery()
                        ->whereKey($address->getKey())
                        ->whereNull('coordinate_precision')
                        ->where('latitude', $coordinates['latitude'])
                        ->where('longitude', $coordinates['longitude'])
                        ->update(['coordinate_precision' => 'APPROXIMATE']);
                }
            }

            return $coordinates + [
                'coordinate_precision' => $precision,
            ];
        }

        $queries = $this->areaQueries($address);
        if ($queries === []) {
            return null;
        }

        $cacheKey = 'approx-address-coordinates:'.hash('sha256', mb_strtolower(implode('|', $queries)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('coordinates', $cached)) {
            $coordinates = $cached['coordinates'];
        } else {
            $coordinates = $this->geocode($queries);
            Cache::put(
                $cacheKey,
                ['coordinates' => $coordinates],
                $coordinates ? now()->addDays(30) : now()->addMinutes(10),
            );
        }

        if (! is_array($coordinates)) {
            return null;
        }

        $attributes = [
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
        ];
        $table = $address->getTable();
        if (Schema::hasTable($table)
            && Schema::hasColumn($table, 'coordinate_precision')) {
            $attributes['coordinate_precision'] = 'APPROXIMATE';
        }

        if ($address->exists
            && Schema::hasTable($table)
            && Schema::hasColumn($table, 'latitude')
            && Schema::hasColumn($table, 'longitude')) {
            $updated = $address->newQuery()
                ->whereKey($address->getKey())
                ->where(fn ($query) => $query
                    ->whereNull('latitude')
                    ->orWhereNull('longitude'))
                ->update($attributes);

            if ($updated === 0) {
                $address->refresh();
                $coordinates = $this->coordinatePair($address->latitude ?? null, $address->longitude ?? null);
                if (! $coordinates) {
                    return null;
                }

                return $coordinates + [
                    'coordinate_precision' => ($address->coordinate_precision ?? null) === 'APPROXIMATE'
                        ? 'APPROXIMATE'
                        : 'EXACT',
                ];
            }

            if (! Schema::hasColumn($table, 'coordinate_precision')) {
                Cache::forever($this->approximateMarkerKey($address), [
                    'signature' => $this->addressSignature($address),
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                ]);
            }
        }

        $address->forceFill($attributes + ['coordinate_precision' => 'APPROXIMATE']);

        return $coordinates + ['coordinate_precision' => 'APPROXIMATE'];
    }

    /** @return list<string> */
    private function areaQueries(Model $address): array
    {
        $province = trim((string) ($address->province_name ?: $address->region_name));
        $municipality = trim((string) ($address->municipality_name ?? ''));
        if ($province === '' || $municipality === '') {
            return [];
        }

        $barangay = trim((string) ($address->barangay_name ?? ''));
        $queries = [];
        $provinceNames = [$province];
        if (trim((string) ($address->province_code ?? '')) === '1300000000'
            || str_contains(mb_strtolower($province), 'national capital region')
            || mb_strtolower($province) === 'ncr') {
            $provinceNames[] = 'Metro Manila';
        }

        foreach (array_unique($provinceNames) as $provinceName) {
            if ($barangay !== '') {
                $queries[] = implode(', ', [$barangay, $municipality, $provinceName, 'Philippines']);
            }
            $queries[] = implode(', ', [$municipality, $provinceName, 'Philippines']);
        }

        return array_values(array_unique($queries));
    }

    private function approximateMarkerKey(Model $address): string
    {
        return 'approx-address-coordinate-marker:'.$address->getTable().':'.$address->getKey();
    }

    private function addressSignature(Model $address): string
    {
        return hash('sha256', implode('|', [
            trim((string) ($address->barangay_code ?? '')),
            trim((string) ($address->municipality_code ?? '')),
            trim((string) ($address->province_code ?? '')),
        ]));
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function geocode(array $queries): ?array
    {
        $apiKey = trim((string) config('geoapify.api_key', ''));
        if ($apiKey === '') {
            $apiKey = trim((string) config('services.geoapify.key', ''));
        }
        if ($apiKey === '') {
            return null;
        }

        try {
            foreach ($queries as $query) {
                $response = Http::acceptJson()
                    ->timeout(3)
                    ->get('https://api.geoapify.com/v1/geocode/search', [
                        'text' => $query,
                        'filter' => 'countrycode:ph',
                        'limit' => 1,
                        'lang' => 'en',
                        'format' => 'geojson',
                        'apiKey' => $apiKey,
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $feature = $response->json('features.0');
                $pair = data_get($feature, 'geometry.coordinates');
                $longitude = is_array($pair) ? ($pair[0] ?? null) : data_get($feature, 'properties.lon');
                $latitude = is_array($pair) ? ($pair[1] ?? null) : data_get($feature, 'properties.lat');
                $coordinates = $this->coordinatePair($latitude, $longitude);
                if ($coordinates) {
                    return $coordinates;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function coordinatePair(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['latitude' => $latitude, 'longitude' => $longitude];
    }
}

<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OpenWeatherMapService
{
    private const CURRENT_URL = 'https://api.openweathermap.org/data/2.5/weather';

    private const FORECAST_URL = 'https://api.openweathermap.org/data/2.5/forecast';

    public function currentAt(float $latitude, float $longitude): ?array
    {
        $apiKey = trim((string) config('services.openweathermap.key'));

        if ($apiKey === '') {
            return null;
        }

        $roundedLocation = number_format(round($latitude, 2), 2, '.', '')
            .'|'.number_format(round($longitude, 2), 2, '.', '');
        $cacheKey = 'weather.openweathermap.'.hash('sha256', $roundedLocation);

        return Cache::store('file')->remember($cacheKey, now()->addMinutes(10), function () use ($latitude, $longitude, $apiKey): ?array {
            try {
                $responses = Http::pool(fn (Pool $pool) => [
                    'current' => $pool->as('current')->timeout(6)->get(self::CURRENT_URL, [
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'appid' => $apiKey,
                        'units' => 'metric',
                    ]),
                    'forecast' => $pool->as('forecast')->timeout(6)->get(self::FORECAST_URL, [
                        'lat' => $latitude,
                        'lon' => $longitude,
                        'appid' => $apiKey,
                        'units' => 'metric',
                        'cnt' => 4,
                    ]),
                ]);
            } catch (ConnectionException) {
                return null;
            }

            $currentResponse = $responses['current'] ?? null;

            if (! $currentResponse || ! $currentResponse->successful()) {
                return null;
            }

            $current = $currentResponse->json();
            $currentConditions = data_get($current, 'weather.0');
            $temperature = data_get($current, 'main.temp');

            if (! is_array($currentConditions) || ! is_numeric($temperature)) {
                return null;
            }

            $forecastResponse = $responses['forecast'] ?? null;
            $forecast = $forecastResponse?->successful() ? $forecastResponse->json() : [];
            $timezoneOffset = (int) data_get($forecast, 'city.timezone', data_get($current, 'timezone', 0));
            $forecastItems = collect(data_get($forecast, 'list', []))
                ->take(4)
                ->map(function (array $item) use ($timezoneOffset): array {
                    $timestamp = (int) data_get($item, 'dt', 0);

                    return [
                        'time' => $timestamp > 0
                            ? CarbonImmutable::createFromTimestampUTC($timestamp)->addSeconds($timezoneOffset)->format('g A')
                            : '',
                        'temperature_c' => round((float) data_get($item, 'main.temp', 0)),
                        'description' => ucfirst((string) data_get($item, 'weather.0.description', '')),
                        'icon' => (string) data_get($item, 'weather.0.icon', ''),
                        'precipitation_chance' => (int) round((float) data_get($item, 'pop', 0) * 100),
                    ];
                })
                ->values()
                ->all();

            $locationParts = array_filter([
                data_get($current, 'name'),
                data_get($current, 'sys.country'),
            ]);

            return [
                'location' => $locationParts ? implode(', ', $locationParts) : 'Your location',
                'temperature_c' => round((float) $temperature),
                'feels_like_c' => round((float) data_get($current, 'main.feels_like', $temperature)),
                'condition' => ucfirst((string) data_get($current, 'weather.0.description', data_get($current, 'weather.0.main', 'Current conditions'))),
                'humidity_percent' => (int) data_get($current, 'main.humidity', 0),
                'wind_kph' => (int) round((float) data_get($current, 'wind.speed', 0) * 3.6),
                'icon' => (string) data_get($current, 'weather.0.icon', ''),
                'observed_at' => (int) data_get($current, 'dt', time()),
                'forecast' => $forecastItems,
            ];
        });
    }
}

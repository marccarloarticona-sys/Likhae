<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MapProxyController extends Controller
{
    public function geocode(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'text' => ['required', 'string', 'min:3', 'max:220'],
        ])->validate();

        $payload = $this->geoapifyGet('https://api.geoapify.com/v1/geocode/search', [
            'text' => trim($input['text']),
            'filter' => 'countrycode:ph',
            'limit' => 1,
            'lang' => 'en',
            'format' => 'geojson',
        ]);

        $features = collect($payload['features'] ?? [])->map(function (array $feature): array {
            $coordinates = data_get($feature, 'geometry.coordinates', []);
            $properties = $feature['properties'] ?? [];
            $center = is_array($coordinates) && count($coordinates) >= 2
                ? [(float) $coordinates[0], (float) $coordinates[1]]
                : null;

            return [
                'id' => $feature['id'] ?? ($properties['place_id'] ?? null),
                'place_name' => $properties['formatted'] ?? '',
                'center' => $center,
                'geometry' => $feature['geometry'] ?? null,
                'properties' => $properties,
            ];
        })->filter(fn (array $feature): bool => is_array($feature['center']))->values();

        return response()->json(['features' => $features]);
    }

    public function route(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'waypoints' => ['required', 'string', 'max:1800'],
        ])->validate();

        $waypoints = [];
        foreach (preg_split('/[;|]/', $input['waypoints']) ?: [] as $coordinate) {
            $pair = array_map('trim', explode(',', $coordinate));
            if (count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                throw ValidationException::withMessages(['waypoints' => 'Invalid route coordinates.']);
            }
            $longitude = (float) $pair[0];
            $latitude = (float) $pair[1];
            if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
                throw ValidationException::withMessages(['waypoints' => 'Route coordinates are outside valid bounds.']);
            }
            $waypoints[] = sprintf('lonlat:%F,%F', $longitude, $latitude);
        }

        if (count($waypoints) < 2 || count($waypoints) > 8) {
            throw ValidationException::withMessages(['waypoints' => 'A route requires between two and eight waypoints.']);
        }

        $payload = $this->geoapifyGet('https://api.geoapify.com/v1/routing', [
            'waypoints' => implode('|', $waypoints),
            'mode' => 'drive',
            'format' => 'geojson',
        ]);

        $features = $payload['features'] ?? [];
        if (!is_array($features) || !isset($features[0]) || !is_array($features[0])) {
            return response()->json(['routes' => []]);
        }

        $feature = $features[0];
        $rawCoordinates = data_get($feature, 'geometry.coordinates', []);
        if (data_get($feature, 'geometry.type') === 'LineString') {
            $lines = [$rawCoordinates];
        } else {
            $lines = is_array($rawCoordinates) ? $rawCoordinates : [];
        }
        $coordinates = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            foreach ($line as $point) {
                if (!is_array($point) || count($point) < 2) {
                    continue;
                }
                $last = $coordinates[count($coordinates) - 1] ?? null;
                $next = [(float) $point[0], (float) $point[1]];
                if ($last !== $next) {
                    $coordinates[] = $next;
                }
            }
        }

        $properties = $feature['properties'] ?? [];
        return response()->json([
            'routes' => count($coordinates) >= 2 ? [[
                'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
                'distance' => (float) ($properties['distance'] ?? 0),
                'duration' => (float) ($properties['time'] ?? 0),
            ]] : [],
        ]);
    }

    private function geoapifyGet(string $url, array $parameters): array
    {
        $apiKey = (string) config('geoapify.api_key', '');
        if (trim($apiKey) === '') {
            throw new HttpResponseException(response()->json([
                'message' => 'Address and routing services are not configured.',
            ], 503));
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get($url, [...$parameters, 'apiKey' => $apiKey]);
        } catch (\Throwable) {
            throw new HttpResponseException(response()->json([
                'message' => 'The map service is temporarily unavailable.',
            ], 502));
        }

        if (!$response->successful()) {
            throw new HttpResponseException(response()->json([
                'message' => 'The map service could not complete the request.',
            ], 502));
        }

        $decoded = $response->json();
        return is_array($decoded) ? $decoded : [];
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PhilippineAddressController extends Controller
{
    private const BASE_URL = 'https://classification.psa.gov.ph/psgc';
    private const FALLBACK_BASE_URL = 'https://psgc.cloud/api/v2';
    private const V1_URL = 'https://psgc.cloud/api/v1';

    public function regions(): JsonResponse
    {
        return $this->respond(fn () => $this->list('/regions'));
    }

    public function provinces(string $region): JsonResponse
    {
        return $this->respond(function () use ($region) {
            $provinces = $this->list('/regions/'.rawurlencode($region).'/provinces');

            if ($provinces !== []) {
                return $provinces;
            }

            // Regions without a province layer (for example NCR) still need a
            // selectable parent for the existing Province -> City -> Barangay UI.
            $regionRecord = $this->record('/regions/'.rawurlencode($region));

            return [[
                'code' => (string) ($regionRecord['code'] ?? $region),
                'name' => (string) ($regionRecord['name'] ?? $region),
                'level' => 'Region',
            ]];
        });
    }

    public function municipalities(string $province): JsonResponse
    {
        return $this->respond(function () use ($province) {
            $municipalities = $this->list('/provinces/'.rawurlencode($province).'/cities-municipalities');
            if ($municipalities !== []) return $municipalities;

            // The selected "province" may actually be a region-level parent
            // for areas with no province layer (for example NCR).
            return $this->list('/regions/'.rawurlencode($province).'/cities-municipalities');
        });
    }

    public function barangays(string $municipality): JsonResponse
    {
        return $this->respond(fn () => $this->list('/cities-municipalities/'.rawurlencode($municipality).'/barangays'));
    }

    /** @return list<array<string, mixed>> */
    public static function barangayRecordsForMunicipality(string $municipalityCode): array
    {
        try {
            return (new self)->list('/cities-municipalities/'.rawurlencode($municipalityCode).'/barangays');
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function postalCode(Request $request): JsonResponse
    {
        $municipalityCode = trim((string) $request->query('municipality', ''));
        $provinceName = trim((string) $request->query('province_name', ''));
        $municipalityName = trim((string) $request->query('municipality_name', ''));

        $postalCode = $this->resolvePostalCode($municipalityCode, $provinceName, $municipalityName);

        if ($postalCode === null) {
            return response()->json([
                'message' => 'Postal code is not available for the selected location.',
                'postal_code' => null,
            ], 404);
        }

        return response()->json(['postal_code' => $postalCode]);
    }

    public static function selectionIsValid(
        string $regionCode,
        string $regionName,
        string $provinceCode,
        string $provinceName,
        string $municipalityCode,
        string $municipalityName,
        string $barangayCode,
        string $barangayName,
    ): bool {
        try {
            $service = new self;

            $region = collect($service->list('/regions'))->first(
                fn (array $row) => (string) $row['code'] === $regionCode
                    && strcasecmp((string) $row['name'], $regionName) === 0
            );
            if (! $region) return false;

            $provinces = $service->list('/regions/'.rawurlencode($regionCode).'/provinces');
            if ($provinces === []) {
                if ($provinceCode !== $regionCode || strcasecmp($provinceName, $regionName) !== 0) return false;
                $municipalities = $service->list('/regions/'.rawurlencode($regionCode).'/cities-municipalities');
            } else {
                $province = collect($provinces)->first(
                    fn (array $row) => (string) $row['code'] === $provinceCode
                        && strcasecmp((string) $row['name'], $provinceName) === 0
                );
                if (! $province) return false;
                $municipalities = $service->list('/provinces/'.rawurlencode($provinceCode).'/cities-municipalities');
            }

            $municipality = collect($municipalities)->first(
                fn (array $row) => (string) $row['code'] === $municipalityCode
                    && strcasecmp((string) $row['name'], $municipalityName) === 0
            );
            if (! $municipality) return false;

            $barangay = collect($service->list('/cities-municipalities/'.rawurlencode($municipalityCode).'/barangays'))->first(
                fn (array $row) => (string) $row['code'] === $barangayCode
                    && strcasecmp((string) $row['name'], $barangayName) === 0
            );

            return (bool) $barangay;
        } catch (Throwable $e) {
            report($e);
            return false;
        }
    }

    public static function expectedPostalCodeFor(
        string $province = '',
        string $municipality = '',
        string $provinceName = '',
        string $municipalityName = ''
    ): ?string {
        return (new self)->resolvePostalCode($municipality, $provinceName, $municipalityName);
    }

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load Philippine address data. Please try again.',
                'data' => [],
            ], 503);
        }
    }

    private function list(string $path): array
    {
        [$level, $filters] = $this->requestDetails($path);
        $version = (string) config('services.psgc.version', 'Q2_2024');
        $cacheKey = 'psgc.'.$version.'.'.$level.'.'.md5(json_encode($filters));

        return Cache::remember($cacheKey, now()->addWeek(), function () use ($path, $level, $filters, $version) {
            $payload = config('services.psgc.local_first', true)
                ? $this->localList($level, $filters)
                : [];

            if ($payload === []) try {
                $token = config('services.psgc.token');
                if (! filled($token)) throw new \RuntimeException('PSGC API token is not configured.');

                $response = Http::acceptJson()
                    ->withOptions(['verify' => config('services.psgc.verify_ssl', true)])
                    ->timeout(15)->retry(2, 250)
                    ->get(self::BASE_URL.'/'.$version.'/'.$level, [
                        'token' => $token,
                        'page_size' => 1000,
                        ...$filters,
                    ]);
                $response->throw();
                $payload = $response->json('results.psgc_data')
                    ?? $response->json('results')
                    ?? $response->json('data')
                    ?? $response->json()
                    ?? [];
            } catch (Throwable $primaryException) {
                report($primaryException);
                try {
                    $response = Http::acceptJson()->timeout(8)->retry(1, 200)
                        ->get(self::FALLBACK_BASE_URL.$path);
                    $response->throw();
                    $payload = $response->json('data') ?? $response->json() ?? [];
                } catch (Throwable $fallbackException) {
                    report($fallbackException);
                    $payload = $this->localList($level, $filters);
                }
            }

            $locations = collect(is_array($payload) ? $payload : [])
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row) => $this->normalize($row))
                ->filter(fn (array $row) => filled($row['code']) && filled($row['name']))
                ->when($level === 'provinces' && ($filters['reg'] ?? null) === '13',
                    fn ($rows) => $rows->filter(fn (array $row) => in_array($row['level'], ['Prov', 'City'], true)))
                ->when($level === 'municipalities' && (string) ($filters['prv'] ?? '') === '56',
                    fn ($rows) => $rows->push([
                        'code' => '0405643000', 'name' => 'City of Lucena',
                        'prv' => 56, 'mun' => 43, 'level' => 'City',
                    ]))
                ->unique('code')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            return $locations;
        });
    }

    private function requestDetails(string $path): array
    {
        if ($path === '/regions') return ['regions', []];
        if (preg_match('#^/regions/([^/]+)/provinces$#', $path, $match)) {
            return ['provinces', ['reg' => substr(rawurldecode($match[1]), 0, 2)]];
        }
        if (preg_match('#^/provinces/([^/]+)/cities-municipalities$#', $path, $match)) {
            return ['municipalities', ['prv' => $this->provinceCode(rawurldecode($match[1]))]];
        }
        if (preg_match('#^/regions/([^/]+)/cities-municipalities$#', $path, $match)) {
            return ['municipalities', ['reg' => substr(rawurldecode($match[1]), 0, 2)]];
        }
        if (preg_match('#^/cities-municipalities/([^/]+)/barangays$#', $path, $match)) {
            $code = rawurldecode($match[1]);
            return ['barangays', ['prv' => $this->provinceCode($code), 'mun' => $this->municipalityCode($code)]];
        }

        throw new \InvalidArgumentException('Unsupported PSGC path.');
    }

    private function localList(string $level, array $filters): array
    {
        $path = $level === 'barangays'
            ? storage_path('app/psgc/barangays/'.($filters['prv'] ?? '').'-'.($filters['mun'] ?? '').'.json')
            : storage_path('app/psgc/'.$level.'.json');

        if (! is_file($path)) return [];
        $records = json_decode((string) file_get_contents($path), true);
        if (! is_array($records)) return [];

        return collect($records)->filter(function (array $record) use ($level, $filters): bool {
            $normalized = $this->normalize($record);
            if ($level === 'provinces' && filled($filters['reg'] ?? null)) {
                return Str::startsWith($normalized['code'], (string) $filters['reg']);
            }
            if ($level === 'municipalities' && filled($filters['prv'] ?? null)) {
                return (string) $normalized['prv'] === (string) $filters['prv'];
            }
            if ($level === 'municipalities' && filled($filters['reg'] ?? null)) {
                return Str::startsWith($normalized['code'], (string) $filters['reg']);
            }
            return true;
        })->values()->all();
    }

    private function record(string $path): array
    {
        if (preg_match('#^/regions/([^/]+)$#', $path, $match)) {
            $code = rawurldecode($match[1]);
            return collect($this->list('/regions'))->firstWhere('code', $code) ?? [];
        }

        return [];
    }

    private function normalize(array $row): array
    {
        $type = (string) ($row['type'] ?? $row['geographic_level'] ?? '');

        return [
            'code' => (string) ($row['code'] ?? $row['psgc_code'] ?? ''),
            'name' => trim((string) ($row['name'] ?? $row['area_name'] ?? '')),
            'prv' => $row['prv'] ?? $row['province_code'] ?? ($row['code'] ?? $row['psgc_code'] ?? null ? (int) $this->provinceCode((string) ($row['code'] ?? $row['psgc_code'])) : null),
            'mun' => $row['mun'] ?? $row['city_municipality_code'] ?? ($row['code'] ?? $row['psgc_code'] ?? null ? (int) $this->municipalityCode((string) ($row['code'] ?? $row['psgc_code'])) : null),
            'level' => $type !== '' ? (Str::contains(Str::lower($type), 'city') ? 'City' : $type) : null,
        ];
    }

    private function provinceCode(string $code): string
    {
        return ltrim(substr($code, 2, 3), '0') ?: $code;
    }

    private function municipalityCode(string $code): string
    {
        return ltrim(substr($code, 5, 2), '0') ?: $code;
    }

    private function resolvePostalCode(string $municipalityCode, string $provinceName, string $municipalityName): ?string
    {
        if ($municipalityCode !== '') {
            try {
                $cacheKey = 'psgc-cloud-v1-postal:'.$municipalityCode;
                $postal = Cache::remember($cacheKey, now()->addMonth(), function () use ($municipalityCode) {
                    $response = Http::acceptJson()->timeout(10)->retry(1, 200)
                        ->get(self::V1_URL.'/cities-municipalities/'.rawurlencode($municipalityCode));
                    $response->throw();
                    $payload = $response->json('data') ?? $response->json() ?? [];
                    return is_array($payload) ? ($payload['zip_code'] ?? null) : null;
                });

                if (filled($postal)) return (string) $postal;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $byName = config('philippine_postal_codes.by_name', []);
        $key = Str::of($provinceName)->lower()->replaceMatches('/\s+/', ' ')->trim()
            ->append('|')
            ->append(Str::of($municipalityName)->lower()->replaceMatches('/\s+/', ' ')->trim())
            ->toString();

        return isset($byName[$key]) ? (string) $byName[$key] : null;
    }
}

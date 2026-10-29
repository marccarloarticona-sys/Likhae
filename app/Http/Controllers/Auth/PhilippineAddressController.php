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

            // Lucena is a city in Quezon for this address form. The PSA source
            // also lists its independent-city record at province level, so
            // omit that duplicate parent entry; it is added under Quezon in
            // the municipalities list below.
            $provinces = collect($provinces)
                ->reject(fn (array $row) => (string) ($row['code'] ?? '') === '0431200000')
                ->values()
                ->all();

            if ($this->provinceLayerRecords($provinces) !== []) {
                return $provinces;
            }

            // Keep a canonical region parent for validation while the browser
            // loads its cities and municipalities directly under this region.
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
            // A province-level placeholder can actually be a region parent for
            // areas without provinces. Build its direct city/municipality list
            // from the region's city records and ordinary municipalities.
            if ($this->record('/regions/'.rawurlencode($province)) !== []) {
                return $this->municipalitiesForRegion($province);
            }

            $municipalities = $this->list('/provinces/'.rawurlencode($province).'/cities-municipalities');
            if ($municipalities !== []) return $municipalities;

            // The selected parent may be a region-level record for areas with
            // no province layer (for example NCR).
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

    /**
     * Resolve a saved address, including legacy short codes, to its canonical
     * PSGC province and municipality codes.
     *
     * @return array{region_code: string, province_code: string, province_name: string, municipality_code: string, municipality_name: string}|null
     */
    public static function canonicalMunicipalityFor(
        string $provinceCode,
        string $provinceName,
        string $municipalityCode,
        string $municipalityName,
    ): ?array {
        try {
            $service = new self;
            $regions = $service->list('/regions');

            foreach ($regions as $region) {
                $regionCode = (string) ($region['code'] ?? '');
                if ($regionCode === '') {
                    continue;
                }

                $provinceOptions = $service->list('/regions/'.rawurlencode($regionCode).'/provinces');
                $provinces = $service->provinceLayerRecords($provinceOptions);
                if ($provinces === []) {
                    if (strcasecmp(trim($provinceName), trim((string) ($region['name'] ?? ''))) !== 0) {
                        continue;
                    }

                    $municipality = collect($service->municipalitiesForRegion($regionCode))
                        ->first(fn (array $record): bool =>
                            (string) ($record['code'] ?? '') === $municipalityCode
                            || strcasecmp(trim((string) ($record['name'] ?? '')), trim($municipalityName)) === 0
                        );

                    if ($municipality) {
                        return [
                            'region_code' => $regionCode,
                            'province_code' => $regionCode,
                            'province_name' => (string) ($region['name'] ?? ''),
                            'municipality_code' => (string) $municipality['code'],
                            'municipality_name' => (string) $municipality['name'],
                        ];
                    }

                    continue;
                }

                $province = collect($provinceOptions)->first(fn (array $record): bool =>
                    (string) ($record['code'] ?? '') === $provinceCode
                    || strcasecmp(trim((string) ($record['name'] ?? '')), trim($provinceName)) === 0
                );
                if (! $province) {
                    continue;
                }

                if (Str::lower(trim((string) ($province['level'] ?? ''))) === 'city') {
                    $cityName = trim((string) ($province['name'] ?? ''));
                    $sameCity = strcasecmp(trim($municipalityName), $cityName) === 0;

                    if (! $sameCity) {
                        continue;
                    }

                    $municipality = $province;
                } else {
                    $municipality = collect($service->list('/provinces/'.rawurlencode((string) $province['code']).'/cities-municipalities'))
                        ->first(fn (array $record): bool =>
                            (string) ($record['code'] ?? '') === $municipalityCode
                            || strcasecmp(trim((string) ($record['name'] ?? '')), trim($municipalityName)) === 0
                        );
                }

                if ($municipality) {
                    return [
                        'region_code' => $regionCode,
                        'province_code' => (string) $province['code'],
                        'province_name' => (string) $province['name'],
                        'municipality_code' => (string) $municipality['code'],
                        'municipality_name' => (string) $municipality['name'],
                    ];
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    public static function regionNameForCode(string $regionCode): ?string
    {
        $regionCode = trim($regionCode);
        if ($regionCode === '') {
            return null;
        }

        try {
            $region = collect((new self)->list('/regions'))->first(
                fn (array $row): bool => (string) ($row['code'] ?? '') === $regionCode,
            );

            return filled($region['name'] ?? null) ? trim((string) $region['name']) : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function postalCode(Request $request): JsonResponse
    {
        $municipalityCode = trim((string) $request->query('municipality', ''));
        $provinceName = trim((string) $request->query('province_name', ''));
        $municipalityName = trim((string) $request->query('municipality_name', ''));

        $provinceCode = trim((string) $request->query('province', ''));
        $postalCode = $this->resolvePostalCode($municipalityCode, $provinceName, $municipalityName, $provinceCode);

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

            $provinceOptions = $service->list('/regions/'.rawurlencode($regionCode).'/provinces');
            $provinces = $service->provinceLayerRecords($provinceOptions);
            if ($provinces === []) {
                if ($provinceCode !== $regionCode || strcasecmp($provinceName, $regionName) !== 0) return false;
                $municipality = collect($service->municipalitiesForRegion($regionCode))->first(
                    fn (array $row) => (string) ($row['code'] ?? '') === $municipalityCode
                        && strcasecmp((string) ($row['name'] ?? ''), $municipalityName) === 0
                );
            } else {
                $province = collect($provinceOptions)->first(
                    fn (array $row) => (string) $row['code'] === $provinceCode
                        && strcasecmp((string) $row['name'], $provinceName) === 0
                );
                if (! $province) return false;

                if (Str::lower(trim((string) ($province['level'] ?? ''))) === 'city') {
                    if ((string) ($province['code'] ?? '') !== $municipalityCode
                        || strcasecmp(trim((string) ($province['name'] ?? '')), trim($municipalityName)) !== 0) {
                        return false;
                    }

                    $municipality = $province;
                } else {
                    $municipality = collect($service->list('/provinces/'.rawurlencode($provinceCode).'/cities-municipalities'))
                        ->first(
                            fn (array $row) => (string) ($row['code'] ?? '') === $municipalityCode
                                && strcasecmp((string) ($row['name'] ?? ''), $municipalityName) === 0
                        );
                }
            }

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
        return (new self)->resolvePostalCode($municipality, $provinceName, $municipalityName, $province);
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
        // Bump the cache namespace when location-response validation changes so
        // malformed entries from an older response cannot remain visible for a week.
        $cacheKey = 'psgc.validated-v2.'.$version.'.'.$level.'.'.md5(json_encode($filters));

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

            $locations = $this->normalizeLocations($payload, $level, $filters);

            // A successful HTTP response can still contain a placeholder or
            // malformed row instead of PSGC locations. In that case, serve the
            // bundled canonical records rather than caching an empty dropdown.
            if ($locations === []) {
                $localPayload = $this->localList($level, $filters);
                if ($localPayload !== []) {
                    $locations = $this->normalizeLocations($localPayload, $level, $filters);
                }
            }

            return $locations;
        });
    }

    private function normalizeLocations(array $payload, string $level, array $filters): array
    {
        return collect($payload)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => $this->normalize($row))
            ->filter(fn (array $row) => preg_match('/^\d{10}$/', trim((string) $row['code'])) === 1 && filled($row['name']))
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
    }

    /** @param list<array<string, mixed>> $records
     *  @return list<array<string, mixed>>
     */
    private function provinceLayerRecords(array $records): array
    {
        return collect($records)
            ->reject(fn (array $record): bool => Str::lower(trim((string) ($record['level'] ?? ''))) === 'city')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function municipalitiesForRegion(string $regionCode): array
    {
        $regionPath = '/regions/'.rawurlencode($regionCode);
        $cities = collect($this->list($regionPath.'/provinces'))
            ->filter(fn (array $record): bool => ($record['level'] ?? null) === 'City');
        $municipalities = collect($this->list($regionPath.'/cities-municipalities'))
            ->filter(fn (array $record): bool => in_array($record['level'] ?? null, ['City', 'Mun'], true));

        return $cities
            ->merge($municipalities)
            ->unique('code')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
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
        $municipality = substr($code, 5, 2);

        return $municipality === '00' ? '0' : (ltrim($municipality, '0') ?: $code);
    }

    private function resolvePostalCode(string $municipalityCode, string $provinceName, string $municipalityName, string $provinceCode = ''): ?string
    {
        $byCode = config('philippine_postal_codes.by_code', []);
        $codeKey = $this->postalLocationCodeKey($provinceCode, $municipalityCode);
        if ($codeKey !== null && isset($byCode[$codeKey])) {
            return (string) $byCode[$codeKey];
        }

        $byName = config('philippine_postal_codes.by_name', []);
        $key = $this->normalizePostalName($provinceName).'|'.$this->normalizePostalName($municipalityName);
        if (isset($byName[$key])) {
            return (string) $byName[$key];
        }

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

        return null;
    }

    private function postalLocationCodeKey(string $provinceCode, string $municipalityCode): ?string
    {
        if (preg_match('/^(\d+)-(\d+)$/', $municipalityCode, $match)) {
            return ((int) $match[1]).'-'.((int) $match[2]);
        }

        if (preg_match('/^\d{10}$/', $municipalityCode)) {
            return ((int) substr($municipalityCode, 2, 3)).'-'.((int) substr($municipalityCode, 5, 2));
        }

        if ($provinceCode !== '' && ctype_digit($provinceCode) && ctype_digit($municipalityCode)) {
            $province = preg_match('/^\d{10}$/', $provinceCode)
                ? (int) substr($provinceCode, 2, 3)
                : (int) $provinceCode;
            return $province.'-'.((int) $municipalityCode);
        }

        return null;
    }

    private function normalizePostalName(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }
}
